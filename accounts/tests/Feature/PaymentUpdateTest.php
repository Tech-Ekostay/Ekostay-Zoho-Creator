<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Payments\PaymentFieldState;
use App\Domain\Payments\PaymentStatus;
use App\Models\BillingCycle;
use App\Models\CoaAccount;
use App\Models\ItemCategory;
use App\Models\Location;
use App\Models\Payment;
use App\Models\Vendor;
use App\Models\Villa;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `Update Payment` — the route that did not exist.
 *
 * Husain: "Right now, on edit nothing is working." The module refused to open an edit
 * form at all, citing §7.6. That was wrong: §7.6 forbids DELETING a settled payment and
 * REISSUING a number, and the DS gives All Payments' `Update Payment` custom action no
 * `condition` whatsoever, so Creator lets any payment be opened and saved.
 *
 * These tests pin the four things that make this NOT simply `storeDirect` with an id:
 * the number never moves, the create-only rules do not fire, the `Accounts.ds:24240`
 * field lock is enforced, and the legs reconcile rather than rebuild.
 */
class PaymentUpdateTest extends TestCase
{
    use RefreshDatabase;

    private CoaAccount $expense;
    private CoaAccount $payable;
    private CoaAccount $bank;
    private ItemCategory $category;
    private Vendor $vendor;
    private BillingCycle $cycle;
    private Villa $villa;

    protected function setUp(): void
    {
        parent::setUp();

        $this->expense = CoaAccount::create(['account_name' => 'Expense']);
        $this->payable = CoaAccount::create(['account_name' => 'Accounts Payable']);
        $this->bank = CoaAccount::create(['account_name' => 'EKOSTAY LLP 1', 'bank' => true]);
        $this->category = ItemCategory::create(['name' => 'PRINTING']);
        $this->vendor = Vendor::create(['name' => 'PAINT SPARK']);
        $this->cycle = BillingCycle::create(['month_name' => 'August', 'year' => '2026', 'month_index' => 8]);
        $this->villa = Villa::create(['name' => 'Casa Bella', 'rent_type' => 'Lease']);
    }

    /**
     * A payment Creator would have saved, WITH its split leg.
     *
     * The leg is not optional scenery: `Accounts.ds:28536` refuses a payment whose
     * legs do not carry the amount ("Please add the Split amount to continue."), so a
     * legless fixture is not a payment Creator could have produced and every edit of
     * one fails for the wrong reason.
     */
    private function payment(array $overrides = []): Payment
    {
        $payment = Payment::create(array_merge([
            'payment_no' => 'EKS/PY/20938',
            'status' => PaymentStatus::DRAFT,
            'payment_status' => 'Pending',
            'coa_account_id' => $this->expense->id,
            'vendor_id' => $this->vendor->id,
            'bank_coa_account_id' => $this->bank->id,
            'item_category_id' => $this->category->id,
            'particulars' => 'Printing for Casa Bella',
            'amount' => '5000.0000',
            'total_amount' => '5000.0000',
            'payable_amount' => '5000.0000',
            'payment_date' => '2026-08-28',
        ], $overrides));

        $payment->splitPayments()->create([
            'villa_id' => $this->villa->id,
            'item_category_id' => $this->category->id,
            'billing_cycle_id' => $this->cycle->id,
            'amount' => $payment->amount,
            'position' => 0,
        ]);

        return $payment->fresh();
    }

    /** The request body Creator's form would post for a minimal, legal edit. */
    private function body(array $overrides = []): array
    {
        return array_merge([
            'item_category_id' => $this->category->id,
            'vendor_id' => $this->vendor->id,
            'coa_account_id' => $this->expense->id,
            'bank_coa_account_id' => $this->bank->id,
            'billing_cycle_ids' => [$this->cycle->id],
            'particulars' => 'Printing for Casa Bella',
            'amount' => '5000.0000',
            'total_amount' => '5000.0000',
            'payment_date' => '2026-08-28',
        ], $overrides);
    }

    // ------------------------------------------------------------ the route exists

    #[Test]
    public function a_payment_can_be_edited_because_creator_always_enables_update_payment(): void
    {
        $payment = $this->payment();

        $this->patchJson("/api/payments/{$payment->id}", $this->body([
            'remarks' => 'Revised after the vendor call',
        ]))->assertOk();

        $this->assertSame('Revised after the vendor call', $payment->fresh()->remarks);
    }

