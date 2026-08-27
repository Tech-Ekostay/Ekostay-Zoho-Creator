# Accounts Rebuild — Context Addendum

**Companion to `ACCOUNTS_REBUILD_CONTEXT.md`. Where the two disagree, this wins.**

Everything here comes from live screenshots and report exports taken 12–13 Aug
2026. Nothing in this document is inferred from the Deluge exports, which were not
available in the session that produced it. Items marked `[TODO]` are unresolved
and should not be guessed.

---

## 1. Corrections to the context document

| § | The context doc says | The evidence says |
|---|---|---|
| §4 | `Item_Category` has `Haewaya_ID` | Empty on **all 135** records. Same on all 10 master categories. The whole sync key is unpopulated |
| §4 | `TDS.Status {Active, Expired}` | 19 Active, **16 blank**. `Expired` occurs zero times. Blank is the real second state |
| §4 | `Tax_Precentage`, `TDS_Precentage` | Field names are misspelled; the **display labels are correct** — `Tax Percentage`, `TDS Percentage`. A scaffold that derives labels from column names reintroduces the typo on screen |
| §4 | `COA` has `Hide(bool)` | No `Hide` on the report or the form. There is a boolean labelled **`COA`** that is in no field list. Probably `Hide`'s display label — `[TODO]` confirm |
| §4.4 | Four series in `Auto_Numbers`, incl. `REFUND-stay` | The four are `EKS/PY`, `EKS/BPY`, `EKS/Haewaya`, `EKS/API`. **`REFUND-stay` is not among them** — it arrives via Backend Payments from outside. Five origins, not four |
| §4.4 | — | `Books Payment No` sits at **1** while Payment No is 20938, Haewaya 32009, External 88. That series has never advanced |
| §7.5 | Payments COA picker filters `COA[Hide == true]` — inverted | If `COA` is `Hide`'s label, the filter is correct: it means *is a chart-of-accounts entry*. 47 of 144 are true. `[TODO]` confirm, then delete this finding |
| §8.2 | Approval scope by Module/Location/Villa/Type/Category | Confirmed, but `Level 1 & 2 Approval` is unset on **3 of 9** records and `Level 2 & 3` on **5 of 9** |
| §8.4 | Approval state recorded six ways, disagreeing | It is **seven**. Pending Approvals has an `Approved` checkbox inside the `Approved By` grid, and it is **unchecked on records whose Status is Approved** |
| §13 | Observation job excludes categories via `Exclude_for_Observation` | True on exactly **1 of 135** categories. The exclusion is effectively inert |
| §13B | Does `Matched_Payments` hold several payments? | Still open. Both `Payment` (single, populated) and `Matched Payments` (empty on the sampled record) exist |
| — | Five states | Locations include **Kodaikanal** and **Bangalore**, and `Head Office Central` is used as a Location value. Ten in total |
| — | Nav rail has 11 items | At least **17** |

---

## 2. Settings — eight reports

Nav item with a flyout submenu. Order as the flyout lists them.

| Report | Object | Records | Columns | Notes |
|---|---|---|---|---|
| All Master Categories | `Master_Category` | 10 | 4 | |
| All Item Categories | `Item_Category` | 135 | 7 | ID is column **6 of 7** |
| All Approvals | `Approval` | 9 | 7 | no `Exclude Category` / `Type` / `ID` columns |
| TDS Report | `TDS` | 35 | 4 | **no ID column** |
| All Taxes | `Tax` | 8 | 5 | |
| COA Report | `COA` | 144 | 9 | **the only inline-editable one** — `*` + Save/Remove Changes |
| Block Payment Date | — | 1 | 5 | singleton; audit fields exposed as columns |
| Auto Numbers | `Auto_Numbers` | 1 | 7 | External Payment fields are form-only |

An export mirrors the report's columns exactly — that is how the three inferred
column sets above were settled. Fields on a detail panel or form that are *not*
report columns will not appear in an export.

**Form conventions confirmed across all eight**

- Near-full-screen overlay, single column, label ~190px + control ~324px
- Field order is the **declared** order; checkboxes sit inline where declared,
  **not** grouped at the top. (Master Category only looked hoisted because `F&B`
  genuinely is its first field.)
- Add commits `Submit`, edit commits `Update`; the button sits directly under the
  last field behind a rule, not pinned to the viewport floor
- Unchecked checkboxes carry a **pink** border
- Lookups show `-Select-` and gain a clear `✕` once populated
- `Variance` and the percentage fields carry a `%` suffix box
- Auto Numbers is the only form with **section headings** — `Payment` (which
  contains the Haewaya fields), `Books Payment`, `External Payment`
- Field instruction text exists: under `Vendor Name` on Item Category —
  *"If vendor is selected, items will be visible only for the selected vendor."*
  That is a real per-vendor scoping rule on the category pickers

**Block Payment Date** is a singleton. `＋` raises Creator's own dialog —
*"Please Edit in same record you cannot add new record"* — with an OK button.
Saving shows the toast *"Block Payment Added Successfully"*. Current cutoff
`01-Jun-2026`. `[TODO]` the form itself was not captured.

**A period lock has consequences the context doc does not cover.** Payments must
enforce it on create, on edit, and on both date fields. The reversal flow has a
real problem: a reversing entry for a payment dated before the cutoff either
inherits the blocked date or lands in a different period from what it reverses.
Schedule Payments' monthly generation and §9.3's delete-and-regenerate of
`Expenses_Bills` both write across periods and would bypass it.

---

## 3. Master data quality — see `master-data/*.json`

Real exports, 12 Aug 2026. Use these to seed; do not invent.

**Item Categories (135)** — `Expense Type` unset on **103**. `Haewaya ID` empty on
all. `Exclude for Observation` true on **1**. `F&B STAFF MEDICAL EXPENSE ` has a
**trailing space** and it is a lookup key. No duplicate names.

**COA (144)** across 16 Books account types — the messiest master.
- 25 accounts typed `bank` have `Bank = false`; **9 with `Bank = true` are typed
  `cash` or `other_current_asset`, including `Security Deposit`**. So
  `COA[Bank == true]` offers Security Deposit as a bank account
- `Account Code` populated on **6 of 144**; `CA Name` on **7** (`Jitesh` ×6,
  `Keshav` ×1); `Account ID` on 125, so 19 accounts exist in Creator with no
  counterpart in Books
- `EKOSTAY IDFC LLP` appears **twice** with different record IDs
- Individuals' personal bank accounts sit in the same master as company entities
- Five spellings of the same concept: `Tax witholding - Premsagar` (missing h),
  `Sabiha Tax withholding`, `Tax withholding- Kotak LLP 1`,
  `Tax withholding - Nishrin`, `tax withholding LLP 2`. §9.4's CA filter matches
  on `Account_Name contains "LLP" | "Petty" | "Haewaya"`, so it picks up two of
  those five by accident and misses three

**TDS (35)** — only **16 distinct name + percentage pairs**. `Other Interest than
securities` appears 4× at 10.00, `(Reduced)` 4× at 7.50. All duplicates are
Active, so the Bills/Payments picker shows the same rate several times with
different `Books ID`s and the entry clerk picks arbitrarily. Reconciliation keyed
on Books ID sees one tax treatment arriving under several identities. `[TODO]`
before deduping: are the extra Books IDs live in Books or orphaned? If live, this
needs a mapping table, not a delete.

**Taxes (8)** — `Tax Type` holds Books API values `tax` (all three IGST) and
`tax_group` (all five GST). That split is real accounting: a `tax_group` is
CGST + SGST and has two ledger destinations, while the app stores a single
`GST_Amount`. **IGST exists only at 0/5/18 while GST runs 0/5/12/18/28** — an
interstate purchase at 12% or 28% has no entry to select. `Tax Type` is a
free-text input on the form, so nothing constrains it.

**Villa master (204 names, recovered from the Approvals export)** — contains
`Nature` **three times**, a test record `fcgfhbjnh`, eight names with leading
spaces, three with doubled spaces (`Athens Villa  Nerul`, `Windsor  Villa`,
`StarMount  Villa`), and both `Copacabana Villa Calangute` and
`Copacabana Villa- Calangute`. Every one is a distinct grouping key for §5.1
splits and §13 observations, so `Nature` splits its expenses three ways.

---

## 4. Backend Expenses — verified 27-Aug-2026

**Sixteen screenshots of All Backend Expenses arrived 27-Aug-2026** — the full report
scrolled horizontally to its last column, and the detail panel scrolled top to bottom.
The edit form was deliberately not sent: "almost all textboxes which are the same",
which is consistent with §13B's finding that 136 of 140 fields are declared `text`.

This section was previously **corrections**. It is now **verified**, with one
`[TODO]` closed, one column added, and five new findings.

### 4.1 The report column order is confirmed — and there are 32, not 31

Every column in §13B's list appears in exactly the recorded order, and `tr_location`
is confirmed last (the horizontal scrollbar reaches its end). One column was missing
from the list:

```
 8  multipe_hccc_names     populated   "Nama (Tropicana)-EKOSTAY"
 9  multipe_hccc_names     BLANK       <- this one was not in the list
10  remark_cat_name
```

The two sit **adjacent**. §4 already recorded that the report shows the field twice;
§13B's column list did not include the second. **32 columns.**

### 4.2 `[TODO]` CLOSED — the textarea copy is canonical, the text copy is dead

The question was: of the three duplicate pairs (`multipe_hccc_names`, `remark_txt`,
`receiver_details`, each declared once as `text` and once as `textarea`), which member
is canonical — "report order says the populated one is declared first, detail-panel
order says second".

**Answered, on one record, for all three pairs at once.** Record
`292482000010971002` shows:

| field | early copy | late copy |
|---|---|---|
| `multipe_hccc_names` | blank | `Nama (Tropicana)-EKOSTAY` |
| `remark_txt` | blank | `Electrical Works - namah villa electrical material approve by subhash sir` |
| `receiver_details` | blank | `upi://pay?pa=paytmqr72irlg@ptys&pn=Paytm` |

**The late copy carries the data in all three cases. The early copy is dead.**

And the reasoning in the old `[TODO]` was wrong on one point, which is why it looked
unresolvable: **report column order is not evidence of declaration order.** A report's
columns are ordered by whoever built the report. The detail panel is in *form field
order*, so it is the only one of the two that testifies to declaration order. Once the
report is set aside there is no conflict — the populated copy is the later one.

**This is the cause of §4's defect 2.** `receiver_details` renders empty on every
report row because the report binds the **early, dead** copy at column 5. The report
binds the *live* copy for `multipe_hccc_names` at column 8 and the dead one at 9. So
the report mixes the two members of the pair, per field, with no pattern.

### 4.3 The detail panel is not alphabetical — it is three blocks

§13B says the panel "lists fields alphabetically, confirming no layout was ever
applied". That is right about the bulk and wrong about the shape. It is **form field
order**, and the form was built in three batches:

| block | contents | order |
|---|---|---|
| **A** | `approve_status` → `verify_lvl_two` (+ unseen tail, incl. `webhook_resp`) | alphabetical |
| **B** | `approval_txt`, `approve_by`, then `lvl_{one..four}_approve_msg`/`_time` | alphabetical |
| **C** | `assets_path`, `business_name`, `Payment`, `time_stamp_date`, `multipe_hccc_names`, `remark_txt`, `receiver_details`, `Matched Payments`, `dup_checked`, `dup_key` | **not** alphabetical |

Two alphabetical runs, then a hand-added tail. That is a more useful reading than "no
layout": blocks A and B were generated (from the provider payload and from the
approval-engine scaffold), block C was **added by hand afterwards** — it holds every
Creator-native field (`Payment`, `Matched Payments`, `business_name`, `dup_checked`,
`dup_key`) and all three live textarea copies. Which independently supports §4.2:
the live copies are in the hand-added block.

**136 field labels counted across the three blocks**, against §13B's DS count of 140.
The four unaccounted-for sit in gaps the screenshots cannot prove contiguous — after
`duplicate_date`, after `transactor_id`, and after `verify_lvl_two`, where §13B's
`webhook_resp` must fall and was not captured. Not a discrepancy; a known blind spot.

### 4.4 The approval bands are static config replicated onto every row

