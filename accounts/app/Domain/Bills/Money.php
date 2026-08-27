<?php

declare(strict_types=1);

namespace App\Domain\Bills;

/**
 * Fixed-scale decimal arithmetic for money. bcmath only — no floats anywhere.
 *
 * SCALE is 4 because that is the column scale, but PAISA is 2 and that is the
 * scale the split-equally rule divides at: §6.3 and §15.1 both say the remainder
 * lands on the last row "to the paisa".
 *
 * [TODO] Addendum §5 records `Gross Amount` printing at THREE decimals in at
 * least two live places — the Payments split grid and All Pending Approvals. If
 * the stored values genuinely carry three decimals rather than that being a
 * display artefact, DIVISION_SCALE becomes 3 and the split-equally expectations
 * move with it. Worth settling from live data before Payments is built, because
 * §7.4 says Payments has no balance check at all today.
 */
final class Money
{
    /** Column scale. */
    public const SCALE = 4;

    /** The scale the split is divided at — paisa, per §6.3. */
    public const DIVISION_SCALE = 2;

    public static function normalise(?string $value): string
    {
        if ($value === null || $value === '') {
            return self::zero();
        }

        return bcadd($value, '0', self::SCALE);
    }

    public static function zero(): string
    {
        return bcadd('0', '0', self::SCALE);
    }

    public static function add(?string $a, ?string $b): string
    {
        return bcadd(self::normalise($a), self::normalise($b), self::SCALE);
    }

    public static function sub(?string $a, ?string $b): string
    {
        return bcsub(self::normalise($a), self::normalise($b), self::SCALE);
    }

    /** Multiply then round to a percentage-derived amount. */
    public static function percentageOf(?string $amount, ?string $percentage): string
    {
        $product = bcmul(self::normalise($amount), self::normalise($percentage), self::SCALE + 4);

        return self::quantise(bcdiv($product, '100', self::SCALE + 4), self::DIVISION_SCALE);
    }

    /**
     * Divide into equal parts at DIVISION_SCALE. TRUNCATES rather than rounds,
     * which is what makes the remainder-on-the-last-row rule exact: whatever the
     * truncation drops is exactly what the final row picks up.
     *
     * §6.3 is explicit — "Reproduce exactly. Do not substitute banker's rounding."
     */
    public static function divideTruncated(?string $amount, int $parts): string
    {
        if ($parts < 1) {
            return self::zero();
        }

        // bcdiv truncates at the given scale, which is the behaviour we want.
        return bcadd(bcdiv(self::normalise($amount), (string) $parts, self::DIVISION_SCALE), '0', self::SCALE);
    }

    public static function quantise(string $value, int $scale): string
    {
        return bcadd(bcadd($value, '0', $scale), '0', self::SCALE);
    }

    public static function equals(?string $a, ?string $b): bool
    {
        return bccomp(self::normalise($a), self::normalise($b), self::SCALE) === 0;
    }

    public static function isZero(?string $value): bool
    {
        return bccomp(self::normalise($value), '0', self::SCALE) === 0;
    }

    /**
     * Compare at whole rupees — `round(0)` in the Deluge original.
     *
     * §6.4's split-total check compares `totAmount.round(0) != input.Amount.round(0)`.
     * That is a real loosening and it is reproduced here deliberately, but see
     * SplitValidator::exactMismatch() for why it should not be the only check.
     */
    public static function equalsAtRupees(?string $a, ?string $b): bool
    {
        return bccomp(self::roundToRupees($a), self::roundToRupees($b), 0) === 0;
    }

    /** Half-up to whole rupees, matching Deluge's round(0). */
    public static function roundToRupees(?string $value): string
    {
        $value = self::normalise($value);
        $offset = bccomp($value, '0', self::SCALE) < 0 ? '-0.5' : '0.5';

        return bcadd(bcadd($value, $offset, self::SCALE), '0', 0);
    }
}
