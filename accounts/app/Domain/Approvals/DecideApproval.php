<?php

declare(strict_types=1);

namespace App\Domain\Approvals;

use App\Domain\Payments\PaymentStatus;
use App\Models\PendingApproval;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Approve or reject a pending approval — the transition that was missing.
 *
 * Until now there were eight write routes and NOT ONE was a status change: a payment
 * was created and then frozen, and the only movement available was a reversal. This
 * is the hop that makes `Draft -> Sent for Approval -> Approved -> Paid` a path
 * rather than a diagram.
 *
 * ---------------------------------------------------------------------------
 * WHAT MADE IT BUILDABLE. It was blocked on the Approval rule's Approvers grid — the
 * amount bands and approver identities that no export carries. Seven screenshots of
 * All Pending Approvals (27-Aug-2026) showed the block was in the wrong place: the
 * PENDING RECORD carries its own `Approvers`, `Approval Type` and `Approved By`
 * subform. The rule's bands decide which level a NEW payment enters at; they are not
 * consulted to move one already in flight.
 *
 * So `ApprovalRouter` still refuses to route a new payment without bands — correctly
 * — and this class can approve one that exists.
 *
 * ---------------------------------------------------------------------------
 * THE APPROVE PATH, from Accounts.ds:16113-16135 and the screenshots:
 *
 *   1. tick the approver's row in the `Approved By` subform
 *   2. is the CURRENT level satisfied? `Any` -> one ticked row; `All` -> every row
 *   3. not satisfied  -> nothing else moves; the record waits
 *   4. satisfied      -> is there a next level in the chain?
 *        yes -> advance `Approval Level`, leave `Next Level Approval Required?` true
 *        no  -> Status = "Approved" on the pending record AND the payment,
 *               `Approved = true`, `Next Level Approval Required?` = false,
 *               and the Payment_Request too if there is one
 *
 * REJECT is flatter: `Approval Rejected` on both records immediately, no level
 * advance, no partial state. Accounts.ds:16508-16511 writes it to all three records
 * at once.
 *
 * ---------------------------------------------------------------------------
 * ONE DEVIATION, LOGGED. Creator recomputes the level chain from the rule on every
 * approval, so editing a rule mid-flight silently re-decides an approval already
 * under way — an approver at Level 2 can find the ground moved. `PendingApproval.chain`
 * freezes the expanded chain at submit and this reads that. Same §14-family reasoning
 * as the reconciliation tolerances: a rule change must not retroactively re-answer a
 * decision. **D8 on the deviation register.**
 *
 * WHO IS APPROVING is passed in, not read from a session, because there is no
 * session — §3.3's matrix is not wired to a gate. That means THIS CLASS CANNOT TELL
 * WHETHER THE CALLER IS ENTITLED TO APPROVE. It verifies the approver is named on the
 * record; it cannot verify the request came from that person. Stated plainly because
 * an approval engine without authentication is a workflow, not a control.
 */
final class DecideApproval
{
    public function __construct(private readonly ApprovalRouter $router = new ApprovalRouter) {}

