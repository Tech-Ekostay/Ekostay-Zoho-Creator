<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Payments\CreatePaymentFromBill;
use App\Domain\Payments\PaymentStatus;
use App\Domain\Payments\ReversalRefusedException;
use App\Domain\Payments\ReversePayment;
use App\Domain\Payments\UnbalancedPaymentException;
use App\Models\AutoNumber;
use App\Models\Bill;
use App\Models\BillingCycle;
use App\Models\CoaAccount;
use App\Models\ItemCategory;
use App\Models\Payment;
use App\Models\Vendor;
use App\Models\Villa;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * §17 step 7 — the Payments write path, which the spec gated until the four §16
 * "blocking write paths" questions were answered.
 *
 * The two that matter most here are the ones that move money:
 *
 *   §7.4  Payments has NO split balance check in Creator. Added, and asserted.
 *   §7.6  `Delete Paid Payment` destroyed 17 real payments. Replaced by a
 *         reversing entry, and the hard delete is asserted to be IMPOSSIBLE —
 *         not merely absent from the UI.
 */
class PaymentWritePathTest extends TestCase
{
    use RefreshDatabase;

    private Bill $bill;

    protected function setUp(): void
    {
        parent::setUp();

        // Create_Payment forces every payment onto this account (§7.2).
        CoaAccount::create(['account_name' => 'Accounts Payable']);

        $this->seedAutoNumbers(20938);
        $this->bill = $this->makeBill();
    }

    // ----------------------------------------------------------- creating

    #[Test]
    public function it_creates_a_payment_from_a_bill_with_the_statuses_create_payment_writes(): void
    {
        $payment = app(CreatePaymentFromBill::class)($this->bill, 'tech@ekostay.com');

        // §7.3 — both axes, exactly as Accounts.ds:45438-45439 writes them.
        $this->assertSame(PaymentStatus::SUBMIT_FOR_APPROVAL, $payment->status);
        $this->assertSame('Open', $payment->payment_status);

        // The legs sum to the bill's gross (§6.4), which is what Create_Payment
        // copies across on the normal path.
        $this->assertSame('100000.0000', $payment->payable_amount);
        $this->assertCount(2, $payment->splitPayments);
        $this->assertCount(1, $payment->billPayments);
        $this->assertTrue($payment->accounts_bills);
    }

    /** The number comes off the real counter, unpadded — §7.6. */
    #[Test]
    public function it_takes_the_next_number_from_the_live_counter(): void
    {
        $payment = app(CreatePaymentFromBill::class)($this->bill);

        $this->assertSame('EKS/PY/20938', $payment->payment_no);
        $this->assertSame(20939, AutoNumber::first()->payment_no);
    }

    /** Consecutive payments never share a number. */
    #[Test]
    public function consecutive_payments_take_distinct_numbers(): void
    {
        $first = app(CreatePaymentFromBill::class)($this->bill);
        $second = app(CreatePaymentFromBill::class)($this->makeBill('TEST/BILL/0002'));

        $this->assertNotSame($first->payment_no, $second->payment_no);
        $this->assertSame('EKS/PY/20939', $second->payment_no);
    }

    /** Creator's `input.Status = "Payment InProgress"` (Accounts.ds:45480). */
    #[Test]
    public function it_moves_the_bill_to_payment_inprogress(): void
    {
        app(CreatePaymentFromBill::class)($this->bill);

        $this->assertTrue($this->bill->fresh()->statusIs('Payment InProgress'));
    }

    /**
     * §7.4's missing check, asserted.
     *
     * A tampered leg makes the split disagree with the payable, and the write is
     * refused ENTIRELY — no payment row, and the counter untouched. Creator would
     * have written this silently and misstated every downstream
     * villa-month-category figure that §5.2 traces back to these legs.
     */
    #[Test]
    public function it_refuses_an_unbalanced_split_and_writes_nothing(): void
    {
        $this->bill->splitPayments()->first()->update(['amount' => '49999.9900']);

        try {
            app(CreatePaymentFromBill::class)($this->bill);
            $this->fail('an unbalanced split should have been refused');
        } catch (UnbalancedPaymentException $e) {
            $this->assertStringContainsString('0.0100', $e->getMessage());
        }

        $this->assertSame(0, Payment::count());
        $this->assertSame(20938, AutoNumber::first()->payment_no, 'the counter must not advance on a refused write');
        $this->assertTrue($this->bill->fresh()->statusIs('Draft'), 'the bill status must not change either');
    }

    /**
     * The §7.2 fix, end to end on a partially-paid bill.
     *
     * The backend triplet is read (addendum §10) and TDS is DEDUCTED. Creator would
     * have produced 102000 here; the vendor is owed 116000.
     */
    #[Test]
    public function a_partially_paid_bill_deducts_tds_from_the_backend_figures(): void
    {
        $this->bill->update(['status' => 'Partially Paid']);

        $payment = app(CreatePaymentFromBill::class)($this->bill);

        // 2 legs x (backend_total 58000 - backend_tds 1000)
        $this->assertSame('114000.0000', $payment->payable_amount);

        foreach ($payment->splitPayments as $leg) {
            $this->assertSame('57000.0000', $leg->amount);
        }
    }

