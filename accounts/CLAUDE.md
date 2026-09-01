# Ekostay Accounts — rebuild

Rebuilding the Zoho Creator **Accounts** app outside Creator. Creator has hit
platform limits. UI is designed in Claude chat; functionality is built here.

Stakeholder: **Husain Khatumdi** (founder). They send screenshots per module and
expect a replica built from those plus the Deluge exports.

---

## Document precedence — this order, always

1. `UI_HANDOFF.md` — how to work here, design system, verify-by-rendering loop,
   current status. **Read first.** (This is v2; it supersedes a v1 not in the repo.)
2. `ACCOUNTS_CONTEXT_ADDENDUM.md` — corrections to the spec, built from
   screenshots + report exports of 12–13 Aug 2026.
3. `ACCOUNTS_REBUILD_CONTEXT.md` — the original functional spec (1,314 lines).
   Authority on behaviour, data flow and the defect register.

**Where 2 and 3 disagree, the addendum wins** — it is evidence-based, the spec is
partly inferred. Where a `[TODO]` appears, **stop and ask; do not guess.** Several
concern money movement, record deletion and statutory compliance.

Keep the `.md` files current. Per the handoff, the docs are the real deliverable —
the JSX is regenerable.

---

## The one instruction that overrides taste

**Replicate the Creator screens. Do not redesign them.** An earlier attempt
reorganised the information architecture into something "better" and was
rejected. Same structure, same labels, same column order, same density, rendered
cleanly. Improvements come later, on Husain's signal — flag them as suggestions,
don't ship them unasked.

Concretely: field labels verbatim · column order as the report shows it (`ID` is
not always last) · section names verbatim · ~20+ rows visible, ~27px rows ·
dates `dd-MMM-yyyy` via text input, never `<input type="date">` · currency
`₹ ##,##,###.##` with Indian grouping · footer `Showing N of M`.

**Preserve source spellings.** `Luxery`, `ACCOMODATION`, `Maintaince`,
`multipe_hccc_names`, `stafffuel`, `Payment InProgress`, and the trailing space on
`F&B STAFF MEDICAL EXPENSE `. These are live lookup keys. Normalise at display
only, never in data.

**Record IDs are 18-digit strings.** Keep them strings end to end. Passing them
through `float()` silently corrupts them (`…361075` → `…361100`); anything
narrower than `bigint` loses precision.

---

## Layout

**This app now lives inside a monorepo** — `ekostay-platform/accounts/`, alongside
`fnb/` (not started). One repo because `F_B.ds` makes 47 cross-app calls into
`accounts.*` and the Bills form carries an F&B lookup today, so neither can be built
against a stub of the other. Root `README.md` and `ONBOARDING.md` cover the split;
`ONBOARDING.md` is written for the second developer joining.

Paths below are relative to `accounts/`.

```
README.md                        the original package readme
UI_HANDOFF.md                    ┐
ACCOUNTS_CONTEXT_ADDENDUM.md     ├ the three context docs, at root so their
ACCOUNTS_REBUILD_CONTEXT.md      ┘ cross-references resolve as written
master-data/                     real Creator exports, 12-Aug-2026 — seed from here
deluge/                          Accounts.ds + Admin.ds (git-ignored; F_B.ds moved to ../fnb/deluge/)
reference-ui/                    8 screenshot-verified React replicas
reference-ui/_do-not-ship/       2 old redesigns, quarantined
docs/screenshots/                (empty) drop per-module screenshots here
docs/ZOHO_ANALYTICS_CONNECTION.md   the read-plane contract: view ids, org, workspaces
docs/ZOHO_CREATOR_FIELD_NOTES.md    694 lines of measured Creator/Analytics behaviour
```

`master-data/` keeps that exact name — the docs reference `master-data/*.json`.

### The DS exports are available again

The handoff says the Deluge exports are "not currently in the working set." **They
are now** — `deluge/` holds `Accounts.ds` (59,063 lines), `Admin.ds` (4,162) and
`F_B.ds` (21,994), generated 08-Aug-2026, line counts matching spec §18 exactly.

This reopens every question the docs park on "needs a DS grep" — where the Block
Payment Date cutoff is enforced (handoff §6.3), the Blueprints question in §8.5,
and the vendor-merge authority in §13A.1. Trace them in the DS before asking
Husain for anything the files can answer.

**§13A.1 turned out not to need the DS at all** — the vendor export answers it by
counting. `Primary Vendor` is the merge pointer, `Primary Status` flags the target,
and `Main Primary` is not a merge field. Addendum §18.1.

The `.ds` files are **git-ignored on purpose**: `Accounts.ds` holds the live DoubleTick
API key at **three** lines (16768, 16780, 22851) and the **Analytics OAuth
client_secret** at 22647. Rotate both before considering these for commit, even to a
private repo. Structure and script only — no records. Addendum §7D.

### What the JSX is

**Reference replicas, not the application.** Single self-contained React files
with inline seed data and an inline `Style()` block so each renders standalone in
a screenshot harness. Take from them: column and field order, labels, formatting
helpers, control behaviours. **Do not** carry over the single-file structure or the
duplicated `Style()` blocks.

`_do-not-ship/` holds `SchedulePaymentsModule.jsx` and `SalaryPayoutsModule.jsx` —
both are the rejected redesign. Rebuild them. **Exception: the payroll engine
inside `SalaryPayoutsModule.jsx` is correct — keep that.**

