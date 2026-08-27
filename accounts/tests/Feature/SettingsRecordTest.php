<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CoaAccount;
use App\Models\ItemCategory;
use App\Models\MasterCategory;
use App\Models\TdsRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Add and edit for the Settings masters — the half that was missing.
 *
 * The `+` button, the row form and COA's `Save Changes` were rendered chrome: no
 * `onClick`, no write routes. These tests cover the write path that now sits
 * behind them.
 *
 * THE FIRST GROUP IS THE IMPORTANT ONE. CLAUDE.md's rule — "Preserve source
 * spellings ... These are live lookup keys. Normalise at display only, never in
 * data" — is only true if the write path honours it, and Laravel's global
 * TrimStrings middleware would have broken it by default. `bootstrap/app.php`
 * exempts `api/settings/*`; these assertions are what stop that exemption being
 * removed by someone tidying up later.
 */
class SettingsRecordTest extends TestCase
{
    use RefreshDatabase;

    /** The real live key: 26 characters stored, 25 trimmed. */
    private const TRAILING_SPACE_KEY = 'F&B STAFF MEDICAL EXPENSE ';

    // ------------------------------------------- whitespace, the load-bearing rule

    #[Test]
    public function a_trailing_space_survives_a_create(): void
    {
        $this->postJson('/api/settings/reports/all_item_categories', [
            'name' => self::TRAILING_SPACE_KEY,
        ])->assertCreated();

        $stored = ItemCategory::query()->orderByDesc('id')->value('name');

        $this->assertSame(self::TRAILING_SPACE_KEY, $stored);
        $this->assertSame(26, strlen($stored), 'the trailing space must still be there');
    }

    #[Test]
    public function a_trailing_space_survives_an_edit(): void
    {
        $category = ItemCategory::create(['name' => self::TRAILING_SPACE_KEY]);

        $this->patchJson("/api/settings/reports/all_item_categories/{$category->id}", [
            'name' => self::TRAILING_SPACE_KEY,
        ])->assertOk();

        $this->assertSame(self::TRAILING_SPACE_KEY, $category->fresh()->name);
        $this->assertSame(26, strlen($category->fresh()->name));
    }

    /** Eight villa names carry one and three carry doubled spaces — addendum §15. */
    #[Test]
    public function leading_and_doubled_spaces_survive_too(): void
    {
        foreach ([' Casa Bella', 'Athens Villa  Nerul', "  padded  "] as $name) {
            $this->postJson('/api/settings/reports/all_master_categories', ['name' => $name])
                ->assertCreated();

            $this->assertSame($name, MasterCategory::query()->orderByDesc('id')->value('name'));
        }
    }

    /**
     * The trimmed and untrimmed forms are DIFFERENT records.
     *
     * This is the proof that uniqueness compares the exact string. If the check
     * trimmed, the second insert would be rejected as a duplicate and the live key
     * would be unrepresentable.
     */
    #[Test]
    public function the_trimmed_and_untrimmed_forms_are_distinct_records(): void
    {
        $this->postJson('/api/settings/reports/all_item_categories', ['name' => self::TRAILING_SPACE_KEY])
            ->assertCreated();

        $this->postJson('/api/settings/reports/all_item_categories', ['name' => rtrim(self::TRAILING_SPACE_KEY)])
            ->assertCreated();

        $this->assertSame(2, ItemCategory::query()->count());
    }

    // -------------------------------------------------------------- create / edit

    #[Test]
    public function it_creates_a_master_category_with_its_flags(): void
    {
        $this->postJson('/api/settings/reports/all_master_categories', [
            'name' => 'NEW MASTER CATEGORY',
            'fb' => true,
            'haewaya_id' => 'HW-1',
        ])->assertCreated();

        $created = MasterCategory::query()->where('name', 'NEW MASTER CATEGORY')->firstOrFail();

        $this->assertTrue($created->fb);
        $this->assertSame('HW-1', $created->haewaya_id);
    }

