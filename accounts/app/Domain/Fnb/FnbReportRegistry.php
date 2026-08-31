<?php

namespace App\Domain\Fnb;

use InvalidArgumentException;

/**
 * One definition per F&B report: its table, its columns, its order.
 *
 * Mirrors `App\Domain\Settings\ReportRegistry` — one source read by the
 * controller so a grid and its data cannot drift.
 *
 * COLUMN ORDER IS FROM THE DS FORM DECLARATION, NOT INVENTED, and where a Creator
 * screenshot exists it wins. Only `All Vendor Order Bookings` has one
 * (findings §8.6), so the rest say so on screen rather than implying they were
 * verified.
 *
 * EVERY REPORT HERE IS READ-ONLY. There is no write path, no POST, no PATCH.
 * §17 says do not implement an F&B write path in the first pass, and these exist
 * so the schema can be inspected — not so records can be created.
 */
final class FnbReportRegistry
{
    /**
     * @return array<string, array{
     *   label: string, table: string, model: class-string, order: string,
     *   verified: bool, columns: array<int, array{key: string, label: string, type?: string}>,
     *   note?: string
     * }>
     */
    public static function all(): array
    {
        return [
            'fnb_item_masters' => [
                'label' => 'All Item Masters',
                'table' => 'fnb_item_masters',
                'model' => \App\Models\FnbItemMaster::class,
                'order' => 'item_name',
                // The export's own column order, and it matches the DS form.
                'verified' => true,
                'columns' => [
                    ['key' => 'item_name', 'label' => 'Item Name'],
                    ['key' => 'uom_name', 'label' => 'UOM'],
                    ['key' => 'item_category_name', 'label' => 'Item Category'],
                    ['key' => 'variance', 'label' => 'Variance ', 'type' => 'percent'],
                    ['key' => 'base_price', 'label' => 'Base Price', 'type' => 'money'],
                    ['key' => 'creator_id', 'label' => 'ID'],
                    ['key' => 'no_decimal_values', 'label' => 'No Decimal Values', 'type' => 'bool'],
                ],
                'note' => 'Column order verified against All Item Masters.csv. '
                    .'`Variance ` carries a trailing space in the export header — kept.',
            ],

            'fnb_uoms' => [
                'label' => 'UOM Report',
                'table' => 'fnb_uoms',
                'model' => \App\Models\FnbUom::class,
                'order' => 'name',
                'verified' => true,
                'columns' => [
                    ['key' => 'name', 'label' => 'UOM'],
                    ['key' => 'item_count', 'label' => 'Items using it'],
                ],
                'note' => '`Pieces ` carries a trailing space and 70 items join to it. '
                    .'Shown with a marker rather than trimmed.',
            ],

            'fnb_warehouses' => [
                'label' => 'All Warehouses',
                'table' => 'fnb_warehouses',
                'model' => \App\Models\FnbWarehouse::class,
                'order' => 'warehouse_name',
                'verified' => false,
                'columns' => [
                    ['key' => 'warehouse_name', 'label' => 'Warehouse Name'],
                    ['key' => 'state_name', 'label' => 'State'],
                    ['key' => 'inventory_count', 'label' => 'Inventory rows'],
                ],
                'note' => 'No warehouse export exists — these 8 are RECOVERED from the '
                    .'inventory rows that name them. Location and Villa are multi-value '
                    .'list fields and Analytics flattened them to nothing, so both pivots '
                    .'are empty (spec §12). Column order is inferred.',
            ],

            'fnb_inventories' => [
                'label' => 'All Inventories',
                'table' => 'fnb_inventories',
                'model' => \App\Models\FnbInventory::class,
                'order' => 'id',
                'verified' => true,
                'columns' => [
                    ['key' => 'item_name', 'label' => 'Item Name'],
                    ['key' => 'uom_name', 'label' => 'UOM'],
                    ['key' => 'available_qty', 'label' => 'Available Qty', 'type' => 'qty'],
                    ['key' => 'item_category_name', 'label' => 'Item Category'],
                    ['key' => 'warehouse_name', 'label' => 'Warehouse Name'],
                    ['key' => 'price', 'label' => 'Base Price', 'type' => 'money'],
                    ['key' => 'creator_id', 'label' => 'ID'],
                ],
                'note' => 'Column order from All Inventories.csv. 390 rows sit at zero '
                    .'stock and 8 have no UOM in the source — both are real, not gaps.',
            ],

            'fnb_billing_cycles' => [
                'label' => 'Billing Cycles',
                'table' => 'billing_cycles',
                'model' => \App\Models\BillingCycle::class,
                'order' => 'year',
                'verified' => false,
                'columns' => [
                    ['key' => 'month_name', 'label' => 'Month'],
                    ['key' => 'year', 'label' => 'Year'],
                    ['key' => 'month_index', 'label' => 'Month Index'],
                ],
                'note' => 'Recovered from the order export — there is no cycle master. '
                    .'BOTH February spellings are here: `Feburary` (847 orders) and '
                    .'`February` (34). Live lookup keys, not duplicates to merge.',
            ],

            'fnb_auto_numbers' => [
                'label' => 'Auto Numbers',
                'table' => 'fnb_auto_numbers',
                'model' => null,
                'order' => 'id',
                'verified' => true,
                'columns' => [
                    ['key' => 'vendor_booking_series', 'label' => 'Vendor Booking Series'],
                    ['key' => 'vendor_booking_no', 'label' => 'Vendor Booking No.'],
                    ['key' => 'request_series', 'label' => 'Request Series'],
                    ['key' => 'request_no', 'label' => 'Request No'],
                    ['key' => 'booking_series', 'label' => 'Booking Series'],
                    ['key' => 'booking_no', 'label' => 'Booking No'],
                    ['key' => 'transfer_series', 'label' => 'Transfer Series'],
                    ['key' => 'transfer_no', 'label' => 'Transfer No.'],
                ],
                'note' => 'FOUR series, not the three both READMEs list. Booking and '
                    .'Transfer carry no counter: no export names one, and allocate() '
                    .'refuses on a null rather than minting from 1.',
            ],
        ];
    }

    /** Reports that have a table but no rows yet — listed so they are visible. */
    public static function empty(): array
    {
        return [
            'All Vendor Order Bookings' => '11,205 rows in master-data, not imported',
            'All Vendor Order Booking Items' => '110,510 rows, not imported',
            'All Raw Material Requests' => '160,995 rows, not imported',
            'Request Stock for Food Report' => '4,328 rows, not imported',
            'All Transaction Items' => 'no export',
            'All Transfer Items' => 'no export',
            'All Chef Masters' => 'no export',
            'All Recipe Masters' => 'no export',
            'Requirements of Recipe' => 'no export',
            'All Monthly Checks' => 'no export',
            'All Food Order Details' => 'no export',
            'All Vendor Price Lists' => 'no export',
            'Block Booking Date' => 'no export',
        ];
    }

    public static function get(string $key): array
    {
        $all = self::all();

        if (! isset($all[$key])) {
            throw new InvalidArgumentException("unknown F&B report '{$key}'");
        }

        return $all[$key];
    }

    public static function keys(): array
    {
        return array_keys(self::all());
    }
}
