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
 *     **VERIFIED 27-Aug-2026** from the Approvers grid: `Level 1` is `0 - 5,000` and
 *     `Level 2` is `5,001 - 50,00,00,000`. Contiguous, inclusive, and ₹50 crore is
 *     the sentinel ceiling. So greatest-minimum and read-the-maximum agree on every
 *     well-formed rule — which is exactly why ignoring the maximum has never shown
 *     up as a bug. It diverges only on a MALFORMED rule, so `bandWarnings()` now
 *     names gaps and overlaps rather than letting them route silently.
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
 *
 * ---------------------------------------------------------------------------
 * THE HEADER FIELDS ARE A BROWSER-SIDE MIRROR OF THE GRID — `Accounts.ds:38118-38146`.
 *
 * `Level_1_2_Approval` and `Level_2_3_Approval`, which step 3 routes on, are not
 * independently maintained. An `on user input of Approvers.Approval_Type` handler
 * copies the grid's value up to the header:
 *
 *     if (row.Level == "Level 2")  input.Level_1_2_Approval = row.Approval_Type;
 *     else if (row.Level == "Level 3") input.Level_2_3_Approval = row.Approval_Type;
 *
 * So the grid is the source and the header is the copy. And like §10's Block Payment
 * Date, that handler is **browser-side only**: it fires when a human changes the
 * field in the form and never for a record written by API, by script, or before the
 * handler existed. All 16 live rules show BOTH headers blank while the grid holds
 * `Any` on Level 2 — so in practice `lvl12` is never `"ALL"` and every Level 2
 * payment routes to `[Level 2]` alone, with Level 1 never participating.
 *
 * **This still routes on the header, as Creator does.** Reading the grid instead
 * would be more faithful to intent and would change who approves money, which is a
 * policy decision and not ours to take quietly. Instead `mirrorWarnings()` reports
 * when the two disagree — the case where Creator skips an approver the configuration
 * asked for. Surfaced, not silently corrected; the same choice `SplitValidator`
 * makes with its sub-rupee gap.
 *
 * ---------------------------------------------------------------------------
 * A NULL `Approval Type` ON LEVEL 1 IS CORRECT AND MUST NOT BE "FIXED".
 *
 * The same handler ends:
 *
 *     else if (row.Level == "Level 1")
 *         alert "Approval Type is Not Applicable for Level 1 ";
 *         row.Approval_Type = null;
 *         disable row.Approval_Type;
 *
 * Creator deliberately nulls and disables it. The grid screenshots agree — Level 1
 * reads `-Select-` on both rules while Level 2 reads `Any`. So
 * `PendingApproval::currentLevelSatisfied()` treating a null type as "any one
 * approver suffices" is right, and tightening that null to `All` — which looked like
 * the conservative fix before this handler was read — would have stalled every
 * Level 1 approval in the system.
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
     *     warnings: list<string>,
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
            'warnings' => array_merge(
                $this->bandWarnings($levels->all()),
                $this->mirrorWarnings($approval, $levels->all()),
            ),
        ];
    }

    /**
     * Gaps and overlaps in the amount bands.
     *
     * The live grid keeps them contiguous and inclusive — `0 - 5,000` then
     * `5,001 - 50,00,00,000` — and on data shaped like that, routing by greatest
     * minimum and routing by "the band containing the amount" give the same answer.
     *
     * A gap or an overlap is where they part company, and the divergence is invisible:
     * with bands `0 - 5,000` and `6,000 - 50cr`, a ₹5,500 payment falls in NO band,
     * but greatest-minimum still hands it to the ₹0-5,000 approver. Creator does the
     * same thing, so this does not change the routing — it just stops the
     * misconfiguration being silent.
     *
     * @param  list<\App\Models\ApprovalLevel>  $levels
     * @return list<string>
     */
    public function bandWarnings(array $levels): array
    {
        $ordered = array_values(array_filter(
            $levels,
            fn ($l): bool => $l->minimum_amount !== null,
        ));

        usort($ordered, fn ($a, $b): int => bccomp((string) $a->minimum_amount, (string) $b->minimum_amount, 4));

        $warnings = [];

        foreach ($ordered as $i => $level) {
            $min = (string) $level->minimum_amount;
            $max = $level->maximum_amount === null ? null : (string) $level->maximum_amount;

            if ($max !== null && bccomp($min, $max, 4) > 0) {
                $warnings[] = sprintf(
                    '%s has minimum %s above its maximum %s, so the band is empty.',
                    $level->level, $min, $max,
                );
            }

            $next = $ordered[$i + 1] ?? null;

            if ($next === null || $max === null) {
                continue;
            }

            // Contiguous means the next minimum is exactly max + 1 rupee, which is
            // how the form maintains it. Anything else is a gap or an overlap.
            $expected = bcadd($max, '1', 4);
            $actual = (string) $next->minimum_amount;
            $cmp = bccomp($actual, $expected, 4);

            if ($cmp === 0) {
                /*
                 * The bands are contiguous IN WHOLE RUPEES, which is how the form
                 * maintains them — and payment amounts are not whole rupees. §6.3
                 * splits at paisa scale and Pending Approvals renders three decimals
                 * (₹58,614.140), so amounts strictly between one band's maximum and
                 * the next band's minimum are real and fall in NEITHER band.
                 *
                 * Greatest-minimum then sends them DOWN, to the lower authority: on
                 * `0-5,000` / `5,001-50cr`, a ₹5,000.50 payment is approved by the
                 * ₹0-5,000 approver. Inherent to the shape rather than a
                 * misconfiguration, so it is stated once per boundary rather than
                 * treated as an error — but it is stated, because "the band above
                 * ₹5,000 approves it" is what a reader would assume.
                 */
                $warnings[] = sprintf(
                    'Amounts above %s and below %s fall in neither band and route to %s, the '
                    .'lower authority. The bands are whole-rupee; payment amounts are not.',
                    $max, $actual, $level->level,
                );
            } elseif ($cmp > 0) {
                $warnings[] = sprintf(
                    'Amounts between %s and %s fall in no band: %s ends at %s and %s begins at %s. '
                    .'Creator routes them to %s anyway, because only minimums are consulted.',
                    $expected, bcsub($actual, '1', 4), $level->level, $max, $next->level, $actual,
                    $level->level,
                );
            } else {
                $warnings[] = sprintf(
                    '%s (%s-%s) and %s (from %s) overlap. Greatest-minimum wins, so the overlap '
                    .'always resolves to %s.',
                    $level->level, $min, $max, $next->level, $actual, $next->level,
                );
            }
        }

        return $warnings;
    }

    /**
     * The header fields against the grid they are supposed to mirror.
     *
     * See the class docblock: `Accounts.ds:38118-38146` copies
     * `Approvers[Level 2].Approval_Type` into `Level_1_2_Approval` and
     * `Approvers[Level 3].Approval_Type` into `Level_2_3_Approval`, browser-side only.
     * When the copy is stale, routing reads the header and can walk a shorter chain
     * than the configuration asked for.
     *
     * @param  list<\App\Models\ApprovalLevel>  $levels
     * @return list<string>
     */
    public function mirrorWarnings(Approval $approval, array $levels): array
    {
        $warnings = [];

        $pairs = [
            'Level 2' => ['level_1_2_approval', 'Level 1 & 2 Approval'],
            'Level 3' => ['level_2_3_approval', 'Level 2 & 3 Approval'],
        ];

        foreach ($pairs as $levelName => [$column, $label]) {
            $level = null;

            foreach ($levels as $candidate) {
                if (trim((string) $candidate->level) === $levelName) {
                    $level = $candidate;
                    break;
                }
            }

            if ($level === null) {
                continue;
            }

            $grid = strtoupper(trim((string) $level->approval_type));
            $header = strtoupper(trim((string) $approval->{$column}));

            if ($grid === $header) {
                continue;
            }

            $warnings[] = sprintf(
                '%s is %s but %s\'s Approval Type is %s. The header is a browser-side copy of the '
                .'grid (Accounts.ds:38118), so this rule was written by a path that never fired '
                .'the handler. Routing follows the header, as Creator does.',
                $label,
                $header === '' ? 'blank' : '"'.trim((string) $approval->{$column}).'"',
                $levelName,
                $grid === '' ? 'blank' : '"'.trim((string) $level->approval_type).'"',
            );
        }

        return $warnings;
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
            'warnings' => [],
        ];
    }
}
