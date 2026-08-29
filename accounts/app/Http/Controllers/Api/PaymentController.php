<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Bills\Money;
use App\Domain\Payments\CreatePaymentFromBill;
use App\Domain\Payments\PaymentFieldState;
use App\Domain\Payments\PaymentFormCalculator;
use App\Domain\Payments\PaymentSaveRules;
use App\Domain\Payments\PaymentNumber;
use App\Domain\Payments\PaymentStatus;
use App\Domain\Payments\ReversalRefusedException;
use App\Domain\Payments\ReversePayment;
use App\Domain\Payments\UnbalancedPaymentException;
use App\Domain\Reports\ReportFilter;
use App\Http\Controllers\Controller;
use App\Models\Bill;
use App\Models\BillingCycle;
use App\Models\CoaAccount;
use App\Models\ItemCategory;
use App\Models\Location;
use App\Models\MasterCategory;
use App\Models\Payment;
use App\Models\Tax;
use App\Models\TdsRate;
use App\Models\Vendor;
use App\Models\Villa;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Payments — the first write endpoints in the application (§7).
 *
 * Response shape follows §17 step 5, same as SettingsReportController: amounts are
 * strings and never floats, dates are 'YYYY-MM-DD', and absent values are empty
 * strings rather than null because that is what Creator renders.
 *
 * COLUMN ORDER IS INFERRED HERE, AND THAT IS A KNOWN GAP. handoff §6 item 4: "All
 * Payments column set — the Payments module's column order is inferred, not seen.
 * Recoverable, Bank Reconciliation and Withdrawal Ma... exist and are not in it,
 * and there is a per-row action button." The order below is the best reading of
 * §7.1 plus the reference JSX, and it is the one thing on this screen that a
 * screenshot would settle immediately.
 *
 * THERE IS NO AUTHORISATION ON THESE ROUTES YET. The §3.3 matrix is extracted and
 * tested (122 permissions, docs/permission_matrix.json) but not yet wired to a
 * gate, so anyone reaching the API can create and reverse payments. Acceptable on
 * a local build with seeded data; a blocker before this is exposed to anything
 * else. Flagged rather than half-built.
 */
class PaymentController extends Controller
{
    /*
     * NOTE: this controller carries its OWN `requestedFilters()` (see near the bottom)
     * rather than using `Concerns\FiltersReports`, because it predates that trait. Not
     * refactored here — unifying them is a separate change from adding paging, and
     * touching the filter path on the 52,639-row report needs its own verification.
     */
    use Concerns\PagesReports;

    /** Column labels in report order — see the docblock on why this is provisional. */
    private const COLUMNS = [
        'Payment No',
        'Vendor Name',
        'Payment Date',
        'Due Date',
        'Amount',
        'TDS Amount',
        'GST Amount',
        'Payable Amount',
        'Status',
        'Payment Status',
        'Location',
        'ID',
    ];

    /**
     * Which columns this report can be filtered on, and as what type.
     *
     * A WHITELIST, not a convenience. A column name arriving from a request and
     * reaching a query builder is how arbitrary columns get read, and this table
     * holds 52,638 payments. Anything not named here is rejected by name.
     *
     * `Vendor Name` filters THROUGH the relation, so a user filters on the name they
     * can see rather than on a foreign key they cannot.
     */
    private function filterable(): ReportFilter
    {
        return new ReportFilter([
            'Payment No' => ['column' => 'payment_no', 'type' => 'text'],
            'Vendor Name' => ['column' => 'name', 'type' => 'text', 'relation' => 'vendor'],
            'Payment Date' => ['column' => 'payment_date', 'type' => 'date'],
            'Due Date' => ['column' => 'due_date', 'type' => 'date'],
            'Amount' => ['column' => 'amount', 'type' => 'money'],
            'TDS Amount' => ['column' => 'tds_amount', 'type' => 'money'],
            'GST Amount' => ['column' => 'gst_amount', 'type' => 'money'],
            'Payable Amount' => ['column' => 'payable_amount', 'type' => 'money'],
            'Status' => ['column' => 'status', 'type' => 'text'],
            'Payment Status' => ['column' => 'payment_status', 'type' => 'text'],
            'Location' => ['column' => 'name', 'type' => 'text', 'relation' => 'location'],
            'ID' => ['column' => 'creator_id', 'type' => 'text'],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $query = Payment::query()->with(['vendor', 'location'])->latest('id');

        /*
         * Filters are applied to the QUERY, not to the page.
         *
         * The old free-text search filtered whatever the browser had already loaded
         * — the first 1,000 of 52,638 — so a payment at row 5,000 came back as "no
         * match" rather than "not on this page". An InvalidArgumentException here is
         * a 422 with the allowed columns named, because a filter that silently does
         * nothing is worse than one that errors: the unfiltered result reads as the
         * filtered one.
         */

        /*
         * AN EXPLICIT, TOTAL ORDERING — and it was missing until 28-Aug-2026.
         *
         * This index had NO `order by` at all, which caused two separate faults:
         *
         *  1. Postgres returned rows in whatever order the plan produced, so the newest
         *     payment was not at the top. Husain reported "still not getting the live
         *     data" partly for this reason: 21697 WAS present, sitting between 21317 and
         *     21370 where nobody would look for it.
         *
         *  2. It silently broke the paging added the same day. `PagesReports` documents
         *     that offset paging is only correct when the sort is TOTAL — without one,
         *     page 2 can repeat or skip rows the reader never sees. Wiring paging into an
         *     unordered query violated the precondition the trait itself states.
         *
         * `added_time desc, id desc` matches every other report here and puts the newest
         * first, which is what makes "did my payment arrive?" answerable. The `id`
         * tiebreak is what makes it total: `added_time` is null on plenty of imported
         * rows and ties on many more.
         *
         * The live All Payments report's own sort order is NOT verified — no screenshot
         * records it — so this is chosen for correctness and consistency, and flagged as
         * assumed rather than replicated.
         */
            /*
             * NULLS LAST, EXPLICITLY. Postgres sorts NULLs FIRST on a DESC order, so
             * `order by added_time desc` floated the 40 rows with no timestamp above
             * all 53,240 real ones — the top of All Payments was blank rows, which
             * reads exactly like a broken sync. A missing stamp is missing data and
             * belongs at the END, not presented as the newest thing in the system.
             */
        $query->orderByRaw('added_time desc nulls last')->orderByDesc('id');

        $filter = $this->filterable();
        $filters = $this->requestedFilters($request);

        try {
            $filter->apply($query, $filters);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'reason' => 'bad_filter'], 422);
        }

        $matched = (clone $query)->count();

        // Reversals are ledger rows and belong in the list by default; this lets a
        // caller ask for forward payments only without a second endpoint.
        if ($request->boolean('forward_only')) {
            $query->forward();
        }

        /*
         * 1000, MATCHING CREATOR'S PAGE SIZE — not an arbitrary cap.
         *
         * handoff §2 rule 8: Creator pages at 1000 and prints `Showing 1000 of ###`
         * above that, the total overflowing the field into literal hashes. At 500 the
         * footer read `Showing 500 of ###`, which is the right hashes with the wrong
         * count — a reviewer comparing against the live screen would see a difference.
         * Caught by rendering it against the 52,638 imported payments.
         */
        /*
         * ONE PAGE, plus whether another exists. `limit(1000)` was a hard CEILING:
         * row 1,001 of 52,639 was unreachable, so a filtered nil result was
         * indistinguishable from a truncated one — the exact confusion server-side
         * filtering was added to remove. The client appends as it scrolls.
         */
        $offset = $this->requestedOffset($request);
        $page = $this->page($query, $offset);
        $payments = $page['rows'];

        return response()->json([
            'report' => 'all_payments',
            'columns' => self::COLUMNS,
            ...$this->pagingEnvelope(
                $offset, $page['next_offset'], $matched, Payment::query()->count(),
            ),
            'filter_schema' => $filter->schema(),
            'filters' => $filters,
            'rows' => $payments->map(fn (Payment $p): array => $this->row($p))->all(),
        ]);
    }

