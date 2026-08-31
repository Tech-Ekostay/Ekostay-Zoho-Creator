<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Concerns;

use App\Domain\Reports\ReportFilter;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * The filter plumbing every report controller shares.
 *
 * Four controllers need the same three things — read the filters off the request,
 * apply them, and turn a rejected filter into a 422 rather than a 500. Written once
 * so a report cannot end up with subtly different filter behaviour from its
 * neighbour, which is the same reason `ReportRegistry` is one definition read by
 * both the Settings grid and its form.
 */
trait FiltersReports
{
    /**
     * Filters off the query string.
     *
     * Carried as JSON in a single `filters` parameter rather than as bracketed array
     * params. A chip list is one value conceptually, and PHP's nested query parsing
     * is its own source of surprises — `filters[0][value]=a,b` does not mean what a
     * reader expects.
     *
     * @return list<array{column?: string, operator?: string, value?: string}>
     */
    protected function requestedFilters(Request $request): array
    {
        $raw = $request->query('filters');

        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded)
            ? array_values(array_filter($decoded, 'is_array'))
            : [];
    }

    /**
     * Apply them, or hand back the message for a 422.
     *
     * A REJECTED FILTER MUST NOT DEGRADE TO AN UNFILTERED RESULT. That is the whole
     * point of returning the error: if an unknown column silently filtered nothing,
     * the user would read 52,638 unfiltered payments as the filtered answer. Loud
     * beats plausible.
     *
     * @param  list<array<string, mixed>>  $filters
     * @return string|null the error message, or null when the filters applied
     */
    protected function applyFilters(ReportFilter $filter, Builder $query, array $filters): ?string
    {
        try {
            $filter->apply($query, $filters);

            return null;
        } catch (InvalidArgumentException $e) {
            return $e->getMessage();
        }
    }
}
