<?php

namespace App\Models;

use App\Models\Concerns\TracksCreatorAudit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One ledger entry — §5.2: "an Expenses_Bills row IS one ledger entry."
 *
 * 66,402 real rows, and the only place both payment origins meet: 56% carry a
 * payment and no bill, 44% carry both. So this is not a projection of bills.
 *
 * `amount` IS NOT `gross_amount`. From the live report:
 *
 *     Gross 58,614.14   TDS 586.14   GST 0.00   Amount 58,028.00
 *
 * `amount` is the net attributable figure and the one to sum for a ledger total;
 * `gross_amount` is what the vendor billed. Summing the wrong column misstates
 * every villa-month-category figure downstream.
 */
class Expense extends Model
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
            'timestamp_date' => 'datetime',
            'payment_date' => 'date',
            'bill_date' => 'date',
            'due_date' => 'date',
            'recon_expense' => 'boolean',
            'duplicate' => 'boolean',
            'bill_available' => 'boolean',
            'updated_by_widget' => 'boolean',
            'a_flag' => 'boolean',
            'b_flag' => 'boolean',
            'c_flag' => 'boolean',
            'd_flag' => 'boolean',
        ];
    }

    public function villa(): BelongsTo
    {
        return $this->belongsTo(Villa::class);
    }

    /** The villa the expense rolls up to — distinct from `villa` on the report. */
    public function primaryVilla(): BelongsTo
    {
        return $this->belongsTo(Villa::class, 'primary_villa_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function headOffice(): BelongsTo
    {
        return $this->belongsTo(HeadOffice::class);
    }

    public function itemCategory(): BelongsTo
    {
        return $this->belongsTo(ItemCategory::class);
    }

    public function masterCategory(): BelongsTo
    {
        return $this->belongsTo(MasterCategory::class);
    }

    public function billingCycle(): BelongsTo
    {
        return $this->belongsTo(BillingCycle::class);
    }

    public function coaAccount(): BelongsTo
    {
        return $this->belongsTo(CoaAccount::class);
    }

    /** `Bank Name` on the report — a COA row, filtered by the `Bank` flag. */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(CoaAccount::class, 'bank_coa_account_id');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(Bill::class);
    }
}
