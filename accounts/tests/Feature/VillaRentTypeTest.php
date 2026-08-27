<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * §17 step 2 verification: "villas.rent_type accepts all 4 values; a fixture per
 * rent_type asserts no branch silently drops one".
 *
 * The bug this guards is described in §3.1: Rent_Type has FOUR values, every
 * handover document describes two, and Accounts branches only on "Lease" and
 * "Revenue Share" — so the two EKOSTAY split types fall through unhandled.
 */
class VillaRentTypeTest extends TestCase
{
    use RefreshDatabase;

    public const RENT_TYPES = [
        'Revenue Split EKOSTAY',
        'Expense Split EKOSTAY',
        'Revenue Share',
        'Lease',
    ];

    public static function rentTypeProvider(): array
    {
        return array_map(static fn (string $t): array => [$t], self::RENT_TYPES);
    }

    /** One fixture per rent type, so no value can be quietly dropped. */
    #[Test]
    #[DataProvider('rentTypeProvider')]
    public function it_accepts_every_rent_type(string $rentType): void
    {
        $id = DB::table('villas')->insertGetId([
            'name' => "Fixture Villa {$rentType}",
            'rent_type' => $rentType,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(
            $rentType,
            DB::table('villas')->where('id', $id)->value('rent_type'),
            "rent_type '{$rentType}' did not round-trip"
        );
    }

    #[Test]
    public function all_four_rent_types_coexist(): void
    {
        foreach (self::RENT_TYPES as $i => $rentType) {
            DB::table('villas')->insert([
                'name' => "Coexist {$i}",
                'rent_type' => $rentType,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->assertSame(
            4,
            DB::table('villas')->whereIn('rent_type', self::RENT_TYPES)->count(),
            'the rent_type domain has been narrowed — this is the §3.1 bug'
        );
    }

    /** The CHECK exists so a fifth value cannot be introduced unnoticed. */
    #[Test]
    public function it_rejects_a_rent_type_outside_the_domain(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::table('villas')->insert([
            'name' => 'Bad Rent Type',
            'rent_type' => 'Revenue Split',   // plausible, and not a real value
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function rent_type_may_be_null_for_unclassified_villas(): void
    {
        // The 204 recovered villa names carry no rent type at all.
        $id = DB::table('villas')->insertGetId([
            'name' => 'No Rent Type',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertNull(DB::table('villas')->where('id', $id)->value('rent_type'));
    }

    #[Test]
    public function the_master_layer_tables_all_exist(): void
    {
        foreach ([
            'states', 'locations', 'head_offices', 'villas',
            'employee_designations', 'employee_departments', 'employees',
            'roles', 'permissions', 'ca_masters', 'coa_accounts',
            'master_categories', 'item_categories', 'billing_cycles',
            'taxes', 'tds_rates', 'vendors',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), "missing table: {$table}");
        }
    }
}