`BillsModule.jsx` and `VendorMasterModule.jsx` are v1-era and still need the
chrome corrections in handoff §3 (overlay not modal, filled status cell, no zebra
striping, `.zc-main { min-height:0 }`).

---

## Master data — verified 22-Aug-2026

All nine exports parse and every record count matches `_index.json`. The
documented dirty data is intact and was confirmed directly against the files:

| Claim | Verified |
|---|---|
| `F&B STAFF MEDICAL EXPENSE ` trailing space, only such name | yes, 1 of 135 |
| `Expense Type` unset on 103 of 135 item categories | yes |
| `Haewaya ID` empty on all 135 — sync key unpopulated | yes |
| `Exclude for Observation` true on 1 → ~~exclusion is inert~~ | count yes, **conclusion WRONG** |
| 9 COA rows with `Bank = true` not typed `bank`, incl. `Security Deposit` | yes |
| `EKOSTAY IDFC LLP` twice, different record IDs | yes |
| `Account Code` populated on 6 of 144 | yes |
| TDS: 35 rows, 16 distinct name+percentage pairs, 16 blank `Status` | yes |
| 8 leading-space villa names; `Athens Villa  Nerul`, `Windsor  Villa`, `StarMount  Villa` doubled | yes |
| `Copacabana Villa Calangute` **and** `Copacabana Villa- Calangute` | yes |
| test record `fcgfhbjnh` | yes |
| all 144 COA IDs are 18-char JSON **strings** | yes |

**`Exclude for Observation` is NOT inert — corrected 27-Aug-2026.** The one category
it excludes is `EXPERIENCES REFUND`, which is the whole Backbend Payments refunds
channel (addendum §7.1). One of 135 by count, not marginal in effect. And its two
siblings disagree: `FOOD REFUND` and `STAY REFUND` are both excluded from profit but
**not** from observation, while `REFUND-stay-*` and `REFUND-food-*` both exist live —
so stay and food refunds show up in Expense Observations and experiences refunds do
not. Looks like an oversight; needs Husain. Addendum §7C.3.

There are **three** exclusion columns, which is what §3.1's "do not implement all of
them" is counting: `exclude_for_profit` (12 of 135), `exclude_for_observation` (1),
`exclude_item_category` (**0** — entirely unused).

**One correction to how the data reads.** The addendum's "`Nature` three times"
is true of the *source*, not of the recovered master. `All_Approvals.Villa Name`
is a **comma-packed string**, and record 8 contains `…,Nature,Nature,Nature,…`.
`Villa_Master_recovered.json` is a flat list of 204 **distinct** names, so it
holds `Nature` once and contains no exact duplicates at all. Per-record
multiplicity is lost in the recovered file — go back to `All_Approvals.json` when
multiplicity matters.

Related: the packed `Villa Name` string is comma-separated with inconsistent
spacing (` Casa Bella`, ` Casa Elara`, ` Brooklyn Villa`), which is where the
leading-space names come from. Splitting it is a parse, not a `split(",")`.

**Seed from `master-data/`, not from the JSX** — some JSX seed arrays are marked
synthetic. Fix dirty data deliberately, with a migration and a mapping table,
never silently on import. `multipe_hccc_names` in particular **needs a mapping
table, not a normalisation function**.

---

## Target stack — blocked before scaffolding

Laravel 11, PHP 8.2+, PostgreSQL 15+, snake_case, config-driven exclusion lists,
synced-vs-user-generated table separation, IDs as opaque strings.

**§1.1 RESOLVED 22-Aug-2026 — new standalone app**, recorded in the spec at §1.1
as §17 step 1 requires. "Same code structure as the expense tracker" means same
conventions, not the same repository. Migrations may proceed.

**§2.1 RESOLVED 29-Aug-2026 — replace the cluster.** All apps become sub-sections of
one domain, one Laravel app, one schema. **The F&B write-path gate is lifted.**
Measured from the DS: the dependency is bidirectional and heavier the other way (63
`accounts`→`fb` against 47 the other), so an API seam was never viable. See
`docs/ARCHITECTURE_2_1_DECIDED.md`.

Either way the apps call each other bidirectionally by direct function reference,
so **Accounts, Admin and the F&B reference tables live in one schema** here.
Accounts cannot ship against a stubbed Admin, and F&B is not a future concern —
Bills has an F&B lookup on it today.

Also from §17: do **not** implement the approval engine, the Books push, or any
F&B write path in the first pass. Stop before Payments, Schedule Payments and
Salary Payouts until the §16 blocking-write-path questions are answered.
Payroll rates/bands/ceilings must land as **versioned configuration rows with
effective dates**, with every payslip recording which version produced it.

---

## Toolchain, as installed 22-Aug-2026

| Tool | Version | Notes |
|---|---|---|
| PHP | 8.4.24 | winget; `php.ini` written by hand — the zip ships none |
| Composer | 2.10.2 | not in winget; from getcomposer.org, sha384 verified |
| Laravel | 12.67.0 | **not 11** — see §1.1 of the spec for why |
| PostgreSQL | 17.11 | service `postgresql-x64-17`, port 5432 |
| Node / npm | 20.11.1 / 10.2.4 | **too old for `npm run dev`** — Vite 7 wants 20.19+/22.12+ and dies with `crypto.hash is not a function`. `npm run build` works, and the screenshot harness runs. |

