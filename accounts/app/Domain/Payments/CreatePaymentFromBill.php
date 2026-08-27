<?php

declare(strict_types=1);

namespace App\Domain\Payments;

use App\Domain\Bills\Money;
use App\Models\Bill;
use App\Models\CoaAccount;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The `Create_Payment` custom action — §7.2, Accounts.ds:45389-45482.
 *
 * Creator runs this per-record from the Bills report and inserts one Payment plus
 * its two subforms. This reproduces that, with three deliberate departures:
 *
 *   D1  the partially-paid TDS sign is corrected (see PayableFormula)
 *   D2  the split legs must balance before anything is written (see
 *       PaymentSplitValidator) — Creator has no such check at all, §7.4
 *   D4  the whole thing is one transaction. Creator inserts the Payment, then the
 *       Bill_Payments rows, then the Split_Payments rows, then mutates the bill's
 *       status, with no transaction anywhere. A failure part-way leaves a payment
 *       with no legs, which §5.2 says silently misstates the downstream ledger.
 *
 * Everything else is Creator's behaviour, including the things that look odd:
 * COA is forced to "Accounts Payable" rather than taken from the bill; Status
 * starts at "Submit for Approval" while Payment_Status starts at the undeclared
 * "Open"; and the bill moves to "Payment InProgress".
 */
final class CreatePaymentFromBill
{
    /**
     * @param  string|null  $addedUser  Creator uses zoho.loginuser. Passed in
     *                                  rather than read from auth() because the
     *                                  §3.3 permission layer is the caller's job
     *                                  and this action stays testable without it.
     */
    public function __invoke(Bill $bill, ?string $addedUser = null): Payment
    {
        return DB::transaction(function () use ($bill, $addedUser): Payment {
            $bill->refresh()->loadMissing('splitPayments', 'itemCategories.masterCategory');

            // Creator forces this rather than carrying bill.COA across
            // (Accounts.ds:45397). Reproduced. The column is `account_name`, which
            // is what the COA export calls it.
            $coa = CoaAccount::query()->where('account_name', 'Accounts Payable')->first();

            if ($coa === null) {
                throw new RuntimeException(
                    'COA account "Accounts Payable" is missing. Create_Payment forces every '
                    .'payment onto it (Accounts.ds:45397), so numbering must not proceed '
                    .'without it.'
                );
            }

            $partiallyPaid = $this->isPartiallyPaid($bill);
            $legs = $this->buildLegs($bill, $partiallyPaid);

            /*
             * D2 — the §7.4 check, before a single row is written.
             *
             * THE EXPECTED FIGURE IS THE BILL'S GROSS, NOT ITS PAYABLE. §6.4 rule 1
             * ties a bill's split legs to `Amount` — "Sum(Split_Payment.Amount) ==
             * Amount" — and Create_Payment copies those leg amounts straight across
             * on the normal path (`payamount = rec.Amount`, Accounts.ds:45463). So
             * the payment's legs sum to the bill's gross by construction.
             *
             * An earlier version compared against `payable_amount`, which can only
             * agree when gross equals payable. It passed only because the fixture
             * had been built to match the check rather than to match §6.4 — a real
             * bill with any GST or TDS would have been refused. Caught by driving
             * the Bills form in a browser: gross 90,000 with payable 106,200 was
             * rejected for being 16,200 out, which is exactly the GST.
             *
             * The bill's `payable_amount` still travels, on the Bill_Payments row —
             * which is where Creator puts it (`row1.Bill_Amount =
             * input.Payable_Amount`).
             */
            $expected = $partiallyPaid
                ? $this->backendPayableTotal($bill)
                : Money::normalise($bill->amount);

            $balance = PaymentSplitValidator::check($legs, $expected);

            if (! $balance['balanced']) {
                throw new UnbalancedPaymentException(PaymentSplitValidator::message($balance));
            }

            /*
             * Creator carries Master_Category and Item_Category from single lookups
             * on Bills. This schema models item categories as a LIST
             * (`bill_item_category`, §6.2) and carries no master category column at
             * all, so both are derived from the first item category rather than
             * invented as new columns. A bill with several categories keeps the full
             * set on its split legs, which is where §5.2 says attribution lives.
             */
            $itemCategory = $bill->itemCategories->first();

            $payment = Payment::create([
                'payment_no' => PaymentNumber::allocate(),
                'bill_id' => $bill->id,
                'vendor_id' => $bill->vendor_id,
                'coa_account_id' => $coa->id,
                'master_category_id' => $itemCategory?->master_category_id,
                'item_category_id' => $itemCategory?->id,
                'location_id' => $bill->location_id,
                'head_office_id' => $bill->head_office_id,
                'tds_rate_id' => $bill->tds_rate_id,
                'villa_id' => $bill->splitPayments->first()?->villa_id,

                // Both axes exactly as Create_Payment writes them (§7.3). "Open"
                // is not in the declared Payment_Status picklist; it is live.
                'status' => PaymentStatus::SUBMIT_FOR_APPROVAL,
                'payment_status' => PaymentStatus::PS_OPEN,

                'amount' => Money::normalise($bill->amount),
                'gst_amount' => Money::normalise($bill->gst_amount),
                'tds_amount' => Money::normalise($bill->tds_amount),

                // Bills stores no total_amount column; Accounts.ds:22489 defines it
                // as Amount + GST_Amount, so it is computed rather than read.
                'total_amount' => Money::add($bill->amount, $bill->gst_amount),
                'payable_amount' => $balance['sum'],

                'requested_date' => now()->toDateString(),
                'due_date' => $bill->due_date,

                'accounts_bills' => true,
                'added_user' => $addedUser,
            ]);

            // The Bill_Payments subform — one row, the bill and its payable.
            $payment->billPayments()->create([
                'bill_id' => $bill->id,
                'bill_amount' => Money::normalise($bill->payable_amount),
                'position' => 0,
            ]);

            foreach ($legs as $position => $leg) {
                $payment->splitPayments()->create($leg + ['position' => $position]);
            }

            // Creator's `input.Status = "Payment InProgress"`. Note the casing:
            // addendum §10 records BOTH "Payment InProgress" and "Payment
            // Inprogress" as live. This writes the Create_Payment spelling.
            $bill->status = 'Payment InProgress';
            $bill->save();

            return $payment->fresh(['billPayments', 'splitPayments']);
        });
    }

