<?php

namespace Database\Seeders;

use Database\Seeders\Concerns\ReadsMasterData;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * COA_Report.json — 144 records, the messiest master.
 *
 * CA masters are derived from the `CA Name` values present on this export
 * (Jitesh x6, Keshav x1) because no CA_Master export exists in the working set.
 * They are created name-only; phone, email and bank are left null.
 *
 * The boolean labelled `COA` on the report is stored as `hide` — see the
 * migration docblock and the open [TODO] in addendum §3.
 *
 * Seeding is keyed on creator_id, which is why the genuine duplicate
 * `EKOSTAY IDFC LLP` survives as two rows: they carry different record ids.
 */
class CoaAccountSeeder extends Seeder
{
    use ReadsMasterData;

    public function run(): void
    {
        $rows = $this->masterData('COA_Report.json');

        $caNames = collect($rows)
            ->map(fn (array $r): ?string => $this->text($r['CA Name'] ?? null))
            ->filter()
            ->unique()
            ->values();

        foreach ($caNames as $name) {
            DB::table('ca_masters')->updateOrInsert(
                ['name' => $name],
                ['updated_at' => now(), 'created_at' => now()]
            );
        }

        $cas = DB::table('ca_masters')->pluck('id', 'name');

        foreach ($rows as $row) {
            $caName = $this->text($row['CA Name'] ?? null);

            DB::table('coa_accounts')->updateOrInsert(
                ['creator_id' => $this->id($row['ID'] ?? null)],
                [
                    'account_name' => $this->text($row['Account Name'] ?? null),
                    'account_type' => $this->text($row['Account Type'] ?? null),
                    'account_code' => $this->text($row['Account Code'] ?? null),
                    'books_account_id' => $this->id($row['Account ID'] ?? null),
                    'bank' => $this->bool($row['Bank'] ?? null),
                    'hide' => $this->bool($row['COA'] ?? null),
                    'ca_master_id' => $caName === null ? null : $cas[$caName],
                    'ca_name_source' => $caName,
                    'ekostay_id' => $this->text($row['Ekostay ID'] ?? null),
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }
    }
}
