<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Vendor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Vendor Master — §13A. 8,063 real records.
 *
 * COLUMN ORDER IS EVIDENCE HERE, NOT INFERENCE — which is unusual for this rebuild
 * and worth saying. `Vendor_Master.csv` is itself a Creator REPORT export, so the
 * order of its 21 columns is the order that report displays them in. That is a
 * stronger basis than All Bills has (§6.1's "which report is live" is still open,
 * and its order is taken from the form).
 *
 * WHAT IS STILL NOT KNOWN: which of Creator's two vendor reports this export came
 * from. The nav carries both `Vendor Master` and `All Vendor Masters` and no
 * screenshot of either exists, so the difference between them is unverified. Both
 * nav keys therefore serve the same report, and the UI says so on screen rather
 * than inventing a distinction. The obvious candidate — that one hides merged-away
 * vendors — is exposed as an explicit filter the user can see and toggle, not as a
 * silent default.
 *
 * THREE COLUMNS SHARE THE LABEL `GST No.` in Creator. A JSON row cannot carry the
 * same key three times, so they are suffixed for transport only. The UI renders the
 * bare label three times, as Creator does.
 *
 * NO WRITE PATH. Add and edit are not offered: §13A.1's merge semantics are now
 * understood but the merge ACTION is not — nothing establishes what Creator does to
 * bills, payments and open requests when two vendors are merged, and a vendor with
 * 112 merge pointers aimed at it is not a record to start editing speculatively. The
 * reportbar shows why, per the disabled-with-a-reason rule.
 *
 * NOT BEHIND AUTHORISATION, like the rest of the API. This one carries PII —
 * PANs, GST registrations, phone numbers and free-text bank details — so it is the
 * strongest argument yet for wiring the §3.3 matrix before this is exposed beyond
 * localhost. Flagged, not fixed here.
 */
class VendorController extends Controller
{
    /**
     * The export's own column order, verbatim. `Primary Status`, `Employee` and the
     * two `Time` columns are Creator's labels, not tidied ones.
     */
    private const COLUMNS = [
        'Vendor Name',
        'Main Primary',
        'Primary Vendor',
        'Primary Status',
        'Location',
        'Master Category',
        'Employee Designation',
        'Employee',
        'State',
        'Email',
        'GST No.',
        'Phone',
        'Account Details',
        'Added Time',
        'Added User',
        'ID',
        'GST No. (2)',
        'GST No. (3)',
        'PAN No.',
        'Modified User',
        'Modified Time',
    ];

    /** Columns the UI must render under a bare `GST No.` heading, as Creator does. */
    private const RELABEL = [
        'GST No. (2)' => 'GST No.',
        'GST No. (3)' => 'GST No.',
    ];

    /**
     * 8,063 rows will not go down a wire usefully and no Creator report shows them
     * all at once either. Searching happens HERE rather than in the browser, which
     * is a departure from Settings and Bills — those hold 135 and a handful of rows
     * and filter client-side. At this size that would ship the whole PII table to
     * the browser to filter three fields of it.
     */
    private const PAGE = 200;

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:200'],
            'merged' => ['nullable', 'in:all,active,merged'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $scope = $data['merged'] ?? 'all';
        $term = $data['q'] ?? '';
        $page = (int) ($data['page'] ?? 1);

        $query = Vendor::query()->with(['location', 'state', 'masterCategory']);

        /*
         * The §13A.1 filter, exposed rather than assumed. `active` hides the 112
         * vendors that were merged away; `merged` shows only those. Neither is the
         * default, because which of Creator's two reports does which is unverified.
         */
        if ($scope === 'active') {
            $query->whereNull('primary_vendor');
        } elseif ($scope === 'merged') {
            $query->whereNotNull('primary_vendor');
        }

        if ($term !== '') {
            $query->where(function ($q) use ($term): void {
                /*
                 * ILIKE, and this is the one place case-insensitivity is right.
                 * Storage is verbatim — `27aahfe2088h1zb` and `27AAHFE2088H1ZB` are
                 * both stored as typed — so a case-sensitive search would hide rows
                 * that exist. Normalising the SEARCH is not normalising the DATA.
                 */
                foreach (['name', 'main_primary', 'primary_vendor', 'phone', 'email',
                    'pan_no', 'gst_no_1', 'gst_no_2', 'gst_no_3', 'creator_id',
                    'employee_designation', 'account_details'] as $column) {
                    $q->orWhere($column, 'ilike', '%'.$term.'%');
                }
            });
        }

        $matched = (clone $query)->count();

        $vendors = $query
            ->orderBy('name')->orderBy('id')      // id breaks the 62 name ties stably
            ->forPage($page, self::PAGE)
            ->get();

        return response()->json([
            'report' => 'vendors',
            'title' => 'All Vendor Masters',
            'columns' => self::COLUMNS,
            'relabel' => self::RELABEL,
            'total' => Vendor::query()->count(),
            'matched' => $matched,
            'page' => $page,
            'per_page' => self::PAGE,
            'pages' => max(1, (int) ceil($matched / self::PAGE)),
            'rows' => $vendors->map(fn (Vendor $v): array => $this->row($v))->all(),
            'counts' => [
                'all' => Vendor::query()->count(),
                'active' => Vendor::query()->whereNull('primary_vendor')->count(),
                'merged' => Vendor::query()->whereNotNull('primary_vendor')->count(),
            ],
        ]);
    }

