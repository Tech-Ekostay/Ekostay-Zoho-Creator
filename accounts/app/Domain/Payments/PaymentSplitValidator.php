<?php

declare(strict_types=1);

namespace App\Domain\Payments;

use App\Domain\Bills\Money;

/**
 * The balance check Payments does not have — §7.4.
 *
 * §7.4 in full: "Bills enforces Sum(Split_Payment.Amount) == Amount. Payment does
 * NOT. Given §5.2 — expense rows are the split legs — an unbalanced payment
 * silently misstates every downstream villa-month-category figure. Add the check
 * server-side."
 *
 * That is what this is. It runs on the payment write path, not on the bill's.
 *
 * IT COMPARES EXACTLY, NOT AT WHOLE RUPEES. Bills' own check compares
 * `totAmount.round(0) != input.Amount.round(0)` (§6.4), and Money::equalsAtRupees
 * reproduces that loosening deliberately for Bills. It is NOT reproduced here.
 * Bills has an excuse — it is validating what a human typed into a grid. A payment
 * is generated from legs the system already computed, so any drift is arithmetic
 * error rather than typing, and rounding it away hides the bug that caused it.
 *
 * Recorded as deviation D2 in ACCOUNTS_CONTEXT_ADDENDUM.md.
 */
final class PaymentSplitValidator
{
    /**
     * @param  list<array{amount?: string|null}>  $legs
     * @return array{balanced: bool, sum: string, expected: string, difference: string}
     */
    public static function check(array $legs, ?string $expected): array
    {
        $sum = Money::zero();

        foreach ($legs as $leg) {
            $sum = Money::add($sum, $leg['amount'] ?? null);
        }

        $expected = Money::normalise($expected);

        return [
            'balanced' => Money::equals($sum, $expected),
            'sum' => $sum,
            'expected' => $expected,
            'difference' => Money::sub($sum, $expected),
        ];
    }

    /**
     * The message a rejected payment carries.
     *
     * It states both figures and the signed difference. §15.1's reconcile scenario
     * is the precedent: a validation message that says only "does not balance"
     * forces whoever hits it to recompute by hand.
     */
    public static function message(array $result): string
    {
        return sprintf(
            'Split legs total %s but the payment is %s — a difference of %s. '
            .'Per §7.4 an unbalanced payment misstates every downstream '
            .'villa-month-category figure, so the write is refused.',
            $result['sum'],
            $result['expected'],
            $result['difference'],
        );
    }
}
