# PostgreSQL on this machine — installed 28-Aug-2026

`winget install PostgreSQL.PostgreSQL.17` **fails**: EnterpriseDB returns HTTP 403
for the `.exe` installer, both for the pinned `17.11-1` and for current. Verified
with a direct `curl -I`.

**The ZIP archive of the same version returns 200.** So the binaries were fetched
and a cluster initialised by hand. Same PostgreSQL 17.11 the toolchain table names.

```
binaries   C:\pgsql_dl\x\pgsql\bin
data dir   C:\pgsql_dl\data
log        C:\pgsql_dl\pg.log
port       5432
```

## Start and stop

There is **no Windows service** — a ZIP install registers none. It must be started
by hand after a reboot:

```bash
export PGBIN="/c/pgsql_dl/x/pgsql/bin"
"$PGBIN/pg_ctl.exe" -D "C:/pgsql_dl/data" -l "C:/pgsql_dl/pg.log" -o "-p 5432" start
"$PGBIN/pg_isready.exe" -h 127.0.0.1 -p 5432        # -> accepting connections
"$PGBIN/pg_ctl.exe" -D "C:/pgsql_dl/data" stop
```

`pg_ctl start` returns but does not exit on Windows — it holds the terminal. Run it
and move on; `pg_isready` is the real check.

## Roles and databases, as CLAUDE.md specifies

Both owned by `ekostay`, both `LC_COLLATE 'C'`. Verified in `pg_database`.

| | |
|---|---|
| superuser | `postgres` / `postgres` |
| app role | `ekostay` / `ekostay` (`CREATEDB`) |
| dev db | `ekostay_accounts` |
| test db | `ekostay_accounts_test` (pinned in `phpunit.xml`) |

Local trust auth, loopback only. Fine for a dev box; **not** a template for anything
exposed.

## Two extraction traps

**`Expand-Archive` was interrupted by a timeout and left `pgsql/share/` empty** while
`bin/` looked complete. `initdb` then failed with `postgres.bki does not exist`,
which reads like a corrupt download and was not one. The ZIP was fine — 21,903
entries, `postgres.bki` present. Re-extracted just that subtree (1,353 files).

If `initdb` reports a missing file under `share/`, check the extraction before
re-downloading 325 MB.

**`initdb --pwfile` needs a real file.** A bash process substitution (`<(echo …)`)
is not a path Windows can open.

## Result

```
php artisan migrate --seed     # 30 migrations, 44 tables
php artisan db:show            # PostgreSQL 17.11, ekostay_accounts
php artisan test               # 116 passed, 20 failed — see below
```

Seeded from the real exports: **254 villas · 144 COA · 135 item categories · 122
permissions · 185 permission_role · 35 TDS · 29 locations · 25 roles · 10 master
categories · 8 taxes · 8 states · 2 CA masters**.

The trailing-space lookup key survives the whole round trip — the API returns
`"F&B STAFF MEDICAL EXPENSE "` at **26 characters**, untrimmed.

### The 20 failing tests are the two PII files, not defects

`VendorSeeder` and `EmployeeSeeder` **skip with an explicit message** because
`master-data/Vendor_Master.csv` (8,063 vendors: PANs, GST, bank details) and
`master-data/All_Employee_Masters.csv` (475 people) are git-ignored on purpose.

`vendors` and `employees` are created and empty. Every failure names one of them —
`it seeds every vendor record`, `only active employees hold permissions`, and so on.
Drop both CSVs into `master-data/` and re-seed to reach the documented 136 tests /
1003 assertions.

## One more thing that bit

A stale **`public/hot`** from an earlier `npx vite --port 5199` experiment made Blade
point every asset at a dev server that was no longer running: HTTP 200, valid HTML,
**empty `#app`**, three `ERR_CONNECTION_REFUSED` in the console. The same failure
shape CLAUDE.md records for the missing `createRoot`.

`rm public/hot` after any manual Vite run that you do not shut down cleanly.

---

## Fully seeded, 29-Aug-2026 — 136 tests, 1008 assertions, all green

The two PII exports arrived and the suite reaches its documented state.

| | |
|---|---|
| vendors | **8,161** (was 8,063 on 24-Aug) |
| employees | **475** |
| villas | 254 · locations 30 · COA 144 · item categories 135 |

### Take the FORM export, not the report

Three vendor files arrived. Only one was usable:

| File | Columns | |
|---|---|---|
| `All Vendor Masters (6).csv` | 13 | report view — rejected |
| `All Vendor Masters (7).csv` | 13 | report view — rejected |
| **`Vendor Master.csv`** | **21** | **form export — correct** |

`VendorSeeder` **refused** the 13-column files rather than importing them, because it
maps positionally — the header repeats `GST No.` three times, so by-name reading is
impossible. That refusal was the design working: a silent import would have left eight
columns empty, including `Primary Vendor` and `Primary Status`, which are how merges
resolve.

The report view omits: Primary Vendor · Primary Status · Employee Designation ·
Employee · GST No. ×2 · Modified User · Modified Time.

### The counts moved; the invariants did not

11 + 9 + 4 snapshot counts were re-measured. They are snapshots of live data, not rules,
and the export is five days newer — **83 vendors were added after 24-Aug**. Nothing
broke. Every structural assertion held unchanged:

- pointer and target sets stay **mutually exclusive** — 0 rows hold both
- merges never resolve through `main_primary`
- edge whitespace survives (324 rows, two of them TABs)
- ids stay 18-character strings
- where `gst_no_1` and `gst_no_2` are both set they **agree**, on all 21

Two figures moved for a second reason. `gst_no_1` went **7 → 21** and distinct names
**7,985 → 8,083**: the earlier import came from a report export whose repeated `GST No.`
header made `array_combine` drop two columns and 7 rows. The form export has all three.

**If these fail after a future re-export, re-measure before assuming a defect.** The
rationale is written into the test file above `it_seeds_every_vendor_record`.

### `.gitignore` needed three passes for one file

The PII patterns matched `Vendor_Master.csv` but not the names the files actually
arrived under. Each was caught by checking `git check-ignore` after every copy:

1. `All Vendor Masters (6).csv` — Creator's report name plus a download counter
2. `Vendor_Master.csv.NEEDS_REMAP` — my own temporary rename
3. `Vendor Master.csv` — the form export, a **space** instead of an underscore

8,161 vendors with PANs, GST numbers and bank details were committable each time.
Patterns are now `Vendor Master*.csv`, `Vendor_Master.csv*`, `All Vendor Masters*.csv`
and the employee equivalents. **Check `git check-ignore` after dropping any data file
in — do not assume the existing pattern covers it.**
