<?php

declare(strict_types=1);

namespace App\Domain\Payments;

use App\Domain\Bills\Money;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;

/**
 * What replaces `Delete Paid Payment` — §7.6.
 *
 * The requirement, quoted: "no hard delete on a settled payment. Reverse it — a
 * linked reversing entry with negative amounts, a required reason, the original
 * and its number intact."
 *
 * WHY THIS MATTERS MORE THAN IT LOOKS. Creator ships `Delete Paid Payment` in the
 * More menu, one click from a settled payment, and prior field notes record 17
 * real payments destroyed by it. A grep of the exports finds 14 unguarded
 * `delete from Payment` sites in Accounts.ds and — worse, outside Payments —
 * `void DeleteAllRecords()` at F_B.ds:4645, which runs
 * `delete from <table>[ID != null]` across 14 F&B tables including Expenses.
 * Standalone Deluge functions are invocable as REST endpoints.
 *
 * So the rule here is absolute: nothing in this codebase hard-deletes a payment.
 * The model uses SoftDeletes for the draft-abandonment case only, and a settled
 * payment cannot be soft-deleted either — it can only be reversed.
 *
 * A REVERSAL IS A NEW ROW. It takes its own payment number, because the reversing
 * entry is a real ledger event and §7.6 warns that anything keyed on payment
 * number drifts if numbers are reused. The original keeps its number, its row and
 * its legs.
 */
final class ReversePayment
{
    public function __invoke(Payment $payment, string $reason, ?string $addedUser = null): Payment
    {
        $reason = trim($reason);

        // §7.6 says "a required reason". Enforced here rather than by a NOT NULL
        // column, because forward payments legitimately have none.
        if ($reason === '') {
            throw new ReversalRefusedException(
                'A reversal reason is required. §7.6: the reversing entry carries a reason so '
                .'the ledger records why money moved back, which a hard delete never could.'
            );
        }

        if (! PaymentStatus::isSettled($payment->status, $payment->payment_status)) {
            throw new ReversalRefusedException(sprintf(
                'Payment %s is not settled (status "%s" / payment status "%s"). An unsettled '
                .'payment has moved no money, so there is nothing to reverse — withdraw it '
                .'instead.',
                $payment->payment_no,
                $payment->status,
                $payment->payment_status,
            ));
        }

        if ($payment->reverses_payment_id !== null) {
            throw new ReversalRefusedException(sprintf(
                'Payment %s is itself a reversal of #%d. Reversing a reversal would net to the '
                .'original amount by a path nothing downstream can read — re-issue instead.',
                $payment->payment_no,
                $payment->reverses_payment_id,
            ));
        }

        if ($payment->reversal()->exists()) {
            throw new ReversalRefusedException(sprintf(
                'Payment %s has already been reversed. A second reversal would double-credit.',
                $payment->payment_no,
            ));
        }

        return DB::transaction(function () use ($payment, $reason, $addedUser): Payment {
            $reversal = Payment::create([
                'payment_no' => PaymentNumber::allocate(),
                'bill_id' => $payment->bill_id,
                'vendor_id' => $payment->vendor_id,
                'coa_account_id' => $payment->coa_account_id,
                'master_category_id' => $payment->master_category_id,
                'item_category_id' => $payment->item_category_id,
                'location_id' => $payment->location_id,
                'head_office_id' => $payment->head_office_id,
                'tds_rate_id' => $payment->tds_rate_id,
                'villa_id' => $payment->villa_id,
                'booking_no' => $payment->booking_no,

                // Axis 2 carries the declared "Reverse" value (§7.3). Axis 1 stays
                // Paid: the reversing entry is itself a settled movement of money,
                // and leaving it in an approval state would imply it might not
                // happen.
                'status' => PaymentStatus::PAID,
                'payment_status' => PaymentStatus::PS_REVERSE,

                'amount' => Money::sub(null, $payment->amount),
                'gst_amount' => Money::sub(null, $payment->gst_amount),
                'tds_amount' => Money::sub(null, $payment->tds_amount),
                'total_amount' => Money::sub(null, $payment->total_amount),
                'payable_amount' => Money::sub(null, $payment->payable_amount),

                'requested_date' => now()->toDateString(),
                'payment_date' => now()->toDateString(),
                'due_date' => $payment->due_date,

                'reverses_payment_id' => $payment->id,
                'reversal_reason' => $reason,
                'reversed_at' => now(),

                'accounts_bills' => $payment->accounts_bills,
                'added_user' => $addedUser,
                'expense_by' => $payment->expense_by,
            ]);

            // Mirror the bill grid with negated amounts.
            foreach ($payment->billPayments as $position => $row) {
                $reversal->billPayments()->create([
                    'bill_id' => $row->bill_id,
                    'bill_amount' => Money::sub(null, $row->bill_amount),
                    'position' => $position,
                ]);
            }

            /*
             * Mirror the split legs with negated amounts.
             *
             * THIS IS THE POINT OF THE WHOLE DESIGN. §5.2: an Expenses_Bills row IS
             * one split leg, and every villa-month-category figure downstream traces
             * back to one. A deleted payment makes those figures silently wrong,
             * because nothing records that the money came back. A negated leg makes
             * the correction visible in exactly the same place the original was.
             */
            foreach ($payment->splitPayments as $position => $leg) {
                $reversal->splitPayments()->create([
                    'villa_id' => $leg->villa_id,
                    'item_category_id' => $leg->item_category_id,
                    'billing_cycle_id' => $leg->billing_cycle_id,
                    'amount' => Money::sub(null, $leg->amount),
                    'total_amount' => Money::sub(null, $leg->total_amount),
                    'tds_amount' => Money::sub(null, $leg->tds_amount),
                    'gst_amount' => Money::sub(null, $leg->gst_amount),
                    'backend_amount' => Money::sub(null, $leg->backend_amount),
                    'backend_tds_amount' => Money::sub(null, $leg->backend_tds_amount),
                    'backend_gst_amount' => Money::sub(null, $leg->backend_gst_amount),
                    'percent' => $leg->percent,
                    'position' => $position,
                ]);
            }

            // The original is annotated, never mutated in substance: its number,
            // amounts and legs stay exactly as they were.
            $payment->reversed_at = now();
            $payment->save();

            return $reversal->fresh(['billPayments', 'splitPayments']);
        });
    }
}
