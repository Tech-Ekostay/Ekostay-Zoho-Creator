<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * `tax_type` holds Books API values: `tax` (IGST) and `tax_group` (GST = CGST +
 * SGST, two ledger destinations behind one GST_Amount).
 *
 * Known gap: IGST exists only at 0/5/18 while GST runs 0/5/12/18/28, so an
 * interstate purchase at 12% or 28% has no entry to select (addendum §3).
 */
class Tax extends Model
{
    protected $guarded = [];

    /** §6.4 rule 2 resolves the hardcoded zero-GST ids by percentage instead. */
    public function scopeZeroRated(Builder $query): Builder
    {
        return $query->where('tax_percentage', 0);
    }

    public function scopeInterstate(Builder $query): Builder
    {
        return $query->where('tax_type', 'tax');
    }
}
