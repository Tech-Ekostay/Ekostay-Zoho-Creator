<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Payments\PaymentNumber;
use App\Models\AutoNumber;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * The two guards on payment-number allocation, added 27-Aug-2026.
 *
 * Husain confirmed the EKS/PY series comes from `Auto_Numbers`, and the Auto Numbers
 * screenshot gave live's contents: `Payment No` **21621** against our 21309. So our
 * counter would re-issue 312 numbers that Creator has already given to real
 * payments — the same failure that once minted `EKS/PY/21305` over a live ₹1,00,000
 * payment.
 *
 * That was fixed to `max + 1` and it was not enough, because `max + 1` of a stale
 * export is stale. These tests exist because the previous fix was a comment plus an
 * arithmetic change, and the comment is what failed.
 *
 * NOTE the watermark is null on a freshly seeded row, which is why the existing 43
 * payment tests still pass. That is deliberate — a fresh install is not punished for
 * a reading nobody has taken — but it also means the guard can sit inert without
 * anybody noticing. Hence a test that it fires, not just that it exists.
 */
class PaymentNumberGuardTest extends TestCase
{
    use RefreshDatabase;

    private function counter(int $paymentNo, ?int $observed = null): AutoNumber
    {
        return AutoNumber::create([
            'singleton' => true,
            'payment_series' => 'EKS/PY',
            'payment_no' => $paymentNo,
            'haewaya_series' => 'EKS/Haewaya',
            'haewaya_no' => 33294,
            'live_payment_no_observed' => $observed,
            'live_observed_at' => $observed === null ? null : '2026-08-27 20:50:00+05:30',
        ]);
    }

    // ------------------------------------------------- the staleness guard

    #[Test]
    public function it_refuses_to_allocate_while_our_counter_is_behind_live(): void
    {
        $this->counter(21309, 21621);

        try {
            DB::transaction(fn () => PaymentNumber::allocate());
            $this->fail('allocation should have been refused: 21309 is behind live 21621');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Refusing to allocate EKS/PY/21309', $e->getMessage());
            $this->assertStringContainsString('read as 21621', $e->getMessage());
            $this->assertStringContainsString('2026-08-27', $e->getMessage());
            // 21621 - 21309 + 1 = 313 numbers that already belong to someone.
            $this->assertStringContainsString('313 behind', $e->getMessage());
        }

        $this->assertSame(21309, AutoNumber::first()->payment_no,
            'a refused allocation must not advance the counter');
    }

    #[Test]
    public function the_boundary_is_inclusive_because_the_stored_value_is_the_NEXT_number(): void
    {
        // `Accounts.ds:20502` reads `nextSeries = ifnull(autoRec.External_Payment_No,1)`
        // and uses it directly, so the stored value is the next number to issue, not
        // the last issued. Live holding 21621 therefore means 21621 is still to come,
        // and ours standing AT 21621 is a collision, not a near miss.
        $this->counter(21621, 21621);

        $this->expectException(RuntimeException::class);
        DB::transaction(fn () => PaymentNumber::allocate());
    }

    #[Test]
    public function it_allocates_once_our_counter_is_past_the_observed_reading(): void
    {
        $this->counter(21622, 21621);

        $number = DB::transaction(fn () => PaymentNumber::allocate());

        $this->assertSame('EKS/PY/21622', $number);
        $this->assertSame(21623, AutoNumber::first()->payment_no);
    }

    #[Test]
    public function a_null_watermark_leaves_the_guard_inert(): void
    {
        $this->counter(20938, null);

        $this->assertSame('EKS/PY/20938', DB::transaction(fn () => PaymentNumber::allocate()));
    }

    // ----------------------------------------------------- the clash guard

    /**
     * Creator has this and we did not — `Accounts.ds:20517` checks
     * `Payment[Payment_No == BkngNo]` and steps once past a taken number.
     */
    #[Test]
    public function it_steps_past_a_number_that_is_already_taken(): void
    {
        $this->counter(20938);
        Payment::create(['payment_no' => 'EKS/PY/20938']);

        $this->assertSame('EKS/PY/20939', DB::transaction(fn () => PaymentNumber::allocate()));
        $this->assertSame(20940, AutoNumber::first()->payment_no);
    }

    /** Creator steps exactly once and still collides on two in a row. This does not. */
    #[Test]
    public function it_steps_past_several_consecutive_taken_numbers(): void
    {
        $this->counter(20938);

        foreach ([20938, 20939, 20940] as $n) {
            Payment::create(['payment_no' => 'EKS/PY/'.$n]);
        }

        $this->assertSame('EKS/PY/20941', DB::transaction(fn () => PaymentNumber::allocate()));
    }

    /**
     * A soft-deleted payment still owns its number. §7.6: a payment number, once
     * issued, is never reissued — and a reversal leaves the original in place.
     */
    #[Test]
    public function a_soft_deleted_payment_still_holds_its_number(): void
    {
        $this->counter(20938);
        Payment::create(['payment_no' => 'EKS/PY/20938'])->delete();

        $this->assertSame('EKS/PY/20939', DB::transaction(fn () => PaymentNumber::allocate()));
    }

    #[Test]
    public function a_long_run_of_taken_numbers_is_reported_as_staleness_not_walked_through(): void
    {
        $this->counter(20938);

        for ($n = 20938; $n <= 20938 + PaymentNumber::MAX_CLASH_SKIP + 1; $n++) {
            Payment::create(['payment_no' => 'EKS/PY/'.$n]);
        }

        try {
            DB::transaction(fn () => PaymentNumber::allocate());
            $this->fail('a run longer than MAX_CLASH_SKIP should be refused');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('consecutive payment numbers', $e->getMessage());
            $this->assertStringContainsString('stale counter rather than a collision', $e->getMessage());
        }

        $this->assertSame(20938, AutoNumber::first()->payment_no);
    }

    // ------------------------------------------------------ the fourth series

    /**
     * The Auto_Numbers FORM declares four series (Accounts.ds:234-292) and the
     * report shows three. `External_Payment_*` is invisible on screen and actively
     * allocated from at `Accounts.ds:20502`.
     */
    #[Test]
    public function the_fourth_series_the_report_does_not_show_is_modelled(): void
    {
        $auto = $this->counter(21309, 21621);

        $auto->update(['external_payment_series' => 'EXT', 'external_payment_no' => 7]);

        $this->assertSame('EXT', $auto->fresh()->external_payment_series);
        $this->assertSame(7, $auto->fresh()->external_payment_no);
    }

    #[Test]
    public function the_live_reading_of_27_aug_2026_is_recorded_on_the_seeded_row(): void
    {
        // The migration wrote it as data. Asserted so a future migrate:fresh that
        // loses it is a failing test rather than a silently disarmed guard.
        $this->artisan('db:seed', ['--class' => 'DatabaseSeeder']);

        $auto = AutoNumber::first();

        $this->assertNotNull($auto, 'the singleton must exist after seeding');
        $this->assertSame(21621, $auto->live_payment_no_observed);
        $this->assertSame(33507, $auto->live_haewaya_no_observed);
    }
}
