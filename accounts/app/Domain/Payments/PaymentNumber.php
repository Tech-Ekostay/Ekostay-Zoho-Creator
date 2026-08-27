<?php

declare(strict_types=1);

namespace App\Domain\Payments;

use App\Models\AutoNumber;
use App\Models\Payment;
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

        /*
         * THE STALENESS GUARD. See the class docblock and the migration of
         * 27-Aug-2026: our counter is 312 behind live, and issuing from it would
         * re-mint numbers Creator has already given to real payments. This refuses
         * while that is true, because saying so in a comment did not stop it before.
         */
        $observed = $auto->live_payment_no_observed;

        if ($observed !== null && $number <= (int) $observed) {
            throw new RuntimeException(sprintf(
                'Refusing to allocate %s: the live Auto_Numbers counter was read as %d on %s '
                .'and ours stands at %d, so this number is %d behind and belongs to a payment '
                .'Creator has already issued. Minting EKS/PY/21305 over a live '
                .'₹1,00,000 payment is what this guard exists to prevent. Creator owns the '
                .'series until cutover — see addendum §6.6 for the two safe designs.',
                self::format($auto->payment_series, $number),
                (int) $observed,
                $auto->live_observed_at?->toDateString() ?? 'an unrecorded date',
                $number,
                (int) $observed - $number + 1,
            ));
        }

        /*
         * THE CLASH GUARD, which Creator has and this did not — `Accounts.ds:20517`
         * checks `Payment[Payment_No == BkngNo]` and steps once past a taken number,
         * adding "Payment_No was already taken - advanced to ...".
         *
         * Reproduced, and then improved on one point: Creator steps exactly ONCE, so
         * two consecutive taken numbers still collide. This walks until the number is
         * free. Deviation D9 — it cannot issue a number Creator would have issued,
         * only skip further than Creator would skip, so it never widens the range.
         *
         * The bound is a backstop, not a policy: a counter far behind live produces
         * hundreds of consecutive hits, and silently walking through them would hide
         * exactly the drift the guard above exists to surface.
         */
        $skipped = 0;

        while (Payment::withTrashed()
            ->where('payment_no', self::format($auto->payment_series, $number))
            ->exists()
        ) {
            if (++$skipped > self::MAX_CLASH_SKIP) {
                throw new RuntimeException(sprintf(
                    'Refusing to allocate: %d consecutive payment numbers from %s are already '
                    .'taken. That is a stale counter rather than a collision, and walking '
                    .'further would hide it. Reconcile against live before issuing.',
                    $skipped,
                    self::format($auto->payment_series, (int) $auto->payment_no),
                ));
            }

            $number++;
        }

        // Increment through the locked row, not through a re-read.
        $auto->payment_no = $number + 1;
        $auto->save();

        return self::format($auto->payment_series, $number);
    }

    /**
     * How far the clash guard will walk before treating the miss as staleness.
     *
     * Small on purpose. A real collision is one or two numbers — 239 duplicate
     * payment numbers exist in live data, but they cluster, they do not run.
     */
    public const MAX_CLASH_SKIP = 25;

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
