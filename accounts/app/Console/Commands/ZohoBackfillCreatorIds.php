<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Give `locations` and `head_offices` their Creator record ids.
 *
 * WHY THEY HAVE NONE. Both tables were *derived* from `All_Villas.csv`, which carries
 * Location and Head Office as NAMES. So the rows exist and are correct, but they have
 * no `creator_id` — and every Analytics fact view refers to them by 18-digit record
 * id. The payments import resolved 334 of 52,636 locations before this ran.
 *
 * HOW IT RECOVERS THEM WITHOUT AN EXPORT. The `villa` Analytics export, already on
 * disk, gives per villa both its own record id and its Location / Head Office record
 * ids. Our `villas` table has the same villas keyed by `creator_id`, with
 * `location_id` and `head_office_id` already resolved by name. Joining the two on the
 * villa's record id yields `our location row -> Creator's location id`.
 *
 * A NEW EXPORT WOULD ALSO WORK and would be more direct — the `location` view is
 * registered and takes seconds. This route is preferred because the export
 * concurrency limit is shared with a live production app, so a slot not used is a
 * slot the expense tracker can have.
 *
 * SAFE TO RE-RUN. It only ever fills a NULL `creator_id`, and it refuses to write a
 * mapping that is ambiguous — if two villas disagree about which Creator id a
 * location has, neither is written and the conflict is reported.
 */
class ZohoBackfillCreatorIds extends Command
{
    protected $signature = 'zoho:backfill-creator-ids
        {--file= : a villa .ndjson export (default: newest in storage/app/zoho)}
        {--dry-run : report what would be written}';

    protected $description = 'Recover Creator record ids for locations and head_offices from a saved villa export.';

    public function handle(): int
    {
        $path = $this->option('file')
            ?: collect(File::glob(storage_path('app/zoho/villa-*.ndjson')))
                ->sortByDesc(fn ($f) => filemtime($f))->first();

        if ($path === null || ! is_file($path)) {
            $this->error('No villa export found. Run: php artisan zoho:inspect villa');

            return self::FAILURE;
        }

        $this->line("Reading <info>{$path}</info>");

        // villa creator_id -> our location_id / head_office_id
        $villas = DB::table('villas')
            ->whereNotNull('creator_id')
            ->get(['creator_id', 'location_id', 'head_office_id'])
            ->keyBy('creator_id');

        // our id -> set of Creator ids seen (a set, so disagreement is detectable)
        $locationMap = [];
        $officeMap = [];

        foreach (file($path) as $line) {
            $row = json_decode(trim($line), true);
            if (! is_array($row)) {
                continue;
            }

            $villa = $villas[$row['ID'] ?? ''] ?? null;
            if ($villa === null) {
                continue;
            }

            $zohoLocation = $row['Location'] ?? '';
            if ($villa->location_id !== null && $zohoLocation !== '') {
                $locationMap[$villa->location_id][$zohoLocation] = true;
            }

            $zohoOffice = $row['Head Office'] ?? '';
            if ($villa->head_office_id !== null && $zohoOffice !== '') {
                $officeMap[$villa->head_office_id][$zohoOffice] = true;
            }
        }

        $written = ['locations' => 0, 'head_offices' => 0];
        $conflicts = [];

        foreach ([['locations', $locationMap], ['head_offices', $officeMap]] as [$table, $map]) {
            foreach ($map as $localId => $creatorIds) {
                if (count($creatorIds) > 1) {
                    // Two villas disagree. Do not pick one — report it.
                    $conflicts[] = sprintf('%s id %d maps to %d different Creator ids: %s',
                        $table, $localId, count($creatorIds), implode(', ', array_keys($creatorIds)));

                    continue;
                }

                $creatorId = (string) array_key_first($creatorIds);

                $affected = DB::table($table)
                    ->where('id', $localId)
                    ->whereNull('creator_id');      // never overwrite an existing id

                if ($this->option('dry-run')) {
                    $written[$table] += $affected->count();

                    continue;
                }

                $written[$table] += $affected->update(['creator_id' => $creatorId, 'updated_at' => now()]);
            }
        }

        $this->newLine();
        $this->info($this->option('dry-run') ? 'DRY RUN — nothing written.' : 'Backfill complete.');
        foreach ($written as $table => $n) {
            $this->line(sprintf('   %-14s %3d rows given a creator_id', $table, $n));
        }

        foreach ($conflicts as $c) {
            $this->warn('   CONFLICT (skipped): '.$c);
        }

        if (! $this->option('dry-run')) {
            foreach (['locations', 'head_offices'] as $table) {
                $this->line(sprintf('   %-14s %d of %d now have one',
                    $table,
                    DB::table($table)->whereNotNull('creator_id')->count(),
                    DB::table($table)->count(),
                ));
            }
        }

        return self::SUCCESS;
    }
}
