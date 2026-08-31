<?php

namespace App\Http\Controllers\Api;

use App\Domain\Fnb\FnbReportRegistry;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * F&B reports, READ ONLY.
 *
 * There is no store, no update, no destroy — deliberately. §17 says do not
 * implement an F&B write path in the first pass, and these endpoints exist so the
 * schema and the seeded data can be inspected in a browser rather than in psql.
 *
 * Paged server-side from the start. `fnb_raw_material_requests` will hold 160,995
 * rows once imported, and Accounts learned at 8,063 vendors that client-side
 * filtering is right at 135 rows and wrong at scale.
 *
 * NO AUTHORISATION, and that is worth stating rather than implying: the §3.3 matrix
 * is extracted and tested but is not wired to a gate anywhere in this app yet, so
 * `/api/payments` is open too. Fine locally, a blocker before exposure. One of
 * these reports carries PII once imported — `Request Stock for Food` holds guest
 * names — so this is not merely theoretical.
 */
class FnbReportController extends Controller
{
    /** The report list, plus what is built-but-empty. */
    public function index(): JsonResponse
    {
        $reports = [];

        foreach (FnbReportRegistry::all() as $key => $def) {
            $reports[] = [
                'key' => $key,
                'label' => $def['label'],
                'count' => DB::table($def['table'])->count(),
                'verified' => $def['verified'],
                'note' => $def['note'] ?? null,
            ];
        }

        return response()->json([
            'reports' => $reports,
            // Tables that exist with no rows. Shown so the schema is visible even
            // where nothing has been imported — a report that is simply absent
            // looks like it was never built.
            'awaiting_import' => FnbReportRegistry::empty(),
        ]);
    }

    public function show(Request $request, string $report): JsonResponse
    {
        try {
            $def = FnbReportRegistry::get($report);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }

        $perPage = min(max((int) $request->query('per_page', 200), 1), 1000);
        $page = max((int) $request->query('page', 1), 1);
        $search = trim((string) $request->query('q', ''));

        $rows = $this->rowsFor($report, $def, $search, $perPage, $page);

        return response()->json([
            'key' => $report,
            'label' => $def['label'],
            'columns' => $def['columns'],
            'verified' => $def['verified'],
            'note' => $def['note'] ?? null,
            'total' => $this->countFor($report, $def, $search),
            'page' => $page,
            'per_page' => $perPage,
            'rows' => $rows,
        ]);
    }

