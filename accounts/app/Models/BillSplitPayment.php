<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One `Split_Payment` leg on a bill — §6.2.
 *
 * §5: "An Expenses_Bills row IS one Split_Payments leg, materialised." This is
 * where villa-month-category attribution is decided, so nothing here is casual.
 *
 * The BACKEND TRIPLET is the allocation snapshot taken while nothing is paid
 * (addendum §10). §7.2 reads it for a partially-paid bill. Money stays a string.
 */
class BillSplitPayment extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'partial_paid' => 'boolean',
            'flagged' => 'boolean',
        ];
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(Bill::class);
    }

    public function villa(): BelongsTo
    {
        return $this->belongsTo(Villa::class);
    }

    public function itemCategory(): BelongsTo
    {
        return $this->belongsTo(ItemCategory::class);
    }

    public function billingCycle(): BelongsTo
    {
        return $this->belongsTo(BillingCycle::class);
    }
}
