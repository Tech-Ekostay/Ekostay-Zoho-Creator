<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Approval;
use App\Models\ApprovalLevel;
use App\Models\Employee;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Import the 16 approval rules and their 24 approver rows. Reads files; never calls Zoho.
 *
 * ---------------------------------------------------------------------------
 * WHAT THIS UNBLOCKS. `ApprovalRouter` refuses to route because "the amount bands and
 * approver identities are in the Approval form's subform grid and are in no export we
 * hold". `zoho:views` found the grid on 28-Aug-2026. After this runs, routing works
 * against the real rules.
 *
 * ---------------------------------------------------------------------------
 * THE EXPORT CONTRADICTED THREE THINGS THE SCREENSHOTS TAUGHT US, and each matters:
 *
 *  1. **The header fields are NOT all blank.** §11.9 read the report screenshot and
 *     concluded all 16 rules carry an empty `Level 1 & 2 Approval`, so `lvl12` is never
 *     `ALL` and Level 1 never participates in a two-level approval. The data says
 *     `Any` x8, blank x7, **`All` x1**. The conclusion survives only by accident: the
 *     one `All` rule (…08783118) has a single Level 1 band, so nothing can target
 *     Level 2 there and the flag is inert. A report column rendering blank is not the
 *     same as a field being empty.
 *
 *  2. **`Type` (Include/Exclude) IS in use.** §11.11 read one detail panel showing a
 *     radio with neither option chosen and called the switch unset. Across 16 rules:
 *     `Include` x5, `Exclude` x3, blank x8. **Half the rules scope by it**, so
 *     `matchRule()` declining to implement `scope_type` now has a measurable cost
 *     rather than a theoretical one.
 *
 *  3. **The ceiling is not uniform.** Most bands top out at 500,000,000 (Rs 50 crore)
 *     and one at 10,000,000 (Rs 1 crore). There is no single sentinel to special-case.
 *
 * And one thing it confirmed: `Module` is `Payment` on 15 rules and blank on one, which
 * is §11.3's "exactly one value, and the blank is simply unset".
 *
 * ---------------------------------------------------------------------------
 * BANDS THAT DO NOT START AT ZERO. Several rules begin at Rs 2,000 or Rs 3,001:
 *
 *     …07201046  Level 1  2,000 - 5,000     Level 2  5,001 - 50cr
 *     …08783118  Level 1  3,001 - 5,000
 *
 * So a Rs 1,500 payment on those rules matches NO band, `targetLevel` stays null, and
 * `expand()` returns an empty chain — which `ApprovalRouter` documents as "no approval
 * required", a real status in the live data. That is probably deliberate (small amounts
 * self-approve) and it is **not** the same as the whole-rupee gap §11.8 reports. Left
 * exactly as found; `bandWarnings()` will report it, and reporting it is correct.
 */
class ZohoImportApprovals extends Command
{
    protected $signature = 'zoho:import-approvals
                            {--rules= : the approval-*.ndjson export (not approval-approvers-*)}
                            {--grid= : the approval-approvers-*.ndjson export}
                            {--dry-run : report and write nothing}';

    protected $description = 'Import approval rules and the Approvers grid from saved exports.';

