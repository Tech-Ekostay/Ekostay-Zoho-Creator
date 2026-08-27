<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Zoho\AnalyticsClient;
use App\Services\Zoho\ZohoViews;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Export one Analytics view and REPORT ON ITS SHAPE. Writes nothing to the database.
 *
 * WHY INSPECTION COMES BEFORE IMPORT, and why this is not busywork. §11 of the
 * field notes is blunt about it: field key names are per-view and unpredictable —
 * payment number arrived as `Payment No.` in one view, `Payment` in another,
 * `payment_no` in a third, and the notes' own conclusion was that they "could never
 * predict it, only discover it per view". So an importer cannot be written until a
 * real export has been looked at. This is the looking.
 *
 * It also checks the two things most likely to make an import silently wrong on
 * THIS data:
 *
 *  1. MULTI-VALUE FLATTENING (§12). Analytics projects a multi-select down to one
 *     silently-chosen value — measured on an expense tagged to two billing cycles
 *     that exported tagged to one. That is exactly this project's shape: a bill
 *     spans many villas x cycles x categories, and §5.2 makes each leg a ledger
 *     entry. If a view has one row per bill, its split is already lost. This
 *     command reports the row-to-key ratio so a parent-level view is recognisable
 *     before anyone imports from it.
 *
 *  2. RECORD IDS AS STRINGS (§6). 18-digit ids get silently corrupted by anything
 *     that treats them as numbers. This reports any id-shaped value that arrived
 *     already numeric, because by then the damage is upstream of us.
 *
 * Usage:
 *   php artisan zoho:inspect <view> [--criteria=...] [--out=path] [--rows=3]
 */
class ZohoInspectView extends Command
{
    protected $signature = 'zoho:inspect
        {view? : a registry name (payment_master, expenses, villa, …) or a raw numeric view id}
        {--criteria= : server-side filter — read §10 first, the grammar is strict}
        {--out= : where to save the raw export (default storage/app/zoho/)}
        {--rows=3 : how many sample rows to print}
        {--no-save : report only, write no file}
        {--force : export a view the registry says to avoid — read the warning first}
        {--list : list the registered views and stop}';

    protected $description = 'Export a Zoho Analytics view and report its shape. Reads only; touches no tables.';

