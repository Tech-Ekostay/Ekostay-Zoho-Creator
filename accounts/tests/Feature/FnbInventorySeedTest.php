<?php

namespace Tests\Feature;

use App\Models\FnbInventory;
use App\Models\FnbUom;
use App\Models\FnbWarehouse;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * fb.Warehouse and fb.Inventory, from `All Inventories.csv` (855 rows).
 *
 * The counts are snapshots. The structural assertions are the ones that matter:
 * the UOM trailing-space resolver, no invented masters, and the empty villa pivot
 * being evidence of §12 flattening rather than a failed import.
 */
class FnbInventorySeedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    #[Test]
    public function it_recovers_the_eight_warehouses(): void
    {
        // No warehouse export exists — these are recovered from the inventory rows
        // that name them, as Alleppey was recovered from a vendor.
        $this->assertSame(8, DB::table('fnb_warehouses')->count());

        $names = FnbWarehouse::query()->orderBy('warehouse_name')->pluck('warehouse_name')->all();
        $this->assertContains('Kamshet Warehouse', $names);
        // Creator's own casing: lowercase 's' on this one alone. Preserved.
        $this->assertContains('Casablanca storeroom', $names);
    }

    #[Test]
    public function every_inventory_row_resolves_to_an_item_and_a_warehouse(): void
    {
        $this->assertSame(855, DB::table('fnb_inventories')->count());
        $this->assertSame(0, DB::table('fnb_inventories')->whereNull('fnb_item_master_id')->count());
        $this->assertSame(0, DB::table('fnb_inventories')->whereNull('fnb_warehouse_id')->count());
    }

    #[Test]
    public function the_uom_resolver_matches_pieces_across_a_trailing_space(): void
    {
        // THE TRAP. `All Item Masters.csv` and `UOM Report.csv` write "Pieces " with
        // a trailing space; `All Inventories.csv` writes "Pieces" without. An exact
        // join would leave 14 rows with no UOM. The resolver falls back to the
        // trimmed form WITHOUT rewriting the stored key.
        $pieces = FnbUom::query()->where('name', 'Pieces ')->first();

        $this->assertNotNull($pieces, 'the stored key must keep its trailing space');
        $this->assertSame(7, mb_strlen($pieces->name));

        $this->assertSame(14, FnbInventory::query()->where('fnb_uom_id', $pieces->id)->count(),
            'the 14 "Pieces" inventory rows must resolve to the space-suffixed UOM');

        // And no trimmed duplicate was created to make the join work.
        $this->assertSame(0, FnbUom::query()->where('name', 'Pieces')->count());
    }

    #[Test]
    public function a_blank_uom_stays_null_rather_than_guessing(): void
    {
        // 8 inventory rows have a genuinely empty UOM in the source. They are not
        // lookup failures and must not acquire a UOM by inference.
        $this->assertSame(8, DB::table('fnb_inventories')->whereNull('fnb_uom_id')->count());
    }

    #[Test]
    public function the_villa_pivot_is_empty_and_that_is_analytics_flattening(): void
    {
        // fb.Warehouse.Villa_Name is a multi-value `list`. §12 measured that
        // Analytics flattens multi-value fields to one silently-chosen value; here
        // it flattened to nothing on all 855 rows. Asserting the emptiness so that
        // a future form-level export changing it is a visible event.
        $this->assertSame(0, DB::table('fnb_warehouse_villas')->count());
        $this->assertSame(0, DB::table('fnb_warehouse_locations')->count());
    }

    #[Test]
    public function no_master_was_invented_to_satisfy_a_reference(): void
    {
        // Creator auto-creates a missing billing cycle during month derivation, and
        // that put a junk "9-2026" cycle into live accounting (spec §6.4). The F&B
        // seeders must never do the equivalent.
        $this->assertSame(370, DB::table('fnb_item_masters')->count());
        $this->assertSame(9, DB::table('fnb_uoms')->count());
        $this->assertSame(135, DB::table('item_categories')->count());
    }

    #[Test]
    public function quantities_and_prices_are_numeric_not_float(): void
    {
        $cols = DB::select(
            "SELECT column_name, data_type FROM information_schema.columns
             WHERE table_name = 'fnb_inventories' AND column_name IN ('available_qty','price')"
        );

        $this->assertCount(2, $cols);
        foreach ($cols as $c) {
            $this->assertSame('numeric', $c->data_type, $c->column_name.' must be numeric');
        }

        // 390 rows sit at zero stock and none is negative — worth pinning, because a
        // negative available quantity would mean the stock ledger had drifted.
        $this->assertSame(0, DB::table('fnb_inventories')->where('available_qty', '<', 0)->count());
    }
}
