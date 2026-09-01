<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Ds\FormSpec;
use PHPUnit\Framework\TestCase;

/**
 * `FormSpec` reads the parsed DS, so these assert against real exported values
 * rather than fixtures. If `docs/_parsed/*.json` is regenerated and a control type
 * moves, these fail — which is the point.
 *
 * The cascade test exists because of a specific defect: the dependency regex was
 * written with a literal 0x08 BACKSPACE byte in place of `\b`, so it matched
 * nothing and `depends_on` came back empty on every field in the application. It
 * passed `php -l`, it read correctly in a diff, and `cat -v` was the only thing
 * that showed it. A count assertion catches that; eyeballing does not.
 */
final class FormSpecTest extends TestCase
{
    public function test_a_fixed_picklist_is_a_closed_set_not_a_text_box(): void
    {
        $status = $this->field('accounts', 'Bills', 'Status');
        $choices = FormSpec::choices($status);

        self::assertSame('picklist', $status['type']);
        self::assertSame('fixed', $choices['kind']);
        self::assertSame([
            'Draft', 'Paid', 'Partially Paid', 'Overdue', 'Payment Inprogress', 'Overpaid',
        ], $choices['values']);
    }

    /**
     * `Payment Inprogress` — lowercase p — is Creator's picklist spelling, and the
     * addendum records `Payment InProgress` as ALSO live in the data. The picklist
     * offers one of them. Preserving the source spelling is the no-normalisation
     * rule; these are live lookup keys.
     */
    public function test_the_picklist_spelling_is_creators_not_a_tidied_one(): void
    {
        $choices = FormSpec::choices($this->field('accounts', 'Bills', 'Status'));

        self::assertContains('Payment Inprogress', $choices['values']);
        self::assertNotContains('Payment InProgress', $choices['values']);
    }

    public function test_list_is_multi_select_and_picklist_is_not(): void
    {
        // Same form, same lookup target, different arity. This is the distinction
        // that was being collapsed.
        self::assertTrue(FormSpec::isMultiSelect($this->field('accounts', 'Bills', 'Master_Category')));
        self::assertFalse(FormSpec::isMultiSelect($this->field('accounts', 'Item_Category', 'Master_Category')));
    }

    public function test_a_cross_app_lookup_keeps_its_app_prefix(): void
    {
        $choices = FormSpec::choices($this->field('accounts', 'Bills', 'Location'));

        self::assertSame('lookup', $choices['kind']);
        self::assertSame('admin', $choices['app']);
        self::assertSame('Location', $choices['table']);
    }

    public function test_a_dependent_lookup_reports_what_it_depends_on(): void
    {
        $choices = FormSpec::choices($this->field('accounts', 'Payment', 'Bill_No1'));

        self::assertSame('lookup', $choices['kind']);
        self::assertSame(['Vendor_Name'], $choices['depends_on']);
    }

    /**
     * The regression guard. A count, not a spot check: the backspace defect left
     * every individual field looking plausible while the whole feature was dead.
     */
    public function test_cascades_are_detected_across_the_application(): void
    {
        $found = 0;

        foreach (['accounts', 'admin', 'fnb'] as $app) {
            foreach (FormSpec::app($app) as $form) {
                foreach ($form['fields'] as $field) {
                    $choices = FormSpec::choices($field);
                    if (($choices['kind'] ?? null) === 'lookup' && ! empty($choices['depends_on'])) {
                        $found++;
                    }
                }
            }
        }

        self::assertGreaterThanOrEqual(14, $found, 'cascade detection has regressed');
    }

    public function test_initial_values_are_cast_not_left_as_strings(): void
    {
        $initials = FormSpec::initialValues('accounts', 'Bills');

        self::assertArrayHasKey('GST_Needed', $initials);
        self::assertFalse($initials['GST_Needed'], 'the DS string "false" must become a bool');
    }

    /**
     * Buttons are declared as fields by Creator and must never reach a renderer or
     * a persistence layer.
     */
    public function test_button_controls_are_not_renderable(): void
    {
        $types = array_column(FormSpec::renderable('accounts', 'Payment'), 'type');

        self::assertNotContains('submit', $types);
        self::assertNotContains('reset', $types);
    }

    public function test_fields_come_back_in_creators_layout_order(): void
    {
        $placed = array_values(array_filter(
            FormSpec::renderable('accounts', 'Payment'),
            static fn (array $f) => $f['row'] !== null && $f['column'] !== null,
        ));

        $previous = [0, 0];
        foreach ($placed as $f) {
            $current = [(int) $f['row'], (int) $f['column']];
            self::assertGreaterThanOrEqual($previous, $current, 'layout order is not monotonic');
            $previous = $current;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function field(string $app, string $form, string $name): array
    {
        foreach (FormSpec::form($app, $form)['fields'] as $field) {
            if ($field['name'] === $name) {
                return $field;
            }
        }

        self::fail("no field {$name} on {$app}.{$form}");
    }
}
