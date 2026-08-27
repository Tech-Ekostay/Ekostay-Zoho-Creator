<?php

declare(strict_types=1);

namespace App\Domain\Approvals;

use App\Models\Approval;
use App\Models\Payment;

/**
 * Which approvers does this payment have to visit, and in what order?
 *
 * Transcribed from `Accounts.ds:16054-16112`. The algorithm is short and every line
 * of it matters, so it is reproduced rather than reinterpreted:
 *
 *   1. amount = Invoice_Amount; if 0, the sum of Bill_Payments.Bill_Amount
 *   2. targetLevel = the level with the GREATEST Minimum_Amount still <= amount
 *   3. expand targetLevel into the chain actually walked:
 *        Level 1 -> [L1]
 *        Level 2 -> lvl12 == "ALL" ? [L1, L2] : [L2]
 *        Level 3 -> lvl23 == "ANY" ? [L3] : (lvl12 == "ALL" ? [L1,L2,L3] : [L2,L3])
 *
 * ---------------------------------------------------------------------------
 * FOUR THINGS PRESERVED THAT A TIDY-UP WOULD DESTROY:
 *
 *  1. **`Maximum_Amount` is never consulted.** Only minimums route. The form even
 *     maintains the bands as contiguous (`row.Minimum_Amount = previous
 *     Maximum_Amount + 1`), which makes the upper bound look load-bearing. It is not.
 *
 *  2. **The two pairings are tested in opposite senses.** Level 2 asks
 *     `lvl12 == "ALL"`; Level 3 asks `lvl23 == "ANY"`. Reproduced. Making them
 *     consistent would change which approvers a payment visits, which is a policy
 *     change disguised as a refactor.
 *
 *  3. **An empty chain means no approval is required**, not an error. If every band's
 *     minimum exceeds the amount, `targetLevel` never gets set. The live data has
 *     `Approval Not Required` as a real status on exactly one payment, so this path
 *     is real, if rare — `ifnull(Minimum_Amount, 0)` makes an unset minimum match
 *     everything, which is why a Level 1 with no minimum absorbs almost all traffic.
 *
 *  4. **The chain is frozen when it is issued, not recomputed on each approval.**
 *     Creator recomputes it every time, reading the rule fresh. That is a defect of
 *     the §14 family: editing a rule mid-flight silently re-decides an approval
 *     already under way, and an approver at Level 2 can find the ground moved.
 *     `PendingApproval.chain` stores the expanded list instead. **Logged as a
 *     deviation** — it is the one place here that does not reproduce Creator.
 *
 * ---------------------------------------------------------------------------
 * IT REFUSES RATHER THAN GUESSES. `All_Approvals` exports only the rule headers —
 * its `Approvers` column is the literal string "Level 1,Level 2", which names the
 * levels and nothing else. The amount bands and approver identities are in the
 * Approval form's subform grid and are in no export we hold. So where a level has no
 * `minimum_amount`, this returns an `unroutable` result naming what is missing.
 * Treating a null band as zero would route every payment to whichever level happened
 * to sort last, and it would look like it was working.
 */
final class ApprovalRouter
{
    /**
     * @return array{
     *     routable: bool,
     *     reason: ?string,
     *     approval_id: ?int,
     *     amount: string,
     *     target_level: ?string,
     *     chain: list<string>,
     * }
     */
    public function route(Payment $payment): array
    {
        $amount = $this->approvalAmount($payment);
        $approval = $this->matchRule($payment);

        if ($approval === null) {
            return $this->unroutable(
                $amount,
                'No approval rule matches this payment. Creator returns early when '
                .'`fetapproval.ID == null` (Accounts.ds:16050), which leaves the payment '
                .'where it is rather than approving it.',
            );
        }

        $levels = $approval->levels->sortBy('position')->values();

        if ($levels->isEmpty()) {
            return $this->unroutable(
                $amount,
                sprintf(
                    'Approval rule #%d has no levels. The `All_Approvals` export carries only '
                    .'rule headers, so the Approvers grid — levels, amount bands and approver '
                    .'identities — has never been exported. A form-level export of the Approval '
                    .'form is what fills this in.',
                    $approval->id,
                ),
                $approval->id,
            );
        }

        // The bands are what routing needs, and they are exactly what is missing.
        if ($levels->every(fn ($l): bool => $l->minimum_amount === null)) {
            return $this->unroutable(
                $amount,
                sprintf(
                    'Approval rule #%d has %d level(s) but no amount bands. Routing picks the '
                    .'level with the greatest Minimum_Amount at or below the payment amount, so '
                    .'with every band unknown there is nothing to pick. Treating null as zero '
                    .'would route everything to whichever level sorted last and would look '
                    .'like it was working.',
                    $approval->id,
                    $levels->count(),
                ),
                $approval->id,
            );
        }

        // Step 2 — greatest minimum still at or below the amount. `maxMatchAmount`
        // starts at -1 so a band of exactly 0 can win.
        $targetLevel = null;
        $best = '-1';

        foreach ($levels as $level) {
            $min = $level->minimum_amount ?? '0';        // ifnull(Minimum_Amount, 0)

            if (bccomp($min, $amount, 4) <= 0 && bccomp($min, $best, 4) > 0) {
                $best = $min;
                $targetLevel = $level->level;
            }
        }

        return [
            'routable' => true,
            'reason' => null,
            'approval_id' => $approval->id,
            'amount' => $amount,
            'target_level' => $targetLevel,
            'chain' => $this->expand($targetLevel, $approval),
        ];
    }

