<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Import real payments from a saved `payment_master` export.
 *
 * READS A FILE, NOT ZOHO. The export is already on disk from `zoho:inspect`, and
 * re-exporting to import would burn an account-wide concurrency slot that a live
 * production app also needs. Import is re-runnable; exporting is not free.
 *
 * ---------------------------------------------------------------------------
 * WHAT THIS DELIBERATELY DOES NOT DO
 *
 * **No unique index on `payment_no`, and none is added.** 239 payment numbers occur
 * on more than one live record — `EKS/Haewaya/12539` six times. §7.6 says a number
 * once issued is never reissued; live disagrees. Reproducing the data means
 * tolerating that, so `payment_no` stays a non-unique index and `creator_id` is the
 * key.
 *
 * **`Paid Amount` IS NOT MAPPED.** The column is named like money and holds `Yes` /
 * `No`. Mapping it to `payments.paid_amount` would be silently wrong on every row.
 * There is no boolean column for it yet, so the value is dropped rather than
 * mangled — flagged here so it is a known omission, not an oversight.
 *
 * **`EKS/API/*` rows are EXCLUDED.** Those 42 payments were written by the expense
 * tracker's own API. §7 of the field notes: re-importing your own writes produced 19
 * duplicates worth ₹1,51,827 in one hourly run there. Provenance, cheaply.
 *
 * **Bills are not linked.** `Bill No` is a bill NUMBER, our `bills` table is still
 * essentially empty, and there is no `bill_no` column on payments to park it on. So
 * `bill_id` stays null on every imported row and the linkage waits for a real bills
 * import.
 *
 * ---------------------------------------------------------------------------
 * LOOKUPS ARE MIXED WITHIN ONE ROW, which §11 predicts and is worth stating:
 *
 *     Vendor Name, COA, Location, Bank Name, Head Office  ->  18-digit RECORD IDS
 *     Item Category, Master Category                      ->  NAMES
 *
 * So vendors resolve on `creator_id` (reliable) while categories resolve on name
 * (fragile — and one name, `F&B STAFF MEDICAL EXPENSE `, is stored trimmed in
 * Analytics and untrimmed in our master, so it cannot match). Unresolved lookups
 * are left NULL and counted, never guessed.
 *
 * FIXTURES ARE SAFE. Our two test payments have `creator_id = null`, and the upsert
 * keys on `creator_id`, so they cannot collide with an imported row.
 */
class ZohoImportPayments extends Command
{
    protected $signature = 'zoho:import-payments
        {file? : path to a payment_master .ndjson (default: newest in storage/app/zoho)}
        {--dry-run : parse, resolve and report — write nothing}
        {--chunk=500 : rows per upsert}';

    protected $description = 'Import real payments from a saved payment_master export. Reads a file; never calls Zoho.';

    /** Money arrives as `₹ 160,450.00` — symbol, space, Indian grouping, 2dp. */
    private function money(?string $v): ?string
    {
        if ($v === null) {
            return null;
        }

        $clean = preg_replace('/[^\d.\-]/u', '', $v);

        return ($clean === '' || $clean === '-') ? null : $clean;
    }

