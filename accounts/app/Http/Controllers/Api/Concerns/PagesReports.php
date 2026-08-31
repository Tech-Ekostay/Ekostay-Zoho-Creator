<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Concerns;

use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Http\Request;

/**
 * Creator's page size, and the offset that makes it scrollable.
 *
 * ---------------------------------------------------------------------------
 * WHY 1,000 AND NOT SOMETHING SENSIBLE. Every Creator report footer in this app reads
 * `Showing 1000 of <total>` — Backend Expenses, Bank, Deleted Payments, Pending
 * Approvals. 1,000 is Creator's page, it is what a reviewer comparing screenshots will
 * count, and Husain asked for exactly this: "1000 records load at a time and then when I
 * keep on scrolling the new records continue to load".
 *
 * So the page size is not a performance knob. It is the specification.
 *
 * ---------------------------------------------------------------------------
 * OFFSET PAGING IS SAFE HERE, AND ONLY BECAUSE THE SORT IS TOTAL.
 *
 * Offset paging skips or duplicates rows when the sort is not deterministic — two rows
 * with the same `added_time` can swap between requests and the reader silently loses
 * one. Every report using this orders by a timestamp descending AND `id` descending, so
 * the ordering is total and an offset means the same thing on every call.
 *
 * **If a report is ever given a non-total sort, this breaks quietly.** That is the
 * failure mode §6 keeps warning about — absence that looks like data rather than an
 * error — so `assertTotalOrdering()` exists to make the requirement explicit at the
 * call site rather than in a comment nobody reads.
 *
 * A cursor would be immune, and was not built: the four reports all sort on columns
 * that are nullable (`added_time` is null on plenty of imported rows), which makes a
 * keyset cursor materially fiddlier than it looks. Recorded as the deliberate choice it
 * is rather than an oversight.
 */
trait PagesReports
{
    /** Creator's page. Not a tuning parameter — see the docblock. */
    public const PAGE = 1000;

    /**
     * Where this request starts. Clamped at zero; garbage reads as the first page
     * rather than as an error, because a bad offset is a broken link, not an attack.
     */
    protected function requestedOffset(Request $request): int
    {
        return max(0, (int) $request->query('offset', 0));
    }

    /**
     * Take one page, and say whether another exists.
     *
     * Fetches PAGE + 1 rows and discards the extra. That is one row of work in exchange
     * for knowing there is a next page WITHOUT a second COUNT query — and the count is
     * the expensive part on 73,361 split legs or 48,245 bank transactions.
     *
     * @return array{rows: \Illuminate\Support\Collection, next_offset: ?int}
     */
    protected function page(Builder $query, int $offset): array
    {
        $rows = $query->offset($offset)->limit(self::PAGE + 1)->get();

        $hasMore = $rows->count() > self::PAGE;

        return [
            'rows' => $hasMore ? $rows->take(self::PAGE) : $rows,
            // The client sends this back verbatim. Null means "you have everything",
            // which is what stops the scroll handler asking forever.
            'next_offset' => $hasMore ? $offset + self::PAGE : null,
        ];
    }

    /**
     * The paging envelope every report shares, so the grid can be generic.
     *
     * `loaded` is what the CLIENT has after appending this page, not what this response
     * carries — the footer reads `Showing <loaded> of <total>` and must count the whole
     * accumulated list or it contradicts the screen.
     *
     * @return array<string, mixed>
     */
    protected function pagingEnvelope(int $offset, ?int $nextOffset, int $matched, int $total): array
    {
        return [
            'offset' => $offset,
            'next_offset' => $nextOffset,
            'per_page' => self::PAGE,
            'matched' => $matched,
            'total' => $total,
            'loaded' => min($offset + self::PAGE, $matched),
        ];
    }
}
