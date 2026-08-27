<?php

namespace Database\Seeders;

use Database\Seeders\Concerns\ReadsMasterData;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/** All_Master_Categories.json — 10 records, exported 12-Aug-2026. */
class MasterCategorySeeder extends Seeder
{
    use ReadsMasterData;

    public function run(): void
    {
        foreach ($this->masterData('All_Master_Categories.json') as $row) {
            DB::table('master_categories')->updateOrInsert(
                ['creator_id' => $this->id($row['ID'] ?? null)],
                [
                    'name' => $this->text($row['Master Category'] ?? null),
                    'fb' => $this->bool($row['F&B'] ?? null),
                    'haewaya_id' => $this->text($row['Haewaya ID'] ?? null),
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }
    }
}
