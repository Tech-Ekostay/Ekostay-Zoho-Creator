<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Approvals\DecideApproval;
use App\Domain\Approvals\MarkPaymentPaid;
use App\Domain\Payments\PaymentStatus;
use App\Domain\Reports\ReportFilter;
use App\Http\Controllers\Controller;
use App\Models\PendingApproval;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * All Pending Approvals — the queue, and the first screen in this app that MOVES money
 * rather than displaying it.
 *
 * COLUMN ORDER IS VERIFIED from seven screenshots (27-Aug-2026): index, detail and
 * edit. 24 columns, and the three that matter are not at the left edge —
 * `Approve`, `Reject` and `Pay` sit mid-table, between `Payment Date` and
 * `Payable Amount`. Reproduced there rather than moved somewhere more convenient,
 * per the standing instruction not to redesign.
 *
 * ---------------------------------------------------------------------------
 * MOST OF THIS REPORT IS THE PAYMENT, NOT THE APPROVAL. Only nine of the 24 columns
 * come off `pending_approvals`; twelve are read through the payment and three are
 * action buttons. That is why `index()` eager-loads the payment and its lookups —
 * without it this is 1,000 rows x 8 lookups.
 *
 * THREE THINGS THE SCREENSHOTS SETTLED:
 *
 *  1. **`Gross Amount` prints at THREE decimals** (₹58,614.140), unlike every other
 *     money column in the app. Addendum §5 flagged it and the screenshots confirm it.
 *     `decimals` on the column spec carries that to the UI rather than hardcoding a
 *     format there.
 *  2. **`Pay` renders solid only on `Approved` rows** and pale on `Sent for Approval`.
 *     So enablement is per-row and derived from status, not a global permission.
 *  3. **`Approved By` is a SUBFORM** and the report flattens it to one name — Creator
 *     doing the §12 flattening in its own UI. The row carries the flattened string for
 *     fidelity AND the full grid under `approved_by_rows`, so the detail view can show
 *     the truth without the report lying.
 *
 * NO AUTHORISATION. §3.3's matrix is extracted and tested and is not wired to a gate,
 * so these endpoints are open. `DecideApproval` verifies the named approver is on the
 * record, which is not the same as verifying who is calling. **Stated on the response
 * itself (`unauthenticated: true`) so the UI cannot present this as a control.**
 */
class PendingApprovalController extends Controller
{
    use Concerns\FiltersReports;
    use Concerns\PagesReports;

    /**
     * Verbatim, in the order the live report displays them.
     *
     * `Next Level Approval Required?` keeps its question mark — it is the label.
     */
    private const COLUMNS = [
        'Added Time',
        'Payment Date',
        'Approve',                        // action
        'Reject',                         // action
        'Link',
        'Payment Status',
        'Pay',                            // action
        'Payable Amount',
        'Location',
        'Gross Amount',
        'Item Category',
        'Bank Name',
        'Vendor Name',
        'Villa Name',
        'Payment No',
        'Master Category',
        'Status',
        'COA',
        'Billing Cycles',
        'Approval Level',
        'Next Level Approval Required?',
        'Approval Type',
        'Approved By',
        'Message ID',
    ];

    /** Rendered as buttons, not values. */
    private const ACTIONS = ['Approve', 'Reject', 'Pay'];

    /**
     * `Payment Status` is a solid filled cell on the live report (addendum §5), and
     * `Gross Amount` is the app's only three-decimal money column.
     */
    private const COLUMN_HINTS = [
        'Payment Status' => ['filled' => true],
        'Gross Amount' => ['decimals' => 3],
        'Payable Amount' => ['decimals' => 2],
    ];

