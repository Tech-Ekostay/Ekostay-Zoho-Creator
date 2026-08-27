<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Bills\Money;
use App\Domain\Bills\SplitAllocator;
use App\Domain\Bills\SplitLeg;
use App\Domain\Bills\SplitValidator;
use App\Domain\Reports\ReportFilter;
use App\Http\Controllers\Controller;
use App\Models\Bill;
use App\Models\BillingCycle;
use App\Models\ItemCategory;
use App\Models\TdsRate;
use App\Models\Vendor;
use App\Models\Villa;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Bills — §6, and §17 step 5's read API plus the write path the `+` button needs.
 *
 * The schema and the arithmetic landed in step 4; this is what makes them reachable.
 * Nothing here re-implements the split rules — `SplitAllocator` and `SplitValidator`
 * already carry them, including §6.3's remainder-on-the-last-row and §5.1's
 * reconcile-never-clear-and-rebuild.
 *
 * TWO RULES FROM §6 THAT THIS DELIBERATELY HONOURS:
 *
 *  - **Billing cycles are never auto-created.** §6.4 is emphatic: Creator INSERTs a
 *    missing cycle during month derivation, and that is the defect that put a junk
 *    `"9-2026"` cycle into live accounting. A cycle must already exist or the write
 *    is refused.
 *  - **The split is validated at whole rupees, as Creator has it** (§6.4 rule 1),
 *    with the sub-rupee residual surfaced as a WARNING rather than hidden. That is
 *    the opposite of the Payments check (§7.4), which compares exactly — and the
 *    difference is intentional: a bill's legs are typed by a human, a payment's are
 *    computed by us.
 *
 * `payable_amount` is stored from the request rather than derived. §6.3 records two
 * different formulas producing different quantities under the same field name and
 * which one is authoritative is still an open `[TODO]`; computing it here would bake
 * in a guess.
 */
class BillController extends Controller
{
    use Concerns\FiltersReports;

    /** Column labels in report order. §6.1's "which All Bills report is live" is open. */
    private const COLUMNS = [
        'Bill No',
        'Vendor Name',
        'Bill Date',
        'Due Date',
        'Gross Amount',
        'GST Amount',
        'TDS Amount',
        'Payable Amount',
        'Paid Amount',
        'Status',
        'Location',
        'ID',
    ];

    public function __construct(
        private readonly SplitAllocator $allocator = new SplitAllocator,
        private readonly SplitValidator $validator = new SplitValidator,
    ) {}

