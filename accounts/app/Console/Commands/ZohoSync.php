<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Zoho\AnalyticsClient;
use App\Services\Zoho\ZohoViews;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Fetch live data for many modules in one deliberate pass.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS EXISTS AS AN ORCHESTRATOR RATHER THAN A LOOP IN A SHELL SCRIPT.
 *
 * **The Analytics export concurrency limit is ACCOUNT-WIDE and shared with a live
 * production application.** A collision stalled both apps' syncs for two days (§9).
 * So a multi-view fetch is not "run the export command N times" — it is a scheduling
 * problem, and the things that make it safe belong in one place:
 *
 *   1. **STRICTLY SEQUENTIAL.** One job at a time, never concurrent. Two of our own
 *      exports racing each other is the same collision as racing Tushar's.
 *   2. **The minute is checked BEFORE EACH VIEW, not once at the start.** A ten-view
 *      pass takes long enough to walk into `:42`, and the guard checked at `:31` says
 *      nothing about where the fifth export lands.
 *   3. **`avoid` views are refused, not skipped quietly.** `all_payments` is a
 *      heavy-join QueryTable that holds a shared slot for ten minutes and then fails.
 *   4. **`--dry-run` costs nothing** and is the default way to decide what to fetch.
 *
 * GROUPS are named sets, because "sync everything" is not a real request: 413 Tables
 * exist, most belong to other applications, and the useful sets are small.
 *
 * NOTHING HERE WRITES TO THE DATABASE. It exports and saves, exactly as
 * `zoho:inspect` does. The importers are separate on purpose — an import is a
 * decision about dirty data (§3: fix it with a migration and a mapping table, never
 * silently on read), and a fetch should never make one.
 */
class ZohoSync extends Command
{
    protected $signature = 'zoho:sync
                            {group? : a named group, or omit and pass --view}
                            {--view=* : explicit view keys, repeatable}
                            {--dry-run : show the plan and touch nothing}
                            {--pause=20 : seconds to wait between views}';

    protected $description = 'Export several Analytics views in one sequential, slot-safe pass.';

    /**
     * Named sets. Ordered cheapest-and-most-unblocking first, so a pass that has to be
     * abandoned has already delivered the valuable part.
     *
     * @var array<string, list<string>>
     */
    private const GROUPS = [
        // One row, and it decides whether this app may mint a payment number at all.
        'counters' => ['auto_numbers'],

        /*
         * The approval engine. `approval_approvers` is the grid `ApprovalRouter` was
         * written to refuse without — the single biggest unblock in the registry.
         * All small: 16 rules, their levels, and a two-field form.
         */
        'approvals' => ['approval', 'approval_approvers', 'preferred_approver'],

        // The queue and its subform. Over 1,000 rows, so `large`.
        'queue' => ['pending_approvals', 'pending_approvals_approved_by'],

        /*
         * THE CHILD ROWS. §12's rule is to import these and never the parent, and the
         * reason `payments.villa_id` is null on 52,637 of 52,639 rows is that we
         * imported the parent only. Expect this to be the largest export here.
         */
        'payment-legs' => ['payment_split_payments', 'payment_bill_payments', 'payment_bills'],

        // Modules documented from screenshots and never sourced.
        'modules' => [
            'payment_request', 'expense_observation',
            'backend_payments', 'backend_expenses',
        ],

        // Bank, which arrives from Books via Creator (§7B.5).
        'bank' => ['bank_transactions', 'bank_transactions_matching', 'banks'],

        // Masters, to confirm counts rather than to seed — we hold these from CSV.
        'masters' => ['coa', 'location', 'villa'],
    ];

    public function handle(AnalyticsClient $client): int
    {
        $views = $this->resolve();

        if ($views === []) {
            $this->error('Nothing to do. Pass a group or --view=<key>.');
            $this->line('  groups: '.implode(', ', array_keys(self::GROUPS)));

            return self::FAILURE;
        }

        // Refuse the whole pass rather than silently dropping a view from it.
        foreach ($views as $key) {
            $meta = ZohoViews::all()[$key] ?? null;

            if ($meta === null) {
                $this->error("Unknown view '{$key}'. Registered: ".implode(', ', array_keys(ZohoViews::all())));

                return self::FAILURE;
            }

            if (isset($meta['avoid'])) {
                $this->error("'{$key}' is flagged avoid: {$meta['avoid']}");

                return self::FAILURE;
            }
        }

        $this->plan($views);

        if ($this->option('dry-run')) {
            $this->line('');
            $this->info('Dry run. No export job was created, so no slot was taken.');

            return self::SUCCESS;
        }

        $this->line('');
        $this->warn('This competes for slots with a LIVE production application.');

        if (! $this->confirm(sprintf('Export %d view(s) now?', count($views)), false)) {
            $this->line('Nothing exported.');

            return self::SUCCESS;
        }

        return $this->exportAll($client, $views);
    }

