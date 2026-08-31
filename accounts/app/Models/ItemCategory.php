<?php

namespace App\Models;

use App\Models\Concerns\TracksCreatorAudit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * `name` carries significant whitespace — `F&B STAFF MEDICAL EXPENSE ` has a
 * trailing space and it is a live lookup key. Never trim it.
 */
class ItemCategory extends Model
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
            'added_time' => 'datetime',
            'modified_time' => 'datetime',
            'exclude_for_profit' => 'boolean',
            'exclude_for_observation' => 'boolean',
            'exclude_item_category' => 'boolean',
            'disable' => 'boolean',
        ];
    }

    public function masterCategory(): BelongsTo
    {
        return $this->belongsTo(MasterCategory::class);
    }

    public function coaAccount(): BelongsTo
    {
        return $this->belongsTo(CoaAccount::class, 'coa_account_id');
    }

    /**
     * §6.2 — the Bills picker excludes PETTY and INTERNAL TRANSFER. `disable` is
     * labelled "Disallow Manual Creation" and blocks manual selection while
     * leaving the category available to syncs and generators (addendum §10).
     */
    public function scopeSelectableManually(Builder $query): Builder
    {
        return $query->where('disable', false);
    }

    public function scopeForFoodAndBeverage(Builder $query): Builder
    {
        return $query->whereHas('masterCategory', fn (Builder $q) => $q->where('fb', true));
    }
}
