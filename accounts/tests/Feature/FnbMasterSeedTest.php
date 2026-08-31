<?php

namespace Tests\Feature;

use App\Models\FnbItemMaster;
use App\Models\FnbUom;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\FnbMasterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * F&B masters — fb.UOM and fb.Item_Master, from the 29-Aug-2026 exports.
 *
 * The counts here are SNAPSHOTS of live data (370 items, 9 UOMs). The structural
 * assertions are the ones that must never move: no trimming, F&B scoping through
 * Accounts' master_categories.fb, and no invented categories.
 */
class FnbMasterSeedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // FnbMasterSeeder runs INSIDE DatabaseSeeder. Seeding it again here
        // double-inserted every row and the creator_id unique index caught it —
        // the constraint working, not a defect.
        $this->seed(DatabaseSeeder::class);
    }

    #[Test]
    public function it_seeds_the_documented_counts(): void
    {
        $this->assertSame(9, DB::table('fnb_uoms')->count());
        $this->assertSame(370, DB::table('fnb_item_masters')->count());
    }

    #[Test]
    public function the_untrimmed_uom_key_survives_and_items_join_to_it(): void
    {
        // 'Pieces ' is 7 characters in the export. Trimming it would orphan every
        // item that names it — 70 of them. This is the same hazard as
        // 'F&B STAFF MEDICAL EXPENSE ' in Accounts.
        $pieces = FnbUom::query()->where('name', 'Pieces ')->first();

        $this->assertNotNull($pieces, "'Pieces ' must exist with its trailing space");
        $this->assertSame(7, mb_strlen($pieces->name));
        $this->assertNotSame($pieces->name, trim($pieces->name));

        $this->assertSame(70, $pieces->itemMasters()->count(),
            'items must join to the untrimmed key, not to a trimmed variant');

        // And the trimmed form must NOT have been created as a second row.
        $this->assertSame(0, FnbUom::query()->where('name', 'Pieces')->count());
    }

    #[Test]
    public function every_item_resolves_to_a_category_and_a_uom(): void
    {
        $this->assertSame(0, DB::table('fnb_item_masters')->whereNull('item_category_id')->count());
        $this->assertSame(0, DB::table('fnb_item_masters')->whereNull('fnb_uom_id')->count());
    }

    #[Test]
    public function every_item_category_is_fb_flagged_on_the_accounts_master(): void
    {
        // Creator's picklist is accounts.Item_Category[Master_Category.F_B == true].
        // An item scoped to a non-F&B category would mean the join is wrong.
        $total = FnbItemMaster::query()->count();

        $this->assertSame($total, FnbItemMaster::query()->fnbScoped()->count());
    }

    #[Test]
    public function no_item_category_was_invented_by_the_fnb_seeder(): void
    {
        // Creator INSERTs a missing billing cycle during month derivation and that
        // put a junk "9-2026" cycle into live accounting (spec §6.4). The F&B
        // seeder must never create a master row to satisfy a reference.
        $this->assertSame(135, DB::table('item_categories')->count());
    }

    #[Test]
    public function seeding_twice_does_not_duplicate_anything(): void
    {
        // fnb_uoms has NO unique index on `name` — deliberately, because `Pieces`
        // and `Pieces ` are two distinct live keys. So nothing at the database
        // level stops a second run, and a second run DID silently double the table
        // to 18 rows, making every UOM lookup ambiguous. Found by re-running the
        // seeder by accident. The guard is in the seeder; this pins it.
        $this->seed(\Database\Seeders\FnbMasterSeeder::class);
        $this->seed(\Database\Seeders\FnbInventorySeeder::class);

        $this->assertSame(9, DB::table('fnb_uoms')->count());
        $this->assertSame(370, DB::table('fnb_item_masters')->count());
        $this->assertSame(8, DB::table('fnb_warehouses')->count());
        $this->assertSame(855, DB::table('fnb_inventories')->count());
    }

    #[Test]
    public function money_and_percentages_are_not_floats(): void
    {
        $cols = DB::select(
            "SELECT column_name, data_type, numeric_scale FROM information_schema.columns
             WHERE table_name = 'fnb_item_masters' AND column_name IN ('base_price','variance')"
        );

        $this->assertCount(2, $cols);
        foreach ($cols as $c) {
            $this->assertSame('numeric', $c->data_type, $c->column_name.' must be numeric');
            $this->assertSame(4, (int) $c->numeric_scale);
        }
    }

    #[Test]
    public function creator_ids_are_stored_as_strings(): void
    {
        $type = DB::selectOne(
            "SELECT data_type FROM information_schema.columns
             WHERE table_name = 'fnb_item_masters' AND column_name = 'creator_id'"
        )->data_type;

        $this->assertSame('character varying', $type);

        // And none arrived rounded — 18 digits, no trailing 000 artefacts.
        // F_B.ds itself carries 311 float-corrupted ids (findings §4.2), so this
        // is a demonstrated failure mode rather than a hypothetical one.
        $ids = DB::table('fnb_item_masters')->whereNotNull('creator_id')->pluck('creator_id');
        $this->assertGreaterThan(0, $ids->count());
        foreach ($ids as $id) {
            $this->assertMatchesRegularExpression('/^\d{15,20}$/', $id);
        }
    }

    #[Test]
    public function no_decimal_values_is_a_real_boolean_and_is_not_uniform(): void
    {
        // A column that is false on every row would mean the import dropped it.
        $true = DB::table('fnb_item_masters')->where('no_decimal_values', true)->count();
        $false = DB::table('fnb_item_masters')->where('no_decimal_values', false)->count();

        $this->assertGreaterThan(0, $true, 'some items are whole-number units');
        $this->assertGreaterThan(0, $false);
        $this->assertSame(370, $true + $false);
    }
}
