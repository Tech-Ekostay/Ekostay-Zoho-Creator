<?php

declare(strict_types=1);

namespace App\Domain\Payments;

/**
 * The two status axes (§7.3), and the mapping layer the spec asks for.
 *
 * §7.3: "Treat as dirty enums — preserve on import, normalise in a mapping layer."
 * That is exactly the split here. The SOURCE constants below are verbatim, spelling
 * mistakes and casing included, because they are live values:
 *
 *   - "Sent for Approval" AND "Send for Approval" both exist
 *   - Payment_Status has lowercase "paid"
 *   - Payment_Status = "Open" is confirmed in live data and is NOT in the declared
 *     picklist at all — Create_Payment writes it (Accounts.ds:45439)
 *
 * Nothing in this class rewrites stored data. `normalise*()` folds a stored value
 * to a canonical form for COMPARISON ONLY, per CLAUDE.md's rule: normalise at
 * display, never in data.
 */
final class PaymentStatus
{
    /** Axis 1, verbatim. Creator's declared init value is "Draft". */
    public const DRAFT = 'Draft';
    public const SUBMIT_FOR_APPROVAL = 'Submit for Approval';
    public const SENT_FOR_APPROVAL = 'Sent for Approval';
    public const SEND_FOR_APPROVAL = 'Send for Approval';   // sic — both are live
    public const APPROVED = 'Approved';
    public const APPROVAL_REJECTED = 'Approval Rejected';
    public const APPROVAL_NOT_REQUIRED = 'Approval Not Required';
    public const PAID = 'Paid';

    /** Axis 2, verbatim. */
    public const PS_PENDING = 'Pending';
    public const PS_PAID = 'paid';                          // sic — lowercase
    public const PS_CANCELLED = 'Cancelled';
    public const PS_REVERSE = 'Reverse';
    public const PS_OPEN = 'Open';                          // undeclared but live

    /**
     * The two spellings of the same state fold together. This is the only pair
     * where Creator disagrees with itself on axis 1.
     */
    private const STATUS_ALIASES = [
        'send for approval' => self::SENT_FOR_APPROVAL,
    ];

    /**
     * Terminal for editing purposes — the §6.5 "Paid lock".
     *
     * Its exact scope is still an open question (§16, "blocking specific modules"),
     * so this is deliberately the NARROW reading: a payment that has been paid or
     * reversed cannot be edited or re-reversed. Widening it later is safe;
     * starting wide and discovering a legitimate edit path is not.
     */
    public static function isLocked(?string $status, ?string $paymentStatus): bool
    {
        return self::normaliseStatus($status) === self::PAID
            || self::normalisePaymentStatus($paymentStatus) === self::PS_PAID
            || self::normalisePaymentStatus($paymentStatus) === self::PS_REVERSE;
    }

    /** Has this payment actually settled? Reversal is only legal once it has. */
    public static function isSettled(?string $status, ?string $paymentStatus): bool
    {
        return self::normaliseStatus($status) === self::PAID
            || self::normalisePaymentStatus($paymentStatus) === self::PS_PAID;
    }

    /**
     * Fold axis 1 to a canonical spelling. Case-insensitive because §3.3's role
     * data needed the same treatment (the two Market Head spellings), and the same
     * class of dirt is present here.
     */
    public static function normaliseStatus(?string $status): ?string
    {
        if ($status === null || trim($status) === '') {
            return null;
        }

        $folded = strtolower(trim($status));

        if (isset(self::STATUS_ALIASES[$folded])) {
            return self::STATUS_ALIASES[$folded];
        }

        foreach (self::statuses() as $canonical) {
            if (strtolower($canonical) === $folded) {
                return $canonical;
            }
        }

        // Unknown values pass through trimmed rather than throwing. 59,063 lines of
        // DS is not a guarantee that these five are the only ones ever written.
        return trim($status);
    }

    /**
     * Fold axis 2. Note the canonical form of paid is LOWERCASE "paid" — that is
     * what the source declares, and CLAUDE.md requires source spellings preserved.
     */
    public static function normalisePaymentStatus(?string $paymentStatus): ?string
    {
        if ($paymentStatus === null || trim($paymentStatus) === '') {
            return null;
        }

        $folded = strtolower(trim($paymentStatus));

        foreach (self::paymentStatuses() as $canonical) {
            if (strtolower($canonical) === $folded) {
                return $canonical;
            }
        }

        return trim($paymentStatus);
    }

    /** @return list<string> */
    public static function statuses(): array
    {
        return [
            self::DRAFT,
            self::SUBMIT_FOR_APPROVAL,
            self::SENT_FOR_APPROVAL,
            self::SEND_FOR_APPROVAL,
            self::APPROVED,
            self::APPROVAL_REJECTED,
            self::APPROVAL_NOT_REQUIRED,
            self::PAID,
        ];
    }

    /** @return list<string> */
    public static function paymentStatuses(): array
    {
        return [
            self::PS_PENDING,
            self::PS_PAID,
            self::PS_CANCELLED,
            self::PS_REVERSE,
            self::PS_OPEN,
        ];
    }

    /** Display form. "paid" is not shown lowercase to a user. */
    public static function label(?string $value): string
    {
        $value = trim((string) $value);

        return $value === '' ? '' : ucfirst($value);
    }
}
