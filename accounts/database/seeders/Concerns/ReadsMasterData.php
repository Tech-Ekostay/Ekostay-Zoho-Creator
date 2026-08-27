<?php

namespace Database\Seeders\Concerns;

use RuntimeException;

/**
 * Shared readers for the real Creator exports in master-data/.
 *
 * Every coercion here exists because of a fault the docs record:
 *
 *  - bool(): §15.2 caught a mapper comparing to the STRING "true" while the data
 *    held real booleans, so all 144 COA flags read false. This accepts both and
 *    treats null as false, which is what an unchecked Creator checkbox exports as.
 *
 *  - id(): 18-digit Creator record ids must stay strings. §15.2 caught them being
 *    silently corrupted by float() (…361075 -> …361100). Nothing here may cast an
 *    id to a number.
 *
 *  - text(): does NOT trim. `F&B STAFF MEDICAL EXPENSE ` has a trailing space and
 *    it is a live lookup key; eight villa names have leading spaces and three have
 *    doubled spaces. Normalising on import breaks joins that currently work
 *    (addendum §3). Only '' collapses to null.
 */
trait ReadsMasterData
{
    protected function masterData(string $file): array
    {
        $path = base_path('master-data/'.$file);

        if (! is_file($path)) {
            throw new RuntimeException("master-data file missing: {$file}");
        }

        $rows = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        // A couple of the exports wrap their rows in a single top-level key.
        if (is_array($rows) && ! array_is_list($rows) && count($rows) === 1) {
            $inner = reset($rows);
            if (is_array($inner)) {
                $rows = $inner;
            }
        }

        return $rows;
    }

    /**
     * Read a CSV export as a list of associative rows.
     *
     * Values are NOT trimmed, for the same reason text() does not trim: 12 villa
     * names carry a leading space and they are real records in the master, not
     * parse artefacts. Verified against All_Villas.csv 22-Aug-2026.
     */
    /**
     * Is this export present?
     *
     * Two of the nine exports are DELIBERATELY not in the repository because they
     * carry personal data — `Vendor_Master.csv` (8,063 PANs, GST registrations and
     * bank details) and `All_Employee_Masters.csv` (475 people's name, DOB, email
     * and phone). Git history is permanent, so they are excluded rather than
     * committed-and-later-removed.
     *
     * The consequence is that a fresh clone cannot seed those two tables, and the
     * seeder should SKIP with an explanation rather than throw and abandon the
     * other fifteen. A new developer getting a working app with two empty tables
     * and a clear message beats a stack trace on `migrate:fresh --seed`.
     */
    protected function masterDataExists(string $file): bool
    {
        return is_file(base_path('master-data/'.$file));
    }

    protected function masterDataCsv(string $file): array
    {
        $path = base_path('master-data/'.$file);

        if (! is_file($path)) {
            throw new RuntimeException("master-data file missing: {$file}");
        }

        $handle = fopen($path, 'r');
        $header = fgetcsv($handle);

        if ($header === false) {
            throw new RuntimeException("empty CSV: {$file}");
        }

        // strip a UTF-8 BOM off the first header cell
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);

        $rows = [];
        while (($line = fgetcsv($handle)) !== false) {
            if ($line === [null] || $line === []) {
                continue;
            }
            $rows[] = array_combine(
                $header,
                array_pad(array_slice($line, 0, count($header)), count($header), null)
            );
        }
        fclose($handle);

        return $rows;
    }

    /**
     * Read a CSV export POSITIONALLY, as a header list plus a list of row arrays.
     *
     * WHY THIS EXISTS: `masterDataCsv()` above keys rows by header name via
     * `array_combine`, which SILENTLY DROPS DUPLICATE HEADERS — the last
     * occurrence wins and the earlier ones vanish with no error. `Vendor_Master.csv`
     * carries the header `GST No.` three times, at positions 11, 17 and 18, holding
     * three different sets of values. Read by name, two of the three disappear and
     * 7 rows of GST data go missing without a trace.
     *
     * So: any export whose header repeats a label must be read through this, by
     * column index. Check `header` for duplicates before choosing between them.
     *
     * Values are not trimmed here either — 328 vendor names carry leading or
     * trailing whitespace and they are live lookup keys.
     *
     * @return array{header: list<string>, rows: list<list<string|null>>}
     */
    protected function masterDataCsvPositional(string $file): array
    {
        $path = base_path('master-data/'.$file);

        if (! is_file($path)) {
            throw new RuntimeException("master-data file missing: {$file}");
        }

        $handle = fopen($path, 'r');
        $header = fgetcsv($handle);

        if ($header === false) {
            throw new RuntimeException("empty CSV: {$file}");
        }

        $header[0] = preg_replace('/^ï»¿/', '', (string) $header[0]);
        $width = count($header);

        $rows = [];
        while (($line = fgetcsv($handle)) !== false) {
            if ($line === [null] || $line === []) {
                continue;
            }
            $rows[] = array_pad(array_slice($line, 0, $width), $width, null);
        }
        fclose($handle);

        return ['header' => $header, 'rows' => $rows];
    }

    /** Verbatim string, or null. Never trimmed — see the class docblock. */
    protected function text(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }

        $s = is_string($v) ? $v : (string) $v;

        return $s === '' ? null : $s;
    }

    /** An 18-digit Creator id or 19-digit Books id, as a string. Never numeric. */
    protected function id(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }

        if (is_float($v) || is_int($v)) {
            // Reaching here means an export changed shape and precision is already
            // at risk. Fail loudly rather than store a rounded id.
            throw new RuntimeException(
                'record id arrived as a number, not a string: '.var_export($v, true)
            );
        }

        return (string) $v;
    }

    /** Real booleans, Creator's string forms, and null-as-false. */
    protected function bool(mixed $v): bool
    {
        if (is_bool($v)) {
            return $v;
        }

        if ($v === null || $v === '') {
            return false;
        }

        if (is_string($v)) {
            return in_array(strtolower(trim($v)), ['true', 'yes', '1'], true);
        }

        return (bool) $v;
    }

    protected function decimal(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }

        return (string) $v;
    }
}
