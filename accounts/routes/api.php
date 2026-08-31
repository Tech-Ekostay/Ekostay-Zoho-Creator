<?php

use App\Http\Controllers\Api\BillController;
use App\Http\Controllers\Api\ExpenseController;
use App\Http\Controllers\Api\PendingApprovalController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\SettingsRecordController;
use App\Http\Controllers\Api\SettingsReportController;
use App\Http\Controllers\Api\VendorController;
use Illuminate\Support\Facades\Route;

/*
 * Settings — the eight reports behind the nav flyout (addendum §2).
 *
 * The reads came first; the writes below are what the `+` button, the row form and
 * COA's `Save Changes` call. Those controls existed as rendered chrome with no
 * handlers and nothing to POST to, which is why nothing worked.
 *
 * NO DELETE ROUTE, deliberately. These are live lookup keys with FK children, and
 * no Creator screenshot has shown a delete control on any of the eight reports.
 *
 * `api/settings/*` is EXEMPT FROM TrimStrings in bootstrap/app.php. Do not remove
 * that exemption: `F&B STAFF MEDICAL EXPENSE ` is a live key that is 26 characters
 * stored and 25 trimmed, and trimming it on save would break every join that
 * matches on it, silently.
 */
Route::get('/settings/reports', [SettingsReportController::class, 'index']);
Route::get('/settings/reports/{report}', [SettingsReportController::class, 'show']);
Route::post('/settings/reports/{report}', [SettingsRecordController::class, 'store']);
Route::patch('/settings/reports/{report}/{id}', [SettingsRecordController::class, 'update']);
// COA's inline grid commit — several rows, one transaction.
Route::patch('/settings/reports/{report}', [SettingsRecordController::class, 'bulkUpdate']);

/*
 * Bills — §6, §17 step 5's read API plus the write path the `+` button needs.
 *
 * The schema and arithmetic landed in step 4; nothing here re-implements the split
 * rules — App\Domain\Bills\SplitAllocator and SplitValidator already carry §6.3's
 * remainder-on-the-last-row and §5.1's reconcile-never-clear-and-rebuild.
 *
 * `split-equally` is an endpoint rather than a browser calculation on purpose: the
 * rule TRUNCATES at paisa and puts the dropped remainder on the LAST row, and §6.3
 * says "Reproduce exactly. Do not substitute banker's rounding." Re-implementing
 * that in JavaScript would be re-deciding it.
 *
 * No DELETE route. Bills guards deletion behind `Status == "Draft"` in Creator, but
 * §12.4's census found hard deletes all over the exports and the safe default here
 * is not to offer one until the rule is verified from a screenshot.
 */
Route::get('/bills', [BillController::class, 'index']);
Route::get('/bills/options', [BillController::class, 'options']);
Route::post('/bills/split-equally', [BillController::class, 'splitEqually']);
Route::get('/bills/{bill}', [BillController::class, 'show']);
Route::post('/bills', [BillController::class, 'store']);
Route::patch('/bills/{bill}', [BillController::class, 'update']);

/*
 * Payments — §7, and the first write paths in the application.
 *
 * §17 step 7 gated these until the four §16 "blocking write paths" questions were
 * answered. All four are now closed against the DS exports; the two that turned
 * out to be defects rather than conventions are fixed rather than replicated, on
 * Husain's instruction of 22-Aug-2026:
 *
 *   D1  the partially-paid TDS sign (§7.2)   — App\Domain\Payments\PayableFormula
 *   D2  the missing split balance check (§7.4) — PaymentSplitValidator
 *   D3  payment-number padding (§7.6)        — moot, see PaymentNumber
 *   D4  Delete Paid Payment (§7.6)           — replaced by reverse, below
 *
 * THERE IS NO `DELETE` ROUTE, deliberately. §7.6: "no hard delete on a settled
 * payment. Reverse it." Payment::delete() enforces the same rule at the model, so
 * the absence here is not the only line of defence.
 *
 * NOT YET BEHIND AUTHORISATION — see PaymentController's docblock. The §3.3 matrix
 * is extracted and tested but not wired to a gate.
 */
/*
 * PAYMENTS CAN BE CREATED TWO WAYS, and until 25-Aug-2026 this app only knew one.
 *
 *   POST /payments          §7.2 Create_Payment — FROM a bill, per-record action
 *   POST /payments/direct   entered directly on the Payment form
 *
 * Husain corrected the model: a payment is not only ever made from a bill. None of
 * the three context docs record the direct path — §7.2 is the only creation route
 * they describe — so the field set comes from the Payment form in Accounts.ds
 * (lines 7273-8673), 130 entries over 10 sections with row/column layout.
 *
 *  is declared before  so the word is not read as a parameter.
 */
