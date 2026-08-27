<?php

namespace App\Models;

use App\Models\Concerns\TracksCreatorAudit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Bills — §6.
 *
 * MONEY IS NOT CAST. Every amount stays the decimal STRING Postgres returns.
 * Casting to `decimal:4` would route it through PHP floats, and Money does all
 * arithmetic in bcmath precisely to avoid that. §15.2's float() corruption is the
 * precedent this whole convention exists for.
 *
 * `status` is compared through a normalising accessor, never inline: addendum §10
 * records both "Payment InProgress" and "Payment Inprogress" as live values.
 */
class Bill extends Model
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
            'bill_date' => 'date',
            'due_date' => 'date',
            'gst_needed' => 'boolean',
            'split_equally' => 'boolean',
        ];
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function coaAccount(): BelongsTo
    {
        return $this->belongsTo(CoaAccount::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function headOffice(): BelongsTo
    {
        return $this->belongsTo(HeadOffice::class);
    }

    public function tdsRate(): BelongsTo
    {
        return $this->belongsTo(TdsRate::class);
    }

    /** The Split_Payment grid — §6.2, and per §5 the ledger in waiting. */
    public function splitPayments(): HasMany
    {
        return $this->hasMany(BillSplitPayment::class)->orderBy('position');
    }

    /** The Amount_Category grid — line items, not allocation (addendum §10). */
    public function amountCategories(): HasMany
    {
        return $this->hasMany(BillAmountCategory::class)->orderBy('position');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function villas(): BelongsToMany
    {
        return $this->belongsToMany(Villa::class, 'bill_villa');
    }

    public function itemCategories(): BelongsToMany
    {
        return $this->belongsToMany(ItemCategory::class, 'bill_item_category');
    }

    public function billingCycles(): BelongsToMany
    {
        return $this->belongsToMany(BillingCycle::class, 'bill_billing_cycle');
    }

    /**
     * Case- and spelling-tolerant status comparison.
     *
     * Addendum §10: "Payment InProgress" and "Payment Inprogress" are both live.
     * Anything comparing bill status must go through here.
     */
    public function statusIs(string $candidate): bool
    {
        return strtolower(trim((string) $this->status)) === strtolower(trim($candidate));
    }
}