    private function filterable(): ReportFilter
    {
        return new ReportFilter([
            'Added Time' => ['column' => 'added_time', 'type' => 'date'],
            'Status' => ['column' => 'status', 'type' => 'text'],
            'Payment Status' => ['column' => 'payment_status', 'type' => 'text'],
            'Approval Level' => ['column' => 'approval_level', 'type' => 'text'],
            'Approval Type' => ['column' => 'approval_type', 'type' => 'text'],
            'Message ID' => ['column' => 'message_id', 'type' => 'text'],
            'Payment No' => ['column' => 'payment_no', 'type' => 'text', 'relation' => 'payment'],
            'Payable Amount' => ['column' => 'payable_amount', 'type' => 'money', 'relation' => 'payment'],
            'Gross Amount' => ['column' => 'amount', 'type' => 'money', 'relation' => 'payment'],
            'Next Level Approval Required?' => [
                'column' => 'next_level_approval_required', 'type' => 'boolean',
            ],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $query = PendingApproval::query()
            ->with([
                'approvers',
                'payment.vendor', 'payment.villa', 'payment.location',
                'payment.itemCategory', 'payment.masterCategory',
                'payment.coaAccount', 'payment.bankAccount',
                'payment.splitPayments.billingCycle',
            ])
            // The report leads with Added Time descending, as Backend Expenses and
            // Backbend Payments do (§4.1, §7.6).
            ->orderByDesc('added_time')
            ->orderByDesc('id');

        $filter = $this->filterable();
        $filters = $this->requestedFilters($request);

        if ($error = $this->applyFilters($filter, $query, $filters)) {
            return response()->json(['message' => $error, 'reason' => 'bad_filter'], 422);
        }

        $matched = (clone $query)->count();

        /*
         * ONE PAGE, plus whether another exists. `limit(1000)` was a hard CEILING:
         * row 1,001 was unreachable, so a filtered nil result was indistinguishable
         * from a truncated one — the exact confusion server-side filtering was added
         * to remove. The client now appends as it scrolls (Husain, 28-Aug-2026).
         */
        $offset = $this->requestedOffset($request);
        $page = $this->page($query, $offset);
        $rows = $page['rows'];

        return response()->json([
            'report' => 'pending-approvals',
            'title' => 'All Pending Approvals',
            'columns' => self::COLUMNS,
            'actions' => self::ACTIONS,
            'column_hints' => self::COLUMN_HINTS,
            ...$this->pagingEnvelope(
                $offset, $page['next_offset'], $matched, PendingApproval::query()->count(),
            ),
            'filter_schema' => $filter->schema(),
            'filters' => $filters,
            // Said out loud, because a queue with buttons looks like a control.
            'unauthenticated' => true,
            'rows' => $rows->map(fn (PendingApproval $p): array => $this->row($p))->all(),
        ]);
    }

    public function show(PendingApproval $pendingApproval): JsonResponse
    {
        $pendingApproval->load([
            'approvers', 'candidates', 'preferredApprover',
            'payment.vendor', 'payment.villa', 'payment.location',
            'payment.itemCategory', 'payment.masterCategory',
            'payment.coaAccount', 'payment.bankAccount',
            'payment.splitPayments.billingCycle',
        ]);

        /*
         * DETAIL ORDER FROM THE SCREENSHOTS, and it is NOT the report order — the
         * panel leads with Payment No and ends with the approver fields. §4.3's lesson
         * on a fourth form: record both, derive neither.
         */
        return response()->json([
            'row' => $this->row($pendingApproval),
            'detail' => [
                'Payment No' => $pendingApproval->payment?->payment_no ?? '',
                'Status' => $pendingApproval->status ?? '',
                'Approval Level' => $pendingApproval->approval_level ?? '',
                'Next Level Approval Required?' => $pendingApproval->next_level_approval_required
                    ? 'true' : 'false',
                'Approval Type' => $pendingApproval->approval_type ?? '',
                'Approved By' => $this->approvedByFlattened($pendingApproval),
                'Approvers' => $pendingApproval->candidates
                    ->map(fn ($c) => $c->approver_name)->filter()->implode(', '),
                'Preferred Approver' => $pendingApproval->preferredApprover?->name ?? '',
                'Item Category' => $pendingApproval->payment?->itemCategory?->name ?? '',
            ],
            /*
             * The subform, in full. The report shows one name because Creator flattens
             * the grid; this is what it flattens FROM, and it is the only shape that
             * can express `Approval Type = All`.
             */
            'approved_by_rows' => $pendingApproval->approvers->map(fn ($a): array => [
                'Approver' => $a->displayName(),
                'Approval Level' => $a->approval_level ?? '',
                'Approved' => (bool) $a->approved,
                'approved_at' => $a->approved_at?->toDateTimeString(),
            ])->all(),
            'chain' => is_array($pendingApproval->chain) ? $pendingApproval->chain : [],
            'can' => $this->permissions($pendingApproval),
        ]);
    }

    /**
     * Approve at the current level.
     *
     * `approver` is required and is NOT read from a session, because there is no
     * session. `DecideApproval` matches it against the record's own approver rows,
     * which is the only check available without authentication.
     */
    public function approve(Request $request, PendingApproval $pendingApproval, DecideApproval $decide): JsonResponse
    {
        $data = $request->validate(['approver' => ['required', 'string', 'max:255']]);

        try {
            return response()->json($decide->approve($pendingApproval, $data['approver']));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage(), 'reason' => 'refused'], 422);
        }
    }

