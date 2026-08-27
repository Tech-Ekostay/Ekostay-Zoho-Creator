<?php

namespace Database\Seeders;

use App\Domain\Access\RoleResolver;
use Database\Seeders\Concerns\ReadsMasterData;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * admin.Employee_Master — 475 records from `All_Employee_Masters.csv`.
 *
 * This is where §17 step 3's structural fix actually lands: every record gets a
 * `role_id` resolved ONCE here, through RoleResolver. `user_role_source` keeps the
 * verbatim Creator string for traceability and is never matched on again.
 *
 * The resolver folds case deliberately. Creator's own chain does not, and the
 * result is that it provisions **zero** of these 475 people (addendum §14). If any
 * role value in a future export fails to resolve, this seeder throws rather than
 * silently dropping the person into a permission-less state — Creator's silent
 * failure is the bug, not the model.
 *
 * Deliberately NOT imported: `Villas` (a comma-packed list of villa names, needs
 * the same mapping-table treatment as every other packed field) and `Employee ID`
 * / `Department`, which are blank on every row of this export.
 */
class EmployeeSeeder extends Seeder
{
    use ReadsMasterData;

    public function run(): void
    {
        /*
         * SKIPPED ON A FRESH CLONE, BY DESIGN. `All_Employee_Masters.csv` is excluded from
         * the repository because it carries the name, DOB, email and phone of 475 real employees, and git history cannot
         * practically be cleaned. So this seeder announces the gap instead of
         * throwing — the other seeders still run and the app still boots.
         */
        if (! $this->masterDataExists('All_Employee_Masters.csv')) {
            $this->command?->warn('employees: SKIPPED — master-data/All_Employee_Masters.csv is not in the repository.');
            $this->command?->line('   It holds the name, DOB, email and phone of 475 real employees, so it is git-ignored on purpose.');
            $this->command?->line('   Ask Husain for it over a private channel and drop it in master-data/.');
            $this->command?->line('   The app runs without it; `employees` stays empty and its pickers are empty with it.');

            return;
        }

        $rows = $this->masterDataCsv('All_Employee_Masters.csv');
        $resolver = new RoleResolver;

        $unresolved = $resolver->unresolved(array_column($rows, 'User Role'));

        if ($unresolved !== []) {
            throw new RuntimeException(
                'unmapped User_Role values: '.json_encode($unresolved)
                .' — add them to RoleResolver::SOURCE_TO_SLUG rather than letting them fall through'
            );
        }

        $locations = DB::table('locations')->pluck('id', 'name');

        foreach ($rows as $row) {
            $sourceRole = $this->text($row['User Role'] ?? null);
            $role = $resolver->resolve($sourceRole);

            // `Location` is a comma-packed list on this export; take the first
            // for the scalar FK and keep the raw string for later mapping.
            $locationRaw = $this->text($row['Location'] ?? null);
            $firstLocation = $locationRaw === null
                ? null
                : trim(explode(',', $locationRaw)[0]);

            DB::table('employees')->updateOrInsert(
                ['creator_id' => $this->id($row['ID'] ?? null)],
                [
                    'name' => $this->text($row['Name'] ?? null),
                    'employee_code' => $this->text($row['Employee ID'] ?? null),
                    'email' => $this->text($row['Email'] ?? null),
                    'phone' => $this->text($row['Phone'] ?? null),
                    'location_id' => $firstLocation !== null ? ($locations[$firstLocation] ?? null) : null,
                    'role_id' => $role?->id,
                    'user_role_source' => $sourceRole,   // traceability only
                    'status' => $this->text($row['Status'] ?? null),  // blank is a real state
                    'ekostay_id' => $this->text($row['Ekostay ID'] ?? null),
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }
    }
}
