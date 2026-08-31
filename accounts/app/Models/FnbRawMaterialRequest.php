<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * fb.Raw_Material_Request — one requested item. F_B.ds:1966. Findings §11.
 *
 * `item_name` is labelled `"request n"` in Creator (F_B.ds:1980) and that label
 * reaches three reports and a vendor-facing print template. Deviation D-FNB-1: the
 * rebuild labels it Item Name.
 *
 * `alt_*` are Creator's `Warehouse_Name1` / `Vendor_Name1` — hidden fields carrying
 * the alternative source, not user inputs (§11.2).
 */
class FnbRawMaterialRequest extends Model
{
    protected $table = 'fnb_raw_material_requests';

    protected $fillable = [
        'creator_id', 'fnb_request_stock_for_food_id', 'request_no',
        'fnb_item_master_id', 'item_category_id', 'uom_text',
        'original_requested_quantity', 'requested_quantity', 'delivered_quantity',
        'pending_quantity', 'available_quantity', 'warehouse_quantity',
        'backend_warehouse_quantity', 'order_quantity',
        'request_from', 'fnb_warehouse_id', 'vendor_id',
        'alt_fnb_warehouse_id', 'alt_vendor_id',
        'all_vendors', 'warehouse_updated', 'request_raised',
        'booking_no', 'request_no_partial', 'request_no_completed',
        'villa_id', 'location_id', 'checked_in_date', 'check_out_date',
        'added_user', 'creator_added_time', 'creator_modified_time',
    ];

    protected $casts = [
        'checked_in_date' => 'date',
        'check_out_date' => 'date',
        'all_vendors' => 'bool',
        'warehouse_updated' => 'bool',
        'request_raised' => 'bool',
    ];

    public function request(): BelongsTo
    {
        return $this->belongsTo(FnbRequestStockForFood::class, 'fnb_request_stock_for_food_id');
    }

    public function itemMaster(): BelongsTo
    {
        return $this->belongsTo(FnbItemMaster::class, 'fnb_item_master_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(FnbWarehouse::class, 'fnb_warehouse_id');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /**
     * How the request splits: off the warehouse, or ordered from a vendor.
     * `warehouse_quantity + order_quantity` should account for the ask, and the
     * order side is what becomes an Items Ordered line (§9.4, §11.3).
     */
    public function splitTotal(): string
    {
        return \App\Domain\Bills\Money::add(
            $this->warehouse_quantity ?? '0',
            $this->order_quantity ?? '0'
        );
    }
}