    /**
     * Filterable columns, whitelisted. See ReportFilter on why a whitelist and not a
     * convenience: a column name arriving from a request and reaching a query builder
     * is how arbitrary columns get read.
     *
     * `Vendor Name` filters through the relation so a user filters on the name they
     * can see rather than a foreign key. 315 bills have no vendor at all (§6's
     * invisible deletions) and those never match a vendor filter — correct, and worth
     * knowing before someone reads a short result as a bug.
     */
    private function filterable(): ReportFilter
    {
        return new ReportFilter([
            'Bill No' => ['column' => 'bill_no', 'type' => 'text'],
            'Vendor Name' => ['column' => 'name', 'type' => 'text', 'relation' => 'vendor'],
            'Bill Date' => ['column' => 'bill_date', 'type' => 'date'],
            'Due Date' => ['column' => 'due_date', 'type' => 'date'],
            'Gross Amount' => ['column' => 'amount', 'type' => 'money'],
            'GST Amount' => ['column' => 'gst_amount', 'type' => 'money'],
            'TDS Amount' => ['column' => 'tds_amount', 'type' => 'money'],
            'Payable Amount' => ['column' => 'payable_amount', 'type' => 'money'],
            'Paid Amount' => ['column' => 'paid_amount', 'type' => 'money'],
            'Status' => ['column' => 'status', 'type' => 'text'],
            'Location' => ['column' => 'name', 'type' => 'text', 'relation' => 'location'],
            'ID' => ['column' => 'creator_id', 'type' => 'text'],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $query = Bill::query()->with(['vendor', 'location'])->latest('id');

        $filter = $this->filterable();
        $filters = $this->requestedFilters($request);

        if ($error = $this->applyFilters($filter, $query, $filters)) {
            return response()->json(['message' => $error, 'reason' => 'bad_filter'], 422);
        }

        $matched = (clone $query)->count();

        // 1000, matching Creator's page size — see PaymentController::index.
        $bills = $query->limit(1000)->get();

        return response()->json([
            'report' => 'bills',
            'title' => 'All Bills',
            'columns' => self::COLUMNS,
            'total' => Bill::query()->count(),
            'matched' => $matched,
            'filter_schema' => $filter->schema(),
            'filters' => $filters,
            'rows' => $bills->map(fn (Bill $bill): array => $this->row($bill))->all(),
        ]);
    }

    /** Everything a form needs to open: the pickers, in the order the report uses. */
    public function options(): JsonResponse
    {
        return response()->json([
            /*
             * VENDORS ARE NOT SENT HERE ANY MORE. This used to return every vendor
             * for a `<select>`, which was reasonable while the only vendor in the
             * database was TestBillSeeder's fixture. There are now 8,063 real ones,
             * and shipping them all would push the entire PII table — PANs, GST
             * registrations, bank details — into the browser to fill a dropdown
             * nobody can scroll. The form searches `/api/vendors/lookup` instead.
             *
             * The count still travels so the form can say how many are selectable
             * rather than leaving an empty picker looking broken.
             */
            /*
             * SAME FILTER AS THE PICKER, and it was not before.
             *
             * When VendorController::lookup() was corrected to Creator's
             * `main_primary IS NOT NULL` (6,957), this count was left on the old
             * `notMergedAway()` (7,947). So the Bills form advertised 7,947
             * selectable vendors while the picker offered 6,957 — a thousand
             * vendors the hint promised and the control did not have. Fixing one
             * side of a filter and not the other is its own bug.
             */
            'vendor_count' => Vendor::query()->whereNotNull('main_primary')
                ->where('name', '<>', '')->count(),
            // §6.2 — the Bills villa picker is admin.Villa[Hide_From_Payments == false].
            'villas' => Villa::query()->selectableForPayments()->orderBy('name')->get(['id', 'name'])
                ->map(fn ($v): array => ['value' => (string) $v->id, 'label' => $v->name])->all(),
            // §6.2 — `Disable` is "Disallow Manual Creation", a hard block at validate.
            'item_categories' => ItemCategory::query()->where('disable', false)->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn ($c): array => ['value' => (string) $c->id, 'label' => $c->name])->all(),
            'billing_cycles' => BillingCycle::query()
                ->orderByDesc('year')->orderByDesc('month_index')->get()
                ->map(fn ($c): array => ['value' => (string) $c->id, 'label' => $c->label()])->all(),
            'tds_rates' => TdsRate::query()->orderBy('name')->get(['id', 'name', 'tds_percentage'])
                ->map(fn ($t): array => ['value' => (string) $t->id, 'label' => $t->name.' — '.$t->tds_percentage.'%'])->all(),
        ]);
    }

    public function show(Bill $bill): JsonResponse
    {
        $bill->load([
            'vendor', 'location', 'tdsRate',
            'villas', 'itemCategories', 'billingCycles',
            'splitPayments.villa', 'splitPayments.itemCategory', 'splitPayments.billingCycle',
            'amountCategories.tax',
            'payments',
        ]);

        return response()->json([
            'bill' => $this->row($bill) + [
                'gst_needed' => $bill->gst_needed,
                'split_equally' => $bill->split_equally,
                'invoice_amount' => $bill->invoice_amount ?? '',
                'ca_email' => $bill->ca_email ?? '',
                'payment_count' => $bill->payments->count(),
                // A bill with a payment against it must not have its allocation
                // rewritten underneath that payment — §6.5's Paid lock, narrow reading.
                'locked' => $bill->payments->isNotEmpty(),
            ],
            'values' => [
                'bill_no' => $bill->bill_no ?? '',
                'bill_date' => $bill->bill_date?->toDateString() ?? '',
                'due_date' => $bill->due_date?->toDateString() ?? '',
                'vendor_id' => (string) ($bill->vendor_id ?? ''),
                // The picker no longer receives a full vendor list, so the
                // stored selection has to arrive with its own label — an id
                // with nothing to display reads as an empty field.
                'vendor_name' => $bill->vendor?->name ?? '',
                'tds_rate_id' => (string) ($bill->tds_rate_id ?? ''),
                'status' => $bill->status ?? '',
                'amount' => $bill->amount ?? '',
                'gst_amount' => $bill->gst_amount ?? '',
                'tds_amount' => $bill->tds_amount ?? '',
                'invoice_amount' => $bill->invoice_amount ?? '',
                'payable_amount' => $bill->payable_amount ?? '',
                'split_equally' => $bill->split_equally,
                'villa_ids' => $bill->villas->pluck('id')->map(fn ($id): string => (string) $id)->all(),
                'item_category_ids' => $bill->itemCategories->pluck('id')->map(fn ($id): string => (string) $id)->all(),
                'billing_cycle_ids' => $bill->billingCycles->pluck('id')->map(fn ($id): string => (string) $id)->all(),
            ],
            'split_payments' => $bill->splitPayments->map(fn ($leg): array => [
                'id' => $leg->id,
                'villa_id' => (string) ($leg->villa_id ?? ''),
                'item_category_id' => (string) ($leg->item_category_id ?? ''),
                'billing_cycle_id' => (string) ($leg->billing_cycle_id ?? ''),
                'Villa Name' => $leg->villa?->name ?? '',
                'Item Category' => $leg->itemCategory?->name ?? '',
                'Billing Cycle' => $leg->billingCycle?->label() ?? '',
                'Gross Amount' => $leg->total_amount ?? '',
                'TDS Amount' => $leg->tds_amount ?? '',
                'GST Amount' => $leg->gst_amount ?? '',
                'Amount' => $leg->amount ?? '',
                'flagged' => $leg->flagged,
                'flag_reason' => $leg->flag_reason ?? '',
            ])->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        return $this->save($request, new Bill);
    }

    public function update(Request $request, Bill $bill): JsonResponse
    {
        // §6.5's Paid lock, narrow reading: once a payment exists against a bill its
        // allocation is what that payment was built from, so rewriting it silently
        // changes what was paid. Widening this later is safe; starting wide is not.
        if ($bill->payments()->exists()) {
            return response()->json([
                'message' => 'This bill already has a payment against it. Its allocation is what that '
                    .'payment was built from, so editing it would change what was paid. Reverse the '
                    .'payment first (§7.6).',
                'reason' => 'bill_locked',
            ], 422);
        }

        return $this->save($request, $bill);
    }

    private function save(Request $request, Bill $bill): JsonResponse
    {
        $data = $request->validate([
            'bill_no' => ['required', 'string', 'max:2000'],
            'bill_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date'],
            'vendor_id' => ['nullable', 'integer', 'exists:vendors,id'],
            'tds_rate_id' => ['nullable', 'integer', 'exists:tds_rates,id'],
            'status' => ['nullable', 'string', 'max:64'],

            'amount' => ['required', 'numeric'],
            'gst_amount' => ['nullable', 'numeric'],
            'tds_amount' => ['nullable', 'numeric'],
            'invoice_amount' => ['nullable', 'numeric'],
            'payable_amount' => ['nullable', 'numeric'],

            'split_equally' => ['sometimes', 'boolean'],

            'villa_ids' => ['array'],
            'villa_ids.*' => ['integer', 'exists:villas,id'],
            'item_category_ids' => ['array'],
            'item_category_ids.*' => ['integer', 'exists:item_categories,id'],
            // NOT `exists` with an auto-create fallback — §6.4. A cycle that does not
            // exist is an error, never something to create on the fly.
            'billing_cycle_ids' => ['array'],
            'billing_cycle_ids.*' => ['integer', 'exists:billing_cycles,id'],

            'legs' => ['array'],
            'legs.*.villa_id' => ['nullable', 'integer'],
            'legs.*.item_category_id' => ['nullable', 'integer'],
            'legs.*.billing_cycle_id' => ['nullable', 'integer'],
            'legs.*.amount' => ['nullable', 'numeric'],
            'legs.*.gst_amount' => ['nullable', 'numeric'],
            'legs.*.tds_amount' => ['nullable', 'numeric'],
            'legs.*.total_amount' => ['nullable', 'numeric'],
        ]);

        $legs = array_map(fn (array $leg): SplitLeg => new SplitLeg(
            villaId: $leg['villa_id'] ?? null,
            itemCategoryId: $leg['item_category_id'] ?? null,
            billingCycleId: $leg['billing_cycle_id'] ?? null,
            amount: isset($leg['amount']) ? (string) $leg['amount'] : null,
            gstAmount: isset($leg['gst_amount']) ? (string) $leg['gst_amount'] : null,
            tdsAmount: isset($leg['tds_amount']) ? (string) $leg['tds_amount'] : null,
            totalAmount: isset($leg['total_amount']) ? (string) $leg['total_amount'] : null,
        ), $data['legs'] ?? []);

        $gross = (string) $data['amount'];

        /*
         * §6.4 rule 1, as Creator has it: compared at WHOLE RUPEES. Reproduced
         * deliberately rather than tightened, because a bill's legs are typed by a
         * human. The sub-rupee residual comes back as a warning so it is visible
         * instead of silently absorbed.
         */
        $errors = $legs === [] ? [] : $this->validator->blockingErrors($legs, $gross);

        if ($errors !== []) {
            return response()->json([
                'message' => implode(' ', $errors),
                'reason' => 'split_mismatch',
                'errors' => ['legs' => $errors],
            ], 422);
        }

        $warnings = $legs === [] ? [] : $this->validator->warnings($legs, $gross);

        $bill = DB::transaction(function () use ($bill, $data, $legs): Bill {
            $bill->fill([
                'bill_no' => $data['bill_no'],
                'bill_date' => $data['bill_date'] ?? null,
                'due_date' => $data['due_date'] ?? null,
                'vendor_id' => $data['vendor_id'] ?? null,
                'tds_rate_id' => $data['tds_rate_id'] ?? null,
                'status' => $data['status'] ?? 'Draft',
                'amount' => Money::normalise((string) $data['amount']),
                'gst_amount' => isset($data['gst_amount']) ? Money::normalise((string) $data['gst_amount']) : null,
                'tds_amount' => isset($data['tds_amount']) ? Money::normalise((string) $data['tds_amount']) : null,
                'invoice_amount' => isset($data['invoice_amount']) ? Money::normalise((string) $data['invoice_amount']) : null,
                'payable_amount' => isset($data['payable_amount']) ? Money::normalise((string) $data['payable_amount']) : null,
                'split_equally' => $data['split_equally'] ?? false,
            ]);

            // Location derives FROM the villas on Bills (§5.1).
            $firstVilla = isset($data['villa_ids'][0]) ? Villa::find($data['villa_ids'][0]) : null;
            $bill->location_id = $firstVilla?->location_id;
            $bill->head_office_id = $firstVilla?->head_office_id ?? $bill->head_office_id;

            $bill->save();

            $bill->villas()->sync($data['villa_ids'] ?? []);
            $bill->itemCategories()->sync($data['item_category_ids'] ?? []);
            $bill->billingCycles()->sync($data['billing_cycle_ids'] ?? []);

            /*
             * RECONCILE, never clear-and-rebuild. §5.1 and §15.1: Creator wipes this
             * grid on every scope change, destroying typed amounts. Surviving
             * combinations keep their money, new ones arrive blank, and a combination
             * that no longer applies is dropped only if empty.
             */
            $existing = $bill->splitPayments()->get()->map(fn ($row): SplitLeg => new SplitLeg(
                villaId: $row->villa_id,
                itemCategoryId: $row->item_category_id,
                billingCycleId: $row->billing_cycle_id,
                amount: $row->amount,
                gstAmount: $row->gst_amount,
                tdsAmount: $row->tds_amount,
                totalAmount: $row->total_amount,
            ))->all();

            // Note the argument order: villas, CYCLES, categories — the same order
            // combinations() takes, which is not the order the form lists them in.
            $reconciled = $this->allocator->reconcile(
                $existing,
                $data['villa_ids'] ?? [],
                $data['billing_cycle_ids'] ?? [],
                $data['item_category_ids'] ?? [],
            );

            // Money typed in this request wins over the reconciled baseline.
            $submitted = [];
            foreach ($legs as $leg) {
                $submitted[$leg->key()] = $leg;
            }

            $bill->splitPayments()->delete();

            foreach (array_values($reconciled) as $position => $leg) {
                $money = $submitted[$leg->key()] ?? $leg;

                $bill->splitPayments()->create([
                    'villa_id' => $leg->villaId,
                    'item_category_id' => $leg->itemCategoryId,
                    'billing_cycle_id' => $leg->billingCycleId,
                    'amount' => $money->amount,
                    'gst_amount' => $money->gstAmount,
                    'tds_amount' => $money->tdsAmount,
                    'total_amount' => $money->totalAmount,
                    // The backend triplet mirrors the live figures while nothing is
                    // paid — addendum §10.
                    'backend_total_amount' => $money->totalAmount,
                    'backend_gst_amount' => $money->gstAmount,
                    'backend_tds_amount' => $money->tdsAmount,
                    'flagged' => $leg->flagged,
                    'flag_reason' => $leg->flagReason,
                    'position' => $position,
                ]);
            }

            return $bill;
        });

        return response()->json([
            'id' => $bill->id,
            'bill_no' => $bill->bill_no,
            'split_legs' => $bill->splitPayments()->count(),
            'warnings' => $warnings,
        ], $bill->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * Split equally across the current combinations — §6.3.
     *
     * Exposed as its own endpoint because the rule has a remainder convention that
     * must not be re-implemented in JavaScript: it TRUNCATES at paisa and puts
     * everything the truncation dropped on the LAST row. §6.3 is explicit —
     * "Reproduce exactly. Do not substitute banker's rounding."
     */
    public function splitEqually(Request $request): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric'],
            'gst_amount' => ['nullable', 'numeric'],
            'tds_percentage' => ['nullable', 'numeric'],
            'villa_ids' => ['array'],
            'item_category_ids' => ['array'],
            'billing_cycle_ids' => ['array'],
        ]);

        $combinations = $this->allocator->combinations(
            $data['villa_ids'] ?? [],
            $data['billing_cycle_ids'] ?? [],
            $data['item_category_ids'] ?? [],
        );

        if ($combinations === []) {
            return response()->json(['legs' => [], 'total' => Money::zero()]);
        }

        /*
         * GST and the TDS percentage go through as well, because §6.3's rule computes
         * all three per row: TDS is `row.Amount x tdsPct / 100`, so the sum of per-row
         * TDS need not equal TDS on the bill total. That is documented behaviour and
         * SplitAllocator reproduces it — passing only the amount would silently drop it.
         */
        $legs = $this->allocator->splitEqually(
            $combinations,
            (string) $data['amount'],
            isset($data['gst_amount']) ? (string) $data['gst_amount'] : null,
            isset($data['tds_percentage']) ? (string) $data['tds_percentage'] : null,
        );

        return response()->json([
            'legs' => array_map(fn (SplitLeg $leg): array => [
                'villa_id' => (string) ($leg->villaId ?? ''),
                'item_category_id' => (string) ($leg->itemCategoryId ?? ''),
                'billing_cycle_id' => (string) ($leg->billingCycleId ?? ''),
                'amount' => $leg->amount,
                'total_amount' => $leg->totalAmount,
                'gst_amount' => $leg->gstAmount,
                'tds_amount' => $leg->tdsAmount,
            ], $legs),
            'total' => $this->allocator->total($legs),
        ]);
    }

    private function row(Bill $bill): array
    {
        return [
            'id' => $bill->id,
            'Bill No' => $bill->bill_no ?? '',
            'Vendor Name' => $bill->vendor?->name ?? '',
            'Bill Date' => $bill->bill_date?->toDateString() ?? '',
            'Due Date' => $bill->due_date?->toDateString() ?? '',
            'Gross Amount' => $bill->amount ?? '',
            'GST Amount' => $bill->gst_amount ?? '',
            'TDS Amount' => $bill->tds_amount ?? '',
            'Payable Amount' => $bill->payable_amount ?? '',
            'Paid Amount' => $bill->paid_amount ?? '',
            'Status' => $bill->status ?? '',
            'Location' => $bill->location?->name ?? '',
            'ID' => $bill->creator_id ?? '',
        ];
    }
}
