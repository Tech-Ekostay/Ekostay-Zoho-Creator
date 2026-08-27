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
            ]
        );
    }
}
