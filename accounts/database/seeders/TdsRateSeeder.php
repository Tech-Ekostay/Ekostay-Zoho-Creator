<?php

namespace Database\Seeders;

use Database\Seeders\Concerns\ReadsMasterData;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * TDS_Report.json — 35 records.
 *
 * The report has NO ID column, so there is no creator_id to key on and
 * updateOrInsert would have nothing stable to match. Keyed on books_id instead,
 * which is the only external identifier present.
 *
 * The 19 duplicate rows are intentionally preserved: they are what the live
 * picker shows, and deduping is blocked on whether the extra Books ids are live
 * in Books or orphaned (addendum §3).
 */
class TdsRateSeeder extends Seeder
{
    use ReadsMasterData;

    public function run(): void
    {
        foreach ($this->masterData('TDS_Report.json') as $row) {
            DB::table('tds_rates')->updateOrInsert(
                ['books_id' => $this->id($row['Books ID'] ?? null)],
                [
                    'name' => $this->text($row['TDS Name'] ?? null),
                    'tds_percentage' => $this->decimal($row['TDS Percentage'] ?? null),
                    'status' => $this->text($row['Status'] ?? null), // blank is a real state
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }
    }
}
