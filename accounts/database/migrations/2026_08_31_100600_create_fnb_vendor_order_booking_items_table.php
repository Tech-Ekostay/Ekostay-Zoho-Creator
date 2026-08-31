<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * fb.Vendor_Order_Booking_Item — the order lines. F_B.ds:3469, 16 fields.
 * Rendered inside the parent as the grid **"Items Ordered"**, in EDIT mode only.
 *
 * The largest table in F&B: **110,510 live rows**. Findings §9.
 *
 * THREE QUANTITIES, AND ONLY TWO ARE IN THE EXPORT (§9.3):
 *
 *     ordered_quantity     what was asked for — READ-ONLY in the grid, it comes
 *                          from the Raw Material Request rather than being retyped
 *     fulfilled_quantity   what the vendor said they would send — in the DS and on
 *                          the form, but ABSENT from the Analytics export
 *     received_quantity    what actually arrived
 *
 * `amount` FOLLOWS RECEIVED, NOT ORDERED. On the 4,523 live rows where the two
 * differ, 4,438 satisfy `amount = received x price` against 81 for ordered:
 *
 *     KULFI     ordered 20  received  9   price 20  ->  amount 180
 *     CABBAGE   ordered  1  received 0.5  price 60  ->  amount  30
 *
 * You pay for what arrived. The DS attaches no formula to `amount` at all, so this
 * is measured behaviour, not documented behaviour — and it is the reason the parent
 * total must be recomputed from these rows (§9.2: 287 orders where the legs already
 * exceed the stored parent).
 *
 * The DS declares GST on the LINE (`accounts.Tax.ID` plus gst_amount and
 * total_amount) as well as on the parent. The export carries neither, and every
 * live parent seen has `gst_amount = 0`, so the line-level GST columns exist here
 * for fidelity and are expected to be null until an export proves otherwise.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fnb_vendor_order_booking_items', function (Blueprint $table) {
            $table->id();
            $table->string('creator_id', 32)->nullable()->unique();

            $table->foreignId('fnb_vendor_order_booking_id')->nullable()
                ->constrained('fnb_vendor_order_bookings')->cascadeOnDelete();

            $table->foreignId('fnb_item_master_id')->nullable()
                ->constrained('fnb_item_masters')->nullOnDelete();

            // Per LINE, not per order — the grid shows it on every row (§9.4).
            $table->foreignId('item_category_id')->nullable()
                ->constrained('item_categories')->nullOnDelete();

            $table->foreignId('fnb_uom_id')->nullable()
                ->constrained('fnb_uoms')->nullOnDelete();

            $table->decimal('ordered_quantity', 16, 4)->nullable();
            $table->decimal('fulfilled_quantity', 16, 4)->nullable();
            $table->decimal('received_quantity', 16, 4)->nullable();

            $table->decimal('price', 16, 4)->nullable();
            $table->decimal('amount', 16, 4)->nullable();       // = received x price

            // Declared in the DS, absent from the export. See the class docblock.
            $table->foreignId('tax_id')->nullable()
                ->constrained('taxes')->nullOnDelete();
            $table->decimal('gst_amount', 16, 4)->nullable();
            $table->decimal('total_amount', 16, 4)->nullable();

            // The line carries its OWN villa, and unlike the parent it is populated
            // (§9.5). This is where a villa for an Against-Booking order comes from.
            $table->foreignId('villa_id')->nullable()
                ->constrained('villas')->nullOnDelete();

            $table->date('line_date')->nullable();              // Creator: Date_field

            $table->string('raw_material_request_no')->nullable();

            $table->timestamps();

            $table->index('fnb_vendor_order_booking_id');
            $table->index('fnb_item_master_id');
            $table->index(['fnb_vendor_order_booking_id', 'fnb_item_master_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fnb_vendor_order_booking_items');
    }
};
