<?php

namespace Database\Seeders;

use Database\Seeders\Concerns\ReadsMasterData;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * fb.Warehouse and fb.Inventory, from `All Inventories.csv` (855 rows).
 *
 * There is no warehouse export. The 8 warehouses are RECOVERED from the inventory
 * rows that name them — the same move `VendorSeeder` used for `Alleppey` and
 * `FnbBillingCycleSeeder` used for the 14 cycles.
 *
 * THE UOM TRAP, AND WHY THIS SEEDER DOES NOT SIMPLY TRIM.
 *
 * Two exports of the same field disagree:
 *
 *     All Item Masters.csv   "Pieces "   (trailing space) x70
 *     All Inventories.csv    "Pieces"    (no space)       x14
 *
 * `fnb_uoms` stores what Item Master and `UOM Report.csv` agree on — `Pieces ` —
 * because that is the live lookup key 70 items join to. So an exact-match join from
 * THIS export finds nothing for those 14 rows.
 *
 * The fix is a RESOLVER, not a normalisation of the stored data:
 *
 *   1. try the exact string
 *   2. fall back to matching on the trimmed form
 *
 * The stored key keeps its space; only the incoming lookup is tolerant. This is the
 * distinction CLAUDE.md draws — "normalise at display only, never in data" — applied
 * to a join rather than a render. Trimming `fnb_uoms.name` instead would orphan the
 * 70 items and silently rewrite a key that Creator still issues with the space.
 *
 * VILLA NAME IS EMPTY ON ALL 855 ROWS. `fb.Warehouse.Villa_Name` is a multi-value
 * `list` field, and §12 measured that Analytics FLATTENS multi-value fields to one
 * silently-chosen value on export. Here it flattened to nothing at all. So the
 * `fnb_warehouse_villas` pivot stays empty and needs a form-level export — this is
 * evidence for §12, not a gap in the import.
 */
class FnbInventorySeeder extends Seeder
{
    use ReadsMasterData;

    public function run(): void
    {
        $rows = $this->masterDataCsv('All Inventories.csv');
        if ($rows === null) {
            $this->command?->warn(
                'fnb_warehouses / fnb_inventories: SKIPPED — master-data/All Inventories.csv '
                .'not found. It is git-ignored; ask Husain for it.'
            );

            return;
        }

        // Re-run guard: fnb_warehouses has no unique index on the name either, so
        // a second run would double it and orphan the inventory join.
        if (DB::table('fnb_warehouses')->count() > 0) {
            $this->command?->warn('fnb_warehouses / fnb_inventories: already populated, skipping.');

            return;
        }

        $warehouses = $this->seedWarehouses($rows);
        $this->seedInventories($rows, $warehouses);
    }

    /** @return array<string,int> warehouse name => id */
    private function seedWarehouses(array $rows): array
    {
        $names = [];
        foreach ($rows as $r) {
            $n = $r['Warehouse Name'] ?? null;
            if ($n !== null && trim($n) !== '') {
                $names[$n] = true;      // NOT trimmed — the name is the key
            }
        }
        ksort($names);

        $now = now();
        DB::table('fnb_warehouses')->insert(
            array_map(fn ($n) => [
                'warehouse_name' => $n,
                'created_at' => $now,
                'updated_at' => $now,
            ], array_keys($names))
        );

        $this->command?->info(sprintf(
            'fnb_warehouses: %d recovered from the inventory export (no warehouse export exists).',
            count($names)
        ));
        $this->command?->warn(
            'fnb_warehouse_villas / _locations left EMPTY: Villa Name is blank on all 855 '
            .'inventory rows. Those are multi-value list fields and Analytics flattens them '
            .'(spec §12) — needs a form-level export, not a re-run.'
        );

        return DB::table('fnb_warehouses')->pluck('id', 'warehouse_name')->all();
    }

