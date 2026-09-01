<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * fb.Request_Stock_for_Food — the kitchen's request. F_B.ds:2283. Findings §11.
 *
 * CONTAINS PII: `guest_name`, beside villa and stay dates. Any read API over this
 * needs authorisation before exposure.
 */
class FnbRequestStockForFood extends Model
{
    protected $table = 'fnb_request_stock_for_foods';

    protected $fillable = [
        'creator_id', 'request_no', 'booking_no', 'booking_number',
        'villa_id', 'location_id', 'fnb_warehouse_id', 'guest_name',
        'checked_in_date', 'check_out_date', 'chef_name', 'status',
        'request_raised', 'request_from', 'remarks',
        'added_user', 'creator_added_time', 'modified_user', 'creator_modified_time',
    ];

    protected $casts = [
        'checked_in_date' => 'date',
        'check_out_date' => 'date',
        'request_raised' => 'bool',
    ];

    /** Fields that must never reach a client without authorisation. */
    protected $hidden = ['guest_name'];

    public function lines(): HasMany
    {
        return $this->hasMany(FnbRawMaterialRequest::class, 'fnb_request_stock_for_food_id');
    }

    public function villa(): BelongsTo
    {
        return $this->belongsTo(Villa::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(FnbWarehouse::class, 'fnb_warehouse_id');
    }
}
