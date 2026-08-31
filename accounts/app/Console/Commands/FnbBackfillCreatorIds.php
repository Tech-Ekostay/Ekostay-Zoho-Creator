<?php

namespace App\Console\Commands;

use App\Services\Zoho\AnalyticsClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Backfill `creator_id` on the CSV-seeded F&B masters, by name, from Analytics.
 *
 *     php artisan fnb:backfill-ids
 *
 * WHY THIS EXISTS. Two CSV exports carry no ID column at all — `UOM Report.csv` is
 * a single column of names, and warehouses have no export of their own (they were
 * recovered from the inventory rows that name them). So both tables seeded with
 * `creator_id` NULL.
 *
 * That was invisible until the stock ledger arrived: 68,294 of 68,322 transaction
 * rows failed to resolve a warehouse, because Analytics references it by record id
 * and we had no id to match. The rows imported with a NULL warehouse and the
 * command printed green.
 *
 * A NAME MATCH IS USED HERE, ONCE, AND ONLY HERE. Everything else resolves by
 * `creator_id`, deliberately — name matching is what nearly orphaned 70 items over
 * a trailing space. But there is no id to match on yet, so the id has to be
 * acquired by the one key both sides share. After this runs, every subsequent
 * import is id-based.
 *
 * IT MATCHES ON THE TRIMMED NAME, AND DOES NOT REWRITE THE STORED NAME.
 * Analytics TRIMS: the UOM view returns `Pieces` where Creator's own report export
 * writes `Pieces ` (findings §15.3). So the join tolerates the difference while the
 * stored key keeps its trailing space — normalise the lookup, never the data.
 *
 * A name that matches two rows is REFUSED rather than guessed at. An ambiguous
 * backfill would attach the wrong id to real transactional data.
 */
class FnbBackfillCreatorIds extends Command
{
    protected $signature = 'fnb:backfill-ids {--dry-run}';

    protected $description = 'Set creator_id on CSV-seeded F&B masters, matched by name from Analytics';

    /**
     * local table => [analytics view, the view's name field, our name column]
     *
     * The last three are ACCOUNTS/ADMIN masters, not F&B — but F&B references them
     * by record id, and ours were recovered from CSV names with no creator_id. The
     * F&B order view returns `State: "292482000000169003"`, so 10,762 of 10,765
     * orders resolved nothing until these were backfilled too.
     *
     * Billing cycles match on MONTH NAME, which is enough only because the source
     * carries one row per month per year and our recovered rows do the same. Both
     * February spellings are distinct keys and both survive.
     */
    private const TARGETS = [
        'fnb_uoms' => ['fnb_uoms', 'UOM', 'name'],
        'fnb_warehouses' => ['fnb_warehouses', 'Warehouse Name', 'warehouse_name'],
        'fnb_item_masters' => ['fnb_item_masters', 'Item Name', 'item_name'],
        'locations' => ['location', 'Location', 'name'],
        'states' => ['states', 'State', 'name'],
    ];

    public function handle(AnalyticsClient $zoho): int
    {
        $dry = (bool) $this->option('dry-run');

        foreach (self::TARGETS as $table => [$view, $sourceField, $localField]) {
            $missing = DB::table($table)->whereNull('creator_id')->count();

            if ($missing === 0) {
                $this->line("{$table}: every row already has a creator_id, skipping.");

                continue;
            }

            $this->newLine();
            $this->line("<fg=cyan>── {$table}</> — {$missing} row(s) without a creator_id");

            // name (trimmed) => [ids]. Collected as a list so an ambiguous name is
            // detectable rather than silently last-wins.
            $byName = [];
            foreach ($zoho->stream($view) as $row) {
                $name = trim((string) ($row[$sourceField] ?? ''));
                $id = (string) ($row['ID'] ?? '');
                if ($name === '' || $id === '') {
                    continue;
                }
                $byName[$name][] = $id;
            }

            $set = 0;
            $ambiguous = [];
            $unmatched = [];

            foreach (DB::table($table)->whereNull('creator_id')->get() as $local) {
                $key = trim((string) $local->{$localField});
                $ids = $byName[$key] ?? [];

                if ($ids === []) {
                    $unmatched[] = $key;

                    continue;
                }

                if (count(array_unique($ids)) > 1) {
                    // Two Creator records share this name. Attaching either id
                    // would be a guess, and the id is what transactional rows
                    // resolve through.
                    $ambiguous[$key] = count(array_unique($ids));

                    continue;
                }

                if (! $dry) {
                    DB::table($table)->where('id', $local->id)->update([
                        'creator_id' => $ids[0],
                        'updated_at' => now(),
                    ]);
                }
                $set++;
            }

            $this->info(sprintf('  %s %d creator_id(s)', $dry ? 'would set' : 'set', $set));

            if ($ambiguous !== []) {
                $this->warn('  AMBIGUOUS — name matches more than one Creator record, left NULL:');
                foreach ($ambiguous as $name => $n) {
                    $this->line(sprintf('    %-40s %d records', '"'.$name.'"', $n));
                }
            }

            if ($unmatched !== []) {
                $this->warn(sprintf(
                    '  %d name(s) not found in the Analytics view: %s%s',
                    count($unmatched),
                    implode(', ', array_map(fn ($u) => '"'.$u.'"', array_slice($unmatched, 0, 5))),
                    count($unmatched) > 5 ? ' …' : ''
                ));
            }
        }

        $this->newLine();
        $this->line('Re-run `fnb:import` afterwards — the id-based lookups will resolve now.');

        return self::SUCCESS;
    }
}
