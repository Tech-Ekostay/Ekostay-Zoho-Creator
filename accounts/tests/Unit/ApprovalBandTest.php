<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Approvals\ApprovalRouter;
use App\Models\Approval;
use App\Models\ApprovalLevel;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * The Approvers grid, from the screenshots of 27-Aug-2026.
 *
 * The live rule that unblocked routing:
 *
 *     Level 1   0     - 5,000            Rohan - rohan.ops@ekostay.com       (type blank)
 *     Level 2   5,001 - 50,00,00,000     Sohail Mirchandani - sohail.m@...   Any
 *
 * with `Level 1 & 2 Approval` and `Level 2 & 3 Approval` both BLANK on the header.
 * These tests pin that shape so a refactor cannot quietly re-decide who approves.
 */
class ApprovalBandTest extends TestCase
{
    use DatabaseTransactions;

    private function liveRule(?string $lvl12 = null): Approval
    {
        $approval = Approval::create([
            'module' => 'Payment',
            'locations' => 'Alibaug',
            'item_categories' => 'PHOTOSHOOT',
            'level_1_2_approval' => $lvl12,
            'level_2_3_approval' => null,
        ]);

        ApprovalLevel::create([
            'approval_id' => $approval->id,
            'level' => 'Level 1',
            'minimum_amount' => '0.0000',
            'maximum_amount' => '5000.0000',
            // Deliberately null — Accounts.ds:38137 nulls and disables it on Level 1.
            'approval_type' => null,
            'position' => 0,
        ]);

        ApprovalLevel::create([
            'approval_id' => $approval->id,
            'level' => 'Level 2',
            'minimum_amount' => '5001.0000',
            'maximum_amount' => '500000000.0000',
            'approval_type' => 'Any',
            'position' => 1,
        ]);

        return $approval->load('levels');
    }

    /**
     * Contiguous in whole rupees raises no gap or overlap -- but it does raise the
     * sub-rupee note, because (5000, 5001) is unbanded and routes down.
     */
    public function test_the_live_bands_raise_only_the_sub_rupee_note(): void
    {
        $router = new ApprovalRouter;
        $rule = $this->liveRule();

        $warnings = $router->bandWarnings($rule->levels->all());

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('fall in neither band', $warnings[0]);
        $this->assertStringContainsString('route to Level 1, the lower authority', $warnings[0]);
        $this->assertStringContainsString('whole-rupee', $warnings[0]);
    }

    /** 0-5,000 and 5,001-50cr: greatest-minimum lands on the right side of 5,000. */
    public function test_amounts_route_to_the_band_that_contains_them(): void
    {
        $router = new ApprovalRouter;
        $rule = $this->liveRule();
        $levels = $rule->levels->all();

        // The routing rule under test, in isolation from Payment.
        $pick = function (string $amount) use ($levels): ?string {
            $target = null;
            $best = '-1';

            foreach ($levels as $level) {
                $min = $level->minimum_amount ?? '0';

                if (bccomp((string) $min, $amount, 4) <= 0 && bccomp((string) $min, $best, 4) > 0) {
                    $best = (string) $min;
                    $target = $level->level;
                }
            }

            return $target;
        };

        $this->assertSame('Level 1', $pick('0.0000'));
        $this->assertSame('Level 1', $pick('4999.9900'));
        $this->assertSame('Level 1', $pick('5000.0000'), 'the maximum is inclusive');
        // NOT Level 2. The bands are whole-rupee and money is decimal(16,4), so
        // (5000, 5001) exclusive falls in NEITHER band -- and greatest-minimum sends
        // it DOWN to the lower authority. A 5,000.50 payment is approved by the
        // 0-5,000 approver. Asserted because it is surprising, not because it is right.
        $this->assertSame('Level 1', $pick('5000.0100'));
        $this->assertSame('Level 1', $pick('5000.9900'));
        $this->assertSame('Level 2', $pick('5001.0000'));
        $this->assertSame('Level 2', $pick('500000000.0000'));
        $this->assertSame(
            'Level 2',
            $pick('900000000.0000'),
            'above the 50cr ceiling it still routes to Level 2 — only minimums are consulted'
        );
    }

    /**
     * Blank header + Level 2 target = Level 1 is skipped. This is Creator's
     * behaviour and it is what all 16 live rules do, because the header is a
     * browser-side mirror that never fired.
     */
    public function test_a_blank_header_routes_level_2_alone(): void
    {
        $router = new ApprovalRouter;

        $this->assertSame(['Level 2'], $router->expand('Level 2', $this->liveRule()));
        $this->assertSame(['Level 1'], $router->expand('Level 1', $this->liveRule()));
    }

    public function test_all_on_the_header_makes_level_2_cumulative(): void
    {
        $router = new ApprovalRouter;

        $this->assertSame(
            ['Level 1', 'Level 2'],
            $router->expand('Level 2', $this->liveRule('ALL'))
        );
    }

    /**
     * The header is blank while Level 2's grid type is `Any` — exactly the live
     * state. The two disagree, so it is reported.
     */
    public function test_the_stale_header_mirror_is_reported(): void
    {
        $router = new ApprovalRouter;
        $rule = $this->liveRule();

        $warnings = $router->mirrorWarnings($rule, $rule->levels->all());

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('Level 1 & 2 Approval is blank', $warnings[0]);
        $this->assertStringContainsString('Level 2\'s Approval Type is "Any"', $warnings[0]);
        $this->assertStringContainsString('38118', $warnings[0]);
    }

    public function test_a_header_matching_the_grid_is_silent(): void
    {
        $router = new ApprovalRouter;
        $rule = $this->liveRule('Any');

        $this->assertSame([], $router->mirrorWarnings($rule, $rule->levels->all()));
    }

    /** A gap is where greatest-minimum and contains-the-amount part company. */
    public function test_a_gap_between_bands_is_reported(): void
    {
        $router = new ApprovalRouter;
        $rule = $this->liveRule();

        $rule->levels->firstWhere('level', 'Level 2')->update(['minimum_amount' => '6000.0000']);

        $warnings = $router->bandWarnings($rule->fresh('levels')->levels->all());

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('fall in no band', $warnings[0]);
        $this->assertStringContainsString('routes them to Level 1 anyway', $warnings[0]);
    }

    public function test_an_overlap_between_bands_is_reported(): void
    {
        $router = new ApprovalRouter;
        $rule = $this->liveRule();

        $rule->levels->firstWhere('level', 'Level 2')->update(['minimum_amount' => '3000.0000']);

        $warnings = $router->bandWarnings($rule->fresh('levels')->levels->all());

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('overlap', $warnings[0]);
        $this->assertStringContainsString('resolves to Level 2', $warnings[0]);
    }

    public function test_an_inverted_band_is_reported(): void
    {
        $router = new ApprovalRouter;
        $rule = $this->liveRule();

        $rule->levels->firstWhere('level', 'Level 1')->update(['maximum_amount' => '-1.0000']);

        $warnings = $router->bandWarnings($rule->fresh('levels')->levels->all());

        $this->assertNotEmpty($warnings);
        $this->assertStringContainsString('the band is empty', $warnings[0]);
    }
}
