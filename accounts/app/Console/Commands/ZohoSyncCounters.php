<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AutoNumber;
use App\Services\Zoho\AnalyticsClient;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Sync the payment counters from live, and — separately — take the series over.
 *
 * TWO OPERATIONS, DELIBERATELY NOT ONE.
 *
 *   `zoho:sync-counters`
 *       Reads live `Auto Numbers` and records what it said, as a watermark. The guard
 *       in `PaymentNumber::allocate()` stays ARMED, so this app still cannot mint a
 *       number. Safe to run any time, and should run often.
 *
 *   `zoho:sync-counters --cutover --payment-no=N --haewaya-no=M`
 *       THE TAKEOVER. Sets our counters and clears the watermark, after which this app
 *       allocates and Creator must not. Requires the numbers to be typed in.
 *
 * ---------------------------------------------------------------------------
 * WHY CUTOVER WILL NOT READ THE NUMBER FROM ANALYTICS.
 *
 * **Analytics LAGS Creator.** Both source documents say so independently, and it is the
 * first line of §9. This export says `Payment No` 21690 with `Modified Time`
 * 28-Aug-2026 05:01:50 — a reading of Creator as it was at some earlier moment, not as
 * it is. Creator may have minted a hundred numbers since.
 *
 * So an automated cutover that trusted this figure would set our counter BELOW live's
 * true position and mint over real payments. That is exactly the failure that produced
 * `EKS/PY/21305` over a ₹1,00,000 payment, and it is why the watermark exists.
 *
 * At cutover the authority is **the Auto Numbers screen in Creator, read after Creator
 * writes have stopped.** This command therefore makes the operator type the number, and
 * cross-checks it against Analytics: a value BELOW the Analytics reading means a stale
 * screen and is refused. A value above is accepted, because Analytics can only lag.
 *
 * The residual risk is stated rather than engineered away: nothing here can prove
 * Creator has stopped. That is a human step, and the command says so before it acts.
 */
class ZohoSyncCounters extends Command
{
    protected $signature = 'zoho:sync-counters
                            {--file= : read a saved auto-numbers NDJSON instead of exporting}
                            {--cutover : TAKE THE SERIES OVER. Requires --payment-no and --haewaya-no.}
                            {--payment-no= : the EKS/PY number read from the Creator screen}
                            {--haewaya-no= : the EKS/Haewaya number read from the Creator screen}';

    protected $description = 'Record what live Auto Numbers says, or take the series over at cutover.';

    public function handle(AnalyticsClient $client): int
    {
        $live = $this->option('file')
            ? $this->fromFile((string) $this->option('file'))
            : $this->fromZoho($client);

        if ($live === null) {
            return self::FAILURE;
        }

        $auto = AutoNumber::query()->first();

        if ($auto === null) {
            $this->error('auto_numbers holds no row. Seed it first: db:seed --class=AutoNumberSeeder');

            return self::FAILURE;
        }

        $this->table(['', 'ours', 'live (Analytics)', 'drift'], [
            ['EKS/PY', $auto->payment_no, $live['payment_no'], $live['payment_no'] - (int) $auto->payment_no],
            ['EKS/Haewaya', $auto->haewaya_no, $live['haewaya_no'], $live['haewaya_no'] - (int) $auto->haewaya_no],
            ['EKS/BPY', $auto->books_payment_no, $live['books_payment_no'], $live['books_payment_no'] - (int) $auto->books_payment_no],
        ]);

        $this->line(sprintf(
            '  live Auto Numbers last modified %s by %s',
            $live['modified_time'] ?: 'unknown',
            $live['modified_user'] ?: 'unknown',
        ));

        if ($live['creator_id'] !== '' && $auto->creator_id !== null
            && $live['creator_id'] !== $auto->creator_id) {
            $this->error(sprintf(
                'Record id mismatch: ours %s, live %s. That is not the same singleton, so '
                .'nothing here is comparable. Refusing.',
                $auto->creator_id, $live['creator_id'],
            ));

            return self::FAILURE;
        }

        return $this->option('cutover')
            ? $this->cutover($auto, $live)
            : $this->watermark($auto, $live);
    }

    /** The safe path: record what live said and leave the guard armed. */
    private function watermark(AutoNumber $auto, array $live): int
    {
        $auto->live_payment_no_observed = $live['payment_no'];
        $auto->live_haewaya_no_observed = $live['haewaya_no'];
        $auto->live_observed_at = now();
        $auto->save();

        $this->line('');
        $this->info('Watermark recorded. The allocation guard stays ARMED.');
        $this->line('  PaymentNumber::allocate() will refuse while our counter sits at or below');
        $this->line('  the live reading, which is the point: Creator is still minting.');
        $this->line('');
        $this->line('  Analytics LAGS Creator, so treat the live figures as a FLOOR.');
        $this->line('  To take the series over, stop Creator writes, read the Auto Numbers');
        $this->line('  screen, then:');
        $this->line(sprintf(
            '    php artisan zoho:sync-counters --cutover --payment-no=%d --haewaya-no=%d',
            $live['payment_no'], $live['haewaya_no'],
        ));

        return self::SUCCESS;
    }

