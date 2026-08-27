<?php

namespace Database\Seeders;

use Database\Seeders\Concerns\ReadsMasterData;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * States, head offices and locations — derived from All_Villas.csv.
 *
 * No State, Head_Office or Location export exists, but the villa report carries
 * all three as names, so the real sets can be recovered from it. This supersedes
 * the earlier LocationSeeder, which read the 10-name recovered file.
 *
 * WHY THAT MATTERED: the recovered file held only the locations referenced by an
 * approval rule. The villa master references **29**. Nineteen locations were
 * missing — Mussoorie, Nainital, Munnar, Jodhpur, Kasauli, Kufri, Dalhousie,
 * Chikmagalur, Arpookara, Solan, Bhimtal, Dehradun, Kullu and Manali, Mumbai,
 * Pune, Panvel, Nashik, Virar, Wada — so every villa in them had no location to
 * resolve to.
 *
 * The footprint is also wider than the docs say. §1 of the handoff lists five
 * states plus Kodaikanal and Bangalore; the data holds **nine**:
 *
 *   Maharashtra 157 · Goa 37 · Tamil Nadu 31 · Himachal Pradesh 13 ·
 *   Uttarakand 7 · Rajasthan 3 · Karnataka 2 · Kerala 2 · (blank) 2
 *
 * `Uttarakand` is missing its 'h'. It is a live grouping key, so it is inserted
 * verbatim and normalised at display only — the same rule as every other source
 * misspelling. It is NOT in the handoff §2 rule-7 list and should be added.
 */
class GeographySeeder extends Seeder
{
    use ReadsMasterData;

    public function run(): void
    {
        $rows = $this->masterDataCsv('All_Villas.csv');

        $this->seedNames('states', $rows, 'State');
        $this->seedNames('head_offices', $rows, 'Head Office');
        $this->seedNames('locations', $rows, 'Location');

        // Resolve locations onto their state and head office, taking the first
        // non-null pairing seen. A location that appears under two states would be
        // a data problem worth surfacing rather than silently picking one.
        $states = DB::table('states')->pluck('id', 'name');
        $offices = DB::table('head_offices')->pluck('id', 'name');

        foreach ($rows as $row) {
            $location = $this->text($row['Location'] ?? null);

            if ($location === null) {
                continue;
            }

            $state = $this->text($row['State'] ?? null);
            $office = $this->text($row['Head Office'] ?? null);

            DB::table('locations')
                ->where('name', $location)
                ->whereNull('state_id')
                ->update([
                    'state_id' => $state !== null ? ($states[$state] ?? null) : null,
                    'head_office_id' => $office !== null ? ($offices[$office] ?? null) : null,
                    'updated_at' => now(),
                ]);
        }
    }

    private function seedNames(string $table, array $rows, string $column): void
    {
        $names = [];

        foreach ($rows as $row) {
            $name = $this->text($row[$column] ?? null);
            if ($name !== null) {
                $names[$name] = true;
            }
        }

        foreach (array_keys($names) as $name) {
            DB::table($table)->updateOrInsert(
                ['name' => $name],
                ['updated_at' => now(), 'created_at' => now()]
            );
        }
    }
}
