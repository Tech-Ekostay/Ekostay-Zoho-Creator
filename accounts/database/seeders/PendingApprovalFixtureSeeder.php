<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Approval;
use App\Models\ApprovalLevel;
use App\Models\Employee;
use App\Models\Payment;
use App\Models\PendingApproval;
use App\Models\PendingApprovalApprover;
use App\Models\PendingApprovalCandidate;
use Illuminate\Database\Seeder;

/**
 * A queue to render Pending Approvals against.
 *
 * **DELIBERATELY OUTSIDE `DatabaseSeeder`**, for the same reason as `TestBillSeeder`:
 * fabricated approvals must never land beside the real masters by accident. Run it
 * explicitly:
 *
 *     php artisan db:seed --class=PendingApprovalFixtureSeeder
 *
 * It builds the rule captured in addendum §11.7 verbatim — the live one — and attaches
 * approvals to REAL imported payments rather than inventing money, so the report shows
 * real vendors, villas, amounts and payment numbers. Nothing here writes to a payment.
 *
 * THE STATUS MIX IS THE POINT. The live report shows five `Sent for Approval` rows
 * with a PALE `Pay` button and four `Approved` rows with a SOLID one, so both states
 * have to exist or the screen cannot be verified. Nine rows, same split.
 */
class PendingApprovalFixtureSeeder extends Seeder
{
    public function run(): void
    {
        $rule = $this->rule();

        /*
         * Real payments, newest first — the report leads with Added Time descending.
         *
         * NOT filtered on `villa_id`, and that is a finding rather than a compromise:
         * only **2 of 52,639** imported payments carry one. A payment's villa lives on
         * its SPLIT LEGS (§5.2 — a payment spans many villa x category x cycle legs),
         * so `payment_master` fills vendor, location and item category and leaves the
         * header's villa null. Filtering on it found 2 rows.
         *
         * So `Villa Name` and `Billing Cycles` render BLANK on this report for almost
         * every row, because both are leg-level. That is a gap in what we imported, not
         * a defect in the screen — and it is the §12 warning arriving in practice:
         * import the child rows, or the parent looks emptier than it is.
         */
        /*
         * AND NOT ALREADY PAID, which matters more than it looks.
         *
         * 52,400 of 52,639 imported payments carry `Paid` — this is a historical
         * ledger, not a work queue. Attaching approvals to arbitrary real payments
         * therefore produced a screen where `Pay` was pale on 8 of 9 rows, because
         * `MarkPaymentPaid` correctly refuses a payment that is already settled.
         *
         * The live report shows the opposite balance: five pale and four SOLID. So the
         * fixture prefers the ~239 payments still in flight, which is what makes both
         * button states visible and the screen verifiable. Found by rendering and
         * counting the buttons, not by reading the code.
         */
        $payments = Payment::query()
            ->whereNotNull('payment_no')
            ->whereNotNull('vendor_id')
            ->whereRaw("lower(coalesce(status, '')) <> 'paid'")
            ->with(['vendor', 'villa'])
            ->orderByDesc('id')
            ->limit(9)
            ->get();

        if ($payments->count() < 9) {
            $this->command?->warn('Fewer than 9 usable payments; seeding what there is.');
        }

        $rohan = Employee::whereRaw('lower(trim(name)) = ?', ['rohan'])->first();
        $sohail = Employee::whereRaw('lower(trim(name)) = ?', ['sohail mirchandani'])->first();

        // Five Sent for Approval, four Approved — the live split.
        $plan = [
            ['Sent for Approval', 'Level 1', $rohan, false],
            ['Sent for Approval', 'Level 1', $rohan, false],
            ['Sent for Approval', 'Level 2', $sohail, false],
            ['Sent for Approval', 'Level 1', $rohan, false],
            ['Sent for Approval', 'Level 2', $sohail, false],
            ['Approved', 'Level 2', $sohail, true],
            ['Approved', 'Level 2', $sohail, true],
            ['Approved', 'Level 1', $rohan, true],
            ['Approved', 'Level 2', $sohail, true],
        ];

        foreach ($payments as $i => $payment) {
            [$status, $level, $approver, $settled] = $plan[$i] ?? $plan[0];

            $pending = PendingApproval::create([
                'payment_id' => $payment->id,
                'approval_id' => $rule->id,
                'approval_level' => $level,
                'chain' => $level === 'Level 2' ? ['Level 1', 'Level 2'] : ['Level 1'],
                'status' => $status,
                'payment_status' => $status,
                // An OPEN approval is one still requiring the next level; a settled one
                // is closed. `isOpen()` reads this, so it decides the buttons.
                'next_level_approval_required' => ! $settled,
                'approval_type' => $level === 'Level 2' ? 'Any' : null,
                'approval_amount' => $payment->total_amount ?? $payment->amount,
                // Real-looking WhatsApp message ids — the DoubleTick ones (§11.12).
                'message_id' => sprintf('27718721-51ad-4372-b12e-716bfb5a%04d', 268 + $i),
                'added_time' => now()->subMinutes(($i + 1) * 7),
                'modified_time' => now()->subMinutes(($i + 1) * 7),
            ]);

            /*
             * The `Approved By` subform. One row per approver per level — the shape
             * that makes `Approval Type = All` expressible (§5). On a settled record
             * the row is ticked, which is what makes the chain look finished.
             *
             * Note the live report shows the Approved checkbox UNCHECKED on approved
             * records (§5's "seventh disagreeing representation"). That is not
             * reproduced here: a fixture that contradicts itself cannot be used to
             * verify the screen renders correctly.
             */
            PendingApprovalApprover::create([
                'pending_approval_id' => $pending->id,
                'employee_id' => $approver?->id,
                'approver_name' => $approver?->name,
                'approval_level' => $level,
                'approved' => $settled,
                'approved_at' => $settled ? now()->subMinutes(($i + 1) * 5) : null,
                'position' => 0,
            ]);

            // `Approvers` — who MAY act, distinct from who HAS.
            if ($approver !== null) {
                PendingApprovalCandidate::create([
                    'pending_approval_id' => $pending->id,
                    'employee_id' => $approver->id,
                    'approver_name' => $approver->name,
                ]);
            }
        }

        $this->command?->info(sprintf(
            'Seeded %d pending approvals against real payments (%d open, %d settled).',
            $payments->count(),
            PendingApproval::where('next_level_approval_required', true)->count(),
            PendingApproval::where('next_level_approval_required', false)->count(),
        ));
    }

    /** The live rule from addendum §11.7, verbatim. */
    private function rule(): Approval
    {
        $rule = Approval::firstOrCreate(
            ['module' => 'Payment', 'locations' => 'Alibaug', 'item_categories' => 'PHOTOSHOOT'],
            [
                // Both headers BLANK, as all 16 live rules have them — the
                // browser-side mirror never fired (§11.9).
                'level_1_2_approval' => null,
                'level_2_3_approval' => null,
            ]
        );

        if ($rule->levels()->exists()) {
            return $rule;
        }

        ApprovalLevel::create([
            'approval_id' => $rule->id,
            'level' => 'Level 1',
            'minimum_amount' => '0.0000',
            'maximum_amount' => '5000.0000',
            // Null and disabled on Level 1 by design — Accounts.ds:38137 (§11.10).
            'approval_type' => null,
            'position' => 0,
        ]);

        ApprovalLevel::create([
            'approval_id' => $rule->id,
            'level' => 'Level 2',
            'minimum_amount' => '5001.0000',
            'maximum_amount' => '500000000.0000',
            'approval_type' => 'Any',
            'position' => 1,
        ]);

        return $rule->load('levels');
    }
}
