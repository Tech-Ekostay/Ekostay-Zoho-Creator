<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * fb.Auto_Numbers — the singleton, with its REAL live values.
 *
 * There is no Auto_Numbers export for F&B, so the counters are derived from the
 * highest number actually issued in the transactional exports of 31-Aug-2026:
 *
 *     EKO/F&BOrder  max 11,435   over 11,205 orders
 *     EKO/Stock     max  4,527   over  4,328 requests
 *
 * STARTING AT 1 WOULD RE-MINT NUMBERS THAT BELONG TO REAL ORDERS. Accounts'
 * AutoNumberSeeder says the same about the payment counter — it "must be the real
 * 20938 and not a fresh 1" — and that project found the hole when a
 * `migrate:fresh --seed` silently disarmed the guard.
 *
 * The counters are set one PAST the observed maximum, and the observed maximum is
 * recorded separately so `FnbNumber::allocate()` can refuse if the two ever
 * disagree. Booking and Transfer have no export, so they carry no counter at all
 * rather than a guess — allocate() refuses on a null.
 *
 * UPDATE THESE when a fresh Auto Numbers screenshot arrives. Creator keeps minting
 * while this is being built, so these values go stale on their own.
 */
class FnbAutoNumberSeeder extends Seeder
{
    /** Measured from the exports, 31-Aug-2026. */
    private const LIVE_VENDOR_BOOKING_NO = 11435;

    private const LIVE_REQUEST_NO = 4527;

    private const LIVE_OBSERVED_AT = '2026-08-31';

    public function run(): void
    {
        if (DB::table('fnb_auto_numbers')->count() > 0) {
            $this->command?->warn('fnb_auto_numbers: already populated, skipping.');

            return;
        }

        $now = now();

        DB::table('fnb_auto_numbers')->insert([
            'singleton' => true,

            'vendor_booking_series' => 'EKO/F&BOrder',
            'vendor_booking_no' => self::LIVE_VENDOR_BOOKING_NO + 1,

            'request_series' => 'EKO/Stock',
            'request_no' => self::LIVE_REQUEST_NO + 1,

            // No export names a booking or a transfer number, so no counter is
            // guessed. allocate() refuses on a null rather than minting from 1.
            'booking_series' => null,
            'booking_no' => null,
            'transfer_series' => null,
            'transfer_no' => null,

            'live_vendor_booking_no_observed' => self::LIVE_VENDOR_BOOKING_NO,
            'live_request_no_observed' => self::LIVE_REQUEST_NO,
            'live_observed_at' => self::LIVE_OBSERVED_AT,

            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->command?->info(sprintf(
            'fnb_auto_numbers: EKO/F&BOrder next %d, EKO/Stock next %d (observed live %s).',
            self::LIVE_VENDOR_BOOKING_NO + 1,
            self::LIVE_REQUEST_NO + 1,
            self::LIVE_OBSERVED_AT,
        ));
        $this->command?->warn(
            'Booking and Transfer series carry NO counter — no export names one, and a '
            .'guessed counter would collide with live records. FnbNumber::allocate() '
            .'refuses on a null rather than minting from 1.'
        );
    }
}
