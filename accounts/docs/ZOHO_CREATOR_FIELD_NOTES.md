# Zoho Creator + Analytics — Field Notes for a Replica Build

**Compiled:** 2026-08-06 · **Source:** ~6 months integrating a Laravel app against a live Zoho Creator
accounting/booking system (India DC), plus the failures that taught us each rule.

## What this is — and what it is NOT

This is an **integrator's** account of Zoho Creator: its API surface, its response contracts, its
observed behaviours and its defects, learned by building against a production instance.

**It is not** Creator's internals, schema, source, or admin/Deluge documentation. Nobody here had
access to any of that. Every statement below is either quoted from a live response or was measured.

Read it as *"here is how the real thing behaves, and where it bites"* — a conformance target and a
list of traps, not a blueprint of the original.

### If you are building a replica, read this first

**What this document gives you**

- The **API contract** to build against — endpoints, auth, request/response shapes, error codes
  (§1–5). If your replica matches this, existing integrations keep working.
- The **payment domain model** we can see: fields, channels, status codes, split structure, the
  date-basis problem, and real-world scale (**§13B**). This is the closest thing here to "what to
  build".
- A **ranked list of defects not to reproduce** (§8) and business rules that turned out to be
  configurable data, not constants (§14).

**What it does NOT give you — you must get these elsewhere**

- Creator's **internal schema** — real table/form definitions, relationships, indexes. Everything in
  §13B is reverse-engineered from a denormalised export.
- The **UI / form builder / Deluge scripting engine** — the actual product surface. Not covered at all.
- **Permissions, roles, workflow, approval chains.** We saw a `Draft → Approved → Paid` progression
  from the outside but never the rules that drive it.
- **Anything about how payments are captured**, only how they are read back. There was no create-UI
  on our side of the boundary.
- **Non-payment modules** — CRM, inventory, and the rest of Creator.

**Sizing this honestly:** if the goal is a drop-in replacement for the whole of Zoho Creator, this
covers a small slice. If the goal is *"a system our apps can point at instead of Creator, for
payments and expenses"*, then §13B plus the API contract is a workable starting spec — with the
schema gaps above still to be filled by someone with admin access to the real instance.

Confidence is marked throughout:
- **[MEASURED]** — observed directly against live, usually more than once.
- **[REPORTED]** — told to us by the Zoho-side developer; not independently verified.
- **[INFERRED]** — our best explanation of observed behaviour. Treat as a hypothesis.

---

## 1. The two-plane architecture (the single most important thing)

A Creator deployment presents **two entirely separate APIs** with different auth, different
domains, and different consistency guarantees.

| | **Zoho Analytics** | **Zoho Creator custom APIs** |
|---|---|---|
| Purpose | READ / reporting | WRITE (the only write path) |
| Auth | OAuth 2 refresh-token → bearer | `publickey` query param, **one per endpoint** |
| Domain | `analyticsapi.zoho.in` | `zohoapis.in/creator/custom/<app>` |
| Shape | Async bulk export jobs | Single JSON POST, synchronous |
| Freshness | **Lags Creator** | Authoritative |

> **[MEASURED] Analytics lags Creator.** After a successful Creator write, re-exporting the
> Analytics view does **not** reliably show it. We wasted a long time "verifying" writes this way and
> concluding they had failed. **Verify a write by re-POSTing and reading the response, never by
> re-reading Analytics.**

A replica must decide whether to reproduce this split. It is a real source of user confusion —
"I changed it, why does the report still show the old value?" — and if your replica is
read-your-writes consistent, that is a genuine improvement, but integrations written against real
Creator may *depend* on the lag being tolerated.

---

## 2. Authentication

### 2.1 Analytics — OAuth 2, refresh-token grant

```
POST https://accounts.zoho.in/oauth/v2/token
  grant_type=refresh_token & refresh_token=… & client_id=… & client_secret=…
→ { "access_token": "…", "expires_in": 3600 }
```

Every subsequent call needs **two** headers — missing the org id is a common 401:

