<?php

namespace App\Models;

use App\Models\Concerns\TracksCreatorAudit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One `Amount_Category` line on a bill — §6.2.
 *
 * Addendum §10 settles what distinguishes this from `Split_Payment`: amount
 * categories are the bill's LINE ITEMS (what was bought, and the tax on it),
 * split payments are the ALLOCATION of that money across villa x cycle x category.
 * Two different questions, which is why both grids exist.
 *
 * Money stays a decimal string. All arithmetic goes through App\Domain\Bills\Money.
 */
class BillAmountCategory extends Model
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

    public function bill(): BelongsTo
    {
        return $this->belongsTo(Bill::class);
    }

    public function tax(): BelongsTo
    {
        return $this->belongsTo(Tax::class);
    }
}
