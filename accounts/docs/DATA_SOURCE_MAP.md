# Which table's data comes from where

Every table in this app, and where its rows are supposed to come from. Compiled
25-Aug-2026 from two live Analytics exports (`payment_master`, `villa`), the nine
CSV/JSON exports in `master-data/`, and the DS profile parse.

Three sources exist, and they are not interchangeable:

| Source | What it is | Freshness |
|---|---|---|
| **Analytics view** | `php artisan zoho:inspect <view>` — read-only bulk export | lags Creator by minutes |
| **`master-data/`** | Creator report exports, taken by hand 12–22 Aug 2026 | frozen; already stale, see below |
| **`deluge/`** | the three DS exports, parsed for structure | frozen, git-ignored |

**Nothing is imported from Analytics yet.** Both exports so far are files under
`storage/app/zoho/`. Every "should come from" below is a plan, not a wiring.

---

## 1. Tables an Analytics view can fill

| Our table | Rows now | Source view | Status |
|---|---|---|---|
| `payments` | 2 (fixture) | **`payment_master`** — 52,678 rows, 115 cols | measured; import needs decisions, see §4 |
| `payment_split_payments` | 4 (fixture) | **`payment_master`**, comma-packed columns | recoverable by parsing, not by `split(',')` |
| `bills` | 1 (fixture) | **`expenses`** (candidate) | **unverified — not yet exported** |
| `bill_split_payments` | 2 (fixture) | **`expenses`** (candidate) | unverified |
| `payment_bill_payments` | 2 (fixture) | **`bill_link_check`** — `payment_no` → bill links | unverified |
| `billing_cycles` | 1 (fixture) | **`payment_master`**, `Billing Cycles` column | **50 real cycles found, 2023–2027** |
| `villas` | 254 | **`villa`** — 45 cols vs our 18 | measured; 27 new fields available |
| `locations` | 30 | **`location`**, or villa lookups | cross-check |
| `coa_accounts` | 144 | **`coa`** | cross-check only; CSV already complete |

## 2. Tables with NO Analytics view

These stay CSV/DS-sourced. There is no vendor view, no tax view and no TDS view in
either workspace, so `master-data/` remains authoritative for them.

| Our table | Rows | Source | Note |
|---|---|---|---|
| `vendors` | 8,064 | `master-data/Vendor_Master.csv` | 8,063 real + 1 fixture. No Analytics view exists. |
| `item_categories` | 135 | `master-data/All_Item_Categories.json` | **stale — see §3** |
| `master_categories` | 10 | `master-data/All_Master_Categories.json` | verified against live: exact match, both directions |
| `taxes` | 8 | `master-data/All_Taxes.json` | |
| `tds_rates` | 35 | `master-data/TDS_Report.json` | |
| `employees` | 475 | `master-data/All_Employee_Masters.csv` | |
| `roles` / `permissions` / `permission_role` | 25 / 122 / 127 | `deluge/` via `docs/parse_permissions.py` | |
| `states` / `head_offices` | 8 / 1 | derived from `All_Villas.csv` | |
| `ca_masters` | 2 | derived from COA `CA Name` | |
| `auto_numbers` | 1 | `master-data/Auto_Numbers.json` | **stale and dangerous — see §4** |
| `employee_designations` | 0 | **nothing** | 25 candidate values on the vendor export; not authoritative |
| `employee_departments` | 0 | **nothing** | no source at all |
| `users` | 0 | n/a | no auth built |

## 3. Two cross-source hazards found by comparing them

**`item_categories` is stale by one row.** Live payments reference **131** atomic item
categories; our master has 135. Exactly one live value is absent from our master:

```
'F&B INCENTIVES'
```

Which is §13B's warning arriving on schedule: *"Item Category is user-extensible at
runtime — a new 'SALES INCENTIVE' category was added mid-project."* `SALES INCENTIVE`
is in our master; `F&B INCENTIVES` came later. The master needs re-exporting, and any
importer must tolerate a category it has never seen rather than reject the row.