    #[Test]
    public function editing_never_moves_the_payment_number(): void
    {
        $payment = $this->payment();

        // §7.6 and D3. `payment_no` is not in `fieldRules()` at all, so even a caller
        // that tries to set it cannot: it is dropped before the controller sees it.
        $this->patchJson("/api/payments/{$payment->id}", $this->body([
            'payment_no' => 'EKS/PY/99999',
            'remarks' => 'nice try',
        ]))->assertOk();

        $this->assertSame('EKS/PY/20938', $payment->fresh()->payment_no);
    }

    #[Test]
    public function a_paid_payment_is_still_saveable_because_that_rule_is_create_only(): void
    {
        /*
         * `Accounts.ds:32097` — "Paid Status can't be created". It is a CREATE rule and
         * `storeDirect` is right to enforce it, but a payment that reached `Paid` by
         * walking the flow must remain editable or `Update Payment` would be dead on
         * exactly the records people most often need to correct.
         */
        $payment = $this->payment(['status' => PaymentStatus::PAID]);

        $this->patchJson("/api/payments/{$payment->id}", $this->body([
            'status' => PaymentStatus::PAID,
            'remarks' => 'UTR corrected',
        ]))->assertOk();

        $this->assertSame('UTR corrected', $payment->fresh()->remarks);
    }

    #[Test]
    public function a_partial_edit_is_judged_against_the_stored_record(): void
    {
        /*
         * The regression this guards: running the 22 rules on the REQUEST alone means a
         * PATCH carrying only `remarks` fails "Please Select vendot to proceed" for a
         * vendor that is sitting in the database. Every partial edit would be
         * impossible and the failure would look like a validation bug.
         */
        $payment = $this->payment();

        $this->patchJson("/api/payments/{$payment->id}", [
            'remarks' => 'Just the remarks',
        ])->assertOk();

        $this->assertSame('Just the remarks', $payment->fresh()->remarks);
        $this->assertSame($this->vendor->id, $payment->fresh()->vendor_id);
    }

    // -------------------------------------------- the field lock, Accounts.ds:24240

    #[Test]
    public function on_accounts_payable_the_gross_amount_is_hidden_and_cannot_be_written(): void
    {
        $payment = $this->payment([
            'coa_account_id' => $this->payable->id,
            'payable_amount' => '5000.0000',
        ]);

        $response = $this->patchJson("/api/payments/{$payment->id}", $this->body([
            'coa_account_id' => $this->payable->id,
            'amount' => '9999.0000',
        ]));

        $response->assertStatus(422)->assertJsonPath('reason', 'field_locked');
        $this->assertSame('5000.0000', $payment->fresh()->amount);
    }

    #[Test]
    public function off_accounts_payable_the_payable_amount_is_disabled_instead(): void
    {
        // The branches are mirror images: whichever amount field one enables, the other
        // disables. Both directions are tested because a transcription that lost the
        // `else` would pass the first test and fail this one.
        $payment = $this->payment();

        $this->patchJson("/api/payments/{$payment->id}", $this->body([
            'payable_amount' => '9999.0000',
        ]))->assertStatus(422)->assertJsonPath('reason', 'field_locked');

        $this->assertSame('5000.0000', $payment->fresh()->payable_amount);
    }

    #[Test]
    public function resending_a_locked_field_unchanged_is_not_an_edit(): void
    {
        /*
         * Creator's form POSTS disabled fields — they are greyed, not absent. If the
         * guard rejected them the lock would be unsatisfiable from the very screen
         * Creator draws, and every save would fail on a field the user never touched.
         *
         * Tested on the Expense branch, where `Payable_Amount` is the disabled one.
         * The Accounts Payable branch cannot currently reach this code at all — see
         * the next test, which is the more interesting fact.
         */
        $payment = $this->payment();

        $this->patchJson("/api/payments/{$payment->id}", $this->body([
            'payable_amount' => '5000.0000',   // unchanged, and disabled on this branch
            'remarks' => 'fine',
        ]))->assertOk();

        $this->assertSame('fine', $payment->fresh()->remarks);
    }

