<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The rest of `Vendor_Master` — 8,063 real records, export of 22-Aug-2026.
 *
 * CLAUDE.md said "not seeded, no export exists" for vendors. That was wrong: the
 * export existed and is now at `master-data/Vendor_Master.csv`. This migration
 * adds the eleven columns the first pass left off.
 *
 * -------------------------------------------------------------------------
 * §13A.1 IS ANSWERED. The original `create_vendors_table` docblock says:
 *
 *     "§13A.1 leaves it open which of the three vendor-merge fields is
 *      authoritative, so merge state is deliberately not modelled yet."
 *
 * The export settles it, by counting rather than by reading Deluge:
 *
 *   Primary Vendor   is the merge POINTER. Set on 112 rows. All 112 name a
 *                    vendor whose Primary Status is true. Never set together
 *                    with Primary Status — the two are mutually exclusive.
 *   Primary Status   flags the merge TARGET. True on 93 rows. All 93 are
 *                    pointed at; 0 orphan flags, 0 unflagged targets.
 *   Main Primary     is NOT authoritative FOR MERGES. It differs from Vendor Name
 *                    on 739 rows, of which only 108 are merges — the other 631
 *                    have no pointer at all — and it is blank on 1,106 rows.
 *
 *                    But it is not junk either, and this is a second finding:
 *                    blank-versus-set separates CUSTOMER PAYEES from trade
 *                    vendors. 1,097 of the 1,099 vendors named `…(Customer)`
 *                    have it blank; only 9 of the other 6,964 do. That confirms
 *                    and quantifies the `[UI]` note in spec §13A.1. So the field
 *                    is the wrong one for merges and the right one for telling
 *                    the table's two populations apart.
 *
 * One row proves the drift outright: `MOHANRAJ Y (CT)` carries
 * `Primary Vendor = MOHANRAJ V (PM)` while its `Main Primary` still says
 * `MOHANRAJ Y (CT)` — the merge happened and Main Primary never followed. It is
 * the only row where the two disagree, and it disagrees in Main Primary's
 * direction being stale. So: follow `primary_vendor`, display `main_primary`,
 * never resolve a merge through `main_primary`.
 *
 * WHY THE POINTER IS TEXT AND THE FK IS A CONVENIENCE. Creator stores the
 * pointer as a name, and one pointer name — `ETRADE MARKETING PRIVATE LIMITED` —
 * matches more than one vendor row, so a name cannot always resolve to exactly
 * one record. `primary_vendor` (text, as exported) is therefore the authority and
 * `primary_vendor_id` is filled in only where resolution is unambiguous. A null
 * id alongside a non-null text is meaningful, not missing data.
 *
 * -------------------------------------------------------------------------
 * THREE COLUMNS LABELLED `GST No.`. The export header carries that label at
 * positions 11, 17 and 18. They are not interchangeable:
 *
 *   gst_no_1   populated on   7 rows, and identical to gst_no_2 on all 7
 *   gst_no_2   populated on 292 rows
 *   gst_no_3   populated on 290 rows, disagreeing with gst_no_2 on 6
 *
 * If #1 were merely #2 rendered twice it would not be blank on the 285 rows
 * where #2 is set. Which Creator field each column is remains unknown without a
 * form-level export, so all three are stored positionally and verbatim. Naming
 * them by guessed meaning would bake the guess in; naming them by position keeps
 * the ambiguity visible. See the seeder for the six disagreeing rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            // §13A.1's merge pointer — see docblock. Text is authoritative.
            $table->text('primary_vendor')->nullable()->after('main_primary');
            $table->foreignId('primary_vendor_id')->nullable()->after('primary_vendor')
                ->constrained('vendors')->nullOnDelete();

            $table->text('email')->nullable();

            // Positional, not semantic. Do not rename these to guessed meanings.
            $table->text('gst_no_1')->nullable();
            $table->text('gst_no_2')->nullable();
            $table->text('gst_no_3')->nullable();

            // Free text in Creator: account numbers, IFSCs and bank names run
            // together in one field. Parsing it is a separate exercise.
            $table->text('account_details')->nullable();

            // Text, not a foreign key. `employee_designations` is still an empty
            // table with no export of its own; these 25 distinct values come from
            // the vendor side and may not be that master's full list, so pointing
            // a FK at a list inferred from one report would assert more than is
            // known. Recorded in the seeder as a candidate source.
            $table->text('employee_designation')->nullable();
            $table->boolean('is_employee')->default(false);

            // Creator's own audit stamps, kept apart from Laravel's timestamps:
            // these say who touched the record in Creator, which `created_at`
            // cannot, and they survive a re-seed.
            $table->text('added_user')->nullable();
            $table->timestampTz('added_time')->nullable();
            $table->text('modified_user')->nullable();
            $table->timestampTz('modified_time')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->dropConstrainedForeignId('primary_vendor_id');
            $table->dropColumn([
                'primary_vendor', 'email', 'gst_no_1', 'gst_no_2', 'gst_no_3',
                'account_details', 'employee_designation', 'is_employee',
                'added_user', 'added_time', 'modified_user', 'modified_time',
            ]);
        });
    }
};