    public function show(Payment $payment): JsonResponse
    {
        $payment->load([
            'vendor', 'location', 'coaAccount', 'bill',
            'billPayments.bill',
            'splitPayments.villa', 'splitPayments.itemCategory', 'splitPayments.billingCycle',
            'reverses', 'reversal',
        ]);

        return response()->json([
            'payment' => $this->row($payment) + [
                'coa' => $payment->coaAccount?->account_name ?? '',
                'remarks' => $payment->remarks ?? '',
                'payment_reference_number' => $payment->payment_reference_number ?? '',
                'requested_date' => $payment->requested_date?->toDateString() ?? '',
                'is_reversal' => $payment->isReversal(),
                'is_locked' => $payment->isLocked(),
                'reversal_reason' => $payment->reversal_reason ?? '',
                'reverses_payment_no' => $payment->reverses?->payment_no ?? '',
                'reversed_by_payment_no' => $payment->reversal?->payment_no ?? '',
            ],

            /*
             * WHAT THE FORM MAY RENDER, from `Accounts.ds:24240` via PaymentFieldState.
             *
             * Sent rather than re-derived in the browser. The rule is COA-dependent and
             * status-dependent and it is enforced by `update()`; a form computing it
             * independently is a form that can disagree with the guard that will reject
             * it, and the user finds out by having a save refused for a field the screen
             * let them type in.
             *
             * `editable` is the ONLY state a save may change. `is_editable` is false
             * exactly for the reversal pair, which is a deviation from Creator noted on
             * `update()` — Creator leaves `Update Payment` enabled on everything.
             */
            'field_states' => PaymentFieldState::for(
                $payment->coaAccount?->account_name,
                $payment->status,
            ),
            'is_accounts_payable' => PaymentFieldState::isAccountsPayable(
                $payment->coaAccount?->account_name,
            ),
            'is_editable' => ! $payment->isReversal() && $payment->reversal === null,

            /*
             * THE RECORD IN FORM SHAPE, which `payment` above is not.
             *
             * `row()` returns DISPLAY labels — "Payment No", "Vendor Name", "Amount" —
             * because that is what the grid and the detail view render. An edit form
             * needs the field names it will POST BACK, and seeding it from display
             * labels is how a form silently loads blank and then saves those blanks
             * over a real record.
             *
             * Built from `currentState()`, the same method the save rules are judged
             * against, so what the form loads and what the server validates cannot be
             * two different pictures of one payment.
             */
            'form' => array_map(
                fn (mixed $v): mixed => $v instanceof \DateTimeInterface ? $v->format('Y-m-d') : $v,
                $this->currentState($payment),
            ) + [
                'id' => $payment->id,
                'Payment No' => $payment->payment_no ?? '',
                'field_states' => PaymentFieldState::for(
                    $payment->coaAccount?->account_name,
                    $payment->status,
                ),
                'legs' => $payment->splitPayments->map(fn ($leg): array => [
                    'villa_id' => (string) ($leg->villa_id ?? ''),
                    'item_category_id' => (string) ($leg->item_category_id ?? ''),
                    'billing_cycle_id' => (string) ($leg->billing_cycle_id ?? ''),
                    'amount' => (string) ($leg->amount ?? ''),
                ])->all(),
            ],
            // DS fields the form cannot render because they have no column here yet —
            // Bill_No1 and Vendor_Order_Booking_No, the two Husain asked for.
            'unbuilt_fields' => PaymentFieldState::missingColumns(),
            'bill_payments' => $payment->billPayments->map(fn ($r): array => [
                'bill_no' => $r->bill?->bill_no ?? '',
                'bill_amount' => $r->bill_amount ?? '',
            ])->all(),
            // The split grid — per §5.2 the rows every downstream
            // villa-month-category figure resolves to.
            'split_payments' => $payment->splitPayments->map(fn ($leg): array => [
                'Villa Name' => $leg->villa?->name ?? '',
                'Item Category' => $leg->itemCategory?->name ?? '',
                'Billing Cycle' => $leg->billingCycle?->label() ?? '',
                'Gross Amount' => $leg->total_amount ?? '',
                'TDS Amount' => $leg->tds_amount ?? '',
                'GST Amount' => $leg->gst_amount ?? '',
                'Amount' => $leg->amount ?? '',
            ])->all(),
        ]);
    }

