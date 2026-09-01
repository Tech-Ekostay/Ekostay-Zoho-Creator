<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * fb.Vendor_Order_Booking_Item — an order line. F_B.ds:3469. Findings §9.
 *
 * THREE quantities, and `amount` follows RECEIVED (§9.1) — measured on the 4,523
 * live rows where ordered and received differ: 4,438 follow received, 81 ordered.
 * You pay for what arrived.
 */
class FnbVendorOrderBookingItem extends Model
{
    protected $table = 'fnb_vendor_order_booking_items';

    protected $fillable = [
        'creator_id', 'fnb_vendor_order_booking_id', 'fnb_item_master_id',
        'item_category_id', 'fnb_uom_id',
        'ordered_quantity', 'fulfilled_quantity', 'received_quantity',
        'price', 'amount', 'tax_id', 'gst_amount', 'total_amount',
        'villa_id', 'line_date', 'raw_material_request_no',
    ];

    protected $casts = ['line_date' => 'date'];

    public function order(): BelongsTo
    {
        return $this->belongsTo(FnbVendorOrderBooking::class, 'fnb_vendor_order_booking_id');
    }

    public function itemMaster(): BelongsTo
    {
        return $this->belongsTo(FnbItemMaster::class, 'fnb_item_master_id');
    }

    public function uom(): BelongsTo
    {
        return $this->belongsTo(FnbUom::class, 'fnb_uom_id');
    }

    public function villa(): BelongsTo
    {
        return $this->belongsTo(Villa::class);
    }

    /** received x price — Creator's rule, per §9.1. */
    public function expectedAmount(): string
    {
        return \App\Domain\Bills\Money::mul(
            $this->received_quantity ?? '0',
            $this->price ?? '0'
        );
    }
}
