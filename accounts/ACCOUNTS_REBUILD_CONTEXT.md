# Ekostay Accounts Rebuild — Build Context

**Living document. Supersedes `ACCOUNTS_REBUILD_SESSION_01.md`.**
Last updated 2026-08-08 · Sources: `Accounts.ds`, `Admin.ds`, `F_B.ds` (exported 08-Aug-2026),
live-UI screenshots, and a walkthrough with Husain.

Covers: master data · Bills · Payments · approvals · Schedule Payments · Salary Payouts ·
Expense Observations · the Accounts↔F&B contract · a register of 48 defects not to reproduce.

---

## 0. How to read this

| Tag | Meaning |
|---|---|
| `[DS]` | Read directly out of a Deluge Script export. High confidence. |
| `[UI]` | Observed in a screenshot of the live app. |
| `[USER]` | Stated by Husain. |
| `[VERIFIED]` | Ported into a prototype and checked against live figures. |
| `[INFER]` | Hypothesis from reading code. **Verify before relying on it.** |
| `[TODO]` | Unknown. **Do not guess. Ask.** |

**Hard rule:** where you find `[TODO]`, stop and ask. Several concern money movement,
record deletion, and statutory compliance.

Follow `karpathy-guidelines`: state assumptions, simplest thing that works, surgical
changes, verifiable success criteria per step.

**Browser-side validation is not a security boundary.** Every rule in the prototypes must
be re-implemented server-side. The split-ties-to-gross rule especially — Payments has no
such check today (§7.4).

---

## 1. What is being built

Ekostay runs 150+ villa rentals across Maharashtra, Goa, Tamil Nadu, Karnataka and
Uttarakhand. Accounting runs on Zoho Creator and has hit platform limits. `[USER]`

We are rebuilding the **Accounts** application outside Creator. UI designed separately and
handed over; functionality built from this document.

### 1.1 Target stack

`[USER]` "Same code structure as the expense tracker." Taken to mean Laravel 11, PHP 8.2+,
PostgreSQL 15+, config-driven exclusion lists, synced-vs-user-generated table separation,
snake_case, record IDs as opaque strings end to end.

**As actually installed, 22-Aug-2026 — Laravel 12, not 11.** Laravel 11 ended at v11.56.0
and is past security support: every 11.x release is blocked by Composer's audit over
unpatched advisories, including two reflected-XSS issues. Shipping a finance application on
that line, or disabling the audit to force it, was the worse trade. Laravel 12 is the
current maintained line and needs no exemption.

| Component | Version | Notes |
|---|---|---|
| PHP | 8.4.24 | spec asked 8.2+; winget's 8.3 manifest 404s on an archived php.net URL |
| Laravel | 12.67.0 | spec inferred 11; see above |
| PostgreSQL | 17.11 | spec asked 15+ |
| Composer | 2.10.2 | not in winget; installed from getcomposer.org, sha384 verified |

Database `ekostay_accounts`, owner role `ekostay`, created `ENCODING UTF8` with
**`LC_COLLATE 'C'`** — byte-order sorting, so the trailing-space and mixed-case master keys
sort deterministically instead of by locale rules. `php.ini` sets `precision = 17` and
`serialize_precision = -1` so an 18-digit record ID that reaches a string cast prints in
full rather than in scientific notation.

The "Laravel 11" figure above is left as written because it records what was inferred at the
time; this block is what is installed.

`[USER]` **RESOLVED 22-Aug-2026 — new standalone app.** Accounts gets its own Laravel
codebase, at `projects/ekostay-accounts/`. "Same code structure as the expense tracker"
therefore means *same conventions*, not the same repository. This satisfies §17 step 1
for the repo half of the decision; migrations may proceed.

Consistent with §2.1: Accounts, Admin and the F&B reference tables share **one schema**
inside this app, which is why extending a separate expense-tracker codebase was the
weaker option.

`[TODO]` **§2.1 remains open** — whether the rebuild ultimately replaces the whole
Creator cluster or keeps calling the surviving apps over API. It does **not** block
§17 step 2: that step names its tables explicitly (states → vendors), so the master
layer is ours to own either way. It *does* still block any F&B write path, per §17.

---

## 2. The application landscape

Seven Creator apps. `[UI]`

| App | Link name | Role |
|---|---|---|
| Accounts | `accounts` | **Being rebuilt.** DS in hand — 59,063 lines, 46 forms, 50 reports, 284 form workflows, ~22 schedules, 33 custom actions, 20 standalone functions, 4 connections |
| Admin | `admin` | Master data. DS in hand — 4,162 lines, 10 forms, 11 reports, 25 workflows |
| F&B | `fb` | Owns the booking master. DS in hand — 21,994 lines, 21 forms |
| Villa Operations Management | ? | `[TODO]` |
| Villa Operation | `villa_operation` | Referenced by Admin for access provisioning |
| Ekostay Revenue Share | `ers` | Referenced by Admin; overlaps the `Eko_RS_*` forms inside Accounts |
| Dood System Development | ? | `[TODO]` |

### 2.1 The dependency mesh — read before designing services

`[DS]` The apps call each other **bidirectionally, by direct function reference**:

```
accounts  ──►  admin            Villa, Location, Employee_Master, Head_Office, State, CA_Master
admin     ──►  accounts         Item_Category, Master_Category, COA, PortalAccess, CAAccess
accounts  ──►  fb               Booking, Expenses, Vendor_Order_Booking, 8× FB.* functions
fb        ──►  accounts         Vendor_Master, Item_Category, Billing_Cycles, Tax, Payment
fb        ──►  admin            Villa, Location, State, Employee_Master
admin     ──►  fb               fb.Access.AssignAccess
admin     ──►  villa_operation, ers
```

**Consequence:** no clean seam. Accounts, Admin and the F&B reference tables must be **one
schema**. You cannot ship Accounts against a stubbed Admin, and F&B is not a future concern
— Bills has an F&B lookup on it today (§12.1).

`[TODO]` Does the rebuild replace this whole cluster, or keep calling the remaining Creator
apps over API? Scoping decision, not implementation detail.

---

## 3. Master data — the `admin` app

All `→` denote lookups by record ID. `[DS]`

### 3.1 Villa (~40 fields, the central entity)

**Identity** — `Villa_Name`, `Location→`, `State→`, `Head_Office→`, `Ekostay_ID`, `Haewaya_ID`, `Date_field`, `Documents`

**Physical** — `Max_Occ_member`, `BHK` (**text**: `5BHK`, `6.5BHK`), `Bathroom`, `Category {Gold, Luxery, Original}` — `Luxery` misspelled in source; preserve on import, normalise at display

**People** — `Caretaker_Name/Number`, `Manager_Name→Employee_Master[User_Role=="Manager"]`, `Manager_Number`, `Villa_Managers` grid `{Primary, Manager→, Phone, Email}`, `Owner_Name/Number`, `Owner_Details` grid `{Name, Number, Email}`

**Commercial** — `Rent_Type`, `Expense_Base_Amount`, `GST %`, `Revenue_split_for_Owner`, `Expenses_split_for_Owner`

**F&B commercial** — `F_B_Revenue_split_for_Owner`, `F_B_Expenses_split_for_Owner`, `F_B_Item_Category_to {Include|Exclude}`, `F_B_Master_Category→`, `F_B_Item_Category→`

**Category scoping, mechanism A** — `Item_Category_to {Include|Exclude}`, `Master_Category→accounts.Master_Category[ID != 292482000000124003]`, `Item_Category→`

**Category scoping, mechanism B** — `Type_field {Include|Exclude}`, `Include_Item_Category→`, `Exclude_Item_Category→`

**Hierarchy** — `Primary_Villa→Villa`, `Secondary_Villa→Villa` (list)

**Flags** — `Active`, `Status {Active, In Active}`, `Hide_From_Payments`, `Primary`, `Inner_Circle`

#### Blocking unknowns on Villa

`[TODO]` **`Rent_Type` has FOUR values, not two:** `{Revenue Split EKOSTAY, Expense Split
EKOSTAY, Revenue Share, Lease}`. Every handover document describes two. Accounts branches
only on `"Lease"` and `"Revenue Share"` — the EKOSTAY split types fall through unhandled.
**Live correctness bug.** Need counts per type and intended behaviour.

`[TODO]` **Owner splits are hidden for EKOSTAY types.** `OnInputRentTypeCE` `[DS]` shows the
GST / revenue-split / expense-split fields only when `Rent_Type == "Revenue Share"` exactly.
Where are splits stored for the two EKOSTAY types?

`[TODO]` **Which category-scoping mechanism is live** — A, B, both, F&B's third, or none?
The downstream expense tracker was building a *fourth*. Do not implement all of them.

`[TODO]` **Three overlapping active flags** — `Active` vs `Status` vs `Hide_From_Payments`.
Bills and Payments filter on `Hide_From_Payments == false` `[DS]`, so that one is load-bearing.

`[TODO]` **Villa hierarchy semantics.** `Primary_Villa`/`Secondary_Villa` self-reference is
undocumented and affects every per-villa aggregation. Note "Central" villas (Ooty Central,
Karjat Central, Panchgani Central, Lonavla Central, Head Office Central) `[UI]` are real
payment targets carrying STAFF FUEL and STAFF RENT & ACCOMODATION — so they behave as
location-level cost centres held as villa values.

### 3.2 Other Admin forms

