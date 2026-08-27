<?php

declare(strict_types=1);

namespace App\Domain\Payments;

use App\Domain\Bills\Money;

/**
 * The payable amount of one split leg — and the §7.2 sign bug.
 *
 * WHAT CREATOR DOES. Accounts.ds:45452, inside Create_Payment, for each leg of a
 * bill whose Status is "Partially Paid":
 *
 *     Totalamount = ifnull(rec.Backend_Total_Amount,0);
 *     tdsamount   = ifnull(rec.Backend_TDS_Amount,0);
 *     gstamount   = ifnull(rec.Backend_GST_Amount,0);
 *     payamount   = Totalamount - gstamount + tdsamount;   // <-- TDS ADDED
 *
 * and otherwise just copies the stored rec.Amount through.
 *
 * WHY THAT IS WRONG. The bill-level formula at Accounts.ds:22489-22490 is
 *
 *     Total_Amount   = Amount + GST_Amount
 *     Payable_Amount = InvoiceAmount - TDSTotal        where InvoiceAmount is the
 *                                                      sum of leg Total_Amounts
 *
 * so the normal payable is `Amount + GST - TDS`. Substituting Total = Amount + GST
 * into Creator's partially-paid line gives `Amount + TDS`. The two differ by
 *
 *     (Amount + TDS) - (Amount + GST - TDS)  =  2*TDS - GST
 *
 * TDS is withholding — money kept back from the vendor and remitted to the
 * department. Adding it pays the vendor the tax as well as the invoice. For a
 * TDS-only vendor with no GST the overpayment is exactly twice the TDS.
 *
 * THE DECISION. Husain chose "fix both, log the deviation" on 22-Aug-2026. So
 * `partiallyPaid()` deducts TDS like the forward path, and `creatorPartiallyPaid()`
 * is kept beside it — not as a fallback, but so a test can assert the delta is
 * exactly `2*TDS - GST` and so a reconciliation against live Creator figures can
 * explain any difference it finds. Nothing in the write path calls it.
 *
 * Recorded as deviation D1 in ACCOUNTS_CONTEXT_ADDENDUM.md.
 */
final class PayableFormula
{
    /**
     * The forward path: gross plus output tax, less withholding.
     *
     * This is `Amount + GST - TDS` written against a leg's own figures, which is
     * the same quantity the bill-level formula produces — see the docblock.
     */
    public static function forward(?string $totalAmount, ?string $tdsAmount): string
    {
        return Money::sub($totalAmount, $tdsAmount);
    }

    /**
     * The partially-paid path, CORRECTED.
     *
     * Reads the backend triplet — addendum §10 settles that those are the
     * allocation snapshot taken while nothing is paid, and §7.2 says they are the
     * figures a partially-paid bill is read from. That part of Creator is right;
     * only the sign was wrong.
     */
    public static function partiallyPaid(?string $backendTotal, ?string $backendTds): string
    {
        return Money::sub($backendTotal, $backendTds);
    }

    /**
     * Creator's partially-paid line, reproduced verbatim for comparison only.
     *
     * NOT USED BY THE WRITE PATH. Present so PaymentSignConventionTest can assert
     * the exact divergence, and so anyone reconciling against live Creator data
     * can compute what Creator would have written.
     */
    public static function creatorPartiallyPaid(
        ?string $backendTotal,
        ?string $backendGst,
        ?string $backendTds,
    ): string {
        return Money::add(Money::sub($backendTotal, $backendGst), $backendTds);
    }

    /**
     * How far Creator's figure overshoots the corrected one: `2*TDS - GST`.
     *
     * Derived in the docblock rather than by subtracting the two functions, so the
     * test proves the algebra instead of restating it.
     */
    public static function creatorOvercharge(?string $backendGst, ?string $backendTds): string
    {
        return Money::sub(Money::add($backendTds, $backendTds), $backendGst);
    }
}
