# Session handoff — Ekostay Accounts rebuild

**Written 27-Aug-2026.** Read this first, then `STATUS.md`. Everything below is
what a new session needs to continue without re-deriving it.

---

## 1 · What this project is

Ekostay (150+ villa rentals across Maharashtra, Goa, Tamil Nadu, Karnataka and
Uttarakhand; founder **Husain Khatumdi**) is rebuilding its **Accounts**
application out of Zoho Creator. Creator has hit platform limits. This repo is
the successor.

Working directory: `c:\xampp1\htdocs\zoho_replica`

---

## 2 · The governing rule: copy as built

**Creator's output is the specification.** Where Creator's arithmetic is wrong,
the rebuild reproduces the wrong answer, documents it beside the number, and
pins it with a test. It does not silently correct anything.

This was the user's explicit instruction — *"cant you check code and make it
like that what is already built?"* and *"build this logic same as zoho creator"*.
It is the single most important thing to preserve. A well-meaning "fix" to any of
the deviations listed in §6 is a regression, not an improvement.

Deviations are allowed only where reproducing Creator would **destroy data or
leak a credential**, and every such case is named in §7.

---

## 3 · Current state

| Area | State |
|---|---|
| Six Creator report modules | ✅ replicas, at repo root as `*.jsx` |
| Dashboard | ✅ new — Creator has none (`src/DashboardModule.jsx`) |
| Login + session | ✅ `src/auth/` |
| Roles, permissions, villa scoping | ✅ enforced, not just displayed |
| Payroll engine | ✅ extracted, `payroll/`, dependency-free CJS |
| PostgreSQL schema | ⚠️ written and parsed — **never run against a database** |
| API layer | ❌ **does not exist** — everything is fixtures |
| Import scripts | ❌ not started (`creator_id` is on every table for this) |

**396 assertions, all passing:**

```bash
npm test          # everything
npm run test:e2e  # 73 Playwright tests
npm run dev       # http://localhost:5173
```

- 104 payroll · 30 schedule · 4 migrations parse · 185 auth · 73 e2e

**All test accounts use the password `ekostay2026`.**

---

## 4 · The two things to do next, in this order

### 4.1 Run the migrations against PostgreSQL

`backend/schema/*.sql` — 4 files, 42 tables, 459 constraints. They parse against
a real grammar and pass 15 invariants, but **parsing is not executing**: it does
not prove a `CHECK` expression is immutable enough for a constraint, or that a
trigger body compiles.

No PostgreSQL and no Docker on the dev machine. The server *can* take it —
verified read-only over SSH:

- `148.113.0.151` (`server.ekostay.com`) — Ubuntu 24.04.4 LTS
- `apt` candidate for `postgresql` is **16+257build1.1** — exactly the version
  targeted, no third-party repo needed
- Existing database is **MariaDB 10.11.16**, 8.2 GB, on 3306. Different port,
  different data directory, no conflict
- Docker 29.4.0 already runs `ekostay-prod` and `ekostay-ontest`
- 31 GB RAM (21 available), 24 cores
- ⚠️ **Disk at 85%** — 71 G free of 467 G. Enough, but wants housekeeping before
  go-live
- CyberPanel + LiteSpeed own the box, which is why Docker is the safer install
  route: CyberPanel gets opinionated about system services it did not install

**The user has not yet approved installing anything.** Recommended sequence:
local Postgres first (a DDL mistake costs nothing there), then the identical
migrations on the server.

### 4.2 Build the API layer

Context doc §17 step 5 — the only step in that build order not done. Steps
1,2,3,4,6 are complete.

This is the real distance between "seven working screens" and "an app". Edits
currently vanish on refresh. It is also what makes the login real: password
verification moves server-side, and `src/auth/credentials.js` gets **deleted**.

Write it against the schema **after** 4.1 passes. An endpoint written against
unverified DDL is work you may have to redo.

---

## 5 · Layout