```
State                 State, Ekostay_ID
Location              Location, Head_Office→, State→, Ekostay_ID, Active, Circle_IDs
Head_Office           Head_Office, Locations→(list), Circle_IDs
Employee_Designation  Designation
Employee_Department   Department, Designation→(list)
Employee_Master       Name, Employee_ID, Email, Department→, Designation→,
                      Status{Active,Inactive}, Ekostay_ID, Villas→, Location→,
                      User_Role(TEXT), Access_Given, Is_HR, DOB, Joining_Date, Phone, Address
CA_Master             Name*, Phone_No, Email*, Bank→accounts.COA[Account_Type=="bank"]
Property_Rent_Type    Rent_Type (text)   ← ORPHANED; Villa.Rent_Type is a hardcoded picklist
Admin_Settings        Billing_Cycle_Min_Year, Billing_Cycle_Max_Year
```

`Admin_Settings` supplies the valid range for Billing Year fields. `[INFER]`

### 3.3 Identity and roles — `Employee_Master` is the IdP for the whole suite

`[DS]` `Access.Accounts(recID)` provisions cross-app portal access by string-matching a
free-text field:

```
"Account Team-Executive"                → accounts.PortalAccess
"Account Team-Senior" | "accounts head" → accounts.PortalAccess
"Food Operator"                         → fb.Access.AssignAccess + accounts.PortalAccess
"Property Manager"                      → villa_operation.Access.Access + accounts.PortalAccess
"Market Head"                           → villa_operation.Access.Access + accounts.PortalAccess
"Central Operations"                    → villa_operation.Access.Access + accounts.PortalAccess
"Human Resources"                       → accounts.PortalAccess
```

On `Status != "Active"` the mirror-image `DeleteAccess` calls run. CA users provision
separately via `accounts.CAAccess.Payments(bank, email)`.

**Known roles:** Account Team-Executive, Account Team-Senior, accounts head, Food Operator,
Property Manager, Market Head, Central Operations, Human Resources, Manager, CA.

**Redesign this.** `User_Role` is unconstrained text matched with `.contains()`, with
`"accounts head"` as a lowercase special case. Must become a roles table with an FK.
Highest-value structural fix in the master layer.

`[TODO]` Full role→permission matrix per module. `[UI]` confirms Edit/Delete/More are
conditionally rendered; the conditions are not in the DS. Note there are **role-scoped
reports** — `CA_Payments`, `View_Payments`, `All_Payments_Hussain`, `CA_Expenses`
("LLP Expenses"), `CA_Bills`, `CA_Bank_Transactions` — which is the permission model
expressed as separate views.

---

## 4. Master data — inside `accounts`

```
COA               Account_Name, Account_Type(text), Account_Code, Account_ID, Bank(bool),
                  Hide(bool), CA_Name→admin.CA_Master, Ekostay_ID
Master_Category   Master_Category, F_B(bool), Haewaya_ID
Item_Category     Item_Category, Master_Category→, Vendor_Name→(list), COA→,
                  Bank_Name→COA[Bank==true], Expense_Type{Direct,Indirect},
                  Exclude_for_Profit(bool), Exclude_for_Observation(bool), Variance(%),
                  Haewaya_ID, Exclude_Item_Category(bool), Disable(bool)
Billing_Cycles    Month_field(full English month name), Year_field(TEXT), MonthIndex
Tax               Tax_Name, Tax_Type, Tax_Precentage, Tax_ID
TDS               TDS_Name, TDS_Precentage, Books_ID, Status{Active,Expired}
Vendor_Master     Vendor Name, Location, State, Phone, UPI ID, PAN No., Source,
                  Primary(bool), Main_Primary, Master_Category→, Item_Category→ [UI]
Auto_Numbers      Payment_Series/No, Books_Payment_Series/No,
                  Haewaya_Series/No, External_Payment_Series/No
```

### 4.1 COA is a real chart of accounts

`[DS]` The downstream expense tracker modelled `coa` as a `VARCHAR(50)` enum of
`{Expense, Accounts Payable, Security Deposit, Payment Reverse}`. Those are **`Account_Name`
values**, not the domain. COA is a typed account master with `Account_Type` including
`"bank"` and `"other_asset"`, an `Account_Code`, and a CA assignment. Approval routing
branches on `Account_Type` (§8.1). **Model as an entity; do not reproduce the enum.**

`[UI]` Bank accounts include **individuals' petty-cash floats** — `Petty Cash Gufran`,
`Aliakber`, `Staff Loan` alongside `EKOSTAY LLP 1`. So `COA[Bank == true]` mixes company
accounts with personal floats. Naming has also drifted: `Haewaya EKOSTAY Hospitality`,
`Haewaya EKOSTAY LLP`, `EKOSTAY HOSPITALITY LLP`, `EKOSTAY LLP 1` — at least two look like
the same entity.

### 4.2 The F&B split is a boolean, not a string

`[DS]` `Master_Category.F_B` is a checkbox, and F&B scoping is
`accounts.Item_Category[Master_Category.F_B == true]`. The expense tracker filters on the
string `master_category == 'F&B'`, which is why BAKERY and KIRANA kept leaking. **Use the flag.**

### 4.3 Item_Category already carries the classification flags

`Exclude_for_Profit`, `Exclude_for_Observation`, `Expense_Type {Direct,Indirect}`, `Variance`.
The expense tracker was building a parallel Settings table for the same concepts. **This
master is the single source of truth.**

`[TODO]` Does `Expense_Type {Direct, Indirect}` correspond to the tracker's capex/opex
classification, or is it a separate axis?

### 4.4 Four parallel numbering series

`Auto_Numbers` holds `Payment_Series`, `Books_Payment_Series`, `Haewaya_Series`,
`External_Payment_Series`. `[UI]` confirms all four appear in live data:
`EKS/PY/20796`, `EKS/Haewaya/31579`, `REFUND-stay-313855`, plus `EKS/API/…` from prior notes.

**Rebuild requirement:** one series, origin as a separate field.

---

## 5. The core data flow — read this before anything else

`[DS]` This is the spine of the whole application and it was not documented anywhere.

```
Bills ──Create Payment──► Payment ──Split_Payments──► Expenses_Bills
  │                          │                              │
  │                          └── Bill_Payments (allocation) │
  │                                                          │
  └────────── also generates Expenses_Bills directly ───────┘
```

**An `Expenses_Bills` row is one `Split_Payments` leg, materialised.** `[DS]`

```deluge
for each rec in Split_Payments:
    insert into Expenses_Bills [
        Type_field   = "Bill" | "Expense"
        Bill         = fetBill.ID
        Villa_Name   = rec.Villa_Name
        Item_Category= rec.Item_Category
        Master_Category = rec.Item_Category.Master_Category
        Billing_Cycle= rec.Billing_Cycle
        Gross_Amount = rec.Amount
        TDS_Amount   = rec.TDS_Amount
        GST_Amount   = rec.GST_Amount
        Amount       = rec.Total_Amount
        Location, Head_Office, Vendor_Name, Bill_No, Bill_Date, Due_Date, COA, CA_Email
                     ← copied down from the Bill/Payment header
        Bill_Available = true
    ]
```

`[UI]` Confirmed in the live Expenses list: `EKS/PY/20799` appears twice for Jungle Beach
12 BHK at ₹41,317.50 each — one PAINTING WORKS, one HARDWARE MATERIAL. Same payment, two
legs, two expense rows.

### 5.1 The bifurcation is a cross-product `[DS]` `[USER]`

Three workflows on Bills — `OnInputVillasCE`, `OnInputBillingCycleCE`, `OnInputCategoryCE`
— each do the identical thing: `input.Split_Payment.clear()` then rebuild **every villa ×
billing cycle × item category combination**. Degrades in three tiers:

```
villas + cycles + categories → villa × cycle × category
villas + cycles, no category → villa × cycle
villas only                  → one row per villa
```

Bills are a **fourth dimension**: the expense generator loops bills on the outside and split
rows inside, so N bills × M splits → N×M expense rows.

Also `input.Location = input.Villas.Location` — **Location derives from the villas** on Bills
and Payment. Note `Schedule_Payment` does the opposite (`admin.Villa[Location.ID == input.Location]`).

`[TODO]` One of those two directions is wrong. Which?

**Rebuild requirement — do not clear and rebuild.** Clearing destroys typed amounts. Reconcile
instead: surviving combinations keep their amounts, new ones arrive blank, and a combination
that no longer applies is dropped only if empty — if it carries money it is kept, flagged, and
blocks save. See §15.

### 5.2 Consequence

**`Expenses_Bills` is the flattened ledger** — the fact table the downstream expense-control
tool syncs. Every villa-month-category figure in that tool traces to these rows, which makes
the split grid the only place attribution is decided. That is why the missing balance check on
Payments (§7.4) matters more than it looks.

---

## 6. Bills module

### 6.1 Screens `[UI]`

**List** — inline-editable grid, Save Changes / Remove Changes, search chips, row checkboxes,
per-row **Create Payment** button (greyed once a payment exists `[INFER]`). Columns: Added
Time · Create Payment · Vendor Name · Bill No · Status · Bill Date · Item Category · Villas ·
Location · Billing Cycles · ID.

**Detail** — Overview block, then each lookup expanded inline, then Add a comment. Actions
Edit / Delete / More→Print, role-gated `[USER]`.

`[TODO]` Two reports both display as "All Bills" (`All_Bills`, `All_Bills1`) with different
column sets. Which is live?

### 6.2 Form fields `[DS]`

Mandatory per `[UI]`: Bill Date, Vendor Name, Item Category, COA, Billing Cycles, Bill No, Villas.

