# Ekostay Accounts — rebuild

Replacing the Zoho Creator **Accounts** app. Creator has hit platform limits;
this is the successor.

## Run it

```bash
npm install
npm run dev            # → http://localhost:5173
npm run test:payroll   # → 104 passed, 0 failed
```

`Alt+1` … `Alt+6` switches modules while the grid keeps focus.

## What's here

```
index.html  src/               Vite shell — a 30px dev strip and nothing else
*.jsx                          the six modules, at the root, unchanged
payroll/                       extracted payroll engine + 104 tests
schedule/                      instalment generator tests (30)
backend/schema/                PostgreSQL migrations — 38 tables, 429 constraints
zoho_source/                   the Creator source, transcribed
tools/capture.mjs              renders every module and screenshots it
SESSION_HANDOFF.md             full context for a new session   ← start here
STATUS.md                      what is built, verified, and left
OPEN_QUESTIONS.md              what's decided, what isn't, who can answer
DB_FINDINGS.md                 live-server inspection — schema corrections
ACCOUNTS_REBUILD_CONTEXT.md    the functional spec (from the 08-Aug export)
UI_HANDOFF.md                  the UI brief
```

### The shell adds no chrome

Each module already renders its own complete Creator frame — navy rail, app bar,
report bar, search chip. That frame **is** the deliverable, so the shell must not
compete with it.

A first attempt added a second rail and a second avatar; it cost 104px of grid
width and put two user menus on screen. Replaced with a thin dark strip. The
strip is a harness, not part of the app.

### Module status

**All six are Creator replicas** as of 13-Aug-2026.

| Module | Lines | |
|---|---|---|
| Bills | 977 | ✅ |
| **Payments** | **1,666** | ✅ rebuilt 13-Aug |
| **Schedule Payments** | **1,550** | ✅ rebuilt 13-Aug |
| **Salary Payouts** | **1,445** | ✅ rebuilt 13-Aug — imports the engine |
| Vendor Master | 942 | ✅ |
| Backend Expenses | 584 | ✅ |

They match the Creator reports: verbatim labels (`Main Primary`,
`multipe_hccc_names`), preserved spellings (`Payment InProgress`, `Maintaince`,
`ACCOMODATION`), monospace tabular figures, 27px rows, ~22 visible.

### Payments — what the rebuild preserves

22 columns in Creator's order. Beyond the layout:

- **Payable = Invoice − TDS**, with no `Paid_Amount` term. Bills subtracts it;
  Payment does not. Both correct for their own screen — now named distinctly in
  the schema so nobody reconciles the two expecting a match.
- **`Payment_Status = "Open"`** is written by `Create_Payment` but is not in the
  declared picklist. Kept, rendered with a dotted underline and a tooltip.
- **Both `"Sent for Approval"` and `"Send for Approval"`** exist. Both kept.
- **COA picker filters `COA[Hide == true]`** — the inverse of Bills. Kept, noted
  on the field.
- **Gross renders at three decimals, Payable at two**, on the same split row.
- **The salary path** swaps TDS for PF/PT/ESIC and assigns the same expression to
  both Invoice and Payable — reproduced including the redundancy.
- **Split rows reconcile rather than clear** when villa / category / cycle change,
  so typed amounts survive. The one addition `UI_HANDOFF.md` §2 accepts.

Two deliberate departures, both required by §7:

1. **`Delete Paid Payment` routes to a reversal.** Creator's label stays in the
   More menu so the report matches, but it creates a linked entry with negated
   amounts and a required reason. The original keeps its number and its bank
   match history. 17 payments (₹93,884) were destroyed by the original.
2. **The split imbalance is shown live.** Creator only blocks outside Draft and
   outside Accounts Payable (§7.4), and that weaker rule is reproduced exactly —
   but a running `₹ x of ₹ y` tally makes the gap visible while typing instead of
   only at submit.

### Schedule Payments — what the rebuild preserves

Three sections under one nav item, as Creator has them: the templates, the
instalments grouped by due date, and a cross-link to Salary Payouts.

```
node schedule/schedule.test.cjs     →  30 passed, 0 failed
```

The generator is the risky part, so it is tested independently of the UI:

- **Month-end clamping.** Day 31 → 30 in 30-day months, → 28 in February. Source
  clamps February **unconditionally**, so a 29th-due schedule still lands on
  28-Feb in a leap year. Copied as built and pinned by test.
- **Clamping can pull a due date *into* the window.** A 31-Jan→30-Jun window with
  a 31st due date yields **6** instalments, not 5, because June's 31st clamps to
  the 30th — which is the window edge. Easy to get wrong; my first test
  expectation was wrong here, not the code.
- **Billing cycle = the month before the due date.** Source resolves this through
  a 24-line if/else chain that appears at least twice; replaced with an array
  lookup, same output.
