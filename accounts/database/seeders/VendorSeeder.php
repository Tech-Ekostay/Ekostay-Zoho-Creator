<?php

namespace Database\Seeders;

use Database\Seeders\Concerns\ReadsMasterData;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Vendors — 8,063 records from `Vendor_Master.csv`, the real export of 22-Aug-2026.
 *
 * THIS CORRECTS CLAUDE.md, which listed vendors under "Not seeded, no export
 * exists" alongside employee_designations, employee_departments and
 * billing_cycles. The export did exist. Of the four, only billing_cycles and the
 * two employee lookups are still unexported.
 *
 * Until now the only vendor in the database was `TestBillSeeder`'s fixture, which
 * is why the Bills vendor picker had exactly one option.
 *
 * READ POSITIONALLY, NOT BY NAME. The header repeats `GST No.` three times; see
 * `masterDataCsvPositional()` for what reading it by name silently costs.
 *
 * ---------------------------------------------------------------------------
 * DIRTY DATA PRESERVED, ALL OF IT DELIBERATE. Every item here is asserted in
 * `tests/Feature/VendorMasterSeedTest.php`, so a later "cleanup" fails loudly.
 *
 *  1. 328 names carry leading or trailing whitespace — 326 with spaces, and TWO
 *     ending in TAB characters (`Mohan Mukhikya	`, `Mukesh chaudhary Alibaug`
 *     with three). A tab is invisible in every UI and survives nothing that trims.
 *     `ETRADE MARKETING PRIVATE LIMITED ` exists ALONGSIDE the same name without
 *     the trailing space: trim them and five rows become four. This is the
 *     `F&B STAFF MEDICAL EXPENSE ` rule again, 328 times over.
 *
 *  2. 5 records have a BLANK vendor name and are live — added by three different
 *     users between Oct 2025 and Jul 2026, all Creator-stamped. §13A already
 *     documents Payment Requests approved against a blank vendor; this is where
 *     such a request comes from. Kept.
 *
 *  3. 62 names occur on more than one record (7,985 distinct over 8,063 rows).
 *     `name` has no unique index for exactly this reason.
 *
 *  4. GST numbers are inconsistently cased — `27aahfe2088h1zb` and
 *     `27AAHFE2088H1ZB` are the same registration on different rows — and one
 *     carries a TRAILING SPACE: `Decathlon` holds `27AAACL9861H1Z6 ` in gst_no_2
 *     against `27AAACL9861H1Z6` in gst_no_3. Stored verbatim; a GST checksum
 *     validator belongs on the form, not on import.
 *
 *  5. gst_no_2 and gst_no_3 disagree on 6 rows, and two of those disagree in a way
 *     that looks like a data-entry error rather than a second registration:
 *     `ASHISH AMAZON` and `Vipul Garg` both carry Amazon's `27AAMCA0671Q1Z4` in
 *     #2 against a personal GST in #3. Not reconciled — see the migration on why
 *     the three columns stay positional.
 *
 *  6. PAN is populated on 515 of 8,063 and includes junk (`NA` and similar).
 *     Stored as-is: a PAN-shaped CHECK would reject live rows.
 *
 *  7. Location is blank on 7,057 rows — 87.5%. The vendor location field is
 *     mostly unused, so any report grouping vendors by location covers an eighth
 *     of them.
 *
 * ---------------------------------------------------------------------------
 * `Alleppey` — ONE LOCATION IS CREATED HERE, and why that is not fabrication.
 *
 * The vendor export names 13 locations. Twelve resolve. `Alleppey` does not,
 * because `locations` was derived from `All_Villas.csv` and Ekostay has no villa
 * in Alleppey — but it does have one vendor there. The villa export is a
 * VILLA-scoped view of Creator's Location master, not the master itself, so a
 * missing row there is an incomplete recovery rather than evidence the value is
 * invalid. Creating it recovers a real value; dropping it would lose the vendor's
 * location silently. Exactly one row is created and it is announced on the console.
 *
 * ---------------------------------------------------------------------------
 * `employee_designation` IS TEXT, NOT A FOREIGN KEY. The export yields 25 distinct
 * designations across 287 employee-flagged vendors (`caretaker` x213 dominates),
 * and `employee_designations` is still an empty table. These 25 are a CANDIDATE
 * source for it, not proof of its contents — a vendor-side list need not be the
 * master's full list, and the values are themselves dirty (`Social media `,
 * `OFFICE BOY `, `HELPER` vs `chef`, mixed case throughout). Pointing an FK at a
 * list inferred from one report would assert more than is known.
 *
 * ---------------------------------------------------------------------------
 * TWO PASSES. Pass 1 inserts every vendor. Pass 2 resolves the §13A.1 merge
 * pointer, which cannot run earlier because a pointer may name a vendor that
 * appears later in the file.
 */
