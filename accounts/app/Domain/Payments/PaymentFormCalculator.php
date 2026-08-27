<?php

declare(strict_types=1);

namespace App\Domain\Payments;

use App\Domain\Bills\Money;

/**
 * The Payment form's live arithmetic — Creator's `on user input` handlers.
 *
 * WHAT THIS IS. Picking a TDS rate on the Payment form does not just store a rate:
 * it recomputes TDS Amount, Invoice Amount and Payable Amount, and then rewrites
 * every split leg. Same for GST and for Gross Amount. None of that existed here, so
 * the form stored what you typed and computed nothing — which is what "the
 * validations are not working" meant.
 *
 * Transcribed from `Accounts.ds`:
 *   OnInputTDSCE          23348-23404
 *   OnInputGrossAmountCE  23148-23252
 *   OnInputGSTCE          25157
 *   OnInputPFCE           23303   (the PF/PT/ESIC bound)
 *
 * All three amount handlers agree on the arithmetic, which is worth knowing: it can
 * be implemented once rather than per trigger.
 *
 *     tdsAmt          = Amount x TDS%  / 100
 *     gstAmt          = Amount x GST%  / 100          (a rate; else the typed GST Amount)
 *     Invoice_Amount  = Amount + gstAmt
 *     Payable_Amount  = Invoice_Amount - tdsAmt
 *
 * and per split leg:
 *
 *     leg.TDS_Amount   = leg.Amount x TDS% / 100
 *     leg.GST_Amount   = leg.Amount x GST% / 100
 *     leg.Total_Amount = leg.Amount + leg.GST_Amount - leg.TDS_Amount
 *
 * Note the per-leg TDS is computed from the LEG, not apportioned from the header —
 * so per-row TDS need not sum to TDS on the total, exactly as §6.3 warns.
 *
 * ---------------------------------------------------------------------------
 * A LIVE DEFECT, REPRODUCED DELIBERATELY AND LOGGED. The DS guard reads:
 *
 *     if (input.Amount != null && COA.Account_Name != "Accounts Payable"
 *         || Item_Category != "F&B SALARY" || Item_Category != "STAFF SALARY")
 *
 * `A != X || A != Y` is ALWAYS TRUE — a value cannot fail to differ from two
 * different strings. So the first branch always executes and the `else if` is dead
 * code. That dead branch is the SALARY path, and it is the only one that deducts
 * PF, PT and ESIC from Payable:
 *
 *     Invoice_Amount = Amount - tdsAmt - PF - PT - ESI + GST_Amount
 *
 * So in live Creator, **salary payments never have PF, PT or ESIC deducted** — the
 * fields are captured, validated, and then ignored by the only formula that would
 * have used them. Same shape as the `&&`-for-`||` defect already on the register.
 *
 * This class reproduces the live behaviour by default, because the figures in the
 * database were produced by it and a "corrected" form would silently disagree with
 * 52,638 existing payments. `$applySalaryDeductions` opts into the intended
 * behaviour, and the result reports which path ran so a caller can surface it. That
 * is D7 on the deviation register — offered, not silently applied.
 */
final class PaymentFormCalculator
{
    /** Item categories the dead salary branch was written for. */
    public const SALARY_CATEGORIES = ['F&B SALARY', 'STAFF SALARY'];

