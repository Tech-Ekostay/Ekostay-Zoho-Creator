# Setup on this machine — 27-Aug-2026

`CLAUDE.md`'s toolchain table describes the machine it was written on (22-Aug-2026).
This one differs, and three of the differences are improvements. Recorded here rather
than edited into `CLAUDE.md`, so the original observations stay intact.

| Tool | CLAUDE.md says | Here | Effect |
|---|---|---|---|
| PHP | 8.4.24, winget | **8.5.6**, `C:\Users\admin\Downloads\php-8.5.6-Win32-vs17-x64` | different install — see below |
| Composer | 2.10.2 | **2.9.7** | `composer install` succeeded, 81 packages |
| Node | 20.11.1 | **24.14.0** | **`npm run dev` works** — HMR is available |
| npm | 10.2.4 | **11.9.0** | |
| PostgreSQL | 17.11 | installed 27-Aug via winget | same version |
| Laravel | 12.67.0 | 12.67.0 | matches |

## The PATH line in the docs does not apply here

`CLAUDE.md` and `ONBOARDING.md` both open with:

```bash
export PATH="$LOCALAPPDATA/Microsoft/WinGet/Packages/PHP.PHP.8.4_Microsoft.Winget.Source_8wekyb3d8bbwe:$PATH"
```

**That directory does not exist on this machine.** PHP here is a standalone 8.5.6
build already on PATH, so `php` resolves with no export at all. Harmless to run the
line — it prepends a non-existent directory and PATH lookup falls through — but it is
not doing anything.

Note there is also XAMPP PHP 8.0.30 at `C:\xampp1\php\php.exe`. **Too old for Laravel
12** (needs 8.2+). Do not point the project at it.

## `npm run dev` is not blocked here

`CLAUDE.md` says it "cannot start" on Node 20.11.1 — Vite 7 wants 20.19+ and dies with
`crypto.hash is not a function`. On Node 24.14.0 it starts in ~500ms. Verified.

So the verify-by-rendering loop does **not** need `npm run build` after every edit on
this machine. `npm run build` also works (40 modules, ~1.1s) if you prefer it.

## Two things that had to be fixed to run a fresh clone

### 1. `.env.example` was never committed

`ONBOARDING.md` step 1 says `cp .env.example .env`. The file is not in the repo, and
the cause is a `.gitignore` collision:

```
.gitignore:12            !.env.example      un-ignores it
accounts/.gitignore:33   /.env.*            re-ignores it
```

The nested rule wins, so the template never made it into a commit and a fresh clone
cannot follow its own setup instructions. **Reconstructed** at `accounts/.env.example`
from `config/*.php` and the two Zoho docs. It holds placeholders only — no secret.

Worth fixing properly in `accounts/.gitignore` (an explicit `!.env.example` after
line 33), otherwise the next clone hits this again.

### 2. `pdo_pgsql` was not enabled

The DLLs ship with the PHP build but both lines are commented out. Uncommented in
`php.ini` (a timestamped `.bak` sits beside it):

```
extension=pdo_pgsql
extension=pgsql
```

Without this, `php artisan migrate` fails with "could not find driver" — which reads
like a database problem and is not one.

## Zoho credentials are absent, deliberately

`.env` carries empty `ZOHO_ANALYTICS_CLIENT_SECRET` and `ZOHO_ANALYTICS_REFRESH_TOKEN`.
Nothing that reads Analytics will work until they are filled.

Per `docs/ZOHO_ANALYTICS_CONNECTION.md` §9, **ask for a new OAuth client for this app**
rather than reusing the expense tracker's: revoking a shared token takes down that
app's production sync. Scope it to `ZohoAnalytics.data.read`.

Two constraints from `CLAUDE.md` that outlive any credential:

- **Analytics is read-only and it LAGS Creator.** A write can never be verified by
  re-reading Analytics.
- **The export concurrency limit is account-wide, shared with a live production app.**
  A collision once stalled both apps' syncs for two days. Any schedule must be agreed
  with Tushar — `ZohoViews::assertScheduleIsClear()` cannot see their job table.
