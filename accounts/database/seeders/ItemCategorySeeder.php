<?php

namespace Database\Seeders;

use Database\Seeders\Concerns\ReadsMasterData;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * All_Item_Categories.json — 135 records.
 *
 * `Master Category` on this export is a NAME, not an id, so the FK is resolved by
 * exact string match. All 135 resolve against the 10 master categories with no
 * trimming or case folding required — verified 22-Aug-2026. The match is
 * deliberately exact: if a future export stops resolving, that is a real data
 * change and should fail here rather than be papered over with a fuzzy match.
 */
class ItemCategorySeeder extends Seeder
{
    use ReadsMasterData;

    public function run(): void
    {
        $masters = DB::table('master_categories')->pluck('id', 'name');

        foreach ($this->masterData('All_Item_Categories.json') as $row) {
            $masterName = $this->text($row['Master Category'] ?? null);

            if ($masterName !== null && ! $masters->has($masterName)) {
                throw new RuntimeException(
                    "unresolved master category on item category: ".var_export($masterName, true)
                );
            }

            DB::table('item_categories')->updateOrInsert(
                ['creator_id' => $this->id($row['ID'] ?? null)],
                [
                    'name' => $this->text($row['Item Category'] ?? null),
                    'master_category_id' => $masterName === null ? null : $masters[$masterName],
                    'expense_type' => $this->text($row['Expense Type'] ?? null),
                    'exclude_for_profit' => $this->bool($row['Exclude for Profit'] ?? null),
                    'exclude_for_observation' => $this->bool($row['Exclude for Observation'] ?? null),
                    'haewaya_id' => $this->text($row['Haewaya ID'] ?? null),
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }
    }
}
