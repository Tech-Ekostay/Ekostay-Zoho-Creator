<?php

namespace App\Models;

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
