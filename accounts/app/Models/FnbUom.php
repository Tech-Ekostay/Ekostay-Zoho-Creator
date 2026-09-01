<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * fb.UOM — F_B.ds:3040.
 *
 * `name` is a live lookup key and is stored untrimmed: `Pieces ` (7 chars) is a
 * real row that 70 items join to. Never trim it on write.
 */
class FnbUom extends Model
{
    protected $table = 'fnb_uoms';

    protected $fillable = ['creator_id', 'name'];

    public function itemMasters(): HasMany
    {
        return $this->hasMany(FnbItemMaster::class, 'fnb_uom_id');
    }
}