    #[Test]
    public function no_accounts_payable_payment_can_be_saved_until_the_bill_fields_exist(): void
    {
        /*
         * A REAL BLOCKAGE, found by running this test rather than by reading the code,
         * and it is the direct consequence of Husain's "I dont have the option to enter
         * the bill number in payments add page."
         *
         * `Accounts.ds:28567` refuses any Accounts Payable payment that names neither a
         * Bill No nor a Vendor Order Booking. `PaymentSaveRules` enforces that
         * faithfully. But `Bill_No1` and `Vendor_Order_Booking_No` have NO COLUMN here,
         * so there is no way to satisfy the rule from our form — which makes every
         * Accounts Payable payment permanently unsaveable, on create AND on edit.
         *
         * The rule is right and the schema is incomplete, so this is pinned as the
         * current truth rather than worked around. It fails the day the fields land,
         * which is exactly when it should be revisited. Tied to
         * `PaymentFieldState::missingColumns()`.
         */
        $payment = $this->payment(['coa_account_id' => $this->payable->id]);

        $this->patchJson("/api/payments/{$payment->id}", $this->body([
            'coa_account_id' => $this->payable->id,
            'amount' => '5000.0000',
            'remarks' => 'fine',
        ]))
            ->assertStatus(422)
            ->assertJsonPath('reason', 'creator_validation')
            ->assertJsonPath('broken_rules.0.rule', 'bill_or_booking')
            ->assertJsonPath('message', 'Please Select Bill No or Vendor Order Booking to proceed');
    }

    #[Test]
    public function the_lock_follows_the_coa_the_save_will_land_on_not_the_stored_one(): void
    {
        /*
         * Creator re-runs the branch `on user input of COA`, so moving a payment ONTO
         * Accounts Payable hides Amount in the same interaction. Reading the stored COA
         * would let exactly one save through the gap — the one that changes the COA.
         */
        $payment = $this->payment();       // stored on Expense, where Amount is editable

        $this->patchJson("/api/payments/{$payment->id}", $this->body([
            'coa_account_id' => $this->payable->id,
            'amount' => '9999.0000',
        ]))->assertStatus(422)->assertJsonPath('reason', 'field_locked');
    }

    #[Test]
    public function paid_and_payable_locks_the_two_bill_fields_per_6_5(): void
    {
        // §6.5's Paid lock, the first evidence ever found for it. NARROW: two fields,
        // and only on the Accounts Payable branch.
        $states = PaymentFieldState::forDsFields('Accounts Payable', PaymentStatus::PAID);

        $this->assertSame('disabled', $states['Bill_No1']);
        $this->assertSame('disabled', $states['Vendor_Order_Booking_No']);

        // ...and NOT locked before Paid, which is what makes it a lock rather than a
        // permanent disable.
        $draft = PaymentFieldState::forDsFields('Accounts Payable', PaymentStatus::DRAFT);
        $this->assertSame('editable', $draft['Vendor_Order_Booking_No']);
    }

    #[Test]
    public function the_two_unbuilt_fields_are_declared_rather_than_silently_absent(): void
    {
        /*
         * Husain: "I dont have the option to enter the bill number in payments add
         * page." Still true — `Bill_No1` and `Vendor_Order_Booking_No` have no column
         * here. This test exists so that stays VISIBLE: it fails the day someone adds
         * the columns without updating the map, and it fails if someone quietly drops
         * the entries to make the map look complete.
         */
        $this->assertSame(
            ['Bill_No1', 'Vendor_Order_Booking_No', 'Bill_Payments'],
            PaymentFieldState::missingColumns(),
        );
    }

    // ------------------------------------------------------------ the split legs

    #[Test]
    public function the_legs_reconcile_and_do_not_get_rebuilt(): void
    {
        /*
         * §5.1/§15.1 — never clear and rebuild. A delete-then-insert mints new ids for
         * rows that did not change, and every downstream reference to a leg (the
         * `Backend_*` snapshot especially) would point at a row that no longer exists.
         */
        $villa = $this->villa;
        $payment = $this->payment();

        $originalId = $payment->splitPayments()->first()->id;

        $this->patchJson("/api/payments/{$payment->id}", $this->body([
            'amount' => '8000.0000',
            'total_amount' => '8000.0000',
            'legs' => [[
                'villa_id' => $villa->id,
                'item_category_id' => $this->category->id,
                'billing_cycle_id' => $this->cycle->id,
                'amount' => '8000.0000',
            ]],
        ]))->assertOk();

        $legs = $payment->fresh()->splitPayments;

        $this->assertCount(1, $legs);
        $this->assertSame($originalId, $legs->first()->id, 'the leg was rebuilt, not reconciled');
        $this->assertSame('8000.0000', $legs->first()->amount);
    }

