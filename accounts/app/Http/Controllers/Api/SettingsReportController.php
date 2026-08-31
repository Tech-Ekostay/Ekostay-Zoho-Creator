<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Reports\ReportFilter;
use App\Domain\Settings\ReportRegistry;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Settings — the eight reports behind the nav flyout (addendum §2).
 *
 * The report and field definitions now live in App\Domain\Settings\ReportRegistry
 * so the read path and the write path cannot drift. That mattered as soon as add
 * and edit existed: two copies of a column list is how a form and its report end
 * up disagreeing about what a record is.
 *
 * Response shape follows §17 step 5's criteria: amounts and ids are strings, never
 * floats — an 18-digit id or a rupee figure must not touch one — dates are
 * 'YYYY-MM-DD', and absent values are empty strings rather than null, because that
 * is what Creator renders.
 *
 * `show` also returns `fields`, so the form is built from the same definition that
 * produced the grid rather than from a second list in the JavaScript.
 */
class SettingsReportController extends Controller
{
    use Concerns\FiltersReports;

    public function index(): JsonResponse
    {
        return response()->json([
            'reports' => ReportRegistry::keys(),
        ]);
    }

    /**
     * The filter whitelist for a Settings report, read off the registry.
     *
     * Types come from the report's own `fields` declaration: `bool` becomes a boolean
     * filter (is true / is false), a decimal becomes a number, everything else is
     * text. `readonly` fields are still filterable — `ID` is read-only for EDITING
     * and perfectly reasonable to filter on.
     */
    private function filterFor(array $definition): ReportFilter
    {
        $types = [];

        foreach ($definition['fields'] ?? [] as $field) {
            $types[$field['column']] = $field['type'] ?? 'text';
        }

        $whitelist = [];

        foreach ($definition['columns'] as $label => $column) {
            // A joined column (`master_categories.name`) keeps its qualified name.
            $bare = str_contains($column, '.') ? substr($column, strrpos($column, '.') + 1) : $column;

            /*
             * QUALIFIED WITH THE TABLE. The item-categories report LEFT JOINs
             * master_categories and BOTH tables have a `name` column, so an
             * unqualified filter raises "column reference name is ambiguous" (42702).
             * The registry stores the bare column because the grid selects it with an
             * alias; a WHERE has no alias to lean on.
             */
            $whitelist[$label] = [
                'column' => str_contains($column, '.') ? $column : $definition['table'].'.'.$column,
                'type' => match ($types[$bare] ?? 'text') {
                    'bool' => 'boolean',
                    'decimal', 'number' => 'number',
                    default => 'text',
                },
            ];
        }

        return new ReportFilter($whitelist);
    }

    public function show(Request $request, string $report): JsonResponse
    {
        $definition = ReportRegistry::get($report);

        if ($definition === null) {
            return response()->json(['message' => "Unknown report: {$report}"], 404);
        }

        $query = DB::table($definition['table']);

        /*
         * FILTERS ARE DERIVED FROM THE REGISTRY, not hand-listed per report.
         *
         * `ReportRegistry` already maps label => db column and declares each field's
         * type, so the filter whitelist can be built from the same definition the
         * grid and the form read. One source, three consumers — the reason the
         * registry exists at all is that a form and its grid must not be able to
         * drift, and a filter is now the third thing that must agree with them.
         *
         * These reports hold 8 to 144 rows, so server-side filtering is not needed
         * for scale here. It is done anyway for consistency: a user should not have
         * to learn that filtering means something different on Settings than on
         * Payments.
         */
        $filter = $this->filterFor($definition);
        $filters = $this->requestedFilters($request);

        if ($error = $this->applyFilters($filter, $query, $filters)) {
            return response()->json(['message' => $error, 'reason' => 'bad_filter'], 422);
        }

        // Item Category shows its master category's NAME, reached through the FK.
        if ($definition['table'] === 'item_categories') {
            $query->leftJoin('master_categories', 'master_categories.id', '=', 'item_categories.master_category_id')
                ->select('item_categories.*', 'master_categories.name as master_category');
        }

        $rows = $query->orderBy($definition['order'])->get();

        return response()->json([
            'filter_schema' => $filter->schema(),
            'filters' => $filters,
            'report' => $report,
            'title' => $definition['title'],
            'columns' => array_keys($definition['columns']),
            'inline_editable' => $definition['inline_editable'] ?? false,
            'inline_columns' => $definition['inline_columns'] ?? [],
            // The form definition, so the UI has one source of truth for both.
            'fields' => ReportRegistry::fields($report),
            'fields_verified' => $definition['fields_verified'] ?? false,
            // Label -> column, so an inline edit knows which column a cell writes.
            'column_map' => $definition['columns'],
            // `total` is the true count. The footer caps the SHOWN figure at 1000
            // and prints ### past that, which is Creator's overflow, not ours.
            'total' => $rows->count(),
            'rows' => $rows->map(function ($row) use ($definition): array {
                $out = ['id' => $row->id ?? null];

                foreach ($definition['columns'] as $label => $column) {
                    $value = $row->{$column} ?? null;

                    $out[$label] = match (true) {
                        is_bool($value) => $value,
                        $value === null => '',
                        default => (string) $value,
                    };
                }

                /*
                 * The raw editable values, so the edit form opens on what is stored
                 * rather than on what the grid displays. The difference is not
                 * cosmetic: the grid shows a master category's NAME while the form
                 * edits its ID, and trailing whitespace is invisible in a table
                 * cell but must survive a round trip through the form.
                 */
                $out['_values'] = collect($definition['fields'])
                    ->mapWithKeys(function (array $field) use ($row): array {
                        $value = $row->{$field['column']} ?? null;

                        return [$field['column'] => match (true) {
                            is_bool($value) => $value,
                            $value === null => '',
                            default => (string) $value,
                        }];
                    })
                    ->all();

                return $out;
            })->all(),
        ]);
    }
}
