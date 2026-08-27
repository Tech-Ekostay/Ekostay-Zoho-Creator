<?php

namespace App\Models;

use App\Models\Concerns\TracksCreatorAudit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MasterCategory extends Model
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
            'fb' => 'boolean',
        ];
    }

    public function itemCategories(): HasMany
    {
        return $this->hasMany(ItemCategory::class);
    }

    /**
     * §4.2 — F&B scoping is the BOOLEAN, never a string comparison on the name.
     * The expense tracker filtered `master_category == 'F&B'`, which is why
     * BAKERY and KIRANA kept leaking.
     */
    public function scopeFoodAndBeverage(Builder $query): Builder
    {
        return $query->where('fb', true);
    }
}
