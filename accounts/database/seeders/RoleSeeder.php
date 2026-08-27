<?php

namespace Database\Seeders;

use App\Domain\Access\RoleResolver;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Roles — §3.3, §17 step 3.
 *
 * §3.3 lists 10 "known roles". The live `Employee_Master` export holds **24
 * distinct values** (addendum §14), so seeding only the documented 10 would leave
 * 14 values with nowhere to resolve to and the authorisation path would have
 * silent holes — exactly what step 3 exists to remove.
 *
 * Roles that hold no Accounts permissions are still roles. A caretaker is a real
 * role with an empty permission set; that is different from an unrecognised
 * string, and the distinction has to survive into the schema.
 *
 * `documented` marks the 10 from §3.3 so the divergence stays visible.
 */
class RoleSeeder extends Seeder
{
    /** The 10 roles §3.3 lists. */
    public const DOCUMENTED = [
        'account-team-executive', 'account-team-senior', 'accounts-head',
        'food-operator', 'property-manager', 'market-head', 'central-operations',
        'human-resources', 'manager', 'ca',
    ];

    /** Display names, keyed by slug. Sourced from the live data's own casing. */
    private const NAMES = [
        'account-team-executive' => 'Account Team-Executive',
        'account-team-senior' => 'Account Team-Senior',
        'accounts-head' => 'accounts head',
        'food-operator' => 'Food Operator',
        'property-manager' => 'Property Manager',
        'market-head' => 'Market Head',
        'central-operations' => 'Central Operations',
        'human-resources' => 'Human Resources',
        'ca' => 'CA',
        'manager' => 'Manager',
        'caretaker' => 'Caretaker',
        'salesperson' => 'Salesperson',
        'sales-manager' => 'Sales Manager',
        'dependant-property-owner' => 'Dependant Property Owner',
        'independant-property-owner' => 'Independant Property Owner',
        'operations-executor' => 'Operations Executor',
        'store-keeper' => 'Store Keeper',
        'superadmin' => 'Superadmin',
        'administrator' => 'Administrator',
        'promoter' => 'Promoter',
        'vendor' => 'Vendor',
        'co-founder' => 'Co-founder',
        'check-in-assistant' => 'Check-in Assistant',
        'ops-analyst' => 'Ops Analyst',
        'data-entry' => 'Data Entry',
    ];

    public function run(): void
    {
        $sourceBySlug = [];

        foreach (RoleResolver::SOURCE_TO_SLUG as $source => $slug) {
            $sourceBySlug[$slug] ??= $source;
        }

        foreach (self::NAMES as $slug => $name) {
            DB::table('roles')->updateOrInsert(
                ['slug' => $slug],
                [
                    'name' => $name,
                    'source_label' => $sourceBySlug[$slug] ?? $slug,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }
    }
}
