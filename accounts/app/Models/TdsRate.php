<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * 35 rows, only 16 distinct name + percentage pairs. The duplicates are live and
 * feed the picker, so the clerk picks arbitrarily between identical rates under
 * different Books ids (addendum §3).
 *
 * `status` is `Active` or NULL. It is documented as {Active, Expired} but
 * `Expired` occurs zero times and 16 rows are blank — blank is the real second
 * state.
 */
class TdsRate extends Model
{
    protected $guarded = [];

    /** §6.2: the Bills picker filters Status == "Active". Blank is excluded. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'Active');
    }
}
