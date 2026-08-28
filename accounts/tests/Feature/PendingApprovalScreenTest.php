<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Payment;
use App\Models\PendingApproval;
use App\Models\PendingApprovalApprover;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * All Pending Approvals — the report and the three transitions.
 *
 * The 24 columns and their order come from seven screenshots (27-Aug-2026), so the
 * order test is a real assertion and not a tautology: if someone tidies the column
 * list alphabetically, or moves the action buttons to the left edge where they look
 * more natural, this fails.
 *
 * The `Pay` gate is tested against the PAYMENT's status rather than the approval's,
 * because the first version tested the approval and was wrong — `MarkPaymentPaid`
 * moves the payment and leaves the approval at `Approved`, so the check never fired
 * and `Pay` stayed live on an already-paid row. Caught by rendering the page and
 * counting the buttons.
 */
class PendingApprovalScreenTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: PendingApproval, 1: Payment} */
    private function queued(string $status, string $level = 'Level 1', bool $open = true, ?string $paymentStatus = 'Submit for Approval'): array
    {
        $payment = Payment::create([
            'payment_no' => 'EKS/PY/'.random_int(90000, 99999),
            'status' => $paymentStatus,
            'amount' => '58614.1400',
            'payable_amount' => '52752.7300',
        ]);

        $pending = PendingApproval::create([
            'payment_id' => $payment->id,
            'approval_level' => $level,
            'chain' => [$level],
            'status' => $status,
            'payment_status' => $status,
            'next_level_approval_required' => $open,
            'approval_type' => null,
            'added_time' => now(),
        ]);

        PendingApprovalApprover::create([
            'pending_approval_id' => $pending->id,
            'approver_name' => 'Rohan',
            'approval_level' => $level,
            'approved' => ! $open,
            'position' => 0,
        ]);

        return [$pending, $payment];
    }

    // ------------------------------------------------------------- the report

    #[Test]
    public function the_24_columns_are_in_the_order_the_live_report_shows_them(): void
    {
        $this->queued('Sent for Approval');

        $body = $this->getJson('/api/pending-approvals')->assertOk()->json();

        $this->assertSame([
            'Added Time', 'Payment Date', 'Approve', 'Reject', 'Link', 'Payment Status',
            'Pay', 'Payable Amount', 'Location', 'Gross Amount', 'Item Category',
            'Bank Name', 'Vendor Name', 'Villa Name', 'Payment No', 'Master Category',
            'Status', 'COA', 'Billing Cycles', 'Approval Level',
            'Next Level Approval Required?', 'Approval Type', 'Approved By', 'Message ID',
        ], $body['columns']);

        $this->assertCount(24, $body['columns']);

        // The three actions sit MID-TABLE, not at the left edge.
        $this->assertSame(2, array_search('Approve', $body['columns'], true));
        $this->assertSame(6, array_search('Pay', $body['columns'], true));
    }

    #[Test]
    public function gross_amount_is_flagged_for_three_decimals_and_payable_for_two(): void
    {
        $this->queued('Sent for Approval');

        $hints = $this->getJson('/api/pending-approvals')->json('column_hints');

        // Addendum §5: ₹58,614.140 on this report and nowhere else in the app.
        $this->assertSame(3, $hints['Gross Amount']['decimals']);
        $this->assertSame(2, $hints['Payable Amount']['decimals']);
        $this->assertTrue($hints['Payment Status']['filled']);
    }

    #[Test]
    public function the_response_says_out_loud_that_nothing_is_authenticated(): void
    {
        $this->queued('Sent for Approval');

        // Three buttons that move money look like a control. This is not one yet, and
        // the flag exists so the UI cannot present it as one.
        $this->assertTrue($this->getJson('/api/pending-approvals')->json('unauthenticated'));
    }

    // ------------------------------------------------------------- the gates

    #[Test]
    public function approve_and_reject_are_live_only_while_the_approval_is_open(): void
    {
        [$open] = $this->queued('Sent for Approval', 'Level 1', true);
        [$shut] = $this->queued('Approved', 'Level 1', false);

        $rows = collect($this->getJson('/api/pending-approvals')->json('rows'))->keyBy('id');

        $this->assertTrue($rows[$open->id]['can']['approve']);
        $this->assertTrue($rows[$open->id]['can']['reject']);
        $this->assertFalse($rows[$shut->id]['can']['approve']);
        $this->assertFalse($rows[$shut->id]['can']['reject']);
    }

    #[Test]
    public function pay_is_live_on_approved_and_pale_on_everything_else(): void
    {
        [$sent] = $this->queued('Sent for Approval', 'Level 1', true);
        [$approved] = $this->queued('Approved', 'Level 1', false);
        [$rejected] = $this->queued('Approval Rejected', 'Level 1', false);

        $rows = collect($this->getJson('/api/pending-approvals')->json('rows'))->keyBy('id');

        $this->assertTrue($rows[$approved->id]['can']['pay']);
        $this->assertFalse($rows[$sent->id]['can']['pay'], 'pale on Sent for Approval, as on the live report');
        $this->assertFalse($rows[$rejected->id]['can']['pay']);
    }

    /**
     * The bug the harness found. `MarkPaymentPaid` moves the PAYMENT and leaves the
     * approval at `Approved`, so a check against the approval's own status never fires.
     */
    #[Test]
    public function pay_goes_pale_once_the_PAYMENT_is_paid_even_though_the_approval_still_reads_approved(): void
    {
        [$pending, $payment] = $this->queued('Approved', 'Level 1', false);

        $this->assertTrue(
            collect($this->getJson('/api/pending-approvals')->json('rows'))
                ->firstWhere('id', $pending->id)['can']['pay']
        );

        $payment->update(['status' => 'Paid', 'payment_status' => 'paid']);

        $row = collect($this->getJson('/api/pending-approvals')->json('rows'))
            ->firstWhere('id', $pending->id);

        $this->assertSame('Approved', $row['Status'], 'the approval itself has not moved');
        $this->assertFalse($row['can']['pay'], 'but Pay must be pale — the payment is settled');
    }

    // ------------------------------------------------- the write transitions

    #[Test]
    public function approving_as_someone_not_on_the_record_is_refused(): void
    {
        [$pending] = $this->queued('Sent for Approval');

        $this->postJson("/api/pending-approvals/{$pending->id}/approve", ['approver' => 'Nobody At All'])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'refused');
    }

    #[Test]
    public function approving_as_the_named_approver_finalises_a_single_level_chain(): void
    {
        [$pending, $payment] = $this->queued('Sent for Approval');

        $this->postJson("/api/pending-approvals/{$pending->id}/approve", ['approver' => 'Rohan'])
            ->assertOk()
            ->assertJsonPath('finalised', true)
            ->assertJsonPath('status', 'Approved');

        $this->assertSame('Approved', $payment->fresh()->status);
    }

    #[Test]
    public function rejecting_without_a_reason_is_a_422(): void
    {
        [$pending] = $this->queued('Sent for Approval');

        // Creator asks for no reason. This does — logged as a deviation.
        $this->postJson("/api/pending-approvals/{$pending->id}/reject", ['approver' => 'Rohan'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');
    }

    #[Test]
    public function rejecting_with_a_reason_settles_both_records(): void
    {
        [$pending, $payment] = $this->queued('Sent for Approval');

        $this->postJson("/api/pending-approvals/{$pending->id}/reject", [
            'approver' => 'Rohan',
            'reason' => 'Duplicate of 21207, checked against the bank line',
        ])->assertOk()->assertJsonPath('status', 'Approval Rejected');

        $this->assertSame('Approval Rejected', $payment->fresh()->status);
        $this->assertFalse($pending->fresh()->isOpen());
    }

    #[Test]
    public function paying_mid_chain_is_refused_with_a_reason_not_a_500(): void
    {
        [$pending] = $this->queued('Sent for Approval', 'Level 1', true, 'Submit for Approval');

        $this->postJson("/api/pending-approvals/{$pending->id}/pay")
            ->assertStatus(422)
            ->assertJsonPath('reason', 'refused');
    }

    #[Test]
    public function paying_an_approved_payment_moves_both_status_axes(): void
    {
        [$pending, $payment] = $this->queued('Approved', 'Level 1', false, 'Approved');

        $this->postJson("/api/pending-approvals/{$pending->id}/pay")
            ->assertOk()
            // Two axes, and the lowercase is the picklist's, not a slip (§7.3).
            ->assertJsonPath('payment_status', 'paid');

        $this->assertSame('Paid', $payment->fresh()->status);
    }

    /**
     * The controller's null-payment guard, reached the only way it CAN be reached.
     *
     * An approval with `payment_id = null` is impossible — the column is NOT NULL, and
     * the first version of this test tried to create one and hit the constraint. The
     * schema is a better guarantee than a runtime check, so that is asserted directly
     * below. But `Payment` soft-deletes, so `$pending->payment` genuinely can resolve to
     * null on a live row, and that is the path worth covering.
     */
    #[Test]
    public function paying_when_the_payment_has_been_soft_deleted_is_refused_rather_than_crashing(): void
    {
        [$pending, $payment] = $this->queued('Approved', 'Level 1', false, 'Approved');

        $payment->delete();

        $this->postJson("/api/pending-approvals/{$pending->id}/pay")
            ->assertStatus(422)
            ->assertJsonPath('reason', 'refused');
    }

    #[Test]
    public function an_approval_cannot_exist_without_a_payment_at_the_schema_level(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);

        // Not a runtime check — the column is NOT NULL, so a bare approval with nothing
        // behind it cannot be written at all. That is the stronger guarantee.
        PendingApproval::create([
            'approval_level' => 'Level 1',
            'status' => 'Approved',
            'next_level_approval_required' => false,
            'added_time' => now(),
        ]);
    }

    // -------------------------------------------------------------- the detail

    #[Test]
    public function the_detail_exposes_the_subform_as_well_as_the_flattened_name(): void
    {
        [$pending] = $this->queued('Sent for Approval');

        $body = $this->getJson("/api/pending-approvals/{$pending->id}")->assertOk()->json();

        // The report shows one name because Creator flattens the grid...
        $this->assertSame('Rohan', $body['detail']['Approved By']);

        // ...and this is what it flattens FROM. Losing it would make `Approval Type =
        // All` inexpressible.
        $this->assertCount(1, $body['approved_by_rows']);
        $this->assertSame('Level 1', $body['approved_by_rows'][0]['Approval Level']);
        $this->assertFalse($body['approved_by_rows'][0]['Approved']);
    }

    #[Test]
    public function the_detail_field_order_is_the_panels_own_not_the_reports(): void
    {
        [$pending] = $this->queued('Sent for Approval');

        $keys = array_keys($this->getJson("/api/pending-approvals/{$pending->id}")->json('detail'));

        // Leads with Payment No and ends with the approver fields — the report leads
        // with Added Time and does not carry Approvers at all.
        $this->assertSame([
            'Payment No', 'Status', 'Approval Level', 'Next Level Approval Required?',
            'Approval Type', 'Approved By', 'Approvers', 'Preferred Approver', 'Item Category',
        ], $keys);
    }

    #[Test]
    public function an_unknown_filter_column_is_a_422_and_not_an_unfiltered_result(): void
    {
        $this->queued('Sent for Approval');

        // The whole point: a rejected filter must not degrade to everything.
        $this->getJson('/api/pending-approvals?filters='.urlencode(json_encode([
            ['column' => 'Wherever', 'operator' => 'contains', 'value' => 'x'],
        ])))->assertStatus(422)->assertJsonPath('reason', 'bad_filter');
    }

    #[Test]
    public function filtering_narrows_the_matched_count_without_touching_the_total(): void
    {
        [$a] = $this->queued('Sent for Approval');
        $this->queued('Approved', 'Level 1', false);

        $body = $this->getJson('/api/pending-approvals?filters='.urlencode(json_encode([
            ['column' => 'Status', 'operator' => 'is', 'value' => 'Sent for Approval'],
        ])))->assertOk()->json();

        $this->assertSame(2, $body['total']);
        $this->assertSame(1, $body['matched']);
        $this->assertSame($a->id, $body['rows'][0]['id']);
    }
}
