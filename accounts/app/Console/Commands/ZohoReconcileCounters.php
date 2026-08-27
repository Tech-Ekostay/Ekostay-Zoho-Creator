<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Move the payment-number counters forward to what live has actually issued.
 *
 * WHY THIS IS THE MOST DANGEROUS THING ON THE LIST. `auto_numbers` was seeded from
 * `Auto_Numbers.json`, a snapshot taken 22-Aug-2026. Live has kept issuing numbers
 * since. Measured against the 52,678-row `payment_master` export:
 *
 *     EKS/PY        live max 21,305   ours 20,942    363 behind
 *     EKS/Haewaya   live max 33,293   ours 32,010  1,283 behind
 *
 * So the next payment this app created would take a number that ALREADY EXISTS in
 * live accounting. §7.6's whole argument is that a number, once issued, is never
 * reissued — and the counter being stale is exactly how it gets reissued.
 *
 * ---------------------------------------------------------------------------
 * IT ONLY EVER MOVES FORWARD. If our counter is already ahead of the export, it is
 * left alone: the export lags Creator by minutes (§1), and a payment we minted after
 * the export was taken is real. Winding a counter BACK would re-issue on purpose.
 *
 * THE COUNTER HOLDS THE **NEXT** NUMBER, NOT THE LAST ISSUED. This is what made the
 * first version of this command wrong, and it caused a real collision before a
 * browser test caught it.
 *
 * `PaymentNumber::allocate()` returns the counter's CURRENT value and then
 * increments, so a counter of 20938 means "20938 is next" — which matches CLAUDE.md's
 * record that the first payment created took `EKS/PY/20938` off a counter seeded at
 * 20938. Setting the counter to live's MAX therefore hands the max out a second time.
 * The correct target is **live max + 1**.
 *
 * What the mistake actually did: minted `EKS/PY/21305` while a live payment of
 * Rs 1,00,000 already held that number. Precisely the §7.6 violation this command
 * exists to prevent, caused by the command itself.
 *
 * BEYOND THE +1 IT INVENTS NO CEILING. No safety margin on top: a margin would
 * silently skip numbers, and a gap in an accounting series is its own question to
 * answer later. If racing live is a real concern the fix is to stop writing from two
 * systems, not to pad here.
 */
class ZohoReconcileCounters extends Command
{
    protected $signature = 'zoho:reconcile-counters
        {file? : a payment_master .ndjson (default: newest in storage/app/zoho)}
        {--dry-run : report what would change}';

    protected $description = 'Move payment-number counters forward to the highest number live has issued.';

    /** Which `auto_numbers` column holds the counter for each series prefix. */
    private const SERIES = [
        'EKS/PY' => 'payment_no',
        'EKS/Haewaya' => 'haewaya_no',
        // `EKS/BPY` (books) and `EKS/PAY` have counters or not depending on the
        // column set; only the two above are modelled, so only those are touched.
    ];

    public function handle(): int
    {
        $path = $this->argument('file')
            ?: collect(File::glob(storage_path('app/zoho/payment-master-*.ndjson')))
                ->sortByDesc(fn ($f) => filemtime($f))->first();

        if ($path === null || ! is_file($path)) {
            $this->error('No payment_master export found. Run: php artisan zoho:inspect payment_master');

            return self::FAILURE;
        }

        $this->line("Reading <info>{$path}</info>");

        $max = array_fill_keys(array_keys(self::SERIES), 0);
        $seen = 0;

        $handle = fopen($path, 'r');
        while (($line = fgets($handle)) !== false) {
            $row = json_decode(trim($line), true);
            if (! is_array($row)) {
                continue;
            }

            $no = (string) ($row['Payment No.'] ?? '');
            $seen++;

            foreach (self::SERIES as $prefix => $_) {
                if (preg_match('#^'.preg_quote($prefix, '#').'/(\d+)$#', $no, $m) === 1) {
                    $max[$prefix] = max($max[$prefix], (int) $m[1]);
                }
            }
        }
        fclose($handle);

        $this->line(sprintf('Scanned %d payment numbers.', $seen));
        $this->newLine();

        $counters = DB::table('auto_numbers')->first();

        if ($counters === null) {
            $this->error('auto_numbers is empty — seed AutoNumberSeeder first.');

            return self::FAILURE;
        }

        $updates = [];

        foreach (self::SERIES as $prefix => $column) {
            $ours = (int) ($counters->{$column} ?? 0);
            $live = $max[$prefix];

            if ($live === 0) {
                $this->warn(sprintf('%-14s no numbers found in the export — left at %d', $prefix, $ours));

                continue;
            }

            // +1: the counter is the NEXT number to issue, not the last issued.
            $target = $live + 1;

            if ($target <= $ours) {
                $this->line(sprintf('%-14s next %6d >= live max %6d + 1 — <info>left alone</info> (forward only)',
                    $prefix, $ours, $live));

                continue;
            }

            $this->warn(sprintf('%-14s next %6d -> %6d  (live max %d; %d numbers would have collided)',
                $prefix, $ours, $target, $live, $target - $ours));

            $updates[$column] = $target;
        }

        if ($updates === []) {
            $this->newLine();
            $this->info('Nothing to do — every counter is at or ahead of live.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->newLine();
            $this->info('DRY RUN — nothing written.');

            return self::SUCCESS;
        }

        $updates['updated_at'] = now();
        DB::table('auto_numbers')->where('singleton', 1)->update($updates);

        $this->newLine();
        $this->info('Counters reconciled. The next number in each series is now one past live.');

        $after = DB::table('auto_numbers')->first();
        foreach (self::SERIES as $prefix => $column) {
            // The stored value IS the next number, so it is printed as-is.
            $this->line(sprintf('   %-14s next -> %s/%d', $prefix, $prefix, (int) $after->{$column}));
        }

        return self::SUCCESS;
    }
}