§4 recorded `lvl_one_amt` 1000 / `lvl_two_amt` 3000 / `lvl_three_amt` 5000 /
`lvl_four_amt` 0 from one record. **The same four values appear on this record**,
whose `dr_amount` is ₹860 — a different amount, an earlier record, identical bands.

So the `lvl_*_amt` fields are not per-record: they are the provider's threshold
configuration **stamped onto each transaction at ingest**. §13B inferred "thresholds
not amounts" from a ₹3500 band on a ₹200 row; that is now confirmed and sharpened —
they are *constant*, so a rebuild reads them from configuration once and does not
store them 140,000 times. `lvl_four_amt` 0 means level 4 is unbounded or disabled.

### 4.5 NEW — money moved without the verification the record says was required

On a transaction with `tr_status` = `paid` and `cron_event_paid` = 1:

```
is_verify_require        1        <- verification IS required
verification_lvl         1
verification_option      4
verification_type        2
verify_lvl_one..four     0 0 0 0  <- nothing verified
verify_by_lvl_one..four  0 0 0 0  <- by nobody
lvl_verification_status  0
approve_status           0
approve_by               (blank)
lvl_*_approve_msg/time   (all blank)
```

**Every approval and verification field is empty on a settled, paid transaction.**
This is the same shape as §5's Pending Approvals finding — every row `Approved` and
`Paid` with the `Approved` checkbox unchecked. Two independent screens, two
independent approval engines, the same conclusion: **the approval state is not what
gates the money; it is written after, or not at all.**

Stated as an observation about the live system, not a rebuild instruction. But it is
the single most important thing in this section, because §13B.2's recommendation
("read-only except `Payment`, `Matched_Payments`, `dup_checked`") assumes the approval
fields are inert. They are — and that is a finding, not an assumption.

### 4.6 NEW — the ingestion lag is measurable, 2 to 10 minutes

`date` is the provider's timestamp and `Added Time` is Creator's platform stamp, so
the gap between them is how long ingestion took. Four consecutive rows:

| `date` | `Added Time` | lag |
|---|---|---|
| 2026-08-27 19:36:30 | 27-Aug-2026 19:45:59 | 9m 29s |
| 2026-08-27 19:56:59 | 27-Aug-2026 19:59:30 | 2m 31s |
| 2026-08-27 19:37:37 | 27-Aug-2026 19:39:30 | 1m 53s |
| 2026-08-27 19:28:11 | 27-Aug-2026 19:36:06 | 7m 55s |

**Rows do not arrive in `date` order** — 19:56:59 was ingested before 19:37:37. So a
reconciliation window keyed on `date` must be at least ~15 minutes wide and must not
assume monotonicity. This also confirms handoff §61's exception: **`date` really does
render `2026-08-27 19:36:30`**, not `dd-MMM-yyyy`, while `Added Time` renders
`27-Aug-2026 19:45:59` in Creator's own format. Two date formats, one row.

`duplicate_date` renders `0001-01-01T00:00:00Z` — Go zero time, **confirmed on a third
record**, and note it keeps its ISO `Z` while `date` does not. Three serialisations.

### 4.7 NEW — `fk_m_hccc_id` is the live key; `fk_hccc_id` is always 0

The report carries both. Across four rows `fk_m_hccc_id` is 6417 / 4849 / 1057 / 1022
and `fk_hccc_id` is `0` on every one. **Join on `fk_m_hccc_id`.** `fk_order_id` and
`fk_safe_id` are 0 too.

Likewise `remark_icon_id` is a stable id per remark category — 2542 for Electrical
Works on two different rows, 2104 Staff fuel, 1575 Water Tanker, 2589 stafffuel again.
**`remark_icon_id` is the joinable key for the remark category, not `remark_cat_name`.**

### 4.8 NEW — the `multipe_hccc_names` separator is itself inconsistent

§4 established the format is `group-property` with an inconsistent group and that it
needs a mapping table. Four new rows make it worse:

```
Nama (Tropicana)-EKOSTAY     property (qualifier) - group    <- group LAST
General -Panchgani           note the SPACE before the hyphen
EKOSTAY-Chestnut Villa       group FIRST
Lonavala-BLANCO              location - property, uppercased
```

Three new failure modes on top of the recorded ones: the group appears on **either
side** within the same four rows; a property can carry a **parenthesised qualifier**
(`Nama (Tropicana)`); and the **separator is not stable** — `General -Panchgani` has a
space before the hyphen. Splitting this string was already unsafe because villa names
contain hyphens. It is now unsafe on the delimiter as well.

And the same villa is spelled two ways **inside one record**: `Nama` in
`multipe_hccc_names`, `namah villa` in `remark_txt`. **The mapping table is not
optional and cannot be derived from this field alone.**

### 4.9 NEW — smaller items, all first sightings

- **`business_name` = `EKOSTAY HOSPITALITY LLP`** — entity attribution per
  transaction, not called out in §13B. Matters because Settings COA carries entity
  accounts and `EKOSTAY IDFC LLP` exists twice with different record ids
- **`txn_option` = `virtual_ac`** — the payout rail, a value not previously seen
- **`tai_cost` 2, `tai_gst` 2, `tai_tds` 0** on an ₹860 row. These are **not money** —
  they are codes or flags. §4's "seven amount fields" list should not include them
- **`tai_invoice_number`** is blank here but `415522`, `488` and `503139` on other
  rows — wildly different widths, so not a formatted series. Optional free text
- `transactor_id` 15181 with `transactor_avtar` (an S3 avatar URL), `receiver_pusid`
  and `public_tr_id` — provider-side actor identity, three opaque ids.
  **`transactor_avtar` is misspelled in the source; preserve it**
- `bill_upload` is the string `"true"` and `assets_path` holds 2 S3 URLs, yet
  `cron_event_bill_upload` and `cron_event_bill_verified` are both 0 and
  `hard_copy_bill` is 0. Confirms the cron flags are not maintained
- `bank_payout_details` is the literal `"na"` — confirmed on all four rows
- `dr_amount` 860 with `balance` 7200; `tr_total_amount` **blank** again, and
  `cb_amount` / `cr_amount` / `pr_amount` / `sr_amount` / `total_paid_amount` all 0.
  Third record confirming: **totals use `dr_amount` gated on `transaction_type == "DR"`**
- `time_stamp_date` is blank in the detail panel *and* in all four report rows.
  Likely always blank
- Roughly a third of the 136 fields carry any value at all. `reject_reason`,
  `paid_by`, `payment_mode`, `payout_via`, `payout_resp`, `payout_change_via`,
  `pg_order_id`, `pg_payment_id`, `rzp_payout_id`, `rbl_trn_id`, `bbps_txn_ref_id`,
  `dyn_qr_ref_id`, `circle_code`, `code_title`, `tag_name`, `check_in_date`,
  `check_out_date`, `invoice_bill_date`, `invoice_receipt_url` are all empty

### 4.10 URGENT — the Haewaya counter is 207 behind live

Not a Backend Expenses finding as such, but the screenshots are what exposed it.

The `Payment` links on these rows read `EKS/Haewaya/33499`, `33501`, `33497`, `33493`,
and their `date` is **today, 27-Aug-2026**. Our counter:

```
auto_numbers.haewaya_no = 33294        (reconciled earlier this session)
live, from the screenshots            >= 33501
```

**Our counter is at least 207 behind, and live is still minting.** This is precisely
the collision that minted `EKS/PY/21305` over a real ₹1,00,000 payment — the same
mistake, a second series, caught before it fired this time only because nothing
allocates from `haewaya_no` yet.

Two things follow, and the first is a question that must be answered before any write:

1. **Who owns the Haewaya series?** If the Haewaya backend mints the number and pushes
   it in, our counter must **track, never allocate** — and `AutoNumber::allocate()`
   must refuse it. If Creator mints it, then reconciliation has to run against live
   immediately before every allocation, not once at import.
2. Reconciling it once is not enough. `EKS/PY` went 21307 → 21309 during this session
   from our own test allocations, and Haewaya moves ~200 a day from a system we do not
   control. **A counter reconciled at import time is stale by definition.**

---

## 5. Pending Approvals

24 columns: `Added Time` · `Payment Date` · **`Approve`** · **`Reject`** · `Link` ·
`Payment Status` · **`Pay`** · `Payable Amount` · `Location` · `Gross Amount` ·
`Item Category` · `Bank Name` · `Vendor Name` · `Villa Name` · `Payment No` ·
`Master Category` · `Status` · `COA` · `Billing Cycles` · `Approval Level` ·
`Next Level Approval Required?` · `Approval Type` · `Approved By` · `Message ID`.

The three action buttons sit mid-table, not at the left edge, and disable once the
record is settled. `Payment Status` is a **solid green filled cell**.
`Gross Amount` prints at three decimals. Footer `Showing 1000 of ###`.

Detail order: Payment No · Status · Approval Level · Next Level Approval
Required? · Approval Type · Approved By · Approvers · Preferred Approver · Item
Category. Form order: Approvers · Preferred Approver · Payment No · Status ·
Approval Level (free text) · Next Level Approval Required? (checkbox) · Approval
Type · then the **`Approved By`** section over a subform of Approver / Approval
Level / Approved, with `+ Add New`.

**Findings**

- **Every record on the report is `Approved` and `Paid`.** Nothing clears the
  queue, so a work list that should be short is over 1000 rows
- **The `Approved` checkbox in the grid is unchecked on approved records.** The
  seventh disagreeing representation, and the one a reviewer would most trust
- **Nine identical records** — `EKS/PY/20954`–`20962`, same vendor, category,
  bank, cycle and `₹4,956.00`, created within ~90 seconds, differing only in
  Message ID. Since approval is what mints a Payment (see §6), an approval that
  fires more than once creates more than one payment. Check whether nine real
  ₹4,956 META ADS payments exist for August
- `Message ID` here is a plain UUID; `Messageid_Level_1/2` on Payments is a
  `wamid.` WhatsApp id. Different channels — do not merge them in the schema
- `Payable ₹4,956.00` against `Gross ₹4,272.410` is a ratio of 1.16, which is no
  clean GST rate. Not derivable from the fields on this report

---

## 6. Payment Requests — three views, verified 27-Aug-2026

Still the clearest evidence for the §3.3 permission matrix:

1. **`Payment Request`** — add-only form, no list. A requester can create but not
   browse. Commits `Submit` / **`Reset`**
2. **`All Payment Requests`** — admin read across everyone, **72 records**
   (was 66 on 13-Aug; +6 since), not editable inline
3. **`User Payment Requests`** — the requester's own **24**, **inline-editable**
   (`*` + Save/Remove Changes) with a per-row **`Re-Send for Approval`** button

So the requester can amend and resubmit; the reviewing admin cannot edit inline.

**Thirteen screenshots of 27-Aug-2026** — the create form, the edit form in both
branches, both reports scrolled to their last column, and two detail panels — turn
this section from inferred to verified. Two recorded claims are corrected, one
`[TODO]` is closed, and one entry on the live-defect register is probably wrong.

### 6.1 `[TODO]` CLOSED — Villa Name and Item Category are NOT read-only

The `[TODO]` read: "on the **edit** form Villa Name and Item Category render greyed
— confirm they are read-only once the request exists."

**They are not read-only. They are multi-select chip fields, and that is what the
grey was.** On the create form they show a grey `-Select-` and, unlike `Vendor Name`
and `Location`, **no `▾` caret**. On the edit form the same controls hold
`× Saltwater Villa- Nerul` and `× PRINTING` — chips **with a remove `×` on each**,
which a read-only field does not have.

So the distinction the screenshots actually encode is not enabled-vs-disabled, it is
**single-select (caret) vs multi-select (chips)**:

| field | control |
|---|---|
| `Requested By` | single-select lookup, `×` + `▾`, **prefilled with the logged-in user** |
| `Vendor Name` | single-select lookup, `▾` |
| `Location` | single-select lookup, `▾` |
| `Villa Name` | **multi-select chips**, no caret |
| `Item Category` | **multi-select chips**, no caret |

Had the `[TODO]` been guessed the wrong way, both fields would have been built
disabled on the edit form, and a request could never be re-scoped.

### 6.2 The multi-value hazard is live on this report, not theoretical

`EKS/PY/20559` carries **six villas in one cell**, stacked on separate lines:

