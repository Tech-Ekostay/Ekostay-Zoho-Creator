<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Zoho\AnalyticsClient;
use App\Services\Zoho\ZohoViews;
use Illuminate\Console\Command;
use Throwable;

/**
 * What does Analytics actually carry? — metadata only, NO EXPORT SLOT.
 *
 * The question "can we sync every module?" cannot be answered from the registry,
 * because the registry is 16 views someone wrote down and Analytics may hold more or
 * fewer. It also must not be answered by attempting exports: the concurrency limit is
 * account-wide and shared with a live production app, and a collision stalled both for
 * two days (§9).
 *
 * So this lists views through the metadata API, which creates no bulk job, and marks
 * which ones the registry already knows. It is the cheap step that decides where the
 * expensive steps go.
 */
class ZohoListViews extends Command
{
    protected $signature = 'zoho:views
                            {--workspace=* : accounts and/or live. Default: both.}
                            {--missing : only show views the registry does NOT know}';

    protected $description = 'List every Analytics view (metadata only — costs no export slot).';

    public function handle(AnalyticsClient $client): int
    {
        $workspaces = $this->option('workspace') ?: ['accounts', 'live'];

        $this->line('');
        $this->info('Metadata only. No bulk export job is created, so no slot is taken');
        $this->line('  and Tushar\'s syncs are untouched.');
        $this->line('');

        $registered = [];

        foreach (ZohoViews::all() as $key => $meta) {
            $registered[(string) $meta['id']] = $key;
        }

        $unknownTotal = 0;

        foreach ($workspaces as $workspace) {
            try {
                $views = $client->views($workspace);
            } catch (Throwable $e) {
                $this->error(sprintf('workspace %s: %s', $workspace, $e->getMessage()));

                continue;
            }

            $rows = [];

            foreach ($views as $view) {
                $key = $registered[$view['id']] ?? null;

                if ($this->option('missing') && $key !== null) {
                    continue;
                }

                if ($key === null) {
                    $unknownTotal++;
                }

                $rows[] = [
                    $view['name'],
                    $view['type'],
                    $view['id'],
                    $key ?? '—',
                ];
            }

            $this->line("<comment>{$workspace}</comment> — ".count($views).' views');
            $this->table(['View', 'Type', 'Id', 'Registry key'], $rows);
            $this->line('');
        }

        if ($unknownTotal > 0) {
            $this->warn(sprintf(
                '%d view(s) exist in Analytics that ZohoViews does not know. Register the ones '
                .'this rebuild needs before exporting them — the registry is what carries the '
                .'`large` and `avoid` flags, and `all_payments` is flagged `avoid` because a '
                .'bulk export of it holds a shared slot for ten minutes and then fails.',
                $unknownTotal,
            ));
        }

        return self::SUCCESS;
    }
}
