<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Nine states in the live data, including `Uttarakand` — missing its 'h' and a
 * live grouping key. Never normalise it (addendum §15).
 */
class State extends Model
{
    protected $guarded = [];

    public function locations(): HasMany
    {
        return $this->hasMany(Location::class);
    }

    public function villas(): HasMany
    {
        return $this->hasMany(Villa::class);
    }
}
