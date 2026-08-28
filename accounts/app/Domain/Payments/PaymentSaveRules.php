<?php

declare(strict_types=1);

namespace App\Domain\Payments;

use App\Models\CoaAccount;
use App\Models\ItemCategory;
use Illuminate\Support\Collection;

/**
 * Creator's save-time rules for the Payment form, transcribed from the DS.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS EXISTS. Husain asked for the flow to work "exact same how it is right now",
 * and an audit of `Accounts.ds` found the gap precisely: the Payment form has **three
 * `on validate` handlers carrying 22 rules that refuse a save**, and this app enforced
 * two of them — the split balance (D2) and the delete guard (D4). Every other field was
 * `nullable` in `PaymentController::storeDirect()`, so a payment could be created with
 * no vendor, no COA, no billing cycle and no particulars.
 *
 * Source: `Accounts.ds:28524` (192 lines), `:30970` (505) and `:32089` (5), located with
 * the handler index in `docs/` rather than by grepping.
 *
 * ---------------------------------------------------------------------------
 * THE RULES, IN THE ORDER CREATOR CHECKS THEM. Order matters: Creator alerts on the
 * FIRST failure and cancels, so the message a user sees is the earliest broken rule.
 * `check()` returns them all, because an API that reports one problem per round trip is
 * worse than the form it replaces — but `first()` reproduces Creator's single message.
 *
 * ---------------------------------------------------------------------------
 * FOUR DEVIATIONS, ALL LOGGED.
 *
 * **D11 — the bank/Paid precedence bug is NOT reproduced.** `Accounts.ds:28563` reads
 * `if (Bank_Name == null && Status == "Submit for Approval" || Status == "Paid")`, and
 * `&&` binds tighter than `||`, so it evaluates as
 * `(bank is null AND submitting) OR (status is Paid)` — meaning **editing any Paid
 * payment demands a bank even when one is set.** Reproduced faithfully, that would make
 * a settled payment unsaveable. The evident intent is "a bank is required once the
 * payment is being submitted or is paid", and that is what this implements. Third
 * instance of the `&&`/`||` precedence class already on the register.
 *
 * **D10 — the bill picker's vendor scope.** Same precedence fault in the `Bill_No1`
 * lookup; see `PaymentController::bills()`.
 *
 * **D12 — `External_Payment` bypasses two rules and that bypass is preserved.**
 * `Accounts.ds:28671` and `:28706` gate the removelist and Disable checks on
 * `External_Payment == false`, so payments arriving through the `External.*` API may use
 * categories disallowed for manual creation. Reproduced rather than closed: the bypass
 * is load-bearing for an integration we do not own, and removing it here would reject
 * rows Creator accepts.
 *
 * **D13 — every rule reports, rather than the first.** See above.
 *
 * ---------------------------------------------------------------------------
 * ONE RULE CANNOT FIRE ON OUR DATA, and says so rather than passing silently.
 * `Disable == true` is set on **0 of 135** item categories in `master-data/`, though the
 * addendum records `PETTY` and `INTERNAL TRANSFER` as disabled live. The rule is
 * implemented and correct; it is the data that is missing, and `disabledCategoriesKnown()`
 * exists so a caller can tell the difference between "nothing is disallowed" and "we do
 * not know what is disallowed".
 */
final class PaymentSaveRules
{
    /** Creator's literal, uppercase on the category side. */
    private const STAFF_LOAN_CATEGORY = 'STAFF LOAN';

    /** And title case on the bank side. Both verbatim — see the docblock. */
    private const STAFF_LOAN_BANK = 'Staff Loan';

    private const ACCOUNTS_PAYABLE = 'Accounts Payable';