```
Location   Ooty And Coonoor
Villa Name Under The Pines / Dusk Villa / Dawn Villa / Orchid Villa /
           Whispering Pines / The Velvet Slope
Category   AMAZON PURCHASE          Amount  ₹ 10,610.70
```

Creator renders all six. **Analytics would flatten this to one**, silently — §12,
measured by the other team on an expense tagged to two billing cycles that exported
tagged to one. This is the first time that hazard has been *seen* on an Accounts
screen rather than reasoned about, and it is on the field a payment request is
allocated by. **Never import Payment Requests from a one-row-per-request view.**

### 6.3 The two `Vendor Name` fields — the mechanic is now proven, not inferred

§6 recorded that the detail panel holds a **second `Vendor Name`** (free text, for
the new-vendor path) alongside `Bank Details`. What it is *for* is now demonstrated.
`Add New Vendor` is the discriminator and the two branches are mutually exclusive:

| | `Add New Vendor` | `Vendor Name` (lookup) | `Vendor Name` (text) |
|---|---|---|---|
| `EKS/PY/21570` | **true** | *(blank)* | `Shree balaji hardware` |
| `…8860030` | **false** | `hussain sir` | *(blank)* |

Checked all 30 master values these screenshots name against our seeded masters —
4 locations, 16 villas, 6 item categories, 4 vendors, 4 employees. **29 of 30
resolve.** The single miss:

```
Shree balaji hardware        -- MISSING from Vendor_Master --
```

Which is **exactly** the `Add New Vendor = true` row. The free-text field exists to
hold a payee that has no master record yet, and the one unresolvable name in the
batch is the one that went down that path. The design is working as intended, so —
same phrasing as the vendor-merge pointer in §18 — **a null lookup beside a non-null
text is a fact, not a gap.**

(`amazon` resolves to **three** case-insensitive rows in our master, not the two
§6 recorded. One vendor, three records.)

### 6.4 A DEFECT ON THE REGISTER IS PROBABLY A REPORTING ARTEFACT

§6 recorded, and `CLAUDE.md` carries on the live-defect list: **"Two rows are
`Approved` with a blank `Vendor Name` — approved with no payee."**

`All Payment Requests` binds the **lookup** copy of `Vendor Name` in its column 4.
So **every request created through `Add New Vendor` shows a blank vendor to the
admin**, while its actual payee sits in the second field, one panel away. On this
report `Vendor Name` is blank on **8 of 10 visible rows**, and the one row whose
detail panel we have — `EKS/PY/21570`, blank in the column — has
`Shree balaji hardware` in the text field.

**So "approved with no payee" is most likely a column bound to the dead half of a
pair, not missing data.** Same defect class as Backend Expenses' `receiver_details`
(§4.2), where a report column is bound to the empty member of a duplicate pair.

Stated as *probably*, not *certainly*: the mechanic is confirmed on one row, and the
two `Approved` rows in question have not had their panels opened. **Before that entry
stays on the register, someone should open those two records and look at the second
`Vendor Name`.** It is a ten-second check and it decides whether a real payee is
missing or merely hidden.

### 6.5 CORRECTED — the `Payment No` rule has a counterexample

§6 recorded: "**`Payment No` is blank on every `Submit for Approval` row and
populated on `Approved` ones — approval is what mints the Payment.**"

The first half is falsified. `EKS/PY/21570` is `Submit for Approval` **and carries a
number.** But the underlying claim survives, and is now much better evidenced.
Checked all ten visible request numbers against our 16,490 imported `EKS/PY`
payments:

```
EKS/PY/21570   Submit for Approval   NOT in payments
EKS/PY/21111   Approved              in payments, status Paid
EKS/PY/21107   Approved              in payments, status Paid
EKS/PY/21094   Approved              in payments, status Paid
EKS/PY/20998   Approved              in payments, status Paid
EKS/PY/20619   Approved              in payments, status Paid
EKS/PY/20618   Approved              in payments, status Paid
EKS/PY/20617   Approved              in payments, status Paid
EKS/PY/20559   Sent for Approval     in payments, status Paid
EKS/PY/16239   Approved              in payments, status Paid
```

**Nine of ten are real, settled payments.** So a request's `Payment No` is not a
parallel series — it is *the payment the request became*, and the request keeps a
pointer to it. That is the Payment Requests → Pending Approvals → Payments link,
now traced through live numbers rather than asserted.

The one absentee has an innocent explanation that has to be checked rather than
assumed: **`EKS/PY/21570` was created today, and our payments came from an export
two days old.** Our imported maximum is `EKS/PY/21308`. So 21570's payment may well
exist live and simply not be in our snapshot — in which case the rule is "`Payment
No` is populated iff a Payment record exists" and the request's own `Status` is
merely stale, which is this application's most reliable habit (§4.5, §5).

`[TODO]` **One live check settles it:** open `EKS/PY/21570` and see whether a Payment
record exists. If it does, the rule is about the Payment's existence, not the
request's status. If it does not, the number is reserved at submit and the mint point
moves earlier than §6 says.

Related, and unresolved: **`Submit for Approval` and `Sent for Approval` both occur**
on these reports, on rows that otherwise look alike. Either two states or two
spellings of one — the same trap as `Payment InProgress` (§10). Added to §8's
label-divergence list.

### 6.6 URGENT — the main payment counter is 261 behind, and my earlier fix was not enough

```
payments table (imported)   max EKS/PY = 21308   over 16,490 rows
auto_numbers.payment_no                 = 21309
live, from these screenshots            >= 21570
```

**At least 261 behind, on the series we actually allocate from.** Roughly 130
payments a day.

This is the same finding as §4.10's Haewaya counter, but it lands harder, because
this counter is live in our code and I already caused one collision with it —
minting `EKS/PY/21305` over a real ₹1,00,000 payment. That was diagnosed as an
off-by-one (`allocate()` returning current-then-incrementing) and fixed to `max + 1`.

**The fix was correct and insufficient.** `max + 1` of a *snapshot* is still a
snapshot. Analytics lags Creator by design, and an export is a photograph — so a
counter reconciled from one is stale the moment it is written, and staleness grows at
~130/day. Reconciling more often does not fix it; it shortens the window.

The only two safe designs:

1. **Creator keeps the series while it is live**, and `AutoNumber::allocate()`
   **refuses** `EKS/PY` entirely — our writes take numbers from nowhere until cutover
2. **We take the series over at cutover**, seeded from a live read taken *at* cutover
   with Creator writes stopped — not from an Analytics export at all

Until one is chosen, **nothing may allocate from `payment_no`.** Recorded here rather
than fixed, because which of the two applies is a cutover decision, not a code one.

### 6.7 Form layout order and detail order are different — the second confirmation

**Form (layout) order.** The conditional block sits high, right after the vendor
lookup it modifies:

```
Requested By · Vendor Name · [x] Add New Vendor
    -> when checked, reveals:  Vendor Name (text) · Bank Details
Location · Villa Name · Item Category · Payment Amount · Particulars
Bills · Supporting Documents            [Submit] [Reset]   /   [Update] [Cancel]
```

**Detail (declaration) order.** The same block sits low, and three fields appear
that the create form never shows:

```
Requested By · Vendor Name · Location · Villa Name · Item Category ·
**Bill Amount** · Payment Amount · Particulars · **Status** ·
Add New Vendor · Vendor Name (text) · Bank Details · **Payment No** ·
Bills · Supporting Documents
```

The conditional block is **declared late and laid out early.** That is §4.3's lesson
on a second, unrelated form: **the detail panel is declaration order, the form is
layout order, and neither can be derived from the other.** Record both, always.
`Status` and `Payment No` are system-set. `Bill Amount` is blank on both records
seen — `[TODO]` who fills it, and when.

`Particulars` is **mandatory** — it is the only field carrying Creator's red required
border, on both the create and the edit form.

### 6.8 Column sets, both reports

**All Payment Requests** (10) — `Payment No` · `Requested By` · `Status` ·
`Vendor Name` · `Location` · `Villa Name` · `Item Category` · `Payment Amount` ·
`ID` · `Added User`. Footer **`Showing 72 of 72`**.

**User Payment Requests** (15) — **`Re-Send for Approval`** · `Payment No` ·
`Requested By` · `Status` · **`Remarks`** · `Vendor Name` · `Location` ·
`Villa Name` · `Item Category` · `Payment Amount` · `ID` · **`Link`** · `Bills` ·
`Supporting Documents` · `Particulars`. Footer **`Showing 24 of 24`**.

So the requester's view adds the action button, `Remarks`, `Link`, and the three
content columns the admin view omits — attachments and particulars included.
**Neither footer is capped**, so both are direct tests of the corrected
`showing()` helper against a real total rather than `###`.

**The panel action bar differs per report, not per form:**

| report | panel bar |
|---|---|
| All Payment Requests | `Edit` · **`Delete`** · `More ▾` |
| User Payment Requests | `Edit` · **`Duplicate`** · `More ▾` |

§6 recorded only the second and generalised it. `Duplicate` is on the **requester's**
view — and two of those 24 rows are identical ₹2,500.00 requests
(`…8860030` / `…8860016`) sharing one attachment,
`WhatsApp_Image_2026-05-26_at_1.43.09_PM__1.jpeg`. Given §5's nine identical payments
from a repeated approval, a `Duplicate` button on a payment request is a duplicate-
payment vector worth raising. Both of these are Husain's own test rows, so no real
money is involved in *this* pair.

### 6.9 Smaller corrections and first sightings

- **NOT REPRODUCED:** §6's "`Requested By` empty on 6 of 10 rows while `Added User`
  is populated on all 10". `Requested By` is populated on **all 10** rows of All
  Payment Requests and all 24 of User. The column that *is* empty on 8 of 10 is
  `Vendor Name` — see §6.4. The earlier note may have conflated the two columns;
  recorded as unreproduced rather than as a corrected fact, since the earlier report
  state cannot be re-examined
- **`Added User` is a login handle, not a name** — `sanjayprojapati1983`,
  `amit7331411`, `shaikh.nehu091`, against `Requested By` values `Sanjay Projapati`,
  `Amit`, `Neha`. So `TracksCreatorAudit`'s user half must store Creator's **login**,
  and `Requested By` is a separate Employee lookup that a requester can override.
  They agree on every row seen, but the form lets them diverge
- **`Requested By` defaults to the logged-in user** (`Husain Super Admin` prefilled
  while Husain is logged in), and is clearable. So this is the first screen whose
  *correct* behaviour needs authentication — `CurrentUser::login()` returns null
  today. Auth moves from "blocker before exposure" to "blocker for this screen's
  fidelity"
- **A second empty record.** Row 3 of All Payment Requests carries only
  `Requested By`, `ID` (`292482000010752954`) and `Added User` — no status, location,
  villa, category, amount or payment number. An empty payment request, 1 of 72, and
  the second blank-`Status` row after the one §6 found in the User view. §11's
  "blank-as-real-state is systemic" now has a fourth screen
- **All 24 User rows are Husain's own test data** — `hussain sir` as vendor on every
  row, `Particulars` reading `test` / `test for payment`, one at `₹10.00`. The view
  proves the filter (own requests only) and carries no business data; the
  `₹42,000.00` and `₹36,000.00` rows should not be read as real spend
- Files render `Select File` when empty and **`File uploaded` in pink** when
  populated — confirmed on both edit forms. The detail panel shows the real filename
  with a type icon (`IMG-20260827-WA0473.jpg`, `REPC500-_Repeat_Guest_EKOSTAY.pdf`)
- `Remarks` reads `na` on the one `Approved` User row and is blank elsewhere —
  the same literal `"na"` placeholder as Backend Expenses' `bank_payout_details`
- **The FK layer is ready for this screen.** 29 of 30 master values resolve, the
  30th by design. Payment Requests can be built against our seeded masters today
  without a mapping table — unlike Backend Expenses (§4.8)

---

## 7. Backend Payments — form only

The payments counterpart to Backend Expenses: where Haewaya writes, including
`REFUND-stay-*`. Two columns plus a `Commercials` section, ~40 fields, Update /
Cancel. `[TODO]` list and detail not captured.

**Three fields on a live PAID record hold unresolved foreign keys:**
`Villa Name` = `292482000000368045`, `Location` = `292482000000170003`,
`Bank Name` = `8`. Plain text fields; the integration writes IDs and nothing
resolves them. A settled ₹4,000 refund therefore has no readable property or
location, and `8` is a third ID space again — not an 18-digit Creator id, not a
Books id, but the small integer that looks like `Ekostay_ID` on the COA master.