```
Authorization:     Zoho-oauthtoken <access_token>
ZANALYTICS-ORGID:  <org id>
```

- Access tokens last ~1 hour. **[MEASURED]** We cache for **50 minutes** and refresh on demand.
- The refresh token is long-lived but **can be revoked server-side without warning**. Handle
  "invalid refresh token" as an alert-worthy condition, not a retry.
- **Data centres are separate deployments.** `.in`, `.com`, `.eu` are not interchangeable — the
  same account does not exist across them. A replica should make the DC/region explicit.

### 2.2 Creator — per-endpoint public key

```
POST https://www.zohoapis.in/creator/custom/<app>/<API_Name>?publickey=<key>
Content-Type: application/json
```

**[MEASURED]** Each custom API has its **own** key. There is no single app-wide credential. Ours:

| API | Purpose |
|---|---|
| `Update_Expense_Billing_cycle` | move an expense to another billing month |
| `Update_External_Payment` | create a payment |
| `Delete_External_Payment` | delete a payment |

Design implications for a replica:
- A leaked key exposes exactly one operation — good blast-radius design, worth copying.
- But there is **no rotation, no expiry, no per-caller identity, and no audit of who called**. If you
  improve one thing here, make it caller identity.

---

## 3. Analytics: the async bulk-export flow

Exports are **jobs**, not requests. Three steps:

```
1. CREATE   GET /restapi/v2/bulk/workspaces/{ws}/views/{view}/data?CONFIG={"responseFormat":"json"}
            → { data: { jobId } }
2. POLL     GET /restapi/v2/bulk/workspaces/{ws}/exportjobs/{jobId}
            → { data: { jobStatus, downloadUrl } }
3. DOWNLOAD GET <downloadUrl>
```

### Hard-won operational rules

**[MEASURED] Poll for up to ~10 minutes.** Our large bookings view genuinely takes minutes. We poll
300 × 2s.

**[MEASURED] Abandoning a poll early is actively harmful.** The job keeps running and keeps holding
a concurrency slot. Early abandonment caused a **slot pile-up** that blocked all later exports —
one of our worst outages. Poll to completion or cancel explicitly.

**[MEASURED] Concurrent exports are limited account-wide** (`ASYNC_EXPORT_LIMIT_EXCEEDED`,
`errorCode 8132`). Not per-user, not per-workspace — **account-wide**, so unrelated syncs compete.
We retry 4× with a **45s** backoff.

**[MEASURED] Big exports fail intermittently** with a bare `ERROR OCCURRED` under load. A fresh job
usually succeeds. We retry the whole job 3× with 20s backoff. Treat as transient.

**[MEASURED] Fail fast on a failed status.** Poll until you see a status containing `fail`/`error`
and stop — do not poll to the timeout, it just holds the slot longer.

**[MEASURED] The download payload has two shapes**: a bare JSON array, or `{ "data": [...] }`.
Handle both.

**[MEASURED] Server-side filtering** via `CONFIG.criteria`, e.g. `"Item_Category" != ''`. Field
names are quoted with `"`. Filtering at source is far cheaper than downloading and filtering
locally — but see §7 on why we mostly stopped trusting it.

**View types differ.** A QueryTable is async-export-only; a live-connect table also supports sync
export. Same bulk endpoint works for both, so just always use bulk.

---

## 4. Creator writes: the response contract

This is the part a replica must get exactly right, because the failure mode is silent.

### Success

```json
{ "result": { "success": true, ... }, "code": 3000 }
```

### ⚠️ The silent-failure trap — [MEASURED]

```json
{ "code": 3000 }
```

A **bare `code: 3000` with no `result` object** means the Deluge function ran and silently did
nothing. `3000` reads like success. **It is a failure.**

```php
// The check that matters:
$res = $json['result'] ?? null;
if ($res === null)              → FAILURE (body never arrived / function did nothing)
if (($res['success'] ?? false) !== true) → FAILURE (with $res['error'] as the reason)
```