```
Bill_Date            date
Vendor_Name          → Vendor_Master
Due_Date             date
Item_Category        → Item_Category[ Item_Category != "PETTY"
                                   && Item_Category != "INTERNAL TRANSFER"
                                   && Master_Category.ID != 292482000000124003 ]  (list)
Master_Category      → (list) — derived from Item_Category [USER]
COA                  → COA, defaults "Expense" [UI]
CA_Email             email
GST_Needed           checkbox
Billing_Year         number      Billing_Months  list of full English month names
Billing_Cycles       → Billing_Cycles (list)
Bill_No              text
Villas               → admin.Villa[ Hide_From_Payments == false ]  (list)
Location             → admin.Location        Head_Office → admin.Head_Office
Booking_No           → fb.Booking[ Villa_Name.ID == input.Villas ]   ← CROSS-APP §12.1
Status               {Draft, Paid, Partially Paid, Overdue, Payment Inprogress, Overpaid}
Amount ("Gross Amount") · GST_Amount · TDS→TDS[Status=="Active"] · TDS_Amount
Invoice_Amount · Paid_Amount · Payable_Amount · Adjusted_Amount · Split_Equally
Books_ID · Payments_Scheduled→ · Salary_Payout_Schedule→
```

**`Amount_Category` grid** — `Bill_For` · `Amount` · `GST→Tax` · `GST_Amount` · `Total_Amount`

**`Split_Payment` grid** — `Villa_Name→` · `Item_Category→` · `Billing_Cycle→` · `Amount` ·
`TDS_Amount` · `GST_Amount` · `Total_Amount` · `Backend_TDS_Amount` · `Backend_GST_Amount` ·
`Backend_Total_Amount` · `Percent` · `Partial_Paid`

`[TODO]` What is the `Backend_*` triplet for? Read instead of the normal columns when the bill
is `Partially Paid` (§7.2).

`[TODO]` `Amount_Category` / `Bill_For` versus `Split_Payment`. `[INFER]` Amount_Category =
invoice line items; Split_Payment = allocation across villa × category × cycle. Confirm.

### 6.3 Amount arithmetic — two formulas exist `[DS]`

**Bills** (`OnInputAdjustmentCE`):
```
Payable_Amount = (Amount + GST_Amount) − (TDS_Amount + Paid_Amount) + Adjusted_Amount
```
**Payment** (`OnInputGrossAmountCE`):
```
GST_Amount     = Amount × GST.Tax_Precentage / 100
Invoice_Amount = Amount + GST_Amount
Payable_Amount = Invoice_Amount − (Amount × TDS.TDS_Precentage / 100)
```

~~`[TODO]` **Which is authoritative for Bills?**~~ **ANSWERED 28-Aug-2026, and the question
was malformed.** There are not two competing formulas for one field — there are two
DIFFERENT FIELDS that share a name because they live on different objects:

| where | how it is set | what it means |
|---|---|---|
| `Bill.Payable_Amount` | `InvoiceAmount - ifnull(TDSTotal,0)` (`Accounts.ds:22490`, with `Paid_Amount = 0` set on the next line) | invoice net of TDS |
| `Payment.Payable_Amount` | `Invoice_Amount - (Amount × TDS%/100)` (the form handler above) | invoice net of TDS — **the same quantity** |
| `Bill_Payments[].Payable_Amount` | **clamped**, not computed: `Accounts.ds:28243-28259` caps it at `row.Bill_Amount` and at `balAmount`, alerting *"Payable Amount Can't be more than Bill Amount"* and *"Payable Amount is more than the Balance Amount"* | how much of THIS bill THIS payment pays |

So the header formula is consistent across Bill and Payment, and it is confirmed a third
time by the delete archive, which recomputes `Amount + GST_Amount - TDS_Amount`
(addendum §7F.3) — algebraically identical.

The "subtracts `Paid_Amount`" behaviour is not a formula at all: it is a **ceiling on a
user-entered allocation** in the `Bill_Payments` subform. Nothing computes
`invoice - paid`; the user types an amount and Creator refuses to let it exceed the
outstanding balance.

**What this means for the rebuild.** The header field is a derived total and should be
computed, never stored from input. The subform field is an input with a validated
ceiling. Note `Bill_Payments` is a different grid from `Split_Payments` — the first is
which bills a payment settles, the second is the villa × category × cycle allocation that
`SplitAllocator` owns — so this clamp does not touch the split arithmetic.

**`Split_Equally == true`** distributes with the **remainder on the last row**:
```
perAmt = Amount / rowCount ;  perGST = GST_Amount / rowCount
rows 1..n-1:  Amount = perAmt              GST_Amount = perGST
row n:        Amount = Amount − Σprev      GST_Amount = GST_Amount − Σprev
every row:    TDS_Amount   = row.Amount × tdsPct / 100
              Total_Amount = row.Amount + row.GST_Amount − row.TDS_Amount
```
Reproduce exactly. Do not substitute banker's rounding.

### 6.4 Validation `[DS]` — `OnInputValidationCE`, on validate

1. **Σ Split_Payment.Amount == Amount** at `round(0)`, else alert + `cancel submit`.
2. `GST_Needed == true` blocks any `Amount_Category` row on the zero-GST tax records
   (hardcoded IDs `292482000003927068`, `292482000000130718`). → In the rebuild resolve via
   `Tax.Tax_Precentage == 0`.
3. `IGST0` zeroes `Backend_GST_Amount` (and `Backend_TDS_Amount` when TDS is empty).
4. Further branch on `Paid_Amount == 0` — truncated in extract; **re-read source**.

**Billing cycle derivation** (`OnInputBillingMonthsCE`): `Billing_Months` requires
`Billing_Year` first; for each month, look up the cycle and **INSERT one if absent**.

> ⚠️ **Do not reproduce the auto-create.** This is the defect that previously created a junk
> billing cycle (`"9-2026"`) in live accounting. Validate against a fixed enum and require
> the cycle to exist, or gate creation behind an explicit admin action.

**Delete guard** (`OnDeleteValidate`):
```
if Status != "Draft" → alert, cancel delete
else                 → DELETE https://www.zohoapis.in/books/v3/bills/{Books_ID}
                       + delete from Expenses_Bills[Bill == ID]
```
Books `organization_id=60040119506` hardcoded → config.

### 6.5 Lifecycle

`[USER]` Fields become disabled once the bill reaches `Paid`.

`[TODO]` Scope of the lock — whole record or specific fields?

`[TODO]` Full transition rules. `[INFER]` `Overpaid` = `Paid_Amount > Payable_Amount`;
`Overdue` = past `Due_Date` and unpaid. Is `Status` ever user-editable or always derived?

---

## 7. Payments module

`[DS]` **~130 top-level fields and three subforms** — the largest object in the app.
`[UI]` **~40 appear on the form.** That is the live-vs-legacy split.

### 7.1 Clearly vestigial `[DS]` `[UI]`

`A`, `B`, `C`, `D` checkboxes plus eight `*_Updated_User_Name` / `*_Updated_User_Login`
fields · `Radio {Choice 1, Choice 2, Choice 3}` (an untouched Creator default) ·
`systemUpdating`, `staffLoanProcessed`, `Link_Updated`, `Bank_name_changed`, `Delete_Record`,
`Paid_Amount` (**a checkbox here, a currency field on Bills — same name, different type**).

Duplicate pairs: `Bill_No` (text) vs `Bill_No1` (lookup) · `Payment_Reference_Number`
(labelled "Haewaya UTR Number") vs `Payment_Reference_Number1` · `Location` vs
`Multi_Location` · `F_B_Payments` vs `F_B_Expenses`.

Six amount fields: `Original_Amount`, `Amount` (Gross), `Invoice_Amount`, `Payable_Amount`,
`bill_total_amount`, `Total_Split_Amount`. Six dates: `Requested_Date`,
`Backend_Payment_Date`, `Payment_Date`, `Due_Date`, `Timestamp_Date`, `Haewaya_TimeStamp`
(stored as **text**).

### 7.2 `Create_Payment` custom action `[DS]`

Per-record from the Bills report. Inserts one Payment:

```
COA            = COA[Account_Name == "Accounts Payable"]        (forced)
Payment_No     = Auto_Numbers.Payment_Series + "/" + zero-padded Payment_No
Status         = "Submit for Approval"      Payment_Status = "Open"
Accounts_Bills = true                       Added_User = zoho.loginuser
Requested_Date = today                      Bill_No1 = Bills.ID
carried over   : Vendor_Name, Due_Date, Billing_Cycles, Master_Category, Item_Category,
                 Location, Villa_Name(←Villas), Head_Office, Booking_No, TDS_Amount(←TDS)
Expense_By     = Bills.Added_User
then Bill_Payments = {Bill_No: Bills.ID, Bill_Amount: Bills.Payable_Amount}
     Split_Payments = clone of Bills.Split_Payment
     Auto_Numbers.Payment_No += 1
```

**Partially Paid uses the Backend columns, with inverted signs:**
```
if Bills.Status == "Partially Paid" && rec.Backend_Total_Amount != null:
    Total = Backend_Total_Amount ; tds = Backend_TDS_Amount ; gst = Backend_GST_Amount
    payable = Total − gst + tds          ← inverse of the normal path
else:
    Total = Total_Amount ; tds = TDS_Amount ; gst = GST_Amount ; payable = Amount
```
`[TODO]` Confirm the sign convention is intentional.

### 7.3 Status axes `[DS]` `[UI]`

```
Status         {Draft, Submit for Approval, Sent for Approval, Send for Approval,
                Approved, Approval Rejected, Approval Not Required, Paid}   init "Draft"
Payment_Status {Pending, paid, Cancelled, Reverse}
```
Both `"Sent for Approval"` and `"Send for Approval"` exist; `Payment_Status` has lowercase
`"paid"`. Treat as dirty enums — preserve on import, normalise in a mapping layer.

`[UI]` **`Payment_Status = "Open"` confirmed in live data and is not in the declared picklist.**
`Create_Payment` writes it.

### 7.4 ⚠️ Payments has no split balance check

Bills enforces `Σ Split_Payment.Amount == Amount`. **Payment does not.** Given §5.2 —
expense rows are the split legs — an unbalanced payment silently misstates every downstream
villa-month-category figure. **Add the check server-side.**

