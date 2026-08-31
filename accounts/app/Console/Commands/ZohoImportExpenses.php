<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Import the Expenses ledger from a saved `expenses` export.
 *
 * §5.2: an Expenses_Bills row IS one ledger entry. This is the table every
 * villa-month-category figure downstream resolves against.
 *
 * ---------------------------------------------------------------------------
 * WHICH ROWS ARE THE LEDGER. The view returns 119,361 rows and only 66,402 are
 * expenses; the rest are booking-side rows joined in, carrying a payment reference
 * and nothing else. `Type` is the discriminator and it is exact:
 *
 *     Type = 'Expense'   66,292
 *     Type = 'Bill'         110
 *     Type = blank       52,959   <- joined booking rows, not ledger entries
 *
 * 66,292 + 110 = 66,402, which is precisely the count of ID-bearing rows. So `Type`
 * populated and `ID` populated are the same set, and either can gate the import.
 * Both are checked, because agreeing on 66,402 rows today is not a guarantee.
 *
 * VERIFIED AGAINST THE LIVE REPORT: Creator's All Expenses footer reads 66,407.
 * This import takes 66,402. The gap of 5 is the Analytics lag — §1 of the field
 * notes: Analytics is a reporting replica that trails Creator by minutes. A gap of
 * 5 on 66,402 is the lag; a gap of 1,102 was a two-day-stale export, which is what
 * the first attempt hit and why it was re-exported.
 *
 * ---------------------------------------------------------------------------
 * THE EXPORT FILLS 24 OF THE REPORT'S 33 DATA COLUMNS. Missing, and stated so the
 * empty columns on screen are not read as a bug:
 *
 *     Primary Vendor Name · TDS % · Recon Expense · Vendor GST No. ·
 *     ID BIlls · Bills · Added User · Modified User · Payment Status
 *
 * Those are on the report but not in the Analytics view, so they need a different
 * view or a form-level export. The nine are left NULL rather than derived — deriving
 * `Payment Status` from `Status`, for instance, would look right and be a guess.
 *
 * `Billing Cycle` comes through as `Billing Month` in the abbreviated form
 * (`Jul 2026`), not the dashed form `payment_master` uses (`July - 2026`). Both are
 * aliased in the cycle map; see ZohoImportBills on why that cost 26,720 legs once.
 */
class ZohoImportExpenses extends Command
{
    protected $signature = 'zoho:import-expenses
        {file? : an expenses .ndjson (default: newest in storage/app/zoho)}
        {--dry-run : parse and resolve, write nothing}
        {--chunk=500}';

    protected $description = 'Import the Expenses ledger from a saved expenses export.';

