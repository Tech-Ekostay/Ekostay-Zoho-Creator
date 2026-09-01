<?php

declare(strict_types=1);

namespace App\Domain\Ds;

use RuntimeException;

/**
 * The Creator form spec, read from `docs/_parsed/*.json`.
 *
 * WHY THIS EXISTS. Every form in this app was hand-written against somebody's
 * reading of the DS, and the same class of error kept surfacing on each new screen:
 * a `picklist` rendered as a text box you had to type `Draft` into, a `list`
 * rendered single-select when Creator allows many, an `initial value` never
 * applied, a checkbox rendered INTO a report grid where Creator prints the literal
 * text true/false. Four separate bug reports, one cause — the layout was being
 * remembered instead of read.
 *
 * `docs/parse_ds_forms.py` removed the excuse. It emits, per field, the control
 * type, the row/column Creator lays it out on, the lookup expression that feeds it
 * and the initial value it opens with, for all 77 base tables across the three
 * apps: 1,624 fields, 1,363 of them positioned. This class is how the application
 * reads that, so a form's shape has ONE source and it is the export.
 *
 * ---------------------------------------------------------------------------
 * WHAT THE DS CANNOT TELL YOU, and you must not infer it from here:
 *
 * **Mandatory-ness is absent.** `required` is zero across all 59,063 lines of
 * `Accounts.ds`, yet Creator renders COA with a red mandatory outline. Required-ness
 * is enforced inside `on validate` workflow bodies, not in the field declaration.
 * Anything in this class that looked like a `required` flag would be a fabrication.
 * Use the handler index (`docs/parse_ds_handlers.py --event validate`) for that.
 *
 * **Visibility is absent too.** Creator hides and disables fields from Deluge at
 * runtime — see `PaymentFieldState`, which encodes the COA-dependent branch that
 * hides eight fields. A field having a row and column here means it is DECLARED on
 * the canvas, not that it is visible for a given record.
 */
final class FormSpec
{
    /** @var array<string, array<string, array<string, mixed>>> app => form => spec */
    private static array $cache = [];

    private const APPS = [
        'accounts' => 'accounts_forms.json',
        'admin' => 'admin_forms.json',
        'fnb' => 'fnb_forms.json',
    ];

    /**
     * Controls that are buttons, not data. Creator declares them as fields; nothing
     * downstream should render or persist them.
     */
    private const BUTTONS = ['submit', 'reset', 'cancel', 'update'];

    /**
     * @return array<string, mixed> the form's spec: name, line, fields[]
     */
    public static function form(string $app, string $form): array
    {
        $forms = self::app($app);

        if (! isset($forms[$form])) {
            throw new RuntimeException(
                "no such form in {$app}: {$form}. Known: ".implode(', ', array_keys($forms))
            );
        }

        return $forms[$form];
    }

    /** @return array<string, array<string, mixed>> keyed by form name */
    public static function app(string $app): array
    {
        if (isset(self::$cache[$app])) {
            return self::$cache[$app];
        }

        if (! isset(self::APPS[$app])) {
            throw new RuntimeException("unknown app: {$app}");
        }

        /*
         * Resolved from __DIR__, not `base_path()`. The spec is a static asset at a
         * fixed offset from this class, and depending on the container would make
         * FormSpec unusable from a plain unit test or a standalone script for no
         * gain. app/Domain/Ds -> accounts/.
         */
        $path = dirname(__DIR__, 3).'/docs/_parsed/'.self::APPS[$app];

        if (! is_file($path)) {
            throw new RuntimeException(
                "parsed form spec missing: {$path}. Regenerate with "
                ."`python docs/parse_ds_forms.py <ds> --json {$path}`."
            );
        }

        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        $byName = [];
        foreach ($decoded as $form) {
            $byName[$form['name']] = $form;
        }

        return self::$cache[$app] = $byName;
    }

