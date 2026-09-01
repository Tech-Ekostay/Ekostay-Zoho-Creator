<?php

namespace Tests\Feature;

use App\Models\Vendor;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Vendor_Master — 8,063 real records, export of 22-Aug-2026.
 *
 * Two jobs. First, pin the dirty data so a later "cleanup" fails loudly instead of
 * silently merging vendors that are distinct in Creator. Second, pin §13A.1's merge
 * semantics, which these counts are what settled.
 *
 * Every figure here was measured against the export, not chosen. If one moves, the
 * data changed — go and look at why before relaxing the assertion.
 */
class VendorMasterSeedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    /*
     * RE-MEASURED 29-Aug-2026 against a fresh FORM export (8,161 rows, was 8,063).
     *
     * Every count below is a SNAPSHOT of live data, not a rule. They moved because
     * the export is five days newer — 83 vendors were added after 24-Aug — and not
     * because anything broke. What actually matters is asserted structurally and did
     * NOT change: the pointer/target sets stay mutually exclusive (0 rows hold both),
     * merges never resolve through main_primary, edge whitespace survives, and ids
     * stay 18-character strings.
     *
     * Two figures moved for a second reason worth knowing. 'gst_no_1' went 7 -> 21
     * and distinct names 7,985 -> 8,083: the earlier import came from a REPORT export
     * whose header repeats 'GST No.' three times, and array_combine silently dropped
     * two of them. This is the form export, so all three GST columns arrive intact.
     *
     * If these fail again after a re-export, re-measure before assuming a defect.
     */
    #[Test]
    public function it_seeds_every_vendor_record(): void
    {
        $this->assertSame(8161, DB::table('vendors')->count());

        // 18-character Creator ids, kept as strings. A float cast corrupts these
        // (…361075 -> …361100), which is why ReadsMasterData::id() throws on a
        // numeric id rather than storing a rounded one.
        $this->assertSame(0, DB::table('vendors')
            ->whereRaw('length(creator_id) <> 18')->count());
        $this->assertSame(8161, DB::table('vendors')
            ->distinct()->count('creator_id'));
    }

    /**
     * §13A.1, ANSWERED. `Primary Vendor` is the merge pointer and `Primary Status`
     * flags the target; the two are mutually exclusive, and every pointer lands on
     * a flagged target with no orphans in either direction. That total consistency
     * across 112 rows is the evidence — a denormalised field would not be perfect.
     */
    #[Test]
    public function the_merge_pointer_and_the_merge_target_flag_are_mutually_exclusive(): void
    {
        $this->assertSame(138, DB::table('vendors')->whereNotNull('primary_vendor')->count());
        $this->assertSame(104, DB::table('vendors')->where('is_primary', true)->count());

        // No row is both merged away and a merge target.
        $this->assertSame(0, DB::table('vendors')
            ->whereNotNull('primary_vendor')->where('is_primary', true)->count());

        // Every pointer names a vendor that IS flagged as a target...
        $pointedAt = DB::table('vendors')->whereNotNull('primary_vendor')
            ->distinct()->pluck('primary_vendor');
        $this->assertCount(104, $pointedAt);

        $flagged = DB::table('vendors')->where('is_primary', true)->pluck('name');
        $this->assertSame([], array_values(array_diff($pointedAt->all(), $flagged->all())),
            'a merge pointer names a vendor that is not flagged Primary Status');

        // ...and every flagged target is pointed at by something. Zero orphan flags.
        $this->assertSame([], array_values(array_diff($flagged->all(), $pointedAt->all())),
            'a vendor is flagged Primary Status but nothing was merged into it');
    }

    /**
     * `Main Primary` IS NOT the merge field, and this is the test that says so.
     *
     * It differs from the vendor name on 739 rows of which only 108 are merges —
     * the other 631 have no pointer at all — and it is blank on 1,106. Resolving a
     * merge through it would move money to the wrong vendor 631 times.
     *
     * That does NOT make the field junk: blank-versus-set turns out to separate
     * customer payees from trade vendors, which is the test below. It is the wrong
     * field for merges and the right field for something else.
     */
    #[Test]
    public function main_primary_is_not_authoritative_for_merges(): void
    {
        $differsWithoutMerge = DB::table('vendors')
            ->whereNotNull('main_primary')
            ->whereColumn('main_primary', '<>', 'name')
            ->whereNull('primary_vendor')
            ->count();

        $this->assertSame(620, $differsWithoutMerge,
            'Main Primary differing from the name does NOT imply a merge');

        $this->assertSame(1123, DB::table('vendors')->whereNull('main_primary')->count());

        /*
         * The single row that proves Main Primary goes stale: the merge happened
         * and Main Primary never followed it. It is the only row where the two
         * fields disagree, and it disagrees in Main Primary's direction being wrong.
         */
        $drifted = Vendor::query()->where('name', 'MOHANRAJ Y (CT)')->sole();
        $this->assertSame('MOHANRAJ V (PM)', $drifted->primary_vendor);
        $this->assertSame('MOHANRAJ Y (CT)', $drifted->main_primary);
    }

    /**
     * BLANK `Main Primary` MARKS A CUSTOMER PAYEE, not merge state — confirming the
     * `[UI]` note in spec §13A.1 ("Main_Primary mirrors Vendor Name for trade
     * vendors and is empty for customers") and quantifying it for the first time.
     *
     * This is the other half of the field's story. It is useless for resolving a
     * merge (see above) and load-bearing as a trade-vendor/customer discriminator:
     *
     *   1,099 vendors named `…(Customer)`  ->  1,097 have Main Primary blank  (99.8%)
     *   6,964 other vendors                ->      9 have Main Primary blank  ( 0.1%)
     *
     * So `Vendor_Master` holds two populations in one table, and the field that
     * separates them is the one nobody documented as doing so. Eleven rows disagree
     * with the rule in both directions and are left alone — the point is that the
     * signal is strong, not that it is a constraint.
     */
    #[Test]
    public function a_blank_main_primary_marks_a_customer_payee_rather_than_a_merge(): void
    {
        $customers = fn () => \Illuminate\Support\Facades\DB::table('vendors')
            ->where('name', 'ilike', '%(customer)%');
        $trade = fn () => \Illuminate\Support\Facades\DB::table('vendors')
            ->where('name', 'not ilike', '%(customer)%');

        $this->assertSame(1116, $customers()->count());
        $this->assertSame(1114, $customers()->whereNull('main_primary')->count());

        $this->assertSame(7045, $trade()->count());
        $this->assertSame(9, $trade()->whereNull('main_primary')->count());
    }

    /**
     * The pointer is a NAME, and one name does not identify one row — so the
     * resolved foreign key is a convenience and the text is the authority.
     */
    #[Test]
    public function an_ambiguous_merge_pointer_leaves_the_foreign_key_null_and_keeps_the_text(): void
    {
        $this->assertSame(134, DB::table('vendors')->whereNotNull('primary_vendor_id')->count());

        $unresolvable = Vendor::query()
            ->whereNotNull('primary_vendor')
            ->whereNull('primary_vendor_id')
            ->get();

        $this->assertCount(4, $unresolvable);
        foreach ($unresolvable as $vendor) {
            $this->assertSame('ETRADE MARKETING PRIVATE LIMITED', $vendor->primary_vendor,
                'the only unresolvable pointer should be the one matching several rows');
        }

        // Four rows carry that exact name, which is why it cannot resolve.
        $this->assertSame(4, DB::table('vendors')
            ->where('name', 'ETRADE MARKETING PRIVATE LIMITED')->count());
    }

    /**
     * 326 names carry a leading or trailing SPACE and 2 more end in TABS. This is
     * the `F&B STAFF MEDICAL EXPENSE ` rule at scale: these are live lookup keys,
     * and trimming them merges vendors Creator keeps apart.
     */
    #[Test]
    public function vendor_names_are_stored_with_their_whitespace_intact(): void
    {
        $this->assertSame(324, DB::table('vendors')
            ->whereRaw('name <> trim(name)')->count());

        /*
         * THE CASE THAT MAKES IT CONCRETE. Both of these exist, as five rows in
         * total, and they are different vendors as far as every join in the app is
         * concerned. Trim the names and five rows become four.
         */
        $this->assertSame(4, DB::table('vendors')
            ->where('name', 'ETRADE MARKETING PRIVATE LIMITED')->count());
        $this->assertSame(1, DB::table('vendors')
            ->where('name', 'ETRADE MARKETING PRIVATE LIMITED ')->count());

        /*
         * Two names end in TAB characters — invisible in every UI, and stripped by
         * anything that trims on whitespace rather than on spaces. Worth an
         * assertion of its own: a `trim()`-based test would not have caught them,
         * which is why the count above is 326 and not 328.
         */
        $this->assertSame(1, DB::table('vendors')
            ->where('name', "Mukesh chaudhary Alibaug\t\t\t")->count());
        $this->assertSame(1, DB::table('vendors')
            ->where('name', "Mohan Mukhikya\t")->count());
    }

    /**
     * Duplicate and blank names are live data — the reason `vendors.name` has no
     * unique index. §13A already records Payment Requests approved against a blank
     * vendor; these five rows are where such a request would come from.
     */
    #[Test]
    public function duplicate_and_blank_vendor_names_survive_the_import(): void
    {
        $this->assertSame(8083, DB::table('vendors')->distinct()->count('name'));

        $duplicated = DB::select(
            "select count(*) c from (
                select name from vendors where name <> '' group by name having count(*) > 1
             ) t"
        );
        $this->assertSame(62, (int) $duplicated[0]->c);

        // Five nameless vendors, added by three different users across ten months.
        $blank = Vendor::query()->where('name', '')->get();
        $this->assertCount(5, $blank);
        $this->assertSame(3, $blank->pluck('added_user')->unique()->count());
        $this->assertTrue($blank->every(fn ($v) => $v->added_time !== null),
            'Creator stamped these, so they are real records and not parse artefacts');
    }

    /**
     * THREE COLUMNS LABELLED `GST No.`, holding three different sets of values.
     *
     * Read by header name — which is what `masterDataCsv()` does via array_combine —
     * two of the three vanish silently and 7 rows of GST data disappear. That is
     * why the seeder reads this export positionally.
     */
    #[Test]
    public function all_three_gst_columns_are_kept_separately(): void
    {
        $this->assertSame(21, DB::table('vendors')->whereNotNull('gst_no_1')->count());
        $this->assertSame(297, DB::table('vendors')->whereNotNull('gst_no_2')->count());
        $this->assertSame(295, DB::table('vendors')->whereNotNull('gst_no_3')->count());

        // #1 is not merely #2 rendered twice: it is blank on 276 rows where #2 is set.
        $this->assertSame(276, DB::table('vendors')
            ->whereNull('gst_no_1')->whereNotNull('gst_no_2')->count());

        // Where both are set they agree, on all 21 — which is what makes the
        // relationship between the columns genuinely unresolved rather than obvious.
        $this->assertSame(0, DB::table('vendors')
            ->whereNotNull('gst_no_1')
            ->whereColumn('gst_no_1', '<>', 'gst_no_2')->count());

        // #2 and #3 disagree on 6 rows. Not reconciled — see the migration.
        $this->assertSame(6, DB::table('vendors')
            ->whereNotNull('gst_no_2')->whereNotNull('gst_no_3')
            ->whereColumn('gst_no_2', '<>', 'gst_no_3')->count());
    }

    /**
     * GST numbers are dirty in two ways that any normalisation would hide: case is
     * inconsistent, and one carries a trailing space. Both are stored verbatim —
     * a GST validator belongs on the form, where a human can see the value it
     * rejects, not on an import that silently rewrites history.
     */
    #[Test]
    public function gst_numbers_keep_their_case_and_their_trailing_space(): void
    {
        // The same registration, cased two ways on two different vendors.
        $this->assertGreaterThan(0, DB::table('vendors')
            ->where('gst_no_2', '27aahfe2088h1zb')->count());
        $this->assertGreaterThan(0, DB::table('vendors')
            ->where('gst_no_3', '27AAHFE2088H1ZB')->count());

        // Decathlon: #2 has a trailing space, #3 does not. Same number, two values.
        $decathlon = Vendor::query()->where('name', 'Decathlon')->sole();
        $this->assertSame('27AAACL9861H1Z6 ', $decathlon->gst_no_2);
        $this->assertSame('27AAACL9861H1Z6', $decathlon->gst_no_3);
    }

    /**
     * PAN is populated on 515 of 8,063 and 18 of those are the literal string `NA`.
     * A PAN-shaped CHECK constraint would reject live rows, so there isn't one.
     */
    #[Test]
    public function junk_pan_values_are_stored_rather_than_rejected(): void
    {
        $this->assertSame(528, DB::table('vendors')->whereNotNull('pan_no')->count());
        $this->assertSame(20, DB::table('vendors')->where('pan_no', 'NA')->count());
    }

    /**
     * The employee half of the vendor master. `employee_designation` is TEXT on
     * purpose: `employee_designations` is still an empty table with no export, and
     * these 25 values are a candidate source for it rather than proof of its
     * contents. Their own dirtiness is the argument — `Social media `, `OFFICE BOY `
     * and `HELPER` are not a curated picklist.
     */
    #[Test]
    public function employee_vendors_carry_free_text_designations(): void
    {
        $this->assertSame(296, DB::table('vendors')->where('is_employee', true)->count());

        $designations = DB::table('vendors')
            ->whereNotNull('employee_designation')
            ->distinct()->pluck('employee_designation');

        $this->assertCount(26, $designations);
        $this->assertContains('caretaker', $designations->all());
        $this->assertContains('Social media ', $designations->all(),
            'trailing space kept — this is a value, not a label');

        // Still empty, and deliberately: no FK points at it yet.
        $this->assertSame(0, DB::table('employee_designations')->count());
    }

    /**
     * `Alleppey` — the one Location the villa export does not contain.
     *
     * `locations` was derived from All_Villas.csv, which is a VILLA-scoped view of
     * Creator's Location master. Ekostay has no villa in Alleppey and one vendor
     * there, so the row is recovered rather than invented. This is also why
     * MasterDataSeedTest now expects 30 locations, not 29.
     */
    #[Test]
    public function the_one_location_named_only_by_a_vendor_is_recovered(): void
    {
        $this->assertSame(30, DB::table('locations')->count());

        $alleppey = DB::table('locations')->where('name', 'Alleppey')->sole();
        $this->assertSame(1, DB::table('vendors')->where('location_id', $alleppey->id)->count());

        // Every other vendor location resolved without being created.
        $this->assertSame(1020, DB::table('vendors')->whereNotNull('location_id')->count());
    }

    /**
     * Creator's own audit stamps, parsed from `dd-MMM-yyyy HH:mm:ss` and kept apart
     * from Laravel's timestamps — they record who touched the record in Creator,
     * which `created_at` cannot, and they survive a re-seed.
     */
    #[Test]
    public function creator_audit_stamps_are_parsed_and_kept(): void
    {
        $this->assertSame(0, DB::table('vendors')->whereNull('added_time')->count());
        $this->assertSame(0, DB::table('vendors')->whereNull('modified_time')->count());

        $this->assertGreaterThan(1, DB::table('vendors')
            ->whereNotNull('added_user')->distinct()->count('added_user'));
    }

    /**
     * The scope Bills and Payments should use: a merged-away vendor is not a valid
     * target for new work, its primary is. Reports still show the merged rows, so
     * this is a scope and not a global filter.
     */
    #[Test]
    public function the_selectable_vendor_list_excludes_merged_away_records(): void
    {
        $this->assertSame(8161 - 138, Vendor::query()->notMergedAway()->count());
    }
}