**[MEASURED] Errors come back as HTTP 200.** Never branch on HTTP status alone:

```json
{ "result": { "success": false, "error": "STATUS_NOT_DELETABLE", "message": "…" } }
```

A replica should return honest HTTP status codes. If you must stay wire-compatible, at minimum
never emit a bare success code with no result body.

---

## 5. Documented API behaviours worth replicating (or fixing)

### 5.1 `Update_Expense_Billing_cycle`

```json
{ "expense_id": "<creator record id>", "month": "June", "year": "2026" }
```

- **[MEASURED] The month must be the full English NAME.** Sending the number `9` **auto-created a
  junk billing cycle called `"9-2026"`** in live accounting. The API stores the value *literally* and
  creates the cycle if absent. We now validate against a hardcoded month-name list before sending.
  → *A replica should validate enum inputs and never auto-create master data from a malformed value.*

- **[MEASURED] It updates the expense SPLIT ROW, not the payment header.** After a successful call
  the "All Payments" view still shows the old cycle. This is not lag — it is a different record. We
  discovered this after 55 backfilled corrections appeared not to work.
  → *Document which record an endpoint mutates. Ours took days to establish.*

### 5.2 `Update_External_Payment` (create)

Every lookup is a **Zoho record ID, never a name** — vendor, bank, COA, villa, item category. Names
are not accepted. This means a replica's clients need an id-resolution layer and a way to discover
ids in the first place (ours came from manual admin lookups, which does not scale).

```
Item_Category ← record id      Payment_Date ← "dd-MMM-yyyy"
Bank_Name     ← record id      Billing_Cycles ← [{month:"June", year:"2026"}]
Vendor_Name   ← record id      Amount / Payable_Amount / Invoice_Amount
```

- **[MEASURED] DEFECT — multi-split writes lose rows.** A payment with N villas / N billing cycles
  saves **only 1 of N split rows** ("Expected N split rows, found 1"). We work around it by always
  posting exactly one villa and one cycle. **Do not reproduce this.** If your replica supports
  splits, make them atomic.

- **[MEASURED] Payment numbering is server-assigned and not stable across recreate.** A deleted and
  re-created payment gets a **new number** (`EKS/API/0022` → `EKS/API/0027`). Any external system
  keyed on payment number will silently drift.

- **[MEASURED] Numbering series differ by origin.** API-created payments got `EKS/API/…` while the
  UI/export used `EKS/PY/…`, so the two could not be reconciled by number at all. **A replica should
  use one series, or expose origin as a separate field rather than encoding it in the identifier.**

### 5.3 `Delete_External_Payment`

```json
{ "payment_id": "<numeric id>", "force": false }
```

| Behaviour | Note |
|---|---|
| `payment_id` **overrides** `payment_no` | **[MEASURED]** Sending both, with a wrong id, deletes by id. Send exactly one. |
| `STATUS_NOT_DELETABLE` | refuses anything not Draft |
| `NOT_AN_EXTERNAL_PAYMENT` | **[MEASURED]** fired even for payments the API itself created |
| `force: true` | **skips every check**, explicitly confirmed in the response message |
| On a real Paid payment | **[MEASURED]** returns `status: ""` and `payment_status: ""` — the API **cannot** be relied on to tell you something is paid |

> ### ⚠️ The most expensive lesson in this document — [MEASURED]
>
> **There is no read-only mode, and a no-force delete of a Draft record succeeds and destroys it.**
>
> We used the delete endpoint to *probe whether a payment existed*, reasoning that a non-deletable
> record would simply be refused. It is refused — **but a Draft one is deleted.** This destroyed
> **17 real payments (₹93,884)** across two incidents, all of which had to be re-created by hand
> under new payment numbers.
>
> The endpoint only *looks* safe because a non-Draft record happens to be rejected. That is a side
> effect of a guard, not a read mode.
>
> **For a replica, three rules:**
> 1. Ship a **read-only status lookup** alongside any destructive endpoint. Its absence is what
>    forced the dangerous workaround.
> 2. Never let a destructive call double as an existence check — support `HEAD`/`GET` on the resource.
> 3. Make a `force` flag that skips *safety* checks impossible to reach for records in a
>    settled/paid state, server-side.

