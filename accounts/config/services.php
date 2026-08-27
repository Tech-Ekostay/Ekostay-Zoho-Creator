<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Zoho — Analytics (read) and Creator custom APIs (write)
    |--------------------------------------------------------------------------
    |
    | The config surface is taken from §9 of ZOHO_CREATOR_FIELD_NOTES.md, an
    | integrator's field notes compiled from six months against this same live
    | instance. Names only — every value comes from .env, which is git-ignored.
    |
    | TWO PLANES, NOT ONE (§1 of those notes). Analytics is READ-ONLY and LAGS
    | Creator; Creator custom APIs are the only write path and are authoritative.
    | A write is verified by re-POSTing and reading the response — NEVER by
    | re-reading Analytics, which is how the other app wasted a long time
    | concluding successful writes had failed.
    |
    | THE DATA CENTRE IS PART OF THE IDENTITY. `.in`, `.com` and `.eu` are
    | separate deployments and the same account does not exist across them. This
    | instance is India, so the defaults are `.in` — but they are explicit rather
    | than hardcoded, because a wrong DC fails as an auth error and reads like a
    | bad credential.
    */
    'zoho' => [
        'accounts_domain' => env('ZOHO_ACCOUNTS_DOMAIN', 'https://accounts.zoho.in'),
        'analytics_api' => env('ZOHO_ANALYTICS_API', 'https://analyticsapi.zoho.in'),

        'client_id' => env('ZOHO_CLIENT_ID'),
        'client_secret' => env('ZOHO_CLIENT_SECRET'),
        'refresh_token' => env('ZOHO_REFRESH_TOKEN'),
        /*
         * NOT SECRETS, so they carry real defaults — they are account identifiers,
         * and ZOHO_ANALYTICS_CONNECTION.md publishes them in a file whose whole
         * premise is that the secrets are absent from it. Only client_secret and
         * refresh_token are secret, and neither has a default.
         *
         * Forgetting the org id is documented as the SECOND most common setup
         * mistake (a bare 401 that does not say the org id is what is missing), so
         * defaulting it removes a whole class of wasted afternoon.
         */
        'org_id' => env('ZOHO_ORG_ID', '60042406851'),

        /*
         * TWO WORKSPACES, addressed by numeric id. `accounts` holds what this
         * rebuild cares about; `live` is bookings and CRM, which is another app's
         * domain. Views live in one or the other and the ids are not interchangeable.
         */
        'workspaces' => [
            'accounts' => env('ZOHO_WORKSPACE_ACCOUNTS', '443703000000062565'),
            'live' => env('ZOHO_WORKSPACE_LIVE', '443703000004950271'),
        ],

        // Which workspace an unqualified view name resolves against.
        'workspace' => env('ZOHO_WORKSPACE', 'accounts'),

        /*
         * Retry and polling limits, all three of them measured rather than
         * chosen (§3):
         *
         *  - a large view genuinely takes minutes, so poll 300 x 2s
         *  - ABANDONING A POLL EARLY IS HARMFUL: the job keeps running and keeps
         *    holding a slot. Early abandonment caused a slot pile-up that blocked
         *    every later export — one of that project's worst outages.
         *  - concurrency is limited ACCOUNT-WIDE (errorCode 8132), not per
         *    workspace, so unrelated syncs compete. 4 tries, 45s apart.
         *  - big exports fail intermittently with a bare `ERROR OCCURRED` under
         *    load; a fresh job usually works. 3 whole-job tries, 20s apart.
         */
        'poll_max' => (int) env('ZOHO_EXPORT_POLL_MAX', 300),
        'poll_interval' => (int) env('ZOHO_EXPORT_POLL_INTERVAL', 2),
        'job_tries' => (int) env('ZOHO_EXPORT_JOB_TRIES', 3),
        'job_backoff' => (int) env('ZOHO_EXPORT_JOB_BACKOFF', 20),
        'busy_tries' => (int) env('ZOHO_EXPORT_BUSY_TRIES', 4),
        'busy_backoff' => (int) env('ZOHO_EXPORT_BUSY_BACKOFF', 45),

        // Access tokens last ~1 hour; both documents cache for 50 minutes.
        'token_ttl' => (int) env('ZOHO_TOKEN_TTL', 3000),

        /*
         * THE EXPORT CONCURRENCY LIMIT IS SHARED WITH ANOTHER PRODUCTION APP.
         *
         * ZOHO_ANALYTICS_CONNECTION.md §7.1 is explicit: the limit is account-wide,
         * "not per application", and the expense tracker's jobs and ours compete for
         * the same slots. A collision once caused a TWO-DAY STALL of both apps' syncs.
         *
         * Their cron minutes are already taken and must not be reused:
         *
         *     :00      main sync
         *     :12 :42  bank reconciliation
         *     :24      COA
         *     :48      incentive
         *     03:33    settlement
         *
         * So nothing here may be scheduled on those minutes, and anything scheduled
         * at all has to be agreed with Tushar first. This list is config rather than
         * a comment so a scheduler can assert against it instead of a human
         * remembering — see the guard in ZohoViews::assertScheduleIsClear().
         */
        'foreign_cron_minutes' => [0, 12, 24, 42, 48],

        // `csv` for anything large — §7.4: loading a 114k-row view as JSON OOM'd
        // the other app's server. `json` stays the default for small views.
        'large_view_format' => env('ZOHO_LARGE_VIEW_FORMAT', 'csv'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