```
*.jsx                        the six Creator replicas, at root, ~7,300 lines
src/DashboardModule.jsx      the landing screen (new)
src/Shell.jsx                dev harness: 30px strip, module switcher, error boundary
src/main.jsx                 the auth gate — no session, no app
src/auth/                    roles, permissions, scoping, login, session
payroll/                     the extracted engine + 104 tests
schedule/                    instalment generator tests (30)
backend/schema/              001_core · 002_payments · 003_payroll · 004_auth
e2e/                         4 Playwright specs, 73 tests
zoho_source/                 the Creator source, transcribed
tools/                       screenshot + probe harnesses
```

### The shell adds no chrome — on purpose

Each module renders its **own complete Creator frame** (navy rail, app bar,
report bar, search chip). That frame *is* the deliverable.

A first attempt gave the shell its own navy rail. It cost 104px of grid width and
put two avatars on screen. **The screenshot caught it; the DOM did not.** Replaced
with a 30px dev strip. Do not re-add chrome to the shell.

---

## 6 · Creator behaviours reproduced — do not "fix" these

### Payroll (all four pinned in `payroll/payroll.test.js`)

1. **Negative HRA.** Total pay in **₹21,001–21,099** yields a negative HRA.
   Priya Nair on ₹21,050 shows **HRA −₹50.00**, rendered in red.
2. **ESIC on base pay, not gross.**
3. **EDLI charged twice.**
4. **PT on prorated salary**, not contractual.

Acceptance case: Ahmed Accounts June **₹22,680 payable / ₹22,880 CTC**;
July ₹24,800 / ₹25,000. These live in the record's payout table, not the grid.

### Payments

- **`Payable = Invoice − TDS`, with no `Paid_Amount` term.** Bills subtracts it;
  Payment does not. Both are correct for their own screen. Named distinctly in
  the schema so nobody reconciles them expecting a match.
- **`Payment_Status = "Open"`** is written by `Create_Payment` but is not in the
  declared picklist. Kept, with a dotted underline and tooltip.
- **Both `"Sent for Approval"` and `"Send for Approval"`** exist. Both kept.
- **COA picker filters `COA[Hide == true]`** — the inverse of Bills. Kept.
- **Gross renders at three decimals, Payable at two**, on the same split row.
- Preserved spellings: `Payment InProgress`, `Maintaince`, `ACCOMODATION`,
  `Main Primary`, `multipe_hccc_names`, `Independant`. An e2e test **fails** if
  `Payment InProgress` is ever corrected.

### Schedule Payments

- **February clamps to 28 unconditionally** — a 29th-due schedule lands on 28-Feb
  even in a leap year.
- **Clamping can pull a due date *into* the window.** A 31-Jan→30-Jun window with
  a 31st due date yields **6** instalments, not 5, because June's 31st clamps to
  the 30th, which is the window edge. My first test expectation was wrong here,
  not the code.
- **GST and TDS apply to Due Amount** (net), unlike Bills and Payment (gross).
- **`No_Of_Days_Not_Worked` is a misnomer, not a sign error** — label and
  arithmetic both mean days *worked*. Renamed in the UI; maths untouched.
- **`Total_Due` is unreliable** — computes only on specific field inputs, so it is
  null on most live records. Rendered `—`, never a computed zero.
- **The parent status never advances.** All 813 live schedules sit at "Click to
  Proceed" while their instalments reach Paid.

### Access control (`src/auth/roles.js`)

Creator resolves access with seven chained `.contains()` tests against a
free-text `User_Role` field (`Admin.ds:1184-1254`). `resolveCreatorRole()`
reproduces all four quirks, for import mapping:

- `"accounts head"` matches **lowercase only** — `"Accounts Head"` falls through
  every branch and the user gets **no access and no error**
- `.contains()` is a substring test, so `"Assistant Property Manager"` receives a
  Property Manager's rights
- branch order breaks ties
- the Active and Inactive arms test the same seven roles in **different order**

**`Is_HR` gates all payroll editing** — one boolean on one record, independent of
role (`Admin.ds:1293`). Sneha and Rohan share the HR role; only Sneha has it.
`Is_HR` alone does *not* escalate a Property Manager, and that is pinned.

---

## 7 · The three deliberate deviations

Each prevents data loss or a credential leak. Each is documented at the call site.

1. **`Delete Paid Payment` routes to a reversal.** Creator's label stays in the
   More menu so the report matches, but it creates a linked entry with negated
   amounts and a **required reason**; the original keeps its number and its bank
   match history. Creator's version destroyed **17 real payments (₹93,884)**.