    public function handle(): int
    {
        $rulesFile = $this->option('rules') ?? $this->newest('approval-*.ndjson', 'approvers');
        $gridFile = $this->option('grid') ?? $this->newest('approval-approvers-*.ndjson');

        if ($rulesFile === null || $gridFile === null) {
            $this->error('Need both exports. Run: php artisan zoho:sync approvals');

            return self::FAILURE;
        }

        $this->line("rules: {$rulesFile}");
        $this->line("grid : {$gridFile}");

        $rules = $this->read($rulesFile);
        $grid = $this->read($gridFile);

        if ($rules === [] || $grid === []) {
            $this->error('One of the exports is empty. Refusing rather than importing half.');

            return self::FAILURE;
        }

        $this->line('');
        $this->line(sprintf('%d rules, %d grid rows.', count($rules), count($grid)));

        if ($this->option('dry-run')) {
            $this->report($rules, $grid);

            return self::SUCCESS;
        }

        $stats = DB::transaction(fn (): array => $this->import($rules, $grid));

        $this->line('');
        $this->info(sprintf(
            'Rules: %d created, %d updated. Levels: %d written, %d approvers resolved to an '
            .'employee, %d left as text only.',
            $stats['rules_created'], $stats['rules_updated'],
            $stats['levels'], $stats['resolved'], $stats['unresolved'],
        ));

        if ($stats['unresolved'] > 0) {
            $this->line('');
            $this->warn(
                'An unresolved approver is a FACT, not a gap (§18.1): the rule names someone '
                .'whose employee row we do not hold, and the raw `Name - email` string is kept '
                .'so the audit survives. It does mean routing cannot notify them.'
            );

            foreach ($stats['unresolved_names'] as $name) {
                $this->line('    '.$name);
            }
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<array<string, mixed>>  $rules
     * @param  list<array<string, mixed>>  $grid
     * @return array<string, mixed>
     */
    private function import(array $rules, array $grid): array
    {
        $stats = [
            'rules_created' => 0, 'rules_updated' => 0, 'levels' => 0,
            'resolved' => 0, 'unresolved' => 0, 'unresolved_names' => [],
        ];

        $byParent = [];

        foreach ($grid as $row) {
            $byParent[(string) ($row['PARENT_ID'] ?? '')][] = $row;
        }

        foreach ($rules as $rule) {
            $creatorId = trim((string) ($rule['ID'] ?? ''));

            if ($creatorId === '') {
                continue;
            }

            $existing = Approval::where('creator_id', $creatorId)->first();

            $approval = Approval::updateOrCreate(
                ['creator_id' => $creatorId],
                [
                    'module' => $this->text($rule['Module'] ?? null),
                    // Comma-packed and NOT split here. §3: the packed strings carry
                    // inconsistent spacing and splitting them is a parse, not a
                    // split(','). `Approval::coversLocation()` already parses them.
                    'locations' => $this->text($rule['Location'] ?? null),
                    'villa_names' => $this->text($rule['Villa Name'] ?? null),
                    'item_categories' => $this->text($rule['Item Category'] ?? null),
                    'exclude_categories' => $this->text($rule['Exclude Category'] ?? null),
                    // Include / Exclude, and set on 8 of 16 rules — see the docblock.
                    'scope_type' => $this->text($rule['Type'] ?? null),
                    'level_1_2_approval' => $this->text($rule['Level 1 & 2 Approval'] ?? null),
                    'level_2_3_approval' => $this->text($rule['Level 2 & 3 Approval'] ?? null),
                    'added_time' => $this->stamp($rule['Added Time'] ?? null),
                    'added_user' => $this->text($rule['Added User'] ?? null),
                    'modified_time' => $this->stamp($rule['Modified Time'] ?? null),
                    'modified_user' => $this->text($rule['Modified User'] ?? null),
                ]
            );

            $existing === null ? $stats['rules_created']++ : $stats['rules_updated']++;

            /*
             * The grid is REPLACED for this rule, not merged. A level is identified by
             * its Creator row id and nothing else, so a level deleted upstream must
             * disappear here — §6's "absence must never be inferred as deletion" is
             * about records vanishing from a FILTERED export, and this export is the
             * whole grid for the whole form.
             */
            $approval->levels()->delete();

            $position = 0;

            foreach ($byParent[$creatorId] ?? [] as $row) {
                [$employeeId, $raw] = $this->approver($row['Approver'] ?? null);

                if ($raw !== null) {
                    if ($employeeId !== null) {
                        $stats['resolved']++;
                    } else {
                        $stats['unresolved']++;
                        $stats['unresolved_names'][$raw] = $raw;
                    }
                }

                ApprovalLevel::create([
                    'approval_id' => $approval->id,
                    'creator_id' => trim((string) ($row['ID'] ?? '')) ?: null,
                    'creator_parent_id' => $creatorId,
                    'level' => $this->text($row['Level'] ?? null),
                    'minimum_amount' => $this->money($row['Minimum Amount'] ?? null),
                    'maximum_amount' => $this->money($row['Maximum Amount'] ?? null),
                    'approval_type' => $this->text($row['Approval Type'] ?? null),
                    'approver' => $raw,
                    'approver_employee_id' => $employeeId,
                    'position' => $position++,
                    'added_time' => $this->stamp($row['Added Time'] ?? null),
                    'added_user' => $this->text($row['Added User'] ?? null),
                    'modified_time' => $this->stamp($row['Modified Time'] ?? null),
                    'modified_user' => $this->text($row['Modified User'] ?? null),
                ]);

                $stats['levels']++;
            }
        }

        $stats['unresolved_names'] = array_values($stats['unresolved_names']);
        $stats['unresolved'] = count($stats['unresolved_names']);

        return $stats;
    }

    /**
     * `Varun Arora - varun@ekostay.com` -> [employeeId, raw].
     *
     * THE EMAIL IS THE JOIN KEY, not the name. §18's lesson is that names do not join —
     * 328 vendor names carry edge whitespace, two of them tabs — and §11.7 measured all
     * three approvers matching `employees.email` exactly. So the email is parsed out and
     * matched, and the raw string is returned regardless so nothing is lost.
     *
     * @return array{0: ?int, 1: ?string}
     */
    private function approver(mixed $value): array
    {
        $raw = $this->text($value);

        if ($raw === null) {
            return [null, null];
        }

        // Take the last `@`-bearing token rather than splitting on ' - ': a name may
        // contain a hyphen (`Casa Pino- Pilerne` is a villa, but people carry them too).
        if (! preg_match('/([^\s]+@[^\s]+)/', $raw, $m)) {
            return [null, $raw];
        }

        $email = rtrim($m[1], '.,;');

        $employee = Employee::whereRaw('lower(trim(email)) = ?', [mb_strtolower(trim($email))])->first();

        return [$employee?->id, $raw];
    }

    /** `Rs 500,000,000.00` / `5,001.00` -> a fixed-scale decimal string. No floats. */
    private function money(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        $clean = preg_replace('/[^\d.\-]/u', '', (string) $value);

        return ($clean === '' || $clean === '-') ? null : bcadd($clean, '0', 4);
    }

    private function text(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        // NOT trimmed. §3: edge whitespace is significant in this data and the packed
        // scope strings carry it deliberately.
        $s = (string) $value;

        return $s === '' ? null : $s;
    }

    /** `27-Jul-2026 01:09:28` — Creator's own format. */
    private function stamp(mixed $value): ?string
    {
        $s = trim((string) ($value ?? ''));

        if ($s === '') {
            return null;
        }

        foreach (['d-M-Y H:i:s', 'd-M-Y'] as $format) {
            try {
                return Carbon::createFromFormat($format, $s)->toDateTimeString();
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }

    /** @return list<array<string, mixed>> */
    private function read(string $path): array
    {
        $out = [];

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $decoded = json_decode($line, true);

            if (is_array($decoded)) {
                $out[] = $decoded;
            }
        }

        return $out;
    }

    private function newest(string $pattern, ?string $exclude = null): ?string
    {
        $files = glob(storage_path('app/zoho/'.$pattern)) ?: [];

        if ($exclude !== null) {
            // The glob `approval-*.ndjson` also matches `approval-approvers-*.ndjson`,
            // and the same trap once made an importer silently pick a 0-row export.
            $files = array_values(array_filter($files, fn ($f) => ! str_contains(basename($f), $exclude)));
        }

        usort($files, fn ($a, $b) => filemtime($b) <=> filemtime($a));

        return $files[0] ?? null;
    }

    /**
     * @param  list<array<string, mixed>>  $rules
     * @param  list<array<string, mixed>>  $grid
     */
    private function report(array $rules, array $grid): void
    {
        $scope = [];
        $lvl12 = [];

        foreach ($rules as $r) {
            $scope[trim((string) ($r['Type'] ?? '')) ?: '(blank)'] = ($scope[trim((string) ($r['Type'] ?? '')) ?: '(blank)'] ?? 0) + 1;
            $key = trim((string) ($r['Level 1 & 2 Approval'] ?? '')) ?: '(blank)';
            $lvl12[$key] = ($lvl12[$key] ?? 0) + 1;
        }

        $this->line('');
        $this->line('  Type (Include/Exclude): '.json_encode($scope));
        $this->line('  Level 1 & 2 Approval  : '.json_encode($lvl12));
        $this->line('');
        $this->line('  Dry run — nothing written.');
    }
}
