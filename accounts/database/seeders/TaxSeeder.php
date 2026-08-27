<?php

namespace Database\Seeders;

use Database\Seeders\Concerns\ReadsMasterData;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/** All_Taxes.json — 8 records. Export label is the correct `Tax Percentage`. */
class TaxSeeder extends Seeder
{
    use ReadsMasterData;

    public function run(): void
    {
        foreach ($this->masterData('All_Taxes.json') as $row) {
            DB::table('taxes')->updateOrInsert(
                ['creator_id' => $this->id($row['ID'] ?? null)],
                [
                    'name' => $this->text($row['Tax Name'] ?? null),
                    'tax_type' => $this->text($row['Tax Type'] ?? null),
                    'tax_percentage' => $this->decimal($row['Tax Percentage'] ?? null),
                    'books_tax_id' => $this->id($row['Tax ID'] ?? null),
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }
    }
}