Two databases: `ekostay_accounts` (dev) and `ekostay_accounts_test` (used by
`php artisan test`, configured in `phpunit.xml`). Both owned by role `ekostay`,
both `LC_COLLATE 'C'`. Credentials are in `.env`, which is git-ignored.

Tests run against **Postgres, not sqlite** — the `villas.rent_type` CHECK is added
with `ALTER TABLE ... ADD CONSTRAINT`, which sqlite cannot do, and that constraint
is the thing under test.

```bash
php artisan migrate:fresh --seed     # rebuild dev db from master-data/
php artisan test                     # 232 tests, 1323 assertions
php artisan db:show --counts         # confirm the connection and row counts
```

### Running it — two things bite every time

**PHP is not on PATH.** winget installed it but wrote no shim. Prepend the package
directory before anything else:

```bash
export PATH="$LOCALAPPDATA/Microsoft/WinGet/Packages/PHP.PHP.8.4_Microsoft.Winget.Source_8wekyb3d8bbwe:$PATH"
php artisan serve --host=127.0.0.1 --port=8000     # -> http://127.0.0.1:8000
```

**`npm run dev` cannot start** on Node 20.11.1 — see the table above. So there is
**no HMR**: after any edit under `resources/js` or `resources/css`, run
`npm run build` and reload. Upgrading Node to 20.19+ is the real fix and would make
the verify-by-rendering loop far less painful.

The React app mounts from `resources/js/app.jsx`. That mount was **missing** until
22-Aug-2026 — the bundle defined `App`, never called `createRoot`, and served a
blank page behind an HTTP 200. Worth remembering as the failure mode: valid CSS,
valid JS, valid response, empty `#app`.

## Build state

**§17 step 1 — done.** Stack and repo decision recorded in spec §1.1.

**§17 step 2 — done.** All 17 master-layer tables exist, migrated and seeded, with
the step's verification criteria as tests:

- `every_foreign_key_resolves` — 0 orphans. All 135 item categories resolve to a
  master category by exact name match, no trimming or case folding needed.
- `VillaRentTypeTest` — a fixture per rent type, all four round-trip, a fifth
  plausible value is rejected by the CHECK.
- plus data-fidelity tests: 18-char string ids, non-uniform booleans, the trailing
  space, doubled spaces, both Copacabana spellings, duplicate `EKOSTAY IDFC LLP`,
  TDS blank-not-Expired, 35 rows over 16 distinct rates.

Seeded from the real exports: master_categories (10), item_categories (135),
coa_accounts (144), ca_masters (2, derived from COA `CA Name`), taxes (8),
tds_rates (35), roles (10, from §3.3).

**Villas are now real** — 254 records from `All_Villas.csv` (was 204 bare names),
with location, state, head office, rent type, category, BHK and flags. Geography is
derived from the same export: **29 locations** (19 were missing) and **9 states**.
**Now 30 locations** — `VendorSeeder` recovers `Alleppey`, which a vendor names and
no villa does; the villa export is a villa-scoped view of the Location master, so it
under-reports it (addendum §18.6).

Still absent from the villa data, because the report carries 18 of ~40 fields:
`Hide_From_Payments` (the filter Bills and Payments actually use), `Status`,
`Inner_Circle`, the commercial/split percentages, both category-scoping mechanisms,
and the Villa_Managers / Owner_Details grids. Needs a **form-level** export.

**Not seeded, no export exists:** employee_designations, employee_departments,
billing_cycles. `TestBillSeeder` creates one of each as fixtures — never run it
expecting real data.

~~vendors~~ — **corrected 24-Aug-2026.** The export did exist. 8,063 real vendors
are seeded from `master-data/Vendor_Master.csv`; see addendum §18.

**Since seeded:** permissions (122) and permission_role (127) from the DS profiles
via `docs/parse_permissions.py`; employees (475) from `All_Employee_Masters.csv`;
auto_numbers (1) from `Auto_Numbers.json` — the payment counter, which **must** be
the real 20938 and not a fresh 1.

**The payment counter is now GUARDED, and the guard lives in the seeder too.**
Husain confirmed 27-Aug-2026 that `EKS/PY` comes from `Auto_Numbers`, and the live
screenshot read `Payment No` **21621** against our 21309 — 312 numbers that
already belong to real payments. `PaymentNumber::allocate()` now REFUSES while our
counter sits at or below `auto_numbers.live_payment_no_observed`, and steps past any
number already taken (Creator has that check at `Accounts.ds:20517`; we did not).

`AutoNumberSeeder` carries the reading as `LIVE_PAYMENT_NO` / `LIVE_HAEWAYA_NO` /
`LIVE_OBSERVED_AT`. **Update them when a fresh Auto Numbers screenshot arrives** —
the master export is 12-Aug and cannot supply them. A `migrate:fresh --seed` without
them silently disarms the guard, which is how the hole was found. Addendum §6.10-6.15.

There are **four** series in that one row, and the report shows three: `Payment`,
`Haewaya`, `Books_Payment` (never used, counter still 1) and **`External_Payment`**,
which no screen displays and `Accounts.ds:20502` allocates from.