    // ---------------------------------------------------------- reversing

    #[Test]
    public function a_settled_payment_reverses_into_a_linked_negative_entry(): void
    {
        $payment = $this->settle(app(CreatePaymentFromBill::class)($this->bill));

        $reversal = app(ReversePayment::class)($payment, 'paid to the wrong bank account');

        $this->assertSame($payment->id, $reversal->reverses_payment_id);
        $this->assertSame('-100000.0000', $reversal->payable_amount);
        $this->assertSame('Reverse', $reversal->payment_status);
        $this->assertSame('paid to the wrong bank account', $reversal->reversal_reason);

        // §7.6 — the original survives intact, number included.
        $original = $payment->fresh();
        $this->assertSame('EKS/PY/20938', $original->payment_no);
        $this->assertSame('100000.0000', $original->payable_amount);
        $this->assertNotNull($original->reversed_at);

        // The reversal is a real ledger event with its own number.
        $this->assertSame('EKS/PY/20939', $reversal->payment_no);
    }

    /**
     * THE POINT OF THE WHOLE DESIGN (§5.2).
     *
     * Every villa x category x cycle nets to zero after a reversal. A hard delete
     * leaves the downstream figures wrong with nothing recording why; a negated leg
     * corrects them in the same place the original allocation was made.
     */
    #[Test]
    public function the_ledger_nets_to_zero_per_villa_category_and_cycle(): void
    {
        $payment = $this->settle(app(CreatePaymentFromBill::class)($this->bill));
        app(ReversePayment::class)($payment, 'wrong account');

        $groups = \DB::table('payment_split_payments')
            ->selectRaw('villa_id, item_category_id, billing_cycle_id, sum(amount) as net, count(*) as legs')
            ->groupBy('villa_id', 'item_category_id', 'billing_cycle_id')
            ->get();

        $this->assertCount(2, $groups, 'two villas, one category, one cycle');

        foreach ($groups as $group) {
            $this->assertSame(2, (int) $group->legs, 'one forward leg and one reversing leg');
            $this->assertSame(0, bccomp((string) $group->net, '0', 4), 'each combination must net to zero');
        }
    }

    #[Test]
    public function an_unsettled_payment_cannot_be_reversed(): void
    {
        $payment = app(CreatePaymentFromBill::class)($this->bill);

        $this->expectException(ReversalRefusedException::class);
        $this->expectExceptionMessageMatches('/not settled/');

        app(ReversePayment::class)($payment, 'changed my mind');
    }

    #[Test]
    public function a_reversal_requires_a_reason(): void
    {
        $payment = $this->settle(app(CreatePaymentFromBill::class)($this->bill));

        $this->expectException(ReversalRefusedException::class);
        $this->expectExceptionMessageMatches('/reason is required/');

        app(ReversePayment::class)($payment, '   ');
    }

    #[Test]
    public function a_payment_cannot_be_reversed_twice(): void
    {
        $payment = $this->settle(app(CreatePaymentFromBill::class)($this->bill));
        app(ReversePayment::class)($payment, 'first');

        $this->expectException(ReversalRefusedException::class);
        $this->expectExceptionMessageMatches('/already been reversed/');

        app(ReversePayment::class)($payment->fresh(), 'second');
    }

    #[Test]
    public function a_reversal_cannot_itself_be_reversed(): void
    {
        $payment = $this->settle(app(CreatePaymentFromBill::class)($this->bill));
        $reversal = app(ReversePayment::class)($payment, 'first');

        $this->expectException(ReversalRefusedException::class);
        $this->expectExceptionMessageMatches('/itself a reversal/');

        app(ReversePayment::class)($reversal, 'undo the undo');
    }

    // ------------------------------------------------- the delete that must not be

    /**
     * §7.6, guarded at the MODEL rather than only at the route.
     *
     * The DS still carries 14 unguarded `delete from Payment` sites. The guard lives
     * on the model so no controller, command, tinker session or future caller can
     * reintroduce the action that destroyed 17 real payments.
     */
    #[Test]
    public function a_settled_payment_cannot_be_deleted_even_directly(): void
    {
        $payment = $this->settle(app(CreatePaymentFromBill::class)($this->bill));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/settled and cannot be deleted/');

        $payment->delete();
    }

    /** An unsettled draft may still be withdrawn — it has moved no money. */
    #[Test]
    public function an_unsettled_payment_may_be_soft_deleted(): void
    {
        $payment = app(CreatePaymentFromBill::class)($this->bill);

        $payment->delete();

        $this->assertSoftDeleted($payment);
    }

