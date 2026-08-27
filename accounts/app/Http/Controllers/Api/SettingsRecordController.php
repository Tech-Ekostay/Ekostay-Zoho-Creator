<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Settings\ReportRegistry;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Add and edit for the Settings masters — the `+` button, the row form, and COA's
 * `Save Changes`.
 *
 * Until now those controls were rendered chrome with no handlers behind them and
 * no write routes to call. This is the missing half.
 *
 * WHITESPACE IS NEVER TRIMMED. bootstrap/app.php exempts `api/settings/*` from
 * Laravel's global TrimStrings middleware, because `F&B STAFF MEDICAL EXPENSE `
 * is a live lookup key that is 26 characters in the database and 25 trimmed. This
 * controller must not undo that: no trim(), no ucfirst(), no normalisation of any
 * stored value. CLAUDE.md's rule is absolute — "Normalise at display only, never
 * in data."
 *
 * NOTHING IS DELETABLE. There is no destroy method and no DELETE route. These are
 * lookup keys with FK children (135 item categories hang off 10 master
 * categories), and no Creator screenshot has shown a delete control on any of the
 * eight Settings reports. §7.6's argument against hard deletes applies with more
 * force to a master than to a payment.
 *
 * NOT BEHIND AUTHORISATION YET, like the Payments endpoints. The §3.3 matrix is
 * extracted and tested (`docs/permission_matrix.json`) but not wired to a gate.
 * Flagged rather than half-built.
 */
class SettingsRecordController extends Controller
{
    public function store(Request $request, string $report): JsonResponse
    {
        if (! ReportRegistry::has($report)) {
            return response()->json(['message' => "Unknown report: {$report}"], 404);
        }

        $definition = ReportRegistry::get($report);
        $data = $this->validated($request, $report, null);

        /** @var Model $model */
        $model = new $definition['model'];
        $model->fill($data);
        $model->save();

        return response()->json([
            'id' => $model->getKey(),
            'report' => $report,
            'created' => true,
        ], 201);
    }

    public function update(Request $request, string $report, int $id): JsonResponse
    {
        if (! ReportRegistry::has($report)) {
            return response()->json(['message' => "Unknown report: {$report}"], 404);
        }

        $definition = ReportRegistry::get($report);

        /** @var Model|null $model */
        $model = $definition['model']::find($id);

        if ($model === null) {
            return response()->json(['message' => 'Record not found'], 404);
        }

        $data = $this->validated($request, $report, $id);

        $model->fill($data);
        $model->save();

        return response()->json([
            'id' => $model->getKey(),
            'report' => $report,
            'updated' => true,
        ]);
    }

    /**
     * COA's `Save Changes` — several rows, one request, one transaction.
     *
     * The report is inline-editable in Creator, so a user types across a grid and
     * commits once. Partial application would leave the grid disagreeing with what
     * they saw, which is why this is transactional: all rows or none.
     *
     * Only `inline_columns` may be touched. A payload naming anything else is
     * rejected rather than filtered, so a caller is told rather than surprised.
     */
    public function bulkUpdate(Request $request, string $report): JsonResponse
    {
        $definition = ReportRegistry::get($report);

        if ($definition === null || ($definition['inline_editable'] ?? false) !== true) {
            return response()->json([
                'message' => "Report {$report} is not inline-editable.",
            ], 422);
        }

        $allowed = $definition['inline_columns'];

        $payload = $request->validate([
            'changes' => ['required', 'array', 'min:1'],
            'changes.*.id' => ['required', 'integer'],
            'changes.*.values' => ['required', 'array', 'min:1'],
        ]);

        $fieldsByColumn = collect($definition['fields'])->keyBy('column');
        $applied = [];

        try {
            DB::transaction(function () use ($payload, $definition, $allowed, $fieldsByColumn, &$applied): void {
                foreach ($payload['changes'] as $change) {
                    $unknown = array_diff(array_keys($change['values']), $allowed);

                    if ($unknown !== []) {
                        abort(422, 'Not inline-editable on this report: '.implode(', ', $unknown));
                    }

                    /** @var Model|null $model */
                    $model = $definition['model']::find($change['id']);

                    if ($model === null) {
                        abort(422, "Record {$change['id']} not found.");
                    }

                    foreach ($change['values'] as $column => $value) {
                        $field = $fieldsByColumn->get($column, []);
                        $model->{$column} = $this->cast($value, $field['type'] ?? 'text');
                    }

                    $model->save();
                    $applied[] = $model->getKey();
                }
            });
        } catch (\Illuminate\Http\Exceptions\HttpResponseException|\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            return response()->json([
                'message' => $e->getMessage() !== '' ? $e->getMessage() : 'Inline update rejected.',
            ], 422);
        }

        return response()->json([
            'report' => $report,
            'updated' => $applied,
            'count' => count($applied),
        ]);
    }

    /**
     * Validate against the registry's field definitions.
     *
     * Only declared, non-readonly fields are accepted. `creator_id` is readonly by
     * design — see ReportRegistry's docblock on why an 18-digit Creator id must not
     * be typed in.
     *
     * @return array<string, mixed>
     */
    private function validated(Request $request, string $report, ?int $ignoreId): array
    {
        $definition = ReportRegistry::get($report);
        $rules = [];
        $editable = [];

        foreach ($definition['fields'] as $field) {
            if ($field['type'] === 'readonly') {
                continue;
            }

            $column = $field['column'];
            $editable[$column] = $field;
            $required = ($field['required'] ?? false) === true;

            $rules[$column] = match ($field['type']) {
                'bool' => ['sometimes', 'boolean'],
                'decimal' => ['sometimes', 'nullable', 'numeric'],
                'select' => $this->selectRules($field, $required),
                default => [$required ? 'required' : 'sometimes', 'nullable', 'string', 'max:2000'],
            };

            /*
             * UNIQUENESS ON THE NAME COLUMN, and only there.
             *
             * Two masters with the same name are indistinguishable in every picker
             * in the app. But the check is on the EXACT string, so
             * `F&B STAFF MEDICAL EXPENSE ` and its trimmed form are correctly
             * treated as different values — which is the whole point of not
             * trimming. Vendor names are deliberately NOT unique (§13A.1); these
             * are not vendors.
             */
            if ($required && in_array($column, ['name', 'account_name'], true)) {
                $unique = Rule::unique($definition['table'], $column);
                $rules[$column][] = $ignoreId === null ? $unique : $unique->ignore($ignoreId);
            }
        }

        $validated = $request->validate($rules);
        $out = [];

        foreach ($validated as $column => $value) {
            $out[$column] = $this->cast($value, $editable[$column]['type']);
        }

        return $out;
    }

    /** @return list<mixed> */
    private function selectRules(array $field, bool $required): array
    {
        $options = ReportRegistry::options($field['options']);
        $values = array_map(static fn (array $o): string => (string) $o['value'], $options);

        return [
            $required ? 'required' : 'sometimes',
            'nullable',
            Rule::in($values),
        ];
    }

    /**
     * Coerce one submitted value for storage.
     *
     * The text branch returns the value UNCHANGED — see the class docblock. Decimals
     * are kept as strings so nothing routes through a PHP float; §15.2 is the
     * standing precedent for what floats do to values in this app.
     */
    private function cast(mixed $value, string $type): mixed
    {
        return match ($type) {
            'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false,
            'decimal' => ($value === null || $value === '') ? null : (string) $value,
            'select' => ($value === null || $value === '') ? null : $value,
            default => $value,
        };
    }
}
