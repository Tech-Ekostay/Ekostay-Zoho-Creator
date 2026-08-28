<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Api\PaymentController;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Endless scroll: the paging contract, and the one way it fails silently.
 *
 * ---------------------------------------------------------------------------
 * THE FAILURE THIS EXISTS TO CATCH. Offset paging is only correct when the sort is
 * TOTAL. If two rows can tie on every ordering column, the database is free to return
 * them in either order, and then page 2 may repeat a row page 1 already showed — or
 * skip one entirely. The reader sees a plausible list with a hole in it, which is §6's
 * standing complaint: absence that looks like data rather than an error.
 *
 * Every paged report here orders by a timestamp descending AND `id` descending, so the
 * ordering is total. `the_union_of_pages_is_the_whole_set_with_no_gap_and_no_repeat`
 * is what proves it, and it would fail the day someone drops the `id` tiebreak.
 *
 * These use 1,001 real payments because the page size is 1,000 and testing the boundary
 * with a smaller page would test different code. A bulk insert makes that cheap.
 */
class ReportPagingTest extends TestCase
{
    use RefreshDatabase;

    private function seedPayments(int $count): void
    {
        $rows = [];
        $now = Carbon::parse('2026-08-28 12:00:00');

        for ($i = 0; $i < $count; $i++) {
            $rows[] = [
                'payment_no' => sprintf('EKS/PY/%05d', 40000 + $i),
                'status' => 'Paid',
                'amount' => '1000.0000',
                /*
                 * DELIBERATELY COARSE. Every row shares a minute, so `added_time` alone
                 * cannot order them and the `id` tiebreak is the only thing making the
                 * sort total. That is the condition the union test needs in order to
                 * mean anything — with distinct timestamps it would pass even if the
                 * tiebreak were removed.
                 */
                'added_time' => $now->copy()->subMinutes(intdiv($i, 250)),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            Payment::insert($chunk);
        }
    }

    #[Test]
    public function the_first_page_is_1000_rows_and_says_another_exists(): void
    {
        $this->seedPayments(1001);

        $body = $this->getJson('/api/payments')->assertOk()->json();

        // Read through the using class: PHP 8.2+ forbids accessing a trait constant
        // directly, which is a language rule and not a hint that the constant is
        // in the wrong place — it belongs with the paging, not with one controller.
        $this->assertCount(PaymentController::PAGE, $body['rows']);
        $this->assertSame(0, $body['offset']);
        $this->assertSame(1000, $body['next_offset']);
        $this->assertSame(1000, $body['per_page']);
        $this->assertSame(1001, $body['total']);
    }

    #[Test]
    public function the_last_page_reports_no_next_offset(): void
    {
        $this->seedPayments(1001);

        $body = $this->getJson('/api/payments?offset=1000')->assertOk()->json();

        $this->assertCount(1, $body['rows']);
        $this->assertSame(1000, $body['offset']);
        // Null is what stops the scroll handler asking forever.
        $this->assertNull($body['next_offset']);
    }

    #[Test]
    public function a_report_smaller_than_one_page_has_no_next_offset(): void
    {
        $this->seedPayments(5);

        $body = $this->getJson('/api/payments')->assertOk()->json();

        $this->assertCount(5, $body['rows']);
        $this->assertNull($body['next_offset']);
    }

    /**
     * THE ONE THAT MATTERS. Walk every page and prove the union is the whole set,
     * exactly once each.
     */
    #[Test]
    public function the_union_of_pages_is_the_whole_set_with_no_gap_and_no_repeat(): void
    {
        $this->seedPayments(1001);

        $seen = [];
        $offset = 0;
        $pages = 0;

        do {
            $body = $this->getJson("/api/payments?offset={$offset}")->assertOk()->json();
            $pages++;

            foreach ($body['rows'] as $row) {
                $seen[] = $row['id'];
            }

            $offset = $body['next_offset'];
        } while ($offset !== null && $pages < 10);

        $this->assertSame(2, $pages, 'exactly two pages for 1,001 rows');
        $this->assertCount(1001, $seen, 'every row appeared');
        $this->assertCount(1001, array_unique($seen), 'and none appeared twice');
        $this->assertSame(
            Payment::query()->pluck('id')->sort()->values()->all(),
            collect($seen)->sort()->values()->all(),
            'the union of pages is exactly the table',
        );
    }

    #[Test]
    public function a_garbage_offset_reads_as_the_first_page_rather_than_erroring(): void
    {
        $this->seedPayments(5);

        // A bad offset is a broken link, not an attack. Negative and non-numeric both
        // clamp to zero rather than 500.
        foreach (['-50', 'abc', ''] as $bad) {
            $body = $this->getJson('/api/payments?offset='.$bad)->assertOk()->json();
            $this->assertSame(0, $body['offset'], "offset={$bad}");
        }
    }

    #[Test]
    public function paging_composes_with_a_filter_and_the_totals_stay_distinct(): void
    {
        $this->seedPayments(1001);
        Payment::query()->limit(3)->update(['status' => 'Draft']);

        $body = $this->getJson('/api/payments?filters='.urlencode(json_encode([
            ['column' => 'Status', 'operator' => 'is', 'value' => 'Draft'],
        ])))->assertOk()->json();

        // `matched` is the filtered count; `total` stays the whole table. The footer
        // reads one against the other, so conflating them misreports both.
        $this->assertSame(3, $body['matched']);
        $this->assertSame(1001, $body['total']);
        $this->assertNull($body['next_offset'], 'three rows is under one page');
    }

    #[Test]
    public function the_page_size_is_creators_1000_and_not_a_tuning_knob(): void
    {
        // Every Creator footer in this app reads `Showing 1000 of <total>`. If this
        // constant moves, the screens stop matching the live ones a reviewer compares
        // against — it is the specification, not a performance setting.
        $this->assertSame(1000, PaymentController::PAGE);
    }
}
