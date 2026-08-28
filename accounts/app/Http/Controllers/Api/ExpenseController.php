<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Reports\ReportFilter;
use App\Http\Controllers\Controller;
use App\Models\Expense;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * All Expenses — the ledger (§5.2). 66,402 real rows.
 *
 * COLUMN ORDER IS VERIFIED, and that is worth saying because almost nothing else in
 * this app can claim it. The 34 columns below are in the order the live report shows
 * them, read across twelve screenshots covering the full horizontal scroll
 * (27-Aug-2026). Not inferred from a form, not taken from the export's key order.
 *
 * THREE THINGS THE SCREENSHOTS SETTLED THAT WOULD HAVE BEEN GUESSED WRONG:
 *
 *  1. `ID BIlls` — capital I in "BIlls". A live misspelling, preserved as a label.
 *  2. `TDS %` is its own column beside `TDS Amount`. Two columns, not one.
 *  3. `Update Expense` is a per-record action rendered as a button INSIDE a column,
 *     second from the left. Bills' `Create Payment` was built as a strip under the
 *     grid; this report puts the action in the row, early. Different shape.
 *
 * NINE COLUMNS RENDER EMPTY, and deliberately. The Analytics `expenses` view carries
 * 44 keys and fills 24 of the report's 33 data columns. These nine are on the report
 * and in no export held here:
 *
 *     Primary Vendor Name · TDS % · Recon Expense · Vendor GST No. ·
 *     ID BIlls · Bills · Added User · Modified User · Payment Status
 *
 * They are in the column list because the report has them and the order matters;
 * they are null because inventing them would be worse. `Payment Status` is the
 * tempting one — deriving it from `Status` would look right and be a guess.
 */
class ExpenseController extends Controller
{
    use Concerns\FiltersReports;
    use Concerns\PagesReports;

    /** Verbatim, in the order the live report displays them. */
    private const COLUMNS = [
        'Added Time',
        'Update Expense',          // a per-record action, not a value
        'Primary Villa',
        'Payment',
        'Bill No',
        'Primary Vendor Name',     // not in the export
        'Vendor Name',
        'Payment Date',
        'Bill Date',
        'Villa Name',
        'Particulars',
        'Location',
        'Gross Amount',
        'TDS Amount',
        'TDS %',                   // not in the export
        'GST Amount',
        'Amount',
        'Item Category',
        'Master Category',
        'Billing Cycle',
        'COA',
        'Bank Name',
        'Recon Expense',           // not in the export
        'Vendor GST No.',          // not in the export
        'Status',
        'ID BIlls',                // not in the export. Creator's capital I.
        'Link',
        'ID',
        'Expense By',
        'Bills',                   // not in the export
        'Added User',              // not in the export
        'Modified User',           // not in the export
        'Modified Time',
        'Payment Status',          // not in the export
    ];

    /** Columns the export cannot fill — surfaced so the UI can say why they are blank. */
    private const UNSOURCED = [
        'Primary Vendor Name', 'TDS %', 'Recon Expense', 'Vendor GST No.',
        'ID BIlls', 'Bills', 'Added User', 'Modified User', 'Payment Status',
    ];

