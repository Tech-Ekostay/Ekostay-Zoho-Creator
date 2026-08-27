<?php

namespace App\Models;

use App\Models\Concerns\TracksCreatorAudit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One `Split_Payments` leg on a payment.
 *
 * §5.2: the flattened ledger downstream syncs from these, so every
 * villa-month-category figure in the expense-control tool traces back to a row
 * here. A reversal's legs carry NEGATIVE amounts — that is how a correction stays
 * visible in the same place the original allocation was.
 */
class PaymentSplitPayment extends Model
{
    /*
     * Creator's four platform fields — Added/Modified Time and User. Not app
     * fields: Creator maintains them on every record of every form, and every
     * report can show them. See the trait for why the user half is null until
     * authorisation exists, and why imported stamps are never overwritten.
     */
    use TracksCreatorAudit;

    protected function casts(): array
    {
        return [
            'added_time' => 'datetime',
            'modified_time' => 'datetime',
        ];
    }

    protected $guarded = [];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
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