**`EKS/PAY` IS A THIRD LIVE SERIES AND HAS NO COUNTER HERE.** Husain, 28-Aug-2026:
`EKS/PAY` is COA **Accounts Payable** and `EKS/PY` is **Expense**. Verified: all 1,344
`EKS/PAY` payments are on Accounts Payable and none on Expense, max number **1,781**.
The prefix census is `EKS/Haewaya` 33,408 · `EKS/PY` 16,490 · `EKS/PAY` 1,344.

**CORRECTED 28-Aug-2026: `EKS/PAY` IS RETIRED and is NOT a cutover blocker.** It ran
2025-09-01 to 2026-05-05 and was replaced by `EKS/PY` for Accounts Payable in Q2 2026 —
the handover is visible per quarter (2026 Q1: PAY 626 / PY 6; Q2: PAY 81 / PY 709; Q3:
PY 434 only). Husain's rule is right about the SEMANTICS —
`Creator.CreatePaymentfromBill` at `Accounts.ds:19018` does force COA to Accounts
Payable — but it mints from `Payment_Series`, so bill-derived payments now take
`EKS/PY`. Max `EKS/PAY` is 1,781 and final. **An importer must still ACCEPT `EKS/PAY`
on read**, or 1,344 historical AP payments get dropped. Addendum §20.

**USE PHP FOR EVERY CLOCK READING.** `app.timezone` is UTC, the shared-slot cron runs
IST, and **Git Bash on Windows returns UTC when asked for `TZ=Asia/Kolkata`**. That
half-hour offset put a 30-minute hole in the concurrency guard and then, once fixed,
fooled the operator the same way half an hour later. The machine's bare `date` is IST
and correct; `TZ=` is not. Addendum §19.4.

**§17 steps 3–6 — done.** Roles and permissions are first-class tables (no string
matching anywhere in the authorisation path, asserted), the Bills schema and its
two child grids exist, and the split allocator/validator carry §6.3's
remainder-on-the-last-row rule to the paisa.

**§17 step 7 — the gate is LIFTED, and Payments is built.** The step said stop
until the four §16 "blocking write paths" questions were answered. All four are
answered from the DS; two were defects rather than questions. The full working is
in **addendum §16**, including the six logged deviations D1–D6. Headlines:

- **§7.2's partially-paid TDS sign is a bug** that overpays vendors by
  `2·TDS − GST` — twice the withholding on a no-GST vendor. Fixed (D1).
- **§7.6's payment-number padding is dead code.** The counter is at 20938 and every
  pad branch tests below 1000. What was actually wrong there is the non-atomic
  increment, now allocated under a row lock (D3).
- **Nothing hard-deletes a payment.** `Delete Paid Payment` is replaced by a
  reversing entry, guarded on the **model** so none of the 14 unguarded
  `delete from Payment` sites in the DS can be reproduced (D4).
- **Payments now enforce the split balance** §7.4 says they never had (D2).

Verified end to end, not just unit-tested: a payment created from a seeded bill
took `EKS/PY/20938` off the real counter, moved its bill to `Payment InProgress`,
refused a one-paisa-unbalanced split without advancing the counter, refused a hard
delete, and reversed into `EKS/PY/20939` after which **every villa × category ×
cycle nets to zero**. 42 of the 90 tests cover Payments.

`TestBillSeeder` builds the fixture bill for this (`db:seed --class=TestBillSeeder`).
It is deliberately **outside** `DatabaseSeeder` so fabricated money never lands
beside the real masters by accident.

**Still gated:** Schedule Payments and Salary Payouts. They depend on §11's
versioned payroll configuration with effective dates, which does not exist yet, and
§17 is explicit that re-running a month without it silently re-decides old payslips.

**The Approvers grid arrived 27-Aug-2026 and routing is unblocked** — amount bands,
approver identities and `Approval Type`, all three approvers resolving against
`employees` by email. Addendum §11.7-11.13. Two things came out of it that the
screenshots alone would have got wrong: the header fields routing branches on are a
**browser-side mirror** of the grid (`Accounts.ds:38118`) and are blank on all 16
live rules, and a null `Approval Type` on Level 1 is **deliberate**
(`Accounts.ds:38137` nulls and disables it) so `currentLevelSatisfied()`'s
null-means-Any default must stay.

**PAYMENTS ARE EDITABLE, corrected 29-Aug-2026.** `PaymentsModule` refused to open an
edit form, citing §7.6. That was a misreading — §7.6 forbids DELETING a settled payment
and REISSUING a number, and the DS gives All Payments' `Update Payment` action **no
`condition` at all**. `PATCH /api/payments/{payment}` now exists, and what §7.6 does
protect is enforced field by field: the number is not in the shared field rules, so no
edit path can name it. Only D4's reversal pair is refused, which is a stated deviation.
Addendum §23.4.

**THE PAYMENT FORM CHANGES SHAPE WITH THE COA** — `Accounts.ds:24240`. Accounts Payable
hides Amount, TDS, TDS Amount, GST and GST Amount and ENABLES Payable Amount; every other
COA does the reverse. That is why the live screenshot of a payable payment shows no
Amount and no GST — hidden, not absent. The branch lives once in
`App\Domain\Payments\PaymentFieldState`; `/api/payments/options` publishes its three
outcomes so the form looks one up rather than re-implementing the `if`. It also gives the
first evidence for **§6.5's Paid lock**, which is NARROW: `Bill_No1` and
`Vendor_Order_Booking_No`, and only on the payable branch. Addendum §23.5.

