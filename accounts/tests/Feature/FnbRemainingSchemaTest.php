<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The nine remaining F&B tables. Findings §13.
 *
 * These pin the constraints that encode a decision, not the column lists.
 */
class FnbRemainingSchemaTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function all_twenty_two_fnb_tables_exist(): void
    {
        $n = DB::selectOne(
            "SELECT count(*) AS c FROM information_schema.tables
             WHERE table_schema = 'public' AND table_name LIKE 'fnb_%'"
        )->c;

        $this->assertSame(22, (int) $n);
    }

    #[Test]
    public function a_warehouse_cannot_transfer_to_itself(): void
    {
        // Creator's To_Warehouse picklist excludes the source
        // (Warehouse[Warehouse_Name != …]), so a self-transfer is unreachable
        // through the UI. Enforced in the database rather than trusted to a
        // picklist — browser-side validation is not a boundary.
        $w = DB::table('fnb_warehouses')->insertGetId(
            ['warehouse_name' => 'Test Store', 'created_at' => now(), 'updated_at' => now()]
        );

        $this->expectException(QueryException::class);

        DB::table('fnb_transfer_items')->insert([
            'transfer_id' => 'T1',
            'from_fnb_warehouse_id' => $w,
            'to_fnb_warehouse_id' => $w,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    #[Test]
    public function a_transfer_between_two_warehouses_is_allowed(): void
    {
        $a = DB::table('fnb_warehouses')->insertGetId(
            ['warehouse_name' => 'A', 'created_at' => now(), 'updated_at' => now()]
        );
        $b = DB::table('fnb_warehouses')->insertGetId(
            ['warehouse_name' => 'B', 'created_at' => now(), 'updated_at' => now()]
        );

        DB::table('fnb_transfer_items')->insert([
            'transfer_id' => 'T2',
            'from_fnb_warehouse_id' => $a,
            'to_fnb_warehouse_id' => $b,
            'status' => 'Transfer Initiated',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame(1, DB::table('fnb_transfer_items')->count());
    }

    #[Test]
    public function the_stock_ledger_admits_reverse_as_a_transaction_type(): void
    {
        // `Reverse` is how a stock mistake is undone — the same shape as the payment
        // reversal Accounts built for D4. Stock is never edited backwards; a
        // correction is another row.
        $types = ['In', 'Out', 'Transfer', 'Damaged', 'Misplaced', 'Reverse'];

        foreach ($types as $t) {
            DB::table('fnb_transaction_items')->insert([
                'transaction_type' => $t,
                'quantity' => '1',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->assertSame(6, DB::table('fnb_transaction_items')->count());
    }

    #[Test]
    public function an_invented_transaction_type_is_rejected(): void
    {
        $this->expectException(QueryException::class);

        DB::table('fnb_transaction_items')->insert([
            'transaction_type' => 'Wastage',      // plausible, not a Creator value
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    #[Test]
    public function recipe_requirements_are_category_agnostic(): void
    {
        // Creator has FOUR grids hardcoded to KIRANA, DAIRY, VEGETABLES and MEAT,
        // which reach 335 of 370 items — FRUITS, BAKERY and three others are
        // unreachable from that form. Whether a recipe SHOULD name a fruit is
        // TODO-FNB-6; the table takes any item so the answer is a query change.
        $this->seed(\Database\Seeders\DatabaseSeeder::class);

        $recipe = DB::table('fnb_recipe_masters')->insertGetId(
            ['recipe_name' => 'Fruit Salad', 'created_at' => now(), 'updated_at' => now()]
        );

        // A FRUITS item — one of the 35 Creator's grids cannot see.
        $fruit = DB::table('fnb_item_masters')
            ->join('item_categories', 'item_categories.id', '=', 'fnb_item_masters.item_category_id')
            ->where('item_categories.name', 'FRUITS')
            ->value('fnb_item_masters.id');

        $this->assertNotNull($fruit, 'the FRUITS category should have items');

        DB::table('fnb_recipe_requirements')->insert([
            'fnb_recipe_master_id' => $recipe,
            'fnb_item_master_id' => $fruit,
            'quantity' => '2',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame(1, DB::table('fnb_recipe_requirements')->count());
    }

    #[Test]
    public function a_vendor_can_price_an_item_only_once(): void
    {
        $this->seed(\Database\Seeders\DatabaseSeeder::class);

        $item = DB::table('fnb_item_masters')->value('id');
        $vendor = DB::table('vendors')->value('id');

        $row = [
            'fnb_item_master_id' => $item,
            'vendor_id' => $vendor,
            'price' => '100',
            'created_at' => now(), 'updated_at' => now(),
        ];

        DB::table('fnb_vendor_price_lists')->insert($row);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        DB::table('fnb_vendor_price_lists')->insert($row);
    }

    #[Test]
    public function chef_status_is_constrained_to_creators_two_values(): void
    {
        DB::table('fnb_chef_masters')->insert([
            'name' => 'Test Chef', 'status' => 'Active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        DB::table('fnb_chef_masters')->insert([
            'name' => 'Other', 'status' => 'Retired',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