- **GST and TDS apply to Due Amount** — after deductions. Bills and Payment apply
  them to gross. Visible in the data: ₹4,200 due → ₹4,956 total, i.e. 18% of the
  net.
- **`No_Of_Days_Not_Worked` is a misnomer, not a sign error.** Label and
  arithmetic both mean days *worked*. Renamed in the UI; the maths is untouched.
  Blank means "no deduction", not "zero days worked".
- **`Total_Due` is unreliable** — it computes only on specific field inputs, so it
  is null on most live records. Rendered as `—` rather than as a computed zero.
- **The parent status never advances.** All 813 live schedules sit at
  "Click to Proceed" while their instalments reach Paid. Kept, with a marker where
  instalments are overdue and the parent still reads as an instruction.
- **Remarks are required** whenever Due Amount differs from Amount. Creator's own
  rule, and a good one — enforced.

One addition: far-future groups start collapsed. One live schedule runs to
**31-Dec-2030** — 53 instalments — so an all-expanded queue buries the next
fortnight under four years of untouched rows.

### Salary Payouts — the arithmetic is not in the module

Every figure comes from `ekostay-payroll`. The module renders; it does not
compute. Ahmed's June row displays **₹22,680 payable / ₹22,880 CTC** — the same
values the 104-test suite pins.

That import took some work. `payroll/payroll.js` is deliberately CommonJS so it
runs under bare `node` with no build step, but the root package is
`type: module`, and Vite will not interop a relative CJS import — named *and*
default both fail. Two tempting wrong answers: duplicate the engine as ESM (two
copies, which drift — the exact failure the extraction prevents), or wrap it in
`createRequire` (Node-only, breaks in the browser). The working answer is to
resolve it as a package (`file:./payroll`) with `preserveSymlinks: true`, so
esbuild converts it once during dep pre-bundling. One source of truth.

What the module surfaces beyond the layout:

- **All four deviations render with severity.** `check()` returns `danger` for the
  negative-HRA band, `warn` for ESIC-on-base and PT-on-prorated, `info` for an
  ESIC flag above the ceiling. Priya Nair on ₹21,050 shows **HRA −₹50.00** in red
  — displayed, not hidden, not corrected.
- **§11.5 made visible.** Ahmed's June payout was computed on a total of ₹23,200;
  his header now reads ₹25,000. Correct for payroll — a payslip must not change
  after issue — but Creator keeps *no record of what the terms were*. The panel
  states the divergence explicitly.
- **Floored payouts are marked.** A recovery exceeding salary sets payable to ₹0
  and Creator drops the excess silently. The `⌊` marker carries the lost amount on
  hover.
- **Staff Advance and Staff Loan are read-only** — they flow in from the §10
  schedules, so typing them here would be a fiction.
- **The `Is_HR` gate is a visible toggle.** One boolean on one record controls
  whether Total Amount, Make Calculation and the Salary Months grid are editable.
  Making it explicit is the only way that fact is noticeable.

## Verify by rendering

`UI_HANDOFF.md` §4 is emphatic: an earlier failure shipped ~4,000 lines of CSS
that nobody ever looked at. So:

```bash
npm run dev &
node tools/capture.mjs      # → tools/shots/*.png, exits non-zero on any error
```

It mounts each module, screenshots at 1600×1000 @2x, counts rows, and reports
console errors. **Look at the PNGs before presenting anything.**

## Payroll engine

`payroll/` — dependency-free CommonJS, no framework, no UI. Ported from the
Deluge and pinned to live figures: **Ahmed Accounts June 2026 payable ₹22,680,
July ₹24,800**, to the rupee.

Four statutory deviations reproduced exactly, each with a test that fails if
someone "cleans it up." See `payroll/README.md`.

One real bug found during the port: `SalaryPayoutsModule.jsx` computed CTC
without adding back advance and loan — **₹3,000 out** on a ₹30,000 salary with a
₹1,000 advance and ₹2,000 loan. Never reached production, but it means the JSX
modules can't be assumed correct because they look finished.

> `payroll/package.json` sets `"type": "commonjs"` — the root is `"type":
> "module"` for Vite, and the engine is deliberately plain `node`, no build step.

## The governing rule: copy as built

**The current system's output is the specification.** Where Creator departs from
statute, or from itself, the rebuild reproduces the departure exactly, documents
it, and pins it with a test.

Three consequences worth stating plainly:

- Negative HRA in the ₹21,001–21,099 band is **reproduced**, not fixed. Three
  tests would fail if someone closed it.
- ESIC on base pay, doubled EDLI, PT on prorated salary — all **reproduced**.
  Each has a test asserting both the as-built and the statutory figure, so the
  gap is quantified whenever anyone wants to close it.
