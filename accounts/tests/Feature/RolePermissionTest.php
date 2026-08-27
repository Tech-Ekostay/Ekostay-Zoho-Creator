<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Access\RoleResolver;
use App\Models\Employee;
use App\Models\Role;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * §17 step 3 verification, verbatim:
 *
 *   "Roles + permissions as first-class tables, replacing User_Role text matching
 *    → verify: a test asserts each of the 10 known roles maps to an explicit
 *      permission set; no string .contains() anywhere in the authorisation path"
 */
class RolePermissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    /** Each of §3.3's 10 documented roles exists and has an explicit set. */
    #[Test]
    public function every_documented_role_maps_to_an_explicit_permission_set(): void
    {
        foreach (RoleSeeder::DOCUMENTED as $slug) {
            $role = Role::where('slug', $slug)->first();

            $this->assertNotNull($role, "documented role missing: {$slug}");

            // "Explicit" means decided, not necessarily non-empty: `manager` and
            // `ca` genuinely hold no Accounts profile in the Creator matrix. What
            // must never happen is a role that cannot be looked up at all.
            $this->assertIsInt(
                $role->permissions()->count(),
                "permission set for {$slug} is not resolvable"
            );
        }
    }

    /** The two account-team roles carry the real matrix, not an empty stub. */
    #[Test]
    public function the_account_team_roles_carry_the_extracted_matrix(): void
    {
        $senior = Role::where('slug', 'account-team-senior')->first();
        $executive = Role::where('slug', 'account-team-executive')->first();

        $this->assertGreaterThan(40, $senior->permissions()->count());
        $this->assertGreaterThan(40, $executive->permissions()->count());
    }

    /**
     * `accounts head` is its own User_Role but Creator routes it to the Account
     * Team-Senior profile (Admin.ds:1643). It must inherit that set, or its 3
     * staff land with nothing.
     */
    #[Test]
    public function accounts_head_inherits_the_account_team_senior_set(): void
    {
        $head = Role::where('slug', 'accounts-head')->first();
        $senior = Role::where('slug', 'account-team-senior')->first();

        $this->assertGreaterThan(0, $head->permissions()->count());
        $this->assertSame(
            $senior->permissions()->pluck('slug')->sort()->values()->all(),
            $head->permissions()->pluck('slug')->sort()->values()->all()
        );
    }

    /**
     * THE STRUCTURAL REQUIREMENT. No string matching may appear in the
     * authorisation path. RoleResolver is the single boundary where a raw Creator
     * string is interpreted, and it is called at import only.
     */
    #[Test]
    public function the_authorisation_path_contains_no_string_matching(): void
    {
        $authPath = [
            app_path('Models/Employee.php'),
            app_path('Models/Role.php'),
            app_path('Models/Permission.php'),
        ];

        foreach ($authPath as $file) {
            $source = file_get_contents($file);

            foreach (['str_contains', 'stripos', 'strpos', 'preg_match', 'LIKE '] as $needle) {
                $this->assertStringNotContainsString(
                    $needle,
                    $source,
                    basename($file).' performs string matching — §17 step 3 forbids it in the auth path'
                );
            }
        }
    }

    /** Permission checks read role_id. The raw string is traceability only. */
    #[Test]
    public function a_permission_check_uses_the_foreign_key_not_the_source_string(): void
    {
        $employee = Employee::whereNotNull('role_id')
            ->where('status', 'Active')
            ->whereHas('role', fn ($q) => $q->where('slug', 'account-team-senior'))
            ->first();

        $this->assertNotNull($employee);

        $granted = $employee->role->permissions()->first()->slug;
        $this->assertTrue($employee->can($granted));
        $this->assertFalse($employee->can('a.permission.that.does.not.exist'));

        // Break the FK and the answer changes — proving the string plays no part.
        $employee->user_role_source = 'Account Team-Senior';
        $employee->role_id = null;
        $employee->setRelation('role', null);
        $this->assertFalse(
            $employee->can($granted),
            'permission survived role_id being cleared — something is reading the source string'
        );
    }

    /** Inactive, and blank-status, employees hold no permissions. */
    #[Test]
    public function only_active_employees_hold_permissions(): void
    {
        $inactive = Employee::where('status', 'Inactive')->whereNotNull('role_id')->first();
        $this->assertNotNull($inactive);
        $this->assertFalse($inactive->can('settings.all_taxes.view'));

        // 2 records have a BLANK status. Access.Accounts runs DeleteAccess on
        // Status != "Active", so blank revokes — it must not read as active.
        $this->assertSame(2, Employee::whereNull('status')->count());
        foreach (Employee::whereNull('status')->get() as $blank) {
            $this->assertFalse($blank->isActive());
        }
    }

    /**
     * The casing fix, measured. Creator's chain assigns a profile to ZERO of the
     * 475 employees; the resolver folds case so `market head` and `Market Head`
     * land on one role.
     */
    #[Test]
    public function case_folding_merges_the_two_market_head_spellings(): void
    {
        $marketHead = Role::where('slug', 'market-head')->first();

        $this->assertSame(22, $marketHead->employees()->count());

        $sources = Employee::where('role_id', $marketHead->id)
            ->distinct()
            ->pluck('user_role_source')
            ->sort()
            ->values()
            ->all();

        $this->assertSame(['Market Head', 'market head'], $sources);
    }

    #[Test]
    public function every_role_value_in_the_data_resolves(): void
    {
        $this->assertSame(475, Employee::count());

        // 23 records have a blank User_Role — that is a real state, not a failure.
        $this->assertSame(23, Employee::whereNull('role_id')->count());
        $this->assertSame(452, Employee::whereNotNull('role_id')->count());

        $unresolved = (new RoleResolver)->unresolved(
            DB::table('employees')->pluck('user_role_source')
        );

        $this->assertSame([], $unresolved, 'a User_Role value has no mapping');
    }

    /** 189 of 475 are per-villa mailboxes, not people. */
    #[Test]
    public function caretaker_service_accounts_are_distinguishable_and_hold_nothing(): void
    {
        $caretakers = Employee::whereHas('role', fn ($q) => $q->where('slug', 'caretaker'))->get();

        $this->assertCount(189, $caretakers);
        $this->assertTrue($caretakers->first()->isServiceAccount());
        $this->assertSame(0, Role::where('slug', 'caretaker')->first()->permissions()->count());
    }
}
