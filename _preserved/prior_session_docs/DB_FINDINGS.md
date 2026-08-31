# Live server findings — 13-Aug-2026

Read-only inspection of `server.ekostay.com` (148.113.0.151). `SELECT` / `SHOW` /
`DESCRIBE` only; nothing written, no schema touched, no service restarted.

**No live data in this file.** No customer names, phone numbers, bank details,
UTRs or amounts tied to a record. Structures, type definitions, value
distributions and counts only.

---

## Answer to the first question: no, I had not built a database

Everything to date is React with in-memory fixtures. Nothing created. Which makes
this good timing — better to see real column types before writing migrations than
to correct them after.

---

## What is actually on that server

Six MySQL databases matter:

| Database | Size | What it is |
|---|---|---|
| `serv_ekostay_db` | 848k booking_history, 254k bookings | **the booking engine** — leads, guests, SLAs, payment links |
| `serv_ekostay_expense` | 105k payments, 50k payouts, 47k bank_transactions | **the settlement / reconciliation system** |
| `acco_accounts` | 63k expenses, 23k bookings | **the expense tracker** — anomaly detection, Laravel, created 03-Jun-2026 |
| `serv_ekostay_reviews`, `serv_leaddash`, `test_*` | — | out of scope |

Two independent Laravel apps consume Zoho Creator:

```
Zoho Creator (Accounts) ──┬── Zoho Analytics ──→ acco_accounts        (expense tracker)
                          └── direct API ──────→ serv_ekostay_expense (settlements)
```

### `accounts.ekostay.com` is the expense tracker, not this project

It carries 3,957 lines of its own docs — `BUSINESS_RULES.md`, `ARCHITECTURE.md`,
`DATABASE_SCHEMA.md`, `ZOHO_INTEGRATION.md`. Its purpose is **anomaly detection**:
villa-category spend against a 3-month baseline, ghost months, vendor
concentration, missing mandatory categories.

That is the app a previous session confused this project with, and which Husain
corrected on 12-Aug. Confirmed from the source: it is read-only analytics over a
Zoho export. It does not create bills, route approvals, or reconcile bank lines.

**It is not a prior attempt at the Accounts rebuild.** No overlap in scope.

---

## Findings that change our build

### 0. 🟢 §A3 answered by live data — the two EKOSTAY rent types are not in use

`OPEN_QUESTIONS.md` §A3 asked whether EKOSTAY-type villas have owner splits that
Creator fails to capture. Creator declares four values plus free text:

```deluge
Rent_Type ( values = {"Revenue Split EKOSTAY","Expense Split EKOSTAY",
                      "Revenue Share","Lease"}   others option = true )
```

Live data across 200 villas and 63k expense rows:

| `rent_type` | rows | villas |
|---|---|---|
| `Lease` | 44,787 | 144 |
| `Revenue Share` | 2,788 | 48 |
| `NULL` | 15,760 | **10** |

**Neither EKOSTAY value appears. Nor does any free-text value.** Same picture in
both the legacy CSV era and the live API era, so this is not an ingest artefact.

And the 10 NULL-rent_type "villas" are not villas:

```
Head Office Central · Alibaug Central · Lonavla Central · Ooty Central
Goa Central · Karjat Central · Igatpuri Central · Panchgani Central · Utan Central
```

They are **regional cost centres** — offices and hubs that carry expenses but have
no owner and therefore no rent type. Correctly NULL.

So the unhandled branch is unreachable in practice: every real villa is `Lease` or
`Revenue Share`, and `Revenue Share` is the branch Creator handles. **The gap is
latent, not active.** Copy-as-built remains right, and there is no spreadsheet of
uncaptured EKOSTAY splits to go looking for.

Worth keeping the validation, though — `others option = true` means a typo in the
picker would create a silent fifth category tomorrow.

### 1. 🔴 `Luxery` vs `Luxury` — Analytics normalises, Creator does not

Creator's form declares the misspelling:

```deluge
// zoho_source/Admin.ds:670
Category ( values = {"Gold","Luxery","Original"} )
```

The tracker holds **`Luxury`** — 8,916 rows, spelled correctly. And its ingest
does **no** mapping:

```php
// ZohoSyncService.php:187
'villa_category' => $this->str($r['Villa Category'] ?? null),
```

Both the legacy CSV import (1,842 rows) and the live API sync (7,074 rows) carry
`Luxury`. So **Zoho Analytics is serving a corrected value that the Creator DS
export does not show.** There is a transformation layer between Creator and
Analytics that no export reveals.