### 7.5 Other findings `[UI]`

- **The split grid's display format is wrong.** Detail and list show `Ooty Central July - 2026 ₹ 0.00` while the edit grid shows the same row carrying ₹7,500. It is concatenating a zero column (`backend_Amount` or one of PT/ESIC/PF) instead of `Amount`. `[TODO]` Confirm the stored value is populated: `select Amount from Split_Payments where Payment = <id>`.
- **Split grid entry model:** Villa · Billing Cycle · Item Category · **Gross Amount (editable)** · TDS (read-only) · GST (read-only) · Payable (read-only). Only gross is typed; the rest derive. Mirror this.
- `Gross Amount` renders at **three decimals** (`₹ 7,500.000`) while Payable on the same row shows two.
- Documents are in **S3** (`hywdocs.s3.ap-southeast-1.amazonaws.com/user_digital_docs/…`), not WorkDrive as the tracker docs assume. Two stores to handle.
- `Haewaya UTR Number` packs two values: `118103052206,15038`.
- **Statutory deduction fields (PT, ESIC, PF) render conditionally** — absent on a non-salary payment, present on the record. `[INFER]` gated on salary payouts. `[TODO]` confirm the trigger.
- **Three separate bill-document mechanisms** plus the allocation grid: `Bills doc` (single upload), `Supporting Documents` (single upload), `Bills` subform (repeating URL list), `Bill_Payments` (allocation, not documents).
- `COA` picker filters `COA[Hide == true]` — shows *only* accounts flagged "Hide", the inverse of Bills. Either the flag is misnamed or the condition is inverted.
- `Vendor_Name` filters `Vendor_Master[Main_Primary.Main_Primary is not null]`. `[UI]` Primary Vendor Name is empty for customer-refund payees and populated for trade vendors, so `Main_Primary` distinguishes the two.
- `OCR` is a native field type on the form — payment-screenshot reading is built in.
- Most records are created by a shared `ekostay` login, weakening audit attribution.

### 7.6 ⚠️ The More menu

`[UI]` `Duplicate Payment` · **`Delete Paid Payment`** · `Print`

Bills guards deletion behind `Status == "Draft"`. Payments ships an action whose name is
deleting a paid payment, one click away in a menu. Prior field notes record **17 real
payments (₹93,884) destroyed** because a delete path was treated as safe.

**Rebuild requirement:** no hard delete on a settled payment. Reverse it — a linked
reversing entry with negative amounts, a required reason, the original and its number intact.
Duplication produces a clearly-linked new draft, because a deleted-and-recreated payment gets
a **new number** and anything keyed on payment number drifts silently.

---

## 8. Approval engine

### 8.1 Routing `[DS]` — `UpdatePaymentStatus`

Short-circuits to `Approval Not Required` when any of:
1. `Bank_Name.Account_Type != "bank"`
2. `COA.Account_Type` is `"bank"` or `"other_asset"`
3. No row in the `Approval` matrix matches

Otherwise: delete existing `Pending_Approvals` for this payment, compute
`ApprovelAmount = Invoice_Amount` (falling back to `Σ Bill_Payments.Bill_Amount` when zero),
match the matrix, raise a `Pending_Approvals` record.

Matching uses `.toString().contains()` on multi-select Location / Villa / Item_Category.
**Reimplement as set membership on IDs.**

### 8.2 The `Approval` matrix `[DS]`

```
Module              {Payment}
Location            → admin.Location (list)
Villa_Name          → admin.Villa[Location.ID == input.Location] (list)
Type_field          {Include, Exclude}
Item_Category       → (list)      Exclude_Category → (list)
Level_1_2_Approval  {Any, All}    Level_2_3_Approval {Any, All}
Approvers grid:
    Level {Level 1, Level 2, Level 3}
    Minimum_Amount · Maximum_Amount
    Approver → admin.Employee_Master
    Approval_Type {Any, All}
```

A 3-level, amount-banded matrix scoped by location × villa × category. Blank Villa or blank
Category means **match all** `[DS]`.

### 8.3 Approve / Reject `[DS]`

`Approve` marks `Approved_By` rows where `Approver.Email == zoho.loginuserid`, then re-matches.

`[TODO]` `allApproved` is initialised `true` and set `true` again inside the loop — it can
never be `false`, so the "not all approved" guard is **dead code**. Determine the intended
Any/All semantics; do not port the dead branch.

`[TODO]` Do `Level_1_2_Approval` / `Level_2_3_Approval` gate sequential level progression or
are they independent?

### 8.4 Approval is recorded six ways `[UI]`

On a payment sitting at `Sent for Approval`: `Approver 1/2/3` **empty**, `Approved` false,
`Approved Persons` empty, `Messageid Level 2` populated but Level 1 empty — and the actual
approval recorded in the `Particulars` free text: *"rent and acc paid from llp1 approved by
zeeshan sir"*.

So the Approver fields look vestigial and real state lives in `Pending_Approvals` plus the
WhatsApp message IDs. **Collapse to one representation.** `[TODO]` which is authoritative?

### 8.5 ⚠️ Blueprints and Approvals may hold logic not in any file

`[UI]` The Creator Workflow tab has `Blueprints` and `Approvals` sub-tabs. **No blueprint or
approval-workflow blocks appear in `Accounts.ds`.**

~~`[TODO]` **Still unverified.**~~ **RESOLVED 22-Aug-2026 `[UI]` — both sub-tabs are EMPTY.**

Screenshots of the Creator builder's Workflow section show `Blueprints` and `Approvals` both
at their zero-state, offering only "Create Blueprint" / "Create Workflow". **Nothing is
configured in either.**

Consequences, all favourable:

1. **No Blueprint drives the payment status lifecycle.** §6.5 and §7.3 are complete as
   written, not partial. The state machine can be built from them.
2. **The approval engine is entirely hand-rolled Deluge** — `UpdatePaymentStatus` (§8.1), the
   `Approval` matrix with its Approvers grid (§8.2, addendum §11), and the separate
   amount-banded engine inside Backend Expenses (addendum §4). No platform approval process
   sits above them.
3. **Therefore all seven disagreeing representations of approval state (§8.4, addendum §1)
   are application-level.** None is a platform artefact, so all seven are ours to reconcile.
4. **The DS exports are complete for logic.** They declare exactly three workflow kinds and
   the counts match §2 exactly: `type = form` ×284, `type = schedule` ×21,
   `type = functions` ×33. No batch-workflow, report-workflow or payment-workflow blocks
   exist in the file, and with Blueprints and Approvals empty there is no known execution
   surface outside these three kinds.

`[TODO]` minor, and cheap: the same Workflow screen also carries **Payments**, **Batch
workflows** and **Report workflows** sub-tabs, which §8.5 never listed. No blocks of those
kinds appear in the DS either. Almost certainly also empty — but that is an inference from
absence, so a glance at those three tabs would close it properly.

---

## 9. Expenses module

### 9.1 Three different objects behind one nav item `[DS]`

| Report | Source | Fields |
|---|---|---|
| All Expenses | `Expenses_Bills` | 66 |
| LLP Expenses (`CA_Expenses`) | `Expenses_Bills`, filtered | 66 |
| All Backend Expenses | `Backend_Expenses` | **140** |
| All Expense Observations | `Expense_Observation` | 10 |

~~`[TODO]` Does anyone open Backend Expenses, or is it a sync landing table?~~
**ANSWERED 27-Aug-2026 — it is a landing table whose human-facing purpose has never
been exercised.** It is reachable (second in the nav rail), it carries a per-row
`Update` button, and Husain opened it to screenshot it. But the three fields a human
would set are untouched on every row seen: `Matched Payments` empty, `dup_checked`
false, `dup_key` empty, `cron_event_duplicate_bill` 0. `Payment` is populated, and
populated at ingest rather than by hand — rows created today already carry their
`EKS/Haewaya` number. So: **a sync landing table with a matching UI nobody uses.**
That supports §13B.2's recommendation to surface it inside Bank Reconciliation rather
than as its own page. Addendum §4.

### 9.2 One form serving both types, and no edit page

`[UI]` The form is titled **"Expenses & Bills"** with a `Type` radio `{Expense, Bill}`, and
`Type_field="Bill"` is set explicitly by the generator (§5).

`[USER]` **There is no edit page.** The list has a per-row **Update Expense** action instead.
`[UI]` Nearly every amount field renders greyed — Gross, Net Paid, GST, Amount, Bill Date,
Payment Date, Bill No, Expense By, Payment By, Particulars. Editable: Villa, Location, Head
Office, Bank, Billing Cycle, Vendor, Master/Item Category, Booking No, VOB No, COA, PT/ESIC/PF,
and **`Old Billing Cycles`**.

**So this is a re-classification tool, not an entry tool** — which is exactly what
`Update_Expense_Billing_cycle` does in the prior field notes.

### 9.3 ⚠️ Delete-and-regenerate

`[DS]` `Creator.CreateExpense` sets `Bill_Available = false` on existing rows, then the
generator runs, then:
```
delete from Expenses_Bills[Bill == fetBill.ID && Bill_Available == false]
```
A sweep keyed on a boolean. **If regeneration fails midway, rows are gone.** Rebuild as an
upsert on a stable key with soft-delete.

### 9.4 Data issues `[UI]`

- **`Particulars` is a packed string** — `{Category} - {note},{date},{date}`. Examples:
  `"F&B General Purchase - paid for light for kitchen,2026-08-08,2026-08-08"`,
  `"Carpentry - khila,,"` (empty trailing fields), `"Bakery - paid,2026-08-08,2026-08-10"`
  (two *different* dates). **Parse into columns at ingest.**