    #[Test]
    public function it_edits_an_item_category_and_reassigns_its_master(): void
    {
        $from = MasterCategory::create(['name' => 'FROM']);
        $to = MasterCategory::create(['name' => 'TO']);
        $category = ItemCategory::create(['name' => 'MOVES', 'master_category_id' => $from->id]);

        $this->patchJson("/api/settings/reports/all_item_categories/{$category->id}", [
            'name' => 'MOVES',
            'master_category_id' => (string) $to->id,
            'expense_type' => 'Direct',
            'exclude_for_profit' => true,
        ])->assertOk();

        $fresh = $category->fresh();

        $this->assertSame($to->id, $fresh->master_category_id);
        $this->assertSame('Direct', $fresh->expense_type);
        $this->assertTrue($fresh->exclude_for_profit);
    }

    /** Percentages stay strings — nothing money-shaped touches a float (§15.2). */
    #[Test]
    public function a_tds_percentage_round_trips_as_a_string(): void
    {
        $this->postJson('/api/settings/reports/tds_report', [
            'name' => 'TDS 194C 1%',
            'tds_percentage' => '1.500',
            'status' => 'Active',
        ])->assertCreated();

        $rate = TdsRate::query()->where('name', 'TDS 194C 1%')->firstOrFail();

        $this->assertSame('1.500', $rate->tds_percentage);
        $this->assertIsString($this->getJson('/api/settings/reports/tds_report')
            ->json('rows.0.TDS Percentage'));
    }

    // ------------------------------------------------------------- validation