2. **`Analytics_Refresh_Token` is not readable.** Creator grants Account
   Team-Executive read access to a live credential
   (`visibility:true, readonly:true`) — the most junior accounts profile. Not
   reproduced. A test asserts no role holds a permission naming a token.
3. **Villa scoping compares IDs, never name substrings.** Creator gets this right
   in `SendVillaName` (`Villa[Location == recid]`) and wrong in its CA views and
   `LLP_Bank`. The fixture proves why: `Lonavla Central` is spelled without the
   second `a`, so a name filter for "Lonavala" silently drops it. Five villas by
   ID, four by name.

Plus two additions, both accepted in `UI_HANDOFF.md`: the split imbalance shows a
live `₹ x of ₹ y` tally, and far-future schedule groups start collapsed (one live
schedule runs to 31-Dec-2030, 53 instalments).

---

## 8 · Live-server findings (read-only, `DB_FINDINGS.md`)

Inspected under an explicit constraint from the user: *"please use it for read
only purpose to check DB for now since this is live data."* Only
`SELECT`/`SHOW`/`DESCRIBE`, capped row counts. **No customer names, phone
numbers, bank details, UTRs or record-linked amounts were recorded anywhere.**

Still-open production problems — **outside this rebuild, but real**:

- **233 payment numbers shared by 494 rows**, with fresh duplicates dated
  13-Aug. `EKS/Haewaya/12539` alone covers six villas/categories/dates. Our
  schema makes this impossible going forward (`UNIQUE (series_code,
  series_seq)`; `payment_no` deliberately **not** unique so live data imports),
  but Creator keeps issuing them until its counter is fixed.
- **The delete webhook has never succeeded.** Creator sends the literal
  `PUT_THE_ROTATED_TOKEN_HERE` while a real 48-char token is configured. 401s
  logged. The user said: *"rotation will be taken care of dont worry"* — treat
  credential rotation as theirs, not the rebuild's.
- **Zoho Analytics silently corrects `Luxery`→`Luxury`** but does *not* normalise
  item categories (`MAINTAINENCE` and `MAINTENANCE` coexist). So the DS export is
  not a complete description of what Analytics serves.
- The supplied SSH key is **root** and grants write access to every database
  including the booking engine. A scoped read-only user is recommended for any
  future work.

Answered from live data during the doc audit:

- **PT coverage is complete.** Every location with payroll rows maps to a handled
  state (Maharashtra, Tamil Nadu, Goa). Karnataka and Uttarakhand have rules but
  no payroll rows.
- **The `Name- Locality` villa pattern is not a region marker.** 34 villas use
  it, not Goa-only (Goa 22, Lonavala 6, Alibaug 2, Karjat 2, Panvel 2). Parsing
  it to derive location would be wrong — join on `location_id`.
- **Backend Expenses is machine-written only** — 63,335 rows, exactly two
  automated sources.

---

## 9 · Environment gotchas that cost time

- **Money is `NUMERIC(16,2)` / `DECIMAL(16,2)`, never float.** `r2()` in
  `payroll/payroll.js` rounds half-away-from-zero.
- **`r2(1.005)` is `1.00`, not `1.01`** — 1.005 is stored as 1.00499999… in
  IEEE-754, and Deluge on the JVM hits the identical limit. My test expectation
  was wrong, not the code. The proof is inline in the test.
- **The payroll engine is a `file:./payroll` package with `preserveSymlinks:
  true`.** It is deliberately CommonJS so it runs under bare `node`. Vite will
  not interop a relative CJS import — named *and* default both fail. Two tempting
  wrong answers: duplicate it as ESM (two copies, which drift — the exact failure
  the extraction prevents), or wrap it in `createRequire` (Node-only, throws in
  the browser). **Never duplicate the engine.**
- **Node scripts in `/tmp` cannot resolve project `node_modules`.** Put them in
  `tools/`.
- **A backtick inside a CSS comment terminates the enclosing template literal.**
  This broke the build twice. Avoid backticks inside `<style>{\`…\`}` blocks.
- **`@import` must be the first rule in a stylesheet.** Prepending anything to a
  module's CSS kills the Inter font.
