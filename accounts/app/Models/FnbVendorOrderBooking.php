<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * fb.Vendor_Order_Booking — the purchase order. Nav label: **Vendor Bills**.
 * F_B.ds:3088. Findings §8.
 *
 * The money is DERIVED, not stored-and-trusted. 287 live orders already have legs
 * exceeding their stored parent total (§9.2), so `recomputeTotals()` is the
 * authority and the columns are a cache of it.
 */
class FnbVendorOrderBooking extends Model
{
    protected $table = 'fnb_vendor_order_bookings';

    protected $fillable = [
        'creator_id', 'order_no', 'vendor_id', 'order_for', 'order_date',
        'booking_no', 'fnb_warehouse_id', 'location_id', 'state_id', 'request_no',
        'status', 'payment_status', 'payment_due_date',
        'billing_year', 'billing_month', 'billing_cycle_id',
        'total_quantity', 'amount', 'gst_amount', 'discount', 'grand_total',
        'adjusted_amount', 'paid_amount', 'payable_amount', 'books_id',
        'update_fulfilled_qty', 'update_received_qty', 'expense_updated',
        'order_received', 'particulars',
        'added_user', 'creator_added_time', 'modified_user', 'creator_modified_time',
    ];

    protected $casts = [
        'order_date' => 'date',
        'payment_due_date' => 'date',
        'update_fulfilled_qty' => 'bool',
        'update_received_qty' => 'bool',
        'expense_updated' => 'bool',
        'order_received' => 'bool',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(FnbVendorOrderBookingItem::class, 'fnb_vendor_order_booking_id');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(FnbWarehouse::class, 'fnb_warehouse_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function billingCycle(): BelongsTo
    {
        return $this->belongsTo(BillingCycle::class);
    }

    /**
     * Recompute every derived figure from the line items. §8.3 and §9.
     *
     * Returns the values rather than saving, so a caller can compare against what
     * is stored — which is how the 287 stale parents are detected rather than
     * silently corrected.
     *
     * All arithmetic goes through Money (bcmath on decimal strings). No floats
     * touch this: a float total on 110,510 lines drifts.
     */
    public function recomputeTotals(): array
    {
        $lines = $this->items;

        $amount = $lines->reduce(
            fn ($carry, $l) => \App\Domain\Bills\Money::add($carry, $l->amount ?? '0'),
            '0'
        );
        $quantity = $lines->reduce(
            fn ($carry, $l) => \App\Domain\Bills\Money::add($carry, $l->received_quantity ?? '0'),
            '0'
        );

        $gst = $this->gst_amount ?? '0';
        $discount = $this->discount ?? '0';

        // raw = amount + gst - discount, then ROUNDED TO WHOLE RUPEES.
        $raw = \App\Domain\Bills\Money::sub(\App\Domain\Bills\Money::add($amount, $gst), $discount);
        $grand = \App\Domain\Bills\Money::roundToRupees($raw);

        return [
            'total_quantity' => $quantity,
            'amount' => $amount,
            'grand_total' => $grand,
            // The remainder Creator stores in Adjusted_Amount. Not money — it is
            // the rounding delta, and Creator renders it without a ₹.
            'adjusted_amount' => \App\Domain\Bills\Money::sub($grand, $raw),
            'payable_amount' => \App\Domain\Bills\Money::sub($grand, $this->paid_amount ?? '0'),
        ];
    }

    /** True when the stored totals still agree with the line items. */
    public function totalsAreCurrent(): bool
    {
        $c = $this->recomputeTotals();

        return \App\Domain\Bills\Money::equals($c['amount'], $this->amount ?? '0')
            && \App\Domain\Bills\Money::equals($c['grand_total'], $this->grand_total ?? '0');
    }
}