**[MEASURED] There is no read API for payments.** `Get_External_Payment` and every variant we tried
returned *"Custom API doesn't exist"*. Read endpoints must be authored individually in Deluge; none
existed. **A replica should default to CRUD-complete resources.**

---

## 6. Data quirks that will bite any consumer

**[MEASURED] 18-digit record IDs overflow spreadsheet precision.** Exporting through Excel/Sheets
silently converted IDs to scientific notation — `2.924820000027315e+17-r5` — permanently corrupting
them in the destination. **Treat record ids as opaque strings end to end**, and if your replica has a
CSV export path, quote them or force text format.

**[MEASURED] Two different date fields, and they disagree.** `Billing Month` (accounting period) and
`Payment_Date` (when money moved) routinely fall in different months. Reports bucketed by one will
never reconcile with reports bucketed by the other. A replica should name such fields unambiguously
and make the bucketing basis explicit in every report.

**[MEASURED] Bulk exports return unstable row order.** Sort explicitly; never rely on export order.

**[MEASURED] Master-data names are not stable or unique.** Villa/vendor names drift
(`EKOSTAY - Deltin 2 BHK Pool Villa` vs `EKOSTAY- Deltin Villa`), so name-based joins fail.
Everything must join on record id. This is right, but it means **id discovery is a first-class
problem** a replica must solve — ours had no good answer.

**[MEASURED] Deletions are invisible.** Nothing in the export marks a deleted record; it is simply
absent. Absence is also what a filtered/failed/paginated-short export looks like, so **absence must
never be inferred as deletion.** We enforce this as a hard rule after nearly mass-deleting on a
partial export. → *A replica should expose soft-delete flags or a deletions feed. This is the single
biggest sync-correctness gap.*

**[MEASURED] Webhooks: none observed.** No deletion or change notification exists; we had to ask the
Zoho-side developer to author an outbound call by hand. Everything is poll-based. **[REPORTED]**
Creator can make outbound calls from Deluge, so this is a "nobody built it" gap rather than a
platform limit. A replica with real webhooks would be strictly better.

---

## 7. Sync patterns that survived contact with production

**Upsert on the Zoho record id.** It is the only stable natural key.

```php
Expense::updateOrCreate(['zoho_id' => $record['ID']], $data);
```

**Never key on a local row id when the table is full-replaced** — ids churn on every sync and you
will re-import or double-count.

**Guard locally-authored corrections.** Where a user has corrected a synced value, the sync must not
overwrite it. We keep corrections in **side tables keyed by the business id** (not the row id), so
they survive a delete-and-reload of the source data entirely. This pattern has repeatedly saved us
and is worth adopting wherever a replica lets users edit imported data.

**Stream large syncs.** Loading a full export into memory OOM'd the process. Stream and batch.

**Filter locally unless you have verified server-side filtering.** We found a sync that appeared to
filter at source but was actually passing `criteria = null` and filtering after download. Verify
which is happening; the performance difference is large and the bug is invisible.

**Expect duplicates from your own writes.** Once we began creating payments via API, those payments
came back in the next expense export and were re-imported as new records — **19 duplicates
(₹1,51,827) in a single hourly run**. We now exclude our own series (`payment_no LIKE 'EKS/API/%'`)
on import. **A replica should tag record provenance** (`created_via: api|ui|import`) so consumers can
filter their own writes. This is cheap to add and we would have paid a lot for it.

---

## 8. What a replica should deliberately do differently

Ranked by how much pain the original caused us:

1. **Read-only status endpoints for every resource.** Their absence directly caused the destruction
   of 17 real records.