    #[Test]
    public function an_edit_that_unbalances_the_split_is_refused(): void
    {
        $villa = $this->villa;
        $payment = $this->payment();

        // Raise the gross and leave the legs behind — §6.4 rule 1 ties them together,
        // and this is the path that would silently misstate a villa-month figure.
        /*
         * REFUSED BY CREATOR'S OWN RULE, not by our arithmetic gate — `reason` is
         * `creator_validation` and not `unbalanced_split`, which is what this test
         * originally asserted and which was the wrong expectation.
         *
         * `Accounts.ds:28540` already ties the legs to the payable amount, so
         * `PaymentSaveRules` catches this first and refuses it in Creator's own words.
         * Our gate behind it is not redundant: it survives if that rule is ever gated
         * off (it is conditional on `Accounts_Bills`), and it reports the two FIGURES
         * and their difference, which a fixed message cannot. But the faithful refusal
         * is the one the user would have seen in Creator, so it goes first.
         */
        $this->patchJson("/api/payments/{$payment->id}", $this->body([
            'amount' => '8000.0000',
            'total_amount' => '8000.0000',
        ]))
            ->assertStatus(422)
            ->assertJsonPath('reason', 'creator_validation')
            ->assertJsonPath('broken_rules.0.rule', 'split_balance');

        $this->assertSame('5000.0000', $payment->fresh()->amount);
    }

    #[Test]
    public function omitting_the_legs_leaves_the_grid_alone(): void
    {
        // `null` legs means "this request did not touch the grid", which must stay
        // distinguishable from "the grid is now empty" — the reason this route is
        // PATCH and not PUT.
        $villa = $this->villa;
        $payment = $this->payment();

        $this->patchJson("/api/payments/{$payment->id}", ['remarks' => 'no legs here'])
            ->assertOk();

        $this->assertCount(1, $payment->fresh()->splitPayments);
    }

    // ------------------------------------------------- D4's pair stays immutable

    #[Test]
    public function a_reversing_entry_cannot_be_edited(): void
    {
        /*
         * A DEVIATION, and stated as one: Creator would allow this, because Creator has
         * no reversal model — it hard-deletes at 14 unguarded sites. Ours nets a villa
         * x category x cycle to zero across two records, and editing either half
         * silently breaks the one property D4 exists to guarantee.
         */
        $original = $this->payment();
        $reversal = $this->payment([
            'payment_no' => 'EKS/PY/20939',
            'reverses_payment_id' => $original->id,
            'amount' => '-5000.0000',
        ]);

        $this->patchJson("/api/payments/{$reversal->id}", ['remarks' => 'edit the reversal'])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'reversal_pair_immutable');

        // And the forward half is equally protected.
        $this->patchJson("/api/payments/{$original->id}", ['remarks' => 'edit the original'])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'reversal_pair_immutable');
    }

    // ---------------------------------------------- what the form is told to render

    #[Test]
    public function show_tells_the_form_which_fields_are_locked(): void
    {
        /*
         * Sent rather than re-derived in the browser. A form computing this
         * independently is a form that can disagree with the guard that will reject it,
         * and the user finds out by having a save refused for a field the screen let
         * them type into.
         */
        $payment = $this->payment(['coa_account_id' => $this->payable->id]);

        $this->getJson("/api/payments/{$payment->id}")
            ->assertOk()
            ->assertJsonPath('is_accounts_payable', true)
            ->assertJsonPath('is_editable', true)
            ->assertJsonPath('field_states.amount', 'hidden')
            ->assertJsonPath('field_states.payable_amount', 'editable')
            ->assertJsonPath('field_states.gst_amount', 'hidden')
            ->assertJsonPath('field_states.location_id', 'disabled');
    }

    #[Test]
    public function show_reports_the_other_branch_as_the_mirror_image(): void
    {
        $payment = $this->payment();

        $this->getJson("/api/payments/{$payment->id}")
            ->assertOk()
            ->assertJsonPath('is_accounts_payable', false)
            ->assertJsonPath('field_states.amount', 'editable')
            ->assertJsonPath('field_states.payable_amount', 'disabled');
    }
}