- **One row contains a customer's full bank account in plaintext** in `Particulars`: account
  number, holder name, IFSC, account type. Structured fields with access control, not a
  description column.
- **Three columns holding the same number** — `Gross_Amount` = `Amount` = `Net_Paid_Amount`
  when GST and TDS are zero. Per the insert, `Gross_Amount = rec.Amount` and
  `Amount = rec.Total_Amount`; they diverge only when GST or TDS is non-zero.
- **Null vs zero inconsistent** — STAY REFUND rows leave TDS and GST blank; others show ₹0.00.
- **The CA's view filters by string matching** — `Bank_Name.Account_Name contains "LLP" |
  "Petty" | "Haewaya"`. `Renu Sethi Kotak Mahindra (7839)` matches none, so it silently
  vanishes from LLP Expenses. Any new account named without those tokens disappears the same way.

---

## 10. Schedule Payments module

Three sections under one nav item `[UI]`: **All Schedule Payments** (813 records),
**All Scheduled Payments**, **Salary Payouts Report**.

### 10.1 Two objects `[DS]`

**`Schedule_Payment`** — the template: Location, Villa_Name (list), Start_Date, End_Date,
Due_Date, Payment_Date, Amount, TDS→, GST→, Total_Amount, Vendor→, Item_Category→,
Master_Category→, **Payment_Type {Payment, Bill & Payment}**, COA→`COA[Hide==true]`,
Bank_Name→`COA[Bank==true]`, Status `{Due, Click to Proceed}`, and a `Payment_Schedule` grid.

**`Payments_Scheduled`** — one instalment: Date, Due_Date, Billing_Cycles→, Amount, GST→,
TDS→, Due_Amount, Total_Due, Remarks, Bills (upload), Excess_Amount, Status
`{Due, Click to Proceed, Paid, Draft, Submit for Approval, …}`, plus a payroll block:
Loan_deduction, Advance_deduction, Penalty, `No_Of_Days_Not_Worked`, Days_deduction, PF, PT, ESIC.

### 10.2 Instalments auto-generate monthly `[DS]` `[VERIFIED]`

Editing Start Date, End Date, Due Date or Payment Date walks the months from start to end and
inserts a row per month inside the window:

```
day 31 clamps to 30 in the 30-day months
Billing cycle = the month BEFORE the due date  (DueDate.addMonth(-1))
row = { Due_Date, Date_field, Billing_Cycles, Amount, Due_Amount }
```

`[UI]` Confirmed: 18 rows, 15-Jul-2026 → 15-Dec-2027, ₹15,000 each. Per-row ✕ and "+ Add New".

No frequency field, because it is always monthly.

### 10.3 Instalment arithmetic `[DS]` `[VERIFIED]`

```
daysInMonth   = days in the month BEFORE the due date
perDay        = Amount / daysInMonth
Days_deduction = perDay × (daysInMonth − input)
Due_Amount = Amount − Loan − Penalty − Advance − Days − PF − PT − ESIC + Excess
Total_Due  = Due_Amount + GST%·Due_Amount − TDS%·Due_Amount
```

**GST and TDS apply to `Due_Amount` here, after deductions** — unlike Bills and Payment,
which apply them to gross. Genuine inconsistency across three modules. `[TODO]` which is right?

**Validation:** `Due_Amount != Amount` requires `Remarks`. **Good rule — keep it.**

**`No_Of_Days_Not_Worked` is a misnomer, not a bug.** The label on both the list and the form
reads "Number of Days Worked", and the arithmetic subtracts the input from the month length —
so it correctly treats the input as days *worked*. **Rename the field; do not change the math.**

`Total_Due` only computes on specific field inputs, so it is null on most records. The
generator uses `Due_Amount`, not `Total_Due`. Treat `Total_Due` as unreliable.

### 10.4 Findings `[UI]`

- **`Schedule.Payment(instalment)` converts one instalment to a Payment**, or a Bill *and* a Payment per `Payment_Type`. The billing cycle is resolved through a 24-line if/else month-name chain (appears at least twice in the codebase) — replace with a month array.
- **All 813 schedules sit at "Click to Proceed", including ones overdue since June.** Meanwhile instalments in `All Scheduled Payments` do reach `Paid`. **So the parent status never advances and is effectively decorative; the child status is the real one.**
- One schedule runs to **31-Dec-2030** — four and a half years of instalments generated up front.
- **"Click to Proceed" is an instruction rendered as state.** `All_Scheduled_Payments` carries four custom actions — `Create Payment`, `Due`, `Click to Proceed`, `Paid` — three of which just set the status. `[TODO]` where do these render? Not visible in screenshots (every row was already Paid).
- The list is **grouped by due date with group-level checkboxes** → a bulk-action pattern.
- The `Schedule Payment` column displays the parent's **Payment_Type**, not its identity — third instance of the display-format bug.
- **Orphaned instalments exist** — rows Paid with amounts but blank vendor/villa/category, one with no parent link at all.
- **Caretakers are stored as vendors with the villa encoded in the name string** — `suman(amani ct)`, `Deepak (chestnut new ct)`, `Priyanka Dinkar Balkawade(vishal wife)`. An employee register inside `Vendor_Master`, with the relationship in free text, while `admin.Employee_Master` exists and is not used for this.
- **Editability is inverted between parent and child:** Advance / Penalty / Days are editable in the parent's grid but read-only on the child's own form.
- Payroll deductions now live in **five** places: `Payments_Scheduled`, `Payment`, `Expenses_Bills`, `Salary_Payout_Schedule`, `Salary_Payouts`.

---

## 11. Salary Payouts — the payroll engine

`[DS]` Two forms, `Salary_Payout_Schedule` and `Salary_Payouts`, with a **21-column Payouts
grid**. `[VERIFIED]` The whole chain reproduces Ahmed Accounts' live June and July rows to
the rupee.

### 11.1 Automatic split of Total Amount `[DS]` `[VERIFIED]`

```
basePay:  total ≤ 21,000   → 14,500          (fixed band)
          total ≤ 40,000   → 21,100          (fixed band)
          total > 40,000   → total × 55%

HRA:      total ≤ 31,650   → total − basePay
          else             → basePay × (metro ? 50% : 40%)
          metro = {Delhi, Mumbai, Head Office Central, Bengaluru, Kolkata,
                   Chennai, Hyderabad, Ahmedabad, Pune}

CC:       total > 21,000   → total − basePay − HRA,  else 0
```

`Make_Calculation {Automatic, Manual}`. `[USER]` In Manual the whole block is skipped and
Base Pay / HRA / CC become editable.

### 11.2 Per-payout computation `[DS]` `[VERIFIED]`

```
daysInMonth  from the BILLING CYCLE month, leap-aware (%400, or %4 and not %100)
Salary / Base / HRA / CC   all × daysWorked ÷ daysInMonth

Employee PF   = min(Base × 12%, 1800)                             if PF_Status = Yes
Employer PF   = min(Base × 12%, 1800) + min(Base, 15000) × 0.5% × 2
Employee ESIC = Base × 0.75%     if ESIC_Status = Yes AND Salary ≤ 21,000
Employer ESIC = Base × 3.25%     same gate

Payable = Salary − EmpPF − EmpESIC − PT − StaffAdvance − StaffLoan − Penalty
                 + OtherExpenses          ← ADDED, not deducted
          floored at zero
CTC     = Payable + EmpPF + EmpESIC + PT + EmployerPF + EmployerESIC
```

**Editable per payout row:** Create Payment, Billing Cycle, Payment Date, Days worked,
Penalty, Other Expenses. Everything else derives — including **Staff Advance and Staff Loan**,
which must therefore flow in from the STAFF LOAN / advance schedules in §10. `[TODO]` confirm
that link.

**`CTC` is misnamed** — it is built up from Payable, and employer contributions are added in.
Anyone reading a payslip will misinterpret it.

### 11.3 Professional tax `[DS]` `[VERIFIED]`

```
Karnataka      ≤25,000 → 0 · ≤41,999 → 150 · else 200 (300 in February)
Maharashtra    Male:   >10,000 & age<65 → 200 (300 Feb) · >7,500 & age<65 → 175
               Female: >25,000 & age<65 → 200 (300 Feb) · else 0