    /** @param list<string> $views */
    private function exportAll(AnalyticsClient $client, array $views): int
    {
        $pause = max(0, (int) $this->option('pause'));
        $failed = [];
        $done = [];

        foreach ($views as $i => $key) {
            $meta = ZohoViews::all()[$key];

            /*
             * CHECKED PER VIEW. A pass that started on a clear minute can walk into a
             * reserved one, and the guard is worthless if it only sees the first.
             *
             * The minute shown is IST, because that is the timezone the other app's cron
             * runs in and `app.timezone` here is UTC. Printing the UTC minute beside an
             * IST reserved-minute list is what hid the guard's timezone bug.
             */
            $zone = (string) config('services.zoho.foreign_cron_timezone', 'Asia/Kolkata');
            $minute = (int) Carbon::now()->setTimezone($zone)->format('i');

            try {
                ZohoViews::assertScheduleIsClear();
            } catch (Throwable $e) {
                $this->line('');
                $this->error($e->getMessage());
                $this->warn(sprintf(
                    'Stopped before %s. %d of %d views were exported; re-run for the rest.',
                    $key, count($done), count($views),
                ));

                return self::FAILURE;
            }

            $this->line('');
            $this->line(sprintf(
                '[%d/%d] <comment>%s</comment> — %s (:%02d %s)',
                $i + 1, count($views), $key, $meta['label'] ?? $key, $minute, $zone,
            ));

            try {
                $rows = 0;
                $path = $this->save($key, $client, $rows);

                $done[$key] = $rows;
                $this->info(sprintf('        %s rows -> %s', number_format($rows), $path));
            } catch (Throwable $e) {
                $failed[$key] = $e->getMessage();
                $this->error('        '.$e->getMessage());
            }

            if ($pause > 0 && $i < count($views) - 1) {
                $this->line("        pausing {$pause}s before the next view");
                sleep($pause);
            }
        }

        $this->summary($done, $failed);

        return $failed === [] ? self::SUCCESS : self::FAILURE;
    }

    private function save(string $key, AnalyticsClient $client, int &$rows): string
    {
        $dir = storage_path('app/zoho');

        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $path = $dir.'/'.str_replace('_', '-', $key).'-'.Carbon::now()->format('Ymd-His').'.ndjson';
        $handle = fopen($path, 'w');

        try {
            foreach ($client->stream($key) as $row) {
                fwrite($handle, json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
                $rows++;
            }
        } finally {
            fclose($handle);
        }

        return str_replace(storage_path().DIRECTORY_SEPARATOR, 'storage/', $path);
    }

    /** @return list<string> */
    private function resolve(): array
    {
        $explicit = (array) $this->option('view');
        $group = $this->argument('group');

        if ($explicit !== []) {
            return array_values(array_map('strval', $explicit));
        }

        return $group !== null ? (self::GROUPS[$group] ?? []) : [];
    }

    /** @param list<string> $views */
    private function plan(array $views): void
    {
        $rows = [];

        foreach ($views as $key) {
            $meta = ZohoViews::all()[$key];

            $rows[] = [
                $key,
                $meta['label'] ?? $key,
                $meta['workspace'],
                isset($meta['large']) ? 'large (csv)' : 'small (json)',
            ];
        }

        $this->line('');
        $this->table(['Key', 'View', 'Workspace', 'Size'], $rows);
        $this->line('  Sequential, one job at a time. The minute is re-checked before each view.');
        $this->line(sprintf(
            '  Reserved minutes, in %s — the timezone their cron runs in, NOT this app\'s UTC:',
            (string) config('services.zoho.foreign_cron_timezone', 'Asia/Kolkata'),
        ));
        $this->line('    '.implode(', ', array_map(
            fn ($m) => sprintf(':%02d', $m),
            (array) config('services.zoho.foreign_cron_minutes', []),
        )));
    }

    /**
     * @param  array<string, int>  $done
     * @param  array<string, string>  $failed
     */
    private function summary(array $done, array $failed): void
    {
        $this->line('');

        if ($done !== []) {
            $this->info('Exported:');

            foreach ($done as $key => $rows) {
                $this->line(sprintf('  %-34s %s rows', $key, number_format($rows)));
            }
        }

        if ($failed !== []) {
            $this->line('');
            $this->error('Failed:');

            foreach ($failed as $key => $message) {
                $this->line(sprintf('  %-34s %s', $key, $message));
            }
        }

        $this->line('');
        $this->line('Files are saved, and NOTHING was written to the database. Importing is a');
        $this->line('separate decision: §3 says fix dirty data with a migration and a mapping');
        $this->line('table, never silently on read.');
    }
}