    /**
     * Each report joins its own lookups. Written out per report rather than
     * generated: the joins differ, and a generic resolver would hide which table a
     * column actually came from.
     */
    private function rowsFor(string $report, array $def, string $search, int $perPage, int $page): array
    {
        $offset = ($page - 1) * $perPage;

        return match ($report) {
            'fnb_item_masters' => DB::table('fnb_item_masters as im')
                ->leftJoin('fnb_uoms as u', 'u.id', '=', 'im.fnb_uom_id')
                ->leftJoin('item_categories as ic', 'ic.id', '=', 'im.item_category_id')
                ->when($search !== '', fn ($q) => $q->where('im.item_name', 'ilike', '%'.$search.'%'))
                ->orderBy('im.item_name')
                ->offset($offset)->limit($perPage)
                ->get([
                    'im.item_name', 'u.name as uom_name', 'ic.name as item_category_name',
                    'im.variance', 'im.base_price', 'im.creator_id', 'im.no_decimal_values',
                ])->map(fn ($r) => (array) $r)->all(),

            'fnb_uoms' => DB::table('fnb_uoms as u')
                ->leftJoin('fnb_item_masters as im', 'im.fnb_uom_id', '=', 'u.id')
                ->groupBy('u.id', 'u.name')
                ->orderBy('u.name')
                ->offset($offset)->limit($perPage)
                ->get(['u.name', DB::raw('count(im.id) as item_count')])
                ->map(fn ($r) => (array) $r)->all(),

            'fnb_warehouses' => DB::table('fnb_warehouses as w')
                ->leftJoin('states as s', 's.id', '=', 'w.state_id')
                ->leftJoin('fnb_inventories as i', 'i.fnb_warehouse_id', '=', 'w.id')
                ->groupBy('w.id', 'w.warehouse_name', 's.name')
                ->orderBy('w.warehouse_name')
                ->offset($offset)->limit($perPage)
                ->get([
                    'w.warehouse_name', 's.name as state_name',
                    DB::raw('count(i.id) as inventory_count'),
                ])->map(fn ($r) => (array) $r)->all(),

            'fnb_inventories' => DB::table('fnb_inventories as i')
                ->leftJoin('fnb_item_masters as im', 'im.id', '=', 'i.fnb_item_master_id')
                ->leftJoin('fnb_uoms as u', 'u.id', '=', 'i.fnb_uom_id')
                ->leftJoin('item_categories as ic', 'ic.id', '=', 'i.item_category_id')
                ->leftJoin('fnb_warehouses as w', 'w.id', '=', 'i.fnb_warehouse_id')
                ->when($search !== '', fn ($q) => $q->where('im.item_name', 'ilike', '%'.$search.'%'))
                ->orderBy('im.item_name')
                ->offset($offset)->limit($perPage)
                ->get([
                    'im.item_name', 'u.name as uom_name', 'i.available_qty',
                    'ic.name as item_category_name', 'w.warehouse_name',
                    'i.price', 'i.creator_id',
                ])->map(fn ($r) => (array) $r)->all(),


            'fnb_vendor_order_bookings' => DB::table('fnb_vendor_order_bookings as o')
                ->leftJoin('vendors as v', 'v.id', '=', 'o.vendor_id')
                ->leftJoin('locations as l', 'l.id', '=', 'o.location_id')
                ->when($search !== '', fn ($q) => $q->where('o.order_no', 'ilike', '%'.$search.'%'))
                ->orderByDesc('o.order_date')->orderByDesc('o.id')
                ->offset($offset)->limit($perPage)
                ->get([
                    'o.order_no', 'o.order_date', 'o.order_for', 'v.name as vendor_name',
                    'l.name as location_name', 'o.booking_no', 'o.status', 'o.payment_status',
                    'o.amount', 'o.discount', 'o.grand_total', 'o.adjusted_amount',
                    'o.payable_amount',
                ])->map(fn ($r) => (array) $r)->all(),

            'fnb_vendor_order_booking_items' => DB::table('fnb_vendor_order_booking_items as i')
                ->leftJoin('fnb_vendor_order_bookings as o', 'o.id', '=', 'i.fnb_vendor_order_booking_id')
                ->leftJoin('fnb_item_masters as im', 'im.id', '=', 'i.fnb_item_master_id')
                ->leftJoin('fnb_uoms as u', 'u.id', '=', 'i.fnb_uom_id')
                ->when($search !== '', fn ($q) => $q->where('im.item_name', 'ilike', '%'.$search.'%'))
                ->orderByDesc('i.id')
                ->offset($offset)->limit($perPage)
                ->get([
                    'o.order_no', 'im.item_name', 'u.name as uom_name',
                    'i.ordered_quantity', 'i.fulfilled_quantity', 'i.received_quantity',
                    'i.price', 'i.amount',
                ])->map(fn ($r) => (array) $r)->all(),

            'fnb_transaction_items' => DB::table('fnb_transaction_items as t')
                ->leftJoin('fnb_item_masters as im', 'im.id', '=', 't.fnb_item_master_id')
                ->leftJoin('fnb_warehouses as w', 'w.id', '=', 't.fnb_warehouse_id')
                ->when($search !== '', fn ($q) => $q->where('im.item_name', 'ilike', '%'.$search.'%'))
                ->orderByDesc('t.id')
                ->offset($offset)->limit($perPage)
                ->get([
                    't.transaction_type', 'im.item_name', 'w.warehouse_name',
                    't.quantity', 't.price', 't.amount',
                ])->map(fn ($r) => (array) $r)->all(),

            'fnb_raw_material_requests' => DB::table('fnb_raw_material_requests as r')
                ->leftJoin('fnb_item_masters as im', 'im.id', '=', 'r.fnb_item_master_id')
                ->when($search !== '', fn ($q) => $q->where('im.item_name', 'ilike', '%'.$search.'%'))
                ->orderByDesc('r.id')
                ->offset($offset)->limit($perPage)
                ->get([
                    'r.request_no', 'im.item_name', 'r.uom_text',
                    'r.requested_quantity', 'r.delivered_quantity', 'r.pending_quantity',
                    'r.warehouse_quantity', 'r.order_quantity', 'r.request_from',
                ])->map(fn ($r) => (array) $r)->all(),

            /*
             * GUEST NAME IS NOT SELECTED, and that is deliberate rather than an
             * oversight. The column exists and the CSV import populates it with real
             * guest names; there is no authorisation in front of this endpoint, so it
             * is not sent to a browser.
             */
            'fnb_request_stock_for_foods' => DB::table('fnb_request_stock_for_foods as r')
                ->leftJoin('villas as vl', 'vl.id', '=', 'r.villa_id')
                ->leftJoin('locations as l', 'l.id', '=', 'r.location_id')
                ->when($search !== '', fn ($q) => $q->where('r.request_no', 'ilike', '%'.$search.'%'))
                ->orderByDesc('r.id')
                ->offset($offset)->limit($perPage)
                ->get([
                    'r.request_no', 'r.booking_no', 'vl.name as villa_name',
                    'l.name as location_name', 'r.chef_name', 'r.status',
                    'r.request_from', 'r.checked_in_date', 'r.check_out_date',
                ])->map(fn ($r) => (array) $r)->all(),

            'fnb_inventory_stocks' => DB::table('fnb_inventory_stocks as s')
                ->leftJoin('fnb_inventories as inv', 'inv.id', '=', 's.fnb_inventory_id')
                ->leftJoin('fnb_item_masters as im', 'im.id', '=', 'inv.fnb_item_master_id')
                ->leftJoin('fnb_warehouses as w', 'w.id', '=', 'inv.fnb_warehouse_id')
                ->orderByDesc('s.stock_date')->orderByDesc('s.id')
                ->offset($offset)->limit($perPage)
                ->get([
                    's.stock_date', 'im.item_name', 'w.warehouse_name',
                    's.quantity', 's.price',
                ])->map(fn ($r) => (array) $r)->all(),

            'fnb_vendor_price_lists' => DB::table('fnb_vendor_price_lists as pl')
                ->leftJoin('fnb_item_masters as im', 'im.id', '=', 'pl.fnb_item_master_id')
                ->leftJoin('vendors as v', 'v.id', '=', 'pl.vendor_id')
                ->orderBy('im.item_name')
                ->offset($offset)->limit($perPage)
                ->get(['im.item_name', 'v.name as vendor_name', 'pl.price', 'pl.deviation'])
                ->map(fn ($r) => (array) $r)->all(),

            // Phone, email and address are stored and NOT selected. Same reason.
            'fnb_chef_masters' => DB::table('fnb_chef_masters as c')
                ->leftJoin('locations as l', 'l.id', '=', 'c.location_id')
                ->orderBy('c.name')
                ->offset($offset)->limit($perPage)
                ->get(['c.name', 'c.chef_id', 'l.name as location_name', 'c.status'])
                ->map(fn ($r) => (array) $r)->all(),

            'fnb_recipe_masters' => DB::table('fnb_recipe_masters')
                ->orderBy('recipe_name')->offset($offset)->limit($perPage)
                ->get(['recipe_name'])->map(fn ($r) => (array) $r)->all(),

            'fnb_food_order_details' => DB::table('fnb_food_order_details')
                ->orderByDesc('id')->offset($offset)->limit($perPage)
                ->get(['booking_no', 'meal_name', 'guest_count', 'meal_details'])
                ->map(fn ($r) => (array) $r)->all(),

            'fnb_monthly_checks' => DB::table('fnb_monthly_checks as m')
                ->leftJoin('fnb_warehouses as w', 'w.id', '=', 'm.fnb_warehouse_id')
                ->leftJoin('locations as l', 'l.id', '=', 'm.location_id')
                ->orderByDesc('m.check_date')
                ->offset($offset)->limit($perPage)
                ->get(['m.check_date', 'w.warehouse_name as warehouse_name',
                       'l.name as location_name', 'm.status'])
                ->map(fn ($r) => (array) $r)->all(),

            'fnb_transfer_items' => DB::table('fnb_transfer_items as t')
                ->leftJoin('fnb_warehouses as f', 'f.id', '=', 't.from_fnb_warehouse_id')
                ->leftJoin('fnb_warehouses as tw', 'tw.id', '=', 't.to_fnb_warehouse_id')
                ->orderByDesc('t.id')
                ->offset($offset)->limit($perPage)
                ->get(['t.transfer_id', 'f.warehouse_name as from_warehouse',
                       'tw.warehouse_name as to_warehouse', 't.status'])
                ->map(fn ($r) => (array) $r)->all(),
            'fnb_billing_cycles' => DB::table('billing_cycles')
                ->orderBy('year')->orderBy('month_index')
                ->offset($offset)->limit($perPage)
                ->get(['month_name', 'year', 'month_index'])
                ->map(fn ($r) => (array) $r)->all(),

            'fnb_auto_numbers' => DB::table('fnb_auto_numbers')
                ->get(array_column($def['columns'], 'key'))
                ->map(fn ($r) => (array) $r)->all(),

            default => [],
        };
    }