2. **Never return a success code with no result body.** Silent no-op is the worst failure mode.
3. **Honest HTTP status codes.** Errors as 200 defeat every standard client and retry layer.
4. **A deletions feed or soft-delete flag.** Absence-is-deletion is unsafe and unfixable downstream.
5. **Provenance on every record.** Prevents the re-import loop entirely.
6. **Atomic multi-split writes**, or reject the request. Never partially save.
7. **Stable identifiers across delete/recreate**, or a documented immutable surrogate key.
8. **One numbering series**, with origin as a field.
9. **Real webhooks** for create/update/delete.
10. **Read-your-writes consistency**, or state the lag explicitly in the response.
11. **Validate enums; never auto-create master data** from a malformed value.
12. **Per-caller identity and key rotation** on write endpoints.
13. **Export multi-value fields as arrays, never a silently-chosen scalar** (§12) — the flattening
    causes false "missing data" alerts that are indistinguishable from real gaps.
14. **Stable field CODES, not human display labels, for both filtering and reading** (§10, §11) —
    labels vary by view and carry punctuation, breaking every criteria filter and key lookup.
15. **Return lookups as `{id, name}` together and resolve names at read time** (§11) — a master-data
    rename must not read as stale data, and name-based joins must be impossible to write by accident.
16. **Reconciliation deduction rates + match tolerances are versioned data, not constants** (§14).
17. **Parse provider-packed strings at ingest and size for the documented max** (§13) — a hidden
    extra split portion is money dropped on the floor.

---

## 9. Reference: our config surface

Useful as a checklist of what an integration actually needs to be told.

```php
// Analytics (read)
accounts_domain   https://accounts.zoho.in
analytics_api     https://analyticsapi.zoho.in
org_id, client_id, client_secret, refresh_token
workspaces        [accounts, live]
export_job_tries  3          // whole-job retry on transient failure
export_poll_max   300        // ×2s ≈ 10 min

// Creator (write)
creator_base      https://www.zohoapis.in/creator/custom/<app>
creator_keys      one publickey PER custom API

// Every lookup is a record id
coa_id, villa_id, location_id, vendor ids, bank ids, item_category ids
```

**Operational note:** we keep a `dry_run` flag on every write path that builds and validates the
full payload, logs it, and returns a synthetic success **without calling Zoho**. Because every real
call creates a Draft record in live accounting that must be deleted by hand — and because deletion
is the dangerous operation — this is close to essential. **A replica should ship a real sandbox.**

---

## 10. Analytics `criteria` filtering — the exact traps

§3 mentions server-side filtering exists. When we actually leaned on it to fetch single records, it
bit in three specific, reproducible ways. All **[MEASURED]**.

**Column names in `criteria` are the Analytics DISPLAY label, verbatim — including trailing
punctuation and spaces.** The payment-number column is literally `Payment No.` **with a trailing
period**. Filtering on `"Payment No"` (no period) returns `UNKNOWN_COLUMN_IN_FILTERCRITERIA`
(`errorCode 7330`). There is no fuzzy match and no hint which column you meant.

**Column names go in double quotes; string literals go in single quotes.** The grammar is
SQL-like but strict:

```
"Payment No." = 'EKS/PY/15397'
```

Double-quote the identifier, single-quote the value. Getting them backwards produces a different but
equally opaque error (it tries to read your literal as a column). We burned real time on this because
the message points at the wrong token.

**The display label and the export KEY are not the same string** (see §11) — so the name you
*filter* on and the name you *read back* can differ within one view. Filter by `"Payment No."`,
receive the value under key `Payment No.`, but in a different view the same datum is keyed
`Payment` or `payment_no`.

→ *A replica should accept a stable field CODE for filtering (not a human label that carries
punctuation), and return a machine-readable error naming the unknown column and the ones that exist.*

---

## 11. Field naming is per-view and unstable — [MEASURED]

The single most time-consuming data-layer surprise, and not obvious from §6.

**The same logical field has different key names in different views.** Payment number arrived as
`Payment No.` in one view, `Payment` in another, `payment_no` in a third. Villa is `Villa_Name`
here, `Villa Name` there. Our sync code carries **alias lists** for every field it reads and tries
each in turn:

