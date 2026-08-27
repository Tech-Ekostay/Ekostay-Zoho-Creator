<?php

namespace App\Models;

use App\Domain\Payments\PaymentStatus;
use App\Models\Concerns\TracksCreatorAudit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use RuntimeException;

/**
 * Payments — §7.
 *
 * MONEY IS NOT CAST, same rule as Bills: amounts stay decimal strings and all
 * arithmetic goes through App\Domain\Bills\Money in bcmath. A `decimal:4` cast
 * would route every figure through a PHP float.
 *
 * SOFT DELETES ARE FOR DRAFTS ONLY. §7.6 forbids destroying a settled payment,
 * and `delete()` is overridden below to enforce that rather than trusting callers.
 * A settled payment is reversed through App\Domain\Payments\ReversePayment.
 */
class Payment extends Model
{
    use SoftDeletes;
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
            'requested_date' => 'date',
            'payment_date' => 'date',
            'due_date' => 'date',
            'backend_payment_date' => 'date',
            'reversed_at' => 'datetime',
            'accounts_bills' => 'boolean',
        ];
    }

    /**
     * REFUSE TO DELETE A SETTLED PAYMENT.
     *
     * §7.6 exists because Creator's `Delete Paid Payment` destroyed 17 real
     * payments. Guarding at the model means no controller, command, tinker session
     * or future caller can repeat it by accident — including the 14 unguarded
     * `delete from Payment` sites the DS still carries.
     */
    public function delete(): ?bool
    {
        if (PaymentStatus::isSettled($this->status, $this->payment_status)) {
            throw new RuntimeException(sprintf(
                'Payment %s is settled and cannot be deleted. §7.6: reverse it instead — a '
                .'linked reversing entry keeps the original and its number intact, which a '
                .'delete does not. The equivalent Creator action destroyed 17 real payments.',
                $this->payment_no,
            ));
        }

        return parent::delete();
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(Bill::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function coaAccount(): BelongsTo
    {
        return $this->belongsTo(CoaAccount::class);
    }

    public function masterCategory(): BelongsTo
    {
        return $this->belongsTo(MasterCategory::class);
    }

    public function itemCategory(): BelongsTo
    {
        return $this->belongsTo(ItemCategory::class);
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

    public function villa(): BelongsTo
    {
        return $this->belongsTo(Villa::class);
    }

    public function billPayments(): HasMany
    {
        return $this->hasMany(PaymentBillPayment::class)->orderBy('position');
    }

    /** Per §5.2, every downstream villa-month-category figure resolves to these. */
    public function splitPayments(): HasMany
    {
        return $this->hasMany(PaymentSplitPayment::class)->orderBy('position');
    }

    /** The payment this one reverses, if it is a reversing entry. */
    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_payment_id');
    }

    /** The reversing entry against this payment, if one exists. */
    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reverses_payment_id');
    }

    public function isReversal(): bool
    {
        return $this->reverses_payment_id !== null;
    }

    /** §6.5's Paid lock, narrow reading — see PaymentStatus::isLocked(). */
    public function isLocked(): bool
    {
        return PaymentStatus::isLocked($this->status, $this->payment_status);
    }

    public function isSettled(): bool
    {
        return PaymentStatus::isSettled($this->status, $this->payment_status);
    }

    /** Forward payments only — excludes reversing entries. */
    public function scopeForward(Builder $query): Builder
    {
        return $query->whereNull('reverses_payment_id');
    }
}
