<?php

declare(strict_types=1);

namespace App\Domain\Reports;

use Illuminate\Contracts\Database\Query\Builder;
use InvalidArgumentException;

/**
 * Creator's report filter — column + operator + value — applied server-side.
 *
 * WHAT THE EVIDENCE IS. The All Payments screenshot of 25-Aug-2026 shows a filter
 * chip reading:
 *
 *     SEARCH   Payment No. contains "EKS/PY/1596…"   (x)
 *
 * So a filter is a COLUMN, an OPERATOR and a VALUE, displayed as a dismissable chip
 * — not the free-text box this app had. That much is verified.
 *
 * A SECOND OPERATOR IS NOW VERIFIED, and it changes a label. The App Preferences
 * screenshot of 27-Aug-2026 caught a Bank filter chip in the background:
 *
 *     SEARCH   Amount is "1713…"   (x)              -> Showing 1 of 1
 *
 * So Creator's equality operator is spelled **`is`**, not `equals`, and it applies to
 * a NUMBER column. `is` is therefore the canonical label here and `equals` is kept as
 * an accepted alias, because renaming an operator would break any saved filter that
 * already uses the old word. Same for `is not` / `not equals`.
 *
 * WHAT IS STILL INFERRED, and says so on screen: the rest of the list. `starts with`,
 * `ends with`, `is empty`, `is not empty` and the remaining numeric/date comparisons
 * are the obvious companions and are offered, but no screenshot confirms Creator's
 * exact menu. The §10 field notes only ever verified `=` and `!=` on the Analytics
 * side, which is a different surface.
 *
 * ---------------------------------------------------------------------------
 * COLUMNS ARE WHITELISTED, AND THAT IS NOT OPTIONAL. A filter names a column, and a
 * column name reaching a query builder from a request is how arbitrary columns get
 * read — on tables holding PANs, bank details and 52,638 payments. Each report
 * declares its own `label => spec` map; anything not in it is rejected by name.
 *
 * FILTERING IS SERVER-SIDE because it has to be. Payments holds 52,638 rows, bills
 * 17,161, vendors 8,064. The old free-text search filtered whatever the browser had
 * already loaded — the first 1,000 payments — so a search for a payment at row 5,000
 * silently found nothing and looked like an empty result rather than a truncated one.
 *
 * TEXT MATCHING IS CASE-INSENSITIVE, and this is the one place normalisation is
 * right. Storage is verbatim: `27aahfe2088h1zb` and `27AAHFE2088H1ZB` are both
 * stored as typed, `Payment InProgress` is spelled two ways, and vendor names carry
 * edge whitespace. A case-sensitive filter would hide rows that exist. Normalising
 * the SEARCH is not normalising the DATA.
 */
final class ReportFilter
{
    /**
     * Operators, by the type of column they apply to.
     *
     * `contains` is first because it is the one Creator was seen using, and it is
     * the default a user gets.
     */
    public const TEXT_OPERATORS = [
        'contains', 'not contains', 'is', 'is not',
        'starts with', 'ends with', 'is empty', 'is not empty',
    ];

    /** Accepted but not offered: the pre-27-Aug-2026 spellings of `is` / `is not`. */
    public const OPERATOR_ALIASES = ['equals' => 'is', 'not equals' => 'is not'];

    public const NUMBER_OPERATORS = [
        'is', 'is not', 'greater than', 'less than',
        'greater or equal', 'less or equal', 'is empty', 'is not empty',
    ];

    public const DATE_OPERATORS = [
        'is', 'is not', 'on or after', 'on or before', 'is empty', 'is not empty',
    ];

    public const BOOLEAN_OPERATORS = ['is true', 'is false'];

    /** Operators that take no value — the value input is hidden for these. */
    public const VALUELESS = ['is empty', 'is not empty', 'is true', 'is false'];

    /**
     * @param  array<string, array{column: string, type: string, relation?: string}>  $whitelist
     *                                                                                            label => spec. `column` may be `table.column` for a joined field.
     */
    public function __construct(private readonly array $whitelist) {}

    /**
     * The filter menu this report offers, for the UI to render.
     *
     * @return list<array{label: string, type: string, operators: list<string>}>
     */
    public function schema(): array
    {
        $out = [];

        foreach ($this->whitelist as $label => $spec) {
            $out[] = [
                'label' => $label,
                'type' => $spec['type'],
                'operators' => $this->operatorsFor($spec['type']),
            ];
        }

        return $out;
    }

