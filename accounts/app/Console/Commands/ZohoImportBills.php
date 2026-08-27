<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Bill;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Reconstruct bills from a saved `expenses` export.
 *
 * WHY RECONSTRUCTED RATHER THAN IMPORTED. There is no bills view. The accounts
 * workspace exposes Expenses, Bill link check, Bank transactions, Banks, COA,
 * Payment master, Location, Villa, Personal Expenses and F&B — nothing bill-shaped.
 * The one view offered as the bills source
 * (live/443703000004950303) exports **0 rows**, and that was checked rather than
 * assumed: the same id against the accounts workspace returns
 * `OBJID_NOT_BELONGS_TO_DB` (7319), so it is in the right workspace and simply
 * empty. §6 of the field notes is explicit that an empty export is not evidence of
 * an empty view, so this is a workaround, not a conclusion.
 *
 * The `expenses` view IS leg-level — one row per villa x cycle x category, verified
 * by zero comma-packing on those columns — so a bill is a GROUP of its rows.
 *
 * ---------------------------------------------------------------------------
 * THE KEY IS (Bill_No, Vendor), NOT Bill_No. Measured: 1,392 Bill_No values span
 * more than one vendor — `'36'` covers 9 of them. That is not dirty data, it is what
 * a bill number IS: the vendor's own invoice number, unique to that vendor and
 * nothing more. Keyed on Bill_No alone, 1,392 groups would merge unrelated bills
 * from unrelated vendors and their amounts would be summed together.
 *
 * JUNK BILL NUMBERS ARE EXCLUDED, 1,933 rows. Including the literal string `'null'`
 * — not a null value, the four characters — used across 471 different vendors. Any
 * grouping that keeps it produces one enormous fictional bill.
 *
 * ---------------------------------------------------------------------------
 * ROWS THAT DO NOT BALANCE ARE IMPORTED ANYWAY, and flagged. 13,594 of 17,168 bills
 * have legs summing to their header gross; 3,574 do not. §6.4 rule 1 is a validation
 * on SAVE, not a filter on history: a bill that did not balance in Creator is a fact
 * about the ledger, and dropping 3,574 real bills to make the import look clean
 * would be the worse error. The count is reported, and `SplitValidator` will surface
 * each one the moment anyone opens it.
 *
 * ONLY ID-BEARING ROWS ARE USED. 45% of the `expenses` view has no record id — a
 * third of it is booking rows joined in, and 19,667 rows are accounts-shaped with no
 * id at all. §7: the record id is the only stable natural key, so a row without one
 * cannot be upserted and is not guessed at.
 */
class ZohoImportBills extends Command
{
    protected $signature = 'zoho:import-bills
        {file? : an expenses .ndjson (default: newest in storage/app/zoho)}
        {--dry-run : group, resolve and report — write nothing}
        {--limit=0 : stop after N bills (0 = all)}';

    protected $description = 'Reconstruct bills and their split legs from a saved expenses export.';

    /** Why legs were dropped, by cause — see the skip branch. */
    private array $unresolved = ['villa' => 0, 'item_category' => 0, 'billing_cycle' => 0];

    /** Bill numbers that are placeholders rather than numbers. */
    private const JUNK_BILL_NO = ['null', 'NULL', 'na', 'NA', 'N/A', '-', '.', '0', 'nil', 'NIL'];

