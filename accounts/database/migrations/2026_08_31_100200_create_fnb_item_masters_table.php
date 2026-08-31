<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * fb.Item_Master — the hub of the F&B domain. Findings §2, F_B.ds:1702.
 *
 * Inventory, Vendor_Price_List, Vendor_Order_Booking_Item, Raw_Material_Request and
 * Request_Stock_for_Food all pick from `Item_Master.ID`. Build it before anything
 * that references it.
 *
 * ITEM_CATEGORY IS AN ACCOUNTS TABLE, NOT AN F&B ONE. The Creator picklist reads
 *
 *     values = accounts.Item_Category[Master_Category.F_B == true].ID
 *
 * so the category list is Accounts' 135 rows filtered by `master_categories.fb`,
 * which is true on `F&B` alone of the 10. Under §2.1's resolution (replace the
 * cluster, one schema) that cross-app call becomes an ordinary foreign key plus a
 * WHERE clause — which is the whole reason the two apps share a schema.
 *
 * MONEY IS decimal(16,4), NOT A FLOAT, matching Accounts' `Money` domain. Creator
 * declares Base_Price as `type = INR, format = commadotindian`; the format is a
 * display concern and does not belong in the column.
 *
 * `no_decimal_values` is Creator's own field name — a checkbox meaning "quantities
 * of this item are whole numbers" (eggs, bottles). Kept verbatim per the
 * preserve-source-names rule, though it reads like a negation.
 *
 * `variance` is `type = percentage`: the tolerance band on a delivered quantity.
 * Stored as a plain decimal, NOT divided by 100 — Creator stores 5 for 5%.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fnb_item_masters', function (Blueprint $table) {
            $table->id();
            $table->string('creator_id', 32)->nullable()->unique();

            $table->text('item_name');

            // Accounts' item_categories, scoped to F&B by master_categories.fb.
            // Nullable because the DS does not mark the picklist mandatory, and a
            // NOT NULL here would reject live rows on import.
            $table->foreignId('item_category_id')->nullable()
                ->constrained('item_categories')->nullOnDelete();

            $table->foreignId('fnb_uom_id')->nullable()
                ->constrained('fnb_uoms')->nullOnDelete();

            $table->decimal('base_price', 16, 4)->nullable();
            $table->decimal('variance', 8, 4)->nullable();
            $table->boolean('no_decimal_values')->default(false);

            $table->timestamps();

            $table->index('item_name');
            $table->index(['item_category_id', 'item_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fnb_item_masters');
    }
};