**Consequence for us.** `UI_HANDOFF.md` §2.7 says preserve `Luxery`, and that is
right for the Creator UI. But any join between our data and the tracker on villa
category will silently miss every Luxury villa. Same class of bug as the CA
report's name-substring filter.

**What we do:** store the Creator value verbatim (`Luxery`), and add an explicit
alias column rather than normalising in either direction. Never join on the label;
join on villa id.

**And the mapping is narrow — it is not a general correction layer.** Item
categories pass through completely unnormalised, misspellings intact and
inconsistent *with each other*:

```
F&B REPAIR AND MAINTAINENCE   253      STAFF RENT & ACCOMODATION     449
STAFF VEHICLE MAINTENANCE     155      F&B RENT AND ACCOMMODATION     81
SOCIETY MAINTENANCE            57
F&B VEHICLE MAINTENANCE         9
```

`MAINTAINENCE` / `MAINTENANCE` and `ACCOMODATION` / `ACCOMMODATION` coexist as
distinct categories in live data. Anything grouping by category name is already
splitting these apart.

So Analytics maps exactly one field that we know of — `Villa Category`. Treat every
other value as passing through raw, and **do not assume any other correction
happens.** That is the useful generalisation: the DS export is not a complete
description of what Analytics serves, and the difference is per-field.

### 2. 🟡 `Payable = Invoice − TDS` confirmed by 16,405 live rows

| Relationship | Rows |
|---|---|
| payable == invoice | 16,285 (99.3%) |
| payable < invoice | 85 |
| payable > invoice | 35 |

Payable equals invoice except where TDS applies — exactly as
`OnInputGrossAmountCE` computes it, and with **no** `Paid_Amount` term. Our
Payments module is correct.

The 35 rows where payable **exceeds** invoice are worth a look eventually — that
should not happen under the formula. Not urgent; likely the salary path, where
both fields get the same expression.

### 3. 🟢 `decimal(14,2)` is already the house standard

`expenses.amount`, `payments.payment_amount`, `bank_transactions.withdrawal` /
`deposit`, `reconciliation_payments.payment_amount` — all `decimal(14,2)`.
`payouts` uses `decimal(16,2)`.

No floats anywhere in the money columns. Our recommendation matches what the team
already does, which makes it an easy decision rather than a new one.

**One adjustment:** use `decimal(16,2)` to match `payouts`, not `(14,2)`. The
largest single expense observed is ₹22.7 lakh; `(14,2)` caps at ₹99,99,99,99,999
so either is ample, but matching the wider existing column avoids a truncation
surprise on import.

### 4. 🔴 `Payment_Status = "Open"` is NOT in the live data

I built the dotted-underline "dirty value" treatment for `Open`, on the strength
of §7.3 saying it was confirmed in live data. The `payouts` table disagrees:

```
Paid 50,990 · Draft 39 · Submit for Approval 31 · Approved 20
Sent for Approval 18 · Send for Approval 3 · Approval Not Required 1
```

No `Open`. **But** — that column is `payouts.status`, which maps to Creator's
`Status`, not `Payment_Status`. The tracker does not ingest `Payment_Status` at
all, so this neither confirms nor refutes §7.3.

**Action: none.** Keep `Open`, keep the marker. `Create_Payment` demonstrably
writes it (`zoho_source/Accounts_LOGIC.ds`), and absence from a downstream sink
that never ingests the column is not evidence.

Worth noting for its own sake: **both** `"Sent for Approval"` (18) and
`"Send for Approval"` (3) appear in live data. The duplicate enum is real, not a
DS-export artefact.

### 5. 🟡 `COA[Hide == true]` — the flag is misnamed, not inverted

`payouts.coa_type` shows what the picker actually offers:

```
expense · accounts_payable · other_expense · bank · cash · other_asset
```

Every COA type appears. So `Hide == true` does not mean "hidden" — it is closer to
"available for selection". §7.5 wondered whether the field was misnamed or the
condition inverted; live data says **misnamed**.

**Action:** keep the filter as-is (copy as built), and rename the column in our
schema to `selectable` with a comment recording the Creator name. The behaviour
does not change.

### 6. 🔴 The `PUT_THE_ROTATED_TOKEN_HERE` placeholder is failing in production

I flagged this from source. The server confirms the impact.