Tamil Nadu     half-year = Salary × 6 → 0 / 22.50 / 52.50 / 115 / 170.83 / 208.33
Kerala         half-year = Salary × 6 → 0 / 20 / 30 / 50 / 75 / 100 / 125 / 166.67 / 208.33
```

So **`Age` is the under-65 exemption** and **`Gender` is the Maharashtra women's threshold** —
both genuinely load-bearing. No branch for Goa or Uttarakhand; correct, since neither levies PT.
`[TODO]` confirm no staff are filed in other states.

### 11.4 ⚠️ Four statutory deviations, quantified `[VERIFIED]`

| Issue | Effect |
|---|---|
| **Negative HRA** | A total of 21,001–21,099 sets basic to the fixed 21,100 — above the total — so `HRA = total − basic` goes negative. At 21,050, HRA = **−50**. |
| **ESIC on Base Pay, not gross wages** | ₹20/month under-contributed per enrolled employee at a ₹15,000 total (₹108.75+₹471.25 vs ₹112.50+₹487.50). Compliance exposure across everyone enrolled. |
| **EDLI doubled** (`0.005 × 2`) | ₹72.50/month over-accrued per enrolled employee at ₹15,000. Statutory rate is 0.5%. |
| **PT on prorated salary** | A woman on ₹26,000 working 12 of 31 days drops to ₹10,064 and pays **₹0 instead of ₹200**. Any part-month can zero the liability. PT is assessed on monthly salary. |

**Rebuild requirement:** every rate, band, ceiling and basis as **versioned configuration
with history**, not constants — and record which rule was in force when a payslip was
computed. Prior field notes make the same point about reconciliation tolerances.

`[UI]` Also: `Sahil Accounts` is marked ESIC **Yes** at ₹24,750 base, above the ₹21,000
ceiling. The code gates on `Salary ≤ 21,000` so it computes to zero anyway — the flag is
misleading but harmless. `PF_Status` / `ESIC_Status` appear to be maintained by hand;
consider deriving them.

### 11.5 Payout rows do not recompute `[VERIFIED]`

Ahmed's June row carries HRA ₹2,100, implying a Total of ₹23,200 at the time; his header now
reads ₹25,000. So historical rows retain the terms in force when computed — correct for
payroll, but **there is no record of what the terms were**, only the stale row.

**Rebuild requirement:** make salary periods additive so a change opens a new period rather
than overwriting, and a historical payslip stays explicable.

---

## 12. The Accounts ↔ F&B contract

### 12.1 F&B owns the booking master `[DS]`

`fb.Booking` is not F&B-specific — it is the stay-booking record for the whole business:

```
Booking_No, Guest_Name, Phone_Number, Email, Checked_In_Date, Check_Out_Date, No_of_Days,
Villa_Name→admin.Villa, Location→, State→, Net_Stay_Tariff, Booking_Source, Commission,
Damage_Recovery, Status {Confirmed, Cancelled, Maintaince, Maintenance, Functionality},
Sales_Person→admin.Employee_Master, Food_Sales_Person→admin.Employee_Master,
No_of_Adults/Children/People, Guest_Proof, Net_Food_Tariff, Direct_Expense,
Indirect_Expense, Profit, Profit1(%), Order_Type {Standard, Deluxe, Chef, Chef Services},
Raw_Material_Status {Not Ordered, Ordered, Pending, Partially Processed, Processed},
Booking_Expense grid → accounts.Vendor_Master, accounts.Item_Category, accounts.Payment
```

This is the source of the downstream tracker's `bookings` table and of the Sales Incentive
module's `sale_name` / `food_sales_person` / `net_stay_tariff` / `net_food_tariff`.

**Bills consequence:** `Bills.Booking_No` resolves against `fb.Booking` filtered by the
selected Villas. The Bills form cannot be completed without read access to this.

`[DS]` **`Status` contains both `"Maintaince"` and `"Maintenance"`.** Real rows exist under
both; any filter matching only the correct spelling under-counts.

`[TODO]` Duplicate fields on `Booking` — which is live in each pair?
`Food_Sales_Person` (→Employee_Master) vs `Food_Sales_Person1` (→Chef_Master) ·
`Villa_Name` (lookup) vs `Villa_Name1` (text) · `Food_Booking` (text) vs `Food_Booking1` (url) ·
`Raw_Materials1` (richtext) vs `Raw_Materials` (grid)

### 12.2 `Vendor_Order_Booking` — the F&B purchase order `[DS]`

Mirrors Bills: `Vendor_Name→accounts.Vendor_Master[Master_Category.F_B == true]`,
`Vendor_Category→accounts.Item_Category[Master_Category.F_B == true]`, `Order_for
{Warehouse, Against Booking, Location}`, `Billing_year`, `Billing_Month`,
`Billing_Cycle→accounts.Billing_Cycles`, Amount → GST → Discount → Grand_Total → Adjusted →
Paid → Payable, `Status {Order Placed, Vendor Fulfilled, Order Received}`, `Payment_Status
{Paid, Unpaid, Payment Inprogress, Partially Paid, Overpaid}`, `Items_Ordered→`.

`Vendor_Order_Booking_Item`: `Item_Name→Item_Master`, `Item_Category→[F_B]`,
Ordered/Fulfilled/Received_Quantity, `UOM→`, Price, Amount, `GST→accounts.Tax`, GST_Amount,
Total_Amount, `Villa→admin.Villa`, `Raw_Material_Request→`.

**The F&B vendor purchase-to-pay cycle settles through Accounts.**

### 12.3 The interface — 12 `FB.*` functions, 8 called from Accounts `[DS]`

```
FB.VendorOrderBooking(recID) → { Payable_Amount, Paid_Amount, Booking_Date, Booking_No,
                                 Location, Item_Category[], Villa, Check_In, Check_Out,
                                 Billing_Cycle }
FB.VendorOrderBookingItems(recID, Cat) → { Amount, GST_Amount, Total_Amount }
FB.VendorOrderBookingItemsnew(recID, Cat)        [TODO] how does it differ?
FB.UpdateVendorBookingPaymentStatus(map)         ⚠️ §12.4
FB.AcountsExpense(map)      ← misspelled in source; superseded
FB.AccountsExpenseNew(map)  ← current generation
FB.DeleteExpense(recID) · FB.ExpenseVendorOrderBooking(recID, VendorID)
FB.Expense · FB.Itemcategory · FB.UpdateExpenserawmaterial · FB.UpdateVillafromExpense
```

`[TODO]` `AcountsExpense` and `AccountsExpenseNew` coexist, with the older call commented out
at one site. Which is authoritative? Is the migration finished?

### 12.4 ⚠️ Destructive side effect inside a status update `[DS]`

```deluge
fetVendorOrder = Vendor_Order_Booking[ID == dataMap.get("ID")];
if(dataMap.get("Paid_Amount")    != null) fetVendorOrder.Paid_Amount    = ...;
if(dataMap.get("Payable_Amount") != null) fetVendorOrder.Payable_Amount = ...;
if(dataMap.get("Payment_No")     != null)
{
    delete from Expenses[Payment_No1 == dataMap.get("Payment_No")];   // ← HARD DELETE
}
fetVendorOrder.Payment_Status = dataMap.get("Status");
```

A function named "update payment status" performs an **unbounded hard delete of F&B Expense
rows**, keyed on a payment number that is documented as unstable across delete/recreate.

**Rebuild requirements:** separate the status update from any deletion; soft-delete only with
an audit row; never key a destructive operation on an unstable value.

`[TODO]` Confirm with Husain whether this delete is intentional.

---

## 13. Expense Observations

`[DS]` A **monthly job** (`Schedule_Observation`) that groups every *paid* expense row in the
current billing cycle by villa and writes one record per villa with the total net paid:

```
for each distinct villa in Expenses_Bills[cycle = current AND Payment != null]:
    total = Σ Net_Paid_Amount
    insert Expense_Observation { Location, Villa_Name, Head_Office, Amount, Month_Year }
```

Form: `Location→`, `Villa_Name→`, `Head_Office→`, `Amount`, **`Expense_Type {Low, High}`**,
`Month_Year→Billing_Cycles`, `Observation_Notes` (textarea), `Attachment`.

The report **groups by villa with subtotals** and colour-codes Amount — grey for Low, red for
High `[DS]`. `Observation Notes` is a per-row custom action (`OnAddObservationNotes`).

**So it is an automated monthly spend-per-villa snapshot for human triage** — the only
proactive review surface in the application. `[UI]` Every visible record has Expense Type and
Observation Notes blank, so nobody is triaging it.

### 13.1 ⚠️ Three bugs `[DS]`

**1. Variable shadows the column name.**
```deluge
Month_field = "January";
fetBillCycle = Billing_Cycles[Month_field == Month_field && Year_field == getYear];
```
Both sides are the same identifier, so the month condition is trivially true and it matches
**every cycle in the current year**. `fetBillCycle` becomes a collection and is then used as a
single value.

**2. No idempotency guard.** Nothing checks for an existing observation for the villa-cycle
before inserting. Re-runs duplicate. `[UI]` Casa Bella shows three rows — ₹2,12,700.10,
₹11,700.10, ₹2,14,700.10 — and there is a group of four rows with **no villa at all**.

**3. A collection assigned to single-value fields.** `sample = expRec.get(0)` is fetched and
never used; `Location=expRec.Location` and `Villa_Name=expRec.Villa_Name` assign collections.
That is why Villa Name is blank on several records while Location is populated.

Also `₹ 0.00` appears on a Panchgani row, which the job's own filter should make impossible.

### 13.2 Rebuild requirements

- One record per villa-cycle, unique key, **upsert not insert**
- **Derive Low/High** from that villa's own prior months rather than typing it — the
  downstream tracker's baseline logic already does this
- Fix the month resolution
- Frame as a **triage queue**: unreviewed first, sorted by variance, so the analyst works the
  outliers instead of scrolling a flat list

---

## 13A. Vendor Master

`[DS]` One form, two reports — "Vendor Master" and "All Vendor Masters" are the same object.
Four sections: an unnamed main section, **Employee Details**, the **Account Details** grid, and
**Merge Vendor**. Employee Details renders only when the `Employee` checkbox is ticked. `[UI]`

```
Section (main)
  Vendor_Name(text) · Location→admin.Location · State→admin.State · Phone_Number(phone)
  UPI_ID · Source{Manual,Haewaya} · Primary(bool) · GST_Needed(bool) · Employee(bool)
  Vendor_Category→Item_Category (LIST) · Master_Category→Master_Category
  Email · GST_No · PAN_No · Documents(file) · Remarks(textarea)

Employee Details
  Entity{Ekostay Hospitality, Ekostay LLP} · Employee_Designation→admin.Employee_Designation
  Gender{Male,Female} · Date_of_Birth · Blood_Group · Marital_Status{Single,Married}
  Emergency_Contact_Number · Father_name · Mother_name · Spouse_Name · Physically_Challenged
  Date_of_Joining · UAN · PAN · Aadhaar_Number · PF_Number
  PF_Joining_Date · EPS_Joining_Date · EPS_Exit_Date · ESI_Insurance_Number
  Current_Address(composite) · Same_as_Current_Address(bool) · Permanent_Address(composite)
  Bank_Account_Number · IFSC_Code · Bank_Name · Aadhaar_Enrollment_Number
  Account_Holder_Name · UPI_ID1 · PF(bool) · PT(bool) · ESIC(bool)