    private function countFor(string $report, array $def, string $search): int
    {
        if ($search === '') {
            return DB::table($def['table'])->count();
        }

        return match ($report) {
            'fnb_item_masters' => DB::table('fnb_item_masters')
                ->where('item_name', 'ilike', '%'.$search.'%')->count(),
            'fnb_inventories' => DB::table('fnb_inventories as i')
                ->leftJoin('fnb_item_masters as im', 'im.id', '=', 'i.fnb_item_master_id')
                ->where('im.item_name', 'ilike', '%'.$search.'%')->count(),
            'fnb_vendor_order_bookings' => DB::table('fnb_vendor_order_bookings')
                ->where('order_no', 'ilike', '%'.$search.'%')->count(),
            'fnb_vendor_order_booking_items' => DB::table('fnb_vendor_order_booking_items as i')
                ->leftJoin('fnb_item_masters as im', 'im.id', '=', 'i.fnb_item_master_id')
                ->where('im.item_name', 'ilike', '%'.$search.'%')->count(),
            'fnb_transaction_items' => DB::table('fnb_transaction_items as t')
                ->leftJoin('fnb_item_masters as im', 'im.id', '=', 't.fnb_item_master_id')
                ->where('im.item_name', 'ilike', '%'.$search.'%')->count(),
            'fnb_raw_material_requests' => DB::table('fnb_raw_material_requests as r')
                ->leftJoin('fnb_item_masters as im', 'im.id', '=', 'r.fnb_item_master_id')
                ->where('im.item_name', 'ilike', '%'.$search.'%')->count(),
            'fnb_request_stock_for_foods' => DB::table('fnb_request_stock_for_foods')
                ->where('request_no', 'ilike', '%'.$search.'%')->count(),
            default => DB::table($def['table'])->count(),
        };
    }
}