`Payment Reference Number` holds a **Firebase Storage URL**. The same field on
Payments is labelled `Haewaya UTR Number` and packs two comma-separated UTRs. One
field, three content types.

`Bill No` appears **twice**, one blank. `GST Type` is not in §7's field list.
`Haewaya ID`, `Creator ID` and `Books ID` are all empty on a paid record.

---

## 8. Label divergence — pick one per concept

| Concept | Variants seen |
|---|---|
| Payment number | `Payment No` · `Payment No.` (Backend Payments, and the Payments search chip) |
| Booking number | `Booking No` · `Booking No.` |
| TDS rate | `TDS` (Payments) · `TDS Percentage` (Settings) · `TDS %` (Backend Payments) |
| Employee state insurance | `ESIC` (Item Category, §7) · `ESI` (Backend Payments) |
| Food & beverage | `F&B` · `F & B Payments` |
| Paid status | `Paid` (Pending Approvals) · `paid` (Payments) · `PAID` (Backend Payments) |
| Approval level pair | `Level 1 & 2 Approval` — **not** `Level 1 2 Approval` |
| Disable flag | field `Disable`, label **`Disallow Manual Creation`** |
| COA visibility flag | field `Hide`(?), label **`COA`** |
| Module name | `Backend Payments` (form) · `Backbend Payments` (rail) |
| **Approval-pending status** | **`Sent for Approval` · `Submit for Approval`** — both live on Payment Requests, on rows that otherwise look alike (27-Aug-2026, §6.5). Two states or two spellings is **unresolved**, and it decides whether a status comparison misses half the queue — exactly the `Payment InProgress` trap in §10 |

`Disallow Manual Creation` finally says what `Disable` does: it stops the category
being picked during manual bill/payment entry while leaving it available to the
sync and generators. True for `PETTY` and `INTERNAL TRANSFER`, which matches
§6.2's Bills picker filter. It is a visibility filter on manual paths, not a soft
delete. `[TODO]` confirm.

---

## 9. Defects worth acting on independently of the rebuild

Carried from v1 and still open:
- hardcoded **DoubleTick API key** at `Accounts.ds` line 22851 — rotate
- **negative-HRA band** (totals ₹21,001–21,099) producing bad payslips today
- **`Delete Paid Payment`** live one click from a settled payment

Added this session:
- **duplicate approvals minting duplicate payments** (§5)
- **approved requests with no payee** (§6)
- **unresolved foreign keys on settled Backend Payments** (§7)
- **`COA[Bank == true]` returning Security Deposit** as a bank account (§3)
- **duplicate TDS records** feeding the live picker (§3)
- **IGST missing 12% and 28%** with interstate vendors in the master (§3)
- **`Nature` existing three times** in the villa master (§3)

---

## 10. Bills `OnInputValidationCE`, read from the DS 22-Aug-2026

`Accounts.ds` lines 27081–27196, the complete `on validate` body for the Bills form.
This is the section §6.4 of the context doc records as "truncated in extract;
**re-read source**". It is no longer truncated. `[DS]`

### Resolved

**§6.4 item 4 — the `Paid_Amount == 0` branch.** It forces the backend triplet to
match the live one:

```deluge
if(Paid_Amount == 0)
    for each val in input.Split_Payment
        if(val.Total_Amount != val.Backend_Total_Amount)
            val.Backend_Total_Amount = val.Total_Amount;
```

**§6.2 — what the `Backend_*` triplet is for.** Taken with §7.2's "read instead of
the normal columns when the bill is Partially Paid", the above settles it:
`Backend_*` is the **allocation snapshot taken while nothing is paid**. It tracks
the original split, is re-synced on every save until a payment exists, and then
diverges and becomes the figure of record for a partially-paid bill. It is a
baseline, not a parallel calculation.

**§6.2 — `Amount_Category` versus `Split_Payment`.** The `[INFER]` is supported by
the DS: this body selects and validates GST **per `Amount_Category` row**, and
sums amounts **per `Split_Payment` row**. Amount_Category is invoice line items
carrying their own tax; Split_Payment is allocation across villa × category ×
cycle. Treat as `[DS]`-backed rather than inferred.

**Addendum §8 — `Disable` really is "Disallow Manual Creation".** Confirmed by a
hard block at validate:

```deluge
if(iiit.Disable == true) → alert "Cannot select (" + ititem + ")
                            as it has been disallowed for manual creation"; cancel submit;
```

So it stops manual selection while leaving the category available to syncs and
generators, exactly as addendum §8 argued. That `[TODO]` can close.

**§6.4 item 2 — the hardcoded zero-GST tax ids are confirmed verbatim**:
`292482000003927068` and `292482000000130718`. Resolve via `Tax.Tax_Percentage == 0`
in the rebuild, as the context doc says.

### Two live bugs in this validation

**1. A condition that can never be true.** The IGST0 branch reads:

```deluge
if(TDS_Amount == null && TDS_Amount == 0)   // ← && , not ||
```

No value is simultaneously null and zero, so the true-branch is **dead code**. The
`else` always runs, zeroing only `Backend_GST_Amount`. Consequence: on an IGST0
bill with no TDS, **`Backend_TDS_Amount` is never zeroed**, so a partially-paid
IGST0 bill can carry a stale backend TDS figure into the amount that §7.2 says is
the one actually read. Almost certainly meant to be `||`.

**2. A subform dereferenced as a scalar, with an assignment in the condition.**

```deluge
if(Amount_Category.GST.Tax_Name = "IGST0")   // ← single '=', and no iteration
```

Every other branch in this body iterates (`for each GN in input.Amount_Category`).
This one reaches through the whole subform as if it were one row, and uses a single
`=` where every other comparison uses `==`. So which row's tax decides the IGST0
path is not knowable from the source. **Do not port this line — port the intent**,
and decide explicitly whether IGST0 is a per-row or per-bill condition. Raise it
with Husain.

### The interstate check that was written and then switched off

Lines 27155–27176 hold a **fully commented-out** validation that keyed on the
vendor's GST number:

```deluge
// isIntraState = input.Vendor_Name.GST_No.startswith("27")     // 27 = Maharashtra
// inter-state + tax_group  → "Please select IGST (Inter-state tax)"; cancel submit
// intra-state + tax        → "Please select GST."; cancel submit
```

This matters because it is the missing half of the addendum §3 finding that **IGST
exists only at 0/5/18 while GST runs 0/5/12/18/28**. Someone built the guard that
would have caught a wrong-tax-type selection, and disabled it. The tax master
cannot satisfy it today: an interstate purchase at 12% or 28% has no IGST entry to
select, so the check would have blocked legitimate entry. Fixing the master and
re-enabling the guard are one job, not two.

Also live and uncommented: if the vendor has a `GST_No`, **every** `Amount_Category`
row must carry a GST selection, or submit is cancelled.

### Note on Vendor_Master

`Vendor_Name.GST_No` is read here, but `GST_No` is **not** in §4's `Vendor_Master`
field list or in §13A. The vendor master has at least one field more than either
document records.

### The `Payment InProgress` status is spelled two ways, both live `[DS]`

`Accounts.ds` contains **both** spellings, and they are not a doc-vs-code
divergence — they are both in the running code:

```
Payment InProgress   7 occurrences   (the handoff §2 rule-7 spelling)
Payment Inprogress  10 occurrences   (the §6.2 spelling, e.g. line 27297)
```

A status is compared by string equality throughout, so **every branch keyed on one
spelling silently misses records saved under the other.** Whichever the form writes,
roughly a third of the comparisons in the file cannot match it. This is a live
defect, not a cosmetic one, and it is invisible to review because both strings read
correctly to a human.

Consequences for the rebuild:

- **No CHECK constraint on `bills.status`.** Constraining it to either spelling
  would reject live data on import.
- Store the status **verbatim**, and compare through a single normalising accessor
  so there is exactly one place that knows both spellings are the same state.
- `[TODO]` for Husain: which spelling does the Bills form actually write today?
  That decides which records the existing branches have been missing, and it needs
  a row count per spelling out of the live data before anything is normalised.

---

## 11. The Approval form, fully specified — 22-Aug-2026

Screenshots of the All Approvals detail, edit form and Approvers grid, plus the
form definition at `Accounts.ds:61–200`. This closes handoff §6 item 7 — "any
second All Approvals record (for `Exclude Category`, `Type`, and the Approvers
bands)".

```
Module              picklist {"Payment"}                        ← ONE value only
Location            list  -> admin.Location.ID
Villa_Name          list  -> admin.Villa[Location.ID == input.Location].ID
Type_field          radiobuttons {"Include","Exclude"}          label "Type"
Item_Category       list  -> Item_Category.ID
Exclude_Category    list  -> Item_Category.ID
Level_1_2_Approval  picklist {"Any","All"}
Level_2_3_Approval  picklist {"Any","All"}

Approvers (grid)
  Level             picklist {"Level 1","Level 2","Level 3"}
  Minimum_Amount    INR, commadotindian
  Maximum_Amount    INR, commadotindian
  Approver          list -> admin.Employee_Master.ID, shown as [Name + " - " + Email]
  Approval_Type     picklist {"Any","All"}
```

### 1. The approval matrix is AMOUNT-BANDED. §8.2 does not mention this at all.

Each approver row carries its own `Minimum_Amount` / `Maximum_Amount`. The sampled
record has a single row: `Level 1`, `0` to `1,00,00,000`, approver
`Komal Takale - komaltakale28@gmail.com`, `Approval Type` unset.

This confirms what §4 of this addendum could only suspect. There are **two
independent amount-banded approval engines** in the application:

| | Settings `Approval` | Backend Expenses (§4) |
|---|---|---|
| bands | per approver row, `Minimum_Amount`–`Maximum_Amount` | fixed `lvl_one_amt` 1000 / `lvl_two_amt` 3000 / `lvl_three_amt` 5000 / `lvl_four_amt` 0 |
| levels | 1–3 | 1–4 |
| approver | FK to `Employee_Master` | `_name` text fields |

Neither knows about the other. A payment can therefore satisfy one engine's bands
and not the other's, and nothing reconciles them. **This must be resolved before
the approval engine is built** — §17 defers that work anyway, but the decision is
now a documented fork rather than an unknown.

### 2. `Level 3` is configurable and never used — so 4 records carry a dead rule

`Approvers.Level` offers `Level 3`, but across all 9 records the `Approvers` column
only ever reads `Level 1` or `Level 1,Level 2`. **No record defines a Level 3
approver.**

Yet `Level_2_3_Approval` is set on **4 of 9** records (3 × `Any`, 1 × `All`). Those
four configure how approval passes between levels 2 and 3 when no level 3 approver
exists to receive it. The rule cannot fire. `[TODO]` for Husain: is Level 3
intended and unconfigured, or abandoned?

### 3. `Module` has exactly one value, so the blank on record 6 is simply unset

`Module` is `picklist {"Payment"}`. Record 6 (Bangalore / Hawaiian Villa / WIFI)
has it blank, and blank is not a member of the picklist. So this is not "an
approval for another module" — it is an approval scoped to no module at all.

Whether §8.1's routing treats a blank module as "matches nothing" or "matches
everything" decides whether that record is inert or dangerously broad. It is also
the only record using `All` on both level fields. `[TODO]` — worth reading
`UpdatePaymentStatus` before assuming.

### 4. A fourth blank-as-real-state, and the pattern is now systemic

`Level_1_2_Approval` and `Level_2_3_Approval` are declared `{"Any","All"}`, and
`Approval_Type` likewise — yet the live data holds:

- `Level 1 & 2 Approval`: `Any` ×5, **blank ×3**, `All` ×1
- `Level 2 & 3 Approval`: `Any` ×3, **blank ×5**, `All` ×1
- `Approval Type`: unset on the sampled row

Together with `TDS.Status` (19 Active, **16 blank**, `Expired` never), that is four
picklists whose documented value set omits the state a third to a half of the data
actually sits in. **Treat "blank" as a first-class state on every Creator picklist
in this application**, and never model one as NOT NULL on the strength of its
declared values.