Account_Details (grid)
  Primary(bool) · Bank_Name · Account_No · Account_Holder_Name · Bank_Branch · IFSC_Code

Merge Vendor
  Primary_Vendor→Vendor_Master · Secondary→Vendor_Master(list) · Books_ID · Vendor_Ledger(url)
  Main_Primary→Vendor_Master

Section1
  Vendor_Key(text)   ← display name has trailing whitespace: "Vendor_Key        "
```

**Report columns** `[UI]`: Vendor Name · Main Primary · Primary Vendor · Primary Status ·
Location · Master Category · Employee Designation · Employee · State · Email · GST No. ·
Phone · Account Details · Added Time · Added User · ID · GST No. · GST No. · PAN No. ·
Modified User · Modified Time — **`GST No.` appears three times as a column.**

**Detail panel order** `[UI]`: Vendor Name, Location, Master Category, State, Email, Phone,
GST No., Secondary, Books ID, Vendor Category, Account Details, UPI ID, Documents, Remarks,
Vendor Ledger, Source.

### 13A.1 Findings

**Employee Details is a second employee register.** A full HR record — Aadhaar, UAN, PF
Number, EPS joining/exit dates, ESI Insurance Number, both addresses, next-of-kin, blood
group, marital status — living inside `Vendor_Master`, while `admin.Employee_Master` exists
separately (§3.2). `[UI]` This is the register your caretakers actually occupy, stored with the
villa encoded in the vendor name string (§10.4).

`[TODO]` Which is authoritative? A person can currently exist in both with different data.

**Four fields for one merge concept.** `Main_Primary` (picklist), `Primary_Vendor` (picklist),
`Secondary` (list), plus a `Primary` checkbox — all self-referencing Vendor_Master.
`[UI]` `Main_Primary` mirrors Vendor Name for trade vendors and is **empty for customers**
(`Poonam Tiwari(Customer)`, `Akshay Agarwal(Customer)`), so that one distinguishes trade
vendors from customer payees and is load-bearing. The other two are indistinguishable from
each other in the data seen.

`[TODO]` Collapse to one relationship. Which of the three is real?

**PF / PT / ESIC checkboxes are here too** — a sixth location for statutory flags, alongside
`Salary_Payouts.PF_Status` / `ESIC_Status` (§11). The payroll engine currently reads the
Salary Payouts pair. `[TODO]` which should it read?

**Duplicate banking fields.** Employee Details carries flat `Bank_Account_Number`,
`IFSC_Code`, `Bank_Name`, `Account_Holder_Name`, `UPI_ID1` **and** the `Account_Details` grid
holds the same five concepts. Also `UPI_ID` in the main section and `UPI_ID1` in Employee
Details share the display label "UPI ID".

`[UI]` One PAN No. holds the literal string `"NA"`.

---

## 13B. Backend Expenses

`[DS]` **140 top-level fields, 136 of them declared `type = text`** — every amount, date and
boolean included. This is a landing table for a third-party payment provider's transaction
feed, not a designed form. `[UI]` The detail panel is in **form field order**, which turned out to be
**two alphabetical runs then a hand-added tail** — not one alphabetical list. The
hand-added tail holds every Creator-native field and all three live textarea copies;
see addendum §4.3, which corrects this and closes the duplicate-pair `[TODO]`.

**Provider identity, from field names** `[DS]`: `rzp_payout_id` (Razorpay), `rbl_trn_id` (RBL
Bank), `bbps_txn_ref_id` (Bharat BillPay), `pg_order_id`, `pg_payment_id`, `dyn_qr_ref_id`,
`webhook_resp`, plus `fk_hccc_id`, `fk_m_hccc_id`, `fk_order_id`, `fk_safe_id` — foreign keys
into the provider's schema.

**A second, parallel approval engine** `[DS]`: `lvl_one_amt/msg/name` through `lvl_four_*`,
`verify_by_lvl_one..four`, `verify_lvl_one..four`, `lvl_one_approve_msg/time` through four,
`lvl_verification_status`, `is_admin_approval_require`, `approve_status`, `approve_by`,
`approval_txt`. Entirely separate from the Approval matrix in §8.

**Nine `cron_event_*` flags** as a state machine: paid, captured, reversed_cr, reversed_dr,
bill_upload, bill_verified, admin_verified, duplicate_bill, api_charges.

**Links out**: `Payment` → one payment, `Matched_Payments` → a **list** of payments.
The `Payment` links are the **`EKS/Haewaya` series**, and the live series is ≥ 33501 while
`auto_numbers.haewaya_no` holds 33294 — addendum §4.10, which must be settled before any
write path touches that counter.
`[TODO]` Does one backend expense ever match several payments, or is the list vestigial?

**Report columns** `[UI]`, in order — **32 columns, order confirmed end to end
27-Aug-2026** (addendum §4.1): Update (button) · date · Payment · Added Time ·
receiver_details · dr_amount · fk_m_hccc_id · multipe_hccc_names · **multipe_hccc_names** (a second, blank copy — verified 27-Aug-2026, addendum §4.1) · remark_cat_name ·
receiver_name · tai_vendor_name · receiver_upi_id · fk_hccc_id · time_stamp_date · tr_utr ·
bbps_txn_ref_id · approve_status · balance · assets_path · circle_code · remark_icon ·
remark_icon_id · bank_payout_details · bill_upload · cb_amount · ID · tr_status ·
lvl_verification_status · transaction_id · tai_invoice_number · tr_location

### 13B.1 Findings from live values `[UI]`

**The source system is written in Go.** `duplicate_date` reads `0001-01-01T00:00:00Z` — Go's
zero-value `time.Time` serialised as a real date. **Map to null on import** or transactions
book in the year 1.

**`multipe_hccc_names` is a packed villa-location pair with inconsistent order:**
```
Central Office-Central Office
Ezra Villa-Goa            ← villa-location
General-Lonavala          ← ?-location
Goa-General               ← location-?
```
Splitting on `-` is unsafe regardless, because villa names contain hyphens
(`Ezra Villa- Anjuna`, `Casa Pino- Pilerne`). `[TODO]` what is the intended composition?

**`receiver_name` and `tai_vendor_name` disagree** — receiver `KAVITA SUPER MARKET` against
vendor `Gayatri sweet mart and baker`; receiver `Police Training Center Khandala Welfare Fund`
against `hp petrol pump`. One is the UPI payee, the other the entered vendor. **Neither
reconciles to `Vendor_Master`.**

**`receiver_details` holds a complete UPI intent URI including its cryptographic signature** —
`upi://pay?pa=…&sign=MEQCIAmF8dm…` — in a plain text field on a screen with no field-level
permissions.

**`assets_path` is a comma-separated list of S3 URLs**, up to six per row, where the first is
absolute and the remainder are relative paths.

**Every transaction carries GPS coordinates** — `lat` 18.9886107, `long` 72.8314067.

Smaller items: `lvl_one_amt` is 3500 on a ₹200 transaction, so the `lvl_*_amt` fields are
approval *thresholds* not amounts · `bill_upload` is the string `"true"`/`"false"` ·
`bank_payout_details` is the literal `"na"` · `remark_icon` filenames read `stafffuel_…` with
words run together · six fields named `olab`, `olar`, `olaret`, `olart`, `olas`, `olat` are
undecodable · three fields exist twice, once as `text` and again as `textarea`
(`multipe_hccc_names`/`…1`, `remark_txt`/`…1`, `receiver_details`/`…1`), with the typo
`multipe` preserved in both.

### 13B.2 Rebuild recommendation

**Do not build this as an editable form.**

- A **typed staging table** with an explicit import contract — real dates, real decimals, real
  booleans, `0001-01-01` → null, packed strings parsed at ingest
- **Read-only** except the three things humans set: `Payment`, `Matched_Payments`,
  `dup_checked`/`dup_key`
- Surfaced inside **Bank Reconciliation** rather than as its own page, since matching provider
  transactions to payments is its only purpose
- `receiver_details` and `receiver_upi_id` are payment credentials — restrict by role

---

## 14. Defect register — do not reproduce

### 14.1 From the prior integration's field notes
1. **Read-only status endpoints for every resource.** Their absence caused the destruction of 17 real payment records (₹93,884).
2. **Never return a success code with an empty result body.** Silent no-op is the worst failure mode.
3. **Honest HTTP status codes.** Errors as HTTP 200 defeat every standard client.
4. **A deletions feed or soft-delete flag.** Absence must never imply deletion.
5. **Provenance on every record** (`created_via: api|ui|import`) — prevents re-import loops.
6. **Atomic multi-split writes**, or reject the request.
7. **One numbering series**, origin as a separate field.
8. **Record IDs as opaque strings end to end.** 18-digit IDs were corrupted to scientific notation by a spreadsheet export.
9. **Validate enums; never auto-create master data** from a malformed value.
10. **Name every date unambiguously** and make the bucketing basis explicit on every report.
11. **Parse provider-packed strings at ingest**; size for the documented maximum.
12. **Reconciliation rates and tolerances as versioned data**, not constants.

