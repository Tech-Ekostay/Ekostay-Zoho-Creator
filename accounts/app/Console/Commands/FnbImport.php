<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ImportsFnbTables;
use App\Services\Zoho\AnalyticsClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Import F&B from the Creator source tables in Zoho Analytics.
 *
 *     php artisan fnb:import                     every table, dependency order
 *     php artisan fnb:import --only=transactions  one table
 *     php artisan fnb:import --dry-run            resolve everything, write nothing
 *
 * WHY THIS IS NOT A GENERIC IMPORTER. Each table resolves its own lookups and each
 * one has a rule that would be lost by a mapping table:
 *
 *   · Analytics returns lookups as 18-DIGIT RECORD IDS, not names. That is better
 *     than the CSVs, where `Pieces ` had to be matched by string and the trailing
 *     space nearly orphaned 70 rows. Resolution is by creator_id throughout.
 *   · Money arrives PRE-FORMATTED as text — `₹ 200.00`. Parsed, never cast.
 *   · A row referencing a master we do not hold gets a NULL and is COUNTED. It
 *     never creates the master: Creator's billing-cycle auto-create is the defect
 *     that put a junk "9-2026" cycle into live accounting (spec §6.4).
 *   · Nothing is trimmed. Every string is stored as it arrives.
 *
 * TOTALS ARE RECOMPUTED, NOT IMPORTED. 287 live orders already have line items
 * exceeding their stored parent total (findings §9.2). Importing the stored figure
 * would import the discrepancy; recomputing exposes it. The count is reported.
 */
class FnbImport extends Command
{
    use ImportsFnbTables;

    protected $signature = 'fnb:import
        {--only= : one table key, e.g. transactions}
        {--dry-run : resolve and report, write nothing}
        {--force : allow a view flagged large or avoid}';

    protected $description = 'Import F&B tables from the Creator source views in Zoho Analytics';

    /**
     * Dependency order. A child never imports before its parent, so a foreign key
     * always has something to resolve against.
     */
    private const ORDER = [
        // The REAL cycle master, 83 rows. Must precede orders: an order carries a
        // cycle id, and FnbBillingCycleSeeder only recovered the 14 cycle NAMES that
        // happened to appear on orders — the master goes back to 2023.
        'billing_cycles' => 'billing_cycles',
        'uoms' => 'fnb_uoms',
        'items' => 'fnb_item_masters',
        'warehouses' => 'fnb_warehouses',
        'inventories' => 'fnb_inventories',
        'inventory_stocks' => 'fnb_inventory_stocks',
        'price_lists' => 'fnb_vendor_price_lists',
        'chefs' => 'fnb_chef_masters',
        'recipes' => 'fnb_recipe_masters',
        'recipe_requirements' => 'fnb_recipe_requirements',
        'requests' => 'fnb_request_stock_for_foods',
        'raw_materials' => 'fnb_raw_material_requests',
        'orders' => 'fnb_vendor_order_bookings',
        'order_items' => 'fnb_vendor_order_booking_items',
        'transactions' => 'fnb_transaction_items',
        'transfers' => 'fnb_transfer_items',
        'monthly_checks' => 'fnb_monthly_checks',
        'monthly_check_items' => 'fnb_monthly_check_items',
        'food_orders' => 'fnb_food_order_details',
        'block_dates' => 'fnb_block_booking_dates',
    ];

    /** creator_id => local id, built as we go. */
    private array $map = [];

    private array $unresolved = [];

    private bool $dry = false;

