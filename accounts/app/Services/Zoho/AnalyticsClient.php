<?php

declare(strict_types=1);

namespace App\Services\Zoho;

use Generator;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Zoho Analytics — the READ plane. Bulk export jobs, Analytics v2, India DC.
 *
 * Built against two documents in `docs/`, both empirical, both from production:
 *   - `ZOHO_ANALYTICS_CONNECTION.md` — the connection contract, view ids, and the
 *     operational rules that cost the expense-tracker team real incidents.
 *   - `ZOHO_CREATOR_FIELD_NOTES.md` — six months of measured behaviour and defects.
 *
 * Everything below marked as documented was observed in production there. It is
 * reproduced rather than re-derived, because re-deriving it means repeating the
 * outage that taught it.
 *
 * ---------------------------------------------------------------------------
 * READ-ONLY, STRUCTURALLY. Analytics is not a write path — Creator custom APIs are,
 * with a separate per-endpoint key. This class has no write method and must not grow
 * one.
 *
 * ANALYTICS LAGS THE SOURCE OF TRUTH. Both documents say so independently: it is a
 * reporting replica trailing Creator by minutes. So it can never confirm a write.
 * Anything needing read-your-writes reads the local database.
 *
 * ---------------------------------------------------------------------------
 * THE FOUR RULES THAT ARE NOT NEGOTIABLE, each one an incident:
 *
 *  1. THE CONCURRENCY LIMIT IS SHARED WITH ANOTHER LIVE APPLICATION. It is
 *     account-wide, "not per application" — the expense tracker's jobs and ours
 *     compete for the same slots, and a collision once stalled both apps for two
 *     days. `ASYNC_EXPORT_LIMIT_EXCEEDED` (8132) therefore backs off 45s and is
 *     never treated as a plain failure.
 *
 *  2. NEVER ABANDON A SLOW POLL. Giving up does not cancel the job; it keeps
 *     running and keeps holding a slot, starving everything else — including the
 *     other application. A poll timeout here is a hard error that tells you not to
 *     start another export, and is deliberately NOT retried.
 *
 *  3. RETRY THE WHOLE JOB, not the poll. Big exports fail intermittently under load
 *     with a bare `ERROR OCCURRED`; a fresh create→poll→download usually succeeds.
 *
 *  4. STREAM LARGE VIEWS AS CSV. Loading a 114k-row view as JSON OOM'd their
 *     server. Views flagged `large` in the registry go through the CSV path and are
 *     yielded row by row, never materialised.
 */
class AnalyticsClient
{
    /** @var array<string, mixed> */
    private array $config;

    public function __construct(?array $config = null)
    {
        $this->config = $config ?? config('services.zoho');

        /*
         * Only the two genuine secrets are required. `client_id` and `org_id` carry
         * real defaults because they are account identifiers, not credentials — the
         * connection guide publishes both in a document whose stated premise is that
         * the secrets are absent from it.
         */
        foreach (['client_id', 'client_secret', 'refresh_token', 'org_id'] as $required) {
            if (blank($this->config[$required] ?? null)) {
                throw new RuntimeException(
                    "Zoho Analytics is not configured: services.zoho.{$required} is empty. "
                    .'Set ZOHO_'.strtoupper($required).' in .env — never in config, which is '
                    .'committed. client_secret and refresh_token come from Tushar over a '
                    .'private channel; the connection guide deliberately omits them.'
                );
            }
        }
    }

    /**
     * Export a view and return all its rows.
     *
     * Use this for the small and medium views. For anything flagged `large` in the
     * registry, use `stream()` — this method refuses those rather than reproducing
     * the out-of-memory failure that made streaming necessary.
     *
     * @param  string  $view  a registry name (see ZohoViews) or a raw numeric view id
     * @param  string|null  $criteria  server-side filter. READ §10 of the field notes
     *                                 first: column names are the Analytics DISPLAY
     *                                 label verbatim, INCLUDING trailing punctuation
     *                                 (`"Payment No."` — with the period), in double
     *                                 quotes, while string literals take single
     *                                 quotes. A wrong name returns
     *                                 UNKNOWN_COLUMN_IN_FILTERCRITERIA (7330) with no
     *                                 hint which column was meant.
     * @return list<array<string, mixed>>
     */
    public function export(string $view, ?string $criteria = null, bool $force = false): array
    {
        $meta = ZohoViews::get($view);

        if (($meta['large'] ?? false) && ! $force) {
            throw new RuntimeException(sprintf(
                "View '%s' (%s) is flagged large. Loading it whole OOM'd the other team's "
                .'server, so use stream() instead — or pass force to override deliberately.',
                $view, $meta['label'],
            ));
        }

        return iterator_to_array($this->stream($view, $criteria, $force), false);
    }