    /** The takeover. Typed numbers, cross-checked, and it says what it cannot verify. */
    private function cutover(AutoNumber $auto, array $live): int
    {
        $paymentNo = $this->option('payment-no');
        $haewayaNo = $this->option('haewaya-no');

        if ($paymentNo === null || $haewayaNo === null) {
            $this->error(
                '--cutover needs --payment-no and --haewaya-no, read from the Auto Numbers '
                .'screen in Creator AFTER Creator writes have stopped. They are not read from '
                .'Analytics, because Analytics lags and a low value mints over real payments.'
            );

            return self::FAILURE;
        }

        $paymentNo = (int) $paymentNo;
        $haewayaNo = (int) $haewayaNo;

        // Analytics can only lag, so a typed value BELOW it means a stale screen.
        foreach ([
            ['EKS/PY', $paymentNo, $live['payment_no']],
            ['EKS/Haewaya', $haewayaNo, $live['haewaya_no']],
        ] as [$series, $typed, $seen]) {
            if ($typed < $seen) {
                $this->error(sprintf(
                    '%s: you passed %d but Analytics already saw %d. Analytics only ever lags '
                    .'Creator, so a lower number means the screen was stale or a digit was '
                    .'mistyped. Refusing — this is the check that stops a counter being set '
                    .'below live.',
                    $series, $typed, $seen,
                ));

                return self::FAILURE;
            }
        }

        $this->line('');
        $this->warn('CUTOVER. After this, THIS APP owns the series and Creator must not mint.');
        $this->line('');
        $this->line(sprintf('  EKS/PY       %d  ->  %d', $auto->payment_no, $paymentNo));
        $this->line(sprintf('  EKS/Haewaya  %d  ->  %d', $auto->haewaya_no, $haewayaNo));
        $this->line('');
        $this->line('  The watermark is CLEARED, so PaymentNumber::allocate() will start issuing.');
        $this->line('  The clash guard remains: it still refuses a number already present in');
        $this->line('  our payments table, and still refuses a long run of them as staleness.');
        $this->line('');
        $this->warn('  NOTHING HERE CAN PROVE CREATOR HAS STOPPED. That is your step, not this');
        $this->warn('  command\'s. If Creator is still live, both systems will mint the same');
        $this->warn('  numbers and the damage is not automatically detectable.');
        $this->line('');

        if (! $this->confirm('Creator writes are stopped and these numbers are from its screen. Proceed?', false)) {
            $this->line('Nothing changed.');

            return self::SUCCESS;
        }

        $auto->payment_no = $paymentNo;
        $auto->haewaya_no = $haewayaNo;
        // Cleared, not set to the new value: a watermark equal to the counter would
        // refuse the very first allocation, since the guard compares `<=`.
        $auto->live_payment_no_observed = null;
        $auto->live_haewaya_no_observed = null;
        $auto->live_observed_at = now();
        $auto->save();

        $this->line('');
        $this->info(sprintf('Series taken over. Next EKS/PY issued will be %d.', $paymentNo));
        $this->line('  Verify before anyone uses it:');
        $this->line('    php artisan tinker --execute="echo App\\\\Models\\\\AutoNumber::first()->payment_no;"');

        return self::SUCCESS;
    }

    /** @return array<string, mixed>|null */
    private function fromZoho(AnalyticsClient $client): ?array
    {
        $this->line('Exporting live Auto Numbers (one row — the cheapest export in the account).');

        $rows = [];

        foreach ($client->stream('auto_numbers') as $row) {
            $rows[] = $row;
        }

        return $this->shape($rows);
    }

    /** @return array<string, mixed>|null */
    private function fromFile(string $path): ?array
    {
        if (! is_file($path)) {
            $this->error("No such file: {$path}");

            return null;
        }

        $rows = [];

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $decoded = json_decode($line, true);

            if (is_array($decoded)) {
                $rows[] = $decoded;
            }
        }

        return $this->shape($rows);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>|null
     */
    private function shape(array $rows): ?array
    {
        if ($rows === []) {
            $this->error('Auto Numbers exported no rows. Without the counter there is nothing to compare.');

            return null;
        }

        if (count($rows) > 1) {
            // Creator reads `Auto_Numbers[ID != null]` with no ordering and takes the
            // first row of an unordered loop, so a second row makes live's own
            // behaviour arbitrary. Worth stopping for.
            $this->error(sprintf(
                'Auto Numbers returned %d rows. It is a singleton — Creator reads it with no '
                .'ordering, so more than one row makes ITS allocation arbitrary too. Refusing '
                .'rather than picking one.',
                count($rows),
            ));

            return null;
        }

        $row = $rows[0];

        // §11: key names vary per view, so read them verbatim from what the export gave.
        return [
            // §15.2: an 18-digit id stays a STRING.
            'creator_id' => trim((string) ($row['ID'] ?? '')),
            'payment_no' => (int) ($row['Payment No'] ?? 0),
            'haewaya_no' => (int) ($row['Haewaya No'] ?? 0),
            'books_payment_no' => (int) ($row['Books Payment No'] ?? 0),
            'modified_time' => trim((string) ($row['Modified Time'] ?? '')),
            'modified_user' => trim((string) ($row['Modified User'] ?? '')),
        ];
    }
}
