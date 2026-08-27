<?php

declare(strict_types=1);

namespace App\Domain\Bills;

/**
 * One Split_Payment leg — a villa x item category x billing cycle combination and
 * the money allocated to it.
 *
 * §5 is blunt about what this is: "An Expenses_Bills row IS one Split_Payments
 * leg, materialised." So a leg is a ledger row in waiting, and §5.2 adds that
 * every villa-month-category figure in the downstream expense-control tool traces
 * back to one of these. This is where attribution is decided.
 *
 * Amounts are decimal STRINGS, never floats. All arithmetic goes through bcmath.
 * §15.2 records 18-digit ids corrupted by float(); money deserves the same care,
 * and the split-equally remainder rule (§6.3) is only exact if nothing rounds
 * behind our back.
 *
 * Any component of the triple may be null: §5.1's degradation tiers produce
 * villa-only and villa x cycle legs as well as the full cross product.
 */
final readonly class SplitLeg
{
    public function __construct(
        public ?int $villaId = null,
        public ?int $itemCategoryId = null,
        public ?int $billingCycleId = null,
        public ?string $amount = null,
        public ?string $gstAmount = null,
        public ?string $tdsAmount = null,
        public ?string $totalAmount = null,
        public bool $flagged = false,
        public ?string $flagReason = null,
    ) {}

    /**
     * The combination identity. Reconciliation matches on this and nothing else —
     * position and money are not part of a leg's identity.
     */
    public function key(): string
    {
        return implode('|', [
            $this->villaId ?? '-',
            $this->itemCategoryId ?? '-',
            $this->billingCycleId ?? '-',
        ]);
    }

    /**
     * Does this leg carry money?
     *
     * The reconcile rule in §5.1 turns on exactly this question: a combination
     * that no longer applies is dropped only if empty — if it carries money it is
     * kept, flagged, and blocks save. Zero counts as empty; a typed 0 is not an
     * allocation.
     */
    public function carriesMoney(): bool
    {
        foreach ([$this->amount, $this->gstAmount, $this->tdsAmount, $this->totalAmount] as $value) {
            if ($value !== null && $value !== '' && bccomp($value, '0', Money::SCALE) !== 0) {
                return true;
            }
        }

        return false;
    }

    public function withMoneyFrom(self $other): self
    {
        return new self(
            villaId: $this->villaId,
            itemCategoryId: $this->itemCategoryId,
            billingCycleId: $this->billingCycleId,
            amount: $other->amount,
            gstAmount: $other->gstAmount,
            tdsAmount: $other->tdsAmount,
            totalAmount: $other->totalAmount,
            flagged: $this->flagged,
            flagReason: $this->flagReason,
        );
    }

    public function flag(string $reason): self
    {
        return new self(
            villaId: $this->villaId,
            itemCategoryId: $this->itemCategoryId,
            billingCycleId: $this->billingCycleId,
            amount: $this->amount,
            gstAmount: $this->gstAmount,
            tdsAmount: $this->tdsAmount,
            totalAmount: $this->totalAmount,
            flagged: true,
            flagReason: $reason,
        );
    }
}