    /**
     * §7.2's condition, verbatim: the bill is Partially Paid AND the leg carries a
     * backend total. Creator tests the backend figure per-leg inside the loop; the
     * bill-level half is hoisted here because it cannot vary between legs.
     */
    private function isPartiallyPaid(Bill $bill): bool
    {
        // Through statusIs(), not a bare ===: addendum §10 records Creator
        // disagreeing with itself on bill-status casing.
        return $bill->statusIs('Partially Paid');
    }

    /**
     * Build the payment's split legs from the bill's.
     *
     * @return list<array<string, mixed>>
     */
    private function buildLegs(Bill $bill, bool $partiallyPaid): array
    {
        $legs = [];

        foreach ($bill->splitPayments as $leg) {
            $useBackend = $partiallyPaid && $leg->backend_total_amount !== null;

            if ($useBackend) {
                $total = Money::normalise($leg->backend_total_amount);
                $tds = Money::normalise($leg->backend_tds_amount);
                $gst = Money::normalise($leg->backend_gst_amount);

                // D1 — TDS deducted, not added. PayableFormula documents the
                // algebra and keeps Creator's version for comparison.
                $payable = PayableFormula::partiallyPaid($total, $tds);
            } else {
                $total = Money::normalise($leg->total_amount);
                $tds = Money::normalise($leg->tds_amount);
                $gst = Money::normalise($leg->gst_amount);

                // Creator copies the stored rec.Amount through on this path rather
                // than recomputing. Reproduced — the bill's own validation already
                // guarantees these legs sum to its gross (§6.4).
                $payable = Money::normalise($leg->amount);
            }

            $legs[] = [
                'villa_id' => $leg->villa_id,
                'item_category_id' => $leg->item_category_id,
                'billing_cycle_id' => $leg->billing_cycle_id,
                'amount' => $payable,
                'total_amount' => $total,
                'tds_amount' => $tds,
                'gst_amount' => $gst,

                // Creator snapshots the same three onto the backend triplet on the
                // payment side too (Accounts.ds:45475-45477). Reproduced.
                'backend_amount' => $total,
                'backend_tds_amount' => $tds,
                'backend_gst_amount' => $gst,

                'percent' => $leg->percent,
            ];
        }

        return $legs;
    }

    /**
     * What the backend legs should sum to on a partially-paid bill.
     *
     * There is no bill-level backend payable column, so it is the sum of the
     * corrected per-leg figures. That makes the §7.4 check on this path a
     * consistency check rather than an independent one — which is worth stating
     * plainly: it catches a leg that fails to compute, not a bill whose backend
     * snapshot is itself wrong. Detecting the latter needs the [TODO] in §6.3
     * about which Payable formula is authoritative to be settled first.
     */
    private function backendPayableTotal(Bill $bill): string
    {
        $sum = Money::zero();

        foreach ($bill->splitPayments as $leg) {
            $sum = $leg->backend_total_amount !== null
                ? Money::add($sum, PayableFormula::partiallyPaid(
                    $leg->backend_total_amount,
                    $leg->backend_tds_amount,
                ))
                : Money::add($sum, $leg->amount);
        }

        return $sum;
    }
}
