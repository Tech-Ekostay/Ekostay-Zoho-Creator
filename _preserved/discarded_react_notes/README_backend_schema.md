# Schema

PostgreSQL 16. Three migrations, **38 tables, 429 constraints, 49 indexes**.

```bash
node backend/schema/validate.mjs     # parses all three, checks 15 invariants
```

| File | |
|---|---|
| `001_core.sql` | domains, master data, employees, roles, vendors, bills |
| `002_payments.sql` | vendor payments, splits, bank reconciliation, expenses |
| `003_payroll.sql` | versioned payroll config, PT slabs, salary periods, payslips, schedules |

## What this has and has not been checked against

**Checked:** every statement parses against a real PostgreSQL grammar
(`pgsql-ast-parser`), and 15 invariants are asserted — no float money column,
`payment_no` deliberately non-unique, one active match line per direction, and so
on.

**Not checked:** this has never run against a live PostgreSQL. There is no
Postgres or Docker on this machine. A parser confirms syntax and structure; it
does not confirm that a `CHECK` expression is immutable enough for a constraint,
that a `GENERATED ALWAYS AS` expression is legal in context, or that a trigger
function compiles. **Run `psql -f` against a scratch database before trusting
these in production.**

Three constructs are lifted out before parsing because the library does not
implement them — measured, not assumed (`probe.cjs`):

```
CREATE DOMAIN · CREATE TRIGGER / CREATE CONSTRAINT TRIGGER · COMMENT ON CONSTRAINT
```

All valid PostgreSQL. Their syntax is pattern-checked instead. `IS DISTINCT FROM`
is rewritten to `<>` **for parsing only** — the migrations keep the correct
null-safe operator, since `a <> b` is NULL when either side is NULL and would let
a bad row through.

## The design rule

Creator's arithmetic is reproduced exactly. What the schema adds is **enforcement
of invariants Creator depends on but never states** — and each one is a rule the
current system breaks in production.

Where live data would violate a constraint, it is `DEFERRABLE` or a trigger with a
recorded exception. Never dropped: a constraint you cannot import against is a
constraint someone will disable.

## Five findings this schema is built around

### 1. 🔴 Payment numbers collide, today

Verified on `serv_ekostay_expense.payouts`, 13-Aug-2026:

```
233 payment numbers shared by 494 rows.  229 of them Haewaya.
```

They are **not** split legs of one payment. `EKS/Haewaya/12539` covers six
different villas, six categories and six dates spanning two weeks — six unrelated
payments wearing one number. Fresh duplicates are dated 08, 10, 12 and
**13-Aug-2026**.

This is why Creator added `Sync_Locks` as a mutex, and why the mutex does not
help: it guards one `transaction_id`, not the counter.

So `payment_no` **cannot** be unique — a plain `UNIQUE` would reject 494 live
rows. Instead: a sequence per series, `UNIQUE (series_code, series_seq)` allocated
by the database, and the display number kept verbatim and non-unique for
traceability. Collision becomes impossible going forward without rewriting
history.

### 2. Two different Payable formulas, two different names

`bills.payable_amount` subtracts `paid_amount`. `vendor_payments.payable_amount`
does not. Both are correct for their own screen; verified against 16,405 live
rows, where 16,285 have payable equal to invoice and the rest differ by exactly
TDS.

Same label in Creator, distinct columns here. Three screens showing one label and
three numbers is how reconciliation errors start.

### 3. The delete sweep becomes an upsert

Creator regenerates expense rows by setting `Bill_Available = false` on all of
them, rebuilding, then **deleting whatever is still false** (defect 30). A sweep
keyed on a boolean: if regeneration fails midway, rows are gone.

Here the leg identity — parent × villa × cycle × category — is a unique index, so
regeneration is an upsert, and removal is `is_active = false`. A failed
regeneration leaves the previous state intact.

### 4. Payroll config is versioned, and v1 is deliberately unpublished

Every rate, band, ceiling and basis is a row with an effective date, and each
payslip stores `payroll_config_id`. So a 2026 payslip stays reproducible after a
2027 rate change — which Creator cannot manage, because §11.5 shows Ahmed's June
payout computed on a total of ₹23,200 while his header now reads ₹25,000, with no
record of the terms.

`payroll_configs` carries this:

```sql
CONSTRAINT payroll_band_gap_must_be_closed_on_publish CHECK (
  published_at IS NULL OR basic_high <= basic_band_low
)
```

The as-built config **fails** that check — `basic_high` 21,100 exceeds
`basic_band_low` 21,000, which is exactly the 99-rupee window where base pay
exceeds total and HRA goes negative. So v1 is stored **unpublished**. The defect
is recorded, reproduced faithfully by the engine, and cannot be silently inherited
by a new version. Closing it becomes a deliberate act with a date attached.

### 5. The invariant Creator repairs after the fact

`resolveDuplicateLines()` exists to clean up double-allocated bank match lines
*after* they happen. A partial unique index makes them impossible:

```sql
CREATE UNIQUE INDEX bml_one_active_per_direction
  ON bank_match_lines(payment_id, direction) WHERE is_active;
```

Released lines (`is_active = false`) do not collide, so unmatch-and-rematch still
works. A second deferred trigger stops a transaction being over-allocated.

## Naming changes, and why

| Creator | Here | Why |
|---|---|---|
| `payments` | `vendor_payments` | two other `payments` tables exist on that host; neither is this one |
| `COA.Hide` | `chart_of_accounts.selectable` | the flag is misnamed, not inverted — live `coa_type` shows every type reaching the picker |
| `No_Of_Days_Not_Worked` | `days_worked` | label and arithmetic both mean days *worked*; the maths is untouched |
| `Active` + `Status` + `Hide_From_Payments` | `is_active` + `hidden_from_payments` | only the third is read by any code |
| `Villa.Category` | `category` + generated `category_alias` | Creator stores `Luxery`; Analytics serves `Luxury`. Store both, join on ids |

`CTC` keeps its name with a comment recording that it is not cost-to-company —
it is built up from payable with employer contributions and recoveries added back.
Rename it in a view, not in the column.

## Still to do

1. **Run it against a real PostgreSQL.** The one gap in this work.
2. **Import scripts** — `creator_id` is on every table for exactly this. The
   ordering that matters: master data → vendors/employees → bills → payments →
   splits → match lines, with `SET CONSTRAINTS ALL DEFERRED` for the balance
   checks and exceptions recorded in `payment_split_exceptions`.
3. **The API layer**, then swap the modules' fixtures for real queries.
4. **`payroll_configs` → the engine.** `payroll/payroll.js` currently holds
   `CONFIG_V1` as a literal. It should load from this table and keep the literal
   only as the test fixture.
