<?php

namespace Tests\Feature;

use App\Domain\Fnb\FnbNumber;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * F&B number allocation. Findings §4.4, and Creator's F_B.ds:6710.
 *
 * The two defects under test are the ones Accounts logged as D3 and §7.6: a
 * non-atomic read-modify-write, and a padding chain that cannot fire and is
 * miswritten anyway.
 */
class FnbNumberTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    #[Test]
    public function the_counters_carry_their_real_live_values(): void
    {
        // Starting at 1 would re-mint numbers belonging to real orders. The live
        // maxima are EKO/F&BOrder 11,435 over 11,205 orders and EKO/Stock 4,527
        // over 4,328 requests.
        $row = DB::table('fnb_auto_numbers')->first();

        $this->assertSame(11436, (int) $row->vendor_booking_no);
        $this->assertSame(4528, (int) $row->request_no);
        $this->assertSame(11435, (int) $row->live_vendor_booking_no_observed);
    }

    #[Test]
    public function it_allocates_the_next_number_and_advances_the_counter(): void
    {
        $this->assertSame('EKO/F&BOrder/11436', FnbNumber::allocate('vendor_booking'));
        $this->assertSame('EKO/F&BOrder/11437', FnbNumber::allocate('vendor_booking'));

        $this->assertSame(11438, (int) DB::table('fnb_auto_numbers')->value('vendor_booking_no'));
    }

    #[Test]
    public function two_series_advance_independently(): void
    {
        FnbNumber::allocate('vendor_booking');

        // The request counter must not have moved.
        $this->assertSame('EKO/Stock/4528', FnbNumber::allocate('request'));
    }

    #[Test]
    public function there_is_no_zero_padding(): void
    {
        // Creator pads below 1000 with `if (<10) … else if (<100) …` then a BARE
        // `if (<1000)` — so a two-digit number gets "00" and then "0" again. The
        // branch has never fired: all 11,205 live order numbers are 4 or 5 digits.
        // A bug that cannot fire is not behaviour to copy, and copying it would
        // corrupt a future low-numbered series.
        DB::table('fnb_auto_numbers')->update(['vendor_booking_no' => 42,
            'live_vendor_booking_no_observed' => null]);

        $this->assertSame('EKO/F&BOrder/42', FnbNumber::allocate('vendor_booking'));
    }

    #[Test]
    public function it_refuses_while_our_counter_is_behind_the_live_one(): void
    {
        // Creator keeps minting while this is built, so our counter can only be
        // behind. Allocating while behind mints a number that already belongs to a
        // real order. Accounts found this hole when migrate:fresh disarmed it.
        DB::table('fnb_auto_numbers')->update([
            'vendor_booking_no' => 11000,
            'live_vendor_booking_no_observed' => 11435,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/REFUSING to allocate/');

        FnbNumber::allocate('vendor_booking');
    }

    #[Test]
    public function it_refuses_a_series_with_no_counter_rather_than_minting_from_one(): void
    {
        // Booking and Transfer have no export, so no counter is guessed.
        $this->expectException(RuntimeException::class);

        FnbNumber::allocate('booking');
    }

    #[Test]
    public function it_refuses_an_unknown_series(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/unknown F&B series/');

        FnbNumber::allocate('not_a_series');
    }

    #[Test]
    public function the_singleton_cannot_be_duplicated(): void
    {
        // A second row would give two counters and two answers.
        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        DB::table('fnb_auto_numbers')->insert(['singleton' => true]);
    }

    #[Test]
    public function a_counter_cannot_be_zero_or_negative(): void
    {
        // EKO/F&BOrder/0 would look plausible in a report.
        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::table('fnb_auto_numbers')->update(['vendor_booking_no' => 0]);
    }

    #[Test]
    public function peek_does_not_take_the_number(): void
    {
        $this->assertSame('EKO/F&BOrder/11436', FnbNumber::peek('vendor_booking'));
        $this->assertSame('EKO/F&BOrder/11436', FnbNumber::peek('vendor_booking'));
        $this->assertSame('EKO/F&BOrder/11436', FnbNumber::allocate('vendor_booking'));
    }
}