### 5. Villa derives FROM Location here — Bills is the outlier

`Villa_Name` is scoped `admin.Villa[Location.ID == input.Location]`. §5.1 records
that `Schedule_Payment` does the same, while Bills does the reverse
(`input.Location = input.Villas.Location`) and asks which direction is wrong.

Score is now **2 forms Location→Villa, 1 form Villa→Location**. Not proof, but
Bills is the minority behaviour, and Bills is the one feeding the split grid that
decides all attribution (§5.2).

### What the export could not have told us

None of the above is in `All_Approvals.json` — and it could not be. The report has
7 columns; the `Approvers` column flattens the subform to its `Level` values only,
discarding the amount bands, the named approver and the approval type entirely.
`Exclude Category` and `Type` are not report columns at all.

This is addendum §2's rule biting: an export mirrors the report's columns exactly.
**For any form with a grid, the screenshot is the primary source and the export is
a summary.**

---

## 12. §8.5 closed — Blueprints and Approvals are both empty `[UI]`

22-Aug-2026. Screenshots of the Creator builder's Workflow section show both
sub-tabs at their zero-state, offering only "Create Blueprint" / "Create Workflow".
**Nothing is configured in either.**

This was the largest open risk in the project — the handoff called it "the one gap
that could invalidate a whole section". It is retired, and nothing already built or
documented needs revisiting.

### What it establishes

**The payment status lifecycle is fully described by §6.5 and §7.3.** No platform
state machine sits behind it, so there are no hidden stage transitions and no
per-stage field permissions to discover.

**The approval engine is entirely hand-rolled Deluge**, in three unconnected
pieces:

1. `UpdatePaymentStatus` routing (§8.1)
2. the `Approval` matrix and its amount-banded Approvers grid (§8.2, §11 above)
3. the separate amount-banded engine inside Backend Expenses (§4 above)

No platform approval process sits above any of them. **So all seven disagreeing
representations of approval state (§8.4, §1 above) are application-level** — none
is a platform artefact, and all seven are ours to reconcile in the rebuild.

**The DS exports are complete for logic.** The workflow inventory reconciles
exactly against §2 of the context doc:

| Declaration in `Accounts.ds` | Count | §2 says |
|---|---|---|
| `type = form` | 284 | 284 form workflows |
| `type = schedule` | 21 | ~22 schedules |
| `type = functions` | 33 | 33 custom actions |

Those are the only three workflow kinds the file declares. With Blueprints and
Approvals empty, there is no known execution surface outside them.

This narrows §18's provenance caveat. "Creator Blueprints, Approval workflows" are
no longer unknowns — they are confirmed absent. What remains genuinely uncovered
is user/role assignments, print templates, and the `villa_operation` / `ers` /
Villa Operations Management / Dood System Development applications.

### One small residual

The same screen carries **Payments**, **Batch workflows** and **Report workflows**
sub-tabs, which §8.5 never listed. No blocks of those kinds appear in the DS
either, and given the three-kind reconciliation above they are almost certainly
empty too — but that is an inference from absence, not an observation. A glance at
those three tabs would close it properly.

### Method note worth keeping

An absent keyword is not an absent feature. A first pass grepping for the literal
word "schedule" returned zero hits even though 21 schedules exist, because the DS
declares them as `type =  schedule`. Any future "it's not in the DS" claim needs
the declaration syntax confirmed first — otherwise it is measuring the grep, not
the file.

---

## 13. The role → permission matrix is in the DS after all — 22-Aug-2026 `[DS]`

§3.3 records the full matrix as a `[TODO]`, and §18 lists "user/role assignments"
as uncovered. Both are only half right. The matrix **is** in `Accounts.ds`, in the
`share_settings` block at line 48208. What is genuinely missing is only the
*assignment* of people to profiles.

Extracted to `docs/permission_matrix.json` by `docs/parse_permissions.py` (brace
walker, not a regex — the block is nested). **20 entries: 19 profiles plus Creator's
`roles` hierarchy**, which holds a single role, `CEO`, described as "can access data
of all other users".

Permission verbs across the whole matrix: **View ×198, Edit ×123, Delete ×73.**
Granularity is per profile → per module → per report, plus an `allFieldsVisible`
flag per module.

### The profiles

| Profile | modules | reports | has a code path? |
|---|---|---|---|
| Account Team-Executive | 47 | 27 | yes |
| Account Team-Senior | 47 | 27 | yes |
| Account Team-Senior *(duplicate)* | 47 | 23 | ambiguous — see below |
| Property Manager | 43 | 1 | yes |
| Food Operator | 39 | 1 | yes |
| Market Head | 39 | 1 | yes |
| Central Operations | 39 | 1 | yes |
| Human Resources | 44 | 6 | **routed but unreachable** |
| CA Team | 41 | 6 | yes, via `CAAccess` |
| Payment Request | 39 | 1 | **none — manual only** |
| Salary Data Entry | 22 | 3 | **none — manual only** |
| Dependant Property Owner | 19 | 2 | none |
| Independant Property Owner | 19 | 2 | none |
| Read / Write / Write - same as admin | 26 / 34 / 44 | 32 / 33 / 32 | none |
| Administrator / Developer / Customer | Creator defaults | | none |

Two profiles the docs never mention are **`Dependant Property Owner` and
`Independant Property Owner`** — 19 modules each. That implies **villa owners have
portal logins**, which appears nowhere in §3.3 or anywhere else. `[TODO]` confirm;
it changes who can see owner-split figures.

`Payment Request` and `Salary Data Entry` are clearly bespoke and have **no
assignment code at all**, so they can only be granted by hand in the Creator UI.
That is precisely the part not in any file.

### The chain, end to end

```
admin.Employee_Master.User_Role   (free text)
    -> Admin.ds:1639+   .contains("Account Team-Executive") etc.   [routing]
    -> accounts.Accounts.PortalAccess(Email, UserName)              [mapping]
    -> thisapp.portal.assignUserInProfile(Email, "<Profile>")       [assignment]
    -> share_settings profile -> per module -> per report -> {View,Edit,Delete}
```

So §3.3's "matched with `.contains()`" is right about the **Admin** side, and the
**Accounts** side then re-matches with `==`. Two different comparison styles across
one hand-off, which is where both defects below live.

### ⚠️ Defect 1 — the case mismatch makes provisioning look inoperative

`Admin.ds` passes **Title Case**; `Accounts.PortalAccess` compares against
**lowercase** literals, with no `toLowerCase()` anywhere on the way in:

```
Admin.ds passes:            "Account Team-Executive"  "Human Resources"  "Market Head" ...
Accounts.ds compares:  UserName == "account team-executive"   "market head" ...
```

Deluge string `==` is case-sensitive, so **no branch can match and no profile is
ever assigned by this function.** Every user provisioned through this path would
land with no profile.

This is stated as a strong reading of the source, not a verified runtime fact —
Deluge's comparison semantics deserve one empirical check, and that check is easy:
**look at the Creator Users list.** If people are sitting in profiles, either the
comparison is looser than it appears or they were assigned by hand; if they are
mostly profile-less, this is why.

### ⚠️ Defect 2 — Human Resources is routed to a branch that does not exist

`Admin.ds:1667` routes `User_Role.contains("Human Resources")` into
`PortalAccess(..., "Human Resources")`. `PortalAccess` has **no `Human Resources`
branch** — its cases are executive, senior/accounts-head, food operator, property
manager, market head, central operations.

A fully populated `Human Resources` profile exists (44 modules, 6 reports) and
nothing can ever assign it. Independent of Defect 1, and it would still be broken
if the casing were fixed.

There is also **no `else`** on either chain, so an unrecognised role fails silently.
`Manager` — a role §3.3 lists — has no branch either.

### ~~Defect 3 — a duplicated profile~~ — CORRECTED 22-Aug-2026

I read the two `Account Team-Senior` entries in `share_settings` as a duplicated
profile with an ambiguous assignment. **That was wrong.** Screenshots of the
builder show Creator keeps **two separate permission systems**, and the name exists
once in each:

| Screen | Profiles |
|---|---|
| **User Permissions** | Account Team-Senior · Read · Write · Write - same as admin |
| **Portal User Permissions** | Customer · Account Team-Executive · Account Team-Senior · Independant Property Owner · Dependant Property Owner · CA Team · Salary Data Entry · Payment Request · Market Head · Property Manager · Central Operations · Food Operator · Human Resources · Admin |

`thisapp.portal.assignUserInProfile` is the **portal** API, so it unambiguously
targets the portal set. There is no ambiguity to resolve.

What remains true is that the shared name is a hazard for anyone reading the
export, and that `Write - same as admin` is self-described as a "**Duplicate**".

Separately, the **Roles** tab holds exactly one role, `CEO` — "can access data of
all other users" — with seven associated users. Roles are Creator's data-sharing
hierarchy and are orthogonal to profiles. One `CEO` holder is a non-company gmail
address; worth a look on access-review grounds, though it may simply be a
consultant.

### What this means for the rebuild

§17 step 3 requires "a test asserting each of the 10 known roles maps to an
explicit permission set; no string `.contains()` anywhere in the authorisation
path". That is now buildable: `docs/permission_matrix.json` is the source data, and
the three defects above are exactly what the roles/permissions tables are meant to
make impossible.

Do **not** port the mapping as written. Port the *intent*, seed
`permission_role` from the extracted matrix, and resolve the duplicate and the two
dead paths deliberately with Husain.

---

## 14. Employee_Master, 475 records — the provisioning funnel measured

`master-data/All_Employee_Masters.csv`, exported 22-Aug-2026. 475 records, 13
columns, all 475 ids intact as 18-digit strings with no duplicates (Creator quoted
them, so the CSV is lossless here).

### ⚠️ Automatic portal provisioning assigns ZERO profiles

Running the actual Deluge logic over the actual data:

| stage | result |
|---|---|
| employees | **475** |
| routed by `Admin.ds` `.contains()` | **4** |
| assigned a profile by `Accounts.PortalAccess` | **0** |

It fails twice, independently, in opposite directions:

1. **`Admin.ds` matches Title Case against lowercase data.** The routing tests
   `.contains("Account Team-Executive")`, `.contains("Property Manager")` and so
   on, but the data holds `account team-executive` (×17), `property manager` (×48),
   `market head` (×21), `food operator` (×21), `account team-senior` (×12),
   `central operations` (×5). **471 of 475 are never routed at all.** The only 4
   that get through are the 3 `accounts head` records — because that one literal
   happens to be lowercase in the source — and the single record whose role reads
   `Market Head` in Title Case.
2. **`Accounts.PortalAccess` then matches lowercase against the Title Case
   literals Admin passes it.** Admin passes `"Account Team-Senior"`; PortalAccess
   compares `UserName == "account team-senior"`. **All 4 survivors are dropped.**

So every profile assignment in the live application must have been made **by hand**
on the Portal User Permissions screen. §13's reading of the source is confirmed by
the data.

One caveat kept deliberately: this assumes Deluge's `contains()` and `==` are
case-sensitive, which is the documented behaviour. The two-sided mismatch — one
file Title Case, the other lowercase — is itself evidence they were written against
different assumptions. **The decisive check is one click: open any Portal User
Permissions profile and see whether users are listed.** If they are, they were
added manually or Deluge is looser than documented.

### `market head` vs `Market Head` — the casing is live in the data

21 records lowercase, 1 Title Case, same role. Any case-sensitive comparison
anywhere in the authorisation path splits this population. This is the third
instance of the same class in this application, after `Payment InProgress` /
`Payment Inprogress` (§10) and `amazon` / `Amazon` on vendors (§6).

### 24 distinct `User_Role` values, not the 10 in §3.3

```
189 caretaker                 17 account team-executive     3 promoter
 48 property manager          16 superadmin                 3 accounts head
 38 salesperson               12 account team-senior        2 vendor
 38 dependant property owner  11 sales manager              2 co-founder
 23 (blank)                    7 independant property owner 2 check-in assistant
 21 market head                5 operations executor        1 Market Head
 21 food operator              5 central operations         1 ops analyst
                               5 store_keeper               1 dataentry
                               4 administrator
```

**Fourteen values have no routing branch at all**, including `superadmin` (16) and
`administrator` (4). Most of the rest legitimately need no Accounts access
(`caretaker`, `salesperson`), but the roles table must still enumerate them or the
authorisation path will have holes where a value falls through silently — §17
step 3's "no string `.contains()`" requirement exists for exactly this.

