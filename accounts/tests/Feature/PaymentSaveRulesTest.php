<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Payments\PaymentSaveRules;
use App\Domain\Payments\PaymentStatus;
use App\Models\BillingCycle;
use App\Models\CoaAccount;
use App\Models\ItemCategory;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Creator's 22 save-time rules for the Payment form.
 *
 * One test per rule, each naming the `Accounts.ds` line it came from, because the point
 * of the audit Husain asked for is that the flow matches Creator — and a rule with no
 * test is a rule nobody will notice going missing.
 *
 * The `valid()` baseline is a payment Creator WOULD save. Every test breaks exactly one
 * thing, which is what makes a failure legible: if `valid()` itself starts failing, the
 * baseline is wrong rather than the rule.
 */
class PaymentSaveRulesTest extends TestCase
{
    use RefreshDatabase;

    private CoaAccount $expense;
    private CoaAccount $payable;
    private CoaAccount $bank;
    private ItemCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->expense = CoaAccount::create(['account_name' => 'Expense']);
        $this->payable = CoaAccount::create(['account_name' => 'Accounts Payable']);
        $this->bank = CoaAccount::create(['account_name' => 'EKOSTAY LLP 1', 'bank' => true]);
        $this->category = ItemCategory::create(['name' => 'PRINTING']);
    }

    /** @return array<string, mixed> */
    private function valid(array $overrides = []): array
    {
        $cycle = BillingCycle::create(['month_name' => 'August', 'year' => '2026', 'month_index' => 8]);
        $vendor = Vendor::create(['name' => 'PAINT SPARK']);

        return array_merge([
            'item_category_ids' => [$this->category->id],
            'vendor_id' => $vendor->id,
            'coa_account_id' => $this->expense->id,
            'bank_coa_account_id' => $this->bank->id,
            'billing_cycle_ids' => [$cycle->id],
            'particulars' => 'Printing for Casa Bella',
            'amount' => '5000.0000',
            'total_amount' => '5000.0000',
            'payment_date' => '2026-08-28',
            'legs' => [['amount' => '5000.0000']],
            'status' => PaymentStatus::DRAFT,
        ], $overrides);
    }

    /** @return list<string> */
    private function rules(array $input): array
    {
        return array_column((new PaymentSaveRules)->check($input), 'rule');
    }

    #[Test]
    public function a_complete_payment_breaks_no_rule(): void
    {
        $broken = (new PaymentSaveRules)->check($this->valid());

        $this->assertSame([], $broken, 'baseline should be saveable: '.json_encode($broken));
    }

    // ------------------------------------------------- required fields, :28531+

    #[Test]
    public function item_category_is_required(): void
    {
        $this->assertContains('item_category', $this->rules($this->valid(['item_category_ids' => []])));
    }

    #[Test]
    public function vendor_is_required_and_the_typo_is_creators(): void
    {
        $broken = (new PaymentSaveRules)->check($this->valid(['vendor_id' => null]));

        // "vendot" is Creator's spelling. Reproduced because it is what the user sees,
        // and a reviewer comparing screens would notice it corrected.
        $this->assertSame('Please Select vendot to proceed', $broken[0]['message']);
    }

    #[Test]
    public function coa_is_required(): void
    {
        $this->assertContains('coa', $this->rules($this->valid(['coa_account_id' => null])));
    }

    #[Test]
    public function billing_cycle_is_required(): void
    {
        $this->assertContains('billing_cycles', $this->rules($this->valid(['billing_cycle_ids' => []])));
    }

    #[Test]
    public function particulars_are_required(): void
    {
        $this->assertContains('particulars', $this->rules($this->valid(['particulars' => '  '])));
    }

    #[Test]
    public function gross_amount_is_required(): void
    {
        $this->assertContains('amount', $this->rules($this->valid(['amount' => '0.0000'])));
    }

    #[Test]
    public function payment_date_is_required(): void
    {
        $this->assertContains('payment_date', $this->rules($this->valid(['payment_date' => ''])));
    }

    #[Test]
    public function amount_and_total_amount_cannot_be_null(): void
    {
        $this->assertContains('amount_null', $this->rules($this->valid(['total_amount' => null])));
    }

    // --------------------------------------------- the bank rule, :28563 (D11)

    #[Test]
    public function a_bank_is_not_required_on_a_draft(): void
    {
        $this->assertNotContains('bank', $this->rules($this->valid([
            'bank_coa_account_id' => null, 'status' => PaymentStatus::DRAFT,
        ])));
    }

    #[Test]
    public function a_bank_is_required_once_submitted_for_approval(): void
    {
        $this->assertContains('bank', $this->rules($this->valid([
            'bank_coa_account_id' => null, 'status' => PaymentStatus::SUBMIT_FOR_APPROVAL,
        ])));
    }

    /**
     * D11. Creator's precedence — `bank == null && submitting || Paid` — fires on every
     * Paid payment regardless of the bank, which would make a settled payment
     * unsaveable. The intent is implemented instead.
     */
    #[Test]
    public function a_paid_payment_with_a_bank_set_does_NOT_trip_the_bank_rule(): void
    {
        $rules = $this->rules($this->valid([
            'status' => PaymentStatus::PAID, 'is_new' => false,
        ]));

        $this->assertNotContains('bank', $rules, 'D11: the precedence bug is not reproduced');
    }

    // ------------------------------- Accounts Payable needs a bill, :28567

    #[Test]
    public function accounts_payable_requires_a_bill_or_a_vendor_order_booking(): void
    {
        $this->assertContains('bill_or_booking', $this->rules($this->valid([
            'coa_account_id' => $this->payable->id,
        ])));
    }

    #[Test]
    public function a_bill_satisfies_it(): void
    {
        $this->assertNotContains('bill_or_booking', $this->rules($this->valid([
            'coa_account_id' => $this->payable->id, 'bill_ids' => [7],
        ])));
    }

    #[Test]
    public function so_does_a_vendor_order_booking(): void
    {
        $this->assertNotContains('bill_or_booking', $this->rules($this->valid([
            'coa_account_id' => $this->payable->id, 'vendor_order_booking_ids' => [3],
        ])));
    }

    #[Test]
    public function and_a_non_payable_coa_needs_neither(): void
    {
        // The rule is scoped to Accounts Payable. An Expense payment stands alone.
        $this->assertNotContains('bill_or_booking', $this->rules($this->valid()));
    }

    // ------------------------------------------------ the split balance, :28600

    #[Test]
    public function the_split_grid_is_required(): void
    {
        $this->assertContains('split_missing', $this->rules($this->valid(['legs' => []])));
    }

    #[Test]
    public function the_split_must_tie_to_the_gross(): void
    {
        $this->assertContains('split_balance', $this->rules($this->valid([
            'legs' => [['amount' => '4000.0000']],
        ])));
    }

    #[Test]
    public function it_ties_at_whole_rupees_as_creator_has_it(): void
    {
        // §6.4 rule 1 compares at whole rupees, so a paisa gap passes here and is
        // surfaced as a warning elsewhere rather than blocking the save.
        $this->assertNotContains('split_balance', $this->rules($this->valid([
            'amount' => '5000.4000', 'legs' => [['amount' => '5000.0000']],
        ])));
    }

    // ------------------------------------------- the bill payments grid, :28540

    #[Test]
    public function the_bill_payments_grid_must_match_the_payable_amount(): void
    {
        $this->assertContains('bill_payments_balance', $this->rules($this->valid([
            'payable_amount' => '5000.0000',
            'bill_payments' => [['payable_amount' => '3000.0000']],
        ])));
    }

    #[Test]
    public function accounts_bills_switches_that_reconciliation_off(): void
    {
        // The checkbox disables the check entirely — Accounts.ds:28540.
        $this->assertNotContains('bill_payments_balance', $this->rules($this->valid([
            'accounts_bills' => true,
            'payable_amount' => '5000.0000',
            'bill_payments' => [['payable_amount' => '3000.0000']],
        ])));
    }

    // ----------------------------------------------------- Staff Loan, :28677

    #[Test]
    public function staff_loan_cannot_share_the_payment_with_another_category(): void
    {
        $loan = ItemCategory::create(['name' => 'STAFF LOAN']);

        $this->assertContains('staff_loan_exclusive', $this->rules($this->valid([
            'item_category_ids' => [$loan->id, $this->category->id],
        ])));
    }

    #[Test]
    public function staff_loan_demands_the_staff_loan_bank(): void
    {
        $loan = ItemCategory::create(['name' => 'STAFF LOAN']);

        $this->assertContains('staff_loan_bank', $this->rules($this->valid([
            'item_category_ids' => [$loan->id],
        ])));
    }

    #[Test]
    public function the_two_staff_loan_literals_have_different_casing_and_both_are_matched(): void
    {
        // Category is `STAFF LOAN`, bank is `Staff Loan`. Creator's own casing.
        $loan = ItemCategory::create(['name' => 'STAFF LOAN']);
        $loanBank = CoaAccount::create(['account_name' => 'Staff Loan', 'bank' => true]);

        $this->assertNotContains('staff_loan_bank', $this->rules($this->valid([
            'item_category_ids' => [$loan->id],
            'bank_coa_account_id' => $loanBank->id,
        ])));
    }

    // -------------------------------- category/COA consistency, :28687

    #[Test]
    public function a_category_that_declares_a_coa_rejects_a_different_one(): void
    {
        $this->category->update(['coa_account_id' => $this->payable->id]);

        $this->assertContains('coa_mismatch', $this->rules($this->valid([
            'coa_account_id' => $this->expense->id,
        ])));
    }

    #[Test]
    public function a_category_that_declares_no_coa_accepts_any(): void
    {
        $this->assertNotContains('coa_mismatch', $this->rules($this->valid()));
    }

    // ------------------------- Disallow Manual Creation, :28706 (D12)

    #[Test]
    public function a_disabled_category_is_refused_for_manual_entry(): void
    {
        $petty = ItemCategory::create(['name' => 'PETTY', 'disable' => true]);

        $this->assertContains('disallowed_category', $this->rules($this->valid([
            'item_category_ids' => [$petty->id],
        ])));
    }

    /** D12: `External_Payment == false` gates the rule, and the bypass is preserved. */
    #[Test]
    public function the_external_payment_api_bypasses_that_block(): void
    {
        $petty = ItemCategory::create(['name' => 'PETTY', 'disable' => true]);

        $this->assertNotContains('disallowed_category', $this->rules($this->valid([
            'item_category_ids' => [$petty->id], 'external_payment' => true,
        ])));
    }

    #[Test]
    public function whether_any_category_is_disabled_is_reported_rather_than_assumed(): void
    {
        // master-data has 0 of 135 set while the addendum records PETTY and INTERNAL
        // TRANSFER as disabled live, so "nothing disallowed" and "we do not know" must be
        // distinguishable.
        $this->assertFalse(PaymentSaveRules::disabledCategoriesKnown());

        ItemCategory::create(['name' => 'INTERNAL TRANSFER', 'disable' => true]);

        $this->assertTrue(PaymentSaveRules::disabledCategoriesKnown());
    }

    // ------------------------------------------- a payment cannot be born Paid, :32097

    #[Test]
    public function a_new_payment_cannot_be_created_already_paid(): void
    {
        $this->assertContains('paid_on_create', $this->rules($this->valid([
            'status' => PaymentStatus::PAID,
        ])));
    }

    #[Test]
    public function an_existing_payment_may_be_moved_to_paid(): void
    {
        // The rule is about CREATION. `MarkPaymentPaid` is the legitimate route.
        $this->assertNotContains('paid_on_create', $this->rules($this->valid([
            'status' => PaymentStatus::PAID, 'is_new' => false,
        ])));
    }

    // -------------------------------------------------------------- reporting

    #[Test]
    public function first_reproduces_creators_single_message_while_check_returns_all(): void
    {
        $broken = $this->valid([
            'item_category_ids' => [], 'vendor_id' => null, 'coa_account_id' => null,
        ]);

        // Creator alerts once and cancels; the API reports everything (D13).
        $this->assertSame('Please add Item Category to proceed', (new PaymentSaveRules)->first($broken));
        $this->assertGreaterThanOrEqual(3, count((new PaymentSaveRules)->check($broken)));
    }
}