    public function handle(AnalyticsClient $zoho): int
    {
        $this->dry = (bool) $this->option('dry-run');
        $only = $this->option('only');

        if ($only !== null && ! isset(self::ORDER[$only])) {
            $this->error("unknown table '{$only}'. Known: ".implode(', ', array_keys(self::ORDER)));

            return self::FAILURE;
        }

        if ($this->dry) {
            $this->warn('DRY RUN — resolving and reporting, writing nothing.');
        }

        $this->warn(
            'The export concurrency limit is ACCOUNT-WIDE and shared with the expense '
            ."tracker's production sync. A collision once stalled both apps for two days. "
            .'Do not interrupt a poll: abandoning it leaves the job holding a slot.'
        );

        $this->preloadAccountsMasters();

        foreach (self::ORDER as $key => $view) {
            if ($only !== null && $key !== $only) {
                continue;
            }

            $this->newLine();
            $this->line("<fg=cyan>── {$key}</> ({$view})");

            try {
                $rows = $this->fetch($zoho, $view);
            } catch (Throwable $e) {
                $this->error('  '.$e->getMessage());

                continue;
            }

            $method = 'import'.str_replace(' ', '', ucwords(str_replace('_', ' ', $key)));

            if (! method_exists($this, $method)) {
                $this->warn("  no importer written for '{$key}' yet — ".count($rows).' rows fetched, skipped.');

                continue;
            }

            $this->{$method}($rows);
        }

        $this->reportUnresolved();

        return self::SUCCESS;
    }

    /** @return array<int, array<string, mixed>> */
    private function fetch(AnalyticsClient $zoho, string $view): array
    {
        $rows = [];
        foreach ($zoho->stream($view, null, (bool) $this->option('force')) as $row) {
            $rows[] = $row;
        }
        $this->line('  fetched '.number_format(count($rows)).' rows');

        return $rows;
    }

    /*
     * ---- the Accounts side -------------------------------------------------
     *
     * F&B references Accounts masters by creator_id. These are already seeded, so
     * the maps are built once rather than queried per row.
     */
    private function preloadAccountsMasters(): void
    {
        foreach ([
            'item_categories' => 'cat',
            'vendors' => 'vendor',
            'locations' => 'location',
            'states' => 'state',
            'villas' => 'villa',
            'employees' => 'employee',
            'billing_cycles' => 'cycle',
            'taxes' => 'tax',
        ] as $table => $alias) {
            $this->map[$alias] = DB::table($table)
                ->whereNotNull('creator_id')
                ->pluck('id', 'creator_id')
                ->all();
        }

        // Locations and states are also referenced BY NAME in some views (the
        // warehouse view returns `Location: "Lonavala"`, not an id), so both are
        // needed.
        $this->map['location_by_name'] = DB::table('locations')->pluck('id', 'name')->all();
        $this->map['state_by_name'] = DB::table('states')->pluck('id', 'name')->all();
        $this->map['cat_by_name'] = DB::table('item_categories')->pluck('id', 'name')->all();

        /*
         * F&B'S OWN MAPS, PRELOADED FROM THE DATABASE.
         *
         * These were originally built only inside put(), as each table imported.
         * That works for a full run and breaks completely for `--only`: importing
         * items alone left map['uom'] empty, so all 371 rows resolved to NULL and
         * the run reported success. 1,226 orphaned rows across three tables before
         * anyone looked.
         *
         * Preloading from what is already stored means a single-table import
         * resolves against the real parents, and a full run still overwrites each
         * map as it goes. A miss is now a genuine missing parent rather than an
         * artefact of the flag.
         */
        foreach ([
            'fnb_uoms' => 'uom',
            'fnb_item_masters' => 'item',
            'fnb_warehouses' => 'warehouse',
            'fnb_inventories' => 'inventory',
            'fnb_recipe_masters' => 'recipe',
            'fnb_request_stock_for_foods' => 'request',
            'fnb_vendor_order_bookings' => 'order',
            'fnb_vendor_order_booking_items' => 'order_item',
            'fnb_transfer_items' => 'transfer',
            'fnb_monthly_checks' => 'monthly_check',
        ] as $table => $alias) {
            $this->map[$alias] = DB::table($table)
                ->whereNotNull('creator_id')
                ->pluck('id', 'creator_id')
                ->all();
        }
    }

    /*
     * ---- helpers ------------------------------------------------------------
     */

