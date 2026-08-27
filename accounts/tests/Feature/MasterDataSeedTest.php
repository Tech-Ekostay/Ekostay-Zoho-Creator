<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * §17 step 2 verification: "every FK resolves", plus the data-fidelity rules the
 * handoff and addendum insist on.
 *
 * These assertions are pinned to the 12-Aug-2026 exports. If an export is
 * refreshed and a count moves, that is a real data change and this test SHOULD
 * fail — do not relax it without looking at why.
 */
class MasterDataSeedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    #[Test]
    public function it_seeds_the_documented_record_counts(): void
    {
        $this->assertSame(10, DB::table('master_categories')->count());
        $this->assertSame(135, DB::table('item_categories')->count());
        $this->assertSame(144, DB::table('coa_accounts')->count());
        $this->assertSame(8, DB::table('taxes')->count());
        $this->assertSame(35, DB::table('tds_rates')->count());
        $this->assertSame(25, DB::table('roles')->count());  // 24 live values + 'manager', not the 10 in §3.3

        // Villas now come from the real report export (254 records, 252 distinct
        // names — `Nature` is three separate records). Previously 204 bare names
        // recovered from the Approvals export.
        $this->assertSame(254, DB::table('villas')->count());

        // Derived from the villa report, not the 10-name recovered file. Nineteen
        // locations were missing before — see addendum §15.
        //
        // 30, not 29: the villa export yields 29, and VendorSeeder recovers
        // `Alleppey`, which is named by a vendor and has no villa. All_Villas.csv is
        // a villa-scoped view of the Location master, so it under-reports it. See
        // VendorMasterSeedTest.
        $this->assertSame(30, DB::table('locations')->count());
        $this->assertSame(8, DB::table('states')->count());   // 9 distinct values, one of which is blank
    }

    /**
     * Correcting §3.1: the picklist offers four rent types but the live data holds
     * only two. The EKOSTAY split types have ZERO records, so the bug §3.1 calls
     * "live" is latent. If this test starts failing because an EKOSTAY count went
     * above zero, the unhandled-branch bug has just become real — go read §3.1.
     */
    #[Test]
    public function the_live_rent_type_distribution_is_only_two_of_the_four(): void
    {
        $this->assertSame(180, DB::table('villas')->where('rent_type', 'Lease')->count());
        $this->assertSame(65, DB::table('villas')->where('rent_type', 'Revenue Share')->count());
        $this->assertSame(9, DB::table('villas')->whereNull('rent_type')->count());

        foreach (['Revenue Split EKOSTAY', 'Expense Split EKOSTAY'] as $unhandled) {
            $this->assertSame(
                0,
                DB::table('villas')->where('rent_type', $unhandled)->count(),
                "'{$unhandled}' now has records — §3.1's unhandled branch is live, not latent"
            );
        }
    }

    /** Correcting §3.1 and handoff §2 rule 7: the spelling is `Luxury`. */
    #[Test]
    public function the_villa_category_is_spelled_luxury_not_luxery(): void
    {
        $this->assertSame(0, DB::table('villas')->where('category', 'Luxery')->count());
        $this->assertSame(34, DB::table('villas')->where('category', 'Luxury')->count());
        $this->assertSame(123, DB::table('villas')->where('category', 'Gold')->count());
        $this->assertSame(86, DB::table('villas')->where('category', 'Original')->count());
    }

    /** `Uttarakand` is missing its 'h' and is a live grouping key. */
    #[Test]
    public function the_misspelled_state_is_preserved(): void
    {
        $this->assertSame(1, DB::table('states')->where('name', 'Uttarakand')->count());
        $this->assertSame(0, DB::table('states')->where('name', 'Uttarakhand')->count());
    }

    /** BHK is TEXT, not a number — `6.5BHK` is a real value. */
    #[Test]
    public function bhk_holds_fractional_text_values(): void
    {
        $this->assertGreaterThan(
            0,
            DB::table('villas')->where('bhk', 'like', '%.5BHK')->count(),
            'fractional BHK values were lost — the column must stay TEXT'
        );
    }

    #[Test]
    public function every_foreign_key_resolves(): void
    {
        $orphanItemCategories = DB::table('item_categories as i')
            ->leftJoin('master_categories as m', 'm.id', '=', 'i.master_category_id')
            ->whereNotNull('i.master_category_id')
            ->whereNull('m.id')
            ->count();

        $this->assertSame(0, $orphanItemCategories);

        $orphanCoa = DB::table('coa_accounts as c')
            ->leftJoin('ca_masters as a', 'a.id', '=', 'c.ca_master_id')
            ->whereNotNull('c.ca_master_id')
            ->whereNull('a.id')
            ->count();

        $this->assertSame(0, $orphanCoa);

        // All 135 item categories carry a master category — none fell through.
        $this->assertSame(0, DB::table('item_categories')->whereNull('master_category_id')->count());
    }

    /**
     * Record ids are 18-digit strings. §15.2 caught them being corrupted by
     * float() (…361075 -> …361100), found only via a duplicate-key warning.
     */
    #[Test]
    public function record_ids_are_preserved_as_eighteen_character_strings(): void
    {
        $ids = DB::table('coa_accounts')->pluck('creator_id');

        $this->assertCount(144, $ids);

        foreach ($ids as $id) {
            $this->assertIsString($id);
            $this->assertSame(18, strlen($id));
            $this->assertMatchesRegularExpression('/^\d{18}$/', $id);
        }
    }

    /**
     * §15.2: a boolean mapper compared against the string "true" while the data
     * held real booleans, so all 144 COA flags read false. Zero here means the
     * bug is back.
     */
    #[Test]
    public function boolean_flags_are_not_uniformly_false(): void
    {
        $this->assertSame(47, DB::table('coa_accounts')->where('hide', true)->count());
        $this->assertSame(44, DB::table('coa_accounts')->where('bank', true)->count());
        $this->assertSame(1, DB::table('master_categories')->where('fb', true)->count());
        $this->assertSame(1, DB::table('item_categories')->where('exclude_for_observation', true)->count());
    }

    /** Dirty data is load-bearing. Normalising on import breaks live joins. */
    #[Test]
    public function it_preserves_significant_whitespace(): void
    {
        $this->assertSame(
            1,
            DB::table('item_categories')->where('name', 'F&B STAFF MEDICAL EXPENSE ')->count(),
            'the trailing space on F&B STAFF MEDICAL EXPENSE was normalised away'
        );

        // Twelve in the real master, not the 8 the recovered file held. They are
        // real records — verified against All_Villas.csv, addendum §15.
        $this->assertSame(12, DB::table('villas')->whereRaw('name <> ltrim(name)')->count());

        foreach (['Athens Villa  Nerul', 'Windsor  Villa', 'StarMount  Villa'] as $name) {
            $this->assertSame(1, DB::table('villas')->where('name', $name)->count(), $name);
        }
    }

    /**
     * `Nature` is three separate villa records with distinct ids — addendum §3 was
     * exact. Each is its own grouping key for §5.1 splits, so all three must
     * survive as rows; collapsing them would merge three villas' expenses.
     */
    #[Test]
    public function nature_is_three_distinct_villa_records(): void
    {
        $this->assertSame(3, DB::table('villas')->where('name', 'Nature')->count());
        $this->assertSame(
            3,
            DB::table('villas')->where('name', 'Nature')->distinct()->count('creator_id')
        );

        // and the test record rides along, deliberately
        $this->assertSame(1, DB::table('villas')->where('name', 'fcgfhbjnh')->count());
    }

    #[Test]
    public function it_preserves_near_duplicate_names_as_distinct_records(): void
    {
        // Two spellings of one property — distinct grouping keys today.
        $this->assertSame(1, DB::table('villas')->where('name', 'Copacabana Villa Calangute')->count());
        $this->assertSame(1, DB::table('villas')->where('name', 'Copacabana Villa- Calangute')->count());

        // A genuine duplicate with two different record ids (addendum §3).
        $this->assertSame(2, DB::table('coa_accounts')->where('account_name', 'EKOSTAY IDFC LLP')->count());
        $this->assertSame(
            2,
            DB::table('coa_accounts')->where('account_name', 'EKOSTAY IDFC LLP')->distinct()->count('creator_id')
        );
    }

    /**
     * The spec says TDS.Status is {Active, Expired}. Expired occurs zero times and
     * 16 rows are blank — blank is the real second state (addendum §1).
     */
    #[Test]
    public function tds_status_has_no_expired_and_blank_is_a_real_state(): void
    {
        $this->assertSame(0, DB::table('tds_rates')->where('status', 'Expired')->count());
        $this->assertSame(16, DB::table('tds_rates')->whereNull('status')->count());
        $this->assertSame(19, DB::table('tds_rates')->where('status', 'Active')->count());
    }

    /** 35 rows, 16 distinct rates — the duplicates feed the live picker. */
    #[Test]
    public function duplicate_tds_rates_are_retained_pending_the_books_id_question(): void
    {
        // Counted through a subquery on purpose: ->distinct()->count() emits
        // count(*) and ignores the selected columns, so it returns 35.
        $distinct = DB::query()
            ->fromSub(
                fn ($query) => $query->from('tds_rates')->select('name', 'tds_percentage')->distinct(),
                'pairs'
            )
            ->count();

        $this->assertSame(16, $distinct);
        $this->assertSame(35, DB::table('tds_rates')->count());
    }

    /** §4.2 — F&B scoping is a boolean, never a string comparison on the name. */
    #[Test]
    public function fb_scoping_uses_the_flag(): void
    {
        $viaFlag = DB::table('item_categories as i')
            ->join('master_categories as m', 'm.id', '=', 'i.master_category_id')
            ->where('m.fb', true)
            ->count();

        $this->assertGreaterThan(0, $viaFlag, 'the F&B flag selects nothing — §4.2 regression');
    }

    /** COA[Bank == true] currently offers accounts that are not banks. */
    #[Test]
    public function it_records_the_bank_flag_inconsistency(): void
    {
        $misTyped = DB::table('coa_accounts')
            ->where('bank', true)
            ->where('account_type', '<>', 'bank')
            ->count();

        $this->assertSame(9, $misTyped, 'the COA bank-flag defect changed shape — re-read addendum §3');

        $this->assertSame(
            25,
            DB::table('coa_accounts')->where('account_type', 'bank')->where('bank', false)->count()
        );
    }
}