    /**
     * Export a view and yield its rows one at a time.
     *
     * The default path for anything large. JSON is parsed whole because a JSON body
     * cannot be streamed usefully; CSV is read line by line off a temp file, which
     * is what makes the 221k-row view survivable.
     *
     * @return Generator<int, array<string, mixed>>
     */
    public function stream(string $view, ?string $criteria = null, bool $force = false): Generator
    {
        $meta = ZohoViews::get($view);

        /*
         * A view the registry says not to export. Refused BEFORE a job is created,
         * because the failure mode is not a quick error — it is a ten-minute poll
         * that ends holding an account-wide slot, which is rule 1's incident.
         */
        if (isset($meta['avoid']) && ! $force) {
            throw new RuntimeException(sprintf(
                "View '%s' (%s) should not be bulk-exported.\n\n%s\n\nPass force to override, "
                .'but understand the cost first.',
                $view, $meta['label'], $meta['avoid'],
            ));
        }

        // CSV for large views, JSON otherwise. §7.4.
        $format = ($meta['large'] ?? false)
            ? (string) $this->config['large_view_format']
            : 'json';

        $tries = max(1, (int) $this->config['job_tries']);

        for ($attempt = 1; $attempt <= $tries; $attempt++) {
            try {
                yield from $this->runExportJob($meta, $criteria, $format);

                return;
            } catch (TransientExportFailure $e) {
                if ($attempt === $tries) {
                    throw new RuntimeException(sprintf(
                        "Export of view '%s' (%s) failed %d times. Last: %s",
                        $view, $meta['label'], $tries, $e->getMessage(),
                    ), previous: $e);
                }

                Log::warning('zoho.export.transient', [
                    'view' => $view, 'attempt' => $attempt, 'error' => $e->getMessage(),
                ]);

                $this->wait((int) $this->config['job_backoff']);
            }
        }
    }

    /** @return Generator<int, array<string, mixed>> */
    private function runExportJob(array $meta, ?string $criteria, string $format): Generator
    {
        $workspaceId = ZohoViews::workspaceId($meta['workspace']);

        $jobId = $this->createJob($workspaceId, $meta, $criteria, $format);
        $downloadUrl = $this->pollJob($workspaceId, $jobId, $meta);

        yield from $format === 'csv'
            ? $this->downloadCsv($downloadUrl, $meta)
            : $this->downloadJson($downloadUrl, $meta);
    }