    /**
     * @param  array<string, mixed>  $input  the payment as submitted
     * @return list<array{rule: string, message: string}> empty means Creator would save it
     */
    public function check(array $input): array
    {
        $f = [];

        $itemCategoryIds = $this->ids($input['item_category_ids'] ?? $input['item_category_id'] ?? null);
        $billIds = $this->ids($input['bill_ids'] ?? $input['bill_no1'] ?? null);
        $bookingIds = $this->ids($input['vendor_order_booking_ids'] ?? null);
        $cycleIds = $this->ids($input['billing_cycle_ids'] ?? null);
        $external = (bool) ($input['external_payment'] ?? false);
        $accountsBills = (bool) ($input['accounts_bills'] ?? false);
        $status = trim((string) ($input['status'] ?? ''));

        $coa = $this->coa($input['coa_account_id'] ?? null);
        $bank = $this->coa($input['bank_coa_account_id'] ?? null);
        $categories = $itemCategoryIds === []
            ? new Collection
            : ItemCategory::whereIn('id', $itemCategoryIds)->get();

        // 1 — Accounts.ds:28531
        if ($itemCategoryIds === []) {
            $f[] = $this->fail('item_category', 'Please add Item Category to proceed');
        }

        // 2 — :28536. The typo is Creator's and is reproduced as the user sees it.
        if (($input['vendor_id'] ?? null) === null) {
            $f[] = $this->fail('vendor', 'Please Select vendot to proceed');
        }

        // 3 and 4 — :28540. Only when the Bill Payments grid is used AND Accounts_Bills
        // is off; that checkbox switches the reconciliation off entirely.
        $billLegs = $this->legs($input['bill_payments'] ?? []);

        if ($billLegs !== [] && ! $accountsBills) {
            $sum = '0.0000';

            foreach ($billLegs as $leg) {
                $sum = bcadd($sum, (string) ($leg['payable_amount'] ?? '0'), 4);
            }

            $payable = (string) ($input['payable_amount'] ?? '0');

            if (bccomp($sum, $payable, 4) !== 0) {
                $f[] = $this->fail('bill_payments_balance', 'Please match the payable amount with the bills ');
            } elseif (bccomp($payable, '0', 4) === 0) {
                $f[] = $this->fail('payable_amount_zero', 'Please update bills to make the payment ');
            }
        }

        // 5 — :28558
        if ($coa === null) {
            $f[] = $this->fail('coa', 'Please select COA to Proceed');
        }

        /*
         * 6 — :28563, DEVIATION D11. Creator's precedence makes this fire on every Paid
         * payment regardless of the bank. Implemented as the intent: required once the
         * payment is submitted for approval or paid.
         */
        if ($bank === null && in_array($status, [PaymentStatus::SUBMIT_FOR_APPROVAL, PaymentStatus::PAID], true)) {
            $f[] = $this->fail('bank', 'Please select bank to Proceed');
        }

        /*
         * 7 — :28567. THE RULE HUSAIN DESCRIBED: with COA = Accounts Payable, a payment
         * must name either a bill or a vendor order booking. This is what ties the
         * bill-derived payment to Accounts Payable (§20).
         */
        if ($coa !== null && $this->isAccountsPayable($coa) && $billIds === [] && $bookingIds === []) {
            $f[] = $this->fail(
                'bill_or_booking',
                'Please Select Bill No or Vendor Order Booking to proceed'
            );
        }

        // 8 — :28575
        if ($cycleIds === []) {
            $f[] = $this->fail('billing_cycles', 'Please add Billing Cycle');
        }

        // 9 — :28583
        if (trim((string) ($input['particulars'] ?? '')) === '') {
            $f[] = $this->fail('particulars', 'Please add Particulars to proceed');
        }

        // 10 — :28589
        if (! $this->positive($input['amount'] ?? null)) {
            $f[] = $this->fail('amount', 'Please add the Gross Amount to proceed');
        }

        // 11 and 12 — :28600. The split grid must exist and must tie to gross (D2).
        $splitLegs = $this->legs($input['legs'] ?? $input['split_payments'] ?? []);

        if ($splitLegs === []) {
            $f[] = $this->fail('split_missing', 'Please add the Split amount to continue.');
        } elseif ($this->positive($input['amount'] ?? null)) {
            $sum = '0.0000';

            foreach ($splitLegs as $leg) {
                $sum = bcadd($sum, (string) ($leg['amount'] ?? '0'), 4);
            }

            // Compared at whole rupees, as §6.4 rule 1 has it.
            if (bccomp(bcadd($sum, '0', 0), bcadd((string) $input['amount'], '0', 0), 0) !== 0) {
                $f[] = $this->fail(
                    'split_balance',
                    'Please Match the Gross Amount with the Split Amount to proceed'
                );
            }
        }

        // 13 — :28612
        if (trim((string) ($input['payment_date'] ?? '')) === '') {
            $f[] = $this->fail('payment_date', 'Please Select Payment Date to Proceed');
        }

        // 14 — :28629
        if (($input['amount'] ?? null) === null || ($input['total_amount'] ?? null) === null) {
            $f[] = $this->fail('amount_null', 'Amount and Total Amount cannot be null');
        }

        // 15 and 16 — :28677. Note the casing: STAFF LOAN on the category, Staff Loan on
        // the bank. Both are Creator's literals.
        $hasStaffLoan = $categories->contains(
            fn ($c) => str_contains(mb_strtoupper((string) $c->name), self::STAFF_LOAN_CATEGORY)
        );

        if ($hasStaffLoan && $categories->count() > 1) {
            $f[] = $this->fail(
                'staff_loan_exclusive',
                "When 'Staff Loan' is selected, no other item category can be selected."
            );
        }

        if ($hasStaffLoan && ! str_contains((string) ($bank->account_name ?? ''), self::STAFF_LOAN_BANK)) {
            $f[] = $this->fail(
                'staff_loan_bank',
                "When the item category is 'Staff Loan', the bank account must also be 'Staff Loan'."
            );
        }

        // 17 and 18 — :28687. The category carries its own COA and bank; the payment's
        // must be among them.
        if ($coa !== null) {
            $declared = $categories->pluck('coa_account_id')->filter()->unique();

            if ($declared->isNotEmpty() && ! $declared->contains($coa->id)) {
                $f[] = $this->fail('coa_mismatch', 'Please select the correct COA.');
            }
        }

        if ($bank !== null) {
            $declared = $categories->pluck('bank_coa_account_id')->filter()->unique();

            if ($declared->isNotEmpty() && ! $declared->contains($bank->id)) {
                $f[] = $this->fail('bank_mismatch', 'Please select the correct Bank Name.');
            }
        }

        // 19 — :28706. DEVIATION D12: the External_Payment bypass is preserved.
        if (! $external) {
            $disallowed = $categories->filter(fn ($c) => $c->disable === true);

            if ($disallowed->isNotEmpty()) {
                $f[] = $this->fail('disallowed_category', sprintf(
                    'Cannot select (%s) as it has been disallowed for manual creation',
                    $disallowed->pluck('name')->implode(', '),
                ));
            }
        }

        // 20 — :32097. A payment cannot be BORN paid; it has to walk the flow.
        if ($status !== '' && strcasecmp($status, PaymentStatus::PAID) === 0
            && ($input['is_new'] ?? true)) {
            $f[] = $this->fail('paid_on_create', "Paid Status can't be created");
        }

        return $f;
    }

