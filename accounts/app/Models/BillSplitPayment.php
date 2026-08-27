<?php

namespace App\Models;

use App\Models\Concerns\TracksCreatorAudit;
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
    /*
     * Creator's four platform fields — Added/Modified Time and User. Not app
     * fields: Creator maintains them on every record of every form, and every
     * report can show them. See the trait for why the user half is null until
     * authorisation exists, and why imported stamps are never overwritten.
     */
    use TracksCreatorAudit;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'added_time' => 'datetime',
            'modified_time' => 'datetime',
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