    /** An 18-digit Creator id as a string, or null. Never numeric. */
    private function id(mixed $v): ?string
    {
        if ($v === null || $v === '' || $v === '0') {
            return null;
        }

        return (string) $v;
    }

    /** `₹ 1,025.50` -> `1025.50`. A decimal STRING; no float ever touches money. */
    private function money(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        $s = preg_replace('/[^0-9.\-]/', '', (string) $v);

        return ($s === '' || $s === '-' || $s === '.') ? null : $s;
    }

    /** `31-Oct-2025 01:12:35` or `31-Oct-2025` -> a Y-m-d string, or null. */
    private function date(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        $t = strtotime((string) $v);

        return $t === false ? null : date('Y-m-d', $t);
    }

    private function timestamp(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        $t = strtotime((string) $v);

        return $t === false ? null : date('Y-m-d H:i:s', $t);
    }

    private function bool(mixed $v): bool
    {
        if (is_bool($v)) {
            return $v;
        }

        return in_array(strtolower(trim((string) $v)), ['true', 'yes', '1'], true);
    }

    /**
     * Resolve a lookup. A miss returns null AND is counted — it never invents the
     * master, and it never passes silently.
     */
    private function look(string $alias, mixed $creatorId, string $what): ?int
    {
        $id = $this->id($creatorId);
        if ($id === null) {
            return null;
        }

        $hit = $this->map[$alias][$id] ?? null;
        if ($hit === null) {
            $this->unresolved[$what] = ($this->unresolved[$what] ?? 0) + 1;
        }

        return $hit;
    }

    private function lookByName(string $alias, mixed $name, string $what): ?int
    {
        if ($name === null || trim((string) $name) === '') {
            return null;
        }

        $hit = $this->map[$alias][$name] ?? null;
        if ($hit === null) {
            $this->unresolved[$what] = ($this->unresolved[$what] ?? 0) + 1;
        }

        return $hit;
    }

    /** Insert in chunks and remember creator_id => local id for the children. */
    private function put(string $table, array $rows, string $alias): void
    {
        if ($rows === []) {
            $this->line('  nothing to write');

            return;
        }

        if ($this->dry) {
            $this->line('  would write '.number_format(count($rows))." rows to {$table}");
            $this->map[$alias] = [];

            return;
        }

        /*
         * DELETE, NOT TRUNCATE.
         *
         * TRUNCATE on a Postgres table with inbound foreign keys CASCADES. So
         * re-importing warehouses silently emptied fnb_inventories, and through it
         * fnb_inventory_stocks, and re-importing those emptied the stock ledger.
         * 299,070 rows became 4,304 across two runs, and every run reported
         * success — because an import counts what it writes and never what it
         * destroyed on the way in.
         *
         * DELETE refuses instead: a child row pointing at a parent raises a
         * foreign-key violation, which is the database saying the import order is
         * wrong rather than quietly discarding the children.
         *
         * A single-table re-import of a parent SHOULD fail while children exist —
         * they hold ids that are about to be reissued.
         */
        try {
            DB::table($table)->delete();
        } catch (Throwable $e) {
            $this->error(
                "  cannot replace {$table}: rows in another table still reference it. "
                .'Re-import its children afterwards, or run the full import without '
                .'--only so the dependency order is respected.'
            );
            $this->line('  '.explode("\n", $e->getMessage())[0]);

            return;
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table($table)->insert($chunk);
        }

        $this->map[$alias] = DB::table($table)
            ->whereNotNull('creator_id')
            ->pluck('id', 'creator_id')
            ->all();

        $this->info('  wrote '.number_format(count($rows))." rows to {$table}");
    }

    private function reportUnresolved(): void
    {
        $this->newLine();

        if ($this->unresolved === []) {
            $this->info('Every lookup resolved. No orphans.');

            return;
        }

        $this->warn('Unresolved lookups — left NULL, never invented:');
        ksort($this->unresolved);
        foreach ($this->unresolved as $what => $n) {
            $this->line(sprintf('  %-46s %s', $what, number_format($n)));
        }
    }
}