- **Editing spec files through `node -e` strips `\d` and `\s`** via shell
  escaping. Edit spec files directly.
- **Only weights named in the Google Fonts `@import` actually load.** Asking for
  700 when the import says `400;500;600;650` makes the browser synthesise a
  thinner face.
- **PowerShell is the primary shell**; Bash is available. `&&` does not work in
  Windows PowerShell 5.1.

---

## 10 · Verify by rendering

**Screenshot and look at the UI before presenting it.** Several bugs in this
project were invisible to assertions and obvious in an image:

- the shell's duplicate navy rail (104px of grid lost)
- two rail items reading as active at once — the DOM correctly reported one `.on`
- the `ACC` logo rendering grey, because `.lg-brand span` overrode `.lg-mark`'s
  white on equal specificity
- empty dashboard tiles reading `0 records · ₹0`, competing for attention with
  the tiles that mattered

`tools/capture.mjs` renders every module and screenshots it. Use it.

---

## 11 · Things the tests found that looking would not

The e2e suite caught two dead controls on its first run:

- **`Alt+7` did nothing** — the handler matched the literal `"123456"`, so adding
  the dashboard as a seventh module silently orphaned the last shortcut. Now
  derived from `MODULES`.
- **The Salary Payouts dashboard tile did not navigate** — `"Salary Payouts"` was
  missing from `RAIL_TO_MODULE`. Same class of bug as the dead sidebar the user
  reported.

Five e2e failures were **my tests being wrong, not the app**. Worth remembering,
because the instinct on a red test is to change the code: wrong class name;
searching a vendor name while the field selector said "Payment No"; comparing
whole-row text to detect sorting (ascending leaves row 1 first); expecting
`−₹50.00` when it renders `−₹ 50.00`; expecting a payout figure on the grid when
it lives in the record panel.

---

## 12 · Open questions for Husain

Three, none blocking:

1. **The four action buttons on All Scheduled Payments** — never seen rendered,
   so their placement in the replica is a guess.
2. **Which vendor-merge field is authoritative** — a person can exist in both
   `Vendor_Master` and `Employee_Master` with different data (§13A.1).
3. **Does anyone open Backend Expenses**, or is it purely a sync target?

`OPEN_QUESTIONS.md` §A holds 8 closed decisions, §B 9 answered from code, §C
defects 49–54, §D the credential inventory. `ACCOUNTS_REBUILD_CONTEXT.md` still
carries **25 `[TODO]`s** — all genuine, none blocking: they ask about Creator's
*intent*, which copy-as-built does not require answered.

---

## 13 · Screens Creator has that have no module yet

Bank · Expenses · Expense Observations (spec exists, context doc §13) · Masters
beyond Vendor Master · Pending Approvals · Settings

The rail marks them not-built rather than pretending. Do not wire a rail item to
nothing — a control that looks live and isn't is worse than one that says so.

---

## 14 · The Creator source

`zoho_source/` holds 7,291 lines, transcribed from the DS exports:

| File | Lines | |
|---|---|---|
| `Accounts_LOGIC.ds` | 3,765 | business logic |
| `Admin.ds` | 2,435 | the access mechanism, `Villa.Rent_Type` |
| `Accounts_SCHEMA.md` | 693 | 46 forms, fields, picklists, hardcoded literals |
| `Accounts_PERMISSIONS.md` | 301 | 17 profiles |
| `SOURCE_PROVENANCE.md` | 97 | **read this before trusting the above** |

⚠️ **Two limits on this source, both important:**

1. **It was reproduced from chat paste, not exported to disk by a tool.**
   `SOURCE_PROVENANCE.md` lists the risks: arithmetic drift and hardcoded IDs are
   the two that matter. Treat a surprising number in a `.ds` file as *possibly a
   transcription artefact* — check it against the live app before building on it.
2. **`F_B.ds` was supplied and analysed but never written to disk.** It is not in
   `zoho_source/`. If F&B logic becomes relevant, ask the user to re-send it.

DS exports contain structure and script only — **no records**, and **no
`blueprints` or `approvals` block ever**. The user confirmed both are empty in
Accounts and F&B, so nothing is missing there.