**NO ACCOUNTS PAYABLE PAYMENT CAN BE SAVED HERE.** `Accounts.ds:28567` demands a Bill No
or a Vendor Order Booking on that COA, and **neither field has a column in this app**, so
the rule cannot be satisfied — on create or on edit. This is the hard consequence of
Husain's "I dont have the option to enter the bill number". `PaymentFieldState::COLUMN_FOR`
maps both to `null` so `missingColumns()` reports the gap; a test fails the day they land.
Addendum §23.6.

**Report columns are extracted.** `docs/parse_ds_report_columns.py` →
`docs/ds_report_columns.json`, **50 reports** with every action button and its enabling
condition. Use it before asking for a screenshot to learn what a column IS. But the DS
declares **138 columns on All Payments** against roughly twenty live, so **visibility and
order still come from screenshots** — same division as §21's form fields. Three
conditions there change built behaviour: `Pay` also accepts `Approval Not Required`,
`Send for Approval` tests all three spellings, and All Bills' `Create Payment` needs
Draft/Partially Paid/Overdue. Addendum §23.1-23.3.

**Not built on Payments yet, and known:** the approval engine between
`Submit for Approval` and `Paid` (§8.2's matrix is amount-banded and collides with
Backend Expenses' second amount-banded engine — addendum §11), and
**authorisation** — the §3.3 matrix is extracted and tested but not wired to a gate,
so `/api/payments` is currently open. Fine locally, a blocker before exposure.

### Settings add / edit built, 22-Aug-2026 — addendum §17

The reportbar controls (`Search`, `+`, `…`, `Save Changes`, `Remove Changes`) were
**dead chrome**: no `onClick`, and only two write routes existed in the whole app,
both on Payments. Now wired, with add, edit, search, and COA inline editing.

**The one thing not to undo.** Laravel's global `TrimStrings` middleware would have
silently destroyed live lookup keys on the first save —
`F&B STAFF MEDICAL EXPENSE ` is 26 characters stored and 25 trimmed. `bootstrap/app.php`
now exempts `api/settings/*` by closure. Four tests pin it, including one asserting
the trimmed and untrimmed forms are accepted as **distinct records**. Do not remove
that exemption while tidying.

Column order, ordering and the editable field set for all five reports live in
`App\Domain\Settings\ReportRegistry` — one definition, read by both the report
controller and the write controller, so a form and its grid cannot drift.

Deliberately absent: any DELETE route (live lookup keys with FK children),
`creator_id` as an editable field (§15.2), and contents for the `…` menu (never
seen on a Settings screenshot). Field set and order are **inferred** for all five —
no Creator *form* screenshot exists for any Settings report, and the form says so
on screen.

Two defects the browser caught that review did not: booleans rendered as the literal
text `false` on all 135 rows, and a read-only checkbox swallowing the row click so
the edit form never opened from the middle of a row.

### Vendor Master seeded, 24-Aug-2026 — addendum §18

8,063 real vendors from `master-data/Vendor_Master.csv`. The largest table here by two
orders of magnitude, and **the export corrects this file** — vendors were listed under
"no export exists".

**§13A.1 is answered, by counting rather than by grepping Deluge.** `Primary Vendor`
is the merge **pointer** (112 rows), `Primary Status` flags the **target** (93), the
two are mutually exclusive, and there are zero orphans in either direction.
`Main Primary` is **not** a merge field: it differs from the name on 739 rows of which
only 108 are merges. **Never resolve a merge through `main_primary`.**

But it is not junk — blank-versus-set separates **customer payees from trade vendors**
(1,097 of 1,099 `…(Customer)` rows are blank, against 9 of 6,964 others), confirming
and quantifying the `[UI]` note in spec §13A.1. Two populations, one table.

**The pointer is a NAME and does not always resolve.** `ETRADE MARKETING PRIVATE
LIMITED` matches four rows, so 108 of 112 get a foreign key and 4 keep only the text.
A null `primary_vendor_id` beside a non-null `primary_vendor` is a fact, not a gap.

**Three columns are all labelled `GST No.`** — and `masterDataCsv()`'s `array_combine`
silently drops duplicate headers, so reading this export by name loses two of them and
7 rows of data. Use `masterDataCsvPositional()` for any export whose header repeats a
label. Stored as `gst_no_1/2/3`, positionally: what each one *is* needs a form-level
export.

**Two things scale broke.** The Bills vendor picker was a `<select>` of every vendor —
now a server-searched combobox, because 8,063 options is both unusable and a way of
pushing the whole PII table into the browser. And Vendor Master is the first report
that searches and pages **server-side**; client-side filtering is right at 135 rows
and wrong at 8,063.

**328 names carry edge whitespace, two of them TAB characters** — invisible in every
UI. Postgres `trim()` strips spaces only, which is why the test asserts 326 and then
names the two tabbed rows separately. The no-trim rule at 328x scale.

**No write path on vendors**, deliberately: the merge *semantics* are settled, the
merge *action* is not. And this is the most sensitive read in the app — PANs, GST
numbers, phone numbers, bank details — with **no authorisation on it**.

### Zoho Books is a THIRD plane, and its contract is already in the DS

Bank was captured 27-Aug-2026 and Husain confirmed its transactions are **fetched
from Zoho Books**. `Accounts.ds` carries a whole `Books.*` namespace — four
status-filtered `banktransactions` fetches, plus `COA()`, `GetTaxes()`, `GetTDS()`,
and a mostly-commented-out write side. Addendum §7B.5.

**`organization_id` is `60040119506` — NOT the Analytics org `60042406851`.** Two
different Zoho tenants; do not assume one id for the estate. The calls use
`connection:"books"`, a named Creator Connection, so **no Books credential is
exposed in these files** (unlike the DoubleTick key).

This also explains three things in our own schema that were never labelled as Books
artefacts: `coa_accounts.books_account_id` comes from `Books.COA()`, and the `taxes`
(8) and `tds_rates` (35) masters come from `/settings/taxes`.

Still needed from Husain: the per-account `account_id` list, a read-only Books OAuth
client, and confirmation the org id is current. **Not** needed: endpoints, filters,
pagination, the `transaction_id` dedup key, or the `Payment.Books_ID` →
`Bank_Reconcilation` link — all in the DS.

### Zoho Analytics read plane wired, 25-Aug-2026

Two documents arrived, both empirical, both from the expense tracker's six months in
production against this same live instance: `docs/ZOHO_ANALYTICS_CONNECTION.md` (the
connection contract and view ids) and `docs/ZOHO_CREATOR_FIELD_NOTES.md` (694 lines of
measured behaviour and defects). Neither contains secrets — placeholders only.

**Analytics is READ-ONLY and it LAGS Creator.** Both documents say so independently.
`AnalyticsClient` therefore has no write method and must not grow one; writes are
Creator custom APIs with a per-endpoint `publickey`, deliberately not built. And a
write can never be verified by re-reading Analytics — that mistake cost the other
team real time concluding successful writes had failed.

**THE EXPORT CONCURRENCY LIMIT IS SHARED WITH A LIVE PRODUCTION APP.** It is
account-wide, "not per application". A collision once stalled both apps' syncs for
two days. The expense tracker owns minutes `:00 :12 :24 :42 :48`, and
`ZohoViews::assertScheduleIsClear()` refuses them with 11 tests behind it — but the
guard cannot see their job table, so **any schedule must still be agreed with Tushar.**

**`all_payments` must never be bulk-exported.** It is a heavy-join QueryTable that
times out, and the failure is not a fast error — it is a ten-minute poll that ends
holding a shared slot. Flagged `avoid` and refused before a job is created. Use
`payment_master` plus the lookup views, as the other team does.

**§12 is the finding that shapes any import.** Analytics FLATTENS multi-value fields
to one silently-chosen value, measured on an expense tagged to two billing cycles
that exported tagged to one, and §15 records that no CONFIG flag was ever found to
stop it. That is exactly this project's shape — a bill spans many villas x cycles x
categories and §5.2 makes each leg a ledger entry. **So never import from a
one-row-per-bill view; import the child rows.** In the other app this produced false
"missing data" alerts indistinguishable from real gaps.

**There is no Bills view** in the accounts workspace. `expenses` is the nearest
candidate and whether it is a bill or an `Expenses_Bills` row is unestablished.
`payment_master` is a plain Table, so expect headers and expect the split legs to be
absent rather than wrong.

**Inspection comes before any importer**, because §11 measured that field key names
are per-view and unpredictable — `Payment No.` / `Payment` / `payment_no` for one
field — and their conclusion was they "could never predict it, only discover it per
view". `php artisan zoho:inspect <view>` exports one view and reports its real keys,
non-uniform rows, and ids that arrived as numbers. It writes nothing to the database.

**Untested, and stated rather than implied:** the client's token refresh, 8132
backoff, whole-job retry, both JSON payload shapes and the CSV streaming path have no
tests. They need recorded fixtures from a real export and none has been run. Only
`ZohoViews` is covered.

**Credentials:** `client_id` and `org_id` are config defaults — identifiers, not
secrets, and the guide publishes them. `client_secret` and `refresh_token` are
`.env` only. Their §9 recommends a **separate OAuth client for this app** (revoking a
shared token takes down the tracker's production sync) scoped to
`ZohoAnalytics.data.read`. Worth doing before the first export.

### The nav rail had three defects, fixed 22-Aug-2026

Worth recording because the cause is structural, not cosmetic. The flyout is a
`position: fixed` sibling of the rail — which handoff §3 is right about, since
`overflow-y: auto` on the rail would clip a child submenu. But that means:

1. `onMouseLeave` on the `<nav>` fired as the pointer crossed from rail to panel,
   so the panel closed *before it could be reached*.
2. `top` was captured once at click and never recomputed, so scrolling the rail
   left the panel floating beside a different nav item — measured at 127px of drift.
   **This is what "the scroll goes away" was.**
3. No `max-height`/`overflow` on the panel, so eight Settings children anchored low
   in a short viewport fell past the fold with nothing to scroll.

Now: hover opens (Creator's behaviour), a **220ms grace period** on close makes the
diagonal trip to a lower child survivable, hovering a leaf *schedules* a close
rather than firing one — without that, sliding toward the panel killed it mid-path —
and the panel re-measures its anchor on every rail scroll and window resize. Seven
browser assertions cover it. Reproduce at **1440×640**, where the rail's 17 items
genuinely overflow; at 768px tall it does not scroll and none of this shows.

### Three documented facts corrected by the real data — addendum §15

- **`Rent_Type` has only two live values.** Lease 180, Revenue Share 65, 9 unset,
  and **zero** of either EKOSTAY split type. §3.1 calls the unhandled-branch a
  "live correctness bug"; it is **latent**. The CHECK still admits all four so the
  domain cannot be narrowed again.
- **The category is `Luxury`, spelled correctly.** `Luxery` does not occur;
  that entry on the preserve-spellings list was stale.
- **`Uttarakand` is a real misspelling** (7 villas) and was not on the list. Added.

**Deliberately not modelled yet**, because §3.1 and §16 leave them open: the
category-scoping mechanisms (A, B, and F&B's third — "do not implement all of
them"), owner-split storage for the two EKOSTAY rent types, the Villa_Managers
and Owner_Details grids, vendor-merge state, and `Auto_Numbers` (which belongs
with Payments, and §4.4's five origins need resolving first). **Vendor-merge state
is now modelled** — §13A.1 is answered, addendum §18.1.

**§17 step 4 — schema and arithmetic done.** `bills`, `bill_amount_categories`,
`bill_split_payments`, plus the list pivots. The domain logic lives in
`app/Domain/Bills/`:

- `Money` — bcmath on fixed-scale decimal strings. **No floats touch money.**
  Columns are `decimal(16,4)`; the split divides at paisa scale (§6.3).
- `SplitAllocator` — the §5.1 degradation tiers, the §5.1/§15.1 **reconcile**
  (never clear-and-rebuild), and split-equally with the remainder on the last row.
- `SplitValidator` — §6.4 rule 1, reproduced at whole rupees as Creator has it,
  with the sub-rupee gap surfaced as a warning rather than hidden.

All three of step 4's verification criteria are tests (`tests/Unit/BillSplitTest.php`),
including the §15.1 fixture verbatim: 1 → 2 → 4 rows, type 10k/20k/30k/40k, add a
second villa → 8 rows with the amounts intact, remove the first → 4 rows flagged,
₹1,00,000 still present, save blocked.

**Suite: 34 tests, 600 assertions, all passing.**

**Step 4 is not finished as a module** — no models, no read API (step 5), no
`Expenses_Bills` generation (step 6). And two `[TODO]`s inside §6 are still open
and deliberately not guessed: which `Payable_Amount` formula is authoritative
(§6.3 — two formulas, different quantities, same field name), and the scope of the
`Paid` field lock (§6.5).

**Do not reproduce the billing-cycle auto-create.** §6.4 is emphatic: Creator
INSERTs a missing cycle during month derivation, and that is the defect that put a
junk `"9-2026"` cycle into live accounting. Validate against existing cycles and
require them to exist.

**Step 7 says STOP before Payments, Schedule Payments and Salary Payouts** until
the §16 blocking write-path questions are answered. Step 3 (the role → permission
matrix) is blocked on Husain, not on us.

## Closed from the DS, 22-Aug-2026

Now that `deluge/` is in the working set, four documented `[TODO]`s are answered —
details in addendum §10 and handoff §6:

- **Block Payment Date is enforced nowhere server-side.** One browser-side
  `on user input` handler on the Payment form, plus a field-disable rule. Nothing
  on Bills, Schedule Payments, or delete-and-regenerate.
- **The `Backend_*` triplet** is the allocation snapshot taken while nothing is
  paid, re-synced on save until a payment exists.
- **`Amount_Category` vs `Split_Payment`** — line items vs allocation, now
  DS-backed rather than inferred.
- **`Disable` really is "Disallow Manual Creation"** — a hard block at validate.

And three live defects found in the process: a `&&` where `||` was meant, making
an IGST0 branch dead code; a subform dereferenced as a scalar with `=` instead of
`==`; and **`Payment InProgress` spelled two ways in the same codebase**, so
every status comparison misses part of the data.

---

## The flow audit — index first, then work the list

Husain asked for the whole DS checked against how Creator actually works. 63,225 lines is
too many to spelunk per question, so `docs/parse_ds_handlers.py` indexes every workflow
handler with its form and event — **360 of them** — into `docs/ds_handler_index.json`.

    on add or edit 216 · on user input of 180 · on load 60 · on add 44 · on edit 36
    on success 24 · on validate 15 · subform rows 9 · on delete 5

**The 15 `on validate` handlers are the business rules** — the ways Creator says no — and
they were audited first. Pass 1 found that the Payment form refuses a save on **22
conditions** where this app refused on 2; all 22 are now in
`App\Domain\Payments\PaymentSaveRules`, wired into `storeDirect`, one test each naming
its DS line. Addendum §22.

Passes still to run, in order of how directly each touches money: the other 12 validates
(including **`Block_Payment`**, the cutoff no screenshot ever supplied) · the 180
`on user input` handlers, of which `PaymentFormCalculator` covers four · the 216
`on add or edit` · the 24 `on success` · the 5 delete guards · and a `&& ... ||`
precedence sweep, because three separate instances of that bug are now on the register
(D10, D11, and the IGST0 branch) and three is a pattern.

---

## Field types come from the DS, not from screenshots

`Accounts.ds` declares every form field's `type`, `displayname`, `row`, `column` and —
for each picklist and list — the query that populates it. `form Payment` alone carries
**130 declared fields**, parsed to `docs/payment_form_fields.json`:

```
checkbox 28 · picklist 21 · text 21 · INR 11 · list 9 · textarea 7 · section 8
email 5 · date 4 · radiobuttons 4 · number 3 · upload file 3 · grid 3 · datetime 1 · url 1
```

A screenshot shows what a control looks like; the DS says what it **is**. So do not ask
for screenshots to learn a control type — parse the form.

**What screenshots ARE for: VISIBILITY.** The DS declares 130 fields and the live form
shows about 40. `Payment No.`, `Backend Payment Date`, `Haewaya TimeStamp` and
`_staffLoanProcessed` are all declared in column 1 and absent from the screen. The DS
gives order and type; the screenshot gives the visible set. Neither substitutes for the
other. Addendum §21.

---

## Verify by rendering. Every time.

The v1 failure was ~4,000 lines of CSS written without once looking at it.
**Do not present UI you have not seen.** The harness recipe is in handoff §4;
`cap.mjs` should capture page *and* console errors and assert on rendered output
— row counts, footer text, header labels — not just take a picture.

## Working style

State assumptions, simplest thing that works, surgical changes, verifiable
success criteria per step. Browser-side validation is not a security boundary —
every prototype rule is re-implemented server-side, the split-ties-to-gross rule
especially, since Payments has no such check today (§7.4).

**Corrections matter.** When an earlier claim turns out wrong, say so plainly.
Several conclusions in this project were revised after better evidence.

## Live defects, independent of the rebuild

Open and worth raising: the hardcoded **DoubleTick API key** appears **three times** —
`Accounts.ds:16768`, `:16780` (the approval notification) and `:22851` (the
revenue-share statement). **Rotate it WITH the deployment, not before**, and in all
three places: a blind rotation silently stops approval notifications AND owner
statements. **`Eko_RS_App_Config.DoubleTick_API_Key` exists and is never read**, so
rotating through the UI changes a field nothing consults — and that field is a
column on a report. Addendum §7D.3-7D.4. Related: **only 81 of 475 employees have a
phone number**, so an approver from the other 394 is routed to correctly and never told

Also open: the **negative-HRA band** (₹21,001–21,099) is producing bad payslips
today · **`Delete Paid Payment`** sits one click from a settled
payment · duplicate approvals minting duplicate payments · ~~approved requests
with no payee~~ — **probably a reporting artefact, not missing data**: All Payment
Requests binds the *lookup* half of the duplicate `Vendor Name` pair, so every
`Add New Vendor` request shows a blank payee. Confirmed on one row 27-Aug-2026;
addendum §6.4 names the ten-second check that settles it · ~~unresolved foreign
keys on settled Backend Payments~~ — **a DISPLAY defect, not a data one**: all four
ids across two records resolve (villas Casa Zul and Lakefront Villa, location
Alibaug, and `Bank Name` via `coa.ekostay_id`). The form renders raw keys where a
name belongs; the records are sound and need no repair. Addendum §7.2.

**Both of those entries were reported from a screen rather than from the record
behind it. Check the record before believing the column.**

Also hardcoded, and found 27-Aug-2026: the **Analytics OAuth client_id and
client_secret** at `Accounts.ds:22646-22648`, against org `60042406851` — **the same
org this rebuild uses.** So the Analytics client is shared between live Creator and
us, and revoking it takes down `Standalone.proxyAnalytics` too. A separate OAuth
client for this app was already the recommendation; this makes it necessary.

Added 27-Aug-2026, **and corrected 28-Aug**. A trash bin EXISTS — `Deleted_Payments`,
a rail item, archiving a deleted payment with a deleted-by stamp and its split grid —
**and it holds 982 records.** The first reading of `Accounts.ds:31027`'s
`COA != "Accounts Payable"` guard concluded the bin sat empty, because §7.2 forces
every payment onto that account. Wrong: §7.2 is true of `Create_Payment` (payments made
FROM A BILL) and that is only **2,571 of 52,639** payments. 91% sit on `Expense` and
archive correctly.

So the defect is narrower and arguably worse: the exception covers precisely the
**bill-derived trade payables**, the payments with a vendor invoice behind them. A bin
that holds salary reversals but not settled supplier payments looks reliable and is
not. Addendum §7H.1.
Three more losses even when it fires: `Status` overwritten to `Draft`, `Expense_By`
reassigned to the deleter, and `Payable_Amount` recomputed. Plus a one-token bug at
`:31022` — `Deleted_By_User` is assigned without its `fetdele.` prefix, so a SECOND
deletion records the time and nobody. Addendum §7F.

**And `Accounts.DeletePermanentlyTrash(RecID, user)` at `:16192` checks authorisation
by comparing its own `user` PARAMETER to a hardcoded `husain@ekostayhospitality.com`.**
Caller-supplied identity, hardcoded email, standalone function — and standalone
functions are REST-invocable. Same family as the three below.

Added 22-Aug-2026, from the delete census in addendum §16.4: **`void
DeleteAllRecords()` at `F_B.ds:4645`** wipes 14 F&B tables with
`delete from <table>[ID != null]` — every row — and standalone Deluge functions
are invocable as REST endpoints. Reads like a dev reset helper left in prod.
Also **§7.2's TDS sign has been overpaying vendors** by `2·TDS − GST` on every
partially-paid bill; worth quantifying against live data.
