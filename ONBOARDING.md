# Onboarding — Ekostay Platform

For a developer joining this repository. Read this before `composer install`; four
things here will otherwise cost you an afternoon each.

## 1. Two projects, one repo, and why

```
accounts/   Laravel 12 + React 19. Working. Real data.
fnb/        Not started. Its DS export and what has been read from it.
```

`F_B.ds` makes **47 cross-app calls into `accounts.*`** — vendors, item categories,
COA, billing cycles, payments. F&B scopes itself with `Master_Category.F_B`, a flag
that lives on the **Accounts** master. And the Accounts Bills form carries an F&B
lookup today. Neither app can be built against a stub of the other, so they share a
schema and a repo.

## 2. Read `accounts/CLAUDE.md` first — it is the operating manual

It carries the document-precedence order, the build state and the traps. The three
context docs at `accounts/*.md` are the specification. **Where the addendum and the
spec disagree, the addendum wins** — it is evidence-based, the spec is partly
inferred. Where a `[TODO]` appears, ask; several concern money movement.

**The one instruction that overrides taste:** replicate the Creator screens, do not
redesign them. An earlier attempt reorganised the IA into something "better" and was
rejected.

## 3. Four rules that look like bugs and are not

**Never trim a stored string.** `F&B STAFF MEDICAL EXPENSE ` is 26 characters with a
trailing space and it is a live lookup key. 328 vendor names carry edge whitespace,
two of them TAB characters. `bootstrap/app.php` exempts `api/settings/*` from
Laravel's global `TrimStrings` middleware by closure — **do not remove that while
tidying.** Normalise at display, never in data.

**Record ids are 18-digit strings, end to end.** `float()` silently corrupts them
(`…361075` → `…361100`). This has already happened once here.

**No float touches money.** `decimal(16,4)` columns, bcmath on fixed-scale decimal
strings, and §6.3's split rule truncates at paisa and puts the whole dropped
remainder on the LAST row. Reproduce it exactly; do not substitute banker's rounding.

**Billing cycles are never created on the fly.** §6.4: Creator INSERTs a missing
cycle during month derivation, and that defect put a junk `"9-2026"` row into live
accounting. Importing a cycle master from an export is fine; deriving one at save
time is not.

## 4. Setup

```bash
git clone https://github.com/Tech-Ekostay/Ekostay-Zoho-Creator.git
cd Ekostay-Zoho-Creator/accounts
composer install
npm install
cp .env.example .env
php artisan key:generate
```

Create two Postgres databases — `ekostay_accounts` and `ekostay_accounts_test`
(the second is configured in `phpunit.xml`). Both need `LC_COLLATE 'C'`.

```bash
php artisan migrate --seed
php artisan test          # 136 tests, 1003 assertions
php artisan serve --host=127.0.0.1 --port=8000
```

### Two toolchain traps, both real

**PHP may not be on PATH.** winget installs no shim:

```bash
export PATH="$LOCALAPPDATA/Microsoft/WinGet/Packages/PHP.PHP.8.4_Microsoft.Winget.Source_8wekyb3d8bbwe:$PATH"
```

**`npm run dev` cannot start** on Node 20.11.1 — Vite 7 wants 20.19+ and dies with
`crypto.hash is not a function`. So there is **no HMR**: run `npm run build` after
every edit under `resources/`. Upgrading Node is the real fix and would make the
verify-by-rendering loop far less painful.

Tests run against **Postgres, not sqlite** — the `villas.rent_type` CHECK is added
with `ALTER TABLE`, which sqlite cannot do, and that constraint is under test.

## 5. What is NOT in the repo, and what breaks without it

Excluded because git history is permanent:

| Path | Why | Effect on you |
|---|---|---|
| `accounts/.env` | Zoho `client_secret` + `refresh_token` | copy `.env.example`; ask for the Zoho values |
| `**/deluge/*.ds` | `Accounts.ds:22851` is a **live DoubleTick API key** | ask Husain; they are the authority on form layout |
| `master-data/Vendor_Master.csv` | 8,063 PANs, GST numbers, bank details | `VendorSeeder` **skips** — vendors empty |
| `master-data/All_Employee_Masters.csv` | 475 people: name, DOB, email, phone | `EmployeeSeeder` **skips** — employees empty |
| `accounts/storage/app/zoho/` | ~251 MB of exported production data | regenerable: `php artisan zoho:inspect <view>` |

Both seeders skip with an on-screen explanation rather than throwing, so
`migrate --seed` completes and the app boots with two empty tables. Everything else
seeds from the 12 non-PII exports that ARE committed.

The DS exports matter more than their size suggests: the Payment form's 130 fields
and the approval algorithm were both read out of `Accounts.ds`, and field ORDER comes
from its `row`/`column` attributes. Without them you are inferring layout.

## 6. Before you run a single Zoho export — read this

**The export concurrency limit is account-wide and shared with a LIVE PRODUCTION
APP.** Not per application. Ekostay's expense tracker (maintained by **Tushar**) runs
scheduled syncs against the same account, and a collision once **stalled both apps
for two days**.

Its cron minutes are taken: `:00` `:12` `:24` `:42` `:48`, plus `03:33`.
`ZohoViews::assertScheduleIsClear()` refuses those, with tests behind it — but the
guard cannot see Tushar's job table, so **agree any schedule with him directly.**

There are now potentially three parties competing for those slots. Two developers
each running an ad-hoc `zoho:inspect` is a new way to break someone else's
production sync, so say so in chat before you export.

Two more rules from the same source, both learned the hard way:

- **Never abandon a slow poll.** Giving up does not cancel the job; it keeps running
  and keeps holding a slot.
- **`all_payments` must never be bulk-exported.** It is a heavy-join QueryTable that
  times out after a ten-minute poll — and then holds the slot. It is flagged `avoid`
  and refused before a job is created.

The contracts are in `accounts/docs/ZOHO_ANALYTICS_CONNECTION.md` and
`accounts/docs/ZOHO_CREATOR_FIELD_NOTES.md`. Both are empirical, from six months in
production. Read §11 and §12 of the field notes before writing any importer.

## 7. Verify by rendering. Every time.

The v1 failure was ~4,000 lines of CSS written without once looking at it. **Do not
present UI you have not seen.** Drive it with Playwright and assert on rendered
output — row counts, footer text, header labels — not just a screenshot.

Most of the real defects on this project were found this way and not by review: a
payment-number collision with live data, booleans rendering as the literal text
`false` on 135 rows, a read-only checkbox swallowing a row click, and a split-legs
validation comparing against the wrong figure.

## 8. Where the work actually stands

**Works:** create a bill and split it across villa × cycle × category; create a
payment from a bill (§7.2) or directly; reverse a settled payment as an entry, never
a delete (§7.6); add/edit the five Settings masters; grid → detail → edit on every
built page.

**Does not:** there are **no status transitions**, so nothing moves through approval
to Paid. There is **no expense posting**, so the split legs are computed and never
reach a ledger. **18 of 27** nav screens are unbuilt. There is **no authorisation** —
the §3.3 matrix is extracted and tested but not wired to a gate, so every endpoint is
open, including 8,064 vendor records with PANs and bank details.

**The approval engine is half-built.** Tables and `ApprovalRouter` exist — the
routing algorithm is transcribed from `Accounts.ds:16054-16112`. The transitions,
routes and UI do not. It is **blocked on data, not code**: the Approval form's amount
bands and approver identities are in no export held here, and the router deliberately
refuses to route rather than reading a null band as zero.

Pick up from `accounts/CLAUDE.md`'s build state, not from this file — it is kept
current and this one is a starting point.
