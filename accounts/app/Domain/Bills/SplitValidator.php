<?php

declare(strict_types=1);

namespace App\Domain\Bills;

/**
 * §6.4 rule 1 — the split total must tie to the bill's gross amount.
 *
 * The Deluge original:
 *
 *   totAmount = Sum(Split_Payment.Amount)
 *   if (totAmount.round(0) != input.Amount.round(0))
 *       alert "Total Amount is X but split amount is Y..."; cancel submit;
 *
 * THE COMPARISON IS AT WHOLE RUPEES. That is reproduced faithfully in
 * passesCreatorRule(), because rejecting a bill Creator would have accepted would
 * block real work on import. But it is a genuine hole and it is surfaced
 * separately rather than hidden:
 *
 * A split can differ from the gross by up to 49 paise per bill and still save.
 * §5.2 explains why that is worse than it sounds — Expenses_Bills is the flattened
 * ledger the downstream expense-control tool syncs, and this grid is the only
 * place attribution is decided. Sub-rupee drift here is silent, permanent, and
 * accumulates across every bill.
 *
 * Recommended posture, per the handoff's rule on additions: keep Creator's rule as
 * the hard gate so nothing that used to save now fails, and surface an exact
 * mismatch as a warning. That is "surfacing an existing rule earlier", which §2 of
 * the handoff explicitly allows — it is not a redesign.
 *
 * Note this validator does NOT check that per-row total_amount is internally
 * consistent, and Creator does not either. §7.4 records that Payments has no split
 * balance check at all, which is the same hole one stage downstream.
 */
final class SplitValidator
{
    public function __construct(private readonly SplitAllocator $allocator = new SplitAllocator) {}

    /** @param  list<SplitLeg>  $legs */
    public function passesCreatorRule(array $legs, ?string $grossAmount): bool
    {
        return Money::equalsAtRupees($this->allocator->total($legs), $grossAmount);
    }

    /**
     * The residual Creator's round(0) comparison tolerates. Zero means the split
     * ties exactly; anything else is real money that has no home.
     *
     * @param  list<SplitLeg>  $legs
     */
    public function residual(array $legs, ?string $grossAmount): string
    {
        return Money::sub($grossAmount, $this->allocator->total($legs));
    }

    /** @param  list<SplitLeg>  $legs */
    public function tiesExactly(array $legs, ?string $grossAmount): bool
    {
        return Money::isZero($this->residual($legs, $grossAmount));
    }

    /**
     * A split that Creator would accept but which does not actually tie. This is
     * the warning case — never a hard failure, or bills that save today would stop.
     *
     * @param  list<SplitLeg>  $legs
     */
    public function exactMismatch(array $legs, ?string $grossAmount): bool
    {
        return $this->passesCreatorRule($legs, $grossAmount)
            && ! $this->tiesExactly($legs, $grossAmount);
    }

    /**
     * Everything blocking a save, in the order a person would want to read it.
     *
     * @param  list<SplitLeg>  $legs
     * @return list<string>
     */
    public function blockingErrors(array $legs, ?string $grossAmount): array
    {
        $errors = [];

        if ($this->allocator->hasBlockingFlags($legs)) {
            $errors[] = 'A split row is out of scope but still carries an amount. '
                .'Clear it or restore the villa, cycle or category it belongs to.';
        }

        if (! $this->passesCreatorRule($legs, $grossAmount)) {
            $errors[] = sprintf(
                'Total Amount is %s but split amount is %s. Please match the total amount in split payment.',
                Money::normalise($grossAmount),
                $this->allocator->total($legs),
            );
        }

        return $errors;
    }

    /**
     * @param  list<SplitLeg>  $legs
     * @return list<string>
     */
    public function warnings(array $legs, ?string $grossAmount): array
    {
        if (! $this->exactMismatch($legs, $grossAmount)) {
            return [];
        }

        return [sprintf(
            'The split is short by %s. Creator accepts this because it compares whole rupees, '
            .'but the difference reaches the ledger unattributed.',
            $this->residual($legs, $grossAmount),
        )];
    }
}