    #[Test]
    public function a_required_name_is_enforced(): void
    {
        $this->postJson('/api/settings/reports/all_master_categories', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    #[Test]
    public function a_duplicate_name_is_rejected(): void
    {
        MasterCategory::create(['name' => 'DUPLICATE']);

        $this->postJson('/api/settings/reports/all_master_categories', ['name' => 'DUPLICATE'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    /** Editing a record must not collide with itself. */
    #[Test]
    public function a_record_may_keep_its_own_name_on_edit(): void
    {
        $category = MasterCategory::create(['name' => 'UNCHANGED']);

        $this->patchJson("/api/settings/reports/all_master_categories/{$category->id}", [
            'name' => 'UNCHANGED',
            'fb' => true,
        ])->assertOk();

        $this->assertTrue($category->fresh()->fb);
    }

    #[Test]
    public function a_select_value_outside_its_options_is_rejected(): void
    {
        $this->postJson('/api/settings/reports/all_item_categories', [
            'name' => 'BAD EXPENSE TYPE',
            'expense_type' => 'Sideways',
        ])->assertStatus(422)->assertJsonValidationErrors('expense_type');
    }

    #[Test]
    public function a_master_category_id_that_does_not_exist_is_rejected(): void
    {
        $this->postJson('/api/settings/reports/all_item_categories', [
            'name' => 'ORPHAN',
            'master_category_id' => '999999',
        ])->assertStatus(422)->assertJsonValidationErrors('master_category_id');
    }

    /**
     * `creator_id` is readonly: an 18-digit Creator id must not be typed in, because
     * doing so fabricates a link to a Creator row that does not exist (§15.2).
     */
    #[Test]
    public function creator_id_cannot_be_set_through_the_form(): void
    {
        $this->postJson('/api/settings/reports/all_master_categories', [
            'name' => 'NO CREATOR ID',
            'creator_id' => '292482000000999999',
        ])->assertCreated();

        $this->assertNull(
            MasterCategory::query()->where('name', 'NO CREATOR ID')->value('creator_id'),
            'a readonly field must be ignored, not stored'
        );
    }

    #[Test]
    public function an_unknown_report_is_a_404(): void
    {
        $this->postJson('/api/settings/reports/not_a_report', ['name' => 'x'])->assertNotFound();
    }

    // ------------------------------------------------- COA inline edit

    #[Test]
    public function coa_inline_edit_applies_several_rows_at_once(): void
    {
        $a = CoaAccount::create(['account_name' => 'ACCOUNT A']);
        $b = CoaAccount::create(['account_name' => 'ACCOUNT B']);

        $this->patchJson('/api/settings/reports/coa_report', [
            'changes' => [
                ['id' => $a->id, 'values' => ['account_code' => 'A-1', 'bank' => true]],
                ['id' => $b->id, 'values' => ['account_type' => 'bank', 'hide' => true]],
            ],
        ])->assertOk()->assertJson(['count' => 2]);

        $this->assertSame('A-1', $a->fresh()->account_code);
        $this->assertTrue($a->fresh()->bank);
        $this->assertSame('bank', $b->fresh()->account_type);
        $this->assertTrue($b->fresh()->hide);
    }

    /**
     * One bad row rolls back the whole commit.
     *
     * The grid is edited as a unit and committed once, so applying half of it would
     * leave the screen disagreeing with the database about what the user just saw.
     */
    #[Test]
    public function a_failing_row_rolls_back_the_whole_inline_commit(): void
    {
        $account = CoaAccount::create(['account_name' => 'ROLLBACK', 'account_code' => 'ORIGINAL']);

        $this->patchJson('/api/settings/reports/coa_report', [
            'changes' => [
                ['id' => $account->id, 'values' => ['account_code' => 'CHANGED']],
                ['id' => 999999, 'values' => ['account_code' => 'X']],
            ],
        ])->assertStatus(422);

        $this->assertSame('ORIGINAL', $account->fresh()->account_code, 'the good row must have rolled back too');
    }

    #[Test]
    public function an_inline_edit_may_not_touch_an_undeclared_column(): void
    {
        $account = CoaAccount::create(['account_name' => 'GUARDED']);

        $this->patchJson('/api/settings/reports/coa_report', [
            'changes' => [['id' => $account->id, 'values' => ['creator_id' => '1']]],
        ])->assertStatus(422);
    }

    #[Test]
    public function only_the_coa_report_is_inline_editable(): void
    {
        $category = MasterCategory::create(['name' => 'NOT INLINE']);

        $this->patchJson('/api/settings/reports/all_master_categories', [
            'changes' => [['id' => $category->id, 'values' => ['name' => 'x']]],
        ])->assertStatus(422);
    }

    // --------------------------------------------------------- what must not exist

    /**
     * No delete route on any Settings report.
     *
     * These are live lookup keys with FK children — 135 item categories hang off 10
     * master categories — and no Creator screenshot has shown a delete control on
     * any of the eight reports. §7.6's argument against hard deletes applies with
     * more force to a master than to a payment.
     */
    #[Test]
    public function there_is_no_delete_route_for_a_settings_record(): void
    {
        $category = MasterCategory::create(['name' => 'UNDELETABLE']);

        $this->deleteJson("/api/settings/reports/all_master_categories/{$category->id}")
            ->assertStatus(405);
    }

    // ------------------------------------------------------ the form definition

    /** The form is built from the same definition as the grid. */
    #[Test]
    public function the_report_endpoint_ships_the_field_definitions(): void
    {
        $response = $this->getJson('/api/settings/reports/coa_report')->assertOk();

        $this->assertTrue($response->json('inline_editable'));
        $this->assertNotEmpty($response->json('fields'));
        $this->assertContains('account_name', array_column($response->json('fields'), 'column'));

        // Column ORDER is the spec — `COA` is the boolean labelled COA (addendum §8).
        $this->assertSame('Account Name', $response->json('columns.0'));
        $this->assertContains('COA', $response->json('columns'));
    }

    /** `_values` carries stored values, so the form opens on data not display text. */
    #[Test]
    public function rows_carry_their_raw_editable_values(): void
    {
        $master = MasterCategory::create(['name' => 'PARENT']);
        ItemCategory::create(['name' => 'CHILD', 'master_category_id' => $master->id]);

        $row = $this->getJson('/api/settings/reports/all_item_categories')->json('rows.0');

        // The grid shows the master category's NAME...
        $this->assertSame('PARENT', $row['Master Category']);
        // ...while the form edits its ID.
        $this->assertSame((string) $master->id, $row['_values']['master_category_id']);
    }
}