    /** Creator's behaviour: the first broken rule is the message the user sees. */
    public function first(array $input): ?string
    {
        return $this->check($input)[0]['message'] ?? null;
    }

    /**
     * Is the Disable flag knowable from our data at all?
     *
     * `false` means no category carries it, which is indistinguishable from "nothing is
     * disallowed" unless a caller asks. `master-data/All_Item_Categories.json` has 0 of
     * 135 set while the addendum records PETTY and INTERNAL TRANSFER as disabled live,
     * so the honest answer is that we do not know.
     */
    public static function disabledCategoriesKnown(): bool
    {
        return ItemCategory::where('disable', true)->exists();
    }

    private function isAccountsPayable(CoaAccount $coa): bool
    {
        return strcasecmp(trim((string) $coa->account_name), self::ACCOUNTS_PAYABLE) === 0;
    }

    private function coa(mixed $id): ?CoaAccount
    {
        return $id === null ? null : CoaAccount::find($id);
    }

    /** @return list<int> */
    private function ids(mixed $v): array
    {
        if ($v === null || $v === '' || $v === []) {
            return [];
        }

        return array_values(array_filter(array_map('intval', (array) $v)));
    }

    /** @return list<array<string, mixed>> */
    private function legs(mixed $v): array
    {
        return is_array($v) ? array_values(array_filter($v, 'is_array')) : [];
    }

    private function positive(mixed $v): bool
    {
        return $v !== null && $v !== '' && bccomp((string) $v, '0', 4) > 0;
    }

    /** @return array{rule: string, message: string} */
    private function fail(string $rule, string $message): array
    {
        return ['rule' => $rule, 'message' => $message];
    }
}
