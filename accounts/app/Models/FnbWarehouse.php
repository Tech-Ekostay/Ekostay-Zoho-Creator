<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * fb.Warehouse — F_B.ds:3798.
 *
 * Locations and villas are MANY per warehouse: Creator declares both as
 * `type = list`. They are pivots rather than columns because §12 measured that
 * Analytics flattens multi-value fields to one silently-chosen value on export —
 * a single column would bake that loss into the schema.
 */
class FnbWarehouse extends Model
{
    protected $table = 'fnb_warehouses';

    protected $fillable = ['creator_id', 'warehouse_name', 'state_id'];

    public function state(): BelongsTo
    {
        return $this->belongsTo(State::class);
    }

    public function locations(): BelongsToMany
    {
        return $this->belongsToMany(Location::class, 'fnb_warehouse_locations', 'fnb_warehouse_id', 'location_id');
    }

    public function villas(): BelongsToMany
    {
        return $this->belongsToMany(Villa::class, 'fnb_warehouse_villas', 'fnb_warehouse_id', 'villa_id');
    }

    public function inventories(): HasMany
    {
        return $this->hasMany(FnbInventory::class, 'fnb_warehouse_id');
    }
}
