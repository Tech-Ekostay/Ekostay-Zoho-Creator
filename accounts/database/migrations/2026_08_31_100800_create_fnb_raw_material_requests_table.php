<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * fb.Raw_Material_Request — one line per item requested. F_B.ds:1966, 25 fields.
 *
 * **160,995 live rows** — the second largest F&B table. Findings §11.
 *
 * SEVEN QUANTITIES, and they are not redundant (§11.3):
 *
 *     original_requested_quantity   what was first asked for
 *     requested_quantity            the current ask (MANDATORY in Creator)
 *     delivered_quantity            what arrived
 *     pending_quantity              the remainder
 *     available_quantity            warehouse stock at the time of asking
 *     warehouse_quantity            taken off the warehouse
 *     backend_warehouse_quantity    the allocation snapshot — always HIDDEN
 *     order_quantity                sent to a vendor instead
 *
 * `warehouse_quantity + order_quantity` is how a request splits between stock on
 * hand and a purchase order. That split is what populates
 * `fnb_vendor_order_booking_items.ordered_quantity`, and it is why that field is
 * read-only in the Items Ordered grid.
 *
 * THE DUPLICATED PAIRS ARE REAL FIELDS, NOT A RENDERING FAULT (§11.2). Creator
 * declares `Warehouse_Name` AND `Warehouse_Name1`, `Vendor_Name` AND `Vendor_Name1`,
 * with IDENTICAL `displayname` on each pair — so they are indistinguishable on
 * screen. The `_1` members plus `backend_warehouse_quantity` are hidden
 * unconditionally at F_B.ds:7897, inside the handler the blank Add form never runs.
 * They are internal working fields: `_1` holds the alternative-source value while
 * the un-suffixed field holds the chosen one. Kept separate — collapsing them would
 * lose the distinction the logic depends on.
 *
 * `item_name` IS NOT CALLED `request n` HERE. Creator labels the field
 * `displayname = "request n"` (F_B.ds:1980) and that label reaches three reports and
 * a vendor-facing print template. Deviation **D-FNB-1**: the rebuild labels it
 * `Item Name`. Copy-as-built covers behaviour, not a label that misinforms.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fnb_raw_material_requests', function (Blueprint $table) {
            $table->id();
            $table->string('creator_id', 32)->nullable()->unique();

            // The parent request. Held by NUMBER as well as by key, because the
            // export references it by number and 3 of 4 exports so far have had a
            // repeated header — positional reading is the rule here (§11.4).
            $table->foreignId('fnb_request_stock_for_food_id')->nullable()
                ->constrained('fnb_request_stock_for_foods')->cascadeOnDelete();
            $table->string('request_no')->nullable();

            $table->foreignId('fnb_item_master_id')->nullable()
                ->constrained('fnb_item_masters')->nullOnDelete();
            $table->foreignId('item_category_id')->nullable()
                ->constrained('item_categories')->nullOnDelete();

            // Creator declares UOM here as `type = text`, NOT a picklist — unlike
            // every other UOM field in the app. Reproduced as text: a foreign key
            // would reject whatever free text live rows contain.
            $table->text('uom_text')->nullable();

            $table->decimal('original_requested_quantity', 16, 4)->nullable();
            $table->decimal('requested_quantity', 16, 4)->nullable();
            $table->decimal('delivered_quantity', 16, 4)->nullable();
            $table->decimal('pending_quantity', 16, 4)->nullable();
            $table->decimal('available_quantity', 16, 4)->nullable();
            $table->decimal('warehouse_quantity', 16, 4)->nullable();
            $table->decimal('backend_warehouse_quantity', 16, 4)->nullable();  // hidden
            $table->decimal('order_quantity', 16, 4)->nullable();

            $table->string('request_from')->nullable();

            // The chosen source.
            $table->foreignId('fnb_warehouse_id')->nullable()
                ->constrained('fnb_warehouses')->nullOnDelete();
            $table->foreignId('vendor_id')->nullable()
                ->constrained('vendors')->nullOnDelete();

            // The alternative-source pair. Hidden at F_B.ds:7897; kept for fidelity.
            $table->foreignId('alt_fnb_warehouse_id')->nullable()
                ->constrained('fnb_warehouses')->nullOnDelete();
            $table->foreignId('alt_vendor_id')->nullable()
                ->constrained('vendors')->nullOnDelete();

            $table->boolean('all_vendors')->default(false);
            $table->boolean('warehouse_updated')->default(false);
            $table->boolean('request_raised')->default(false);

            $table->string('booking_no')->nullable();

            // Creator has THREE Request_No pickers on this form — the plain one plus
            // Request_No_Partial and Request_No_Completed, all over
            // Request_Stock_for_Food.ID. They are how a partially-filled request is
            // carried forward, so all three are kept.
            $table->string('request_no_partial')->nullable();
            $table->string('request_no_completed')->nullable();

            $table->foreignId('villa_id')->nullable()
                ->constrained('villas')->nullOnDelete();
            $table->foreignId('location_id')->nullable()
                ->constrained('locations')->nullOnDelete();

            $table->date('checked_in_date')->nullable();
            $table->date('check_out_date')->nullable();

            $table->string('added_user')->nullable();
            $table->timestamp('creator_added_time')->nullable();
            $table->timestamp('creator_modified_time')->nullable();

            $table->timestamps();

            $table->index('request_no');
            $table->index('fnb_request_stock_for_food_id');
            $table->index('fnb_item_master_id');
            $table->index(['request_from', 'request_raised']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fnb_raw_material_requests');
    }
};
