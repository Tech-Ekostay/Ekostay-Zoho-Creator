<?php

declare(strict_types=1);

namespace App\Domain\Settings;

use App\Models\CoaAccount;
use App\Models\ItemCategory;
use App\Models\MasterCategory;
use App\Models\Tax;
use App\Models\TdsRate;
use Illuminate\Support\Facades\DB;

/**
 * The Settings reports — ONE definition, read and write.
 *
 * The read controller and the write controller were about to hold two copies of
 * the same column list, which is how a report and its form drift apart. Both now
 * read this.
 *
 * COLUMN ORDER IS THE SPEC. handoff §2 rule 2: "column order as the report shows
 * it", and `ID` is NOT always last — it sits sixth of seven on All Item
 * Categories. The orders here come from the real exports, which mirror the
 * reports exactly, so they are observed rather than inferred.
 *
 * FIELD order and the field SET are a different matter and are marked per report.
 * A Creator form usually carries more fields than its report shows, and the only
 * form definitions verified from screenshots so far are Approvals (addendum §11)
 * and Bills (§10). Where a form is unverified the fields below are the table's own
 * editable columns in report order, then the rest — flagged, not guessed at.
 *
 * WHAT IS NOT EDITABLE, and why:
 *
 *  - `creator_id`. An 18-digit Creator record id. Records created here have none,
 *    and typing one would fabricate a link to a Creator row that does not exist.
 *    Shown read-only so it is visible but cannot be invented. §15.2 is the standing
 *    warning about what happens when these are handled carelessly.
 *  - Nothing is deletable. These are live lookup keys with FK children — 135 item
 *    categories hang off 10 master categories. §7.6's argument about hard deletes
 *    applies with more force to a master than to a payment, and no Creator
 *    screenshot has shown a delete control on any of these eight reports.
 */