    /**
     * The typeahead behind the Bills vendor picker.
     *
     * Bills used to receive every vendor in `/api/bills/options` and render them
     * into a `<select>`. That was fine at one fixture vendor and is not fine at
     * 8,063: it ships the whole PII table to the browser to populate a dropdown
     * nobody can scroll. Bills now searches through here instead.
     *
     * Merged-away vendors are EXCLUDED from this list and only from this list. A new
     * bill must not be raised against a vendor Creator has merged into another
     * (§13A.1); the report above still shows them, because history is history.
     */
    public function lookup(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:200'],
            'id' => ['nullable', 'integer'],       // resolve a stored selection
        ]);

        // Editing an existing bill: the picker needs the label for the id it holds,
        // and that vendor may well be one that is no longer selectable.
        if (isset($data['id'])) {
            $vendor = Vendor::query()->find($data['id']);

            return response()->json([
                'results' => $vendor === null ? [] : [$this->option($vendor)],
            ]);
        }

        $term = $data['q'] ?? '';

        /*
         * THE FILTER IS `main_primary IS NOT NULL` — Creator's, not mine.
         *
         * CORRECTING WHAT THIS SHIPPED WITH. I had `primary_vendor IS NULL`, excluding
         * the 112 merged-away vendors, reasoning from §13A.1. The Payment form in
         * Accounts.ds says otherwise:
         *
         *     Vendor_Name -> Vendor_Master[Main_Primary.Main_Primary is not null].ID
         *
         * Measured against the real data the two differ in BOTH directions:
         *
         *     Creator's filter   6,957 selectable
         *     mine               7,952 selectable
         *     Creator includes 112 I excluded   (exactly the merged-away rows)
         *     Creator excludes 1,107 I included
         *
         * And those 1,107 are the CUSTOMER PAYEES addendum §18 identified — 1,097 of
         * the 1,099 `…(Customer)` rows have a blank main_primary. So Creator's rule is
         * "trade vendors, not customers", which is the sensible one; mine excluded the
         * wrong population and admitted the wrong one.
         *
         * `main_primary` still must never RESOLVE a merge (§13A.1). Being a
         * selectability flag and being a merge pointer are different jobs, and this
         * field does the first, not the second.
         */
        $query = Vendor::query()
            ->with('location')
            ->whereNotNull('main_primary')
            ->where('name', '<>', '');          // the 5 nameless rows are unpickable

        if ($term !== '') {
            $query->where(function ($q) use ($term): void {
                $q->where('name', 'ilike', '%'.$term.'%')
                    ->orWhere('gst_no_2', 'ilike', '%'.$term.'%')
                    ->orWhere('pan_no', 'ilike', '%'.$term.'%');
            });
        }

        return response()->json([
            'results' => $query->orderBy('name')->limit(30)->get()
                ->map(fn (Vendor $v): array => $this->option($v))->all(),
            // So the UI can say "30 of 214" rather than implying the list is complete.
            'matched' => (clone $query)->count(),
        ]);
    }

    public function show(Vendor $vendor): JsonResponse
    {
        $vendor->load(['location', 'state', 'masterCategory', 'primaryVendor', 'mergedVendors']);

        return response()->json([
            'row' => $this->row($vendor),
            'merge' => [
                // The pointer as Creator stores it — a NAME — plus the resolved id
                // where it resolves. A name with no id means it matches several rows.
                'primary_vendor' => $vendor->primary_vendor,
                'primary_vendor_id' => $vendor->primary_vendor_id,
                'resolved' => $vendor->primary_vendor !== null && $vendor->primary_vendor_id === null
                    ? 'ambiguous'
                    : ($vendor->primary_vendor === null ? 'not merged' : 'resolved'),
                'is_merge_target' => (bool) $vendor->is_primary,
                'merged_in' => $vendor->mergedVendors
                    ->map(fn (Vendor $v): array => ['id' => $v->id, 'name' => $v->name])->all(),
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function row(Vendor $vendor): array
    {
        return [
            'id' => $vendor->id,
            'Vendor Name' => $vendor->name,
            'Main Primary' => $vendor->main_primary ?? '',
            'Primary Vendor' => $vendor->primary_vendor ?? '',
            // Creator's own label for the merge-target flag. Rendered as a word, not
            // a checkbox: §17's browser pass caught booleans printing as the literal
            // text `false` on 135 rows, and blank-for-false reads better than 7,970
            // of the same word.
            'Primary Status' => $vendor->is_primary ? 'Yes' : '',
            'Location' => $vendor->location?->name ?? '',
            'Master Category' => $vendor->masterCategory?->name ?? '',
            'Employee Designation' => $vendor->employee_designation ?? '',
            'Employee' => $vendor->is_employee ? 'Yes' : 'No',
            'State' => $vendor->state?->name ?? '',
            'Email' => $vendor->email ?? '',
            'GST No.' => $vendor->gst_no_1 ?? '',
            'Phone' => $vendor->phone ?? '',
            'Account Details' => $vendor->account_details ?? '',
            'Added Time' => $vendor->added_time?->toDateString() ?? '',
            'Added User' => $vendor->added_user ?? '',
            'ID' => $vendor->creator_id ?? '',
            'GST No. (2)' => $vendor->gst_no_2 ?? '',
            'GST No. (3)' => $vendor->gst_no_3 ?? '',
            'PAN No.' => $vendor->pan_no ?? '',
            'Modified User' => $vendor->modified_user ?? '',
            'Modified Time' => $vendor->modified_time?->toDateString() ?? '',
        ];
    }

    /**
     * A picker option. The label carries the GST or PAN where there is one, because
     * 62 vendor names are ambiguous on their own and picking the wrong `Hussain`
     * puts a bill on the wrong vendor.
     */
    private function option(Vendor $vendor): array
    {
        $qualifier = $vendor->gst_no_2 ?? $vendor->gst_no_3 ?? $vendor->pan_no;

        return [
            'value' => (string) $vendor->id,
            'label' => $vendor->name,
            'hint' => $qualifier,
            'location' => $vendor->location?->name,
        ];
    }
}
