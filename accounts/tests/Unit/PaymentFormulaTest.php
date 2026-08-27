<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Payments\PayableFormula;
use App\Domain\Payments\PaymentNumber;
use App\Domain\Payments\PaymentSplitValidator;
use App\Domain\Payments\PaymentStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The two §16 "blocking write paths" questions that turned out to be DEFECTS
 * rather than conventions, pinned as arithmetic.
 *
 *   §7.2  the partially-paid TDS sign
 *   §7.6  payment-number padding
 *
 * Plus the §7.4 balance check Payments has never had. No database — this is all
 * pure arithmetic and string handling, which is the level the bugs live at.
 */
class PaymentFormulaTest extends TestCase
{
    // ------------------------------------------------- §7.2, the sign convention

    /**
     * The heart of it. Creator's partially-paid line ADDS withholding tax.
     *
     * gross 100000 + GST 18000 = total 118000, TDS 2000:
     *   correct : 118000 - 2000  = 116000
     *   Creator : 118000 - 18000 + 2000 = 102000
     *
     * Creator's figure is lower here because GST dominates — but the vendor is
     * still paid the wrong amount, and on a no-GST bill it is paid MORE.
     */
    #[Test]
    public function the_partially_paid_path_deducts_tds_where_creator_added_it(): void
    {
        $this->assertSame('116000.0000', PayableFormula::partiallyPaid('118000', '2000'));
        $this->assertSame('102000.0000', PayableFormula::creatorPartiallyPaid('118000', '18000', '2000'));
    }

    /**
     * The divergence is exactly `2*TDS - GST`, for any figures.
     *
     * Proved against the two functions rather than restated: if either changes,
     * this fails.
     */
    #[Test]
    #[DataProvider('signProvider')]
    public function creator_diverges_by_exactly_two_tds_minus_gst(
        string $total,
        string $gst,
        string $tds,
    ): void {
        $correct = PayableFormula::partiallyPaid($total, $tds);
        $creator = PayableFormula::creatorPartiallyPaid($total, $gst, $tds);

        $this->assertSame(
            PayableFormula::creatorOvercharge($gst, $tds),
            bcsub($creator, $correct, 4),
            "divergence should be 2*TDS - GST for total={$total} gst={$gst} tds={$tds}",
        );
    }

    public static function signProvider(): array
    {
        return [
            'with GST' => ['118000', '18000', '2000'],
            'no GST — the worst case' => ['100000', '0', '2000'],
            'GST exactly twice TDS — the two agree' => ['110000', '4000', '2000'],
            'paisa-level' => ['1234.5600', '111.1100', '12.3400'],
            'zero tds' => ['50000', '9000', '0'],
        ];
    }

    /**
     * THE CASE THAT COSTS REAL MONEY: a vendor with TDS and no GST is overpaid by
     * exactly twice the withholding.
     *
     * §10.3 flags that the GST/TDS basis differs across three modules, so no-GST
     * bills are not a corner case in this data — they are ordinary.
     */
    #[Test]
    public function a_no_gst_vendor_was_overpaid_by_twice_the_tds(): void
    {
        $correct = PayableFormula::partiallyPaid('100000', '2000');
        $creator = PayableFormula::creatorPartiallyPaid('100000', '0', '2000');

        $this->assertSame('98000.0000', $correct);
        $this->assertSame('102000.0000', $creator);
        $this->assertSame('4000.0000', bcsub($creator, $correct, 4));  // 2 x 2000
    }

    /** The forward path is unchanged from Creator, and must stay that way. */
    #[Test]
    public function the_forward_path_is_untouched(): void
    {
        $this->assertSame('116000.0000', PayableFormula::forward('118000', '2000'));
    }

    // ------------------------------------------------------- §7.6, the padding

    /**
     * The live counter is 20938, so nothing pads. This is the answer to the
     * "fix or preserve" question: neither, the branches are dead.
     */
    #[Test]
    public function the_live_counter_is_never_padded(): void
    {
        $this->assertSame('EKS/PY/20938', PaymentNumber::format('EKS/PY', 20938));
        $this->assertSame('EKS/PY/20938', PaymentNumber::creatorFormat('EKS/PY', 20938));
    }

