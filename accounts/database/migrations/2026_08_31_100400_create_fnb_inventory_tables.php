<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * fb.Inventory + fb.Inventory_Stock — how much of what is where.
 * F_B.ds:1504 and :1615.
 *
 * `Inventory` is one row per (warehouse × item): the CURRENT position, carrying
 * `Available_Qty` and `Price`. `Inventory_Stock` is its child grid — dated rows
 * that each add or hold quantity.
 *
 * ITEM_NAME IS A CASCADING PICKLIST. Creator declares
 *
 *     Item_Name  values = Item_Master[Item_Category.ID == input.Item_Category].ID
 *
 * so the item list depends on the category chosen on the SAME form. That is a UI
 * behaviour, but it implies an invariant worth holding in the database: the item's
 * own category must agree with the row's category. Not enforced with a CHECK —
 * Postgres cannot reference another table from one — so it belongs in the model
 * and in a test. Recorded here so nobody assumes the two are independent.
 *
 * `available_qty` is decimal, not integer: Item_Master carries `no_decimal_values`
 * precisely because SOME items are whole-number and most are not.
 *
 * UOM IS DUPLICATED ON PURPOSE. Both Inventory and Inventory_Stock carry their own
 * `UOM` picklist even though Item_Master already has one. Reproduced as-is — the
 * denormalisation is Creator's, and collapsing it would change behaviour if a
 * stock row was ever recorded in a different unit from the item's default.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fnb_inventories', function (Blueprint $table) {
            $table->id();
            $table->string('creator_id', 32)->nullable()->unique();

            $table->foreignId('fnb_warehouse_id')->nullable()
                ->constrained('fnb_warehouses')->nullOnDelete();

            // Accounts' table, F&B-scoped — same as Item_Master's.
            $table->foreignId('item_category_id')->nullable()
                ->constrained('item_categories')->nullOnDelete();

            $table->foreignId('fnb_item_master_id')->nullable()
                ->constrained('fnb_item_masters')->nullOnDelete();

            $table->foreignId('fnb_uom_id')->nullable()
                ->constrained('fnb_uoms')->nullOnDelete();

            $table->decimal('available_qty', 16, 4)->nullable();
            $table->decimal('price', 16, 4)->nullable();

            $table->timestamps();

            $table->index(['fnb_warehouse_id', 'fnb_item_master_id']);
        });

        Schema::create('fnb_inventory_stocks', function (Blueprint $table) {
            $table->id();
            $table->string('creator_id', 32)->nullable()->unique();

            // Creator's field is literally `Inventory` — the parent link.
            $table->foreignId('fnb_inventory_id')->nullable()
                ->constrained('fnb_inventories')->cascadeOnDelete();

            // Creator's field name is `Date_field`, because `Date` is reserved in
            // Deluge. Renamed to `stock_date` here: the Creator name carries no
            // information and is not a lookup key, unlike a picklist value.
            $table->date('stock_date')->nullable();

            $table->decimal('quantity', 16, 4)->nullable();
            $table->foreignId('fnb_uom_id')->nullable()
                ->constrained('fnb_uoms')->nullOnDelete();
            $table->decimal('price', 16, 4)->nullable();

            $table->timestamps();

            $table->index(['fnb_inventory_id', 'stock_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fnb_inventory_stocks');
        Schema::dropIfExists('fnb_inventories');
    }
};
