# Zoho Analytics — connection verified live, 27-Aug-2026

Credentials arrived and the read plane is **working end to end**. This records what
was measured, because two things in the codebase turned out to be wrong.

`docs/ZOHO_ANALYTICS_CONNECTION.md` is still not in the repo. Its **credentials** were
supplied and are in `.env` (git-ignored, verified with `git check-ignore` before
writing). Its prose was explicitly out of scope — the file describes the expense
tracker, not this app — so nothing else from it was copied here.

## What was proven

| Step | Result |
|---|---|
| Mint access token | ✅ HTTP 200, `expires_in=3600` |
| `GET /restapi/v2/workspaces` | ✅ HTTP 200, 7 workspaces |
| Both documented workspace ids present | ✅ `443703000000062565` accounts · `443703000004950271` live |
| Export the F&B view | ✅ **27,950 rows, 57 columns, 11.5s** |
| `ZohoViewsTest` | ✅ 11 tests, 120 assertions |

Nothing was written to Analytics. There is no write path in `AnalyticsClient` and it
must not grow one.

## Defect 1 — `fnb` was not flagged `large`, and OOM'd

The first `php artisan zoho:inspect fnb` died with:

```
Allowed memory size of 134217728 bytes exhausted
  at app/Services/Zoho/AnalyticsClient.php:457   json_decode($body, true)
```

The export itself **succeeded**; the crash was decoding it whole. This is precisely
the §7.4 failure the guide warns about — and it happened on a view nobody had run,
because `large` was absent from its registry entry.

**Fixed:** `'large' => true` on `fnb` in `ZohoViews.php`, with the measured numbers in
the note so the next person does not have to rediscover them. It now takes the CSV
streaming path like `bookings` and `booking_payment_type`.

Worth noting the failure mode: **a successful download that dies in the parser leaves
a zero-byte output file.** Two of those were created before the flag was fixed. An
empty `.ndjson` is not evidence the export failed.

## Defect 2 — the token cache required a database

The second run failed with:

```
SQLSTATE[08006] connection to server at "127.0.0.1", port 5432 failed
  SQL: select * from "cache" where "key" in (...zoho.analytics.access_token)
```

`CACHE_STORE=database` means the access-token cache is read **before any query**, so a
missing Postgres surfaces as what looks like an Analytics failure and is not one.

**Fixed:** `CACHE_STORE=file` and `SESSION_DRIVER=file` in `.env`, with a comment
saying why. The token cache does not need a database. Switch back once Postgres is up
if you prefer, but there is no reason to.

## Two environment fixes that were needed first

**No CA bundle.** Every outbound HTTPS call failed with `cURL error 60: unable to get
local issuer certificate`. This PHP build ships no `cacert.pem` and neither
`curl.cainfo` nor `openssl.cafile` was set. Copied XAMPP's
`apache/bin/curl-ca-bundle.crt` to the PHP directory and pointed both settings at it.

Use **forward slashes** in those `php.ini` paths. Backslashes were silently consumed
and `ini_get()` returned a mangled path with no separators at all.

**`pdo_pgsql` was disabled** — see `SETUP_NOTES_THIS_MACHINE.md`.

## The F&B view's 57 columns

Read them verbatim from `php artisan zoho:inspect fnb`. §11's point holds — key names
are per-view and unpredictable, and this view proves it:

- **`Payment No`** with no dot, while `Booking No.`, `Order No.`, `Vendor Order No.`
  and `Request Stock for Food No.` all carry one.
- **`Vendor Order No._1`, `Villa Name_1`** — Analytics' suffix for a repeated label,
  the same hazard as the three `GST No.` columns in `Vendor_Master.csv`. Positional
  reading, not by-name.
- **Money arrives pre-formatted as text** — `"₹ 300.00"`, not `300.00`. Parse it;
  never `float()` it into a money column.
- **`Payment Date` has a trailing space** in its values (`"31-Jul-2026 "`). The
  no-trim rule applies to values as well as lookup keys.
- **`Split Equally`, `Validate`, `Expense Updated`** render as `"Yes"`/`"No"`, not
  booleans — the same shape the guide notes for the CRM OCR view's `ok`.

## Before any scheduled export

The concurrency limit is **account-wide and shared with the expense tracker's
production sync**. A collision once stalled both apps for two days.
`ZohoViews::assertScheduleIsClear()` refuses their known minutes (`:00 :12 :24 :42
:48`) with 11 tests behind it, but **it cannot see their job table** — so any schedule
must still be agreed with Tushar.

Manual `zoho:inspect` runs are fine; they hold a slot for seconds.

## Housekeeping

The exported `.ndjson` files were **deleted**. They hold real PANs, GST numbers and
bank details. `storage/app/zoho/` is git-ignored and everything in it is regenerable
with `php artisan zoho:inspect <view>`.

Per the guide's §9, the credentials in use are the **expense tracker's shared client**.
Ask Tushar for a separate OAuth client for this app, scoped to
`ZohoAnalytics.data.read` — revoking a shared refresh token takes down their
production sync.