(Six of our 135 appear unused on any payment, but one of those six is
`F&B STAFF MEDICAL EXPENSE ` — which IS used, under its trimmed name, per the hazard
below. So five are genuinely unused, and absence from a payment is not evidence a
category is dead anyway — see §6 of the field notes on absence.)

**ANALYTICS TRIMS A KEY THAT THE MASTER DOES NOT.** The 26-character
`F&B STAFF MEDICAL EXPENSE ` — the trailing-space lookup key this whole project's
no-trim rule is built around — appears in the Analytics payment export **trimmed**, as
25 characters. Verified by splitting the packed column *without* stripping: no item
category in that export carries edge whitespace at all.

So the two sources disagree on the same key, and a name-based join between them fails
on exactly that row. This is the concrete instance of why §6 and §11 both say join on
**record id, never on name**. Do not "fix" it by trimming the master — that breaks the
Creator-side joins that currently work.

---

## 4. What blocks the two imports that matter

**`payments` — four decisions, not just a mapping.**

1. **Six numbering series**, not one: `EKS/Haewaya` 33,408 · `EKS/PY` 16,487 ·
   `EKS/PAY` 1,344 · `REFUND-*` 1,356 (with spelling variants `REFUND-stay` /
   `REFUND-stay-`, `REFUND-experiences` / `REFUND-exper`) · `EKS/API` 42 · and three
   rows numbered bare `37`, `35`, `25`. §8 rule 8 says a replica should use **one
   series with origin as a field**; reproducing six means reproducing a defect.
2. **239 payment numbers appear on more than one record**, 506 rows involved,
   `EKS/Haewaya/12539` six times. So `payment_no` cannot carry a unique index if this
   is imported as-is — and §7.6's "a number is never reissued" is already violated
   upstream.
3. **The 42 `EKS/API` rows are the expense tracker's own writes.** §7 says exclude
   them (`payment_no LIKE 'EKS/API/%'`) or they re-import as new records — that
   mistake produced 19 duplicates worth ₹1,51,827 in one hourly run there.
4. **`Paid Amount` holds `Yes`/`No`, not money.** A column named like a decimal is a
   boolean. Mapping it to our `paid_amount` decimal would be silently wrong.

**`auto_numbers` — stale counters that would reissue live numbers.**

| Series | Live max | Ours | Gap |
|---|---|---|---|
| `EKS/PY` | 21,305 | 20,942 | **363 behind** |
| `EKS/Haewaya` | 33,293 | 32,010 | **1,283 behind** |

Until these are reconciled, any real payment this app creates takes a number that
already exists in live accounting. This is the highest-consequence item on this page
and it is a data fix, not a code fix.

---

## 5. Views with no table here at all

Registered, exportable, and nothing in this schema consumes them. Listed so the gap
is visible rather than forgotten:

- **`banks`** and **`bank_transactions`** — there is no `banks` table. Payments carry
  a `Bank Name` column, so one is implied. Note §6's warning: the banks view's
  `zoho_id` is a **different id series** from Creator form lookups, so it must not be
  joined to a Creator record id.
- **`fnb`** — F&B is not a future concern; Bills carries an F&B lookup today.
- **`personal_expenses`** — Personal Expenses (All Sources).
- The whole **`live` workspace** (bookings, booking payment types, sales, debit
  statement, CRM OCR) is the booking side and another application's domain. §13B of
  the field notes describes *that* payment model — guest names, check-in dates,
  channels like `airpay` and `razorpay` — which is **not** the same thing as the
  accounts payments in §1 above. Easy to conflate; they are different records.

---

## 6. The rule that governs all of it

Join on **record id**, never on name. Names drift (`EKOSTAY - Deltin 2 BHK Pool Villa`
vs `EKOSTAY- Deltin Villa`), lookups export as bare 18-digit ids, and the same field
is keyed differently in different views (`Payment No.` / `Payment` / `payment_no`), so
every importer needs alias lists rather than a fixed column map.

And ids are **strings**. An 18-digit id read as a number becomes a different id
(`…361075` → `…361100`) — a corruption both source documents warn about independently
and that this project has already hit once.
