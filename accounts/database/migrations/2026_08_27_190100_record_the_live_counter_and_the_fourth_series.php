<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Auto Numbers, from the screenshot of 27-Aug-2026 — and the drift made enforceable.
 *
 * Husain confirmed the origin question outright: **the EKS/PY series comes from
 * Auto_Numbers.** So Creator's singleton is the one allocator, and the screenshot
 * gives its live contents:
 *
 *     Payment Series        EKS/PY          Payment No        21621
 *     Books Payment Series  EKS/BPY         Books Payment No      1
 *     Haewaya Series        EKS/Haewaya     Haewaya No        33507
 *     ID                    292482000000132217
 *
 * Our `creator_id` matches that ID exactly, so it is the same record. The counters
 * are not:
 *
 *     payment_no        21309  against live 21621   ->  312 behind
 *     haewaya_no        33294  against live 33507   ->  213 behind
 *     books_payment_no      1  against live     1   ->  in step
 *
 * ---------------------------------------------------------------------------
 * WHY THIS IS A MIGRATION AND NOT A NOTE. `payment_no` has already minted
 * `EKS/PY/21305` over a live ₹1,00,000 payment once. That was diagnosed as an
 * off-by-one and fixed to `max + 1`, which was correct and insufficient — `max + 1`
 * of a two-day-old Analytics export is still two days stale, and the drift measured
 * here is ~150 numbers a day.
 *
 * Documenting "nothing may allocate from payment_no" did not stop it the first time.
 * So `live_payment_no_observed` records what live actually held and when, and
 * `PaymentNumber::allocate()` REFUSES while our counter sits at or below it. The
 * staleness becomes a hard block with a date on it rather than a paragraph.
 *
 * Null means no observation, and then the guard is inert — a fresh install is not
 * punished for a reading nobody has taken.
 *
 * ---------------------------------------------------------------------------
 * A FOURTH SERIES THE REPORT DOES NOT SHOW. The `Auto_Numbers` FORM declares four
 * pairs (Accounts.ds:234-92):
 *
 *     Payment_Series / Payment_No
 *     Haewaya_Series / Haewaya_No
 *     Books_Payment_Series / Books_Payment_No
 *     External_Payment_Series / External_Payment_No      <-- not a report column
 *
 * The All Auto Numbers report carries the first three. The fourth is invisible on
 * screen and **actively allocated from** — `Accounts.ds:20502` reads
 * `prefix = ifnull(autoRec.External_Payment_Series,"EXT")` and mints against
 * `External_Payment_No`. §2's rule again: an export, and here a report, mirrors its
 * own columns and not the form. Added so the fourth allocator is at least modelled;
 * its live values are still unknown because no screen shows them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('auto_numbers', function (Blueprint $table) {
            // The fourth series. `EXT` is Creator's fallback prefix, not a default
            // we chose — left null here so an unset series stays visibly unset.
            $table->text('external_payment_series')->nullable();
            $table->integer('external_payment_no')->nullable();

            /*
             * What live held, and when it was read. Not a counter — a watermark.
             * `allocate()` refuses while `payment_no` is at or below it.
             */
            $table->integer('live_payment_no_observed')->nullable();
            $table->integer('live_haewaya_no_observed')->nullable();
            $table->timestampTz('live_observed_at')->nullable();
        });

        /*
         * The 27-Aug-2026 reading. Written as data rather than left to a seeder
         * because the whole point is that it is a dated observation of a system we
         * do not control.
         *
         * The counters themselves are deliberately NOT advanced to match. Advancing
         * them would make our allocator look safe while Creator carries on issuing
         * from the same series — two allocators, one range. Which of the two owns
         * EKS/PY until cutover is still Husain's decision (addendum §6.6), so this
         * records the gap and blocks allocation rather than papering over it.
         */
        DB::table('auto_numbers')->where('singleton', true)->update([
            'live_payment_no_observed' => 21621,
            'live_haewaya_no_observed' => 33507,
            'live_observed_at' => '2026-08-27 20:50:00+05:30',
        ]);
    }

    public function down(): void
    {
        Schema::table('auto_numbers', function (Blueprint $table) {
            $table->dropColumn([
                'external_payment_series', 'external_payment_no',
                'live_payment_no_observed', 'live_haewaya_no_observed', 'live_observed_at',
            ]);
        });
    }
};