    // ------------------------------------------------------------- the API

    #[Test]
    public function the_endpoint_creates_a_payment_and_reports_its_number(): void
    {
        $this->postJson('/api/payments', ['bill_id' => $this->bill->id])
            ->assertCreated()
            ->assertJson([
                'payment_no' => 'EKS/PY/20938',
                'status' => 'Submit for Approval',
                'payment_status' => 'Open',
                'payable_amount' => '100000.0000',
                'split_legs' => 2,
            ]);
    }

    #[Test]
    public function the_endpoint_rejects_an_unbalanced_split_with_422(): void
    {
        $this->bill->splitPayments()->first()->update(['amount' => '1.0000']);

        $this->postJson('/api/payments', ['bill_id' => $this->bill->id])
            ->assertStatus(422)
            ->assertJson(['reason' => 'unbalanced_split']);

        $this->assertSame(0, Payment::count());
    }

    #[Test]
    public function the_reverse_endpoint_requires_a_meaningful_reason(): void
    {
        $payment = $this->settle(app(CreatePaymentFromBill::class)($this->bill));

        $this->postJson("/api/payments/{$payment->id}/reverse", ['reason' => 'x'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');
    }

    /** There is no DELETE route at all — §7.6. */
    #[Test]
    public function there_is_no_delete_route_for_a_payment(): void
    {
        $payment = $this->settle(app(CreatePaymentFromBill::class)($this->bill));

        $this->deleteJson("/api/payments/{$payment->id}")->assertStatus(405);
    }

    #[Test]
    public function money_leaves_the_api_as_strings_never_floats(): void
    {
        app(CreatePaymentFromBill::class)($this->bill);

        $row = $this->getJson('/api/payments')->assertOk()->json('rows.0');

        // §15.2's lesson generalised: nothing numeric in this app may reach a float.
        $this->assertIsString($row['Payable Amount']);
        $this->assertIsString($row['TDS Amount']);
        $this->assertSame('100000.0000', $row['Payable Amount']);
    }

    // ------------------------------------------------------------- fixtures

    private function seedAutoNumbers(int $paymentNo): void
    {
        AutoNumber::create([
            'singleton' => true,
            'payment_series' => 'EKS/PY',
            'payment_no' => $paymentNo,
        ]);
    }

    /**
     * The same figures as TestBillSeeder. The legs tie to the GROSS, which is what
     * §6.4 rule 1 requires: 2 x 50000 = 100000 = Amount. Payable (116000) travels
     * on the Bill_Payments row, not on the split legs.
     */
    private function makeBill(string $billNo = 'TEST/BILL/0001'): Bill
    {
        $villas = collect(['Fixture Villa A', 'Fixture Villa B'])
            ->map(fn (string $name) => Villa::create(['name' => $name, 'hide_from_payments' => false]));

        $itemCategory = ItemCategory::create(['name' => 'FIXTURE CATEGORY']);
        $cycle = BillingCycle::create(['month_name' => 'August', 'year' => '2026', 'month_index' => 8]);
        $vendor = Vendor::create(['name' => 'FIXTURE VENDOR']);

        $bill = Bill::create([
            'bill_no' => $billNo,
            'bill_date' => '2026-08-22',
            'due_date' => '2026-09-21',
            'vendor_id' => $vendor->id,
            'status' => 'Draft',
            'amount' => '100000.0000',
            'gst_amount' => '18000.0000',
            'tds_amount' => '2000.0000',
            'invoice_amount' => '118000.0000',
            'paid_amount' => '0.0000',
            'payable_amount' => '116000.0000',
        ]);

        $bill->itemCategories()->attach($itemCategory->id);
        $bill->billingCycles()->attach($cycle->id);
        $bill->villas()->attach($villas->pluck('id')->all());

        foreach ($villas->values() as $position => $villa) {
            $bill->splitPayments()->create([
                'villa_id' => $villa->id,
                'item_category_id' => $itemCategory->id,
                'billing_cycle_id' => $cycle->id,
                'amount' => '50000.0000',
                'total_amount' => '58000.0000',
                'gst_amount' => '9000.0000',
                'tds_amount' => '1000.0000',
                'backend_total_amount' => '58000.0000',
                'backend_gst_amount' => '9000.0000',
                'backend_tds_amount' => '1000.0000',
                'percent' => '50.0000',
                'position' => $position,
            ]);
        }

        return $bill->fresh(['splitPayments', 'itemCategories']);
    }

    /** What the approval + payment path would eventually do (§6.5, §7.3). */
    private function settle(Payment $payment): Payment
    {
        $payment->update([
            'status' => PaymentStatus::PAID,
            'payment_status' => PaymentStatus::PS_PAID,
            'payment_date' => now()->toDateString(),
        ]);

        return $payment->fresh(['splitPayments', 'billPayments']);
    }
}
