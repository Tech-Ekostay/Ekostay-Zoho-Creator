<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 29 locations, not the 10 the recovered file held. `Head Office Central` is used
 * as a Location value as well as a Head Office (addendum §1).
 */
class Location extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function state(): BelongsTo
    {
        return $this->belongsTo(State::class);
    }

    public function headOffice(): BelongsTo
    {
        return $this->belongsTo(HeadOffice::class);
    }

    public function villas(): HasMany
    {
        return $this->hasMany(Villa::class);
    }
}
