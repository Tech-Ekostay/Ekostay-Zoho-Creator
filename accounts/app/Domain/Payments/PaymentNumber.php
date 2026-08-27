<?php

declare(strict_types=1);

namespace App\Domain\Payments;

use App\Models\AutoNumber;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Payment number allocation — §7.6, §15, and the answer to a question the docs
 * park as open.
 *
 * WHAT CREATOR DOES. Accounts.ds:45400:
 *
 *     Series = ifnull(fetAuto.Payment_No,1);
 *     if(Series < 10)        { Series = "000" + Series; }
 *     else if(Series < 100)  { Series = "00"  + Series; }
 *     if(Series < 1000)      { Series = "0"   + Series; }      // <-- not else-if
 *     BkngNo = fetAuto.Payment_Series + "/" + Series;
 *
 * The third `if` is unchained, so for 1..99 it fires on top of a branch that has
 * already padded — five characters where four were meant.
 *
 * WHY IT IS MOOT. Auto_Numbers.Payment No is 20938 in the 12-Aug-2026 export.
 * Every branch tests below 1000, so none of them has fired for roughly twenty
 * thousand payments. The live format is a bare counter: "EKS/PY/20938".
 *
 * SO THE ANSWER TO "fix or preserve" IS NEITHER — there is nothing left to fix.
 * This class pads nothing. What it does instead is the part that actually matters:
 * allocate under a row lock so two concurrent Create_Payment calls cannot take the
 * same number. Creator's read is `Auto_Numbers[ID != null]` with a non-atomic
 * increment, which is a genuine race — and §7.6's argument is precisely that
 * anything keyed on payment number drifts when numbers collide.
 *
 * Historical rows numbered 1..999 do carry inconsistent widths. Sorting payment
 * numbers as STRINGS mis-orders them; sort on the counter, or zero-extend at the
 * edge for display. Recorded as deviation D3 in ACCOUNTS_CONTEXT_ADDENDUM.md.
 */
final class PaymentNumber
{
    /**
     * Take the next payment number, atomically.
     *
     * MUST run inside a transaction — `lockForUpdate` outside one is a no-op that
     * silently reintroduces the race, so this refuses rather than pretending.
     */
    public static function allocate(): string
    {
        if (DB::transactionLevel() === 0) {
            throw new RuntimeException(
                'PaymentNumber::allocate() must run inside a transaction: the row lock '
                .'that makes allocation atomic has no effect outside one.'
            );
        }

        $auto = AutoNumber::query()->lockForUpdate()->first();

        if ($auto === null) {
            throw new RuntimeException(
                'Auto_Numbers holds no row. Payment numbering cannot proceed without the '
                .'counter — seed it from master-data/Auto_Numbers.json.'
            );
        }

        $number = (int) $auto->payment_no;

        // Increment through the locked row, not through a re-read.
        $auto->payment_no = $number + 1;
        $auto->save();

        return self::format($auto->payment_series, $number);
    }

    /**
     * Join series and counter. No padding — see the docblock.
     *
     * A null or blank series yields the bare counter rather than a leading slash,
     * because "/20938" is worse than "20938" if the singleton is ever incomplete.
     */
    public static function format(?string $series, int $number): string
    {
        $series = trim((string) $series);

        return $series === '' ? (string) $number : $series.'/'.$number;
    }

    /**
     * Creator's padding, reproduced for comparison only.
     *
     * NOT USED. Present so a test can demonstrate the unchained-if defect on the
     * 1..999 range that historical data actually occupies, and so anyone matching
     * old Creator numbers can reproduce what it would have produced.
     */
    public static function creatorFormat(?string $series, int $number): string
    {
        $padded = (string) $number;

        if ($number < 10) {
            $padded = '000'.$padded;
        } elseif ($number < 100) {
            $padded = '00'.$padded;
        }

        // The unchained third `if`, verbatim. It tests the ORIGINAL number, which is
        // how Deluge's loose string/number comparison behaves here.
        if ($number < 1000) {
            $padded = '0'.$padded;
        }

        $series = trim((string) $series);

        return $series === '' ? $padded : $series.'/'.$padded;
    }
}