    private function seedInventories(array $rows, array $warehouses): void
    {
        $items = DB::table('fnb_item_masters')->pluck('id', 'item_name');
        $categories = DB::table('item_categories')->pluck('id', 'name');

        // Exact key => id, plus a trimmed index for the fallback described above.
        $uoms = DB::table('fnb_uoms')->pluck('id', 'name');
        $uomsByTrimmed = [];
        foreach ($uoms as $name => $id) {
            $uomsByTrimmed[trim((string) $name)] ??= $id;
        }

        $now = now();
        $insert = [];
        $unmatchedItem = [];
        $uomExact = 0;
        $uomTrimmed = 0;
        $uomMissing = [];

        foreach ($rows as $r) {
            $itemName = $r['Item Name'] ?? null;
            if ($itemName === null || trim($itemName) === '') {
                continue;
            }

            $itemId = $items[$itemName] ?? null;
            if ($itemId === null) {
                $unmatchedItem[$itemName] = true;
            }

            // The resolver. Exact first, trimmed second, never a silent null.
            $uomRaw = $r['UOM'] ?? null;
            $uomId = null;
            if ($uomRaw !== null && $uomRaw !== '') {
                if (isset($uoms[$uomRaw])) {
                    $uomId = $uoms[$uomRaw];
                    $uomExact++;
                } elseif (isset($uomsByTrimmed[trim($uomRaw)])) {
                    $uomId = $uomsByTrimmed[trim($uomRaw)];
                    $uomTrimmed++;
                } else {
                    $uomMissing[$uomRaw] = true;
                }
            }

            $catName = $r['Item Category'] ?? null;

            $insert[] = [
                'creator_id' => $this->id($r['ID'] ?? null),
                'fnb_warehouse_id' => $warehouses[$r['Warehouse Name'] ?? ''] ?? null,
                'item_category_id' => ($catName !== null && $catName !== '')
                    ? ($categories[$catName] ?? null) : null,
                'fnb_item_master_id' => $itemId,
                'fnb_uom_id' => $uomId,
                'available_qty' => $this->decimal($r['Available Qty'] ?? null),
                // The export calls it Base Price; the column is `price`, matching
                // Creator's own field name on fb.Inventory.
                'price' => $this->decimal($r['Base Price'] ?? null),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($insert, 500) as $chunk) {
            DB::table('fnb_inventories')->insert($chunk);
        }

        $this->command?->info(sprintf(
            'fnb_inventories: %d rows, %d with an item, %d with a warehouse.',
            count($insert),
            collect($insert)->whereNotNull('fnb_item_master_id')->count(),
            collect($insert)->whereNotNull('fnb_warehouse_id')->count(),
        ));

        if ($uomTrimmed > 0) {
            $this->command?->warn(sprintf(
                'UOM: %d matched exactly, %d only after trimming. This export writes "Pieces" '
                .'while Item Master and UOM Report write "Pieces " — the stored key keeps its '
                .'space; only the lookup is tolerant.',
                $uomExact, $uomTrimmed
            ));
        }

        if ($uomMissing !== []) {
            $this->command?->warn(
                'UOMs named by an inventory row and absent from fnb_uoms (left null, NOT created): '
                .implode(', ', array_map(fn ($u) => '"'.$u.'"', array_keys($uomMissing)))
            );
        }

        if ($unmatchedItem !== []) {
            $this->command?->warn(sprintf(
                '%d item names in the inventory export do not exist in fnb_item_masters '
                .'(left null, NOT created): %s%s',
                count($unmatchedItem),
                implode(', ', array_slice(array_keys($unmatchedItem), 0, 6)),
                count($unmatchedItem) > 6 ? ' …' : ''
            ));
        }
    }

    /** Fixed-scale decimal strings. Never a float. */
    private function decimal(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        $s = preg_replace('/[^0-9.\-]/', '', (string) $v);

        return ($s === '' || $s === '-' || $s === '.') ? null : $s;
    }
}