    private function filterable(): ReportFilter
    {
        return new ReportFilter([
            'Added Time' => ['column' => 'added_time', 'type' => 'date'],
            'Payment' => ['column' => 'payment_no', 'type' => 'text'],
            'Bill No' => ['column' => 'bill_no', 'type' => 'text'],
            'Vendor Name' => ['column' => 'vendor_name', 'type' => 'text'],
            'Payment Date' => ['column' => 'payment_date', 'type' => 'date'],
            'Bill Date' => ['column' => 'bill_date', 'type' => 'date'],
            'Villa Name' => ['column' => 'name', 'type' => 'text', 'relation' => 'villa'],
            'Particulars' => ['column' => 'particulars', 'type' => 'text'],
            'Location' => ['column' => 'name', 'type' => 'text', 'relation' => 'location'],
            'Gross Amount' => ['column' => 'gross_amount', 'type' => 'money'],
            'TDS Amount' => ['column' => 'tds_amount', 'type' => 'money'],
            'GST Amount' => ['column' => 'gst_amount', 'type' => 'money'],
            'Amount' => ['column' => 'amount', 'type' => 'money'],
            'Item Category' => ['column' => 'name', 'type' => 'text', 'relation' => 'itemCategory'],
            'Master Category' => ['column' => 'name', 'type' => 'text', 'relation' => 'masterCategory'],
            'COA' => ['column' => 'account_name', 'type' => 'text', 'relation' => 'coaAccount'],
            'Bank Name' => ['column' => 'account_name', 'type' => 'text', 'relation' => 'bankAccount'],
            'Status' => ['column' => 'status', 'type' => 'text'],
            'Expense By' => ['column' => 'expense_by', 'type' => 'text'],
            'ID' => ['column' => 'creator_id', 'type' => 'text'],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        // The report sorts by Added Time descending — the newest expense first, which
        // is what the screenshots show (27-Aug 19:06 at the top).
        $query = Expense::query()
            ->with(['villa', 'primaryVilla', 'location', 'itemCategory', 'masterCategory',
                'coaAccount', 'bankAccount', 'billingCycle'])
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
        $expenses = $page['rows'];

        return response()->json([
            'report' => 'expenses',
            'title' => 'All Expenses',
            'columns' => self::COLUMNS,
            'unsourced' => self::UNSOURCED,
            ...$this->pagingEnvelope(
                $offset, $page['next_offset'], $matched, Expense::query()->count(),
            ),
            'filter_schema' => $filter->schema(),
            'filters' => $filters,
            'rows' => $expenses->map(fn (Expense $e): array => $this->row($e))->all(),
        ]);
    }

    public function show(Expense $expense): JsonResponse
    {
        $expense->load(['villa', 'primaryVilla', 'location', 'itemCategory', 'masterCategory',
            'coaAccount', 'bankAccount', 'billingCycle', 'headOffice', 'vendor', 'payment']);

        /*
         * FIELD ORDER FROM THE DETAIL SCREENSHOTS, which are the first Creator
         * record-detail view seen on this project. It is NOT the report's column
         * order — the detail leads with Payment Date and the categories, and puts the
         * amounts in the middle. Recorded here rather than reusing the grid order.
         */
        return response()->json([
            'row' => $this->row($expense),
            'detail' => [
                'Payment Date' => $expense->payment_date?->toDateString() ?? '',
                'Master Category' => $expense->masterCategory?->name ?? '',
                'Item Category' => $expense->itemCategory?->name ?? '',
                'Villa Name' => $expense->villa?->name ?? '',
                'Location' => $expense->location?->name ?? '',
                'Head Office' => $expense->headOffice?->name ?? '',
                'COA' => $expense->coaAccount?->account_name ?? '',
                'Accounts Remarks' => $expense->accounts_remarks ?? '',
                'Management Remarks' => $expense->management_remarks ?? '',
                'Particulars' => $expense->particulars ?? '',
                'Timestamp Date' => $expense->timestamp_date?->toDateTimeString() ?? '',
                'Expense By' => $expense->expense_by ?? '',
                'Payment By' => $expense->payment_by ?? '',
                'Gross Amount' => $expense->gross_amount ?? '',
                'GST Amount' => $expense->gst_amount ?? '',
                'TDS Amount' => $expense->tds_amount ?? '',
                'PT' => $expense->pt_amount ?? '',
                'ESIC' => $expense->esic_amount ?? '',
                'PF' => $expense->pf_amount ?? '',
                'Net Paid Amount' => $expense->net_paid_amount ?? '',
                'Payment Reference Number' => $expense->payment_reference_number ?? '',
                'Vendor Name' => $expense->vendor_name ?? '',
                'Type' => $expense->type ?? '',
                'Bill Date' => $expense->bill_date?->toDateString() ?? '',
                'Booking No.' => $expense->booking_no ?? '',
                'Amount' => $expense->amount ?? '',
                'Status' => $expense->status ?? '',
                'Due Date' => $expense->due_date?->toDateString() ?? '',
                'Bill No' => $expense->bill_no ?? '',
                'Billing Cycle' => $expense->billingCycle?->label() ?? '',
                'Payment' => $expense->payment_no ?? '',
                'Books ID' => $expense->books_id ?? '',
                'Bank Name' => $expense->bankAccount?->account_name ?? '',
                'CA Email' => $expense->ca_email ?? '',
                'Recon Expense' => $expense->recon_expense ? 'true' : 'false',
                'Duplicate' => $expense->duplicate ? 'true' : 'false',
                'Link' => $expense->link ?? '',
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function row(Expense $e): array
    {
        return [
            'id' => $e->id,
            'Added Time' => $e->added_time?->toDateTimeString() ?? '',
            // The action column carries no value; the UI renders a button.
            'Update Expense' => '',
            'Primary Villa' => $e->primaryVilla?->name ?? '',
            'Payment' => $e->payment_no ?? '',
            'Bill No' => $e->bill_no ?? '',
            'Primary Vendor Name' => '',        // not in the export
            'Vendor Name' => $e->vendor_name ?? '',
            'Payment Date' => $e->payment_date?->toDateString() ?? '',
            'Bill Date' => $e->bill_date?->toDateString() ?? '',
            'Villa Name' => $e->villa?->name ?? '',
            'Particulars' => $e->particulars ?? '',
            'Location' => $e->location?->name ?? '',
            'Gross Amount' => $e->gross_amount ?? '',
            'TDS Amount' => $e->tds_amount ?? '',
            'TDS %' => '',                      // not in the export
            'GST Amount' => $e->gst_amount ?? '',
            'Amount' => $e->amount ?? '',
            'Item Category' => $e->itemCategory?->name ?? '',
            'Master Category' => $e->masterCategory?->name ?? '',
            'Billing Cycle' => $e->billingCycle?->label() ?? '',
            'COA' => $e->coaAccount?->account_name ?? '',
            'Bank Name' => $e->bankAccount?->account_name ?? '',
            // Rendered as the word, as the screenshots show ("false", not a checkbox).
            'Recon Expense' => $e->recon_expense ? 'true' : 'false',
            'Vendor GST No.' => '',             // not in the export
            'Status' => $e->status ?? '',
            'ID BIlls' => $e->id_bills ?? '',   // not in the export
            'Link' => $e->link ?? '',
            'ID' => $e->creator_id ?? '',
            'Expense By' => $e->expense_by ?? '',
            'Bills' => $e->bills ?? '',         // not in the export
            'Added User' => $e->added_user ?? '',       // not in the export
            'Modified User' => $e->modified_user ?? '', // not in the export
            'Modified Time' => $e->modified_time?->toDateTimeString() ?? '',
            'Payment Status' => $e->payment_status ?? '', // not in the export
        ];
    }
}
