<?php

namespace App\Domain\Fnb;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * F&B order and request numbers, allocated under a row lock.
 *
 * Creator's version (`F_B.ds:6710`) does this:
 *
 *     fetAuto = Auto_Numbers[ID != null];          // read
 *     Series  = ifnull(fetAuto.Vendor_Booking_No, 1);
 *     ReqNo   = fetAuto.Vendor_Booking_Series + "/" + Series;
 *     fetAuto.Vendor_Booking_No = ... + 1;         // write, later, unlocked
 *
 * Two concurrent orders read the same value and mint the same number. Accounts
 * logged the identical defect as D3 and fixed it with `lockForUpdate()`; this is
 * the same fix for F&B's four series.
 *
 * THE PADDING IS NOT REPRODUCED. Creator pads below 1000 with a chain that is
 * itself wrong — `if (<10) … else if (<100) …` then a BARE `if (<1000)`, so a
 * two-digit number is padded twice. It has never fired: 11,205 live orders are all
 * four or five digits. Accounts reached the same conclusion about its own padding
 * (§7.6, counter at 20938). A branch that cannot fire is not behaviour; copying a
 * bug that would corrupt a future low-numbered series is not fidelity.
 *
 * THE GUARD. Creator is still minting numbers while this is being built, so our
 * counter can only be behind. Allocating while behind mints a number that already
 * belongs to a real order — so `allocate()` REFUSES rather than colliding. Accounts
 * found this hole the hard way: a `migrate:fresh --seed` without the observed
 * values silently disarmed it.
 */
class FnbNumber
{
    /** series column => counter column, and the label used in errors. */
    private const SERIES = [
        'booking' => ['booking_series', 'booking_no', null],
        'request' => ['request_series', 'request_no', 'live_request_no_observed'],
        'vendor_booking' => ['vendor_booking_series', 'vendor_booking_no', 'live_vendor_booking_no_observed'],
        'transfer' => ['transfer_series', 'transfer_no', null],
    ];

    /**
     * Take the next number off a series. Returns e.g. `EKO/F&BOrder/11436`.
     *
     * Runs inside a transaction with the singleton row locked, so two callers
     * cannot read the same counter.
     */
    public static function allocate(string $series): string
    {
        if (! isset(self::SERIES[$series])) {
            throw new RuntimeException(
                "unknown F&B series '{$series}'. Known: ".implode(', ', array_keys(self::SERIES))
            );
        }

        [$seriesCol, $noCol, $observedCol] = self::SERIES[$series];

        return DB::transaction(function () use ($series, $seriesCol, $noCol, $observedCol) {
            $row = DB::table('fnb_auto_numbers')->lockForUpdate()->first();

            if ($row === null) {
                throw new RuntimeException(
                    'fnb_auto_numbers is empty. Run FnbAutoNumberSeeder — the counters '
                    .'must carry their real live values, not start at 1.'
                );
            }

            $prefix = $row->{$seriesCol};
            if ($prefix === null || $prefix === '') {
                throw new RuntimeException("F&B series '{$series}' has no prefix configured.");
            }

            $next = (int) ($row->{$noCol} ?? 0);
            if ($next < 1) {
                throw new RuntimeException(
                    "F&B counter '{$noCol}' is {$next}. Allocating would mint number 0 or lower."
                );
            }

            // The guard. Creator keeps minting while we build; if our counter is at
            // or below the last number observed live, that number is already taken.
            if ($observedCol !== null && $row->{$observedCol} !== null
                && $next <= (int) $row->{$observedCol}) {
                throw new RuntimeException(sprintf(
                    "REFUSING to allocate %s: our counter is at %d but %d was already "
                    ."observed live in Creator. Those %d numbers belong to real records. "
                    .'Update %s from a fresh Auto Numbers reading first.',
                    $series, $next, $row->{$observedCol},
                    (int) $row->{$observedCol} - $next + 1, $observedCol
                ));
            }

            DB::table('fnb_auto_numbers')->where('id', $row->id)->update([
                $noCol => $next + 1,
                'updated_at' => now(),
            ]);

            // No padding. See the class docblock.
            return $prefix.'/'.$next;
        });
    }

    /** What allocate() would return, without taking it. For display and tests. */
    public static function peek(string $series): ?string
    {
        [$seriesCol, $noCol] = self::SERIES[$series] ?? [null, null];
        if ($seriesCol === null) {
            return null;
        }

        $row = DB::table('fnb_auto_numbers')->first();
        if ($row === null || $row->{$seriesCol} === null || $row->{$noCol} === null) {
            return null;
        }

        return $row->{$seriesCol}.'/'.$row->{$noCol};
    }
}
