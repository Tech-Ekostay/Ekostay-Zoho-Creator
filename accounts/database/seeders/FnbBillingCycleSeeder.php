<?php

namespace Database\Seeders;

use Database\Seeders\Concerns\ReadsMasterData;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * billing_cycles, recovered from the F&B order export.
 *
 * `accounts/CLAUDE.md` lists billing_cycles under "not seeded, no export exists".
 * There still is no cycle master, but `All Vendor Order Bookings.csv` names 14
 * distinct cycles across 11,149 orders, so they can be recovered the way locations
 * were recovered from the villa export.
 *
 * TWO SPELLINGS OF FEBRUARY, AND THE MISSPELLING IS THE COMMON ONE:
 *
 *     "Feburary - 2026"   847 orders     <- misspelled, dominant
 *     "February - 2026"    34 orders     <- correct
 *
 * Both are kept as separate rows. They are live lookup keys: 847 orders resolve
 * through the misspelling, and normalising it would orphan them. This is the
 * `multipe_hccc_names` situation again — CLAUDE.md is explicit that it "needs a
 * mapping table, not a normalisation function". The mapping belongs in a later
 * migration with a decision attached, not in an importer.
 *
 * §6.4 IS THE REASON THIS SEEDER EXISTS AT ALL. Creator INSERTs a missing billing
 * cycle during month derivation, and that is the defect that put a junk "9-2026"
 * cycle into live accounting. So cycles must exist BEFORE an order references one,
 * and the order importer must fail on an unknown cycle rather than create it.
 */
class FnbBillingCycleSeeder extends Seeder
{
    use ReadsMasterData;

    /** Creator's month spellings, misspelling included, in calendar order. */
    private const MONTHS = [
        'January' => 1, 'February' => 2, 'Feburary' => 2, 'March' => 3,
        'April' => 4, 'May' => 5, 'June' => 6, 'July' => 7, 'August' => 8,
        'September' => 9, 'October' => 10, 'November' => 11, 'December' => 12,
    ];

    public function run(): void
    {
        if (DB::table('billing_cycles')->count() > 0) {
            $this->command?->warn('billing_cycles: already populated, skipping.');

            return;
        }

        $rows = $this->masterDataCsv('All Vendor Order Bookings.csv');
        if ($rows === null) {
            $this->command?->warn(
                'billing_cycles: SKIPPED — master-data/All Vendor Order Bookings.csv not found. '
                .'It is git-ignored (11,205 orders naming vendors, villas and guest stay dates); '
                .'ask Husain for it.'
            );

            return;
        }

        $seen = [];
        foreach ($rows as $r) {
            $name = $r['Billing Cycle'] ?? null;
            if ($name === null || trim($name) === '') {
                continue;
            }
            $seen[trim($name)] = true;
        }

        $now = now();
        $insert = [];
        $unparsed = [];

        foreach (array_keys($seen) as $name) {
            // "July - 2026" -> month name + year. Kept verbatim in `name`.
            if (! preg_match('/^([A-Za-z]+)\s*-\s*(\d{4})$/', $name, $m)) {
                $unparsed[] = $name;

                continue;
            }

            $monthName = $m[1];
            $year = (int) $m[2];
            $monthNo = self::MONTHS[$monthName] ?? null;

            if ($monthNo === null) {
                $unparsed[] = $name;

                continue;
            }

            // Columns are the repo's own: month_name (as Creator spells it),
            // year as TEXT, month_index for ordering. There is no combined `name`
            // column, so the misspelling is preserved in month_name.
            $insert[] = [
                'month_name' => $monthName,
                'year' => (string) $year,
                'month_index' => $monthNo,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        usort($insert, fn ($a, $b) => [$a['year'], $a['month_index']] <=> [$b['year'], $b['month_index']]);

        DB::table('billing_cycles')->insert($insert);

        $this->command?->info(sprintf(
            'billing_cycles: %d recovered from the order export (%d..%d).',
            count($insert),
            min(array_column($insert, 'year')),
            max(array_column($insert, 'year')),
        ));

        // Both February spellings must have survived, or a mapping happened by
        // accident somewhere.
        $febs = collect($insert)->where('month_index', 2)->pluck('month_name')->unique()->all();
        if (count($febs) > 1) {
            $this->command?->warn(
                'two spellings of February kept as distinct cycles (both are live lookup '
                .'keys, 847 vs 34 orders): '.implode(' | ', $febs)
            );
        }

        if ($unparsed !== []) {
            $this->command?->warn(
                'billing cycle names that did not parse (NOT inserted): '.implode(', ', $unparsed)
            );
        }
    }
}
