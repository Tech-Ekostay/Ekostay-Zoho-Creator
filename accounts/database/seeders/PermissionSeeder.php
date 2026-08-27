<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * §17 step 3 — permissions and the role pivot, seeded from the REAL Creator
 * matrix rather than invented.
 *
 * Source: `docs/permission_matrix.json`, extracted by `docs/parse_permissions.py`
 * from the `share_settings` block of `Accounts.ds` (addendum §13). 19 profiles,
 * per module, per report, with verbs View / Edit / Delete.
 *
 * Permission slugs are `module.report.verb`, lower-snake — e.g.
 * `bills.all_bills.view`. That granularity is Creator's own: it grants per REPORT,
 * not per module, which is why §3.3 observes the permission model is "expressed as
 * separate views" (CA_Payments, View_Payments, All_Payments_Hussain and the rest).
 *
 * FOUR PROFILES ARE NOT ROLES and are skipped: Read, Write, Write - same as admin,
 * and Administrator/Developer/Customer are Creator built-ins or generic
 * permission sets with no corresponding `User_Role` value. Mapping them onto roles
 * would invent authorisation that does not exist.
 *
 * The matrix is a snapshot of 08-Aug-2026. The live app has at least one profile
 * (`Admin`) that post-dates it — see addendum §13. Re-export before a cutover.
 */
class PermissionSeeder extends Seeder
{
    /**
     * Creator profile name => role slug(s). Only profiles that ARE roles.
     *
     * `accounts head` is its own `User_Role` value but Creator routes it to the
     * **Account Team-Senior** profile — `Admin.ds:1643` reads
     * `.contains("Account Team-Senior") || .contains("accounts head")`. So one
     * profile grants two roles, and the 3 `accounts head` staff must inherit the
     * senior permission set or they land with nothing.
     *
     * @var array<string, list<string>>
     */
    private const PROFILE_TO_ROLE = [
        'Account Team-Executive' => 'account-team-executive',
        'Account Team-Senior' => ['account-team-senior', 'accounts-head'],
        'Food Operator' => 'food-operator',
        'Property Manager' => 'property-manager',
        'Market Head' => 'market-head',
        'Central Operations' => 'central-operations',
        'Human Resources' => 'human-resources',
        'CA Team' => 'ca',
        'Dependant Property Owner' => 'dependant-property-owner',
        'Independant Property Owner' => 'independant-property-owner',
    ];

    /** Generic permission sets, not roles. Deliberately not mapped. */
    private const NOT_ROLES = [
        'Read', 'Write', 'Write - same as admin', 'Administrator',
        'Developer', 'Customer', 'Payment Request', 'Salary Data Entry', 'roles',
    ];

    public function run(): void
    {
        $path = base_path('docs/permission_matrix.json');

        if (! is_file($path)) {
            throw new RuntimeException('docs/permission_matrix.json missing — run docs/parse_permissions.py');
        }

        $profiles = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        // 1. every distinct (module, report, verb) becomes a permission
        $permissions = [];

        foreach ($profiles as $profile) {
            foreach ($profile['modules'] ?? [] as $module => $detail) {
                foreach ($detail['reports'] ?? [] as $report => $verbs) {
                    foreach ($verbs as $verb) {
                        $slug = $this->slug($module, $report, $verb);
                        $permissions[$slug] = [
                            'slug' => $slug,
                            'name' => "{$verb} {$report}",
                            'module' => Str::snake($module),
                        ];
                    }
                }
            }
        }

        foreach ($permissions as $permission) {
            DB::table('permissions')->updateOrInsert(
                ['slug' => $permission['slug']],
                $permission + ['updated_at' => now(), 'created_at' => now()]
            );
        }

        // 2. attach them to roles
        $permissionIds = DB::table('permissions')->pluck('id', 'slug');
        $roleIds = DB::table('roles')->pluck('id', 'slug');

        foreach ($profiles as $profile) {
            $name = $profile['profile'];

            if (in_array($name, self::NOT_ROLES, true)) {
                continue;
            }

            $roleSlugs = (array) (self::PROFILE_TO_ROLE[$name] ?? []);

            foreach ($roleSlugs as $roleSlug) {
                if (! isset($roleIds[$roleSlug])) {
                    continue;
                }

                foreach ($profile['modules'] ?? [] as $module => $detail) {
                    foreach ($detail['reports'] ?? [] as $report => $verbs) {
                        foreach ($verbs as $verb) {
                            $slug = $this->slug($module, $report, $verb);

                            DB::table('permission_role')->updateOrInsert(
                                [
                                    'role_id' => $roleIds[$roleSlug],
                                    'permission_id' => $permissionIds[$slug],
                                ],
                                ['updated_at' => now(), 'created_at' => now()]
                            );
                        }
                    }
                }
            }
        }
    }

    private function slug(string $module, string $report, string $verb): string
    {
        return Str::snake($module).'.'.Str::snake($report).'.'.strtolower($verb);
    }
}