    /**
     * @param  string  $approverName  who is acting. Matched against the record's own
     *                                approver rows — the only check available without
     *                                authentication.
     * @return array{status: string, level: ?string, advanced: bool, finalised: bool, message: string}
     */
    public function approve(PendingApproval $pending, string $approverName): array
    {
        return DB::transaction(function () use ($pending, $approverName): array {
            $pending->refresh()->load('approvers', 'payment');

            if (! $pending->isOpen()) {
                throw new RuntimeException(sprintf(
                    'This approval is already settled (%s). Re-approving would move a decided '
                    .'record, which is the class of thing §7.6 forbids for payments.',
                    $pending->status ?? 'no status',
                ));
            }

            $level = trim((string) $pending->approval_level);

            $row = $pending->approvers->first(
                fn ($a) => trim((string) $a->approval_level) === $level
                    && strcasecmp($a->displayName(), $approverName) === 0
            );

            if ($row === null) {
                throw new RuntimeException(sprintf(
                    '"%s" is not an approver for %s on this record. Named approvers at this '
                    .'level: %s. Approving on behalf of someone else is exactly what the '
                    .'Approved By grid exists to prevent.',
                    $approverName,
                    $level === '' ? 'this level' : $level,
                    $pending->approvers->where('approval_level', $level)
                        ->map(fn ($a) => $a->displayName())->filter()->implode(', ') ?: 'none',
                ));
            }

            $row->approved = true;
            $row->approved_at = now();
            $row->save();

            $pending->load('approvers');

            // Step 2 — `Any` needs one ticked row here, `All` needs every one.
            if (! $pending->currentLevelSatisfied()) {
                $outstanding = $pending->approvers
                    ->filter(fn ($a) => trim((string) $a->approval_level) === $level && ! $a->approved)
                    ->map(fn ($a) => $a->displayName())->filter()->implode(', ');

                return [
                    'status' => (string) $pending->status,
                    'level' => $level,
                    'advanced' => false,
                    'finalised' => false,
                    'message' => sprintf(
                        'Recorded. %s is set to "All", so %s must also approve before %s advances.',
                        $pending->approval_type, $outstanding ?: 'the remaining approvers', $level,
                    ),
                ];
            }

            // Step 4 — the frozen chain, not a recomputation. See the docblock.
            $chain = is_array($pending->chain) ? $pending->chain : [];
            $next = $this->router->nextLevel($chain, $level);

            if ($next !== null) {
                $pending->approval_level = $next;
                $pending->next_level_approval_required = true;
                $pending->decided_by = $approverName;
                $pending->save();

                return [
                    'status' => (string) $pending->status,
                    'level' => $next,
                    'advanced' => true,
                    'finalised' => false,
                    'message' => sprintf('%s approved. Advanced to %s.', $level, $next),
                ];
            }

            // The chain is exhausted — finalise, on both records.
            $pending->status = PaymentStatus::APPROVED;
            $pending->payment_status = PaymentStatus::APPROVED;
            $pending->next_level_approval_required = false;
            $pending->decided_by = $approverName;
            $pending->decided_at = now();
            $pending->save();

            if ($pending->payment !== null) {
                $pending->payment->status = PaymentStatus::APPROVED;
                // Creator's own flag, set alongside the status (Accounts.ds:16132).
                $pending->payment->approved = true;
                $pending->payment->save();
            }

            return [
                'status' => PaymentStatus::APPROVED,
                'level' => $level,
                'advanced' => false,
                'finalised' => true,
                'message' => sprintf(
                    'Approved. %s was the last level in the chain, so the payment is now Approved '
                    .'and can be paid.',
                    $level,
                ),
            ];
        });
    }

    /**
     * Reject — flat, immediate, both records.
     *
     * No level advance and no partial state: Accounts.ds:16508-16511 writes
     * `Approval Rejected` to the pending record, the payment and the request at once.
     * A reason is required, unlike Creator, which asks for none — a rejected payment
     * with no recorded reason is a question nobody can answer later.
     */
    public function reject(PendingApproval $pending, string $approverName, string $reason): array
    {
        if (mb_strlen(trim($reason)) < 3) {
            throw new RuntimeException(
                'A rejection needs a reason. Creator does not require one; this does, because '
                .'a rejected payment with no explanation is unanswerable a month later.'
            );
        }

        return DB::transaction(function () use ($pending, $approverName, $reason): array {
            $pending->refresh()->load('payment');

            if (! $pending->isOpen()) {
                throw new RuntimeException(sprintf(
                    'This approval is already settled (%s).', $pending->status ?? 'no status',
                ));
            }

            $pending->status = PaymentStatus::APPROVAL_REJECTED;
            $pending->payment_status = PaymentStatus::APPROVAL_REJECTED;
            $pending->next_level_approval_required = false;
            $pending->decided_by = $approverName;
            $pending->decision_reason = $reason;
            $pending->decided_at = now();
            $pending->save();

            if ($pending->payment !== null) {
                $pending->payment->status = PaymentStatus::APPROVAL_REJECTED;
                $pending->payment->approved = false;
                $pending->payment->save();
            }

            return [
                'status' => PaymentStatus::APPROVAL_REJECTED,
                'level' => (string) $pending->approval_level,
                'advanced' => false,
                'finalised' => true,
                'message' => 'Rejected. The payment is marked Approval Rejected and does not advance.',
            ];
        });
    }
}
