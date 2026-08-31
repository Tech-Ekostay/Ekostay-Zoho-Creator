<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * fb.Auto_Numbers — F&B's own counters. F_B.ds:15, four series.
 *
 * SEPARATE FROM `auto_numbers`, WHICH IS ACCOUNTS'. That table holds
 * payment_series, haewaya_series, books_payment_series and external_payment_series
 * plus the live-counter guard. F&B keeps a second singleton with four different
 * series, and Creator keeps them apart too:
 *
 *     Booking_Series          Booking_No           fb.Booking
 *     Request_Series          Request_No           fb.Request_Stock_for_Food
 *     Vendor_Booking_Series   Vendor_Booking_No    fb.Vendor_Order_Booking
 *     Transfer_Series         Transfer_No          fb.Transfer_Items
 *
 * Both READMEs list three and miss `Transfer_Series` (findings §4.4).
 *
 * THE SAME TWO DEFECTS ACCOUNTS HAD, MEASURED HERE TOO.
 *
 * 1. NON-ATOMIC INCREMENT. `F_B.ds:6710` reads the singleton, formats a number,
 *    then writes `No = No + 1` — with no lock between. Two concurrent orders take
 *    the same number. Accounts logged this as D3 and fixed it with a row lock;
 *    `FnbAutoNumber::allocate()` does the same, and the read-modify-write happens
 *    inside `lockForUpdate()`.
 *
 * 2. THE PADDING IS DEAD CODE, AND WRONGLY WRITTEN. F_B.ds:6713-6725 pads with
 *    `if (< 10) … else if (< 100) …` and then a BARE `if (< 1000)`, not an
 *    `else if`. So a 2-digit number gets "00" and then "0" again — three zeros for
 *    two digits. It has never fired: the live census over 11,205 orders is 9,276
 *    four-digit and 1,466 five-digit numbers, nothing shorter. Same shape as
 *    Accounts §7.6, where every pad branch tests below 1000 and the counter sits
 *    at 20938. NOT reproduced — a bug that cannot fire is not behaviour to copy,
 *    and reproducing it would corrupt any future low-numbered series.
 *
 * THE COUNTER MUST CARRY ITS REAL VALUE. `AutoNumberSeeder` for Accounts insists
 * the payment counter be the real 20938 rather than a fresh 1, and the same holds
 * here: the live maximum `EKO/F&BOrder` number is 11,436 as of 31-Aug-2026.
 * Starting at 1 would re-mint numbers that already belong to real orders.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fnb_auto_numbers', function (Blueprint $table) {
            $table->id();
            $table->string('creator_id', 32)->nullable()->unique();

            // One row, like Accounts' auto_numbers. The unique index makes a second
            // row impossible rather than merely unlikely.
            $table->boolean('singleton')->default(true);

            $table->string('booking_series')->nullable();
            $table->unsignedInteger('booking_no')->nullable();

            $table->string('request_series')->nullable();
            $table->unsignedInteger('request_no')->nullable();

            $table->string('vendor_booking_series')->nullable();
            $table->unsignedInteger('vendor_booking_no')->nullable();

            $table->string('transfer_series')->nullable();
            $table->unsignedInteger('transfer_no')->nullable();

            /*
             * The live-counter guard, copied from Accounts' pattern. Creator is
             * still minting numbers while we build, so our counter can only be
             * BEHIND. Allocating while behind would mint a number that already
             * belongs to a real order.
             *
             * Update these when a fresh Auto Numbers screenshot or export arrives.
             * A `migrate:fresh --seed` without them silently disarms the guard —
             * which is exactly how Accounts found its own hole.
             */
            $table->unsignedInteger('live_vendor_booking_no_observed')->nullable();
            $table->unsignedInteger('live_request_no_observed')->nullable();
            $table->timestamp('live_observed_at')->nullable();

            $table->timestamps();

            $table->unique('singleton');
        });

        // Every counter is a positive integer or absent. A zero would allocate
        // EKO/F&BOrder/0 and look plausible.
        foreach (['booking_no', 'request_no', 'vendor_booking_no', 'transfer_no'] as $col) {
            DB::statement(
                "ALTER TABLE fnb_auto_numbers ADD CONSTRAINT fnb_auto_{$col}_positive "
                ."CHECK ({$col} IS NULL OR {$col} > 0)"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fnb_auto_numbers');
    }
};