```php
$no = $this->g($r, ['Payment No.', 'Payment No', 'Payment Number', 'payment_no']);
```

**[INFERRED]** this reflects whether the column is a base-table field, a lookup, or a formula in
that particular view — but we could never predict it, only discover it per view.

**Lookups export as record IDs; you resolve them yourself against a separate lookup view.** COA,
bank and location arrive on the payment row as **record ids**, not names. To render "EKOSTAY LLP 1"
we export the COA view once, build an `id → {name, type}` map, and resolve every payment against it.
Two consequences a replica must plan for:

- The lookup view can be **renamed underneath you**: a COA account's *name* changed
  (`EKOSTAY LLP 1` → `Haewaya EKOSTAY LLP`) while its *id* stayed constant. Rows synced before and
  after the rename showed different names for the **same id** — correct behaviour (we mirror the
  current name), but it looks like stale data to a user and generated a "why isn't this updated?"
  ticket. Resolve names at read time from the id; never freeze a resolved name.
- If the lookup export and the fact export are fetched in separate jobs (they must be — different
  views), they can be **inconsistent with each other** under the Analytics lag. Fetch the small
  lookup view in the same sync pass, close in time to the fact view.

→ *A replica should return either the id AND the resolved name together, or a stable field code —
never a bare human label that varies by view.*

---

## 12. Multi-value fields are FLATTENED by Analytics — [MEASURED]

This is a genuine data-loss trap, distinct from the §1 lag, and it silently under-reports.

A Creator field can hold **multiple values** (Creator's UI showed one expense tagged to **two**
billing cycles, `March – 2026` **and** `April – 2026`). The **Analytics export of that field returned
only ONE value** (`Billing Month = "Mar 2026"`). The second cycle is simply gone from the export —
no delimiter, no array, no second column.

Downstream this manifested as a **false "missing data" alert**: our monthly-completeness check saw
no electricity bill in the April bucket for those villas and flagged them, because the bill had been
filed against both months in Creator but reached us tagged to March only. We chased it as a sync bug;
it is an export-fidelity bug.

**[INFERRED]** the flattening is Analytics picking a representative value from a multi-select when
projecting it into a flat tabular view. We never found a CONFIG option to expand it.

→ *A replica must decide, per field, whether it is single- or multi-valued, and a multi-valued field
must export as an array (or repeated rows), never a silently-chosen scalar. If a field can drive a
"data present?" check, losing its other values causes false negatives that look exactly like missing
source data.*

---

## 13. Settlement-provider payout data arrives as opaque encoded strings — [MEASURED]

Not Creator itself, but it reaches consumers *through* the Creator/Analytics export and every replica
of an accounting system will meet the same shape, so it belongs here.

A third-party settlement (our payment gateway) splits **one customer payment across multiple
destination bank accounts**, and encodes the split inside single string fields on the exported row:

- **Per-bank split amounts** live in flat numbered columns `id-1` … `id-10` (up to ten portions;
  most rows use one or two, a handful use four, we saw exactly one use five). Unused slots are `0` /
  `N/A`, so a consumer must read all ten and treat blanks as absent — you cannot tell the arity from
  a count of non-empty columns without scanning them.
- **The UTR / bank-reference field packs multiple remittances into one string** with a bespoke
  delimiter:

  ```
  FCM-260502MPMUT4:|:420188.75::FCM-260502MPMUU3:|:84906.25
  ```

  `::` separates remittances, `:|:` separates a remittance's ref from its amount. This has to be
  parsed, not read.

- **A related "account relationship type" field** is similarly `::`-packed
  (`9913324159:Self||8848475714:Third Party Account||…`), pairing each destination account id with a
  role via yet another (`:` / `||`) convention.

