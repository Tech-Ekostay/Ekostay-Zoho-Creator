<?php

namespace Database\Seeders;

use App\Models\AutoNumber;
use Database\Seeders\Concerns\ReadsMasterData;
use Illuminate\Database\Seeder;

/**
 * `Auto_Numbers` — one real row, from master-data/Auto_Numbers.json (12-Aug-2026).
 *
 * The counters MUST come from the live export rather than starting at 1. Payment
 * No is at 20938; seeding a fresh counter would re-issue numbers that already
 * exist in twenty thousand live records, and §7.6's entire argument is that
 * anything keyed on payment number drifts when numbers collide.
 *
 * Counters are read through (int) deliberately, NOT through the id() reader that
 * refuses numbers. These are quantities, not record ids — 20938 is meant to be
 * arithmetic. The id() guard applies to the 18-digit `ID`, which goes to
 * creator_id as a string.
 */
class AutoNumberSeeder extends Seeder
{
    use ReadsMasterData;

    public function run(): void
    {
        $rows = $this->masterData('Auto_Numbers.json');
        $row = $rows[0] ?? null;

        if ($row === null) {
            return;
        }

        // updateOrCreate on the singleton flag: re-running the seeder must not
        // attempt a second row, which the unique index would reject anyway.
        AutoNumber::updateOrCreate(
            ['singleton' => true],
            [
                'creator_id' => $this->id($row['ID'] ?? null),
                'payment_series' => $this->text($row['Payment Series'] ?? null),
                'payment_no' => (int) ($row['Payment No'] ?? 1),
                'books_payment_series' => $this->text($row['Books Payment Series'] ?? null),
                'books_payment_no' => (int) ($row['Books Payment No'] ?? 1),
                'haewaya_series' => $this->text($row['Haewaya Series'] ?? null),
                'haewaya_no' => (int) ($row['Haewaya No'] ?? 1),

                /*
                 * THE WATERMARK MUST BE RE-SEEDED, NOT JUST MIGRATED.
                 *
                 * The migration of 27-Aug-2026 wrote these as data, and a
                 * `migrate:fresh --seed` then reran this seeder and left them null —
                 * silently disarming the staleness guard in
                 * `PaymentNumber::allocate()`. Caught by
                 * PaymentNumberGuardTest::the_live_reading_of_27_aug_2026_is_recorded_on_the_seeded_row,
                 * which was written precisely because a guard that can go quiet is
                 * worse than no guard.
                 *
                 * This export is 12-Aug-2026 (`Payment No` 20938) and the live
                 * screenshot is 27-Aug-2026 (21621), so the master data CANNOT supply
                 * these. They are a dated observation of a system we do not control
                 * and they belong beside the counter they guard.
                 *
                 * **Update both when a fresh reading of Auto Numbers arrives.** They
                 * are the only thing standing between this app and re-issuing a
                 * payment number that already belongs to real money.
                 */
                'live_payment_no_observed' => self::LIVE_PAYMENT_NO,
                'live_haewaya_no_observed' => self::LIVE_HAEWAYA_NO,
                'live_observed_at' => self::LIVE_OBSERVED_AT,
            ]
        );
    }

    /** Auto Numbers, read from the live app 27-Aug-2026. */
    public const LIVE_PAYMENT_NO = 21621;

    public const LIVE_HAEWAYA_NO = 33507;

    public const LIVE_OBSERVED_AT = '2026-08-27 20:50:00+05:30';
}
