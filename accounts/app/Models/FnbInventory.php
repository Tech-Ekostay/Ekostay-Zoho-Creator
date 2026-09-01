<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * fb.Inventory — one row per warehouse × item. F_B.ds:1504.
 *
 * Creator's Item_Name picklist cascades off Item_Category on the same form
 * (`Item_Master[Item_Category.ID == input.Item_Category]`), so an inventory row's
 * category is expected to match its item's own category. Postgres cannot express
 * that as a CHECK across tables, so `categoryMatchesItem()` states the invariant
 * and a test asserts it against the seeded data.
 */
class FnbInventory extends Model
{
    protected $table = 'fnb_inventories';

    protected $fillable = [
        'creator_id', 'fnb_warehouse_id', 'item_category_id',
        'fnb_item_master_id', 'fnb_uom_id', 'available_qty', 'price',
    ];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(FnbWarehouse::class, 'fnb_warehouse_id');
    }

    public function itemMaster(): BelongsTo
    {
        return $this->belongsTo(FnbItemMaster::class, 'fnb_item_master_id');
    }

    public function itemCategory(): BelongsTo
    {
        return $this->belongsTo(ItemCategory::class);
    }

    public function uom(): BelongsTo
    {
        return $this->belongsTo(FnbUom::class, 'fnb_uom_id');
    }

    public function stocks(): HasMany
    {
        return $this->hasMany(FnbInventoryStock::class, 'fnb_inventory_id');
    }

    /** True when this row's category agrees with its item's own category. */
    public function categoryMatchesItem(): bool
    {
        if ($this->item_category_id === null || $this->fnb_item_master_id === null) {
            return true;   // nothing to contradict
        }

        return $this->itemMaster?->item_category_id === $this->item_category_id;
    }
}
