<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * fb.Item_Master — the hub of the F&B domain. F_B.ds:1702.
 *
 * `itemCategory` points at ACCOUNTS' item_categories, scoped by
 * `master_categories.fb`. Under §2.1 (replace the cluster) Creator's cross-app
 * `accounts.Item_Category[Master_Category.F_B == true].ID` is this relation plus
 * the `fnbScoped` filter — no network call.
 *
 * Money and percentages are decimal strings, never floats.
 */
class FnbItemMaster extends Model
{
    protected $table = 'fnb_item_masters';

    protected $fillable = [
        'creator_id', 'item_name', 'item_category_id',
        'fnb_uom_id', 'base_price', 'variance', 'no_decimal_values',
    ];

    protected $casts = [
        'no_decimal_values' => 'bool',
    ];

    public function itemCategory(): BelongsTo
    {
        return $this->belongsTo(ItemCategory::class);
    }

    public function uom(): BelongsTo
    {
        return $this->belongsTo(FnbUom::class, 'fnb_uom_id');
    }

    public function inventories(): HasMany
    {
        return $this->hasMany(FnbInventory::class, 'fnb_item_master_id');
    }

    /**
     * The category list Creator's picklist actually offers:
     * accounts.Item_Category[Master_Category.F_B == true].
     *
     * A category whose master is not F&B-flagged should never appear on an F&B
     * form, so any query that offers a choice goes through this.
     */
    public function scopeFnbScoped($query)
    {
        return $query->whereHas('itemCategory.masterCategory', fn ($q) => $q->where('fb', true));
    }
}