    /**
     * @param  array<string, mixed>  $input  amount, gst_amount, tds_percentage,
     *                                       gst_percentage, pt, esic, pf,
     *                                       item_category, coa_account
     * @param  list<array<string, mixed>>  $legs  each with an `amount`
     * @return array{
     *     tds_amount: string, gst_amount: string, total_amount: string,
     *     payable_amount: string, legs: list<array<string, string>>,
     *     salary_path: bool, warnings: list<string>,
     * }
     */
    public function __invoke(array $input, array $legs = [], bool $applySalaryDeductions = false): array
    {
        $amount = Money::normalise((string) ($input['amount'] ?? '0'));
        $tdsPct = (string) ($input['tds_percentage'] ?? '0');
        $gstPct = $input['gst_percentage'] ?? null;

        $tdsAmount = Money::percentageOf($amount, $tdsPct);

        /*
         * A GST RATE OVERRIDES A TYPED GST AMOUNT, which is what the DS does: when
         * `input.GST` is set it computes gstAmt from the rate, otherwise it uses
         * `input.GST_Amount` as typed. This is the `GST Type` picklist in effect —
         * `Predefined GST` versus `Enter Manully` (Creator's spelling).
         */
        $gstAmount = $gstPct !== null && $gstPct !== ''
            ? Money::percentageOf($amount, (string) $gstPct)
            : Money::normalise((string) ($input['gst_amount'] ?? '0'));

        $isSalary = in_array(trim((string) ($input['item_category'] ?? '')), self::SALARY_CATEGORIES, true);
        $useSalaryPath = $isSalary && $applySalaryDeductions;

        $warnings = [];

        if ($useSalaryPath) {
            // The INTENDED salary formula from the dead `else if`.
            $invoice = Money::normalise($amount);
            $invoice = Money::sub($invoice, $tdsAmount);
            foreach (['pf', 'pt', 'esic'] as $key) {
                $invoice = Money::sub($invoice, Money::normalise((string) ($input[$key] ?? '0')));
            }
            $invoice = Money::add($invoice, $gstAmount);
            $payable = $invoice;
        } else {
            // Live behaviour: the branch that always executes.
            $invoice = Money::add($amount, $gstAmount);
            $payable = Money::sub($invoice, $tdsAmount);

            if ($isSalary) {
                $warnings[] = 'This is a salary category, and PF / PT / ESIC are NOT deducted — '
                    .'reproducing live Creator, where the branch that would deduct them is dead '
                    .'code (`A != X || A != Y` is always true). The values are stored but do not '
                    .'affect Payable.';
            }
        }

        /*
         * §6.3's PF bound, from OnInputPFCE: `PF cannot be Greater than
         * Amount - PT - ESI`. A warning rather than a refusal, because the DS raises
         * it as an `alert` on the browser side and nothing enforces it on save — so
         * refusing here would reject rows Creator accepts.
         */
        $pf = Money::normalise((string) ($input['pf'] ?? '0'));
        $room = Money::sub(
            Money::sub($amount, Money::normalise((string) ($input['pt'] ?? '0'))),
            Money::normalise((string) ($input['esic'] ?? '0')),
        );

        if (bccomp($pf, $room, 4) > 0) {
            $warnings[] = sprintf('PF cannot be greater than %s (Gross - PT - ESIC).', $room);
        }

        return [
            'tds_amount' => $tdsAmount,
            'gst_amount' => $gstAmount,
            'total_amount' => $invoice,        // `Invoice_Amount` on the form
            'payable_amount' => $payable,
            'legs' => $this->recomputeLegs($legs, $tdsPct, $gstPct),
            'salary_path' => $useSalaryPath,
            'warnings' => $warnings,
        ];
    }

    /**
     * Rewrite each split leg from its own amount.
     *
     * PER-LEG, NOT APPORTIONED. The DS computes `rec.TDS_Amount = rec.Amount x
     * tdsPct / 100` from the leg, so the legs' TDS need not sum to the header's —
     * §6.3 says so explicitly and it is not an error to be smoothed away.
     *
     * @param  list<array<string, mixed>>  $legs
     * @return list<array<string, string>>
     */
    public function recomputeLegs(array $legs, string $tdsPct, ?string $gstPct): array
    {
        $out = [];

        foreach ($legs as $leg) {
            $legAmount = Money::normalise((string) ($leg['amount'] ?? '0'));
            $legTds = Money::percentageOf($legAmount, $tdsPct);
            $legGst = $gstPct !== null && $gstPct !== ''
                ? Money::percentageOf($legAmount, (string) $gstPct)
                : Money::normalise((string) ($leg['gst_amount'] ?? '0'));

            $out[] = [
                'amount' => $legAmount,
                'tds_amount' => $legTds,
                'gst_amount' => $legGst,
                // Amount + GST - TDS. Matches TestBillSeeder's per-leg total.
                'total_amount' => Money::sub(Money::add($legAmount, $legGst), $legTds),
            ];
        }

        return $out;
    }

    /*
     * NO LOCAL PERCENTAGE HELPER. `Money::percentageOf()` already does exactly this
     * — multiply at extra scale, divide by 100, quantise at DIVISION_SCALE — and it
     * is the same function the bill split uses. A second implementation here would
     * be a second place for the rounding to drift.
     */
}