class VendorSeeder extends Seeder
{
    use ReadsMasterData;

    /**
     * Column positions in Vendor_Master.csv. Named because three of the headers
     * are the same string and index is the only way to tell them apart.
     */
    private const NAME = 0;

    private const MAIN_PRIMARY = 1;

    private const PRIMARY_VENDOR = 2;

    private const PRIMARY_STATUS = 3;

    private const LOCATION = 4;

    private const MASTER_CATEGORY = 5;

    private const DESIGNATION = 6;

    private const EMPLOYEE = 7;

    private const STATE = 8;

    private const EMAIL = 9;

    private const GST_1 = 10;

    private const PHONE = 11;

    private const ACCOUNT_DETAILS = 12;

    private const ADDED_TIME = 13;

    private const ADDED_USER = 14;

    private const ID = 15;

    private const GST_2 = 16;

    private const GST_3 = 17;

    private const PAN = 18;

    private const MODIFIED_USER = 19;

    private const MODIFIED_TIME = 20;

    private const WIDTH = 21;

    public function run(): void
    {
        /*
         * SKIPPED ON A FRESH CLONE, BY DESIGN. `Vendor_Master.csv` is excluded from
         * the repository because it carries 8,063 real vendors with PANs, GST registrations and bank details, and git history cannot
         * practically be cleaned. So this seeder announces the gap instead of
         * throwing — the other seeders still run and the app still boots.
         */
        if (! $this->masterDataExists('Vendor_Master.csv')) {
            $this->command?->warn('vendors: SKIPPED — master-data/Vendor_Master.csv is not in the repository.');
            $this->command?->line('   It holds 8,063 real vendors with PANs, GST registrations and bank details, so it is git-ignored on purpose.');
            $this->command?->line('   Ask Husain for it over a private channel and drop it in master-data/.');
            $this->command?->line('   The app runs without it; `vendors` stays empty and its pickers are empty with it.');

            return;
        }

        ['header' => $header, 'rows' => $rows] = $this->masterDataCsvPositional('Vendor_Master.csv');

        if (count($header) !== self::WIDTH) {
            throw new RuntimeException(sprintf(
                'Vendor_Master.csv has %d columns, expected %d. The column constants in '
                .'this seeder are POSITIONAL because the header repeats `GST No.` three '
                .'times — a changed export must be re-mapped by hand, not guessed.',
                count($header),
                self::WIDTH,
            ));
        }

        $locations = $this->locationMap($rows);
        $states = DB::table('states')->pluck('id', 'name');
        $categories = DB::table('master_categories')->pluck('id', 'name');

        $unresolved = ['state' => [], 'master_category' => []];

        foreach (array_chunk($rows, 500) as $chunk) {
            $batch = [];

            foreach ($chunk as $row) {
                $state = $this->text($row[self::STATE]);
                $category = $this->text($row[self::MASTER_CATEGORY]);

                if ($state !== null && ! isset($states[$state])) {
                    $unresolved['state'][$state] = true;
                }
                if ($category !== null && ! isset($categories[$category])) {
                    $unresolved['master_category'][$category] = true;
                }

                $batch[] = [
                    'creator_id' => $this->id($row[self::ID]),

                    // NOT trimmed, and not coalesced to a placeholder. 5 rows are
                    // genuinely nameless and stay that way.
                    'name' => (string) ($row[self::NAME] ?? ''),

                    'main_primary' => $this->text($row[self::MAIN_PRIMARY]),
                    'primary_vendor' => $this->text($row[self::PRIMARY_VENDOR]),
                    'primary_vendor_id' => null,        // pass 2
                    'is_primary' => $this->bool($row[self::PRIMARY_STATUS]),

                    'location_id' => $locations[$this->text($row[self::LOCATION]) ?? ''] ?? null,
                    'state_id' => $state !== null ? ($states[$state] ?? null) : null,
                    'master_category_id' => $category !== null ? ($categories[$category] ?? null) : null,

                    // Vendors carry no Item Category on this export, only Master
                    // Category. The column stays, unset — TestBillSeeder fills it.
                    'item_category_id' => null,

                    'employee_designation' => $this->text($row[self::DESIGNATION]),
                    'is_employee' => $this->bool($row[self::EMPLOYEE]),

                    'email' => $this->text($row[self::EMAIL]),
                    'phone' => $this->text($row[self::PHONE]),
                    'account_details' => $this->text($row[self::ACCOUNT_DETAILS]),

                    'gst_no_1' => $this->text($row[self::GST_1]),
                    'gst_no_2' => $this->text($row[self::GST_2]),
                    'gst_no_3' => $this->text($row[self::GST_3]),
                    'pan_no' => $this->text($row[self::PAN]),

                    'added_user' => $this->text($row[self::ADDED_USER]),
                    'added_time' => $this->creatorTime($row[self::ADDED_TIME]),
                    'modified_user' => $this->text($row[self::MODIFIED_USER]),
                    'modified_time' => $this->creatorTime($row[self::MODIFIED_TIME]),

                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            DB::table('vendors')->upsert($batch, ['creator_id']);
        }

        foreach ($unresolved as $field => $values) {
            if ($values !== []) {
                $this->command?->warn(sprintf(
                    'vendor %s values with no master row (left null): %s',
                    $field,
                    implode(', ', array_keys($values)),
                ));
            }
        }

        $this->resolveMergePointers();
    }

    /**
     * Pass 2 — §13A.1's merge pointer, name to id.
     *
     * A pointer that matches no vendor, or matches more than one, is LEFT NULL and
     * counted. The text column keeps the pointer either way, so nothing is lost:
     * a null id beside a non-null `primary_vendor` means "Creator points at this
     * name and the name does not identify one row", which is a fact about the data
     * and not a gap in the import.
     *
     * The match is on the name EXACTLY as stored, untrimmed. One pointer,
     * `ETRADE MARKETING PRIVATE LIMITED`, matches several rows — and the trailing-
     * space variant of that same name is one of the rows it does not match.
     */
    private function resolveMergePointers(): void
    {
        $pointers = DB::table('vendors')
            ->whereNotNull('primary_vendor')
            ->pluck('primary_vendor', 'id');

        // Group ids by name so ambiguity is visible rather than resolved by luck.
        $byName = [];
        foreach (DB::table('vendors')->select('id', 'name')->get() as $vendor) {
            $byName[$vendor->name][] = $vendor->id;
        }

        $resolved = 0;
        $ambiguous = [];
        $missing = [];

        foreach ($pointers as $id => $pointer) {
            $candidates = $byName[$pointer] ?? [];

            if (count($candidates) === 1) {
                DB::table('vendors')->where('id', $id)->update(['primary_vendor_id' => $candidates[0]]);
                $resolved++;
            } elseif ($candidates === []) {
                $missing[$pointer] = true;
            } else {
                $ambiguous[$pointer] = count($candidates);
            }
        }

        $this->command?->info(sprintf(
            'vendors: %d rows, %d merge pointers resolved to an id, %d ambiguous, %d unmatched.',
            DB::table('vendors')->count(),
            $resolved,
            count($ambiguous),
            count($missing),
        ));

        foreach ($ambiguous as $name => $count) {
            $this->command?->warn(sprintf(
                'merge pointer "%s" matches %d vendor rows — id left null, text kept (§13A.1).',
                $name,
                $count,
            ));
        }
    }

    /**
     * Location names, with the one genuinely-missing row created. See the class
     * docblock for why creating it is recovery and not fabrication.
     *
     * @param  list<list<string|null>>  $rows
     * @return array<string, int>
     */
    private function locationMap(array $rows): array
    {
        $known = DB::table('locations')->pluck('id', 'name')->all();

        $needed = [];
        foreach ($rows as $row) {
            $name = $this->text($row[self::LOCATION]);
            if ($name !== null && ! array_key_exists($name, $known)) {
                $needed[$name] = true;
            }
        }

        foreach (array_keys($needed) as $name) {
            $id = DB::table('locations')->insertGetId([
                'name' => $name,
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $known[$name] = $id;

            $this->command?->warn(sprintf(
                'location "%s" created — named by a vendor but absent from All_Villas.csv, '
                .'which is a villa-scoped view of the Location master.',
                $name,
            ));
        }

        return $known;
    }

    /** `22-Aug-2026 18:44:32` — Creator's stamp format. Null on anything else. */
    private function creatorTime(?string $value): ?string
    {
        $value = $this->text($value);

        if ($value === null) {
            return null;
        }

        $parsed = Carbon::createFromFormat('d-M-Y H:i:s', $value);

        return $parsed === false ? null : $parsed->toDateTimeString();
    }
}