    /**
     * Every view in a workspace, by name — METADATA, so it costs NO EXPORT SLOT.
     *
     * This matters more than it looks. The export concurrency limit is account-wide and
     * shared with a live production application (§9), so the expensive question
     * "does Analytics carry a table for this module?" must not be answered by trying an
     * export and seeing what happens. `/restapi/v2/workspaces/{id}/views` is a plain
     * GET against the metadata API: no bulk job is created, nothing queues, and Tushar's
     * syncs are untouched.
     *
     * Added 28-Aug-2026 when the answer to "can we sync every module?" turned out to
     * depend entirely on which views exist, and guessing would have cost slots.
     *
     * @return list<array{id: string, name: string, type: string}>
     */
    public function views(string $workspace): array
    {
        $workspaceId = ZohoViews::workspaceId($workspace);

        $body = $this->decode(
            $this->request()->get($this->url("/restapi/v2/workspaces/{$workspaceId}/views"))->body(),
            "listing views in workspace {$workspace}",
        );

        $out = [];

        foreach (($body['data']['views'] ?? []) as $view) {
            $out[] = [
                // §15.2: ids stay STRINGS. A view id is 18 digits like every other
                // Creator/Analytics id and float() corrupts them.
                'id' => (string) ($view['viewId'] ?? ''),
                'name' => (string) ($view['viewName'] ?? ''),
                'type' => (string) ($view['viewType'] ?? ''),
            ];
        }

        usort($out, fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return $out;
    }

    /**
     * Step 1 — create the job. Retries only on 8132, which is queuing for a slot
     * rather than failing, and which competes with a live production application.
     */
    private function createJob(string $workspaceId, array $meta, ?string $criteria, string $format): string
    {
        $exportConfig = ['responseFormat' => $format];

        if ($criteria !== null) {
            $exportConfig['criteria'] = $criteria;
        }

        $tries = max(1, (int) $this->config['busy_tries']);

        for ($attempt = 1; $attempt <= $tries; $attempt++) {
            $response = $this->request()->get(
                $this->url("/restapi/v2/bulk/workspaces/{$workspaceId}/views/{$meta['id']}/data"),
                ['CONFIG' => json_encode($exportConfig, JSON_THROW_ON_ERROR)],
            );

            $body = $this->decode($response->body(), "create job for {$meta['label']}");
            $jobId = data_get($body, 'data.jobId');

            if ($jobId !== null) {
                return (string) $jobId;
            }

            $code = (int) data_get($body, 'data.errorCode', data_get($body, 'errorCode', 0));

            if ($code === 8132 && $attempt < $tries) {
                Log::info('zoho.export.slot_busy', [
                    'view' => $meta['label'], 'attempt' => $attempt,
                ]);
                $this->wait((int) $this->config['busy_backoff']);

                continue;
            }

            throw new TransientExportFailure(sprintf(
                'no jobId for %s (HTTP %d, errorCode %d): %s',
                $meta['label'], $response->status(), $code, mb_substr($response->body(), 0, 300),
            ));
        }

        throw new RuntimeException(sprintf(
            "Could not get an export slot for '%s' after %d attempts.\n"
            .'The limit is ACCOUNT-WIDE and shared with the expense tracker, so one of its '
            ."syncs may be holding it — its minutes are :00, :12, :24, :42, :48.\n"
            .'Check for an abandoned job before retrying; a stuck job holds its slot until '
            .'it finishes or is cancelled.',
            $meta['label'], $tries,
        ));
    }

    /** Step 2 — poll to completion. Returns the download URL. */
    private function pollJob(string $workspaceId, string $jobId, array $meta): string
    {
        $max = max(1, (int) $this->config['poll_max']);
        $interval = max(1, (int) $this->config['poll_interval']);

        for ($tick = 1; $tick <= $max; $tick++) {
            $response = $this->request()->get(
                $this->url("/restapi/v2/bulk/workspaces/{$workspaceId}/exportjobs/{$jobId}")
            );

            $body = $this->decode($response->body(), "poll job {$jobId}");
            $status = (string) data_get($body, 'data.jobStatus', '');
            $url = data_get($body, 'data.downloadUrl');

            if ($url !== null && $url !== '') {
                return (string) $url;
            }

            // Fast-fail. Polling a dead job to the timeout only holds the slot longer.
            if (preg_match('/fail|error/i', $status) === 1) {
                throw new TransientExportFailure(sprintf(
                    "job %s for %s reported status '%s'", $jobId, $meta['label'], $status,
                ));
            }

            $this->wait($interval);
        }

        /*
         * NOT retried, deliberately. A timeout means the job is very likely STILL
         * RUNNING and still holding a slot shared with a live production app. Firing
         * a second job now is precisely the pile-up that stalled both syncs for two
         * days. Surface it and let a human decide.
         */
        throw new RuntimeException(sprintf(
            "Export job %s for '%s' did not finish within %ds.\n\n"
            .'IT IS PROBABLY STILL RUNNING and still holding an account-wide concurrency slot '
            .'that the expense tracker also needs. Do NOT start another export until it '
            ."completes or is cancelled.\n\n"
            .'If this view times out consistently, it may be a heavy-join QueryTable — '
            .'`all_payments` is documented as doing exactly this. Rebuild from plain Tables.',
            $jobId, $meta['label'], $max * $interval,
        ));
    }

    /**
     * Step 3, JSON — both documented payload shapes: a bare array, or {"data":[...]}.
     *
     * @return Generator<int, array<string, mixed>>
     */
    private function downloadJson(string $url, array $meta): Generator
    {
        $response = $this->request()->get($this->absolute($url));

        if (! $response->successful()) {
            throw new TransientExportFailure(
                "download for {$meta['label']} returned HTTP ".$response->status()
            );
        }

        $body = $this->decode($response->body(), "download for {$meta['label']}");

        if (array_is_list($body)) {
            yield from $body;

            return;
        }

        if (isset($body['data']) && is_array($body['data'])) {
            yield from array_values($body['data']);

            return;
        }

        throw new RuntimeException(sprintf(
            'Unrecognised download shape for %s. Two are documented — a bare array and '
            .'{"data":[...]}. A third means the contract changed; do not guess at it. '
            .'Top-level keys: %s',
            $meta['label'],
            implode(', ', array_slice(array_keys($body), 0, 12)),
        ));
    }

    /**
     * Step 3, CSV — streamed to a temp file and parsed row by row.
     *
     * The BOM strip is required: the guide says so explicitly, and this project has
     * already been bitten by a UTF-8 BOM on the first header cell of a Creator CSV.
     *
     * VALUES ARE NOT TRIMMED and ids are never cast. This project's whole data
     * discipline depends on it: 326 vendor names carry edge whitespace, two end in
     * tabs, and an 18-digit id read as a number loses precision (…361075 becomes
     * …361100). Both documents warn about the id case independently.
     *
     * @return Generator<int, array<string, string|null>>
     */
    private function downloadCsv(string $url, array $meta): Generator
    {
        $temp = tempnam(sys_get_temp_dir(), 'zoho-');

        if ($temp === false) {
            throw new RuntimeException('could not create a temp file for the CSV export');
        }

        try {
            $response = $this->request()->sink($temp)->get($this->absolute($url));

            if (! $response->successful()) {
                throw new TransientExportFailure(
                    "CSV download for {$meta['label']} returned HTTP ".$response->status()
                );
            }

            $handle = fopen($temp, 'r');

            if ($handle === false) {
                throw new RuntimeException("could not read the downloaded CSV for {$meta['label']}");
            }

            try {
                $header = fgetcsv($handle);

                if ($header === false) {
                    throw new TransientExportFailure("empty CSV for {$meta['label']}");
                }

                $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
                $width = count($header);

                /*
                 * DUPLICATE HEADERS ARE REAL HERE. Vendor_Master.csv carries `GST No.`
                 * three times, and array_combine would silently keep only the last.
                 * Suffixing keeps all three reachable; the suffix is transport only.
                 */
                $keys = [];
                $seen = [];
                foreach ($header as $label) {
                    $label = (string) $label;
                    $seen[$label] = ($seen[$label] ?? 0) + 1;
                    $keys[] = $seen[$label] === 1 ? $label : $label.' ('.$seen[$label].')';
                }

                while (($line = fgetcsv($handle)) !== false) {
                    if ($line === [null] || $line === []) {
                        continue;
                    }

                    yield array_combine(
                        $keys,
                        array_pad(array_slice($line, 0, $width), $width, null),
                    );
                }
            } finally {
                fclose($handle);
            }
        } finally {
            @unlink($temp);
        }
    }

    /**
     * OAuth 2 refresh-token grant, cached 50 minutes.
     *
     * A revoked refresh token is an ALERT, not a retry — it can be revoked
     * server-side without warning, and retrying just burns rate limit. Note also
     * that revoking the SHARED credential takes down the expense tracker's
     * production sync, which is why the guide recommends a separate OAuth client
     * for this application.
     */
    private function accessToken(): string
    {
        return Cache::remember('zoho.analytics.access_token', (int) $this->config['token_ttl'], function (): string {
            $response = Http::asForm()->post($this->config['accounts_domain'].'/oauth/v2/token', [
                'grant_type' => 'refresh_token',
                'refresh_token' => $this->config['refresh_token'],
                'client_id' => $this->config['client_id'],
                'client_secret' => $this->config['client_secret'],
            ]);

            $token = data_get($response->json(), 'access_token');

            if (! is_string($token) || $token === '') {
                throw new RuntimeException(
                    'Zoho refused the refresh-token grant (HTTP '.$response->status().'): '
                    .mb_substr($response->body(), 0, 300)
                    ."\n\nThree things this is usually: a revoked refresh token (reauthorise — "
                    .'not retryable); the wrong data centre (`.in` here, and `.com` fails '
                    .'looking exactly like a bad credential); or a missing '
                    .'`ZohoAnalytics.data.read` scope.'
                );
            }

            return $token;
        });
    }

    /** BOTH headers, every call. Omitting the org id is a documented common 401. */
    private function request(): PendingRequest
    {
        return Http::withHeaders([
            'Authorization' => 'Zoho-oauthtoken '.$this->accessToken(),
            'ZANALYTICS-ORGID' => (string) $this->config['org_id'],
        ])->timeout(300)->connectTimeout(20);
    }

    /** The download URL may come back relative — prefix the API domain if so. */
    private function absolute(string $url): string
    {
        return str_starts_with($url, 'http') ? $url : $this->url('/'.ltrim($url, '/'));
    }

    private function url(string $path): string
    {
        return rtrim((string) $this->config['analytics_api'], '/').$path;
    }

    private function decode(string $body, string $context): array
    {
        $decoded = json_decode($body, true);

        if (! is_array($decoded)) {
            throw new TransientExportFailure(
                "non-JSON response on {$context}: ".mb_substr($body, 0, 300)
            );
        }

        return $decoded;
    }

    /** Extracted so tests exercise the retry logic without waiting. */
    protected function wait(int $seconds): void
    {
        sleep($seconds);
    }
}