`Human Resources` **does not occur even once in the data.** So that branch is dead
three times over: absent from `PortalAccess`, absent from the data, and its
44-module profile is unassignable.

### Villa owners as portal users — §13's inference confirmed

**45 property-owner records** (38 dependant, 7 independant), matching the two
portal profiles. Villa owners really do have logins. That decides who can see
owner-split figures and needs a deliberate answer in the rebuild — it is not
mentioned anywhere in §3.1's commercial fields.

### 189 caretaker records are per-villa service accounts, not people

`pinewood@ekostay.com`, `casapolo@ekostay.com`, `amber@ekostay.com` and so on —
40% of the employee master is one shared mailbox per villa. The identity model must
distinguish **person** from **villa service account**; treating all 475 as people
will produce nonsense in any per-user audit trail.

### Five emails carry conflicting roles across duplicate records

Seven emails have more than one `Employee_Master` record; five of those disagree
on `User_Role`:

| email | roles held simultaneously |
|---|---|
| `shaikharmaan914@gmail.com` | account team-senior **and** account team-executive |
| `om@manaslifestyleresort.in` | independant **and** dependant property owner |
| `onyx@ekostay.com` | dependant property owner **and** caretaker |
| `wada@ekostay.com` | caretaker **and** blank |
| `hr@ekostay.com` | account team-senior **and** blank |

Provisioning keys on **Email**, so which record decides a person's access is
arbitrary. `om@manaslifestyleresort.in` holding both owner types is contradictory
by definition — the two profiles differ, and the split determines whether that
owner sees other properties' figures.

### A fifth blank-as-real-state

`Status` is `Active` ×395, `Inactive` ×78, **blank ×2**. §3.2 documents
`{Active, Inactive}`. `Access.Accounts` runs the `DeleteAccess` mirror on
`Status != "Active"`, so a blank status silently revokes access. Add it to the
list with `TDS.Status`, both approval level fields, and `Approval_Type`.

### The extracted matrix is an 08-Aug snapshot, not the current state

Reconciling the DS extraction against the two live permission screens
(22-Aug-2026):

| | |
|---|---|
| profiles in `Accounts.ds` `share_settings` | 19 |
| profiles visible on the two screens | 17 |
| on screen, **absent from the DS** | **`Admin`** ("Admin Profile") |
| in the DS, absent from both screens | `Administrator`, `Developer` |

`Admin` does not appear anywhere in `Accounts.ds` — not as a profile name, not as
the description "Admin Profile". The DS was generated 08-Aug-2026 and the
screenshots are 22-Aug, so the most likely explanation is that `Admin` was created
in between; the alternative is that Creator does not export every portal profile.

Either way: **treat `docs/permission_matrix.json` as a dated snapshot, not the live
permission state.** Re-export the DS before relying on it for a cutover, and expect
at least one profile to be missing from any analysis based on it. `Administrator`
and `Developer` are probably Creator built-ins surfaced on a different screen, but
that is an assumption, not an observation.

### Where portal user membership is NOT

`Settings → Portal User Permissions` **defines** profiles. It carries no user count
and no member list, so it cannot answer who holds which profile — the question
§14 needs settled to confirm whether provisioning ever worked. That roster lives
elsewhere. Note the `Roles` tab does show an **Associated Users** block for `CEO`,
so a profile's detail view is the place to look.

---

## 15. Villas, 254 records — three documented facts corrected

`master-data/All_Villas.csv`, exported 22-Aug-2026. 254 records, 252 distinct
names, 18 columns. All 254 ids intact as 18-digit strings, no duplicate ids.

This is the **report** export. §3.1 describes ~40 fields, so the commercial half is
still absent: `Hide_From_Payments` (the filter Bills and Payments actually use),
`Status`, `Inner_Circle`, `Expense_Base_Amount`, the GST and revenue/expense split
percentages, the F&B commercial fields, both category-scoping mechanisms, and the
`Villa_Managers` / `Owner_Details` grids. Those need a form-level export.

### ⚠️ Correction 1 — `Rent_Type` has only TWO values in the live data

§3.1 calls this a **"live correctness bug"**: four rent types exist, Accounts
branches only on `Lease` and `Revenue Share`, so the two EKOSTAY split types fall
through unhandled.

The data says otherwise:

```
Lease           180
Revenue Share    65
(unset)           9
Revenue Split EKOSTAY    0
Expense Split EKOSTAY    0
```

**Zero villas use either EKOSTAY type.** The bug is **latent, not live** — it
becomes real the moment anyone selects one. Severity drops from "affecting
production today" to "a trap waiting on a picklist".

The guard stays as built: the `villas_rent_type_check` CHECK admits all four so the
domain cannot be silently narrowed again, and `VillaRentTypeTest` still asserts a
fixture per value. What changes is priority, not design. Also note **9 villas have
no rent type at all**, which §3.1 does not mention and which no branch handles
either.

### ⚠️ Correction 2 — the category is spelled `Luxury`, correctly

§3.1 and handoff §2 rule 7 both record `Luxery` as a source misspelling to
preserve. It does not occur:

```
Gold 123 · Original 86 · Luxury 34 · (blank) 11
```

That entry on the preserve-spellings list is **stale** — either fixed since the doc
was written, or never right. Removed from the migration docblock.

### ⚠️ Correction 3 — `Uttarakand` IS a real misspelling, and is not on the list

States, which the docs never enumerate:

```
Maharashtra 157 · Goa 37 · Tamil Nadu 31 · Himachal Pradesh 13 ·
Uttarakand 7 · Rajasthan 3 · Karnataka 2 · Kerala 2 · (blank) 2
```

`Uttarakand` is missing its 'h'. It is a live grouping key, so it is inserted
verbatim and normalised at display only. **Add it to handoff §2 rule 7** in place
of `Luxery`.

### The footprint is much wider than §1 describes

Handoff §1 says "Maharashtra, Goa, Tamil Nadu, Karnataka, Uttarakhand — and, it
turns out, Kodaikanal and Bangalore too". The real numbers: **9 states and 29
locations.**

```
Alibaug · Arpookara · Bangalore · Bhimtal · Chikmagalur · Dalhousie · Dehradun ·
Goa · Head Office Central · Igatpuri · Jodhpur · Karjat · Kasauli · Kodaikanal ·
Kufri · Kullu and Manali · Lonavala · Mumbai · Munnar · Mussoorie · Nainital ·
Nashik · Ooty And Coonoor · Panchgani · Panvel · Pune · Solan · Virar · Wada
```

**Nineteen of those were missing from the seeded locations table**, because
`Location_Master_recovered.json` only held the 10 referenced by an approval rule.
Every villa in the other 19 had no location to resolve to. Now seeded from the
villa report by `GeographySeeder`.

`Head Office` holds one value, `Central Office`, and is **blank on 75 of 254**.

### 48 villas are covered by no approval rule

Set difference between the villa master and every villa named across the 9
approval records: **48 villas appear in no approval scope at all.** §8.2 routes
approval by Module / Location / Villa / Type / Category, so a payment against one
of those 48 matches no rule. Whether that means "no approval needed" or "cannot be
approved" depends on §8.1's fallback, which is worth reading before Payments is
built. `[TODO]`

### Confirmed, not corrected

- **`Nature` really is three separate records** with distinct ids — addendum §3 was
  exact. All three seed as three rows, which is right: each is a distinct grouping
  key for §5.1 splits.
- **`fcgfhbjnh`** is a real record in the master.
- **12 villa names carry a leading space** and they are real records. Both
  `Copacabana Villa Calangute` and `Copacabana Villa- Calangute` exist, as do the
  three doubled-space names.
- Every one of the 204 recovered names resolves to a real villa, so
  `Villa_Master_recovered.json` was faithful as far as it went.
- **`BHK` is TEXT** — `4.5BHK`, `5.5BHK`, `6.5BHK`, `7.5BHK` all occur.

### Method note — a strip I should not have made

My first pass at comparing the recovered names against the master reported 8
recovered names as missing. That was wrong: I had built the comparison set from
*stripped* names while comparing against *unstripped* ones, so the 12 real
leading-space villas looked like phantoms. On a dataset whose whole premise is that
whitespace is significant, normalising inside an analysis is the same mistake as
normalising on import. Compare raw, always.

### `Haewaya_ID` needs a parser, not a `split(",")`

`master-data/All_Villas.json` (same report as the CSV, re-exported 22-Aug-2026)
exposes how irregular the packed list is. Across 254 villas:

| quirk | count | example |
|---|---|---|
| leading **TAB** character | 3 | `'\t8186'`, `'\t10597'`, `'\t10677'` |
| **trailing** comma | 9 | `'10681,'`, `'5237,'`, `'618,1034,'` |
| space after the comma | 1 | `'621, 5794'` |
| several ids, no separator issues | 95 | `'4847,1065,1033,961,962,8400,960'` |

So unpacking is: split on comma, drop empty segments, trim each segment of
whitespace **including tabs**. A naive `split(",")` yields empty strings and
tab-prefixed ids that will not match anything on the Haewaya side.

Consistent with the standing rule, the raw string is stored verbatim and the
unpacking happens deliberately at the point of mapping — never on import.

### Two more `BHK` values break the pattern

`Santorini Villa` holds `2` and `Ecstasy Villa` holds `4` — bare numbers with no
`BHK` suffix, against 243 that carry it (`3BHK`, `6.5BHK`). Both are inactive
records with almost every other field blank, so they look like abandoned drafts.
It confirms the column must stay TEXT: neither a number parse nor a suffix strip
handles the whole set.

### The CSV and JSON exports of the same report are not identical

The CSV carries the villa's own `Modified Time` / `Added Time`; the JSON carries
`Location.Modified_Time` / `Location.Added_Time` — the **location's** audit
timestamps reached through the lookup. Same 18 columns otherwise, same 254 rows.

Worth knowing before treating any two exports of "the same report" as
interchangeable. Both preserved the tab characters and the 18-digit ids intact.
Creator's JSON export renders booleans as the STRINGS `"true"` / `"false"`, which
is exactly the §15.2 trap — the seeder's `bool()` accepts both by design.

---

## 16. Payments built — the §17 step 7 gate opened, 22-Aug-2026 `[DS]`

§17 step 7 said **STOP before Payments** until the four §16 "blocking write paths"
questions were answered. All four are now answered from the DS exports, which have
been in the working set since 22-Aug-2026. Two were questions; **two were defects.**

### 16.1 The four blockers, closed

| Question | Answer |
|---|---|
| §3.3 full role→permission matrix | **Extracted.** `docs/permission_matrix.json` — 122 permissions, 127 role-permission pairs, 25 roles. Addendum §13 has the derivation. |
| §7.6 payment-number padding — fix or preserve | **Neither: the branches are dead code.** See 16.2. |
| §7.2 partial-payment sign convention | **Not a convention — a bug.** See 16.3. |
| §12.4 is the Expenses delete intentional | **Confirmed destructive, and there is a worse one.** See 16.4. |

### 16.2 §7.6 — the padding never fires any more

`Accounts.ds:45400`:

```deluge
Series = ifnull(fetAuto.Payment_No,1);
if(Series < 10)        { Series = "000" + Series; }
else if(Series < 100)  { Series = "00"  + Series; }
if(Series < 1000)      { Series = "0"   + Series; }   // <- NOT else-if
```

The third `if` is unchained, so on 1–99 it fires **on top of** a branch that has
already padded — five characters where four were intended.

But `master-data/Auto_Numbers.json` shows `Payment No` = **20938**. Every branch
tests below 1000, so none has fired for roughly twenty thousand payments. The live
format is a bare counter: `EKS/PY/20938`.

**Consequences.** Nothing to fix going forward. Historical rows numbered 1–999 do
carry three different widths (5 digits for 1–99, 4 for 100–999, unpadded above),
so **sorting payment numbers as strings mis-orders the old data** — sort on the
counter, or zero-extend at the edge for display.

The real defect at that line is different, and it was fixed: Creator reads
`Auto_Numbers[ID != null]` with no ordering and increments non-atomically, so two
concurrent `Create_Payment` calls can take the same number. `PaymentNumber::allocate()`
holds a row lock and refuses to run outside a transaction. The singleton is now
enforced by a unique index rather than assumed.

