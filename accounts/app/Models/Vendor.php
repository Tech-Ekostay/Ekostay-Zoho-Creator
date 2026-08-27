<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * `Vendor_Master` — §13A. 8,063 real records.
 *
 * `name` IS NOT UNIQUE, deliberately: 62 names occur on more than one record and
 * 5 records have no name at all. A unique index would reject real rows.
 *
 * §13A.1 IS NOW ANSWERED — see the `add_vendor_master_columns` migration for the
 * counting that settles it. In short: `primary_vendor` is the merge pointer,
 * `is_primary` flags the target, and `main_primary` is a display field that drifts
 * and must never be used to resolve a merge.
 */
class Vendor extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'is_employee' => 'boolean',
            'added_time' => 'datetime',
            'modified_time' => 'datetime',
        ];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function state(): BelongsTo
    {
        return $this->belongsTo(State::class);
    }

    public function masterCategory(): BelongsTo
    {
        return $this->belongsTo(MasterCategory::class);
    }

    public function itemCategory(): BelongsTo
    {
        return $this->belongsTo(ItemCategory::class);
    }

    /**
     * The vendor this one was merged INTO — §13A.1.
     *
     * Nullable even when `primary_vendor` is set: Creator stores the pointer as a
     * name, and one such name matches several vendor rows, so it cannot always
     * resolve. Read `primary_vendor` when you need the pointer itself.
     */
    public function primaryVendor(): BelongsTo
    {
        return $this->belongsTo(self::class, 'primary_vendor_id');
    }

    /** The rows merged INTO this one. Only meaningful where `is_primary`. */
    public function mergedVendors(): HasMany
    {
        return $this->hasMany(self::class, 'primary_vendor_id');
    }

    /**
     * Vendors a user should be offered, per §13A.1: a merged-away vendor is not a
     * valid target for new bills, its primary is. Scope rather than a global
     * filter because reports must still show the merged rows.
     */
    public function scopeNotMergedAway($query)
    {
        return $query->whereNull('primary_vendor');
    }
}
