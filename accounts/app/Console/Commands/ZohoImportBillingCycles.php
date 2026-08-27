<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\BillingCycle;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Import the real billing cycles from saved exports.
 *
 * THIS IS NOT THE §6.4 DEFECT, and the distinction is the whole point of this
 * docblock. §6.4 forbids Creator's behaviour of INSERTING a missing cycle **during
 * month derivation on a bill save** — that is what put a junk `"9-2026"` row into
 * live accounting, because a malformed month (`9` instead of `September`) was stored
 * literally and a cycle conjured to match it.
 *
 * Importing a master list from a source export is a different act: the cycles here
 * already exist in Creator, they are read from real records, and nothing is derived
 * from a user's input at save time. The rule that stays intact is the one that
 * matters — `BillController` and `PaymentController` still require a cycle to
 * pre-exist and will not create one.
 *
 * TWO SOURCES, because they disagree in shape:
 *   `payment_master`.`Billing Cycles`  comma-PACKED  (`May - 2026,June - 2026`)
 *   `expenses`.`Billing Month`         single value per row (it is leg-level)
 *
 * Both are read and unioned. The packed one is split — a parse, not a `split(',')`.
 *
 * `Feburary - 2026` IS IMPORTED AS SPELLED. It is a real live cycle with a real
 * misspelling, and it belongs on the preserve-spellings list. Correcting it here
 * would break every join against the rows that reference it, which is the no-trim
 * rule in a different costume.
 */
class ZohoImportBillingCycles extends Command
{
    protected $signature = 'zoho:import-billing-cycles {--dry-run}';

    protected $description = 'Import real billing cycles from the payment and expense exports.';

    /**
     * Month name -> index. `Feburary` is Creator's misspelling and maps to 2 so the
     * cycle sorts correctly; the NAME is still stored as spelled.
     */
    private const MONTHS = [
        'January' => 1, 'February' => 2, 'Feburary' => 2, 'March' => 3, 'April' => 4,
        'May' => 5, 'June' => 6, 'July' => 7, 'August' => 8, 'September' => 9,
        'October' => 10, 'November' => 11, 'December' => 12,
    ];

    /**
     * `Jul` -> `July`. Note `Feb` maps to the CORRECT spelling: the live misspelling
     * `Feburary` also abbreviates to `Feb`, so an abbreviated label cannot tell the
     * two apart and resolves to the correct one. The misspelled cycle still exists
     * as its own row from the dashed source, exactly as Creator holds it.
     */
    private const ABBREVIATIONS = [
        'Jan' => 'January', 'Feb' => 'February', 'Mar' => 'March', 'Apr' => 'April',
        'May' => 'May', 'Jun' => 'June', 'Jul' => 'July', 'Aug' => 'August',
        'Sep' => 'September', 'Oct' => 'October', 'Nov' => 'November', 'Dec' => 'December',
    ];

    public function handle(): int
    {
        $labels = [];

        foreach (['payment-master-*.ndjson' => 'Billing Cycles',
            'expenses-*.ndjson' => 'Billing Month'] as $glob => $column) {
            $path = collect(File::glob(storage_path('app/zoho/'.$glob)))
                // `expenses-*` also matches `expenses-bills-*`, which is a different
                // (and currently empty) view. Exclude it unless it IS the target.
                ->reject(fn ($f) => $glob !== 'expenses-bills-*.ndjson'
                    && str_contains(basename($f), 'expenses-bills-'))
                ->sortByDesc(fn ($f) => filemtime($f))->first();

            if ($path === null) {
                $this->warn("no export matching {$glob} — skipped");

                continue;
            }

            $before = count($labels);
            $handle = fopen($path, 'r');

            while (($line = fgets($handle)) !== false) {
                $row = json_decode(trim($line), true);
                if (! is_array($row)) {
                    continue;
                }

                $packed = (string) ($row[$column] ?? '');
                if ($packed === '') {
                    continue;
                }

                // A parse, not a split: the packed strings carry uneven spacing.
                foreach (explode(',', $packed) as $part) {
                    $label = trim($part);
                    if ($label !== '') {
                        $labels[$label] = true;
                    }
                }
            }
            fclose($handle);

            $this->line(sprintf('%-26s %s -> %d new label(s)',
                basename($path), $column, count($labels) - $before));
        }

        ksort($labels);
        $this->newLine();
        $this->line(sprintf('%d distinct cycle labels found.', count($labels)));

        $created = 0;
        $skipped = [];

        foreach (array_keys($labels) as $label) {
            /*
             * TWO SPELLINGS OF THE SAME CYCLE, and both are live:
             *
             *     payment_master.Billing Cycles   `July - 2026`
             *     expenses.Billing Month          `Jul 2026`
             *
             * Measured: zero of the expenses view's 56 labels match the dashed form.
             * §11 says field KEYS vary per view; this is the same instability in the
             * VALUES, and §12 of the field notes shows it too (`Mar 2026`). Accepting
             * one form only silently discarded 56 real labels as unparseable.
             */
            if (preg_match('/^([A-Za-z]+)\s*(?:-\s*)?(\d{4})$/', $label, $m) !== 1) {
                $skipped[] = $label;

                continue;
            }

            [, $month, $year] = $m;

            // Expand an abbreviation to the full month name. The FULL name is what
            // gets stored, so `Jul 2026` and `July - 2026` land on one row rather
            // than creating two cycles for one month.
            $month = self::ABBREVIATIONS[$month] ?? $month;

            if (! isset(self::MONTHS[$month])) {
                $skipped[] = $label;

                continue;
            }

            if ($this->option('dry-run')) {
                $created++;

                continue;
            }

            $cycle = BillingCycle::firstOrCreate(
                ['month_name' => $month, 'year' => $year],
                ['month_index' => self::MONTHS[$month]],
            );

            if ($cycle->wasRecentlyCreated) {
                $created++;
            }
        }

        $this->newLine();
        $this->info($this->option('dry-run') ? 'DRY RUN — nothing written.' : 'Done.');
        $this->line(sprintf('   cycles created   %d', $created));
        $this->line(sprintf('   cycles in table  %d', BillingCycle::query()->count()));

        if ($skipped !== []) {
            $this->newLine();
            $this->warn(sprintf('%d label(s) did not parse and were NOT guessed at:', count($skipped)));
            foreach (array_slice($skipped, 0, 10) as $s) {
                $this->warn('   '.var_export($s, true));
            }
        }

        if (! $this->option('dry-run')) {
            $odd = BillingCycle::query()->where('month_name', 'Feburary')->count();
            if ($odd > 0) {
                $this->newLine();
                $this->line('Note: `Feburary` is imported as spelled — a real live cycle with a real '
                    .'misspelling. Add it to the preserve-spellings list; correcting it would break '
                    .'every row that references it.');
            }
        }

        return self::SUCCESS;
    }
}