### 14.2 Found in this work
13. Bills auto-creates Billing_Cycles records (§6.4).
14. `FB.UpdateVendorBookingPaymentStatus` hard-deletes Expenses (§12.4).
15. Payment number zero-padding is double-applied — the third `if` is not chained, so a series of 5 becomes `00005` (§7.2). **Do not silently "fix"** — existing numbers are referenced externally.
16. `Approve`'s `allApproved` guard is dead code (§8.3).
17. `Rent_Type` has 4 values; only 2 are handled anywhere (§3.1).
18. F&B filtering uses a string match where a boolean flag is authoritative (§4.2).
19. `Booking.Status` has both `Maintaince` and `Maintenance` (§12.1).
20. `Payment_Status = "Open"` is written but not declared in the picklist (§7.3).
21. Hardcoded Books `organization_id` and zero-GST tax record IDs (§6.4).
22. Ten near-identical `ScheduleMatchTransaction1..10` daily jobs — replace with one queued job.
23. **Payments has no split-equals-gross validation** (§7.4).
24. The split subform's display format shows a zero column instead of `Amount` (§7.5).
25. `Delete Paid Payment` sits one click away in a menu (§7.6).
26. `Duplicate Payment` churns payment numbers untraceably (§7.6).
27. `Paid_Amount` is a checkbox on Payment and a currency field on Bills (§7.1).
28. `COA` picker filters `Hide == true` on Payment and Schedule_Payment — inverse of Bills (§7.5).
29. Currency renders at three decimals in the Payments list (§7.5).
30. `Expenses_Bills` delete-and-regenerate sweep keyed on a boolean (§9.3).
31. `Particulars` is a packed string containing plaintext bank account details (§9.4).
32. The CA's expense view filters bank accounts by substring, silently hiding accounts (§9.4).
33. Schedule parent status never advances; all 813 stuck at "Click to Proceed" (§10.4).
34. GST and TDS are computed on gross in Bills/Payment but on `Due_Amount` in Schedule Payments (§10.3).
35. `No_Of_Days_Not_Worked` is a misnomer — rename, don't change the math (§10.3).
36. Negative HRA for totals of 21,001–21,099 (§11.4).
37. ESIC on Base Pay rather than gross wages; EDLI doubled; PT on prorated salary (§11.4).
38. Expense Observations: shadowed variable, no idempotency guard, collection-to-scalar assignment (§13.1).
39. Two employee registers — `Vendor_Master.Employee_Details` and `admin.Employee_Master` (§13A.1).
40. Four self-referencing fields for one vendor-merge concept (§13A.1).
41. Duplicate banking fields on Vendor Master: flat set plus the Account Details grid (§13A.1).
42. `GST No.` appears three times as a column on the Vendor Master report (§13A).
43. Backend Expenses: 136 fields declared `text`, including all amounts, dates and booleans (§13B).
44. Backend Expenses carries a second four-level approval engine, parallel to §8 (§13B).
45. Go zero-time `0001-01-01T00:00:00Z` stored as a real date (§13B.1).
46. `multipe_hccc_names` packs villa and location with inconsistent order, unsplittable (§13B.1).
47. `receiver_name` and `tai_vendor_name` disagree and neither reconciles to Vendor_Master (§13B.1).
48. A UPI intent URI with its cryptographic signature stored in an unrestricted text field (§13B.1).

### 14.3 Security
`[DS]` `Accounts.ds` line 22851 contains a **hardcoded DoubleTick API key** in
`widgetSendWhatsApp`. **Rotate it.** No credential in source in the rebuild — config/env only.
`Admin.ds` and `F_B.ds` are clean.

---

## 15. UI prototypes

Working React prototypes exist. They are **executable specification, not production code**:
real arithmetic, real validation, real state transitions, in-memory data only. No database,
API, auth, Books sync, F&B calls, bank matching, OCR or WhatsApp.

**The UI direction is replication, not redesign.** `[USER]` An earlier set of prototypes
reorganised the screens and renamed fields; that was rejected. Field labels, column order,
section names and control placement must match Creator exactly, at Creator's density. See
`UI_HANDOFF.md` for the full rules.

| File | Module | State |
|---|---|---|
| `BillsModule.jsx` | Bills | ✅ Creator replica, screenshot-verified |
| `VendorMasterModule.jsx` | Vendor Master | ✅ Creator replica, screenshot-verified |
| `BackendExpensesModule.jsx` | Backend Expenses | ✅ Creator replica, screenshot-verified |
| `PaymentsModule.jsx` | Payments | ⚠️ old redesign — **rebuild before use** |
| `SchedulePaymentsModule.jsx` | Schedule Payments | ⚠️ old redesign — **rebuild before use** |
| `SalaryPayoutsModule.jsx` | Salary Payouts | ⚠️ old redesign — **rebuild before use** |

The three marked ⚠️ have renamed labels, invented controls and the sparse layout that was
rejected. **Their arithmetic is sound and worth porting** — the payroll engine in particular
reproduces live figures to the rupee — but the UI needs redoing.

### 15.1 Behaviours worth porting exactly

**The reconcile pattern for split rows** (§5.1). Verified:
```
villa only → 1 row · +2 cycles → 2 · +2 categories → 4
type 10k/20k/30k/40k · add a 2nd villa → 8 rows, the 4 amounts intact, 4 new blank
remove the 1st villa → its 4 rows flagged, ₹1,00,000 still on screen, save blocked
```

**Split-equally with the remainder on the last row**, to the paisa (§6.3).

**Month clamping and prior-month billing** (§10.2). Verified: a 31st-of-month schedule gives
Feb 28, Apr 30, each billing the month before.

**The payroll engine** (§11), verified against live June and July payout rows, with all four
statutory deviations exposed as configuration.

### 15.2 Faults found by rendering, that static review missed

Worth repeating because they are the class of bug that survives code review:

- `Added Time` sorted alphabetically — stored as `"30-Jun-2026 17:45:03"`, so string sort put 30-Jun above 07-Aug
- `Payable Amount` went negative on paid and overpaid bills, and headline totals summed the negatives
- A split tally showed green "reconciled" on an empty form, because ₹0 of ₹0 balances
- Native date inputs rendered **mm/dd/yyyy** in an Indian accounting application

## 16. Open questions, by urgency

**Blocking the data model**
- §3.1 `Rent_Type` four values; where EKOSTAY splits are stored
- §3.1 Which category-scoping mechanism is live
- §3.1 Villa hierarchy semantics; how Central cost centres should be modelled
- §3.1 Which of the three active flags governs what
- §6.3 Which Payable formula is authoritative
- §10.3 Which GST/TDS basis is authoritative across the three modules
- §8.5 Whether Blueprints/Approvals contain the real status machine

**Blocking write paths**
- §12.4 Is the Expenses delete intentional
- §7.2 Partial-payment sign convention
- §7.6 / §15 Payment-number padding — fix or preserve
- §3.3 Full role→permission matrix

**Blocking specific modules**
- §6.2 `Backend_*` triplet purpose; `Amount_Category` vs `Split_Payment`
- §6.5 Scope of the Paid lock; status transition rules
- §7.5 Trigger for the conditional statutory fields
- §10.4 Where the four instalment actions render
- §11.2 Whether Staff Advance / Staff Loan flow from the loan schedules

**Later**
- §2 Purpose of Villa Operations Management, Villa Operation, Dood System Development
- §2 Revenue Share split across the `ers` app and the `Eko_RS_*` forms
- ~~§9.1 Whether Backend Expenses is human-facing~~ — answered 27-Aug-2026, §9.1
- §12.3 `AcountsExpense` vs `AccountsExpenseNew`
- §6.1 Which "All Bills" report is live

---

## 17. Suggested build order with verification criteria

Each step has a check. Do not proceed past a failing check.

```
1. Confirm stack + repo decision (§1.1)
   → verify: written answer recorded in this file before any migration exists

2. Master-layer migrations only:
   states, locations, head_offices, villas, employee_designations, employee_departments,
   employees, roles, permissions, ca_masters, coa_accounts, master_categories,
   item_categories, billing_cycles, taxes, tds_rates, vendors
   → verify: every FK resolves; villas.rent_type accepts all 4 values; a fixture per
     rent_type asserts no branch silently drops one

3. Roles + permissions as first-class tables, replacing User_Role text matching
   → verify: a test asserts each of the 10 known roles maps to an explicit permission set;
     no string .contains() anywhere in the authorisation path

4. Bills: schema + amount_category and split_payment child tables
   → verify: split-total-equals-gross rejects a mismatched payload; split-equally
     distributes with remainder on the last row, asserted to the paisa; the reconcile
     scenario in §15.1 passes as a test

5. Bills read API + list/detail endpoints
   → verify: amounts are JSON numbers not strings; dates are 'YYYY-MM-DD'; empty strings
     not null; response shape matches the prototype contract

6. Expenses_Bills as the ledger, generated from split legs (§5)
   → verify: one bill with 2 villas × 2 categories × 1 cycle produces exactly 4 rows whose
     Gross_Amount sums to the bill's gross; regeneration is an upsert, never a delete sweep

7. STOP before Payments, Schedule Payments and Salary Payouts.
   → verify: the "blocking write paths" questions in §16 are answered
```

Do **not** implement the approval engine, the Books push, or any F&B write path in the first
pass — they depend on §8.5 and §12.4.

For payroll specifically: the rates, bands, ceilings and bases in §11 must land as
**versioned configuration rows with effective dates**, and every computed payslip must record
which configuration version produced it. Without that, re-running a month silently re-decides
old payslips under new rules.

---

## 18. Provenance

Derived from Deluge Script exports generated 08-Aug-2026 — `Accounts.ds`, `Admin.ds`, `F_B.ds`
— plus live-UI screenshots of Bills, Payments, Expenses, Schedule Payments, All Scheduled
Payments, Salary Payouts and Expense Observations, and a walkthrough with Husain.

DS exports contain structure and script only, **no records**. Every claim about data volumes
or distributions comes from the prior integration's field notes or from row counts visible in
screenshots, not from these files.

Not covered by any file in hand: Creator Blueprints, Approval workflows, user/role
assignments, print templates, and the `villa_operation` / `ers` / Villa Operations Management /
Dood System Development applications.