    /** @return list<string> */
    public function operatorsFor(string $type): array
    {
        return match ($type) {
            'number', 'money' => self::NUMBER_OPERATORS,
            'date' => self::DATE_OPERATORS,
            'boolean' => self::BOOLEAN_OPERATORS,
            default => self::TEXT_OPERATORS,
        };
    }

    /**
     * Apply a set of filters to a query.
     *
     * Filters combine with AND, which is what a stack of chips reads as. Creator may
     * support OR groups; no screenshot shows one, so it is not invented here.
     *
     * @param  list<array{column?: string, operator?: string, value?: string}>  $filters
     */
    public function apply(Builder $query, array $filters): Builder
    {
        foreach ($filters as $filter) {
            $label = (string) ($filter['column'] ?? '');
            $operator = self::OPERATOR_ALIASES[
                (string) ($filter['operator'] ?? 'contains')
            ] ?? (string) ($filter['operator'] ?? 'contains');
            $value = $filter['value'] ?? null;

            if ($label === '') {
                continue;
            }

            if (! isset($this->whitelist[$label])) {
                // Named, not silently dropped. A filter that quietly does nothing is
                // worse than one that errors: the user reads the unfiltered result as
                // the filtered one.
                throw new InvalidArgumentException(sprintf(
                    'Cannot filter on "%s". This report allows: %s',
                    $label,
                    implode(', ', array_keys($this->whitelist)),
                ));
            }

            $spec = $this->whitelist[$label];
            $allowed = $this->operatorsFor($spec['type']);

            if (! in_array($operator, $allowed, true)) {
                throw new InvalidArgumentException(sprintf(
                    'Operator "%s" does not apply to %s (%s). Allowed: %s',
                    $operator, $label, $spec['type'], implode(', ', $allowed),
                ));
            }

            if (! in_array($operator, self::VALUELESS, true)
                && ($value === null || $value === '')) {
                continue;       // an incomplete chip filters nothing, silently and safely
            }

            $this->applyOne($query, $spec, $operator, (string) $value);
        }

        return $query;
    }

    private function applyOne(Builder $query, array $spec, string $operator, string $value): void
    {
        $column = $spec['column'];

        // A relation-backed column filters through whereHas, so a label like
        // "Vendor Name" can filter on vendors.name without joining by hand.
        if (isset($spec['relation'])) {
            $query->whereHas($spec['relation'], function (Builder $sub) use ($column, $operator, $value, $spec): void {
                $this->condition($sub, $column, $spec['type'], $operator, $value);
            });

            return;
        }

        $this->condition($query, $column, $spec['type'], $operator, $value);
    }

    private function condition(Builder $query, string $column, string $type, string $operator, string $value): void
    {
        switch ($operator) {
            case 'is empty':
                // Blank AND null both read as empty to a user. `ConvertEmptyStringsToNull`
                // is on, so new rows store null, but imported rows can hold ''.
                $query->where(fn (Builder $q) => $q->whereNull($column)->orWhere($column, ''));

                return;

            case 'is not empty':
                $query->whereNotNull($column)->where($column, '<>', '');

                return;

            case 'is true':
                $query->where($column, true);

                return;

            case 'is false':
                $query->where($column, false);

                return;
        }

        // ILIKE for text: storage is verbatim and case varies, so a case-sensitive
        // match would hide real rows. The value is bound, never interpolated, and
        // the wildcards are added around the binding.
        $like = fn (string $pattern) => $query->where($column, 'ilike', $pattern);

        switch ($operator) {
            case 'contains':        $like('%'.$value.'%');

                return;
            case 'not contains':    $query->where($column, 'not ilike', '%'.$value.'%');

                return;
            case 'starts with':     $like($value.'%');

                return;
            case 'ends with':       $like('%'.$value);

                return;

            case 'is':
                // Text equality stays case-insensitive for the same reason as
                // `contains`; numbers and dates compare exactly.
                $type === 'text'
                    ? $like($value)
                    : $query->where($column, '=', $value);

                return;

            case 'is not':
                $type === 'text'
                    ? $query->where($column, 'not ilike', $value)
                    : $query->where($column, '<>', $value);

                return;

            case 'greater than':     $query->where($column, '>', $value);

                return;
            case 'less than':        $query->where($column, '<', $value);

                return;
            case 'greater or equal': $query->where($column, '>=', $value);

                return;
            case 'less or equal':    $query->where($column, '<=', $value);

                return;
            case 'on or after':      $query->whereDate($column, '>=', $value);

                return;
            case 'on or before':     $query->whereDate($column, '<=', $value);

                return;
        }
    }
}