→ *Lessons for a replica: (1) never store a provider's packed string as if it were atomic — parse it
into rows at ingest; (2) size for the documented maximum (ten portions), not the common case (one),
or you silently drop money onto the floor — we shipped a UI that showed only three portions and hid a
real fourth-bank split of ₹1,13,050 until it was reported; (3) reconcile the sum of the parsed
portions back to the row total and surface the residual, because provider fees/round-off mean the
portions do **not** always sum to the headline amount.*

---

## 13B. The PAYMENT domain model — what a replica actually has to store

Sections 1–12 describe how to *call* Creator. This section describes what a payment *is*, which is
what you need to build the thing rather than integrate with it.

**Caveat:** this is the shape as seen through the Analytics export, denormalised into one row per
payment. The true Creator-side schema (its forms, subforms and relationships) is **not** visible to
us. Field names below marked `Backtick_Case` are the literal Analytics column names; lower_case ones
are our local names. **[INFERRED]** where the underlying structure is a guess.

### Source vocabulary — literal Analytics column names [MEASURED]

```
ID                Villa_Name        Payment_Date      Item_Category    Master_Category
Amount            Vendor_Name       Bank_Name         COA              Particulars
Bill_No           Expense_By        Payment           Link             Type
Billing Month     Booking Status    Booking Source    Location         BHK
Checked In Date   Check Out Date    Net Stay Tariff   Rent Type        Villa Category
New_Gross_Amount
```

Note the **inconsistent casing convention** — `Payment_Date` (underscored) sits beside
`Billing Month` and `Checked In Date` (spaced). Both appear in the same export. A replica should
pick one convention; if wire-compatibility matters, you must reproduce this inconsistency exactly.

### A payment record, as consumed [MEASURED]

| Field | Type | Notes |
|---|---|---|
| `payment_no` | string | Server-assigned. **Not stable across delete/recreate** (§5.2). |
| `booking_no` | string | e.g. `EKO10332581`. The business key that survives everything. |
| `payment_date` | date | When money moved. |
| `month` | `YYYY-MM` | Derived bucket — **from payment date, not check-in** (see below). |
| `payment_amount` | decimal(14,2) | |
| `payment_type` | enum-ish | channel: see distribution below |
| `status` | int | booking status code, **not** payment status |
| `bank_name` / `zoho_bank_name` | string | raw vs mapped — two fields because names drift |
| `villa_name`, `location` | string | property |
| `guest_name`, `guest_phone` | string | |
| `salesperson` | string | drives commission |
| `checkin`, `checkout` | date | stay dates |
| `pending_price` | decimal | balance outstanding |
| `split_legs` | JSON | see below |
| `payment_verified` | bool | |
| `reference_link` | text | link back to the source document |

**Scale for sizing:** ~105,000 payment rows over roughly a year of operation.

### Channel distribution [MEASURED] — the long tail is the hard part

```
bank_transfer  35,859      airnb        6,263
(blank)        22,662  ←   qr_scanner   5,178
airpay         16,401      mmt          2,695
CASH           14,744      razorpay     1,095
                           adjusted         5
```

**22,662 rows (22%) have no channel at all.** A replica that makes `payment_type` mandatory will not
be able to represent one fifth of real data. Blank is a legitimate state, not dirty data.

### Booking status codes [MEASURED]

```
3  = Confirmed     101,110      4  = Disapproved   71
6  = Cancelled       3,332     13 = (rare)          1
1  = Approved          358     '' = (blank)        30
```

**[MEASURED] `status` is the BOOKING's status, not the payment's.** A cancelled booking (6) still has
real payments attached — money genuinely arrived and may later be refunded. Any replica that filters
payments by booking status will silently lose real money from its totals; we hit exactly this.

**[MEASURED] Payment status is a separate axis** (`Draft` / `Approved` / `Paid`) and lives only on
the accounting side. There was **no API to read it** (§5.3) — we could only learn a payment was Paid
by it appearing in an export. **A replica must expose payment status as a first-class readable
field.** Its absence was one of the most costly gaps.

### Split payments — one payment, many bookings [MEASURED]