    public function handle(): int
    {
        $path = $this->argument('file')
            ?: collect(File::glob(storage_path('app/zoho/expenses-*.ndjson')))
                // `expenses-*` also matches `expenses-bills-*`, a different and
                // currently empty view. Excluding it is not optional: the unfiltered
                // glob silently picked the 0-row file once.
                ->reject(fn ($f) => str_contains(basename($f), 'expenses-bills-'))
                ->sortByDesc(fn ($f) => filemtime($f))->first();

        if ($path === null || ! is_file($path)) {
            $this->error('No expenses export found. Run: php artisan zoho:inspect expenses');

            return self::FAILURE;
        }

        $this->line('Reading <info>'.basename($path).'</info>');
        $dry = (bool) $this->option('dry-run');

        // Lookups. Villas and locations resolve by NAME here because that is what
        // this view carries; ids would be safer and are not on offer.
        $villas = DB::table('villas')->pluck('id', 'name');
        $locations = DB::table('locations')->pluck('id', 'name');
        $itemCats = DB::table('item_categories')->pluck('id', 'name');
        $masterCats = DB::table('master_categories')->pluck('id', 'name');
        $coa = DB::table('coa_accounts')->pluck('id', 'account_name');
        $vendors = DB::table('vendors')->whereNotNull('name')->where('name', '<>', '')->pluck('id', 'name');
        $payments = DB::table('payments')->whereNotNull('payment_no')->pluck('id', 'payment_no');
        $cycles = $this->cycleMap();

        $stats = ['read' => 0, 'skipped_not_ledger' => 0, 'written' => 0];
        $unresolved = ['villa' => 0, 'location' => 0, 'item_category' => 0,
            'master_category' => 0, 'coa' => 0, 'vendor' => 0, 'cycle' => 0, 'payment' => 0];

        $batch = [];
        $chunk = max(50, (int) $this->option('chunk'));
        $handle = fopen($path, 'r');

        while (($line = fgets($handle)) !== false) {
            $row = json_decode(trim($line), true);
            if (! is_array($row)) {
                continue;
            }
            $stats['read']++;

            $creatorId = $this->text($row['ID'] ?? null);
            $type = $this->text($row['Type'] ?? null);

            // Both gates, per the docblock.
            if ($creatorId === null || $type === null) {
                $stats['skipped_not_ledger']++;

                continue;
            }

            $name = fn (string $k) => $this->text($row[$k] ?? null);

            $villa = $name('Villa_Name');
            $primaryVilla = $name('Primary_Villa');
            $location = $name('Location');
            $itemCat = $name('Item_Category');
            $masterCat = $name('Master_Category');
            $coaName = $name('COA');
            $bankName = $name('Bank_Name');
            $vendorName = $name('Vendor_Name');
            $paymentNo = $name('Payment');
            $cycleLabel = $name('Billing Month');

            $resolve = function (?string $key, $map, string $stat) use (&$unresolved) {
                if ($key === null) {
                    return null;
                }
                $id = $map[$key] ?? null;
                if ($id === null) {
                    $unresolved[$stat]++;
                }

                return $id;
            };

            $batch[] = [
                'creator_id' => $creatorId,
                'type' => $type,

                'added_time' => $this->stamp($row['Added Time'] ?? null),
                'modified_time' => $this->stamp($row['Modified Time'] ?? null),
                'timestamp_date' => $this->stamp($row['Timestamp_Date'] ?? null),

                'primary_villa_id' => $resolve($primaryVilla, $villas, 'villa'),
                'villa_id' => $resolve($villa, $villas, 'villa'),
                'location_id' => $resolve($location, $locations, 'location'),
                'item_category_id' => $resolve($itemCat, $itemCats, 'item_category'),
                'master_category_id' => $resolve($masterCat, $masterCats, 'master_category'),
                'coa_account_id' => $resolve($coaName, $coa, 'coa'),
                'bank_coa_account_id' => $resolve($bankName, $coa, 'coa'),
                'vendor_id' => $resolve($vendorName, $vendors, 'vendor'),
                'billing_cycle_id' => $resolve($cycleLabel, $cycles, 'cycle'),
                'payment_id' => $resolve($paymentNo, $payments, 'payment'),

                // The NAMES travel too, not just the ids. §6: names drift and deleted
                // vendors vanish from the master while their expenses remain, so the
                // name as filed is evidence a null foreign key would destroy.
                'vendor_name' => $vendorName,
                'payment_no' => $paymentNo,
                'bill_no' => $this->text($row['Bill_No'] ?? null),

                'payment_date' => $this->date($row['Payment_Date'] ?? null),
                'bill_date' => $this->date($row['Bill_Date'] ?? null),

                'particulars' => $this->text($row['Particulars'] ?? null),
                'status' => $this->text($row['Status'] ?? null),
                'link' => $this->text($row['Link'] ?? null),
                'expense_by' => $this->text($row['Expense_By'] ?? null),
                'payment_by' => $this->text($row['Payment_By'] ?? null),

                'gross_amount' => $this->money($row['Gross_Amount'] ?? null),
                'tds_amount' => $this->money($row['TDS_Amount'] ?? null),
                'gst_amount' => $this->money($row['GST_Amount'] ?? null),
                // NOT the same as gross: Gross - TDS + GST, the net attributable
                // figure and the one §5.2 means by "ledger entry".
                'amount' => $this->money($row['Amount'] ?? null),
                'net_paid_amount' => $this->money($row['Net_Paid_Amount'] ?? null),

                // The revision triplet — identical on 64,699 rows, differing on 606.
                'new_gross_amount' => $this->money($row['New_Gross_Amount'] ?? null),
                'new_gst_amount' => $this->money($row['New_GST_Amount'] ?? null),
                'new_tds_amount' => $this->money($row['New_TDS_Amount'] ?? null),

                'booking_no' => $this->text($row['Booking No.'] ?? null),

                'created_at' => now(),
                'updated_at' => now(),
            ];

            if (count($batch) >= $chunk) {
                $stats['written'] += $this->flush($batch, $dry);
                $batch = [];

                if ($stats['written'] % 20000 === 0) {
                    $this->line('  ... '.$stats['written'].' written');
                }
            }
        }
        fclose($handle);

        if ($batch !== []) {
            $stats['written'] += $this->flush($batch, $dry);
        }

        $this->newLine();
        $this->info($dry ? 'DRY RUN — nothing written.' : 'Import complete.');
        foreach ($stats as $k => $v) {
            $this->line(sprintf('   %-22s %7d', $k, $v));
        }

        $this->newLine();
        $this->line('unresolved lookups (left NULL, never invented):');
        foreach ($unresolved as $what => $n) {
            if ($n > 0) {
                $this->warn(sprintf('   %-18s %7d', $what, $n));
            }
        }

        if (! $dry) {
            $this->newLine();
            $this->line(sprintf('expenses table now holds %d rows. Creator reports 66,407 — a small '
                .'gap is the Analytics lag (§1), not a missing import.', DB::table('expenses')->count()));
        }

        return self::SUCCESS;
    }