The receiving endpoint is **well built** — timing-safe `hash_equals`, idempotency,
an enable flag, and a rule that a settled payout is never auto-deleted by an
inbound call. Its docblock is candid about why it exists:

> *"a no-force call on a Draft payment now DELETES it (learned the hard way — it
> removed two real payments during testing)"*

A real 48-character token is configured and the webhook is enabled. So Creator
sending the literal placeholder gets a 401. Four bad-token warnings are logged,
from two different IPs, on 05-Aug and 07-Aug.

`zoho_payment_deletions` holds 4 rows, all from 05-Aug testing — two `not_found`,
one `matched`, one `duplicate`. **The last three are from `148.113.0.151`, the
server itself** — i.e. manual curl tests, not Creator. The two from an external IP
both resolved `not_found`.

**Net effect: no delete notification from Creator has ever succeeded.** When a
payment is deleted in Creator, the settlement system is never told. Its own docs
explain why that cannot be caught by polling — a deleted Draft leaves no trace to
detect, so absence carries no information.

**This is a live integration gap, not a rebuild item.** Someone needs to paste the
real token into `Accounts.Payment.OnDeleteValidate`. Independent of anything we
build.

### 7. 🟢 `payouts` validates the Payment field set

Our Payment module maps cleanly:

| Creator | `payouts` |
|---|---|
| `Payment_No` | `payment_no` (unique) |
| `Status` | `status` |
| `Withdrawal_Matched` / `Deposit_Matched` | same names, `tinyint(1)` |
| `Payable_Amount` / `Invoice_Amount` / `Amount` | same, `decimal(16,2)` |
| `COA` / `Bank_Name` / `Item_Category` | same |
| `Villa_Name` / `Location` | same |

Note it is **flattened** — no split legs. The tracker takes the header and drops
the allocation, which is consistent with its analytics purpose and confirms that
`Split_Payments` is ours alone to model.

### 8. 🟡 Two different things are called `payments`

- `serv_ekostay_expense.payments` — **guest-side inbound**: guest_name,
  guest_phone, checkin, checkout, `payment_type ∈ {bank_transfer, airpay, CASH,
  airnb, qr_scanner, mmt, razorpay, adjusted}`
- `serv_ekostay_expense.payouts` — **vendor-side outbound**, which is our domain

Our `payments` table must not be confused with either. Name ours
`vendor_payments`, or schema-qualify it.

### 9. 🟡 `bank_transactions` already exists, with a different shape

The settlement system's version has `txn_key` (unique), `withdrawal`, `deposit`,
`zoho_match_payments` (a **text** blob), `is_duplicate`, `deposit_class`.

`zoho_match_payments` as text is the same anti-pattern `Bank_Match_Line` fixed in
Creator — a list stuffed into a column instead of a junction table. Our
`bank_match_lines` design stands; there is no existing table to reuse.

---

## Revised schema decisions

| Decision | Before | After live inspection |
|---|---|---|
| Money type | `NUMERIC(14,2)` | **`DECIMAL(16,2)`** — matches `payouts` |
| Villa category | preserve `Luxery` | preserve **and** add an alias column; never join on the label |
| COA `Hide` flag | keep, note the oddity | rename to **`selectable`**, behaviour unchanged |
| `Payment_Status = Open` | keep, mark as dirty | **unchanged** — downstream absence proves nothing |
| Our payments table | `payments` | **`vendor_payments`** — two other `payments` exist |
| Bank match lines | junction table | **unchanged** — confirmed as the right call |

None of these change the UI. Items 1, 3 and 5 change the schema; the rest are
naming and documentation.

---

## Not our problem, but worth passing on

1. **The webhook token.** Creator still sends `PUT_THE_ROTATED_TOKEN_HERE`.
   Every delete notification since 05-Aug has 401'd. Fix is one paste.
2. **35 payouts where payable > invoice.** Should be impossible under
   `payable = invoice − tds`. Probably the salary path. Worth one query.
3. **683 expense rows with `amount = 0`.** Legitimate or ingest artefacts —
   unknown.
4. **`acco_accounts.expenses` holds rows dated to 2027-02.** Future-dated billing
   months, presumably advance rent. Not an error, just surprising in a
   month-baseline anomaly tool.

---

## Access note

Connected with the supplied key as `root`. That is full read **and write** on
every database on the host, including the booking engine. I confined myself to
reads, but a scoped read-only MySQL user would be the safer arrangement for any
future work of this kind — it makes the restriction structural rather than a
matter of my discipline.