final class ReportRegistry
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        return [
            'all_master_categories' => [
                'table' => 'master_categories',
                'model' => MasterCategory::class,
                'title' => 'All Master Categories',
                'columns' => [
                    'Master Category' => 'name',
                    'F&B' => 'fb',
                    'Haewaya ID' => 'haewaya_id',
                    'ID' => 'creator_id',
                ],
                'order' => 'name',
                // Four columns, four fields — nothing hidden on this one.
                'fields_verified' => false,
                'fields' => [
                    ['column' => 'name', 'label' => 'Master Category', 'type' => 'text', 'required' => true,
                        'hint' => 'A live lookup key. Whitespace is preserved exactly as typed.'],
                    ['column' => 'fb', 'label' => 'F&B', 'type' => 'bool'],
                    ['column' => 'haewaya_id', 'label' => 'Haewaya ID', 'type' => 'text'],
                    ['column' => 'creator_id', 'label' => 'ID', 'type' => 'readonly',
                        'hint' => 'Creator record id. Blank on records created here.'],
                ],
            ],

            'all_item_categories' => [
                'table' => 'item_categories',
                'model' => ItemCategory::class,
                'title' => 'All Item Categories',
                // ID is column 6 of 7. Do not "tidy" it to the end.
                'columns' => [
                    'Item Category' => 'name',
                    'Master Category' => 'master_category',
                    'Expense Type' => 'expense_type',
                    'Exclude for Profit' => 'exclude_for_profit',
                    'Haewaya ID' => 'haewaya_id',
                    'ID' => 'creator_id',
                    'Exclude for Observation' => 'exclude_for_observation',
                ],
                'order' => 'name',
                'fields_verified' => false,
                'fields' => [
                    ['column' => 'name', 'label' => 'Item Category', 'type' => 'text', 'required' => true,
                        'hint' => 'A live lookup key — `F&B STAFF MEDICAL EXPENSE ` has a trailing space and keeps it. Whitespace is never trimmed.'],
                    ['column' => 'master_category_id', 'label' => 'Master Category', 'type' => 'select',
                        'options' => 'master_categories'],
                    ['column' => 'expense_type', 'label' => 'Expense Type', 'type' => 'select',
                        'options' => [['value' => '', 'label' => ''], ['value' => 'Direct', 'label' => 'Direct'], ['value' => 'Indirect', 'label' => 'Indirect']],
                        'hint' => 'Unset on 103 of 135 live rows — blank is normal.'],
                    ['column' => 'exclude_for_profit', 'label' => 'Exclude for Profit', 'type' => 'bool'],
                    ['column' => 'exclude_for_observation', 'label' => 'Exclude for Observation', 'type' => 'bool'],
                    ['column' => 'exclude_item_category', 'label' => 'Exclude Item Category', 'type' => 'bool'],
                    ['column' => 'disable', 'label' => 'Disallow Manual Creation', 'type' => 'bool'],
                    ['column' => 'variance', 'label' => 'Variance', 'type' => 'decimal', 'suffix' => '%'],
                    ['column' => 'haewaya_id', 'label' => 'Haewaya ID', 'type' => 'text',
                        'hint' => 'Empty on all 135 live rows.'],
                    ['column' => 'creator_id', 'label' => 'ID', 'type' => 'readonly'],
                ],
            ],

            'tds_report' => [
                'table' => 'tds_rates',
                'model' => TdsRate::class,
                'title' => 'TDS Report',
                // No ID column at all on this report.
                'columns' => [
                    'TDS Name' => 'name',
                    'TDS Percentage' => 'tds_percentage',
                    'Books ID' => 'books_id',
                    'Status' => 'status',
                ],
                'order' => 'name',
                'fields_verified' => false,
                'fields' => [
                    ['column' => 'name', 'label' => 'TDS Name', 'type' => 'text', 'required' => true],
                    ['column' => 'tds_percentage', 'label' => 'TDS Percentage', 'type' => 'decimal', 'suffix' => '%'],
                    ['column' => 'books_id', 'label' => 'Books ID', 'type' => 'text'],
                    ['column' => 'status', 'label' => 'Status', 'type' => 'select',
                        'options' => [['value' => '', 'label' => ''], ['value' => 'Active', 'label' => 'Active']],
                        'hint' => 'Live data holds Active or blank — never `Expired`, despite the label suggesting a lifecycle.'],
                ],
            ],

            'all_taxes' => [
                'table' => 'taxes',
                'model' => Tax::class,
                'title' => 'All Taxes',
                'columns' => [
                    'Tax Name' => 'name',
                    'Tax Type' => 'tax_type',
                    'Tax Percentage' => 'tax_percentage',
                    'Tax ID' => 'books_tax_id',
                    'ID' => 'creator_id',
                ],
                'order' => 'name',
                'fields_verified' => false,
                'fields' => [
                    ['column' => 'name', 'label' => 'Tax Name', 'type' => 'text', 'required' => true],
                    ['column' => 'tax_type', 'label' => 'Tax Type', 'type' => 'select',
                        'options' => [['value' => '', 'label' => ''], ['value' => 'tax', 'label' => 'tax (IGST)'], ['value' => 'tax_group', 'label' => 'tax_group (GST = CGST + SGST)']],
                        'hint' => 'Books API values, lowercase. `tax_group` splits to two ledger destinations behind one GST_Amount.'],
                    ['column' => 'tax_percentage', 'label' => 'Tax Percentage', 'type' => 'decimal', 'suffix' => '%',
                        'hint' => 'Addendum §3: IGST exists only at 0/5/18 while GST runs 0/5/12/18/28, so interstate at 12% or 28% has no entry to select.'],
                    ['column' => 'books_tax_id', 'label' => 'Tax ID', 'type' => 'text'],
                    ['column' => 'creator_id', 'label' => 'ID', 'type' => 'readonly'],
                ],
            ],

            'coa_report' => [
                'table' => 'coa_accounts',
                'model' => CoaAccount::class,
                'title' => 'COA Report',
                // The one report that is inline-editable in Creator — it carries
                // `Save Changes` / `Remove Changes` in the reportbar.
                'inline_editable' => true,
                'columns' => [
                    'Account Name' => 'account_name',
                    'Account Type' => 'account_type',
                    'Account Code' => 'account_code',
                    'Account ID' => 'books_account_id',
                    'COA' => 'hide',          // the boolean LABELLED `COA` — addendum §8
                    'Bank' => 'bank',
                    'CA Name' => 'ca_name_source',
                    'ID' => 'creator_id',
                    'Ekostay ID' => 'ekostay_id',
                ],
                'order' => 'account_name',
                // Which columns an inline edit may touch. `creator_id` is excluded
                // for the reason in the class docblock.
                'inline_columns' => ['account_name', 'account_type', 'account_code', 'books_account_id', 'hide', 'bank', 'ca_name_source', 'ekostay_id'],
                'fields_verified' => false,
                'fields' => [
                    ['column' => 'account_name', 'label' => 'Account Name', 'type' => 'text', 'required' => true,
                        'hint' => '`Accounts Payable` is load-bearing — Create_Payment forces every payment onto it (§7.2). Renaming it breaks payment creation.'],
                    ['column' => 'account_type', 'label' => 'Account Type', 'type' => 'text',
                        'hint' => '16 Books types live. Unconstrained on purpose; `bank` is load-bearing.'],
                    ['column' => 'account_code', 'label' => 'Account Code', 'type' => 'text',
                        'hint' => 'Populated on only 6 of 144 live rows.'],
                    ['column' => 'books_account_id', 'label' => 'Account ID', 'type' => 'text',
                        'hint' => '19-digit Books id. Kept as a string.'],
                    ['column' => 'hide', 'label' => 'COA', 'type' => 'bool',
                        'hint' => 'The column is `hide`; the report labels it `COA` (addendum §8). Whether the form checkbox IS the Hide field is still an OPEN QUESTION — handoff §6 item 2. If it is, §7.5\'s "inverted filter" finding dissolves.'],
                    ['column' => 'bank', 'label' => 'Bank', 'type' => 'bool',
                        'hint' => 'Load-bearing: the bank-account picker filters on this.'],
                    ['column' => 'ca_name_source', 'label' => 'CA Name', 'type' => 'text',
                        'hint' => 'Verbatim source text, kept for mapping to ca_masters.'],
                    ['column' => 'ekostay_id', 'label' => 'Ekostay ID', 'type' => 'text'],
                    ['column' => 'creator_id', 'label' => 'ID', 'type' => 'readonly'],
                ],
            ],
        ];
    }

    public static function has(string $report): bool
    {
        return isset(self::all()[$report]);
    }

    /** @return array<string, mixed>|null */
    public static function get(string $report): ?array
    {
        return self::all()[$report] ?? null;
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    /**
     * Resolve a field's `options` to concrete values.
     *
     * A string means "look these up" — the only such source today is
     * master_categories, ordered by name so the picker matches the report.
     * Anything already a list passes through.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function options(array|string $options): array
    {
        if (is_array($options)) {
            return $options;
        }

        return match ($options) {
            'master_categories' => DB::table('master_categories')
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn ($row): array => ['value' => (string) $row->id, 'label' => (string) $row->name])
                ->prepend(['value' => '', 'label' => ''])
                ->values()
                ->all(),
            default => [],
        };
    }

    /**
     * The editable fields, with option lists resolved and hints intact.
     *
     * @return list<array<string, mixed>>
     */
    public static function fields(string $report): array
    {
        $definition = self::get($report);

        if ($definition === null) {
            return [];
        }

        return array_map(function (array $field): array {
            if (isset($field['options'])) {
                $field['options'] = self::options($field['options']);
            }

            return $field;
        }, $definition['fields']);
    }
}
