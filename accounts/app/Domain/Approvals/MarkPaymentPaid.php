<?php

declare(strict_types=1);

namespace App\Domain\Approvals;

use App\Domain\Payments\PaymentStatus;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * `Pay` — the last hop, and the one the screenshots pinned down.
 *
 * The All Pending Approvals index carries a `Pay` button per row, and its state is
 * the rule: on the five `Sent for Approval` rows it renders PALE (disabled); on the
 * four `Approved` rows it renders SOLID (enabled). So Pay is gated on approval, and
 * that is read off the screenshot rather than assumed.
 *
 * Approve and Reject render pale on every visible row — presumably because the
 * signed-in user is not the named approver — which is the same shape of rule and the
 * reason this app disables a control with a reason rather than hiding it.
 *
 * ---------------------------------------------------------------------------
 * WHAT `Paid` MEANS HERE. Two status axes exist and both move (§7.3):
 *
 *     Status          -> "Paid"
 *     Payment Status  -> "paid"     lowercase, as the picklist declares it
 *
 * The lowercase is not a slip. `PaymentStatus::PS_PAID` is `'paid'`, it is what the
 * DS picklist contains, and 36,586 imported payments carry `Paid` while 4 carry
 * `paid`. Normalising on write would make this app the only writer producing a
 * spelling Creator does not.
 *
 * NO MONEY MOVES. This records that a payment was made; it does not make one. There
 * is no bank integration here and §16's Books push is explicitly out of scope for the
 * first pass, so `Pay` is a state change plus a date — and the date is the one
 * §6.3-adjacent field that matters downstream, because reports bucket by it.
 */
final class MarkPaymentPaid
{
    /**
     * @param  string|null  $paymentDate  when money actually moved. Defaults to today.
     *                                    Kept separate from `Status = Paid` because §6
     *                                    records that reports bucketing by payment date
     *                                    and by billing month never reconcile, and
     *                                    conflating them is how that starts.
     */
    public function __invoke(Payment $payment, ?string $paymentDate = null): Payment
    {
        return DB::transaction(function () use ($payment, $paymentDate): Payment {
            $payment->refresh()->load('pendingApprovals');

            /*
             * THE GATE, from the screenshots: Pay is live only on an Approved row.
             *
             * Checked through statusIs() rather than a bare ===, because addendum §10
             * records Creator disagreeing with itself on status casing and an equality
             * test silently misses part of the data.
             */
            if (! $payment->statusIs(PaymentStatus::APPROVED)) {
                throw new RuntimeException(sprintf(
                    'Only an Approved payment can be paid. This one is "%s". On the live report '
                    .'the Pay button is disabled on every row that is not Approved.',
                    $payment->status ?? 'unset',
                ));
            }

            if ($payment->statusIs(PaymentStatus::PAID)) {
                throw new RuntimeException(
                    'This payment is already Paid. Paying twice is not an idempotent no-op — it '
                    .'would move the payment date and misstate when money left.'
                );
            }

            // Both axes, per §7.3. The lowercase `paid` is the picklist's.
            $payment->status = PaymentStatus::PAID;
            $payment->payment_status = PaymentStatus::PS_PAID;
            $payment->payment_date = $paymentDate ?? now()->toDateString();
            $payment->save();

            /*
             * The pending record follows. Creator shows `Payment Status` on the pending
             * row beside `Status`, and a paid payment whose approval record still reads
             * Approved would make the report disagree with itself.
             */
            foreach ($payment->pendingApprovals as $pending) {
                $pending->payment_status = PaymentStatus::PAID;
                $pending->save();
            }

            return $payment->fresh();
        });
    }
}
