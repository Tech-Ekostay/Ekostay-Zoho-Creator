<?php

namespace App\Models;

use App\Models\Concerns\TracksCreatorAudit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Nine states in the live data, including `Uttarakand` — missing its 'h' and a
 * live grouping key. Never normalise it (addendum §15).
 */
class State extends Model
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

    public function locations(): HasMany
    {
        return $this->hasMany(Location::class);
    }

    public function villas(): HasMany
    {
        return $this->hasMany(Villa::class);
    }
}