    public function handle(): int
    {
        $path = $this->argument('file')
            ?: collect(File::glob(storage_path('app/zoho/expenses-*.ndjson')))
                // `expenses-*` ALSO matches `expenses-bills-*`, and that file is
                // newer AND empty — so the unfiltered glob silently picked a 0-row
                // export and the import would have looked like a data problem.
                ->reject(fn ($f) => str_contains(basename($f), 'expenses-bills-'))
                ->sortByDesc(fn ($f) => filemtime($f))->first();

        if ($path === null || ! is_file($path)) {
            $this->error('No expenses export found. Run: php artisan zoho:inspect expenses');

            return self::FAILURE;
        }

        $this->line("Reading <info>{$path}</info>");
        $dry = (bool) $this->option('dry-run');

        $vendorsByName = DB::table('vendors')->whereNotNull('name')->where('name', '<>', '')
            ->pluck('id', 'name');
        $locationsByName = DB::table('locations')->pluck('id', 'name');
        $itemCatsByName = DB::table('item_categories')->pluck('id', 'name');
        $villasByName = DB::table('villas')->pluck('id', 'name');
        $cyclesByLabel = $this->cycleMap();

        // Group in memory: 65k keyable rows is fine, and grouping must see every
        // leg of a bill before the header can be written.
        $groups = [];
        $stats = ['read' => 0, 'no_id' => 0, 'junk_bill_no' => 0, 'no_bill_no' => 0, 'no_vendor' => 0];

        $handle = fopen($path, 'r');
        while (($line = fgets($handle)) !== false) {
            $row = json_decode(trim($line), true);
            if (! is_array($row)) {
                continue;
            }
            $stats['read']++;

            if ($this->blank($row['ID'] ?? null)) {
                $stats['no_id']++;

                continue;
            }

            $billNo = trim((string) ($row['Bill_No'] ?? ''));
            if ($billNo === '') {
                $stats['no_bill_no']++;

                continue;
            }
            if (in_array($billNo, self::JUNK_BILL_NO, true)) {
                $stats['junk_bill_no']++;

                continue;
            }

            $vendor = trim((string) ($row['Vendor_Name'] ?? ''));
            if ($vendor === '') {
                $stats['no_vendor']++;

                continue;
            }

            $groups[$billNo.'|'.$vendor][] = $row;
        }
        fclose($handle);

        $this->line(sprintf('%d rows read; %d bills grouped by (Bill_No, Vendor).',
            $stats['read'], count($groups)));
        foreach ($stats as $k => $v) {
            if ($k !== 'read') {
                $this->line(sprintf('   skipped %-14s %6d', $k, $v));
            }
        }

        $limit = (int) $this->option('limit');
        $written = 0;
        $legsWritten = 0;
        $unbalanced = 0;
        $unresolvedVendor = 0;

        foreach ($groups as $key => $rows) {
            if ($limit > 0 && $written >= $limit) {
                break;
            }

            [$billNo, $vendorName] = explode('|', $key, 2);
            $head = $rows[0];

            $vendorId = $vendorsByName[$vendorName] ?? null;
            if ($vendorId === null) {
                $unresolvedVendor++;
            }

            $gross = $this->money($head['Gross_Amount'] ?? null);
            $legSum = '0.0000';
            foreach ($rows as $r) {
                $legSum = bcadd($legSum, $this->money($r['Amount'] ?? null) ?? '0', 4);
            }

            $balanced = $gross !== null
                && bccomp(bcadd($legSum, '0', 0), bcadd($gross, '0', 0), 0) === 0;
            if (! $balanced) {
                $unbalanced++;
            }

            if ($dry) {
                $written++;

                continue;
            }

            DB::transaction(function () use (
                $billNo, $vendorId, $head, $rows, $gross,
                $locationsByName, $itemCatsByName, $villasByName, $cyclesByLabel,
                &$legsWritten
            ): void {
                $locationName = trim((string) ($head['Location'] ?? ''));

                $bill = Bill::updateOrCreate(
                    // No creator_id: a reconstructed bill has no Creator record of
                    // its own — the ids in the export belong to the EXPENSE rows,
                    // not to a bill. Keyed on the business pair instead, which is
                    // the only identity this data has.
                    ['bill_no' => $billNo, 'vendor_id' => $vendorId],
                    [
                        'bill_date' => $this->date($head['Bill_Date'] ?? null),
                        'location_id' => $locationsByName[$locationName] ?? null,
                        // Verbatim — `Payment InProgress` is spelled two ways live.
                        'status' => $this->text($head['Status'] ?? null),
                        'amount' => $gross,
                        'gst_amount' => $this->money($head['GST_Amount'] ?? null),
                        'tds_amount' => $this->money($head['TDS_Amount'] ?? null),
                        'invoice_amount' => $this->money($head['Invoice Amount'] ?? null),
                        // NOT NULL with a default of 0, so null is a constraint
                        // violation rather than "unknown". Net_Paid_Amount is blank
                        // on plenty of rows; blank means nothing paid.
                        'paid_amount' => $this->money($head['Net_Paid_Amount'] ?? null) ?? '0.0000',
                        // §6.3 has two formulas under this name and which is
                        // authoritative is open, so it is taken from the source
                        // rather than computed.
                        'payable_amount' => $this->money($head['Invoice Amount'] ?? null),
                    ]
                );

                // Reconcile, never clear-and-rebuild (§5.1 / §15.1). Legs are keyed
                // on the villa x category x cycle triple, which is what makes a leg
                // identifiable at all.
                foreach ($rows as $position => $r) {
                    $villa = $villasByName[trim((string) ($r['Villa_Name'] ?? ''))] ?? null;
                    $cat = $itemCatsByName[(string) ($r['Item_Category'] ?? '')] ?? null;
                    $cycle = $cyclesByLabel[trim((string) ($r['Billing Month'] ?? ''))] ?? null;

                    if ($villa === null || $cat === null || $cycle === null) {
                        // Skipped, never invented — but COUNTED by cause. The first
                        // run dropped 26,720 of 26,722 legs in silence because the
                        // cycle labels did not match, and a silent skip looks exactly
                        // like an empty source.
                        $this->unresolved[$villa === null ? 'villa'
                            : ($cat === null ? 'item_category' : 'billing_cycle')]++;

                        continue;
                    }

                    $bill->splitPayments()->updateOrCreate(
                        [
                            'villa_id' => $villa,
                            'item_category_id' => $cat,
                            'billing_cycle_id' => $cycle,
                        ],
                        [
                            'amount' => $this->money($r['Amount'] ?? null),
                            'gst_amount' => $this->money($r['GST_Amount'] ?? null),
                            'tds_amount' => $this->money($r['TDS_Amount'] ?? null),
                            'total_amount' => $this->money($r['Invoice Amount'] ?? null),
                            'position' => $position,
                        ]
                    );
                    $legsWritten++;
                }
            });

            $written++;

            if ($written % 2000 === 0) {
                $this->line('  ... '.$written.' bills');
            }
        }

        $this->newLine();
        $this->info($dry ? 'DRY RUN — nothing written.' : 'Import complete.');
        $this->line(sprintf('   bills            %6d', $written));
        $this->line(sprintf('   split legs       %6d', $legsWritten));
        $this->warn(sprintf('   legs do NOT tie to gross on %d bills — §6.4 rule 1. Imported and '
            .'flagged, not dropped: a bill that did not balance in Creator is a fact.', $unbalanced));
        $this->warn(sprintf('   vendor name unresolved on %d bills (left null) — §6: names drift '
            .'and deleted vendors vanish from the master while their bills remain.', $unresolvedVendor));

        foreach ($this->unresolved as $what => $n) {
            if ($n > 0) {
                $this->warn(sprintf('   legs dropped, %-14s unresolved: %6d', $what, $n));
            }
        }

        if (! $dry) {
            $this->newLine();
            $this->line(sprintf('bills table now holds %d rows.', Bill::query()->count()));
        }

        return self::SUCCESS;
    }