    private function flush(array $batch, bool $dry): int
    {
        if ($dry) {
            return count($batch);
        }

        DB::table('expenses')->upsert($batch, ['creator_id']);

        return count($batch);
    }

    /** Both cycle spellings — `Jul 2026` here, `July - 2026` on payment_master. */
    private function cycleMap(): array
    {
        $map = [];

        foreach (DB::table('billing_cycles')->get() as $c) {
            $full = (string) $c->month_name;
            $year = (string) $c->year;
            $abbr = substr($full, 0, 3);

            foreach ([$full.' - '.$year, $full.'-'.$year, $full.' '.$year,
                $abbr.' '.$year, $abbr.'-'.$year] as $label) {
                $map[$label] = $c->id;
            }
        }

        return $map;
    }

    /** Verbatim, never trimmed — these are lookup keys. */
    private function text(mixed $v): ?string
    {
        return ($v === null || $v === '') ? null : (string) $v;
    }

    /** `₹ 58,614.14` -> `58614.14`. */
    private function money(?string $v): ?string
    {
        if ($v === null) {
            return null;
        }

        $clean = preg_replace('/[^\d.\-]/u', '', $v);

        return ($clean === '' || $clean === '-') ? null : $clean;
    }

    /** `10-Aug-2026 ` — trailing space is common. Trimming a DATE is safe. */
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
     * TWO TIMESTAMP FORMATS, AND THIS VIEW USES THE ONE THE SCREENSHOT DOES NOT.
     *
     * The live report displays `27-Aug-2026 19:06:48` and `payment_master` exports
     * that same shape — so `d-M-Y H:i:s` looked like the format. The `expenses` view
     * exports ISO instead:
     *
     *     Added Time = "2025-12-25 15:26:50"
     *
     * The first version parsed only the dd-MMM-yyyy form, so every one of the 66,402
     * timestamps came through NULL — including `added_time`, which the report sorts
     * by. Caught by reading the top row of the API response and finding it blank,
     * not by the import reporting anything: a failed parse returned null and null is
     * a legal value.
     *
     * This is §11 again — field naming is per-view and unstable — except it is the
     * VALUE format varying, not the key. Both are tried, ISO first since that is what
     * this view sends.
     */
    private function stamp(?string $v): ?string
    {
        $v = $v === null ? '' : trim($v);

        if ($v === '') {
            return null;
        }

        foreach (['Y-m-d H:i:s', 'd-M-Y H:i:s', 'Y-m-d\TH:i:s'] as $format) {
            try {
                return Carbon::createFromFormat($format, $v)->toDateTimeString();
            } catch (\Throwable) {
                continue;
            }
        }

        // A date with no time still beats losing the row entirely.
        return $this->date($v);
    }
}
