<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use JsonException;

/**
 * Fidelity check for a Creator report export, run BEFORE it is trusted.
 *
 * Every rule here corresponds to a corruption this project has already been bitten
 * by, or to a value the docs say is load-bearing:
 *
 *  - 18-digit ids arriving as JSON numbers. §15.2 caught these being silently
 *    rounded (…361075 -> …361100), found only via a React duplicate-key warning.
 *    A spreadsheet round-trip does this every time.
 *  - Booleans arriving as the strings "true"/"false", or as 0/1. §15.2 caught a
 *    mapper comparing to the string while the data held real booleans, so all 144
 *    COA flags read false.
 *  - Significant whitespace stripped. `F&B STAFF MEDICAL EXPENSE ` has a trailing
 *    space and it is a live lookup key; villa names carry leading and doubled
 *    spaces. Addendum §3.
 *  - Scientific notation, which means a number went through a float.
 *
 * Usage: php artisan export:check path/to/export.json
 */
class CheckExport extends Command
{
    protected $signature = 'export:check {file : Path to the exported JSON}';

    protected $description = 'Check a Creator export for the corruptions that break this dataset';

    public function handle(): int
    {
        $path = $this->argument('file');

        if (! is_file($path)) {
            $this->error("No such file: {$path}");

            return self::FAILURE;
        }

        try {
            $data = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->error('Not valid JSON: '.$e->getMessage());
            $this->line('If this came out of a spreadsheet, re-export it as JSON — see the notes below.');

            return self::FAILURE;
        }

        $rows = $this->rows($data);

        if ($rows === []) {
            $this->error('No records found in the file.');

            return self::FAILURE;
        }

        $this->info(sprintf('%s — %d records', basename($path), count($rows)));
        $this->newLine();

        $problems = 0;
        $problems += $this->checkIds($rows);
        $problems += $this->checkBooleans($rows);
        $problems += $this->checkScientificNotation($rows);
        $this->reportWhitespace($rows);
        $this->reportColumns($rows);

        $this->newLine();

        if ($problems > 0) {
            $this->error("{$problems} problem(s) found — this export should not be trusted as-is.");

            return self::FAILURE;
        }

        $this->info('No corruption detected. Safe to add to master-data/.');

        return self::SUCCESS;
    }

    /** @return list<array<string,mixed>> */
    private function rows(mixed $data): array
    {
        if (! is_array($data)) {
            return [];
        }

        if (! array_is_list($data) && count($data) === 1) {
            $inner = reset($data);
            if (is_array($inner)) {
                $data = $inner;
            }
        }

        return array_values(array_filter($data, 'is_array'));
    }

    private function checkIds(array $rows): int
    {
        $suspect = [];

        foreach ($rows as $i => $row) {
            foreach ($row as $key => $value) {
                if (! $this->looksLikeIdColumn((string) $key)) {
                    continue;
                }

                if (is_int($value) || is_float($value)) {
                    $suspect[$key] ??= [];
                    $suspect[$key][] = $i;
                }
            }
        }

        if ($suspect === []) {
            $this->line('  <fg=green>OK</>   record ids are strings');

            return 0;
        }

        foreach ($suspect as $column => $indexes) {
            $this->line(sprintf(
                '  <fg=red>FAIL</> column "%s" holds NUMBERS on %d row(s) — 18-digit ids lose precision as numbers',
                $column,
                count($indexes),
            ));
        }

        return count($suspect);
    }

    private function looksLikeIdColumn(string $key): bool
    {
        $key = strtolower($key);

        return $key === 'id'
            || str_ends_with($key, ' id')
            || str_ends_with($key, '_id')
            || str_contains($key, 'books id');
    }

    private function checkBooleans(array $rows): int
    {
        $stringly = [];

        foreach ($rows as $row) {
            foreach ($row as $key => $value) {
                if (is_string($value) && in_array(strtolower(trim($value)), ['true', 'false'], true)) {
                    $stringly[$key] = ($stringly[$key] ?? 0) + 1;
                }
            }
        }

        if ($stringly === []) {
            $this->line('  <fg=green>OK</>   no booleans arrived as strings');

            return 0;
        }

        foreach ($stringly as $column => $count) {
            $this->line(sprintf(
                '  <fg=yellow>WARN</> column "%s" holds the STRING "true"/"false" on %d row(s) — expected real booleans',
                $column,
                $count,
            ));
        }

        return 0; // warn, not fail — the seeder copes, but it signals a lossy export
    }

    private function checkScientificNotation(array $rows): int
    {
        $hits = [];

        foreach ($rows as $row) {
            foreach ($row as $key => $value) {
                if (is_string($value) && preg_match('/^\d+(\.\d+)?[eE][+-]?\d+$/', trim($value))) {
                    $hits[$key] = ($hits[$key] ?? 0) + 1;
                }
            }
        }

        foreach ($hits as $column => $count) {
            $this->line(sprintf(
                '  <fg=red>FAIL</> column "%s" contains scientific notation on %d row(s) — a number went through a float',
                $column,
                $count,
            ));
        }

        if ($hits === []) {
            $this->line('  <fg=green>OK</>   no scientific notation');
        }

        return count($hits);
    }

    private function reportWhitespace(array $rows): void
    {
        $found = [];

        foreach ($rows as $row) {
            foreach ($row as $key => $value) {
                if (! is_string($value) || $value === '') {
                    continue;
                }

                if ($value !== trim($value) || str_contains($value, '  ')) {
                    $found[$key] = ($found[$key] ?? 0) + 1;
                }
            }
        }

        if ($found === []) {
            $this->line('  <fg=yellow>NOTE</> no significant whitespace found.');
            $this->line('       If this report has values you know carry leading/trailing or doubled');
            $this->line('       spaces, they were stripped — re-export without a spreadsheet step.');

            return;
        }

        foreach ($found as $column => $count) {
            $this->line(sprintf('  <fg=green>OK</>   column "%s" preserved significant whitespace on %d value(s)', $column, $count));
        }
    }

    private function reportColumns(array $rows): void
    {
        $columns = array_keys($rows[0]);
        $this->newLine();
        $this->line('  Columns, in export order (this mirrors the report exactly):');

        foreach ($columns as $i => $column) {
            $this->line(sprintf('    %2d. %s', $i + 1, $column));
        }

        $ragged = 0;
        foreach ($rows as $row) {
            if (array_keys($row) !== $columns) {
                $ragged++;
            }
        }

        if ($ragged > 0) {
            $this->line(sprintf('  <fg=yellow>WARN</> %d row(s) have a different column set to the first row', $ragged));
        }
    }
}
