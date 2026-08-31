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
            default => DB::table($def['table'])->count(),
        };
    }
}