    public function handle(): int
    {
        if ($this->option('list') || $this->argument('view') === null) {
            $this->listViews();

            return self::SUCCESS;
        }

        $view = (string) $this->argument('view');

        try {
            $meta = ZohoViews::get($view);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        try {
            $client = new AnalyticsClient;
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line(sprintf('Exporting <info>%s</info> (%s) from workspace <info>%s</info>.',
            $meta['label'], $meta['id'], $meta['workspace']));

        if (isset($meta['note'])) {
            $this->line('  <comment>'.wordwrap($meta['note'], 100, PHP_EOL.'  ').'</comment>');
        }

        $this->newLine();
        $this->line('<comment>Do not interrupt this.</comment> Abandoning a poll does not cancel the job — it keeps');
        $this->line('running and keeps holding a concurrency slot that is shared ACCOUNT-WIDE with the');
        $this->line("expense tracker's production sync. A collision once stalled both apps for two days.");
        $this->newLine();

        $started = microtime(true);

        /*
         * STREAMED, NOT MATERIALISED — and this was measured, not anticipated.
         * The first run of this command against `payment_master` authenticated,
         * exported and downloaded correctly, then exhausted a 512MB PHP limit
         * inside json_decode. Holding an unknown-size view in memory to count it
         * is the same mistake §7.4 warns about, one layer up.
         *
         * So everything below is computed INCREMENTALLY: a running count, a tally
         * of distinct key sets, the id-hazard check, and a bounded sample. Rows are
         * written to disk as NDJSON as they arrive, because pretty-printing a
         * 100k-row array is the original problem wearing a hat.
         */
        $sampleSize = max(0, (int) $this->option('rows'));

        $count = 0;
        $shapes = [];
        $numericIds = [];
        $sample = [];
        $handle = null;
        $path = null;

        if (! $this->option('no-save')) {
            $path = $this->option('out')
                ?: storage_path('app/zoho/'.str($view)->slug().'-'.now()->format('Ymd-His').'.ndjson');
            File::ensureDirectoryExists(dirname($path));
            $handle = fopen($path, 'w');
        }

        try {
            $rows = $client->stream($view, $this->option('criteria') ?: null, (bool) $this->option('force'));

            foreach ($rows as $row) {
                $count++;

                if (is_array($row)) {
                    $keys = array_keys($row);
                    sort($keys);
                    $shape = implode('|', $keys);
                    $shapes[$shape] = ($shapes[$shape] ?? 0) + 1;

                    // Only the first 500 rows are type-checked — enough to catch a
                    // column-wide problem without walking the whole view.
                    if ($count <= 500) {
                        foreach ($row as $key => $value) {
                            if ((is_int($value) || is_float($value))
                                && preg_match('/(^|[_\s])id$|^id$/i', (string) $key) === 1) {
                                $numericIds[$key] = true;
                            }
                        }
                    }
                }

                if (count($sample) < $sampleSize) {
                    $sample[] = $row;
                }

                if ($handle !== null) {
                    fwrite($handle, json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL);
                }

                if ($count % 25000 === 0) {
                    $this->line('  ... '.$count.' rows');
                }
            }
        } catch (Throwable $e) {
            if ($handle !== null) {
                fclose($handle);
            }
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($handle !== null) {
            fclose($handle);
        }

        $elapsed = round(microtime(true) - $started, 1);
        $this->info(sprintf('%d rows in %ss.', $count, $elapsed));

        if ($count === 0) {
            /*
             * §6, MEASURED: absence is not evidence of anything. An empty export
             * looks identical to a filtered one, a failed one and a short-paginated
             * one — the notes record nearly mass-deleting on a partial export. So
             * this is reported as inconclusive, not as "the view is empty".
             */
            $this->warn('No rows came back. That is NOT evidence the view is empty: a filtered,');
            $this->warn('failed or short export looks the same (§6). Check the criteria and retry');
            $this->warn('before drawing any conclusion — and never infer deletion from absence.');

            return self::SUCCESS;
        }

        $this->reportKeys($shapes, $count);
        $this->reportIdHazards($numericIds);
        $this->reportSample($sample);

        if ($path !== null) {
            $this->reportSaved($path);
        }

        return self::SUCCESS;
    }

    /**
     * §11 — the key set can differ ROW TO ROW as well as view to view, so this
     * reports every distinct key set rather than assuming the first row is typical.
     *
     * @param  array<string, int>  $shapes  key-set signature => how many rows had it
     */
    private function reportKeys(array $shapes, int $count): void
    {
        if ($shapes === []) {
            return;
        }

        arsort($shapes);

        $this->newLine();
        $this->line('<info>Column keys</info> — these are what an importer must read by, and §11 says');
        $this->line('they vary per view. Copy them verbatim; do not tidy them.');

        if (count($shapes) > 1) {
            $this->newLine();
            $this->warn(sprintf('%d DIFFERENT key sets in one export:', count($shapes)));

            foreach ($shapes as $shape => $rows) {
                $this->warn(sprintf('   %6d rows, %d columns', $rows, count(explode('|', (string) $shape))));
            }

            $this->warn('An importer needs alias lists and a per-row presence check, not a fixed map.');
        }

        $dominant = (string) array_key_first($shapes);

        foreach (explode('|', $dominant) as $key) {
            // Trailing punctuation and spaces are load-bearing in criteria (§10),
            // so keys are shown quoted rather than printed bare.
            $this->line('   '.var_export($key, true));
        }

        $this->newLine();
        $this->line(sprintf(
            '<comment>%d columns, %d rows.</comment> If this view has ONE ROW PER BILL rather than one per '
            .'split leg, its multi-selects are already flattened (§12) and the villa x cycle x '
            .'category attribution §5.2 depends on is gone. Import the child rows instead.',
            count(explode('|', $dominant)),
            $count,
        ));
    }

    /**
     * §6 — an id that arrived numeric has already lost precision.
     *
     * @param  array<string, true>  $numericIds
     */
    private function reportIdHazards(array $numericIds): void
    {
        if ($numericIds === []) {
            return;
        }

        $this->newLine();
        $this->error('ID COLUMNS ARRIVED AS NUMBERS: '.implode(', ', array_keys($numericIds)));
        $this->error('18-digit Creator ids lose precision as numbers (...361075 becomes ...361100).');
        $this->error('Cast to string at the boundary and confirm against Analytics before importing.');
    }

    private function reportSample(array $sample): void
    {
        if ($sample === []) {
            return;
        }

        $this->newLine();
        $this->line('<info>Sample rows</info>');

        foreach ($sample as $i => $row) {
            $this->line("  --- row {$i} ---");
            $this->line('  '.json_encode($row, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
    }

    /**
     * The export is written as NDJSON — one JSON object per line — as rows arrive.
     *
     * Not a pretty-printed array: building one in memory is the same out-of-memory
     * failure this command already hit once. NDJSON also streams back in, which any
     * importer over a large view is going to need.
     *
     * storage/app/ is git-ignored (`storage/app/.gitignore` is `*`), verified — a
     * real export of this instance carries PANs, GST registrations and bank details
     * and must not be committable by accident.
     */
    private function reportSaved(string $path): void
    {
        $this->newLine();
        $this->info('Saved to '.$path);
        $this->line('<comment>Real data — PANs, GST numbers, bank details.</comment> One JSON object per line.');
        $this->line('storage/ is git-ignored; keep it that way and delete the file when done.');
    }

    /**
     * The registry, with the recommended order for THIS project at the top.
     *
     * The order is not arbitrary: `payments` and `bills` in this database are 100%
     * fixture, so `payment_master` and `expenses` are the two views that would turn
     * the rebuild from a shell with real masters into one with real money in it.
     */
    private function listViews(): void
    {
        $this->newLine();
        $this->line('<info>Inspect these first, in this order</info>');

        foreach (ZohoViews::inspectionOrder() as $i => $name) {
            $meta = ZohoViews::get($name);
            $this->line(sprintf('  %d. <info>%-20s</info> %s', $i + 1, $name, $meta['label']));

            if (isset($meta['note'])) {
                $this->line('     <comment>'.wordwrap($meta['note'], 92, PHP_EOL.'     ').'</comment>');
            }
        }

        $this->newLine();
        $this->line('<info>All registered views</info>');

        foreach (['accounts', 'live'] as $workspace) {
            $this->newLine();
            $this->line("  <info>{$workspace}</info> workspace");

            foreach (ZohoViews::all() as $name => $meta) {
                if ($meta['workspace'] !== $workspace) {
                    continue;
                }

                $flags = [];
                if (isset($meta['avoid'])) {
                    $flags[] = '<error>AVOID</error>';
                }
                if ($meta['large'] ?? false) {
                    $flags[] = '<comment>large — stream as CSV</comment>';
                }

                $this->line(sprintf('    %-22s %-34s %s',
                    $name, $meta['label'], implode(' ', $flags)));
            }
        }

        $this->newLine();
        $this->line('Usage: <info>php artisan zoho:inspect payment_master</info>');
        $this->line('A raw numeric view id also works, for a view not registered here.');
        $this->newLine();
    }
}