    /**
     * Dates arrive as `24-Jul-2026 ` — dd-MMM-yyyy WITH A TRAILING SPACE.
     *
     * Trimming is correct here and only here: this is a date being parsed into a
     * date column, not a string being stored as a lookup key. The no-trim rule
     * protects identifiers, not calendar values.
     */
    private function date(?string $v): ?string
    {
        $v = $v === null ? '' : trim($v);

        if ($v === '') {
            return null;
        }

        try {
            return Carbon::createFromFormat('d-M-Y', substr($v, 0, 11))->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * `18-Jun-2026 05:36:17` — Creator's platform stamp, date AND time.
     *
     * Separate from `date()` because that one truncates to 11 characters and returns a
     * date string, which threw the time away. Every report in this app leads with
     * `Added Time` and shows both halves (`27-Aug-2026 19:06:48`), so losing the time
     * loses the column's whole purpose — and the ordering that depends on it.
     */
    private function stamp(?string $v): ?string
    {
        $v = $v === null ? '' : trim($v);

        if ($v === '') {
            return null;
        }

        foreach (['d-M-Y H:i:s', 'd-M-Y H:i', 'd-M-Y'] as $format) {
            try {
                return Carbon::createFromFormat($format, $v)->toDateTimeString();
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }

    private function yes(?string $v): bool
    {
        return strtolower(trim((string) $v)) === 'yes';
    }

    private function text(?string $v): ?string
    {
        // NOT trimmed — these can be lookup keys.
        return ($v === null || $v === '') ? null : $v;
    }

    public function handle(): int
    {
        $path = $this->argument('file') ?: $this->newest();

        if ($path === null || ! is_file($path)) {
            $this->error('No payment_master export found. Run: php artisan zoho:inspect payment_master');

            return self::FAILURE;
        }

        $this->line("Reading <info>{$path}</info>");
        $dry = (bool) $this->option('dry-run');

        // Lookup maps. Ids where the export gives ids, names where it gives names.
        $vendors = DB::table('vendors')->whereNotNull('creator_id')->pluck('id', 'creator_id');
        $coa = DB::table('coa_accounts')->whereNotNull('creator_id')->pluck('id', 'creator_id');
        $locations = DB::table('locations')->whereNotNull('creator_id')->pluck('id', 'creator_id');
        $offices = DB::table('head_offices')->whereNotNull('creator_id')->pluck('id', 'creator_id');
        $itemCats = DB::table('item_categories')->pluck('id', 'name');
        $masterCats = DB::table('master_categories')->pluck('id', 'name');

        $stats = [
            'read' => 0, 'skipped_api' => 0, 'skipped_no_id' => 0, 'written' => 0,
            'vendor_unresolved' => 0, 'coa_unresolved' => 0, 'location_unresolved' => 0,
            'item_category_unresolved' => 0, 'master_category_unresolved' => 0,
        ];
        $unresolvedNames = [];

        $batch = [];
        $chunk = max(50, (int) $this->option('chunk'));
        $handle = fopen($path, 'r');

        if ($handle === false) {
            $this->error("Could not open {$path}");

            return self::FAILURE;
        }

        try {
            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }

                $r = json_decode($line, true);
                if (! is_array($r)) {
                    continue;
                }

                $stats['read']++;

                $creatorId = $this->text($r['ID'] ?? null);
                if ($creatorId === null) {
                    $stats['skipped_no_id']++;

                    continue;
                }

                $paymentNo = $this->text($r['Payment No.'] ?? null);

                // §7 — never re-import our own (or another app's) API writes.
                if ($paymentNo !== null && str_starts_with($paymentNo, 'EKS/API/')) {
                    $stats['skipped_api']++;

                    continue;
                }

                $vendorKey = $this->text($r['Vendor Name'] ?? null);
                $coaKey = $this->text($r['COA'] ?? null);
                $locKey = $this->text($r['Location'] ?? null);
                $officeKey = $this->text($r['Head Office'] ?? null);
                $icName = $this->text($r['Item Category'] ?? null);
                $mcName = $this->text($r['Master Category'] ?? null);

                $vendorId = $vendorKey === null ? null : ($vendors[$vendorKey] ?? null);
                $coaId = $coaKey === null ? null : ($coa[$coaKey] ?? null);
                $locId = $locKey === null ? null : ($locations[$locKey] ?? null);
                $officeId = $officeKey === null ? null : ($offices[$officeKey] ?? null);
                $icId = $icName === null ? null : ($itemCats[$icName] ?? null);
                $mcId = $mcName === null ? null : ($masterCats[$mcName] ?? null);

                if ($vendorKey !== null && $vendorId === null) {
                    $stats['vendor_unresolved']++;
                }
                if ($coaKey !== null && $coaId === null) {
                    $stats['coa_unresolved']++;
                }
                if ($locKey !== null && $locId === null) {
                    $stats['location_unresolved']++;
                }
                if ($icName !== null && $icId === null) {
                    $stats['item_category_unresolved']++;
                    $unresolvedNames['item_category'][$icName] = true;
                }
                if ($mcName !== null && $mcId === null) {
                    $stats['master_category_unresolved']++;
                    $unresolvedNames['master_category'][$mcName] = true;
                }

                $gross = $this->money($r['Gross Amount'] ?? null);
                $gst = $this->money($r['GST Amount'] ?? null);
                $tds = $this->money($r['TDS Amount'] ?? null);

                $batch[] = [
                    'creator_id' => $creatorId,
                    'payment_no' => $paymentNo,
                    'vendor_id' => $vendorId,
                    'coa_account_id' => $coaId,
                    'location_id' => $locId,
                    'head_office_id' => $officeId,
                    'item_category_id' => $icId,
                    'master_category_id' => $mcId,

                    // Verbatim. `Paid` / `paid` and three spellings of
                    // Submit/Send/Sent for Approval are all live (addendum §10).
                    'status' => $this->text($r['Status'] ?? null),
                    'payment_status' => $this->text($r['Payment Status'] ?? null),

                    'amount' => $gross,
                    'gst_amount' => $gst,
                    'tds_amount' => $tds,
                    'payable_amount' => $this->money($r['Payable Amount'] ?? null),
                    'total_amount' => $this->money($r['Invoice Amount'] ?? null),

                    'payment_date' => $this->date($r['Payment Date'] ?? null),
                    'due_date' => $this->date($r['Due Date'] ?? null),
                    'requested_date' => $this->date($r['Requested Date'] ?? null),
                    'backend_payment_date' => $this->date($r['Backend Payment Date'] ?? null),

                    'booking_no' => $this->text($r['Booking No.'] ?? null),
                    'payment_reference_number' => $this->text($r['Payment Reference Number'] ?? null),
                    'remarks' => $this->text($r['Accounts Remarks'] ?? null),
                    'expense_by' => $this->text($r['Expense By'] ?? null),

                    /*
                     * CREATOR'S FOUR PLATFORM FIELDS — and three of them were dropped
                     * until 28-Aug-2026.
                     *
                     * The export carries `Added Time`, `Modified Time`, `Added User` and
                     * `Modified User`, and only `added_user` was mapped. So `added_time`
                     * was NULL on all 53,280 rows, which had two consequences:
                     *
                     *   1. The `Added Time` column rendered blank on every report that
                     *      leads with it.
                     *   2. `order by added_time desc, id desc` degenerated to `id desc`
                     *      — import-file order — so the newest payment was NOT at the top
                     *      of All Payments. Husain saw 21697 sitting between 21317 and
                     *      21370 and reasonably read the whole sync as stale.
                     *
                     * `TracksCreatorAudit` did not save it either: this importer writes
                     * through `DB::table()->upsert()` in batches, which bypasses model
                     * events by design — 53,280 model saves would be far slower. So the
                     * values have to be mapped here, explicitly.
                     */
                    'added_time' => $this->stamp($r['Added Time'] ?? null),
                    'added_user' => $this->text($r['Added User'] ?? null),
                    'modified_time' => $this->stamp($r['Modified Time'] ?? null),
                    'modified_user' => $this->text($r['Modified User'] ?? null),
                    'accounts_bills' => $this->yes($r['Accounts Bills'] ?? null),

                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                if (count($batch) >= $chunk) {
                    $stats['written'] += $this->flush($batch, $dry);
                    $batch = [];

                    if ($stats['read'] % 10000 === 0) {
                        $this->line('  ... '.$stats['read'].' read');
                    }
                }
            }
        } finally {
            fclose($handle);
        }

        if ($batch !== []) {
            $stats['written'] += $this->flush($batch, $dry);
        }

        $this->newLine();
        $this->info($dry ? 'DRY RUN — nothing written.' : 'Import complete.');
        foreach ($stats as $k => $v) {
            $this->line(sprintf('   %-26s %7d', $k, $v));
        }

        foreach ($unresolvedNames as $field => $names) {
            $this->newLine();
            $this->warn(sprintf('%s names with no master row (left NULL): %d', $field, count($names)));
            foreach (array_slice(array_keys($names), 0, 10) as $n) {
                $this->warn('   '.var_export($n, true));
            }
        }

        if (! $dry) {
            $this->newLine();
            $this->line(sprintf('payments table now holds %d rows (%d imported, %d local fixtures).',
                DB::table('payments')->count(),
                DB::table('payments')->whereNotNull('creator_id')->count(),
                DB::table('payments')->whereNull('creator_id')->count(),
            ));
        }

        return self::SUCCESS;
    }

    private function flush(array $batch, bool $dry): int
    {
        if ($dry) {
            return count($batch);
        }

        DB::table('payments')->upsert($batch, ['creator_id']);

        return count($batch);
    }

    private function newest(): ?string
    {
        $files = collect(File::glob(storage_path('app/zoho/payment-master-*.ndjson')))
            ->sortByDesc(fn ($f) => filemtime($f));

        return $files->first();
    }
}
