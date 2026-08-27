<?php

namespace Database\Seeders;

use Database\Seeders\Concerns\ReadsMasterData;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Villas — from All_Villas.csv, the real report export of 22-Aug-2026.
 *
 * 254 records, 252 distinct names. This REPLACES the earlier seeding from
 * Villa_Master_recovered.json, which held 204 bare names recovered from the
 * Approvals export and nothing else.
 *
 * The report carries 18 columns; §3.1 describes ~40 fields. So the commercial
 * half is still missing — `Hide_From_Payments` (the load-bearing filter Bills and
 * Payments actually use), `Status`, `Inner_Circle`, `Expense_Base_Amount`, the
 * GST and revenue/expense split percentages, the F&B commercial fields, both
 * category-scoping mechanisms, and the Villa_Managers / Owner_Details grids. Those
 * need a form-level export, not a report export.
 *
 * `Nature` genuinely appears THREE TIMES as three separate records with distinct
 * ids, confirming addendum §3. Seeding keys on creator_id, so all three survive as
 * three rows — which is correct: each is a distinct grouping key for §5.1 splits.
 *
 * Twelve names carry a LEADING SPACE and they are real records, not artefacts.
 * Inserted verbatim.
 *
 * RENT TYPE — correcting §3.1. The picklist may offer four values, but the live
 * data holds only two: `Lease` ×180 and `Revenue Share` ×65, with 9 unset. There
 * are ZERO `Revenue Split EKOSTAY` and ZERO `Expense Split EKOSTAY` records. So
 * the "live correctness bug" §3.1 describes is **latent, not live** — it becomes
 * real the moment anyone selects an EKOSTAY type. The CHECK constraint on the
 * column still admits all four deliberately, so the domain cannot be narrowed
 * again, and VillaRentTypeTest still asserts a fixture per value.
 *
 * CATEGORY — also correcting §3.1 and handoff §2 rule 7. The documented
 * misspelling `Luxery` does NOT occur. The data holds `Gold` ×123, `Original` ×86,
 * `Luxury` ×34 (spelled correctly), 11 blank.
 */
class VillaSeeder extends Seeder
{
    use ReadsMasterData;

    public function run(): void
    {
        $rows = $this->masterDataCsv('All_Villas.csv');

        $locations = DB::table('locations')->pluck('id', 'name');
        $states = DB::table('states')->pluck('id', 'name');
        $offices = DB::table('head_offices')->pluck('id', 'name');

        foreach ($rows as $row) {
            $location = $this->text($row['Location'] ?? null);
            $state = $this->text($row['State'] ?? null);
            $office = $this->text($row['Head Office'] ?? null);

            DB::table('villas')->updateOrInsert(
                ['creator_id' => $this->id($row['ID'] ?? null)],
                [
                    'name' => $this->text($row['Villa Name'] ?? null),
                    'location_id' => $location !== null ? ($locations[$location] ?? null) : null,
                    'state_id' => $state !== null ? ($states[$state] ?? null) : null,
                    'head_office_id' => $office !== null ? ($offices[$office] ?? null) : null,
                    'ekostay_id' => $this->text($row['Ekostay ID'] ?? null),
                    'haewaya_id' => $this->text($row['Haewaya ID'] ?? null),
                    'max_occupancy' => is_numeric($row['Max Occ (member)'] ?? null)
                        ? (int) $row['Max Occ (member)']
                        : null,
                    'bhk' => $this->text($row['BHK'] ?? null),        // TEXT: '6.5BHK'
                    'bathroom' => $this->text($row['Bathroom'] ?? null),
                    'category' => $this->text($row['Category'] ?? null),
                    'rent_type' => $this->text($row['Rent Type'] ?? null),
                    'active' => $this->bool($row['Active'] ?? null),
                    'is_primary' => $this->bool($row['Primary'] ?? null),
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }

        $this->linkHierarchy($rows);
    }

    /**
     * `Primary Villa` and `Secondary Villa` are self-references by NAME, and
     * `Nature` resolves to three different records — so a name lookup is
     * ambiguous for it. Those are skipped rather than guessed at; §3.1 lists the
     * hierarchy semantics as an open [TODO] anyway.
     */
    private function linkHierarchy(array $rows): void
    {
        $byName = DB::table('villas')
            ->select('id', 'name')
            ->get()
            ->groupBy('name')
            ->map(fn ($group) => $group->count() === 1 ? $group->first()->id : null);

        foreach ($rows as $row) {
            $primary = $this->text($row['Primary Villa'] ?? null);
            $creatorId = $this->id($row['ID'] ?? null);

            if ($primary === null || $creatorId === null) {
                continue;
            }

            $target = $byName[$primary] ?? null;

            if ($target === null) {
                continue;   // unknown, or an ambiguous name like 'Nature'
            }

            DB::table('villas')
                ->where('creator_id', $creatorId)
                ->update(['primary_villa_id' => $target, 'updated_at' => now()]);
        }
    }
}
