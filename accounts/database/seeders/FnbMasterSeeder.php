<?php

namespace Database\Seeders;

use Database\Seeders\Concerns\ReadsMasterData;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * fb.UOM and fb.Item_Master, from the real Creator exports of 29-Aug-2026.
 *
 * `UOM Report.csv`        — 9 rows, one column
 * `All Item Masters.csv`  — 370 rows
 *
 * Two things this seeder deliberately does NOT do.
 *
 * IT DOES NOT TRIM. `UOM Report.csv` carries `Pieces ` with a trailing space, and
 * the item export references it by that exact string. Trimming would break the
 * join and destroy a live lookup key — the same hazard as
 * `F&B STAFF MEDICAL EXPENSE ` in Accounts, which `bootstrap/app.php` exempts from
 * Laravel's global TrimStrings middleware for precisely this reason.
 *
 * IT DOES NOT INVENT ITEM CATEGORIES. Categories are Accounts' 135 rows, scoped by
 * `master_categories.fb`. An item naming a category that does not exist gets a NULL
 * `item_category_id` and is reported — never a silently created category. Creator's
 * billing-cycle auto-create is the cautionary tale (spec §6.4: it put a junk
 * `"9-2026"` cycle into live accounting).
 */
class FnbMasterSeeder extends Seeder
{
    use ReadsMasterData;

    public function run(): void
    {
        $this->seedUoms();
        $this->seedItemMasters();
    }

    private function seedUoms(): void
    {
        $rows = $this->masterDataCsv('UOM Report.csv');
        if ($rows === null) {
            $this->command?->warn('fnb_uoms: SKIPPED — master-data/UOM Report.csv not found.');

            return;
        }

        $now = now();
        $insert = [];
        foreach ($rows as $r) {
            // NO trim() here, on purpose. See the class docblock.
            $name = $r['UOM'] ?? null;
            if ($name === null || $name === '') {
                continue;
            }
            $insert[] = ['name' => $name, 'created_at' => $now, 'updated_at' => $now];
        }

        DB::table('fnb_uoms')->insert($insert);

        $untrimmed = collect($insert)->filter(fn ($u) => $u['name'] !== trim($u['name']))->count();
        $this->command?->info(sprintf(
            'fnb_uoms: %d rows, %d carrying edge whitespace (kept).',
            count($insert), $untrimmed
        ));
    }

    private function seedItemMasters(): void
    {
        $rows = $this->masterDataCsv('All Item Masters.csv');
        if ($rows === null) {
            $this->command?->warn('fnb_item_masters: SKIPPED — master-data/All Item Masters.csv not found.');

            return;
        }

        // Match on the exact stored string, whitespace included.
        $uoms = DB::table('fnb_uoms')->pluck('id', 'name');
        $categories = DB::table('item_categories')->pluck('id', 'name');

        $now = now();
        $insert = [];
        $unmatchedUom = [];
        $unmatchedCat = [];

        foreach ($rows as $r) {
            $name = $r['Item Name'] ?? null;
            if ($name === null || $name === '') {
                continue;
            }

            $uomName = $r['UOM'] ?? null;
            $catName = $r['Item Category'] ?? null;

            $uomId = $uomName !== null && $uomName !== '' ? ($uoms[$uomName] ?? null) : null;
            $catId = $catName !== null && $catName !== '' ? ($categories[$catName] ?? null) : null;

            if ($uomName !== null && $uomName !== '' && $uomId === null) {
                $unmatchedUom[$uomName] = true;
            }
            if ($catName !== null && $catName !== '' && $catId === null) {
                $unmatchedCat[$catName] = true;
            }

            $insert[] = [
                'creator_id' => $this->id($r['ID'] ?? null),
                'item_name' => $name,
                'item_category_id' => $catId,
                'fnb_uom_id' => $uomId,
                // 'Variance ' — the export's header carries a trailing space.
                'base_price' => $this->decimal($r['Base Price'] ?? null),
                'variance' => $this->decimal($r['Variance '] ?? $r['Variance'] ?? null),
                'no_decimal_values' => $this->bool($r['No Decimal Values'] ?? null),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($insert, 500) as $chunk) {
            DB::table('fnb_item_masters')->insert($chunk);
        }

        $this->command?->info(sprintf(
            'fnb_item_masters: %d rows, %d with a category, %d with a UOM.',
            count($insert),
            collect($insert)->whereNotNull('item_category_id')->count(),
            collect($insert)->whereNotNull('fnb_uom_id')->count(),
        ));

        if ($unmatchedCat !== []) {
            $this->command?->warn(
                'item categories named by an item but absent from Accounts (left null, NOT created): '
                .implode(', ', array_keys($unmatchedCat))
            );
        }
        if ($unmatchedUom !== []) {
            $this->command?->warn(
                'UOMs named by an item but absent from the UOM export (left null): '
                .implode(', ', array_keys($unmatchedUom))
            );
        }
    }

    /** Money and percentages as fixed-scale strings. Never a float. */
    private function decimal(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }

        $s = preg_replace('/[^0-9.\-]/', '', (string) $v);

        return ($s === '' || $s === '-' || $s === '.') ? null : $s;
    }
}
