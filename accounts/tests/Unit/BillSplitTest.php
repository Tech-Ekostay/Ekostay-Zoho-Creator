<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Bills\Money;
use App\Domain\Bills\SplitAllocator;
use App\Domain\Bills\SplitLeg;
use App\Domain\Bills\SplitValidator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * §17 step 4 verification:
 *
 *   "split-total-equals-gross rejects a mismatched payload; split-equally
 *    distributes with remainder on the last row, asserted to the paisa; the
 *    reconcile scenario in §15.1 passes as a test"
 *
 * All three are below. No database — this is arithmetic and set logic.
 */
class BillSplitTest extends TestCase
{
    private SplitAllocator $allocator;

    private SplitValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->allocator = new SplitAllocator;
        $this->validator = new SplitValidator($this->allocator);
    }

    // ---------------------------------------------------------------- tiers

    /** §5.1 degradation: villas only -> one row per villa. */
    #[Test]
    public function villas_only_gives_one_row_per_villa(): void
    {
        $this->assertCount(1, $this->allocator->combinations([1], [], []));
        $this->assertCount(3, $this->allocator->combinations([1, 2, 3], [], []));
    }

    /** §5.1 degradation: villas + cycles, no category -> villa x cycle. */
    #[Test]
    public function villas_and_cycles_give_the_pair_product(): void
    {
        $this->assertCount(2, $this->allocator->combinations([1], [10, 11], []));
        $this->assertCount(6, $this->allocator->combinations([1, 2, 3], [10, 11], []));
    }

    /** §5.1 degradation: the full cross product. */
    #[Test]
    public function the_full_cross_product_is_villa_times_cycle_times_category(): void
    {
        $legs = $this->allocator->combinations([1, 2], [10, 11], [100, 101, 102]);

        $this->assertCount(12, $legs);
        $this->assertCount(12, array_unique(array_map(fn (SplitLeg $l): string => $l->key(), $legs)));
    }

    // ------------------------------------------------- §15.1 reconcile fixture

    /**
     * The §15.1 scenario, verbatim:
     *
     *   villa only -> 1 row · +2 cycles -> 2 · +2 categories -> 4
     *   type 10k/20k/30k/40k · add a 2nd villa -> 8 rows, the 4 amounts intact,
     *   4 new blank
     *   remove the 1st villa -> its 4 rows flagged, Rs 1,00,000 still on screen,
     *   save blocked
     */
    #[Test]
    public function the_reconcile_scenario_from_15_1(): void
    {
        // villa only -> 1 row
        $legs = $this->allocator->reconcile([], [1], [], []);
        $this->assertCount(1, $legs);

        // + 2 cycles -> 2
        $legs = $this->allocator->reconcile($legs, [1], [10, 11], []);
        $this->assertCount(2, $legs);

        // + 2 categories -> 4
        $legs = $this->allocator->reconcile($legs, [1], [10, 11], [100, 101]);
        $this->assertCount(4, $legs);

        // type 10k / 20k / 30k / 40k
        $typed = ['10000', '20000', '30000', '40000'];
        foreach ($legs as $i => $leg) {
            $legs[$i] = new SplitLeg(
                villaId: $leg->villaId,
                itemCategoryId: $leg->itemCategoryId,
                billingCycleId: $leg->billingCycleId,
                amount: $typed[$i],
            );
        }
        $this->assertSame('100000.0000', $this->allocator->total($legs));

        // add a 2nd villa -> 8 rows, the 4 amounts intact, 4 new blank
        $legs = $this->allocator->reconcile($legs, [1, 2], [10, 11], [100, 101]);

        $this->assertCount(8, $legs);
        $this->assertSame(
            '100000.0000',
            $this->allocator->total($legs),
            'the typed amounts did not survive the scope change — this is the clear-and-rebuild bug'
        );

        $funded = array_values(array_filter($legs, fn (SplitLeg $l): bool => $l->carriesMoney()));
        $blank = array_values(array_filter($legs, fn (SplitLeg $l): bool => ! $l->carriesMoney()));
        $this->assertCount(4, $funded, 'expected the 4 original amounts intact');
        $this->assertCount(4, $blank, 'expected 4 new blank rows');
        $this->assertFalse($this->allocator->hasBlockingFlags($legs));

        // remove the 1st villa -> its 4 rows flagged, Rs 1,00,000 still on screen,
        // save blocked
        $legs = $this->allocator->reconcile($legs, [2], [10, 11], [100, 101]);

        $this->assertCount(8, $legs, 'villa 1 rows carry money, so they are kept not dropped');

        $flagged = array_values(array_filter($legs, fn (SplitLeg $l): bool => $l->flagged));
        $this->assertCount(4, $flagged);

        foreach ($flagged as $leg) {
            $this->assertSame(1, $leg->villaId);
            $this->assertTrue($leg->carriesMoney());
        }

        $this->assertSame(
            '100000.0000',
            $this->allocator->total($legs),
            'Rs 1,00,000 must still be on screen'
        );

        $this->assertTrue($this->allocator->hasBlockingFlags($legs), 'save must be blocked');
        $this->assertNotEmpty($this->validator->blockingErrors($legs, '100000'));
    }

    /** The other half of the rule: an out-of-scope row with no money just goes. */
    #[Test]
    public function empty_out_of_scope_rows_are_dropped_silently(): void
    {
        $legs = $this->allocator->reconcile([], [1, 2], [10], [100]);
        $this->assertCount(2, $legs);

        $legs = $this->allocator->reconcile($legs, [2], [10], [100]);

        $this->assertCount(1, $legs, 'an empty orphaned row should be dropped, not flagged');
        $this->assertFalse($this->allocator->hasBlockingFlags($legs));
    }

    #[Test]
    public function a_typed_zero_is_not_an_allocation(): void
    {
        $legs = [new SplitLeg(villaId: 1, itemCategoryId: 100, billingCycleId: 10, amount: '0')];

        $legs = $this->allocator->reconcile($legs, [2], [10], [100]);

        $this->assertCount(1, $legs);
        $this->assertFalse($this->allocator->hasBlockingFlags($legs));
    }

    // ------------------------------------------------------- split equally

    /** §6.3 — remainder on the last row, to the paisa. */
    #[Test]
    public function split_equally_puts_the_remainder_on_the_last_row(): void
    {
        $legs = $this->allocator->combinations([1, 2, 3], [], []);

        $legs = $this->allocator->splitEqually($legs, '100.00');

        $this->assertSame('33.3300', $legs[0]->amount);
        $this->assertSame('33.3300', $legs[1]->amount);
        $this->assertSame('33.3400', $legs[2]->amount, 'the remainder belongs on the last row');
        $this->assertSame('100.0000', $this->allocator->total($legs));
    }

    #[Test]
    public function split_equally_ties_exactly_for_awkward_amounts(): void
    {
        foreach (['1000.01', '0.01', '7', '99999.99', '1234.56', '10.00'] as $gross) {
            foreach ([1, 2, 3, 6, 7, 9, 13] as $rows) {
                $legs = $this->allocator->splitEqually(
                    $this->allocator->combinations(range(1, $rows), [], []),
                    $gross
                );

                $this->assertTrue(
                    $this->validator->tiesExactly($legs, $gross),
                    "gross {$gross} over {$rows} rows left a residual of "
                    .$this->validator->residual($legs, $gross)
                );
            }
        }
    }

    #[Test]
    public function split_equally_distributes_gst_the_same_way(): void
    {
        $legs = $this->allocator->splitEqually(
            $this->allocator->combinations([1, 2, 3], [], []),
            '100.00',
            '18.00'
        );

        $this->assertSame('6.0000', $legs[0]->gstAmount);
        $this->assertSame('6.0000', $legs[1]->gstAmount);
        $this->assertSame('6.0000', $legs[2]->gstAmount);

        $gstTotal = array_reduce(
            $legs,
            fn (string $carry, SplitLeg $l): string => Money::add($carry, $l->gstAmount),
            Money::zero()
        );
        $this->assertSame('18.0000', $gstTotal);
    }

    /** §6.3: TDS_Amount = row.Amount x pct / 100, per row. */
    #[Test]
    public function tds_is_computed_per_row_and_total_follows_the_formula(): void
    {
        $legs = $this->allocator->splitEqually(
            $this->allocator->combinations([1, 2], [], []),
            '1000.00',
            '180.00',
            '10'
        );

        foreach ($legs as $leg) {
            $this->assertSame('500.0000', $leg->amount);
            $this->assertSame('90.0000', $leg->gstAmount);
            $this->assertSame('50.0000', $leg->tdsAmount);
            // Total = amount + gst - tds
            $this->assertSame('540.0000', $leg->totalAmount);
        }
    }

    #[Test]
    public function flagged_rows_are_excluded_from_the_distribution(): void
    {
        $legs = [
            new SplitLeg(villaId: 1),
            new SplitLeg(villaId: 2),
            (new SplitLeg(villaId: 3, amount: '500'))->flag('out of scope'),
        ];

        $legs = $this->allocator->splitEqually($legs, '100.00');

        $this->assertSame('50.0000', $legs[0]->amount);
        $this->assertSame('50.0000', $legs[1]->amount);
        $this->assertSame('500', $legs[2]->amount, 'a flagged row must not be redistributed into');
    }

    // ---------------------------------------------------------- validation

    /** §6.4 rule 1 — a mismatched payload is rejected. */
    #[Test]
    public function it_rejects_a_split_that_does_not_match_the_gross(): void
    {
        $legs = [
            new SplitLeg(villaId: 1, amount: '4000'),
            new SplitLeg(villaId: 2, amount: '5000'),
        ];

        $this->assertFalse($this->validator->passesCreatorRule($legs, '10000'));
        $this->assertNotEmpty($this->validator->blockingErrors($legs, '10000'));
        $this->assertStringContainsString(
            'Please match the total amount in split payment',
            $this->validator->blockingErrors($legs, '10000')[0]
        );
    }

    #[Test]
    public function it_accepts_a_split_that_ties(): void
    {
        $legs = [
            new SplitLeg(villaId: 1, amount: '4000'),
            new SplitLeg(villaId: 2, amount: '6000'),
        ];

        $this->assertTrue($this->validator->passesCreatorRule($legs, '10000'));
        $this->assertTrue($this->validator->tiesExactly($legs, '10000'));
        $this->assertSame([], $this->validator->blockingErrors($legs, '10000'));
        $this->assertSame([], $this->validator->warnings($legs, '10000'));
    }

    /**
     * The round(0) hole, reproduced and surfaced. Creator compares whole rupees, so
     * a split short by 40 paise saves. It must still save here — but not silently.
     */
    #[Test]
    public function it_reproduces_the_whole_rupee_comparison_but_warns_about_the_gap(): void
    {
        $legs = [
            new SplitLeg(villaId: 1, amount: '4000.00'),
            new SplitLeg(villaId: 2, amount: '5999.60'),
        ];

        $this->assertTrue(
            $this->validator->passesCreatorRule($legs, '10000.00'),
            'Creator would accept this; rejecting it would block work that saves today'
        );
        $this->assertFalse($this->validator->tiesExactly($legs, '10000.00'));
        $this->assertTrue($this->validator->exactMismatch($legs, '10000.00'));
        $this->assertSame('0.4000', $this->validator->residual($legs, '10000.00'));
        $this->assertSame([], $this->validator->blockingErrors($legs, '10000.00'));
        $this->assertNotEmpty($this->validator->warnings($legs, '10000.00'));
    }

    /** Just past the tolerance, Creator's own rule rejects it. */
    #[Test]
    public function a_gap_over_half_a_rupee_fails_the_creator_rule(): void
    {
        $legs = [new SplitLeg(villaId: 1, amount: '9999.40')];

        $this->assertFalse($this->validator->passesCreatorRule($legs, '10000.00'));
    }

    #[Test]
    public function money_never_uses_floats(): void
    {
        // 0.1 + 0.2 is the canonical float failure.
        $this->assertSame('0.3000', Money::add('0.1', '0.2'));
        $this->assertTrue(Money::equals(Money::add('0.1', '0.2'), '0.3'));
    }
}
