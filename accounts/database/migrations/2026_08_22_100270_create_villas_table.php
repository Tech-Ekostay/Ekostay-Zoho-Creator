<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * admin.Villa — §3.1, the central entity.
 *
 * RENT TYPE HAS FOUR VALUES, NOT TWO. Every handover document describes two, and
 * Accounts branches only on "Lease" and "Revenue Share" — the two EKOSTAY split
 * types fall through unhandled. That is a live correctness bug, not a modelling
 * choice. The CHECK below admits all four precisely so nothing can silently
 * narrow the domain again, and §17 step 2 requires a fixture per value asserting
 * no branch drops one.
 *
 * `category` holds {Gold, Luxury, Original}. NOTE: §3.1 and handoff §2 rule 7 both
 * record the value as the misspelling `Luxery`; the live data spells it `Luxury`
 * correctly. That entry on the preserve-spellings list is stale.
 *
 * `bhk` is TEXT, not a number: real values include `5BHK` and `6.5BHK`.
 *
 * THREE OVERLAPPING ACTIVE FLAGS — active, status and hide_from_payments. All
 * three are kept because collapsing them would destroy information while a
 * [TODO] is open. Bills and Payments filter on hide_from_payments == false, so
 * that is the load-bearing one.
 *
 * Deliberately NOT modelled yet:
 *  - The two category-scoping mechanisms (A and B in §3.1) plus F&B's third. §3.1
 *    says which is live is unknown and "do not implement all of them".
 *  - Owner-split storage for the two EKOSTAY rent types — OnInputRentTypeCE shows
 *    the split fields only when rent_type == "Revenue Share" exactly, so where
 *    those splits live is an open [TODO].
 *  - The Villa_Managers and Owner_Details grids, which are child tables and are
 *    not in the §17 step 2 list.
 *
 * "Central" villas (Ooty Central, Karjat Central, Panchgani Central, Lonavla
 * Central, Head Office Central) are real payment targets carrying STAFF FUEL and
 * STAFF RENT & ACCOMODATION — location-level cost centres held as villa values.
 * They must not be filtered out as junk.
 */
return new class extends Migration
{
    /** The full rent_type domain. Narrowing this is the bug §3.1 describes. */
    public const RENT_TYPES = [
        'Revenue Split EKOSTAY',
        'Expense Split EKOSTAY',
        'Revenue Share',
        'Lease',
    ];

    public function up(): void
    {
        Schema::create('villas', function (Blueprint $table) {
            $table->id();
            $table->string('creator_id', 20)->nullable()->unique();

            // Identity
            $table->text('name');                         // significant whitespace — do not trim
            $table->foreignId('location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->foreignId('state_id')->nullable()->constrained('states')->nullOnDelete();
            $table->foreignId('head_office_id')->nullable()->constrained('head_offices')->nullOnDelete();
            $table->string('ekostay_id', 24)->nullable();   // clean single value, max 3 chars

            /**
             * NOT an id — a COMMA-PACKED LIST. Populated on 177 of 254 villas, and
             * 95 of those hold several ids: `Lonavla Central` carries
             * `4847,1065,1033,961,962,8400,960` — seven Haewaya properties mapped
             * to one villa. Longest seen is 31 chars, so varchar(24) truncates and
             * the seeder fails outright (which is how this was found).
             *
             * Stored verbatim as text, per the standing rule: preserve the packed
             * string, unpack deliberately with a mapping table, never normalise on
             * import. This is the same shape as `Villa Name` on All_Approvals and
             * `Haewaya UTR Number` on Payments (addendum §7), which packs two UTRs.
             *
             * Note this also qualifies addendum §1: the Haewaya sync key is empty
             * on all 135 item categories and all 10 master categories, but on
             * villas it is populated AND multi-valued.
             */
            $table->text('haewaya_id')->nullable();

            // Physical
            $table->unsignedInteger('max_occupancy')->nullable();
            $table->text('bhk')->nullable();              // TEXT: '5BHK', '6.5BHK'
            $table->text('bathroom')->nullable();
            $table->string("category", 32)->nullable();   // {Gold, Luxury, Original}

            // People (scalar fields only; the two grids are child tables, later)
            $table->text('caretaker_name')->nullable();
            $table->text('caretaker_number')->nullable();
            $table->foreignId('manager_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->text('manager_number')->nullable();
            $table->text('owner_name')->nullable();
            $table->text('owner_number')->nullable();

            // Commercial
            $table->string('rent_type', 48)->nullable();
            $table->decimal('expense_base_amount', 14, 2)->nullable();
            $table->decimal('gst_percentage', 8, 3)->nullable();
            $table->decimal('revenue_split_for_owner', 8, 3)->nullable();
            $table->decimal('expenses_split_for_owner', 8, 3)->nullable();
            $table->decimal('fb_revenue_split_for_owner', 8, 3)->nullable();
            $table->decimal('fb_expenses_split_for_owner', 8, 3)->nullable();

            // Hierarchy — semantics undocumented (§3.1 [TODO])
            $table->foreignId('primary_villa_id')->nullable()->constrained('villas')->nullOnDelete();

            // Flags — three overlapping, all retained. See docblock.
            $table->boolean('active')->default(true);
            $table->string('status', 32)->nullable();     // {Active, In Active} — sic, spaced
            $table->boolean('hide_from_payments')->default(false); // load-bearing filter
            $table->boolean('is_primary')->default(false);
            $table->boolean('inner_circle')->default(false);

            $table->date('date_field')->nullable();
            $table->timestamps();

            $table->index('rent_type');
            $table->index('hide_from_payments');
        });

        $values = implode(', ', array_map(
            static fn (string $v): string => "'".str_replace("'", "''", $v)."'",
            self::RENT_TYPES
        ));

        DB::statement(
            "ALTER TABLE villas ADD CONSTRAINT villas_rent_type_check
             CHECK (rent_type IS NULL OR rent_type IN ({$values}))"
        );

        // Secondary_Villa is a list on the source form.
        Schema::create('villa_secondary_villa', function (Blueprint $table) {
            $table->id();
            $table->foreignId('villa_id')->constrained()->cascadeOnDelete();
            $table->foreignId('secondary_villa_id')->constrained('villas')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['villa_id', 'secondary_villa_id'], 'villa_secondary_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('villa_secondary_villa');
        Schema::dropIfExists('villas');
    }
};
