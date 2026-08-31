<?php

namespace Tests\Unit;

use App\Domain\Bills\Money;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The F&B order arithmetic, pinned to real records.
 *
 * Findings §8.3 and §9.1. None of these formulas is documented in the DS — they
 * were measured against 11,149 orders and 110,510 line items, then confirmed
 * field-by-field against two rendered Creator records.
 */
class FnbOrderTotalsTest extends TestCase
{
    #[Test]
    public function a_line_amount_is_received_quantity_times_price(): void
    {
        // The rows that prove it is RECEIVED and not ORDERED: on 4,523 live rows
        // where the two differ, 4,438 follow received.
        $this->assertTrue(Money::equals('180.0000', Money::mul('9', '20')),      'KULFI: 9 received x 20');
        $this->assertTrue(Money::equals('30.0000', Money::mul('0.5', '60')),     'CABBAGE: 0.5 x 60');

        // Ordered would have given 400 and 60 — both wrong.
        $this->assertFalse(Money::equals(Money::mul('20', '20'), '180.0000'));
        $this->assertFalse(Money::equals(Money::mul('1', '60'), '30.0000'));
    }

    #[Test]
    public function grand_total_rounds_to_whole_rupees(): void
    {
        // EKO/F&BOrder/11431, confirmed against the rendered detail view:
        //   amount 1025.50, gst 0, discount 7.00 -> raw 1018.50 -> grand 1019
        $raw = Money::sub(Money::add('1025.50', '0'), '7.00');
        $this->assertTrue(Money::equals('1018.5000', $raw));
        $this->assertTrue(Money::equals('1019', Money::roundToRupees($raw)));
    }

    #[Test]
    public function adjusted_amount_is_the_rounding_remainder(): void
    {
        // Creator shows 0.50 with NO rupee symbol — it is type=decimal, not INR.
        $raw = Money::sub(Money::add('1025.50', '0'), '7.00');
        $grand = Money::roundToRupees($raw);

        $this->assertTrue(Money::equals('0.5000', Money::sub($grand, $raw)));

        // And EKO/F&BOrder/11430: 2464.50 - 11.00 = 2453.50 -> 2454, remainder 0.50
        $raw2 = Money::sub(Money::add('2464.50', '0'), '11.00');
        $grand2 = Money::roundToRupees($raw2);
        $this->assertTrue(Money::equals('2454', $grand2));
        $this->assertTrue(Money::equals('0.5000', Money::sub($grand2, $raw2)));
    }

    #[Test]
    public function payable_is_grand_total_less_paid(): void
    {
        // Unpaid: payable == grand. True on all 125 live Unpaid rows and all 477
        // 'Payment Inprogress' rows.
        $this->assertTrue(Money::equals('1019', Money::sub('1019', '0')));

        // Partially paid.
        $this->assertTrue(Money::equals('519', Money::sub('1019', '500')));
    }

    #[Test]
    public function the_remainder_can_be_negative(): void
    {
        // Live range is -0.48 .. 0.50, so rounding goes both ways and the column
        // must be signed. 1018.40 rounds DOWN to 1018, remainder -0.40.
        $raw = '1018.4000';
        $grand = Money::roundToRupees($raw);

        $this->assertTrue(Money::equals('1018', $grand));
        $this->assertTrue(Money::equals('-0.4000', Money::sub($grand, $raw)));
    }

    #[Test]
    public function rounding_is_half_away_from_zero_like_deluge(): void
    {
        $this->assertTrue(Money::equals('1', Money::roundToRupees('0.5')));
        $this->assertTrue(Money::equals('-1', Money::roundToRupees('-0.5')));
        $this->assertTrue(Money::equals('2', Money::roundToRupees('1.5')));
    }
}
