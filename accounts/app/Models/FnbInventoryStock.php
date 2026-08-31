<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * fb.Inventory_Stock — dated stock rows under an Inventory. F_B.ds:1615.
 *
 * Carries its own UOM even though the parent and the item both have one. That
 * denormalisation is Creator's; reproduced rather than collapsed, because a stock
 * row recorded in a different unit from the item default would otherwise change
 * meaning.
 */
class FnbInventoryStock extends Model
{
    protected $table = 'fnb_inventory_stocks';

    protected $fillable = [
        'creator_id', 'fnb_inventory_id', 'stock_date',
        'quantity', 'fnb_uom_id', 'price',
    ];

    protected $casts = ['stock_date' => 'date'];

    public function inventory(): BelongsTo
    {
        return $this->belongsTo(FnbInventory::class, 'fnb_inventory_id');
    }

    public function uom(): BelongsTo
    {
        return $this->belongsTo(FnbUom::class, 'fnb_uom_id');
    }
}