### 16.3 §7.2 — the partially-paid sign is a bug, and it overpays vendors

`Accounts.ds:45452`, per split leg, when the bill is `Partially Paid`:

```deluge
payamount = Totalamount - gstamount + tdsamount;    // TDS ADDED
```

The bill-level formula at `Accounts.ds:22489-22490` is

```deluge
Total_Amount   = Amount + GST_Amount
Payable_Amount = InvoiceAmount - TDSTotal      // InvoiceAmount = sum of leg Total_Amount
```

so the normal payable is `Amount + GST - TDS`. Substituting `Total = Amount + GST`
into Creator's partially-paid line gives `Amount + TDS`. The two differ by

```
(Amount + TDS) - (Amount + GST - TDS)  =  2*TDS - GST
```

TDS is **withholding** — money kept back from the vendor and remitted to the
department. Adding it pays the vendor the tax as well as the invoice. **For a
TDS-only vendor with no GST the overpayment is exactly twice the TDS.** §10.3
records the GST/TDS basis differing across three modules, so no-GST bills are
ordinary here, not a corner case.

Worth quantifying against live data before anything else: the exposure is every
partially-paid bill ever settled.

### 16.4 §12.4 — the delete is real, and `DeleteAllRecords()` is worse

The documented one is confirmed: `F_B.ds:5927` hard-deletes `Expenses` rows keyed
on a payment number the docs already describe as unstable.

Grepping every delete in the exports turned up something the docs do not mention.
`F_B.ds:4645`:

```deluge
void DeleteAllRecords()
{
    delete from Booking[ID != null];
    delete from Monthly_Check[ID != null];
    delete from Transaction_Items[ID != null];
    delete from Warehouse[ID != null];
    delete from Request_Stock_for_Food[ID != null];
    delete from Vendor_Order_Booking[ID != null];
    delete from Expenses[ID != null];
    delete from Raw_Material_Request[ID != null];
    delete from Item_Master[ID != null];
    delete from Chef_Master[ID != null];
    delete from Vendor_Price_List[ID != null];
    delete from UOM[ID != null];
    delete from Inventory[ID != null];
    delete from Inventory_Stock[ID != null];
}
```

`ID != null` matches every row. This is a **standalone function that wipes 14 F&B
tables**, and standalone Deluge functions are invocable as REST endpoints. It reads
like a development reset helper left in a production app.

Full delete census across the three exports:

```
19  delete from Expenses_Bills        6  delete from Pending_Approvals
14  delete from Payment               5  delete from Vendor_Order_Booking_Item
 8  delete from Expenses              5  delete from Raw_Material_Request
```

**Belongs on the §9 list of things to act on independently of the rebuild** —
alongside the DoubleTick key at `Accounts.ds:22851` and the negative-HRA band.

### 16.5 Deviations from Creator, as built

Husain's instruction on 22-Aug-2026 was **fix both, log the deviation**. Logged:

| # | Deviation | Where | Why |
|---|---|---|---|
| **D1** | Partially-paid legs **deduct** TDS instead of adding it | `PayableFormula::partiallyPaid()` | 16.3. Creator's line is kept as `creatorPartiallyPaid()` for reconciliation, and a test asserts the divergence is exactly `2*TDS - GST`. Not called by the write path. |
| **D2** | Payments now **enforce** that split legs sum to the payable | `PaymentSplitValidator` | §7.4 says Payments has no such check and an unbalanced payment misstates every downstream villa-month-category figure. Compared **exactly**, not at whole rupees like Bills (§6.4) — payment legs are computed, not typed, so a paisa of drift is an arithmetic fault, and rounding it away hides the cause. |
| **D3** | No number padding; allocation under a row lock | `PaymentNumber` | 16.2. |
| **D4** | **No hard delete.** `Delete Paid Payment` replaced by a reversing entry | `ReversePayment`, `Payment::delete()` | §7.6. A reversal is a new row with negative amounts and its own number, linked by `reverses_payment_id`, with a required reason; the original keeps its number, amounts and legs. The guard is on the **model**, not just the route, so none of the 14 DS delete sites can be reproduced by a future caller. |
| **D5** | The whole `Create_Payment` action is one transaction | `CreatePaymentFromBill` | Creator inserts the payment, then the bill grid, then the split grid, then mutates bill status, with no transaction. A failure part-way leaves a payment with no legs, which §5.2 says silently misstates the ledger. |
| **D6** | §7.1's vestigial fields are **not** reproduced | `payments` migration | The `A`/`B`/`C`/`D` checkboxes, eight `*_Updated_User_*` fields, the untouched `Radio {Choice 1..3}` default, and the duplicate `Bill_No`/`Bill_No1`, `Location`/`Multi_Location`, `Payment_Reference_Number`/`...1` pairs carry no behaviour in 59,063 lines of DS. `Paid_Amount` is also absent: §7.1 records it as a **checkbox** on Payment and a currency field on Bills, and a boolean named `paid_amount` beside real money columns is a defect waiting to happen. |

Both status axes keep Creator's dirty enums verbatim — `Sent for Approval` **and**
`Send for Approval`, lowercase `paid`, and the undeclared-but-live `Open` that
`Create_Payment` writes. No CHECK constraint on either column; comparison goes
through `PaymentStatus`, and `paid` is capitalised only at display.

### 16.6 What is verified, and what is not

Verified by a payment created and reversed end to end against the seeded masters:
the number came off the real counter (`EKS/PY/20938`, counter -> 20939), the bill
moved to `Payment InProgress`, an unbalanced split was refused with the counter
and bill status untouched, a hard delete was refused at the model, and after
reversal **every villa x category x cycle nets to zero** — which is the whole
point of D4, and something a delete can never give. 42 tests cover it.

**Not verified, and still wanted:**

- **The All Payments column order.** handoff §6 item 4 — inferred, not seen.
  `Recoverable`, `Bank Reconciliation` and `Withdrawal Ma...` exist and are not in
  it, and there is a per-row action button. A screenshot settles this in a minute.
- **Authorisation.** The §3.3 matrix is extracted and tested but **not yet wired
  to a gate**, so anyone reaching `/api/payments` can create and reverse. Fine on a
  local build; a blocker before this is exposed to anything else.
- **Which Payable formula is authoritative** (§6.3) is still open, so D2's check on
  the partially-paid path is a *consistency* check — it catches a leg that fails to
  compute, not a backend snapshot that is itself wrong.
- **The approval engine** between `Submit for Approval` and `Paid`. §8.2's matrix is
  amount-banded (addendum §11) and collides with the second amount-banded engine in
  Backend Expenses. Payments currently jump straight to settled when told to.

---

## 17. Settings add / edit built — and the middleware that would have broken it, 22-Aug-2026

Reported: "the add, edit and nothing in the functionality is working." Correct, and
nothing was broken — **it had never been wired**. The Settings reportbar rendered
`Search`, `+`, `…`, `Save Changes` and `Remove Changes` as plain buttons with no
`onClick`, and the application had exactly two write routes, both on Payments. The
form CSS (`.zc-overlay`, `.zc-field`, `.zc-input`, `.zc-check`, `.zc-commit`,
`.zc-searchrow`) was already in `zc.css`, unused — the intent was there, the wiring
never landed.

### 17.1 TrimStrings would have silently destroyed a live lookup key

**This is the finding worth keeping.** Laravel ships `TrimStrings` in the global
middleware stack, and `bootstrap/app.php` had not touched it. The first edit saved
through a normal route would have trimmed every string field.

`F&B STAFF MEDICAL EXPENSE ` is **26 characters stored and 25 trimmed** — verified
directly against the database, not inferred from the export. CLAUDE.md's rule
("These are live lookup keys. Normalise at display only, never in data") was being
honoured on import, where the seeder's `text()` reader deliberately does not trim,
but the write path would have disagreed with it. No error, no warning: the record
would simply stop matching every join keyed on that name.

Eight villa names carry a leading space and three carry doubled spaces (§15), so
this is not a single-record curiosity.

`api/settings/*` is now exempt, by closure rather than by attribute list so a field
added later cannot silently fall outside the exemption:

```php
$middleware->trimStrings(except: [
    fn (Request $request): bool => $request->is('api/settings/*'),
]);
```

Four tests pin it — a trailing space surviving a create, surviving an edit, leading
and doubled spaces surviving, and the trimmed and untrimmed forms being accepted as
**distinct records**. That last one also proves uniqueness compares the exact
string; if it trimmed, the live key would be unrepresentable.

`ConvertEmptyStringsToNull` is deliberately left ON: `''` becoming null matches what
the seeder does on import, so a field cleared in the form and a field absent from an
export end up identical.

### 17.2 One definition for the grid and the form

`App\Domain\Settings\ReportRegistry` now holds table, column order, ordering, and
the editable field set for all five built reports. The read controller and the write
controller both read it. Two copies of a column list is how a report and its form
drift apart, and that was about to happen.

`show` returns `fields`, `column_map` and a per-row `_values` block. `_values`
matters more than it looks: the grid displays a master category's **name** while the
form edits its **id**, and trailing whitespace is invisible in a table cell but has
to survive a round trip through the form.

### 17.3 What is editable, and what is deliberately not

- **Add** and **edit** on all five reports; **search** filters every displayed
  column, which is how Creator's single search box behaves.
- **COA inline editing** — `Save Changes` / `Remove Changes`, several rows in one
  transaction. One bad row rolls the whole commit back, because the grid is edited
  as a unit and applying half would leave the screen disagreeing with the database.
  Only `inline_columns` may be touched; a payload naming anything else is rejected
  rather than filtered, so a caller is told rather than surprised.
- **`creator_id` is readonly.** An 18-digit Creator id must not be typed in — doing
  so fabricates a link to a Creator row that does not exist (§15.2).
- **Nothing is deletable.** No DELETE route, on any of the five. These are live
  lookup keys with FK children (135 item categories hang off 10 master categories),
  and no Creator screenshot has shown a delete control on any of the eight Settings
  reports. §7.6's argument against hard deletes applies with more force to a master
  than to a payment.
- **The `…` menu is left inert and disabled.** Its Creator contents have not been
  seen on any Settings screenshot, and inventing entries would be redesigning.

**Field set and order are inferred** for all five — no Creator *form* screenshot
exists for any Settings report, only report screenshots. The form says so on screen
rather than only in a docblock. The columns themselves are real.

### 17.4 Two UI defects the browser found that review did not

- **Booleans rendered as the literal text `false`.** The old shell did
  `String(value)`, so `Exclude for Profit` read "false" on all 135 rows. Creator
  shows a checkbox. Now a checkbox, with the pink unchecked outline §2 records.
- **A read-only checkbox ate the row click.** Clicking the middle of a row did
  nothing, because the midpoint of a seven-column row lands on `Exclude for Profit`.
  A `disabled` input dispatches no click event *and* does not let one through to its
  ancestors, so the row handler never fired and the edit form never opened.
  Fixed with `pointer-events: none` on the read-only case; `stopPropagation` stays
  on the editable case, where a click should toggle the box and nothing more.

Both were invisible to code reading and only appeared when the grid was actually
driven. Consistent with the standing rule in CLAUDE.md — verify by rendering.

### 17.5 Still open on these screens

- **No authorisation.** Same gap as Payments: the §3.3 matrix is extracted and
  tested (`docs/permission_matrix.json`) but not wired to a gate, so anyone reaching
  `api/settings/*` can add and edit masters. Fine on a local build; a blocker before
  exposure.
- **Three of the eight Settings reports are still unbuilt** — All Approvals, Block
  Payment Date, Auto Numbers. All Approvals is fully specified in §11 and is the
  obvious next one. Block Payment Date is the singleton whose cutoff §16 found is
  enforced nowhere server-side, so building the screen without the enforcement would
  be misleading.
- **Whether the COA `hide` checkbox IS the form's Hide field** — handoff §6 item 2,
  still unanswered. The form surfaces the uncertainty as a field hint rather than
  quietly picking a reading. If it is, §7.5's "inverted filter" finding dissolves.

---

## 18. Vendor Master seeded — and §13A.1 answered by counting

Recorded 24-Aug-2026. `Vendor_Master.csv` — 8,063 records, 21 columns, exported
22-Aug-2026 — is now in `master-data/` and seeded.

