<?php

declare(strict_types=1);

namespace App\Domain\Bills;

/**
 * The Split_Payment grid: which combinations exist, and how money lands on them.
 *
 * Two behaviours, both of which §15.1 lists as "worth porting exactly".
 *
 * 1. RECONCILE, not clear-and-rebuild. Creator runs three workflows
 *    (OnInputVillasCE, OnInputBillingCycleCE, OnInputCategoryCE) that each do
 *    `input.Split_Payment.clear()` then rebuild the whole cross product. That
 *    destroys typed amounts. §5.1's rebuild requirement is the opposite:
 *    surviving combinations keep their amounts, new ones arrive blank, and a
 *    combination that no longer applies is dropped only if empty — if it carries
 *    money it is kept, flagged, and blocks save.
 *
 * 2. SPLIT EQUALLY with the remainder on the LAST row, exact to the paisa (§6.3).
 *    "Reproduce exactly. Do not substitute banker's rounding."
 */
final class SplitAllocator
{
    /**
     * The §5.1 degradation tiers:
     *
     *   villas + cycles + categories -> villa x cycle x category
     *   villas + cycles, no category -> villa x cycle
     *   villas only                  -> one row per villa
     *
     * Order is deterministic — villa, then cycle, then category — so positions are
     * stable across saves and a diff of the grid means something.
     *
     * @param  list<int>  $villaIds
     * @param  list<int>  $billingCycleIds
     * @param  list<int>  $itemCategoryIds
     * @return list<SplitLeg>
     */
    public function combinations(array $villaIds, array $billingCycleIds, array $itemCategoryIds): array
    {
        if ($villaIds === []) {
            return [];
        }

        $legs = [];

        foreach ($villaIds as $villaId) {
            if ($billingCycleIds === []) {
                // Tier 3 — villas only.
                $legs[] = new SplitLeg(villaId: $villaId);

                continue;
            }

            foreach ($billingCycleIds as $cycleId) {
                if ($itemCategoryIds === []) {
                    // Tier 2 — villa x cycle.
                    $legs[] = new SplitLeg(villaId: $villaId, billingCycleId: $cycleId);

                    continue;
                }

                // Tier 1 — the full cross product.
                foreach ($itemCategoryIds as $categoryId) {
                    $legs[] = new SplitLeg(
                        villaId: $villaId,
                        itemCategoryId: $categoryId,
                        billingCycleId: $cycleId,
                    );
                }
            }
        }

        return $legs;
    }

    /**
     * Reconcile the existing grid against a new scope.
     *
     * Returns every leg that should now be on the form, in combination order,
     * with orphaned-but-funded legs appended at the end carrying a flag. Those
     * flagged legs are what block save — the caller asks hasBlockingFlags().
     *
     * @param  list<SplitLeg>  $existing
     * @param  list<int>  $villaIds
     * @param  list<int>  $billingCycleIds
     * @param  list<int>  $itemCategoryIds
     * @return list<SplitLeg>
     */
    public function reconcile(
        array $existing,
        array $villaIds,
        array $billingCycleIds,
        array $itemCategoryIds,
    ): array {
        $byKey = [];

        foreach ($existing as $leg) {
            $byKey[$leg->key()] = $leg;
        }

        $target = $this->combinations($villaIds, $billingCycleIds, $itemCategoryIds);
        $targetKeys = [];
        $result = [];

        foreach ($target as $leg) {
            $targetKeys[$leg->key()] = true;

            // A surviving combination keeps its money. A new one stays blank.
            $result[] = isset($byKey[$leg->key()])
                ? $leg->withMoneyFrom($byKey[$leg->key()])
                : $leg;
        }

        // Combinations that no longer apply: drop the empty ones, keep and flag
        // the funded ones. Dropping a funded leg silently would lose money that a
        // person typed, which is the whole point of §5.1's rebuild requirement.
        foreach ($existing as $leg) {
            if (isset($targetKeys[$leg->key()])) {
                continue;
            }

            if ($leg->carriesMoney()) {
                $result[] = $leg->flag('combination no longer in scope but carries an amount');
            }
        }

        return $result;
    }

    /** @param  list<SplitLeg>  $legs */
    public function hasBlockingFlags(array $legs): bool
    {
        foreach ($legs as $leg) {
            if ($leg->flagged) {
                return true;
            }
        }

        return false;
    }

    /** @param  list<SplitLeg>  $legs */
    public function total(array $legs): string
    {
        $total = Money::zero();

        foreach ($legs as $leg) {
            $total = Money::add($total, $leg->amount);
        }

        return $total;
    }

    /**
     * Split equally across the legs, remainder on the last row (§6.3):
     *
     *   perAmt = Amount / rowCount ;  perGST = GST_Amount / rowCount
     *   rows 1..n-1:  Amount = perAmt          GST_Amount = perGST
     *   row n:        Amount = Amount - Sum(prev)   GST_Amount = GST_Amount - Sum(prev)
     *   every row:    TDS_Amount   = row.Amount x tdsPct / 100
     *                 Total_Amount = row.Amount + row.GST_Amount - row.TDS_Amount
     *
     * Note the TDS is computed per row from that row's amount, so the sum of the
     * per-row TDS need not equal a TDS computed on the bill total. That is the
     * documented behaviour and it is reproduced, not corrected.
     *
     * Flagged legs are excluded from the distribution — they are out of scope and
     * only present to block the save.
     *
     * @param  list<SplitLeg>  $legs
     * @return list<SplitLeg>
     */
    public function splitEqually(
        array $legs,
        ?string $amount,
        ?string $gstAmount = null,
        ?string $tdsPercentage = null,
    ): array {
        $distributable = [];
        $passthrough = [];

        foreach ($legs as $index => $leg) {
            if ($leg->flagged) {
                $passthrough[$index] = $leg;
            } else {
                $distributable[$index] = $leg;
            }
        }

        $count = count($distributable);

        if ($count === 0) {
            return $legs;
        }

        $perAmount = Money::divideTruncated($amount, $count);
        $perGst = Money::divideTruncated($gstAmount, $count);

        $allocatedAmount = Money::zero();
        $allocatedGst = Money::zero();

        $result = $legs;
        $position = 0;

        foreach ($distributable as $index => $leg) {
            $position++;
            $isLast = $position === $count;

            if ($isLast) {
                // The remainder — whatever truncation dropped lands here.
                $rowAmount = Money::sub($amount, $allocatedAmount);
                $rowGst = Money::sub($gstAmount, $allocatedGst);
            } else {
                $rowAmount = $perAmount;
                $rowGst = $perGst;
                $allocatedAmount = Money::add($allocatedAmount, $rowAmount);
                $allocatedGst = Money::add($allocatedGst, $rowGst);
            }

            $rowTds = $tdsPercentage === null || $tdsPercentage === ''
                ? Money::zero()
                : Money::percentageOf($rowAmount, $tdsPercentage);

            $rowTotal = Money::sub(Money::add($rowAmount, $rowGst), $rowTds);

            $result[$index] = new SplitLeg(
                villaId: $leg->villaId,
                itemCategoryId: $leg->itemCategoryId,
                billingCycleId: $leg->billingCycleId,
                amount: $rowAmount,
                gstAmount: $rowGst,
                tdsAmount: $rowTds,
                totalAmount: $rowTotal,
                flagged: $leg->flagged,
                flagReason: $leg->flagReason,
            );
        }

        foreach ($passthrough as $index => $leg) {
            $result[$index] = $leg;
        }

        return array_values($result);
    }
}
