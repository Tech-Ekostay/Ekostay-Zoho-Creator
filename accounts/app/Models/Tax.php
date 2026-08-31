<?php

namespace App\Models;

use App\Models\Concerns\TracksCreatorAudit;
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