Two vendor exports arrived; the newer is a strict superset of the older by exactly one
record (`VAGAD SUPER MARKET`, id `292482000010854187`), so the newer one is what is in
`master-data/`. Worth stating because it means the export is a live snapshot, not a
frozen extract — a re-export will not match these counts, and the tests are pinned to
these. This is the largest table in the
application by two orders of magnitude, and the first one big enough to change how a
screen has to be built.

**It also corrects this repo.** `CLAUDE.md` listed vendors under *"Not seeded, no
export exists"*. The export existed. Of the four tables on that list, only
`billing_cycles`, `employee_designations` and `employee_departments` are still
genuinely unexported.

### 18.1 §13A.1 is answered — and it did not need a DS grep

Spec §13A.1 asks *"Collapse to one relationship. Which of the three is real?"*, and
`CLAUDE.md` files it under the questions reopened by the DS exports. It turned out to
be answerable from the data alone, by counting:

| field | rows | what it is |
|---|---|---|
| `Primary Vendor` | 112 | the merge **pointer** — the vendor this row was merged into |
| `Primary Status` | 93 | flags the merge **target** |
| `Main Primary` | 6,957 set | **not** a merge field at all — see §18.2 |

The evidence is that the first two are *perfectly* consistent, which a denormalised
field never is:

- all 112 pointers name a vendor whose `Primary Status` is true
- the two are **mutually exclusive** — 0 rows carry both
- 93 distinct names are pointed at and 93 rows are flagged: **0 orphan flags, 0
  unflagged targets**

`Main Primary` fails the same test outright. It differs from `Vendor Name` on 739
rows, of which only 108 are merges — **the other 631 have no pointer at all.**
Resolving a merge through it would move money to the wrong vendor 631 times.

One row proves it goes stale on merge: `MOHANRAJ Y (CT)` carries
`Primary Vendor = MOHANRAJ V (PM)` while its `Main Primary` still reads
`MOHANRAJ Y (CT)`. It is the only row where the two disagree, and it disagrees in
`Main Primary`'s direction being wrong.

**So: follow `primary_vendor`, and never resolve a merge through `main_primary`.**

### 18.2 ...but `Main Primary` is load-bearing for something else

This confirms and quantifies the `[UI]` note already in spec §13A.1 — *"Main_Primary
mirrors Vendor Name for trade vendors and is empty for customers"* — which had never
been measured:

| population | rows | `Main Primary` blank |
|---|---|---|
| named `...(Customer)` | 1,099 | **1,097** (99.8%) |
| everything else | 6,964 | **9** (0.1%) |

`Vendor_Master` holds **two populations in one table** — trade vendors and customer
payees — and the field that separates them is the one nobody documented as
separating them. Eleven rows disagree with the rule in both directions; the point is
that the signal is strong, not that it is a constraint.

The field is therefore the *wrong* one for merges and the *right* one for telling the
table's two populations apart. Both statements are pinned in
`tests/Feature/VendorMasterSeedTest.php`.

### 18.3 The pointer is a name, so it does not always resolve

Creator stores the merge pointer as a **name**, and one such name —
`ETRADE MARKETING PRIVATE LIMITED` — matches four vendor rows. A name cannot always
identify one record.

So `primary_vendor` (text, verbatim) is the authority and `primary_vendor_id` is a
convenience filled in only where resolution is unambiguous: **108 resolved, 4 left
null.** A null id beside a non-null text is a fact about the data, not a gap in the
import, and the UI states it on the record rather than guessing a link.

A related trap: the trailing-space variant `ETRADE MARKETING PRIVATE LIMITED ` is a
**fifth, separate row**, and it is not one of the four the pointer matches.

### 18.4 Three columns are all labelled `GST No.`

Spec §13A already notes this. What it costs is new: the shared `masterDataCsv()`
reader keys rows by header name through `array_combine`, which **silently drops
duplicate headers** — last one wins, no error. Read that way, two of the three GST
columns vanish and 7 rows of data disappear without trace.

`masterDataCsvPositional()` was added for this, and any export whose header repeats a
label must go through it. The three columns are stored **positionally** —
`gst_no_1/2/3` — because naming them by guessed meaning would bake the guess in:

- `gst_no_1` populated on **7** rows, identical to `gst_no_2` on all 7
- `gst_no_2` populated on **292**
- `gst_no_3` populated on **290**, disagreeing with `gst_no_2` on **6**

If #1 were merely #2 rendered twice, it would not be blank on the 285 rows where #2
is set. What each Creator field actually is needs a form-level export.

Two of the six disagreements look like data entry rather than a second registration:
`ASHISH AMAZON` and `Vipul Garg` both carry Amazon's `27AAMCA0671Q1Z4` in #2 against
a personal GST in #3. **Not reconciled.**

### 18.5 The dirty data, and what tidying it would cost

All of it is asserted in `VendorMasterSeedTest`, so a later cleanup fails loudly.

- **328 names carry edge whitespace** — 326 with spaces, and **two ending in TAB
  characters** (`Mohan Mukhikya`, `Mukesh chaudhary Alibaug` with three). A tab is
  invisible in every UI and survives nothing that trims. This is the
  `F&B STAFF MEDICAL EXPENSE ` rule at 328x scale. Note the test asserts **326**, not
  328: Postgres `trim()` strips spaces only, so a `trim()`-based check would not have
  found the tabs at all.
- **5 records have no name**, added by three different users between Oct 2025 and
  Jul 2026, all Creator-stamped. §13A already records Payment Requests approved
  against a blank vendor — this is where such a request comes from.
- **62 names occur on more than one record** (7,985 distinct over 8,063). Including
  `DECATHLON` and `Decathlon`, and two `Hussain`. This is why `vendors.name` has no
  unique index, and why every picker option carries its GST or PAN.
- **GST numbers are cased inconsistently** (`27aahfe2088h1zb` /
  `27AAHFE2088H1ZB` — the same registration) and one carries a **trailing space**:
  `Decathlon` holds `27AAACL9861H1Z6 ` in #2 against `27AAACL9861H1Z6` in #3. A GST
  validator belongs on the form, where a human sees the value it rejects, not on an
  import that rewrites history.
- **PAN populated on 515 of 8,063, 18 of them the literal `NA`.** A PAN-shaped CHECK
  would reject live rows.
- **Location blank on 7,057 rows — 87.5%.** Any report grouping vendors by location
  covers an eighth of them.

### 18.6 `Alleppey` — one location row created, and why that is not fabrication

The vendor export names 13 locations. Twelve resolve; `Alleppey` does not, because
`locations` was derived from `All_Villas.csv` and Ekostay has **no villa** in
Alleppey — but it has one vendor there.

`All_Villas.csv` is a *villa-scoped view* of Creator's Location master, not the master
itself, so a missing row is an incomplete recovery rather than evidence the value is
invalid. `VendorSeeder` creates exactly that one row and announces it on the console.
**`MasterDataSeedTest` now expects 30 locations, not 29** — that change is this, not a
data drift.

### 18.7 `employee_designation` is text, deliberately

The export yields **25 distinct designations** across 287 employee-flagged vendors,
`caretaker` x213 dominating. `employee_designations` is still an empty table with no
export of its own, so these 25 are a **candidate source** for it, not proof of its
contents — a vendor-side list need not be the master's full list. Their own dirtiness
is the argument: `Social media `, `OFFICE BOY `, `HELPER` against `chef`, mixed case
throughout. That is not a curated picklist. Pointing an FK at a list inferred from one
report would assert more than is known.

This is also the second employee register spec §13A.1 flags — 287 employee-flagged
vendors alongside 475 rows in `employee_masters`. **`[TODO]` still open:** which is
authoritative, and who exists in both.

### 18.8 What 8,063 rows changed about the UI

**The Bills vendor picker was a `<select>` fed with every vendor.** Correct at one
fixture vendor; at 8,063 it ships the entire PII table — PANs, GST registrations, bank
details — into the browser to populate a control nobody can scroll. It is now a
server-searched combobox (`components/VendorPicker.jsx`) against
`/api/vendors/lookup`. Verified in a browser: the form DOM carries 36 `<option>`
elements, not 8,099.

Three rules the picker follows:

1. **Merged-away vendors are excluded from the picker and only from the picker.** A
   new bill must not be raised against a vendor Creator has merged away (§13A.1); the
   report still lists them, because history is history. The 5 nameless rows are also
   unpickable, leaving **7,946** of the 8,063 selectable. (A dev database reads 7,947 —
   `TestBillSeeder`'s fixture vendor is the extra one.)
2. **Every option shows its GST or PAN.** 62 names are ambiguous on their own, and
   picking the wrong `Hussain` puts a bill on the wrong vendor with nothing downstream
   noticing.
3. **The 30-result cap is stated on screen** — "Showing 30 of 214 matches". A silently
   truncated list looks like a complete one.

**Vendor Master is the first report that searches and pages server-side.** Settings
filters 135 rows in the browser, which is right at that size. Here it would mean
sending the whole table to filter three fields of it. 200 rows a page.

**Two nav keys, one report.** Creator has both `Vendor Master` and
`All Vendor Masters`, and no screenshot of either exists, so the difference is
unverified and is **not invented** — both keys render the same report and the screen
says so. The obvious candidate, hiding merged-away vendors, is offered as a visible
filter (`All` / `Not merged` / `Merged away`, with live counts) rather than applied
silently.

**Column order here is *verified*, unusually for this rebuild.** `Vendor_Master.csv` is
itself a report export, so its 21-column order is a report's column order — a stronger
basis than All Bills, whose order is taken from the form because §6.1's "which report
is live" is still open. All three `GST No.` headings render bare, as Creator has them;
the suffixes exist only because a JSON row cannot repeat a key.

**Two display-only affordances, flagged as deviations.** Both exist because HTML
renders the real data as indistinguishable from a fault, and neither touches a stored
value:

- edge whitespace in a name is drawn with visible markers, because
  `ETRADE MARKETING PRIVATE LIMITED ` and `ETRADE MARKETING PRIVATE LIMITED` are
  different vendors and HTML collapses that difference to nothing;
- the 5 nameless records render `(no name)`. They sort to the top, so without it the
  first thing the report shows is five empty rows.

**`Showing 200 of ###`** is not a bug — `showing()` reproduces Creator's own
above-1,000 behaviour, and vendors is the first report large enough to trigger it.

### 18.9 A race the browser found

Because searching and filtering are both server-side, two requests are routinely in
flight at once. Nothing ordered the responses, so a slow earlier reply could land
after a fast later one and repaint the grid with the previous query's rows.

Reproduced by clearing a search and immediately clicking a filter: the grid showed
**5 rows — the intersection of the two queries — and then sat there looking
authoritative.** Fixed with a monotonic request ticket checked on arrival; the grid now
dims while a request is in flight rather than presenting stale rows as the answer. A
browser assertion covers it specifically.

### 18.10 No write path, and one thing that needs saying

**Add and edit are not offered on vendors.** §13A.1's merge *semantics* are settled;
the merge *action* is not — nothing establishes what Creator does to open bills,
payments and requests when two vendors are merged, and 112 records point at 93
targets. `+` renders disabled with that reason, per the §17 honest-chrome rule.

**This is the most sensitive read in the application** — PANs, GST registrations, phone
numbers and free-text bank account details, 8,063 rows of it — and `/api/vendors` is
not behind the §3.3 gate any more than the rest of the API is. Fine on localhost; it
is the strongest argument yet for wiring authorisation before this is exposed anywhere
else. The CSV itself should not be committed carelessly, for the same reason.

### 18.11 Still open after this

- **Which of Creator's two vendor reports** the export came from, and what the other
  one shows. Needs a screenshot.
- **The merge action** — what it does to open bills, payments and requests.
- **What the three `GST No.` fields are.** Needs a form-level export.
- **Whether `employee_designations` is these 25 values.** Needs its own export.
- **Vendor vs employee register authority** — spec §13A.1's `[TODO]`, untouched.
- The `Account_Details` grid, `Secondary` (list), `Books ID`, `Vendor Ledger`,
  `Documents`, `Remarks`, `UPI ID`, and the PF/PT/ESIC flags: **all in spec §13A, and
  none of them in this report export.** The vendor table is seeded, not complete.