    /**
     * The fields a UI should render, in Creator's own layout order.
     *
     * Sorted by row then column because that is the reading order of the canvas,
     * and the JSON preserves DECLARATION order, which is not the same thing — on
     * the Payment form the declaration order interleaves the two columns.
     *
     * @return list<array<string, mixed>>
     */
    public static function renderable(string $app, string $form): array
    {
        $fields = array_values(array_filter(
            self::form($app, $form)['fields'],
            static fn (array $f) => ! in_array($f['type'], self::BUTTONS, true),
        ));

        usort($fields, static function (array $a, array $b) {
            // Unplaced fields sort last rather than to row 0: a null row means the
            // export gave no position, and guessing one puts a field somewhere
            // Creator never put it.
            $ar = $a['row'] === null ? PHP_INT_MAX : (int) $a['row'];
            $br = $b['row'] === null ? PHP_INT_MAX : (int) $b['row'];

            return [$ar, (int) ($a['column'] ?? 0)] <=> [$br, (int) ($b['column'] ?? 0)];
        });

        return $fields;
    }

    /**
     * Classify a field's `values` expression, which the DS writes in two shapes that
     * must NOT be rendered the same way.
     *
     *   fixed   values = {"Draft","Paid","Partially Paid",...}
     *   lookup  values = COA[Hide == true].ID
     *   lookup  values = fb.Booking[Villa_Name.ID == input.Villa_Name].ID
     *
     * A fixed list is a closed set and ships with the form. A lookup is a query
     * against another table, sometimes another APP (`fb.`, `admin.`, `accounts.`),
     * and sometimes DEPENDENT on another field on this form — `input.Vendor_Name`
     * is Creator's cascade, the thing that makes Bill No refill when the vendor
     * changes. Losing the distinction is how a cascading dropdown becomes a
     * free-text box.
     *
     * The filter is returned verbatim rather than translated. Deluge predicates do
     * not map cleanly onto SQL, and a half-translated filter that silently widens a
     * result set is worse than one the caller must handle deliberately.
     *
     * @return array{kind: string, values?: list<string>, app?: string|null,
     *               table?: string, filter?: string|null, depends_on?: list<string>}|null
     */
    public static function choices(array $field): ?array
    {
        $raw = $field['values'] ?? null;

        if ($raw === null || $raw === '') {
            return null;
        }

        $raw = trim($raw);

        // Fixed set: {"A","B","C"}
        if (str_starts_with($raw, '{')) {
            preg_match_all('/"([^"]*)"/', $raw, $m);

            return ['kind' => 'fixed', 'values' => $m[1]];
        }

        // Lookup: [app.]Table[filter].Field
        if (! preg_match('/^(?:([a-z]+)\.)?([A-Za-z0-9_]+)(?:\[(.*)\])?(?:\.([A-Za-z0-9_]+))?$/s', $raw, $m)) {
            // Unrecognised shape. Say so rather than guessing it is a table name.
            return ['kind' => 'unparsed', 'filter' => $raw];
        }

        $filter = ($m[3] ?? '') !== '' ? $m[3] : null;

        // `input.X` is Creator naming another field ON THIS FORM. Those are the
        // cascades; the UI has to re-query when X changes.
        $depends = [];
        if ($filter !== null && preg_match_all('/input\.([A-Za-z0-9_]+)/', $filter, $dm)) {
            $depends = array_values(array_unique($dm[1]));
        }

        return [
            'kind' => 'lookup',
            'app' => ($m[1] ?? '') !== '' ? $m[1] : null,
            'table' => $m[2],
            'filter' => $filter,
            'depends_on' => $depends,
        ];
    }

    /**
     * Whether this control takes MANY values. Creator distinguishes `picklist`
     * (one) from `list` (many) and we were rendering both as one — the
     * "Item category, Villas, Billing Cycles will be multi select" report. 70
     * fields across the three apps are `list`.
     */
    public static function isMultiSelect(array $field): bool
    {
        return in_array($field['type'], ['list', 'checkboxes'], true);
    }

    /**
     * Fields carrying a DS `initial value`, as name => value.
     *
     * 144 fields across the three apps declare one and NONE were being applied, so
     * a new record opened blank where Creator opens it populated — the "Status has
     * to be typed manually" report.
     *
     * Booleans are cast because the DS writes them as the strings 'true'/'false'.
     *
     * @return array<string, mixed>
     */
    public static function initialValues(string $app, string $form): array
    {
        $out = [];

        foreach (self::renderable($app, $form) as $f) {
            if ($f['initial'] === null || $f['type'] === 'section') {
                continue;
            }

            $out[$f['name']] = match ($f['initial']) {
                'true' => true,
                'false' => false,
                default => $f['initial'],
            };
        }

        return $out;
    }
}