    /**
     * The unchained third `if` demonstrated on the range historical data occupies.
     *
     * 1..99 get FIVE digits because a padded branch and the unchained one both
     * fire; 100..999 get four; 1000+ get none. Three different widths, which is
     * why sorting old payment numbers as strings mis-orders them.
     */
    #[Test]
    #[DataProvider('paddingProvider')]
    public function creator_padding_produced_inconsistent_widths(int $number, string $expected, int $digits): void
    {
        $this->assertSame($expected, PaymentNumber::creatorFormat('EKS/PY', $number));
        $this->assertSame($digits, strlen(substr($expected, strrpos($expected, '/') + 1)));
    }

    public static function paddingProvider(): array
    {
        return [
            'single digit -> 5 wide' => [5, 'EKS/PY/00005', 5],
            'two digits -> 5 wide' => [50, 'EKS/PY/00050', 5],
            'three digits -> 4 wide' => [500, 'EKS/PY/0500', 4],
            'four digits -> unpadded' => [5000, 'EKS/PY/5000', 4],
            'the live counter -> unpadded' => [20938, 'EKS/PY/20938', 5],
        ];
    }

    /** A blank series must not yield a leading slash. */
    #[Test]
    public function a_missing_series_does_not_produce_a_leading_slash(): void
    {
        $this->assertSame('20938', PaymentNumber::format(null, 20938));
        $this->assertSame('20938', PaymentNumber::format('  ', 20938));
    }

    // --------------------------------------------------- §7.4, the balance check

    #[Test]
    public function balanced_legs_pass(): void
    {
        $result = PaymentSplitValidator::check(
            [['amount' => '58000.0000'], ['amount' => '58000.0000']],
            '116000.0000',
        );

        $this->assertTrue($result['balanced']);
        $this->assertSame('0.0000', $result['difference']);
    }

    /**
     * A ONE-PAISA drift is rejected.
     *
     * Deliberately stricter than Bills, which compares at whole rupees (§6.4).
     * Payment legs are computed rather than typed, so a paisa of drift is an
     * arithmetic fault, and rounding it away hides the cause.
     */
    #[Test]
    public function a_single_paisa_of_drift_is_rejected(): void
    {
        $result = PaymentSplitValidator::check(
            [['amount' => '58000.0000'], ['amount' => '58000.0100']],
            '116000.0000',
        );

        $this->assertFalse($result['balanced']);
        $this->assertSame('0.0100', $result['difference']);
        $this->assertStringContainsString('0.0100', PaymentSplitValidator::message($result));
    }

    #[Test]
    public function a_missing_leg_amount_counts_as_zero_and_unbalances(): void
    {
        $result = PaymentSplitValidator::check(
            [['amount' => '58000.0000'], ['amount' => null]],
            '116000.0000',
        );

        $this->assertFalse($result['balanced']);
        $this->assertSame('-58000.0000', $result['difference']);
    }

    // ----------------------------------------------------- §7.3, the dirty enums

    /** Both live spellings of the same state fold to one. */
    #[Test]
    public function the_two_approval_spellings_fold_together(): void
    {
        $this->assertSame(
            PaymentStatus::normaliseStatus('Sent for Approval'),
            PaymentStatus::normaliseStatus('Send for Approval'),
        );
    }

    /** "Open" is not in the declared picklist but is live — it must survive. */
    #[Test]
    public function the_undeclared_open_status_survives_normalisation(): void
    {
        $this->assertSame('Open', PaymentStatus::normalisePaymentStatus('Open'));
        $this->assertSame('Open', PaymentStatus::normalisePaymentStatus('open'));
    }

    /**
     * The canonical stored form of paid stays LOWERCASE, per CLAUDE.md: source
     * spellings are preserved in data and normalised only at display.
     */
    #[Test]
    public function paid_stays_lowercase_in_data_and_is_capitalised_only_for_display(): void
    {
        $this->assertSame('paid', PaymentStatus::normalisePaymentStatus('Paid'));
        $this->assertSame('Paid', PaymentStatus::label('paid'));
    }

    /** An unknown value passes through rather than throwing — 59,063 lines is not a proof. */
    #[Test]
    public function an_unknown_status_passes_through_trimmed(): void
    {
        $this->assertSame('Something New', PaymentStatus::normaliseStatus('  Something New  '));
    }

    #[Test]
    public function the_paid_lock_covers_both_axes_and_reversal(): void
    {
        $this->assertTrue(PaymentStatus::isLocked('Paid', null));
        $this->assertTrue(PaymentStatus::isLocked(null, 'paid'));
        $this->assertTrue(PaymentStatus::isLocked(null, 'Reverse'));
        $this->assertFalse(PaymentStatus::isLocked('Submit for Approval', 'Open'));
    }
}