- The three different Payable formulas are **all reproduced**. The only change is
  distinct names in the schema, because three screens showing one label and three
  numbers is how reconciliation errors start.

The rebuild's addition is not different arithmetic — it is a warning beside the
same number. `check()` flags the negative-HRA band; it does not alter it.

## Decided — nothing is blocking

- **UI** — replicate Creator.
- **Stack** — React 19 + JSX + Vite, as the modules already are. PostgreSQL for
  the database. Money never a float: `DECIMAL(16,2)`, matching the live server's
  existing columns (`DB_FINDINGS.md` §3).
- **Blueprints / Approvals** — empty in Accounts and F&B, verified in the Creator
  Workflow tab. The Deluge is the whole story; no hidden lifecycle rules.
- **All three DS exports** received: Accounts, Admin, F&B.
- **Villa flags** — only `Hide_From_Payments` is functional. `Active` and
  `Status` are read by nothing.
- **Payroll deviations, Payable formulas, EKOSTAY splits** — copy as built, all
  of them.
- **Credential rotation** — handled separately, outside the rebuild.

Full record in `OPEN_QUESTIONS.md`.

## Validated against the live server

A read-only pass over `server.ekostay.com` on 13-Aug (`DB_FINDINGS.md`) confirmed
three of our decisions and corrected four details:

- ✅ **`Payable = Invoice − TDS`, no `Paid_Amount` term** — 16,285 of 16,405 live
  rows agree; the rest differ by exactly TDS.
- ✅ **No floats in money columns** — the team already uses `decimal` everywhere.
- ✅ **`bank_match_lines` as a junction table** — the settlement system stores its
  matches as a text blob, the same anti-pattern Creator's `Bank_Match_Line` fixed.
- 🔧 Money type → **`DECIMAL(16,2)`**, matching `payouts` rather than a narrower
  `(14,2)`.
- 🔧 **`Luxery` gets silently corrected to `Luxury`** by Zoho Analytics — but item
  categories do not, and `MAINTAINENCE` / `MAINTENANCE` coexist in live data. So
  the DS export is not a complete description of what Analytics serves, and the
  difference is per-field. Store Creator's spelling, add an alias, join on ids.
- 🔧 **`COA.Hide` is misnamed, not inverted** — every COA type reaches the Payment
  picker, so `Hide == true` means "selectable".
- 🔧 Our table → **`vendor_payments`**; two other `payments` tables already exist
  on that host and neither is ours.

Also settled §A3 with evidence: **neither EKOSTAY rent type appears across 200
villas**, so the unhandled branch is dead code rather than an accounting hole.

## Schema

`backend/schema/` — three PostgreSQL migrations, **38 tables, 429 constraints, 49
indexes**. Full notes in `backend/schema/README.md`.

The design rule: Creator's arithmetic is reproduced exactly, and the schema adds
**enforcement of invariants Creator depends on but never states**. Each one is a
rule the current system breaks in production.

Building it surfaced a live defect worth stating plainly:

> **233 payment numbers are shared by 494 rows** in the settlement system, 229 of
> them Haewaya. They are not split legs — `EKS/Haewaya/12539` covers six villas,
> six categories and six dates two weeks apart. Six unrelated payments, one
> number. Fresh duplicates dated **13-Aug-2026**.

So `payment_no` cannot be unique — a plain `UNIQUE` would reject 494 live rows.
Instead: `UNIQUE (series_code, series_seq)` allocated by the database, with the
display number kept verbatim and non-unique. Collision becomes impossible going
forward without rewriting history.

**Honest limit:** the migrations parse against a real PostgreSQL grammar and pass
15 asserted invariants, but they have never run against a live PostgreSQL — there
is none on this machine. Run `psql -f` on a scratch database before production.

## Next

1. **Run the migrations against a real PostgreSQL.** The one unverified step.
2. **Import scripts** — `creator_id` is on every table for this. Order: master
   data → vendors/employees → bills → payments → splits → match lines, with
   `SET CONSTRAINTS ALL DEFERRED` and exceptions recorded rather than corrected.
3. **API layer**, then swap the modules' fixtures for real queries.
4. **Six screens Creator has that aren't built**: Expense Observations (§13),
   Bank, Accounts, Masters beyond Vendor Master, Pending Approvals, Settings.
5. **`payroll_configs` → the engine.** `payroll/payroll.js` holds `CONFIG_V1` as a
   literal; it should load from the table and keep the literal as a test fixture.

## Tests

```bash
npm test                        # all three suites
npm run test:payroll            # 104 — pinned to Ahmed's live June/July rows
npm run test:schedule           #  30 — instalment generation, month-end clamping
npm run test:schema             #   3 migrations, 15 invariants
node tools/capture.mjs          # all 6 modules render, zero console errors
```