Route::get('/payments/options', [PaymentController::class, 'options']);
Route::post('/payments/direct', [PaymentController::class, 'storeDirect']);
// The form's live arithmetic — Creator's `on user input` handlers. A round trip
// rather than JS so there is exactly one implementation of the money rules.
Route::post('/payments/recalculate', [PaymentController::class, 'recalculate']);
Route::get('/payments', [PaymentController::class, 'index']);
Route::get('/payments/{payment}', [PaymentController::class, 'show']);
Route::post('/payments', [PaymentController::class, 'store']);
Route::post('/payments/{payment}/reverse', [PaymentController::class, 'reverse']);

/*
 * Vendor Master — §13A. 8,063 real records, seeded 22-Aug-2026.
 *
 * `lookup` exists because Bills cannot have a dropdown of 8,063 vendors. It was a
 * plain `<select>` fed from `/api/bills/options` when there was exactly one fixture
 * vendor in the database; at real scale that ships the whole PII table to the
 * browser to populate a control nobody can scroll. Declared BEFORE `/{vendor}` so
 * the word `lookup` is not read as a route parameter.
 *
 * NO WRITE ROUTES. §13A.1's merge SEMANTICS are settled (see the migration) but the
 * merge ACTION is not: nothing yet establishes what Creator does to open bills,
 * payments and requests when two vendors are merged. Editing a record that 112
 * merge pointers aim at, without knowing that, is not a safe default.
 *
 * THIS IS THE MOST SENSITIVE READ IN THE APP — PANs, GST registrations, phone
 * numbers and free-text bank details, none of it behind the §3.3 gate yet.
 */
Route::get('/vendors', [VendorController::class, 'index']);
Route::get('/vendors/lookup', [VendorController::class, 'lookup']);
Route::get('/vendors/{vendor}', [VendorController::class, 'show']);

/*
 * Expenses — the ledger (§5.2). 66,402 real rows.
 *
 * COLUMN ORDER IS VERIFIED from twelve screenshots of the live report covering the
 * full horizontal scroll, which makes this the only report here whose order is seen
 * rather than inferred. Nine of its columns render empty because the Analytics view
 * does not carry them; ExpenseController names them rather than deriving them.
 *
 * No write route. `Update Expense` is a per-record action on the report and what it
 * does is unverified — a button that mutates a ledger entry is not something to
 * guess at.
 */
Route::get('/expenses', [ExpenseController::class, 'index']);
Route::get('/expenses/{expense}', [ExpenseController::class, 'show']);

/*
|--------------------------------------------------------------------------
| Pending Approvals — the first route here that MOVES money
|--------------------------------------------------------------------------
|
| 24 columns, order verified from seven screenshots (27-Aug-2026). The three
| actions sit MID-TABLE, between `Payment Date` and `Payable Amount`, which is
| where the live report puts them.
|
| The write routes are the transitions that were missing until 65f5845: there were
| eight write paths in this app and not one of them was a status change, so
| `Draft -> Sent for Approval -> Approved -> Paid` was a diagram rather than a path.
|
| POST /pending-approvals/{id}/approve   tick the approver's row; advance or finalise
| POST /pending-approvals/{id}/reject    flat, immediate, both records, reason REQUIRED
| POST /pending-approvals/{id}/pay       gated on Approved — the pale button
|
| NO AUTHORISATION on any of them. §3.3's matrix is extracted and tested and is not
| wired to a gate. `DecideApproval` checks the named approver is ON the record, which
| is not the same as checking who is calling — and the index response says
| `unauthenticated: true` so the UI cannot present this as a control. That is the
| blocker before this is exposed to anyone, and it is the same gap that makes
| `Accounts.DeletePermanentlyTrash` (addendum §7F.5) what it is.
|
| Deliberately absent: a DELETE route. §7.6 — a payment number, once issued, is never
| reissued, and an approval is not the place to start deleting from.
*/
Route::get('/pending-approvals', [PendingApprovalController::class, 'index']);
Route::get('/pending-approvals/{pendingApproval}', [PendingApprovalController::class, 'show']);
Route::post('/pending-approvals/{pendingApproval}/approve', [PendingApprovalController::class, 'approve']);
Route::post('/pending-approvals/{pendingApproval}/reject', [PendingApprovalController::class, 'reject']);
Route::post('/pending-approvals/{pendingApproval}/pay', [PendingApprovalController::class, 'pay']);