A single payment can settle several bookings:

```json
[{"booking_no":"EKO10332581","villa_name":"Casa Del Sol","checkin":"2026-08-09","amount":17037},
 {"booking_no":"EKO10332578","villa_name":"Skyfall Dew Drops","checkin":"2026-08-08","amount":17037}]
```

**[INFERRED]** On the Creator side this is almost certainly a subform/line-item table; we only ever
see it flattened into one JSON column. A replica should model it as a proper child table —
`payment` 1→N `payment_allocation` — because every consumer has to reconstruct that relationship
anyway, and the flattening is what makes reconciliation hard.

This is also the structure implicated in the **partial-split write defect** (§5.2): posting N legs
saved only 1.

### The date-basis problem — [MEASURED], and it recurs constantly

Payments carry **three** dates, and reports bucket by different ones:

- `payment_date` — when money moved
- `checkin` / `checkout` — when the stay happens
- `Billing Month` — the accounting period

A June-check-in export of 129 bookings spread across **April (9), May (28), June (92)** by booking
date. A June-payment-date view of the same data shows a different set again. **None of these is
wrong; they answer different questions.**

→ *A replica should (a) name every date unambiguously, (b) make the bucketing basis an explicit
parameter on every report and export, and (c) state the basis in the export's own metadata.* Nearly
every "the numbers don't match" investigation we ran traced back to two views silently using
different date bases.

### Related entities [INFERRED from lookups]

Every one of these is referenced **by record id, never by name** (§5.2), so a replica needs them as
first-class entities with stable ids and a discovery endpoint:

```
Vendor   Bank   COA (chart of accounts)   Villa/Property   Location
Item Category   Master Category   Billing Cycle   Booking   Salesperson
```

**Item Category is user-extensible at runtime** — a new "SALES INCENTIVE" category was added
mid-project and immediately needed a new record id in every integration's config. **[MEASURED]** A
replica should expose a lookup API for these; hardcoding ids in consumer config (what we were forced
into) does not survive contact with a live system.

---

## 14. Reconciliation tolerances are a data contract, not an implementation detail — [MEASURED]

When you match provider/marketplace payouts to their source bookings, the amounts **never match
exactly**, and the rule for "close enough" is itself business data that changes.

For one marketplace channel the money received = booking amount − a small TDS withholding, so the
**expected** figure a consumer must compute is `amount − amount × tds_rate` (our `tds_rate` = 0.1%),
and a match is accepted only within a **tolerance window** around that expected value. That window
was first a flat rupee value, then a percentage of the amount (0.2%), then back to a flat ₹5 — three
changes in two months, each a deliberate business decision, none a code bug.

→ *A replica should treat both the deduction rate and the match tolerance as **configurable data
with history**, not hardcoded constants. Expose them, version them, and record which rule was in
force when a given match was made — otherwise re-running reconciliation later silently re-decides old
matches under a new rule.*

---

## 15. Open questions we never resolved

Worth designing answers for:

- Is there any bulk/batch write? We only ever found single-record POSTs.
- Is there pagination on custom API responses, or an implicit row cap?
- Are Deluge functions transactional? The partial-split defect suggests **[INFERRED]** not.
- What is the actual rate limit on custom APIs? Never documented to us, never observably hit.
- Can a custom API return a record without an update side effect? We never got one authored.
- Is there any CONFIG flag to make Analytics expand a multi-value field (§12) instead of flattening
  it? We never found one; a replica should not assume it can be worked around at the export layer.
- Does the Analytics `criteria` grammar support anything beyond simple comparisons and `!=`/`=`
  (joins, `IN`, `LIKE`)? We only ever verified equality and not-empty.

---

*Everything here is empirical. Where a claim is marked **[MEASURED]** it was observed on a live
production instance, usually more than once, and often the hard way. **[REPORTED]** items came from
the Zoho-side developer and were not independently verified. **[INFERRED]** items are hypotheses.
Nothing here is derived from Zoho source, schema, or internal documentation.*