    /**
     * Step 3 — expand the target level into the chain actually walked.
     *
     * The asymmetry between the two tests is Creator's; see the class docblock.
     *
     * @return list<string>
     */
    public function expand(?string $targetLevel, Approval $approval): array
    {
        // ifnull(...,"").trim().toUpperCase()
        $lvl12 = strtoupper(trim((string) $approval->level_1_2_approval));
        $lvl23 = strtoupper(trim((string) $approval->level_2_3_approval));

        return match ($targetLevel) {
            'Level 1' => ['Level 1'],
            'Level 2' => $lvl12 === 'ALL' ? ['Level 1', 'Level 2'] : ['Level 2'],
            'Level 3' => $lvl23 === 'ANY'
                ? ['Level 3']
                : ($lvl12 === 'ALL' ? ['Level 1', 'Level 2', 'Level 3'] : ['Level 2', 'Level 3']),
            // No band matched — no approval required. See docblock point 3.
            default => [],
        };
    }

    /**
     * The next level after the current one, or null when the chain is exhausted.
     *
     * Null is the signal to finalise: Creator sets Status = "Approved",
     * Approved = true and Next_Level_Approval_Required = false at this point
     * (Accounts.ds:16129-16135).
     *
     * @param  list<string>  $chain
     */
    public function nextLevel(array $chain, ?string $currentLevel): ?string
    {
        $index = array_search($currentLevel, $chain, true);

        if ($index === false) {
            return null;
        }

        return $chain[$index + 1] ?? null;
    }

    /**
     * What the routing decision is made on.
     *
     * `Invoice_Amount` first, falling back to the sum of the Bill_Payments subform.
     * Note this is INVOICE, not gross and not payable — a distinction that matters
     * because §6.3 has two formulas under the name Payable and neither is this one.
     */
    public function approvalAmount(Payment $payment): string
    {
        $invoice = $payment->total_amount;

        if ($invoice !== null && bccomp((string) $invoice, '0', 4) !== 0) {
            return bcadd((string) $invoice, '0', 4);
        }

        $sum = '0.0000';

        foreach ($payment->billPayments as $row) {
            $sum = bcadd($sum, (string) ($row->bill_amount ?? '0'), 4);
        }

        return $sum;
    }

    /**
     * Find the rule governing this payment.
     *
     * Matching is on the comma-packed scope columns, so it is a parse rather than a
     * `split(',')` — the live strings carry inconsistent spacing (` Casa Bella`),
     * which is where the leading-space names in §3 come from.
     *
     * WHAT IS NOT IMPLEMENTED HERE, and is flagged rather than approximated: the
     * `scope_type` Include/Exclude radio and `exclude_categories`. Both exist on the
     * form; how they compose with `item_categories` when several rules match is not
     * established, and §3.1 already warns against implementing all of the
     * category-scoping mechanisms at once. Where more than one rule matches, the
     * most specific by item category wins and the choice is deterministic — but it
     * is OURS, not verified against Creator.
     */
    public function matchRule(Payment $payment): ?Approval
    {
        $itemCategory = $payment->itemCategory?->name;
        $location = $payment->location?->name;

        $candidates = Approval::query()->with('levels')->get()->filter(
            fn (Approval $rule): bool => $rule->coversModule('Payment')
                && $rule->coversLocation($location)
                && $rule->coversItemCategory($itemCategory)
        );

        // Most specific first: fewest item categories listed = tightest rule.
        return $candidates
            ->sortBy(fn (Approval $rule): int => count($rule->itemCategoryList()))
            ->first();
    }

    /** @return array<string, mixed> */
    private function unroutable(string $amount, string $reason, ?int $approvalId = null): array
    {
        return [
            'routable' => false,
            'reason' => $reason,
            'approval_id' => $approvalId,
            'amount' => $amount,
            'target_level' => null,
            'chain' => [],
        ];
    }
}
