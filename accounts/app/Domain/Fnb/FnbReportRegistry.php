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


            /*
             * ---- imported from Analytics, 31-Aug-2026 -------------------------
             *
             * Column order here is the ANALYTICS field order, not a Creator report
             * layout — none of these has been screenshotted. Marked unverified so
             * the screen says so rather than implying otherwise.
             */
            'fnb_vendor_order_bookings' => [
                'label' => 'All Vendor Order Bookings',
                'table' => 'fnb_vendor_order_bookings',
                'model' => \App\Models\FnbVendorOrderBooking::class,
                'order' => 'order_date',
                // The ONE F&B report with a Creator screenshot (findings §8.6).
                'verified' => true,
                'columns' => [
                    ['key' => 'order_no', 'label' => 'Order No.'],
                    ['key' => 'order_date', 'label' => 'Order Date', 'type' => 'date'],
                    ['key' => 'order_for', 'label' => 'Order for'],
                    ['key' => 'vendor_name', 'label' => 'Vendor Name'],
                    ['key' => 'location_name', 'label' => 'Location'],
                    ['key' => 'booking_no', 'label' => 'Booking No.'],
                    ['key' => 'status', 'label' => 'Status'],
                    ['key' => 'payment_status', 'label' => 'Payment Status'],
                    ['key' => 'amount', 'label' => 'Amount', 'type' => 'money'],
                    ['key' => 'discount', 'label' => 'Discount', 'type' => 'money'],
                    ['key' => 'grand_total', 'label' => 'Grand Total', 'type' => 'money'],
                    ['key' => 'adjusted_amount', 'label' => 'Adjusted Amount', 'type' => 'qty'],
                    ['key' => 'payable_amount', 'label' => 'Payable Amount', 'type' => 'money'],
                ],
                'note' => 'Column order from the live report screenshot. Note '
                    .'"Payment Inprogress" — lowercase p, the third spelling of that '
                    .'status in the cluster. Adjusted Amount shows WITHOUT a rupee '
                    .'sign because Creator types it decimal: it is a rounding '
                    .'remainder, not an amount.',
            ],

            'fnb_vendor_order_booking_items' => [
                'label' => 'All Vendor Order Booking Items',
                'table' => 'fnb_vendor_order_booking_items',
                'model' => \App\Models\FnbVendorOrderBookingItem::class,
                'order' => 'id',
                'verified' => false,
                'columns' => [
                    ['key' => 'order_no', 'label' => 'Order No.'],
                    ['key' => 'item_name', 'label' => 'Item Name'],
                    ['key' => 'uom_name', 'label' => 'UOM'],
                    ['key' => 'ordered_quantity', 'label' => 'Ordered Quantity', 'type' => 'qty'],
                    ['key' => 'fulfilled_quantity', 'label' => 'Fulfilled Quantity', 'type' => 'qty'],
                    ['key' => 'received_quantity', 'label' => 'Received Quantity', 'type' => 'qty'],
                    ['key' => 'price', 'label' => 'Price', 'type' => 'money'],
                    ['key' => 'amount', 'label' => 'Amount', 'type' => 'money'],
                ],
                'note' => 'THREE quantities, and "amount" follows RECEIVED — measured '
                    .'on 5,672 rows where ordered and received differ, against 1 for '
                    .'ordered. You pay for what arrived. "Fulfilled Quantity" is in '
                    .'this view but was ABSENT from the CSV export.',
            ],

            'fnb_transaction_items' => [
                'label' => 'All Transaction Items',
                'table' => 'fnb_transaction_items',
                'model' => \App\Models\FnbInventory::class,
                'order' => 'id',
                'verified' => false,
                'columns' => [
                    ['key' => 'transaction_type', 'label' => 'Transaction Type'],
                    ['key' => 'item_name', 'label' => 'Item Name'],
                    ['key' => 'warehouse_name', 'label' => 'Warehouse Name'],
                    ['key' => 'quantity', 'label' => 'Quantity', 'type' => 'qty'],
                    ['key' => 'price', 'label' => 'Price', 'type' => 'money'],
                    ['key' => 'amount', 'label' => 'Amount', 'type' => 'money'],
                ],
                'note' => 'THE STOCK LEDGER. Out 48,808 · In 10,361 · Reverse 7,218 · '
                    .'Misplaced 1,936 · Damaged 90. The 7,218 reversals matter: stock '
                    .'corrections are common and are made as NEW rows, never as edits.',
            ],

            'fnb_raw_material_requests' => [
                'label' => 'All Raw Material Requests',
                'table' => 'fnb_raw_material_requests',
                'model' => \App\Models\FnbRawMaterialRequest::class,
                'order' => 'id',
                'verified' => false,
                'columns' => [
                    ['key' => 'request_no', 'label' => 'Request No.'],
                    ['key' => 'item_name', 'label' => 'Item Name'],
                    ['key' => 'uom_text', 'label' => 'UOM'],
                    ['key' => 'requested_quantity', 'label' => 'Requested Quantity', 'type' => 'qty'],
                    ['key' => 'delivered_quantity', 'label' => 'Delivered Quantity', 'type' => 'qty'],
                    ['key' => 'pending_quantity', 'label' => 'Pending Quantity', 'type' => 'qty'],
                    ['key' => 'warehouse_quantity', 'label' => 'Warehouse Quantity', 'type' => 'qty'],
                    ['key' => 'order_quantity', 'label' => 'Order Quantity', 'type' => 'qty'],
                    ['key' => 'request_from', 'label' => 'Request From'],
                ],
                'note' => 'The largest F&B table. Item Name is labelled "request n" in '
                    .'Creator (F_B.ds:1980) and Analytics has taken that as the COLUMN '
                    .'NAME — the view field is literally "requestn". Shown as Item Name '
                    .'here: deviation D-FNB-1.',
            ],

            'fnb_request_stock_for_foods' => [
                'label' => 'Request Stock for Food',
                'table' => 'fnb_request_stock_for_foods',
                'model' => \App\Models\FnbRequestStockForFood::class,
                'order' => 'id',
                'verified' => false,
                'columns' => [
                    ['key' => 'request_no', 'label' => 'Request No.'],
                    ['key' => 'booking_no', 'label' => 'Booking No.'],
                    ['key' => 'villa_name', 'label' => 'Villa Name'],
                    ['key' => 'location_name', 'label' => 'Location'],
                    ['key' => 'chef_name', 'label' => 'Chef Name'],
                    ['key' => 'status', 'label' => 'Status'],
                    ['key' => 'request_from', 'label' => 'Request From'],
                    ['key' => 'checked_in_date', 'label' => 'Checked In Date', 'type' => 'date'],
                    ['key' => 'check_out_date', 'label' => 'Check Out Date', 'type' => 'date'],
                ],
                'note' => 'GUEST NAME IS DELIBERATELY NOT A COLUMN HERE. The CSV export '
                    .'carries real guest names; the Analytics view does not expose it, '
                    .'and this report has no authorisation in front of it.',
            ],

            'fnb_inventory_stocks' => [
                'label' => 'All Inventory Stocks',
                'table' => 'fnb_inventory_stocks',
                'model' => \App\Models\FnbInventoryStock::class,
                'order' => 'id',
                'verified' => false,
                'columns' => [
                    ['key' => 'stock_date', 'label' => 'Date', 'type' => 'date'],
                    ['key' => 'item_name', 'label' => 'Item Name'],
                    ['key' => 'warehouse_name', 'label' => 'Warehouse Name'],
                    ['key' => 'quantity', 'label' => 'Quantity', 'type' => 'qty'],
                    ['key' => 'price', 'label' => 'Price', 'type' => 'money'],
                ],
            ],

            'fnb_vendor_price_lists' => [
                'label' => 'All Vendor Price Lists',
                'table' => 'fnb_vendor_price_lists',
                'model' => null,
                'order' => 'id',
                'verified' => false,
                'columns' => [
                    ['key' => 'item_name', 'label' => 'Item Name'],
                    ['key' => 'vendor_name', 'label' => 'Vendor Name'],
                    ['key' => 'price', 'label' => 'Price', 'type' => 'money'],
                    ['key' => 'deviation', 'label' => 'Deviation', 'type' => 'percent'],
                ],
                'note' => '2,291 of 2,336 source rows. The other 45 are duplicate '
                    .'(item, vendor) pairs the unique index refused — reported at '
                    .'import rather than silently last-wins.',
            ],

            'fnb_chef_masters' => [
                'label' => 'All Chef Masters',
                'table' => 'fnb_chef_masters',
                'model' => \App\Models\FnbWarehouse::class,
                'order' => 'name',
                'verified' => false,
                'columns' => [
                    ['key' => 'name', 'label' => 'Name'],
                    ['key' => 'chef_id', 'label' => 'Chef ID'],
                    ['key' => 'location_name', 'label' => 'Location'],
                    ['key' => 'status', 'label' => 'Status'],
                ],
                'note' => 'CONTAINS PII — phone, email and address are stored but NOT '
                    .'exposed here. Status arrives as an empty string where unset and '
                    .'the CHECK rejected it; coerced to null rather than widening the '
                    .'constraint to admit blanks.',
            ],

            'fnb_recipe_masters' => [
                'label' => 'All Recipe Masters',
                'table' => 'fnb_recipe_masters',
                'model' => null,
                'order' => 'recipe_name',
                'verified' => false,
                'columns' => [
                    ['key' => 'recipe_name', 'label' => 'Recipe Name'],
                ],
            ],

            'fnb_food_order_details' => [
                'label' => 'All Food Order Details',
                'table' => 'fnb_food_order_details',
                'model' => null,
                'order' => 'id',
                'verified' => false,
                'columns' => [
                    ['key' => 'booking_no', 'label' => 'Booking No.'],
                    ['key' => 'meal_name', 'label' => 'Meal Name'],
                    ['key' => 'guest_count', 'label' => 'Guest Count'],
                    ['key' => 'meal_details', 'label' => 'Meal Details'],
                ],
            ],

            'fnb_monthly_checks' => [
                'label' => 'All Monthly Checks',
                'table' => 'fnb_monthly_checks',
                'model' => null,
                'order' => 'check_date',
                'verified' => false,
                'columns' => [
                    ['key' => 'check_date', 'label' => 'Date', 'type' => 'date'],
                    ['key' => 'warehouse_name', 'label' => 'Warehouse'],
                    ['key' => 'location_name', 'label' => 'Location'],
                    ['key' => 'status', 'label' => 'Status'],
                ],
            ],

            'fnb_transfer_items' => [
                'label' => 'All Transfer Items',
                'table' => 'fnb_transfer_items',
                'model' => null,
                'order' => 'id',
                'verified' => false,
                'columns' => [
                    ['key' => 'transfer_id', 'label' => 'Transfer ID'],
                    ['key' => 'from_warehouse', 'label' => 'From Warehouse'],
                    ['key' => 'to_warehouse', 'label' => 'To Warehouse'],
                    ['key' => 'status', 'label' => 'Status'],
                ],
                'note' => 'A CHECK refuses a self-transfer. Creator only prevents it '
                    .'with a picklist filter, and a picklist is not a boundary.',
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
