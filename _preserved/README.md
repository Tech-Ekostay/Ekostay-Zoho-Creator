# Preserved from the prior session — 27-Aug-2026

The React prototype that used to live in this directory was **discarded** in favour
of the `Tech-Ekostay/Ekostay-Zoho-Creator` monorepo, which is now checked out here.

The instruction was: *"Dont delete anything non code realted since we can use the ds
files for our logic and other memory things and .md files for business logic."* So no
documentation and no `.ds` file was deleted. This folder holds what survived, sorted
by whether it is still worth reading.

A full copy of the discarded prototype — code included — is at
`C:\xampp1\htdocs\zoho_replica_OLD_react_backup.zip` (11.6 MB).

---

## `prior_session_docs/` — still useful, the repo does not have these

| File | Why it survives |
|---|---|
| **`DB_FINDINGS.md`** | The only account of the **live MySQL server** (`server.ekostay.com`, `148.113.0.151`) and the expense tracker. Nothing in the repo mentions either. |
| **`Accounts_SCHEMA.md`** | 46 forms — fields, lookup filters, picklists, hardcoded literals — read out of the DS. |
| **`Accounts_PERMISSIONS.md`** | 17 Creator profiles, field-level detail. The repo derives permissions from the DS via `docs/parse_permissions.py`; this is the human-readable reading of the same thing. |
| **`OPEN_QUESTIONS.md`** | Decisions register: §A 8 closed, §B 9 answered from code, §C defects 49–54, §D credential inventory. |
| **`SESSION_HANDOFF.md`** | Narrative context for the whole prior session. Its §6/§7 (Creator behaviours to reproduce, and the deviations that earn an exception) still apply — they are statements about **Creator**, not about the discarded code. |
| **`SOURCE_PROVENANCE.md`** | ⚠️ Read before trusting either `.ds` file. Explains they were transcribed from chat paste, not exported. |

### Findings in `DB_FINDINGS.md` that are absent from the repo

Checked by grep, not assumed:

- **233 payment numbers shared by 494 rows** in production, with fresh duplicates
  dated 13-Aug. `EKS/Haewaya/12539` alone covers six villas/categories/dates.
- **The delete webhook has never succeeded** — Creator sends the literal
  `PUT_THE_ROTATED_TOKEN_HERE` while a real 48-char token is configured. 401s logged.
- **Zoho Analytics silently corrects `Luxery`→`Luxury`** but does *not* normalise item
  categories: `MAINTAINENCE` and `MAINTENANCE` coexist. So a DS export is not a
  complete description of what Analytics serves.
- **`acco_accounts.expenses` holds 63,335 rows from exactly two automated sources** —
  machine-written only, consistent with a sync landing table.
- **PT coverage is complete** — every location with payroll rows maps to a handled
  state (Maharashtra, Tamil Nadu, Goa).
- **The `Name- Locality` villa pattern is not a region marker** — 34 villas, not
  Goa-only (Goa 22, Lonavala 6, Alibaug 2, Karjat 2, Panvel 2). Parsing it to derive
  location would be wrong; join on `location_id`.

All gathered **read-only** under an explicit constraint from the user. No customer
names, phone numbers, bank details, UTRs or record-linked amounts were recorded.

---

## `superseded_by_repo/` — keep only for diffing

The repo has newer, longer versions. **Use the repo's.**

| File | Ours | Repo's |
|---|---|---|
| `ACCOUNTS_REBUILD_CONTEXT.md` | 1,346 | **1,370** |
| `UI_HANDOFF.md` | 202 | **311** |
| `README_prior_react_app.md` | — | describes the discarded app |

The repo also adds `ACCOUNTS_CONTEXT_ADDENDUM.md`, which our session never had, and
`accounts/CLAUDE.md` states the precedence: **addendum beats spec**, because the
addendum is evidence-based and the spec is partly inferred.

---

## `discarded_react_notes/` — history only

Notes describing the deleted React build: its auth model, its 73 Playwright tests,
its SQL schema, its payroll engine, and `STATUS.md`. Kept because they record
*reasoning*, not because anything here should be rebuilt.

One thing in them is still load-bearing: the **four payroll deviations** and the
acceptance case (Ahmed June **₹22,680 payable / ₹22,880 CTC**; July ₹24,800 /
₹25,000). Those are facts about Creator's arithmetic and they carry over.

---

## `payroll_engine/` — the one piece of code worth keeping

`payroll.js` + `payroll.test.js` (104 assertions, dependency-free CommonJS).

`accounts/CLAUDE.md` independently reaches the same conclusion about its own copy:

> **Exception: the payroll engine inside `SalaryPayoutsModule.jsx` is correct — keep that.**

It reproduces four Creator deviations rather than correcting them: negative HRA in the
₹21,001–21,099 band, ESIC on base pay instead of gross, EDLI charged twice, and PT on
prorated salary.

Two things not to lose when porting it to PHP:

- `r2()` rounds **half away from zero**.
- **`r2(1.005)` is `1.00`, not `1.01`** — 1.005 is stored as 1.00499999… in IEEE-754,
  and Deluge on the JVM hits the identical limit. A test expectation of `1.01` is
  wrong; the proof is inline in the test file.

Also note `accounts/CLAUDE.md`: payroll rates, bands and ceilings must land as
**versioned configuration rows with effective dates**, each payslip recording which
version produced it. Schedule Payments and Salary Payouts are **gated** on that.

---

## `prior_schema_sql/` — reference, do not run

Four PostgreSQL migrations (42 tables, 459 constraints) written for the discarded app.
**They were never executed against a database** — they parse against a real grammar and
pass 15 invariants, which is not the same thing.

The repo's `accounts/database/migrations/` is the real schema: migrated, seeded, and
covered by 136 tests against Postgres 17. Use that.

These are kept because they encode decisions worth re-reading, not code worth running:

- `UNIQUE (series_code, series_seq)` with `payment_no` deliberately **not** unique, so
  the 233 live collisions can be imported at all.
- Deferrable split-balance constraints — legs balance at **commit**, not per row.
- `CREATE DOMAIN money_inr AS NUMERIC(16,2)`, so precision changes in one place.
- `citext` on vendor names, which catches `Luxery`/`Luxury`.
- The `auth_*` tables: server-side sessions rather than JWT (an accounts app must be
  able to revoke immediately), storing a SHA-256 and never the token.

---

## `deluge/` — moved to `accounts/deluge/`

Both `.ds` files are now at `accounts/deluge/`, where the repo's layout expects them
and where `*.gitignore` correctly excludes them from commits.

⚠️ **Both are partial.** `Admin.ds` is 2,435 lines against a canonical 4,162 — 41%
missing — so it was renamed `Admin_PARTIAL_chat_transcription.ds`. Details and the
replacement plan are in `accounts/deluge/PRIOR_SESSION_FILES.md`.

**`F_B.ds` is absent** — and it is what this session needs. Neither the repo (which
git-ignores `*.ds`) nor the prior session (which read it from a paste and never saved
it) has the file. **It must be requested before F&B logic is built.**