    /**
     * Reject, with a reason.
     *
     * Creator asks for no reason. This requires one — a rejected payment with no
     * recorded explanation is unanswerable a month later. Logged as a deviation.
     */
    public function reject(Request $request, PendingApproval $pendingApproval, DecideApproval $decide): JsonResponse
    {
        $data = $request->validate([
            'approver' => ['required', 'string', 'max:255'],
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
        ]);

        try {
            return response()->json($decide->reject($pendingApproval, $data['approver'], $data['reason']));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage(), 'reason' => 'refused'], 422);
        }
    }

    /**
     * Pay — gated on `Approved`, which is why the button is pale until then.
     *
     * `payment_date` is when money actually moved, and it is deliberately NOT the same
     * thing as the status change. §6 records that reports bucketing by payment date and
     * by billing month never reconcile, and conflating them is how that starts. Null
     * means today, which is `MarkPaymentPaid`'s own default.
     *
     * `MarkPaymentPaid` acts on the PAYMENT, not on the approval — the approval is only
     * how we got here — so this resolves the payment and refuses if there is none.
     */
    public function pay(Request $request, PendingApproval $pendingApproval, MarkPaymentPaid $pay): JsonResponse
    {
        $data = $request->validate([
            'payment_date' => ['nullable', 'date'],
        ]);

        $payment = $pendingApproval->payment;

        if ($payment === null) {
            return response()->json([
                'message' => 'This approval has no payment attached, so there is nothing to pay. '
                    .'An approval without a payment is a data fault, not a state to move through.',
                'reason' => 'refused',
            ], 422);
        }

        try {
            $paid = $pay($payment, $data['payment_date'] ?? null);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage(), 'reason' => 'refused'], 422);
        }

        $pendingApproval->refresh();

        return response()->json([
            'status' => $pendingApproval->status,
            'payment_status' => $paid->payment_status,
            'payment_no' => $paid->payment_no,
            'payment_date' => $paid->payment_date?->toDateString(),
            'message' => sprintf(
                '%s is marked Paid as of %s. No money moved — this records that it did.',
                $paid->payment_no,
                $paid->payment_date?->toDateString() ?? 'today',
            ),
        ]);
    }

    /**
     * Which buttons are live on this row.
     *
     * Read off the screenshots: `Pay` is solid on the four `Approved` rows and pale on
     * the five `Sent for Approval` ones, and the three actions disable once the record
     * is settled. So enablement is a function of status, per row.
     *
     * @return array<string, bool>
     */
    private function permissions(PendingApproval $p): array
    {
        $open = $p->isOpen();
        $approved = $p->statusIs(PaymentStatus::APPROVED);

        /*
         * PAID IS A FACT ABOUT THE PAYMENT, NOT ABOUT THE APPROVAL.
         *
         * This first read `$p->statusIs(PAID)` and was wrong: `DecideApproval` leaves
         * the approval at `Approved` and `MarkPaymentPaid` moves the PAYMENT, so the
         * approval's own status never becomes `Paid`. The test was therefore always
         * false and `Pay` stayed live on an already-paid row.
         *
         * Caught by rendering — the harness counted five live `Pay` buttons where only
         * four rows were payable, one of them a payment I had already paid through the
         * API minutes earlier. `MarkPaymentPaid` would have refused the second attempt,
         * so no double payment was possible; the defect was a button offering an action
         * that could only fail, which is the honest-chrome rule broken in the other
         * direction.
         */
        $payment = $p->payment;
        $paid = $payment !== null && (
            $payment->statusIs(PaymentStatus::PAID)
            || strcasecmp(trim((string) $payment->payment_status), PaymentStatus::PS_PAID) === 0
        );

        return [
            'approve' => $open,
            'reject' => $open,
            // Approved and not yet paid. Pale everywhere else.
            'pay' => $approved && ! $paid,
        ];
    }

    /**
     * The report's `Approved By` cell — one name, as Creator renders it.
     *
     * Creator shows the subform's first value and discards the rest. Reproduced
     * because the column order and content are meant to match, with the full grid
     * available on the detail response so nothing is actually lost.
     */
    private function approvedByFlattened(PendingApproval $p): string
    {
        return (string) ($p->approvers->first()?->displayName() ?? '');
    }

    /**
     * `Billing Cycles` is plural and comes from the split legs, not the payment.
     *
     * A payment spans many villa x category x cycle legs (§5.2), so this is a
     * comma-packed list of the DISTINCT cycles across them — which is what Creator
     * shows. `label()` gives `August - 2026`, the spelling the live report uses.
     */
    private function billingCycles(?object $payment): string
    {
        if ($payment === null) {
            return '';
        }

        return $payment->splitPayments
            ->map(fn ($leg) => $leg->billingCycle?->label())
            ->filter()
            ->unique()
            ->implode(', ');
    }

    /** @return array<string, mixed> */
    private function row(PendingApproval $p): array
    {
        $payment = $p->payment;

        return [
            'id' => $p->id,
            'Added Time' => $p->added_time?->toDateTimeString() ?? '',
            'Payment Date' => $payment?->payment_date?->toDateString() ?? '',
            // The three action columns carry no value; the UI renders buttons.
            'Approve' => '',
            'Reject' => '',
            'Link' => $payment?->creator_id ?? '',
            'Payment Status' => $p->payment_status ?? '',
            'Pay' => '',
            'Payable Amount' => $payment?->payable_amount ?? '',
            'Location' => $payment?->location?->name ?? '',
            'Gross Amount' => $payment?->amount ?? '',
            'Item Category' => $payment?->itemCategory?->name ?? '',
            'Bank Name' => $payment?->bankAccount?->account_name ?? '',
            'Vendor Name' => $payment?->vendor?->name ?? '',
            'Villa Name' => $payment?->villa?->name ?? '',
            'Payment No' => $payment?->payment_no ?? '',
            'Master Category' => $payment?->masterCategory?->name ?? '',
            'Status' => $p->status ?? '',
            'COA' => $payment?->coaAccount?->account_name ?? '',
            'Billing Cycles' => $this->billingCycles($payment),
            'Approval Level' => $p->approval_level ?? '',
            'Next Level Approval Required?' => $p->next_level_approval_required ? 'true' : 'false',
            'Approval Type' => $p->approval_type ?? '',
            'Approved By' => $this->approvedByFlattened($p),
            'Message ID' => $p->message_id ?? '',
            'can' => $this->permissions($p),
        ];
    }
}
