<?php

namespace App\Models;

use App\Models\Concerns\TracksCreatorAudit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 29 locations, not the 10 the recovered file held. `Head Office Central` is used
 * as a Location value as well as a Head Office (addendum §1).
 */
class Location extends Model
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
            // Creator's platform stamps — cast so they compare and render as
            // dates rather than strings.
            'added_time' => 'datetime',
            'modified_time' => 'datetime',
            'active' => 'boolean',
        ];
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
