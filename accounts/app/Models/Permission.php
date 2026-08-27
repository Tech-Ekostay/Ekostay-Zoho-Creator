<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * One row per (module, report, verb) triple extracted from the Creator
 * `share_settings` block — e.g. `bills.all_bills.edit`.
 *
 * Verbs in the live matrix are View, Edit and Delete only. Creator has no
 * separate Add verb at this level.
 */
class Permission extends Model
{
    protected $guarded = [];

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }
}
