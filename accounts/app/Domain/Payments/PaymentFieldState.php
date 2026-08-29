<?php

declare(strict_types=1);

namespace App\Domain\Payments;

/**
 * The Payment form CHANGES SHAPE with the COA — `Accounts.ds:24240`.
 *
 * Husain: "When I select COA accounts payable in payments we have vendor order
 * booking no which is a lookup for the bills. I want the flow to be exact same how
 * it is right now."
 *
 * It is not one form with a couple of conditional fields. Creator hides eight fields
 * and enables a ninth on one branch and does the reverse on the other, so the two COA
 * branches are genuinely different screens over one table:
 *
 *     if (COA.Account_Name == "Accounts Payable")
 *     {
 *         hide Bill_Payments;
 *         disable Bill_No;  disable Bill_No1;
 *         disable Villa_Name;  disable Location;  disable Head_Office;
 *         hide Amount;                    enable Payable_Amount;
 *         hide TDS;  hide TDS_Amount;  hide GST;  hide GST_Amount;
 *
 *         if (Status == "Paid")
 *         {
 *             disable Bill_No1;
 *             disable Vendor_Order_Booking_No;
 *         }
 *     }
 *     else
 *     {
 *         show Amount;                    disable Payable_Amount;
 *         hide Bill_No1;  hide Vendor_Order_Booking_No;  hide Bill_Payments;
 *     }
 *
 * This explains the live screenshot exactly: with Accounts Payable selected the form
 * shows COA, Requested Date, Payment Date, Due Date, Item Category, Master Category,
 * Payment Mode, Bank Name, Status and Expense By — and NO Amount, NO TDS, NO GST,
 * because this branch hides all four.
 *
 * WHY THIS IS ONE CLASS AND NOT TWO IMPLEMENTATIONS. A browser-side `disable` is not
 * a security boundary, and the working style here is explicit that every prototype
 * rule is re-implemented server-side. But writing it twice is how a form and its
 * guard drift — the same argument that put column order in one `ReportRegistry` read
 * by both the report controller and the write controller. So the form asks this class
 * what to render and `PaymentController::update()` asks it what to refuse.
 *
 * NOTE THE ASYMMETRY, because it is Creator's and not a transcription slip: on the
 * Accounts Payable branch `Bill_No` and `Bill_No1` are DISABLED (visible, greyed —
 * they are populated FROM the bill), while on the other branch `Bill_No1` and
 * `Vendor_Order_Booking_No` are HIDDEN outright. Disabled and hidden are different
 * states and the DS uses both deliberately.
 */
final class PaymentFieldState
{
    /** The COA that switches the form. Compared case-insensitively; see §10. */
    public const ACCOUNTS_PAYABLE = 'Accounts Payable';

    /**
     * DS field name to our column, because they are not the same names.
     *
     * TWO OF THESE HAVE NO COLUMN YET and are mapped to `null` rather than quietly
     * omitted. `Bill_No1` is the vendor-scoped bill multi-select Husain asked for —
     * "I dont have the option to enter the bill number in payments add page" — and
     * `Vendor_Order_Booking_No` is the lookup beside it. Both are declared on the
     * Creator form, both are unbuilt here, and `PaymentSaveRules` already enforces the
     * rule that ties them together (`Accounts.ds:28567`: on Accounts Payable a payment
     * must name one or the other), which currently cannot be SATISFIED from our form
     * because neither field exists to fill in.
     *
     * Keeping them in the map with a null column makes that a visible gap rather than
     * an absence — `missingColumns()` reports it and a test asserts the list, so this
     * shrinks as the fields land instead of being forgotten.
     *
     * @var array<string, string|null>
     */
    public const COLUMN_FOR = [
        'Bill_No' => 'bill_id',
        'Bill_No1' => null,
        'Vendor_Order_Booking_No' => null,
        'Villa_Name' => 'villa_id',
        'Location' => 'location_id',
        'Head_Office' => 'head_office_id',
        'Amount' => 'amount',
        'Payable_Amount' => 'payable_amount',
        'TDS' => 'tds_rate_id',
        'TDS_Amount' => 'tds_amount',
        'GST' => 'tax_id',
        'GST_Amount' => 'gst_amount',
        'Bill_Payments' => null,
    ];

    /**
     * Field states, keyed by DS field name, for a payment on this COA and status.
     *
     * @return array<string, 'editable'|'disabled'|'hidden'>
     */
    public static function forDsFields(?string $coaAccountName, ?string $status): array
    {
        if (! self::isAccountsPayable($coaAccountName)) {
            return [
                'Amount' => 'editable',
                'Payable_Amount' => 'disabled',
                'Bill_No1' => 'hidden',
                'Vendor_Order_Booking_No' => 'hidden',
                'Bill_Payments' => 'hidden',
            ];
        }

        $state = [
            'Bill_Payments' => 'hidden',
            'Bill_No' => 'disabled',
            'Bill_No1' => 'disabled',
            'Villa_Name' => 'disabled',
            'Location' => 'disabled',
            'Head_Office' => 'disabled',
            'Amount' => 'hidden',
            'Payable_Amount' => 'editable',
            'TDS' => 'hidden',
            'TDS_Amount' => 'hidden',
            'GST' => 'hidden',
            'GST_Amount' => 'hidden',
            'Vendor_Order_Booking_No' => 'editable',
        ];

        /*
         * §6.5's Paid lock, which the spec has carried as an open [TODO] since it was
         * written — "the scope of the Paid field lock" — and this is the first evidence
         * either way. It is NARROW: two fields, and only on the Accounts Payable
         * branch. It says nothing about the other branch, so nothing is inferred for
         * it here. A partial answer, not a closed question.
         */
        if (PaymentStatus::normaliseStatus($status) === PaymentStatus::PAID) {
            $state['Bill_No1'] = 'disabled';
            $state['Vendor_Order_Booking_No'] = 'disabled';
        }

        return $state;
    }

    /**
     * The same states keyed by OUR column names, for fields that have a column.
     *
     * @return array<string, 'editable'|'disabled'|'hidden'>
     */
    public static function for(?string $coaAccountName, ?string $status): array
    {
        $mapped = [];

        foreach (self::forDsFields($coaAccountName, $status) as $dsField => $state) {
            $column = self::COLUMN_FOR[$dsField] ?? null;

            if ($column !== null) {
                $mapped[$column] = $state;
            }
        }

        return $mapped;
    }

    /**
     * Columns a save must not change, given the COA and status.
     *
     * Both `disabled` and `hidden` land here. A hidden field is not an editable one:
     * Creator never sends it, so a request that sets it is not reproducing the form.
     *
     * @return list<string>
     */
    public static function readOnly(?string $coaAccountName, ?string $status): array
    {
        return array_keys(array_filter(
            self::for($coaAccountName, $status),
            static fn (string $s): bool => $s !== 'editable',
        ));
    }

    /**
     * DS fields this app cannot yet render or guard, because they have no column.
     *
     * @return list<string>
     */
    public static function missingColumns(): array
    {
        return array_keys(array_filter(
            self::COLUMN_FOR,
            static fn (?string $column): bool => $column === null,
        ));
    }

    /**
     * Case-insensitive, deliberately.
     *
     * Addendum §10 records Creator disagreeing with itself on the spelling of a status
     * in the same codebase, so every comparison in this domain normalises rather than
     * trusting a literal.
     */
    public static function isAccountsPayable(?string $coaAccountName): bool
    {
        return $coaAccountName !== null
            && mb_strtolower(trim($coaAccountName)) === mb_strtolower(self::ACCOUNTS_PAYABLE);
    }
}