    /**
     * Billing cycles by the label the expenses view uses (`July - 2026`).
     *
     * §6.4: a cycle is NEVER created here. An unmatched label leaves the leg out,
     * because deriving a cycle from a month name is the defect that put a junk
     * `"9-2026"` row into live accounting.
     */
    private function cycleMap(): array
    {
        $map = [];

        foreach (DB::table('billing_cycles')->get() as $c) {
            $full = (string) $c->month_name;
            $year = (string) $c->year;

            // `payment_master` spells it `July - 2026`.
            $map[$full.' - '.$year] = $c->id;
            $map[$full.'-'.$year] = $c->id;

            /*
             * `expenses` spells the SAME cycle `Jul 2026` — abbreviated month, one
             * space, no dash. Measured: ZERO of its 56 distinct labels match the
             * `Month - YYYY` form, which is why the first run resolved 2 split legs
             * out of 26,722 rows.
             *
             * This is §11's per-view instability reaching the VALUES, not just the
             * key names — and §12 of the field notes shows the same thing
             * (`Billing Month = "Mar 2026"`). Both spellings are aliased rather
             * than one being normalised, because either view may be the source.
             *
             * `Feburary` abbreviates to `Feb` like February does, so the live
             * misspelling still resolves.
             */
            $abbr = substr($full, 0, 3);
            $map[$abbr.' '.$year] = $c->id;
            $map[$abbr.'-'.$year] = $c->id;
            $map[$full.' '.$year] = $c->id;
        }

        return $map;
    }

    private function blank(mixed $v): bool
    {
        return $v === null || $v === '';
    }

    /** `₹ 160,450.00` -> `160450.00`. Symbol, spaces and Indian grouping removed. */
    private function money(?string $v): ?string
    {
        if ($v === null) {
            return null;
        }

        $clean = preg_replace('/[^\d.\-]/u', '', $v);

        return ($clean === '' || $clean === '-') ? null : $clean;
    }

    /** `24-Jul-2026 ` — dd-MMM-yyyy, often with a trailing space. */
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

    /** Verbatim, never trimmed — these can be lookup keys. */
    private function text(?string $v): ?string
    {
        return ($v === null || $v === '') ? null : $v;
    }
}