    /**
     * The `Create_Payment` action (§7.2), as an endpoint.
     *
     * 422 on an unbalanced split rather than 500: §7.4's check is a rejected write,
     * not a server fault, and the message carries both figures and the difference.
     */
    public function store(Request $request, CreatePaymentFromBill $create): JsonResponse
    {
        $validated = $request->validate([
            'bill_id' => ['required', 'integer', 'exists:bills,id'],
        ]);

        $bill = Bill::findOrFail($validated['bill_id']);

        try {
            $payment = $create($bill, $request->string('added_user')->toString() ?: null);
        } catch (UnbalancedPaymentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'reason' => 'unbalanced_split',
            ], 422);
        }

        return response()->json([
            'payment_id' => $payment->id,
            'payment_no' => $payment->payment_no,
            'status' => $payment->status,
            'payment_status' => $payment->payment_status,
            'payable_amount' => $payment->payable_amount,
            'split_legs' => $payment->splitPayments->count(),
        ], 201);
    }

    /**
     * Fields `update()` writes, grouped by how they are cast on the way in.
     *
     * Money is normalised to fixed-scale strings, booleans are cast, everything else
     * is assigned. `payment_no` IS DELIBERATELY ABSENT from all three — §7.6 and D3:
     * a number, once allocated off `auto_numbers`, belongs to that record forever, and
     * the safest way to guarantee that is for no edit path to name the column.
     *
     * @var list<string>
     */
    private const MONEY_FIELDS = [
        'amount', 'gst_amount', 'tds_amount', 'pt_amount', 'esic_amount', 'pf_amount',
        'payable_amount', 'total_amount', 'original_amount',
    ];

    /** @var list<string> */
    private const PLAIN_FIELDS = [
        'coa_account_id', 'vendor_id', 'bank_coa_account_id', 'location_id',
        'item_category_id', 'master_category_id', 'tds_rate_id', 'tax_id',
        'status', 'payment_status', 'payment_mode', 'gst_type',
        'payment_date', 'due_date', 'requested_date',
        'particulars', 'remarks', 'management_remarks', 'payment_reference_number',
        'haewaya_id', 'payment_by', 'expense_by', 'ca_email', 'payment_source',
        'haewaya_utr_number', 'billing_year',
    ];

    /** @var list<string> */
    private const BOOLEAN_FIELDS = [
        'gst_needed', 'split_equally', 'multiple_villa', 'verified', 'accounts_bills',
    ];

    /**
     * `Update Payment` — `Accounts.ds`, All Payments, and it is ALWAYS ENABLED.
     *
     * THIS ROUTE DID NOT EXIST, and its absence was deliberate and wrong. Husain:
     * "Right now, on edit nothing is working." `PaymentsModule` carried an
     * `editDisabledReason` reading "A payment is not editable. §7.6 makes its number,
     * amounts and split legs immutable once issued." That was a MISREADING of §7.6,
     * which forbids DELETING a settled payment and REISSUING a number — neither of
     * which is editing. The DS is unambiguous: the `Update Payment` custom action on
     * All Payments carries no `condition` at all, so Creator lets any payment be
     * opened and saved. The block is withdrawn, and what §7.6 actually protects is
     * enforced field by field below instead of by refusing the whole screen.
     *
     * So this is deliberately NOT a mirror of `storeDirect`. Four things differ:
     *
     *  1. `payment_no` is never in the request and never assigned, and
     *     `PaymentNumber::allocate()` is not called on this path at all.
     *  2. `PaymentSaveRules` runs with `is_new = false`, so "Paid Status can't be
     *     created" (`Accounts.ds:32097`) does not fire. It is a CREATE rule; a payment
     *     that reached `Paid` legitimately must still be saveable.
     *  3. The COA-driven field lock from `Accounts.ds:24240` is enforced, through the
     *     same `PaymentFieldState` the form renders from.
     *  4. A reversing entry, and a payment already reversed, are refused — see below.
     */
    public function update(Request $request, Payment $payment): JsonResponse
    {
        /*
         * D4's pair is immutable, and this is a DEVIATION: Creator would allow it.
         *
         * Creator has no reversal model — it hard-deletes, at 14 unguarded sites — so
         * it has nothing to protect here and leaves `Update Payment` enabled on
         * everything. Ours nets a villa x category x cycle to zero across two records,
         * and that netting is the single property the whole reversal model exists to
         * guarantee. Edit either half and it silently stops holding.
         */
        if ($payment->isReversal() || $payment->reversal !== null) {
            return response()->json([
                'message' => $payment->isReversal()
                    ? 'This is a reversing entry. It records what was reversed and cannot itself be edited.'
                    : 'This payment has been reversed. Editing it would leave the reversal pair no longer netting to zero.',
                'reason' => 'reversal_pair_immutable',
            ], 422);
        }

        $data = $request->validate($this->fieldRules());
        $legs = $data['legs'] ?? null;

        /*
         * THE FIELD LOCK, from `Accounts.ds:24240`.
         *
         * Evaluated against the COA the payment will HAVE after this save, not the one
         * it has now — Creator re-runs that branch `on user input of COA`, so switching
         * a payment onto Accounts Payable hides Amount and reveals Payable Amount in
         * the same interaction. Reading the stored COA instead would let the first save
         * after a COA change write a field the form had already hidden.
         */
        $coaName = array_key_exists('coa_account_id', $data)
            ? CoaAccount::find($data['coa_account_id'])?->account_name
            : $payment->coaAccount?->account_name;

        $status = $data['status'] ?? $payment->status;
        $states = PaymentFieldState::for($coaName, $status);
        $violations = [];

        foreach (PaymentFieldState::readOnly($coaName, $status) as $field) {
            if (! array_key_exists($field, $data)) {
                continue;
            }

            /*
             * Sending a field back UNCHANGED is not an edit. A form that renders a
             * disabled field still posts it, and refusing that would make the lock
             * impossible to satisfy from the very screen Creator draws.
             */
            if ($this->sameValue($payment->getAttribute($field), $data[$field])) {
                continue;
            }

            $violations[] = [
                'field' => $field,
                'state' => $states[$field],
                'message' => sprintf(
                    'With COA "%s" and status "%s", Creator renders %s as %s (Accounts.ds:24240).',
                    $coaName ?? 'unset',
                    $status ?? 'unset',
                    $field,
                    $states[$field],
                ),
            ];
        }

        if ($violations !== []) {
            return response()->json([
                'message' => $violations[0]['message'],
                'reason' => 'field_locked',
                'violations' => $violations,
                'field_states' => $states,
            ], 422);
        }

        /*
         * Creator's 22 save rules, with `is_new = false`.
         *
         * Merged over the STORED record rather than run on the request alone: a PATCH
         * that changes only the remarks must still be judged against the payment's
         * vendor and COA, or every partial edit would fail "Please Select vendot to
         * proceed" for a vendor that is present in the database and merely absent from
         * this request.
         */
        $merged = $this->currentState($payment);

        foreach ($data as $key => $value) {
            $merged[$key] = $value;
        }

        $merged['is_new'] = false;

        // `null` legs means "this request did not touch the grid", which is a different
        // thing from "the grid is now empty" — so judge the rules against the stored legs.
        $merged['legs'] = $legs ?? $payment->splitPayments->map(fn ($leg): array => [
            'villa_id' => $leg->villa_id,
            'item_category_id' => $leg->item_category_id,
            'billing_cycle_id' => $leg->billing_cycle_id,
            'amount' => $leg->amount,
        ])->all();

        $broken = (new PaymentSaveRules)->check($merged);

        if ($broken !== []) {
            return response()->json([
                'message' => $broken[0]['message'],
                'reason' => 'creator_validation',
                'broken_rules' => $broken,
                'disable_flag_known' => PaymentSaveRules::disabledCategoriesKnown(),
            ], 422);
        }

        // §6.4 rule 1 again, on the POST-SAVE figures: the legs tie to the gross whether
        // this request changed the legs, the gross, or only one of the two.
        $effectiveLegs = $merged['legs'];
        $gross = $merged['amount'] ?? null;

        if ($effectiveLegs !== [] && $gross !== null) {
            $sum = '0';

            foreach ($effectiveLegs as $leg) {
                $sum = Money::add($sum, (string) $leg['amount']);
            }

            if (! Money::equalsAtRupees($sum, Money::normalise((string) $gross))) {
                return response()->json([
                    'message' => sprintf(
                        'The split legs sum to %s but the gross is %s. §6.4 rule 1 ties the legs to '
                        .'the gross, so this would misstate every downstream villa-month-category figure.',
                        $sum, Money::normalise((string) $gross),
                    ),
                    'reason' => 'unbalanced_split',
                ], 422);
            }
        }

        DB::transaction(function () use ($payment, $data, $legs): void {
            foreach (self::MONEY_FIELDS as $field) {
                if (array_key_exists($field, $data)) {
                    $payment->{$field} = $data[$field] === null
                        ? null
                        : Money::normalise((string) $data[$field]);
                }
            }

            foreach (self::PLAIN_FIELDS as $field) {
                if (array_key_exists($field, $data)) {
                    $payment->{$field} = $data[$field];
                }
            }

            foreach (self::BOOLEAN_FIELDS as $field) {
                if (array_key_exists($field, $data)) {
                    $payment->{$field} = (bool) $data[$field];
                }
            }

            if (array_key_exists('billing_months', $data)) {
                // Comma-packed as Creator stores it, per storeDirect. Splitting it later
                // is a parse, not a split(',').
                $payment->billing_months = $data['billing_months'] === null
                    ? null
                    : implode(',', $data['billing_months']);
            }

            $payment->save();

            if ($legs === null) {
                return;
            }

            /*
             * RECONCILE the legs; never clear and rebuild.
             *
             * §5.1/§15.1 is explicit about this and `SplitAllocator` already does it on
             * the bill side. A delete-then-insert would mint new ids for rows that did
             * not change, and every downstream reference to a split leg — the
             * `Backend_*` allocation snapshot especially — would point at a row that no
             * longer exists.
             */
            $existing = $payment->splitPayments()->orderBy('position')->get();

            foreach (array_values($legs) as $position => $leg) {
                $attributes = [
                    'villa_id' => $leg['villa_id'],
                    'item_category_id' => $leg['item_category_id'],
                    'billing_cycle_id' => $leg['billing_cycle_id'],
                    'amount' => Money::normalise((string) $leg['amount']),
                    'position' => $position,
                ];

                $row = $existing->get($position);

                if ($row === null) {
                    $payment->splitPayments()->create($attributes);
                } else {
                    $row->fill($attributes)->save();
                }
            }

            foreach ($existing->slice(count($legs)) as $surplus) {
                $surplus->delete();
            }
        });

        return $this->show($payment->fresh());
    }

    /**
     * The stored record in request shape, so a partial edit can be judged whole.
     *
     * @return array<string, mixed>
     */
    private function currentState(Payment $payment): array
    {
        $state = [];

        foreach ([...self::MONEY_FIELDS, ...self::PLAIN_FIELDS, ...self::BOOLEAN_FIELDS] as $field) {
            $state[$field] = $payment->getAttribute($field);
        }

        $state['billing_months'] = $payment->billing_months === null
            ? null
            : explode(',', $payment->billing_months);

        /*
         * BILLING CYCLES AND ITEM CATEGORIES LIVE ON THE LEGS, not on the header.
         *
         * There is no `billing_cycle_ids` column and no pivot: `storeDirect` only ever
         * writes a cycle through `legs.*.billing_cycle_id`, which is right — §5.2 makes
         * each leg the ledger entry and the cycle is a property of the entry, not of
         * the payment. So a stored payment's cycles have to be READ BACK from its legs.
         *
         * Getting this wrong is not subtle and it is how the first run of these tests
         * failed: `PaymentSaveRules` refused every partial edit with "Please add
         * Billing Cycle" for a payment whose legs each named one.
         */
        $state['billing_cycle_ids'] = $payment->splitPayments
            ->pluck('billing_cycle_id')->filter()->unique()->values()->all();

        $state['item_category_ids'] = $payment->splitPayments
            ->pluck('item_category_id')
            ->push($payment->item_category_id)
            ->filter()->unique()->values()->all();

        return $state;
    }

    /**
     * Is a posted value the same as the stored one?
     *
     * Loose on purpose, and used only to let an UNCHANGED locked field through. A date
     * arrives as `2026-08-29` and is stored as a Carbon; an id arrives as the string
     * `"7"` from a form and is stored as int 7. A strict comparison would call both of
     * those an edit and make the lock unsatisfiable from Creator's own screen.
     *
     * MONEY IS COMPARED AT SCALE and never as floats, which is the whole reason no
     * float touches a rupee figure anywhere in this app.
     */
    private function sameValue(mixed $stored, mixed $posted): bool
    {
        if ($stored === null || $posted === null) {
            return $stored === $posted;
        }

        if ($stored instanceof \DateTimeInterface) {
            return $stored->format('Y-m-d') === (string) $posted;
        }

        if (is_bool($stored)) {
            return $stored === (bool) $posted;
        }

        if (is_numeric($stored) && is_numeric($posted)) {
            return Money::equals((string) $stored, (string) $posted);
        }

        return (string) $stored === (string) $posted;
    }

    /**
     * Reverse a settled payment — what replaces `Delete Paid Payment` (§7.6).
     *
     * There is deliberately NO destroy() method on this controller. A settled
     * payment cannot be deleted through the API at all; Payment::delete() refuses
     * it at the model too, so a future careless caller cannot reintroduce the
     * action that destroyed 17 real payments.
     */
    public function reverse(Request $request, Payment $payment, ReversePayment $reverse): JsonResponse
    {
        $validated = $request->validate([
            // §7.6 wants a reason that explains the movement, so a one-character
            // placeholder is not enough.
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ]);

        try {
            $reversal = $reverse(
                $payment,
                $validated['reason'],
                $request->string('added_user')->toString() ?: null,
            );
        } catch (ReversalRefusedException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'reason' => 'reversal_refused',
            ], 422);
        }

        return response()->json([
            'reversal_id' => $reversal->id,
            'reversal_payment_no' => $reversal->payment_no,
            'reverses_payment_no' => $payment->payment_no,
            'payable_amount' => $reversal->payable_amount,
        ], 201);
    }

    /**
     * Pickers for the DIRECT payment form.
     *
     * EVERY FILTER HERE IS THE DS's, not a guess. They come from the `Payment` form
     * in `deluge/Accounts.ds` (lines 7273-8673), and two of them corrected code this
     * app had already shipped:
     *
     *   COA           -> COA[Hide == true]            47 of 144. `Hide` does NOT mean
     *                                                 hidden on this form; the 47 are
     *                                                 the real entity/bank accounts.
     *                                                 Answers addendum §17.5's open
     *                                                 COA `hide` question.
     *   Bank Name     -> COA[Bank == true]            the load-bearing flag, not
     *                                                 Account_Type — 9 rows are
     *                                                 Bank = true without being typed
     *                                                 `bank`.
     *   Vendor Name   -> Vendor_Master[Main_Primary is not null]
     *                                                 6,957 — trade vendors, not the
     *                                                 1,107 customer payees.
     *   Villa Name    -> admin.Villa[Hide_From_Payments == false]     §6.2
     *   Item Category -> Item_Category[Disable == false]              §6.2
     *
     * Vendors are NOT listed here. 6,957 options is the same mistake Bills made at
     * 8,063 — the form searches /api/vendors/lookup instead.
     */
    public function options(): JsonResponse
    {
        return response()->json([
            'vendor_count' => Vendor::query()->whereNotNull('main_primary')
                ->where('name', '<>', '')->count(),

            'coa_accounts' => CoaAccount::query()->where('hide', true)->orderBy('account_name')
                ->get(['id', 'account_name'])
                ->map(fn ($c): array => ['value' => (string) $c->id, 'label' => $c->account_name])->all(),

            'bank_accounts' => CoaAccount::query()->where('bank', true)->orderBy('account_name')
                ->get(['id', 'account_name'])
                ->map(fn ($c): array => ['value' => (string) $c->id, 'label' => $c->account_name])->all(),

            'villas' => Villa::query()->selectableForPayments()->orderBy('name')->get(['id', 'name'])
                ->map(fn ($v): array => ['value' => (string) $v->id, 'label' => $v->name])->all(),

            'item_categories' => ItemCategory::query()->where('disable', false)->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn ($c): array => ['value' => (string) $c->id, 'label' => $c->name])->all(),

            'master_categories' => MasterCategory::query()->orderBy('name')->get(['id', 'name'])
                ->map(fn ($c): array => ['value' => (string) $c->id, 'label' => $c->name])->all(),

            'locations' => Location::query()->orderBy('name')->get(['id', 'name'])
                ->map(fn ($l): array => ['value' => (string) $l->id, 'label' => $l->name])->all(),

            'tds_rates' => TdsRate::query()->orderBy('name')->get(['id', 'name', 'tds_percentage'])
                ->map(fn ($t): array => [
                    'value' => (string) $t->id,
                    'label' => $t->name.' — '.$t->tds_percentage.'%',
                ])->all(),

            'taxes' => Tax::query()->orderBy('name')->get(['id', 'name', 'tax_percentage'])
                ->map(fn ($t): array => [
                    'value' => (string) $t->id,
                    'label' => $t->name.($t->tax_percentage !== null ? ' — '.$t->tax_percentage.'%' : ''),
                ])->all(),

            // §6.4: a cycle is NEVER created on the fly. If the list is empty the
            // form says so rather than deriving one from a month name.
            'billing_cycles' => BillingCycle::query()
                ->orderByDesc('year')->orderByDesc('month_index')->get()
                ->map(fn ($c): array => ['value' => (string) $c->id, 'label' => $c->label()])->all(),

            // Picklist values, verbatim from the DS — including the three spellings
            // of one concept and the lowercase `paid`, which are IN THE PICKLIST and
            // not merely dirty data.
            // All 8, exactly as the DS picklist declares them — including
            // `Submit for Approval`, `Sent for Approval` AND `Send for Approval`.
            // Three spellings of one concept, and they are in the SCHEMA, not just
            // in the data. Offering all three is replication, not sloppiness.
            'statuses' => PaymentStatus::statuses(),

            /*
             * `Open` IS EXCLUDED HERE and only here. It is live on 7,583 imported
             * payments but is NOT in the DS picklist — addendum §10 calls it
             * undeclared. Reading it must keep working; offering it on a form would
             * be inventing a choice Creator does not present.
             */
            'payment_statuses' => array_values(array_filter(
                PaymentStatus::paymentStatuses(),
                fn (string $s): bool => $s !== PaymentStatus::PS_OPEN,
            )),
            'payment_modes' => ['Online', 'Offline'],
            // `Enter Manully` is Creator's misspelling. Preserved (handoff §2 rule 7).
            'gst_types' => ['Predefined GST', 'Enter Manully'],
            'billing_months' => [
                'January', 'February', 'March', 'April', 'May', 'June',
                'July', 'August', 'September', 'October', 'November', 'December',
            ],

            /*
             * THE COA-DRIVEN FORM SHAPE, published rather than re-implemented.
             *
             * `Accounts.ds:24240` switches the Payment form between two genuinely
             * different screens on the COA, and the browser has to know which one to
             * draw the moment the user picks a COA — before any save.
             *
             * The obvious way to do that is to write the `if` again in JavaScript, and
             * that is exactly the drift `PaymentFieldState` exists to prevent: a form
             * that computes the rule independently is a form that can disagree with the
             * guard which will reject it, and the user finds out by having a save
             * refused for a field the screen let them type into.
             *
             * So the three reachable states are enumerated HERE, from the one authority,
             * and the browser only has to LOOK ONE UP. `accounts_payable_coa_ids` is
             * what it keys on, because the form holds a COA id and the rule is written
             * against an account name.
             */
            'field_states' => [
                'accounts_payable' => PaymentFieldState::for(
                    PaymentFieldState::ACCOUNTS_PAYABLE, PaymentStatus::DRAFT,
                ),
                'accounts_payable_paid' => PaymentFieldState::for(
                    PaymentFieldState::ACCOUNTS_PAYABLE, PaymentStatus::PAID,
                ),
                'other' => PaymentFieldState::for('Expense', PaymentStatus::DRAFT),
            ],
            'accounts_payable_coa_ids' => CoaAccount::all()
                ->filter(fn (CoaAccount $c): bool => PaymentFieldState::isAccountsPayable($c->account_name))
                ->pluck('id')->map(fn ($id): string => (string) $id)->values()->all(),

            // Declared so the form can SAY the two fields are missing rather than
            // simply not showing them — see PaymentFieldState::COLUMN_FOR.
            'unbuilt_fields' => PaymentFieldState::missingColumns(),
        ]);
    }

    /**
     * Recompute the form's derived fields — Creator's `on user input` handlers.
     *
     * WHY THIS IS A ROUND TRIP RATHER THAN JAVASCRIPT. Every other money rule in this
     * app is server-side for one reason: there must be exactly one implementation of
     * the arithmetic. `PaymentFormCalculator` shares `Money::percentageOf()` with the
     * bill split, so a rate applied on the form and the same rate applied on a saved
     * bill cannot drift apart. A JS copy would be a second place for the rounding to
     * disagree, and §6.3 already warns that per-row TDS does not sum to header TDS —
     * a discrepancy that is correct is impossible to tell from one that is a bug if
     * two implementations exist.
     *
     * It answers with the whole derived set INCLUDING the rewritten split legs,
     * because that is what the DS handler does: picking a TDS rate rewrites every
     * leg's TDS, GST and Total.
     */
    public function recalculate(Request $request, PaymentFormCalculator $calculate): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['nullable', 'numeric'],
            'gst_amount' => ['nullable', 'numeric'],
            'pf' => ['nullable', 'numeric'],
            'pt' => ['nullable', 'numeric'],
            'esic' => ['nullable', 'numeric'],
            'tds_rate_id' => ['nullable', 'integer', 'exists:tds_rates,id'],
            'tax_id' => ['nullable', 'integer', 'exists:taxes,id'],
            'item_category_id' => ['nullable', 'integer', 'exists:item_categories,id'],
            'legs' => ['nullable', 'array'],
            'legs.*.amount' => ['nullable', 'numeric'],
            // Opt in to the INTENDED salary formula instead of the live one. Off by
            // default: the 52,638 imported payments were produced by the live path.
            'apply_salary_deductions' => ['nullable', 'boolean'],
        ]);

        // The rate, not the id — the calculator works in percentages.
        $tds = isset($data['tds_rate_id']) ? TdsRate::find($data['tds_rate_id']) : null;
        $tax = isset($data['tax_id']) ? Tax::find($data['tax_id']) : null;
        $category = isset($data['item_category_id']) ? ItemCategory::find($data['item_category_id']) : null;

        $result = $calculate(
            [
                'amount' => $data['amount'] ?? '0',
                'gst_amount' => $data['gst_amount'] ?? '0',
                'pf' => $data['pf'] ?? '0',
                'pt' => $data['pt'] ?? '0',
                'esic' => $data['esic'] ?? '0',
                'tds_percentage' => $tds?->tds_percentage ?? '0',
                'gst_percentage' => $tax?->tax_percentage,
                // Name, not id: the dead salary branch compares on the NAME, and the
                // name is a live lookup key that must not be trimmed.
                'item_category' => $category?->name,
            ],
            array_values($data['legs'] ?? []),
            (bool) ($data['apply_salary_deductions'] ?? false),
        );

        return response()->json($result);
    }

    /**
     * The Payment form's field rules — the request SHAPE, shared by create and edit.
     *
     * These were inline in `storeDirect`. `update()` needs exactly the same set, and
     * a second copy is how a create path and an edit path start accepting different
     * things: the same argument that put column order in one `ReportRegistry` read by
     * both the report controller and the write controller.
     *
     * SHAPE ONLY. Every field here is `nullable` because Creator's business rules are
     * not shape rules — they live in `PaymentSaveRules`, which both callers run next.
     *
     * @return array<string, mixed>
     */
    private function fieldRules(): array
    {
        return [
            'coa_account_id' => ['nullable', 'integer', 'exists:coa_accounts,id'],
            'vendor_id' => ['nullable', 'integer', 'exists:vendors,id'],
            'bank_coa_account_id' => ['nullable', 'integer', 'exists:coa_accounts,id'],
            'location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'item_category_id' => ['nullable', 'integer', 'exists:item_categories,id'],
            'master_category_id' => ['nullable', 'integer', 'exists:master_categories,id'],
            'tds_rate_id' => ['nullable', 'integer', 'exists:tds_rates,id'],
            'tax_id' => ['nullable', 'integer', 'exists:taxes,id'],

            /*
             * ENUMS ARE VALIDATED AGAINST THE PICKLIST, not merely length-checked.
             *
             * These were `['nullable','string','max:100']`, which accepts anything.
             * §8 rule 11 of the field notes is explicit — "validate enums; never
             * auto-create master data from a malformed value" — and §5.1 records the
             * cost of not doing it: an API sent the month as `9` instead of
             * `September`, Creator stored it literally and CONJURED a billing cycle
             * called "9-2026" in live accounting.
             *
             * The lists come from the DS picklists verbatim, including the three
             * spellings of one approval concept and the lowercase `paid`. Rule::in
             * on dirty values is still validation: the point is that only the values
             * Creator itself offers get through, not that they are tidy.
             *
             * `Open` is accepted on READ but is NOT in this list — it is live on
             * 7,583 imported payments and absent from the picklist (addendum §10), so
             * a form must not mint a new one.
             */
            'status' => ['nullable', Rule::in(PaymentStatus::statuses())],
            'payment_status' => ['nullable', Rule::in(array_values(array_filter(
                PaymentStatus::paymentStatuses(),
                fn (string $v): bool => $v !== PaymentStatus::PS_OPEN,
            )))],
            'payment_mode' => ['nullable', Rule::in(['Online', 'Offline'])],
            // `Enter Manully` is Creator's spelling. Validating against the correct
            // spelling would reject every real record.
            'gst_type' => ['nullable', Rule::in(['Predefined GST', 'Enter Manully'])],

            'amount' => ['nullable', 'numeric'],
            'gst_amount' => ['nullable', 'numeric'],
            'tds_amount' => ['nullable', 'numeric'],
            'pt_amount' => ['nullable', 'numeric'],
            'esic_amount' => ['nullable', 'numeric'],
            'pf_amount' => ['nullable', 'numeric'],
            'payable_amount' => ['nullable', 'numeric'],
            'total_amount' => ['nullable', 'numeric'],
            'original_amount' => ['nullable', 'numeric'],

            'payment_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date'],
            'requested_date' => ['nullable', 'date'],

            'particulars' => ['nullable', 'string'],
            'remarks' => ['nullable', 'string'],
            'management_remarks' => ['nullable', 'string'],
            'payment_reference_number' => ['nullable', 'string', 'max:255'],
            'haewaya_id' => ['nullable', 'string', 'max:255'],
            'payment_by' => ['nullable', 'string', 'max:255'],
            'expense_by' => ['nullable', 'string', 'max:255'],
            'ca_email' => ['nullable', 'email', 'max:80'],
            'payment_source' => ['nullable', 'string', 'max:255'],
            'haewaya_utr_number' => ['nullable', 'string', 'max:255'],

            // DS: number, maxchar 4. Bounded rather than left open — an
            // unbounded year is how a cycle ends up filed under 20260.
            'billing_year' => ['nullable', 'integer', 'digits:4'],
            'billing_months' => ['nullable', 'array'],
            'billing_cycle_ids' => ['nullable', 'array'],
            'billing_cycle_ids.*' => ['integer', 'exists:billing_cycles,id'],

            'gst_needed' => ['nullable', 'boolean'],
            'split_equally' => ['nullable', 'boolean'],
            'multiple_villa' => ['nullable', 'boolean'],
            'verified' => ['nullable', 'boolean'],
            'accounts_bills' => ['nullable', 'boolean'],

            // Legs, if the user allocated any.
            'legs' => ['nullable', 'array'],
            'legs.*.villa_id' => ['required_with:legs', 'integer', 'exists:villas,id'],
            'legs.*.item_category_id' => ['required_with:legs', 'integer', 'exists:item_categories,id'],
            'legs.*.billing_cycle_id' => ['required_with:legs', 'integer', 'exists:billing_cycles,id'],
            'legs.*.amount' => ['required_with:legs', 'numeric'],
        ];
    }

    /**
     * Create a payment DIRECTLY — not from a bill.
     *
     * WHY THIS EXISTS. §7.2's `Create_Payment` was the only creation path the three
     * context docs describe, so this app was built as though a payment could only be
     * made from a bill, and Payments' `+` sent the user to Bills. Husain corrected
     * that on 25-Aug-2026: **a payment can be entered directly.** None of the `.md`
     * files record it; the Payment form in Accounts.ds is the evidence that it is a
     * first-class form with its own 130 fields.
     *
     * WHAT IS ENFORCED, and why each one:
     *
     *  - **The number comes from the counter under a row lock** (`PaymentNumber`),
     *    never from the request. The counters were 363 and 1,283 behind live until
     *    `zoho:reconcile-counters` ran; a client-supplied number would reissue.
     *  - **Split legs must balance the gross** if any are supplied — §6.4 rule 1 and
     *    §7.4's missing check (D2). A direct payment with no legs is allowed, because
     *    the form allows it; one with legs that do not tie is refused.
     *  - **No billing cycle is created.** §6.4: deriving a cycle from a month name is
     *    the defect that put a junk `"9-2026"` row into live accounting. Cycles must
     *    already exist and are validated by id.
     *  - **One transaction.** Creator inserts the payment then its subforms with no
     *    transaction anywhere; a part-written payment silently misstates the ledger
     *    (§5.2).
     */
    public function storeDirect(Request $request): JsonResponse
    {
        $data = $request->validate($this->fieldRules());

        $legs = $data['legs'] ?? [];

        /*
         * CREATOR'S SAVE-TIME RULES, all 22 of them.
         *
         * Every field above is `nullable`, which is right for the request SHAPE and
         * wrong for the business rules: it let a payment be created with no vendor, no
         * COA, no billing cycle and no particulars, none of which Creator permits. The
         * rules live in three `on validate` handlers (`Accounts.ds:28524`, `:30970`,
         * `:32089`) and are transcribed in `PaymentSaveRules`.
         *
         * Returned ALL AT ONCE rather than one per round trip (D13) — Creator alerts on
         * the first failure because a form can only show one alert; an API has no such
         * excuse. `first_message` carries what Creator itself would have said, so a
         * reviewer comparing behaviour has it.
         */
        $rules = new PaymentSaveRules;
        $broken = $rules->check($data + ['is_new' => true]);

        if ($broken !== []) {
            return response()->json([
                'message' => $broken[0]['message'],
                'reason' => 'creator_validation',
                'broken_rules' => $broken,
                /*
                 * Said out loud when it matters: the Disallow Manual Creation rule
                 * cannot fire on our data, because `disable` is set on 0 of 135 item
                 * categories while the addendum records PETTY and INTERNAL TRANSFER as
                 * disabled live. A caller must be able to tell "nothing is disallowed"
                 * from "we do not know what is disallowed".
                 */
                'disable_flag_known' => PaymentSaveRules::disabledCategoriesKnown(),
            ], 422);
        }

        // §6.4 rule 1 / §7.4's missing check, kept as the arithmetic gate behind the
        // rules above: this one reports the actual figures, which a message cannot.
        if ($legs !== [] && isset($data['amount'])) {
            $sum = '0';
            foreach ($legs as $leg) {
                $sum = Money::add($sum, (string) $leg['amount']);
            }

            $gross = Money::normalise((string) $data['amount']);

            if (round((float) $sum) !== round((float) $gross)) {
                return response()->json([
                    'message' => sprintf(
                        'The split legs sum to %s but the gross is %s. §6.4 rule 1 ties the legs to '
                        .'the gross, so this would misstate every downstream villa-month-category figure.',
                        $sum, $gross,
                    ),
                    'reason' => 'unbalanced_split',
                ], 422);
            }
        }

        $payment = DB::transaction(function () use ($data, $legs): Payment {
            $payment = Payment::create([
                'payment_no' => PaymentNumber::allocate(),

                // Defaults are the FIRST value of each DS picklist, not invented:
                // Status starts at `Draft`, Payment Status at `Pending`.
                'status' => $data['status'] ?? 'Draft',
                'payment_status' => $data['payment_status'] ?? 'Pending',

                'coa_account_id' => $data['coa_account_id'] ?? null,
                'vendor_id' => $data['vendor_id'] ?? null,
                'bank_coa_account_id' => $data['bank_coa_account_id'] ?? null,
                'location_id' => $data['location_id'] ?? null,
                'item_category_id' => $data['item_category_id'] ?? null,
                'master_category_id' => $data['master_category_id'] ?? null,
                'tds_rate_id' => $data['tds_rate_id'] ?? null,
                'tax_id' => $data['tax_id'] ?? null,

                'amount' => isset($data['amount']) ? Money::normalise((string) $data['amount']) : null,
                'gst_amount' => isset($data['gst_amount']) ? Money::normalise((string) $data['gst_amount']) : null,
                'tds_amount' => isset($data['tds_amount']) ? Money::normalise((string) $data['tds_amount']) : null,
                'pt_amount' => isset($data['pt_amount']) ? Money::normalise((string) $data['pt_amount']) : null,
                'esic_amount' => isset($data['esic_amount']) ? Money::normalise((string) $data['esic_amount']) : null,
                'pf_amount' => isset($data['pf_amount']) ? Money::normalise((string) $data['pf_amount']) : null,
                'payable_amount' => isset($data['payable_amount']) ? Money::normalise((string) $data['payable_amount']) : null,
                'total_amount' => isset($data['total_amount']) ? Money::normalise((string) $data['total_amount']) : null,
                'original_amount' => isset($data['original_amount']) ? Money::normalise((string) $data['original_amount']) : null,

                'payment_date' => $data['payment_date'] ?? null,
                'due_date' => $data['due_date'] ?? null,
                'requested_date' => $data['requested_date'] ?? now()->toDateString(),

                'payment_mode' => $data['payment_mode'] ?? null,
                'gst_type' => $data['gst_type'] ?? null,
                'particulars' => $data['particulars'] ?? null,
                'remarks' => $data['remarks'] ?? null,
                'management_remarks' => $data['management_remarks'] ?? null,
                'payment_reference_number' => $data['payment_reference_number'] ?? null,
                'payment_by' => $data['payment_by'] ?? null,
                'expense_by' => $data['expense_by'] ?? null,
                'ca_email' => $data['ca_email'] ?? null,
                'payment_source' => $data['payment_source'] ?? null,
                'haewaya_utr_number' => $data['haewaya_utr_number'] ?? null,

                'billing_year' => $data['billing_year'] ?? null,
                // Multi-select, stored comma-packed as Creator does. Splitting it
                // later is a parse, not a split(',').
                'billing_months' => isset($data['billing_months'])
                    ? implode(',', $data['billing_months'])
                    : null,

                'gst_needed' => (bool) ($data['gst_needed'] ?? false),
                'split_equally' => (bool) ($data['split_equally'] ?? false),
                'multiple_villa' => (bool) ($data['multiple_villa'] ?? false),
                'verified' => (bool) ($data['verified'] ?? false),
                'accounts_bills' => (bool) ($data['accounts_bills'] ?? false),
            ]);

            foreach ($legs as $position => $leg) {
                $payment->splitPayments()->create([
                    'villa_id' => $leg['villa_id'],
                    'item_category_id' => $leg['item_category_id'],
                    'billing_cycle_id' => $leg['billing_cycle_id'],
                    'amount' => Money::normalise((string) $leg['amount']),
                    'position' => $position,
                ]);
            }

            return $payment;
        });

        return response()->json([
            'payment_id' => $payment->id,
            'payment_no' => $payment->payment_no,
            'status' => $payment->status,
            'payment_status' => $payment->payment_status,
            'split_legs' => count($legs),
        ], 201);
    }

    /**
     * Filters off the request.
     *
     * Accepted as JSON in a single `filters` parameter rather than as bracketed
     * array params, because a chip list is one value conceptually and PHP's nested
     * query parsing is its own source of surprises.
     *
     * @return list<array{column?: string, operator?: string, value?: string}>
     */
    private function requestedFilters(Request $request): array
    {
        $raw = $request->query('filters');

        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_array')) : [];
    }

    /** One list row. Nulls render as '' — Creator shows blanks, not "null". */
    private function row(Payment $payment): array
    {
        return [
            'id' => $payment->id,
            'Payment No' => $payment->payment_no ?? '',
            'Vendor Name' => $payment->vendor?->name ?? '',
            'Payment Date' => $payment->payment_date?->toDateString() ?? '',
            'Due Date' => $payment->due_date?->toDateString() ?? '',
            'Amount' => $payment->amount ?? '',
            'TDS Amount' => $payment->tds_amount ?? '',
            'GST Amount' => $payment->gst_amount ?? '',
            'Payable Amount' => $payment->payable_amount ?? '',
            'Status' => PaymentStatus::normaliseStatus($payment->status) ?? '',
            // Displayed capitalised; the STORED value keeps Creator's lowercase
            // "paid" per CLAUDE.md — normalise at display, never in data.
            'Payment Status' => PaymentStatus::label($payment->payment_status),
            'Location' => $payment->location?->name ?? '',
            // 18-digit Creator id, string end to end (§15.2).
            'ID' => $payment->creator_id ?? '',
        ];
    }
}
