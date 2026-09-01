# F&B — what `F_B.ds` actually says

Read from `fnb/deluge/F_B.ds` (21,994 lines, generated 13-Aug-2026), 31-Aug-2026.
Every claim below is traceable to a line number in that file. Where it contradicts
`fnb/README.md` or the root `README.md`, this document is the one measured against the
source.

Follows `accounts/CLAUDE.md`'s precedence habit: the evidence-based reading wins over the
inferred summary.

---

## 1 · Structure: 21 forms, 21 reports, 292 fields

**Not 84 forms.** Both READMEs say 84; the DS declares **21**. Each form name appears
four times — once in the `forms` block, three more in the i18n dictionary — so a naive
`grep -c 'form '` quadruples it. `ACCOUNTS_REBUILD_CONTEXT.md` §18's "21 forms" was
right all along.

| Form | Fields | Line | |
|---|---|---|---|
| `Booking` | 52 | 195 | the stay booking, not a food order |
| `Expenses` | 41 | 941 | F&B's own expense rows |
| `Vendor_Order_Booking` | 34 | 3088 | purchase order to a vendor |
| `Raw_Material_Request` | 23 | 1966 | kitchen asks for stock |
| `Request_Stock_for_Food` | 23 | 2283 | per-dish stock request |
| `Vendor_Order_Booking_Item` | 16 | 3469 | order line items |
| `Transaction_Items` | 14 | 2755 | stock movement |
| `Auto_Numbers` | 12 | 15 | four series, F&B's own |
| `Chef_Master` | 10 | 777 | |
| `Vendor_Price_List` | 9 | 3653 | price per vendor per item |
| `Item_Master` | 8 | 1702 | |
| `Inventory` | 8 | 1504 | stock at a warehouse |
| `Monthly_Check` | 7 | 1809 | |
| `Inventory_Stock` | 6 | 1615 | dated stock rows |
| `Requirements_of_Recipe` | 6 | 2547 | |
| `Transfer_Items` | 6 | 2923 | warehouse → warehouse |
| `Warehouse` | 6 | 3798 | |
| `Food_Order_Details` | 5 | 1432 | |
| `Block_Booking_Date` | 2 | 149 | mirrors Accounts' Block_Payment_Date |
| `Recipe_Master` | 2 | 2238 | |
| `UOM` | 2 | 3040 | |

Parsed field lists are in `fnb/docs/_parsed/forms.json`.

## 2 · The shape of the domain

Stock and kitchen, in four chains:

```
Item_Master ──┬── UOM                     what a thing is, and in what unit
              └── Vendor_Price_List       what each vendor charges for it

Warehouse ──── Inventory ──── Inventory_Stock      where stock is, and how much
                    │
              Transfer_Items / Transaction_Items   how it moves

Raw_Material_Request  /  Request_Stock_for_Food    the kitchen asking
              └── Requirements_of_Recipe

Vendor_Order_Booking ──── Vendor_Order_Booking_Item      buying it
              └── Expenses                               and paying for it
```

`Item_Master` is the hub: `Inventory`, `Vendor_Price_List`,
`Vendor_Order_Booking_Item` and the request forms all pick from it. **Build it first.**

### `Booking` is a stay booking, not a food order

52 fields, the largest form here, and it is the **stay-booking master for the whole
business** — check-in/out, villa, guest. F&B hangs food orders off it. Worth stating
plainly because the name reads like a restaurant booking and it is not.

## 3 · The 47 cross-app calls, measured

Two kinds, and the distinction matters:

**Picklist sources — 26 reads.** These become `WHERE` clauses under §2.1's decision.

| Call | × |
|---|---|
| `admin.Villa.ID` | 7 |
| `admin.Location.ID` | 7 |
| `admin.State.ID` | 5 |
| `accounts.Vendor_Master.ID` | 5 |
| `accounts.Item_Category[Master_Category.F_B == true].ID` | 5 |
| `admin.Employee_Master.ID` | 4 |
| `accounts.Item_Category.ID` | 4 |
| `accounts.Billing_Cycles.ID` | 3 |
| `accounts.Payment.ID` · `accounts.Tax.ID` · `accounts.COA.ID` · `accounts.Expenses_Bills.ID` | 1 each |
| `accounts.COA[Bank == true].ID` | 1 |
| `accounts.Tax[Tax_Type == "tax_group"].ID` | 1 |
| `accounts.Vendor_Master[Vendor_Category.ID == input.Item_Category].ID` | 1 |

**Function calls into Accounts — 19.** These are not table reads; they are functions
living *in Accounts* that exist only to serve F&B:

| Function | × | Returns |
|---|---|---|
| `accounts.FB.Accounts(id)` | 8 | a bank / COA record by id |
| `accounts.FB.BillingCycle(month, year)` | 6 | the cycle for a month |
| `accounts.FB.ItemCatVendor(cat, location)` | 3 | vendor for a category at a location |
| `accounts.FB.Vendor(name)` | 1 | vendor by name |
| `accounts.FB.ItemCategoryVendor(list, loc)` | 1 | the list-taking form of the above |

Plus `admin.Villa.VillaData` ×3, `admin.Employee.GetData` ×2,
`admin.BillingYear.GetYear` ×1, `accounts.Creator.SendItemData` ×2.

**`ItemCatVendor` vs `ItemCategoryVendor` are NOT duplicates** — checked at
`Accounts.ds:21997` and `:22011`. Same body, different arity: one takes a single
`int ItemCategory`, the other a `list ItemCategoryList` and loops it. Both filter
`Vendor_Master[Vendor_Category.contains(x) && Location == y]` and return a distinct
list. **Implement one method that accepts an array** and the singular case is a
one-element call. TODO-FNB-3 closed.

## 4 · Findings that change what we build

### 4.1 The hardcoded COA ids are Zoho BOOKS ids, and both resolve — CORRECTED

**My first reading of this was wrong and is corrected here.** I said the two literals
addressed a different Zoho org because they are 19 digits starting `2508841` while every
Creator record id is 18 digits starting `2924820`. The digit counts were right; the
conclusion was not.

```
BankName = accounts.FB.Accounts(2508841000000218127);   // 4 sites
COA      = accounts.FB.Accounts(2508841000000161277);   // 4 sites
```

`FB.Accounts` in `Accounts.ds:21972` does **not** look up by record id:

```deluge
map FB.Accounts(int recID)
{
    fetCOA = COA[Account_ID == recID];      // Account_ID, not ID
    ReturnMap = {"ID":fetCOA.ID};
    return ReturnMap;
}
```

`COA.Account_ID` (`Accounts.ds:3480`, `type = number`, label "Account ID") is the
**Zoho Books account id**. We already hold it as `coa_accounts.books_account_id`, and
**all 125 populated values are 19 digits starting `2508841`** — the Books org prefix.
Both literals resolve:

| literal | resolves to | bank | type |
|---|---|---|---|
| `2508841000000218127` | **F&B Store Room Purchase** | false | `other_current_asset` |
| `2508841000000161277` | **Expense** | false | `expense` |

So this is not a broken reference. It is a **hardcoded default**: every F&B expense
created down this path is booked to F&B Store Room Purchase and Expense, whatever the
order was for.

**Two things still worth carrying, and neither needs Husain:**

1. **The field is named `Bank_Name` but the row is not a bank.** `F&B Store Room
   Purchase` has `bank = false` and type `other_current_asset`. Consistent with the
   Accounts finding that `Bank` is the load-bearing flag and `Account_Type` is not —
   here neither says "bank", and the field name is simply wrong. Do not validate
   `bank_name` against `bank = true`; it would reject live data.
2. **Resolve through `books_account_id`, not a Creator id**, and keep the two defaults
   in **configuration** rather than repeating the literal at four call sites. 19 of 144
   COA rows have no Books id at all, so the lookup must tolerate a miss.

**TODO-FNB-1 closed.**

### 4.2 311 of 322 hardcoded record ids are float-corrupted

`grep` for 16+ digit literals gives 322 distinct ids. **311 of them end in `000`.**

That is the exact corruption `accounts/CLAUDE.md` warns about — an 18-digit id through a
float loses its last three digits (`…361075` → `…361100`). Measured against real data:

| | ending `000` |
|---|---|
| real vendor ids | **3 of 8,161** |
| real villa ids | **0 of 254** |
| hardcoded ids in `F_B.ds` | **311 of 322** |

And `292482000006417000` — the most repeated, 68 times — **matches no vendor**, while
**4 vendors share its first 15 digits.** The id has been rounded and no longer identifies
anything.

**Where they are**, by function:

| Function | ids | |
|---|---|---|
| `FB.UpdateVillafromExpense` | **657** | never called — dead backfill |
| `RequestStock.RequestStockPendingRaw` | 7 | |
| `FB.UpdateExpenserawmaterial`, `pricechange`, `Temp`, `TempInStock` | 1 each | |

The concentration is the reassuring part: 657 of 668 occurrences sit in **one function
that nothing calls**. It is a one-off data fix someone ran once, like
`manualupdatebooks()` at line 4676 with its ~220 hardcoded order numbers — also never
called.

**Neither function gets ported.** They are not behaviour; they are somebody's Tuesday
afternoon. But they are worth recording, because they are evidence that **live Creator
code has been passing record ids through a numeric type** — which is a live data-integrity
issue independent of the rebuild.

### 4.3 A third spelling of "Payment InProgress"

`Vendor_Order_Booking.Payment_Status` declares:

```
{"Paid","Unpaid","Payment Inprogress","Partially Paid","Overpaid"}
```

`accounts/CLAUDE.md` already records `Payment InProgress` spelled **two** ways in
Accounts, so every status comparison misses part of the data. F&B adds
**`Payment Inprogress`** — lowercase `p` — making three variants across the cluster.

Preserved as-is per the no-normalising rule, and it needs a mapping table rather than a
`strtolower`, exactly as `multipe_hccc_names` does.

### 4.4 F&B owns four auto-number series, not three

Both READMEs list three. `Auto_Numbers` (line 15) declares **four**:

```
Booking_Series          Booking_No
Request_Series          Request_No
Vendor_Booking_Series   Vendor_Booking_No
Transfer_Series         Transfer_No      ← not in either README
```

Separate from Accounts' payment counter. Same table pattern, different rows — and
`accounts/CLAUDE.md` is emphatic that the payment counter must carry its real value
(20938) rather than restarting at 1. The same applies to each of these four.

### 4.5 The Vendor_Price_List filter is correct — resolved

It looked wrong. `Vendor_Price_List.Vendor_Name` filters
`accounts.Vendor_Master[Vendor_Category.ID == input.Item_Category]` — a field called
*Vendor*_Category matched against an *Item*_Category input.

**Checked in `Accounts.ds:11479`, and the field genuinely holds item categories:**

```
Vendor_Category    list        Item_Category.ID
Master_Category    picklist    Master_Category.ID
```

So `Vendor_Category` is a misleading name for "which item categories this vendor
supplies", and the filter does exactly what it should. Two different scoping mechanisms
coexist on one table: `Master_Category.F_B == true` for "is an F&B vendor at all", and
`Vendor_Category.contains(x)` for "supplies this item category".

Our `vendors` table already carries `item_category_id` and `master_category_id`,
so both are modellable today. **TODO-FNB-2 closed** — no question for Husain.

## 5 · What must not be reproduced

1. **`DeleteAllRecords()`** at line **4637** — 14 unguarded `delete from <table>[ID !=
   null]`. It **stays live** (user decision, 31-Aug-2026), so the only defence is ours:
   never write it, and guard deletes at the model layer as Accounts did (D4).
2. **`manualupdatebooks()`** (4676) and **`FB.UpdateVillafromExpense()`** (5938) — dead
   one-off backfills carrying ~220 hardcoded order numbers and 657 corrupted ids between
   them.

## 6 · Open questions

| | | Answerable by |
|---|---|---|
| ~~TODO-FNB-1~~ | **CLOSED** — they are Books ids and both resolve (§4.1) | answered from `Accounts.ds` + seeded data |
| ~~TODO-FNB-2~~ | **CLOSED** — `Vendor_Category` really does hold `Item_Category.ID` (§4.5) | answered from `Accounts.ds` |
| ~~TODO-FNB-3~~ | **CLOSED** — the two functions differ by arity, not purpose (§3) | answered from `Accounts.ds` |
| ~~TODO-FNB-4~~ | **MOSTLY CLOSED** — the detail view shows all 34 fields, so the 13 are hidden on *Add* only, not conditionally absent (§8.8) | detail screenshot |
| **TODO-FNB-7** | Which is authoritative for a string key — Creator's report export (`Pieces `, 7 chars) or the Analytics view (`Pieces`, 6)? Analytics TRIMS (§15.3) | Husain / Tushar |
| **TODO-FNB-6** | Should a recipe be able to name a FRUITS or BAKERY item? Creator's four hardcoded grids reach 335 of 370 items (§13.2) | Husain |
| ~~TODO-FNB-5~~ | **CLOSED** — the stored field really is empty; the export resolves villa through the booking (§9.5) | second detail view + Edit form |

**All three are closed, and all three were answerable from the source rather than by
asking.** Worth noting that TODO-FNB-1 was closed by *correcting* my own reading: the
19-digit ids looked like a foreign org and were Zoho Books ids we already store.

---

## 7 · Built so far — 31-Aug-2026

**7 tables, 5 models, 8 tests. Suite: 144 passed, 1,399 assertions.** (Was 136/1,008.)

Inside `accounts/` rather than a separate `fnb/` app directory, because §2.1 resolved to
one Laravel app and one schema. Tables carry an `fnb_` prefix — both apps have an
`expenses` and the names would otherwise collide.

| Table | Rows | Creator source |
|---|---|---|
| `fnb_uoms` | **9** | `fb.UOM` (3040) |
| `fnb_item_masters` | **370** | `fb.Item_Master` (1702) |
| `fnb_warehouses` | 0 | `fb.Warehouse` (3798) |
| `fnb_warehouse_locations` | 0 | its `Location` list field |
| `fnb_warehouse_villas` | 0 | its `Villa_Name` list field |
| `fnb_inventories` | 0 | `fb.Inventory` (1504) |
| `fnb_inventory_stocks` | 0 | `fb.Inventory_Stock` (1615) |

The four empty tables have no export yet — structure only, seeded when data arrives.

### What the seeding proved

**370 items, and all 370 resolve to both a category and a UOM.** Zero orphans, no
invented masters.

**`'Pieces '` kept its trailing space** — 7 characters, and **70 items join to it.** A
`trim()` on import would have orphaned all 70 and created a phantom `Pieces` alongside.
This is the `F&B STAFF MEDICAL EXPENSE ` hazard, in F&B, at 70× the blast radius.

**All 9 distinct item categories used are `master_categories.fb = true`** — so the join
reproduces Creator's `accounts.Item_Category[Master_Category.F_B == true]` picklist
exactly, without a cross-app call.

### Decisions worth knowing

- **Warehouse locations and villas are pivots, not columns.** Creator declares both as
  `type = list`. §12 measured that Analytics flattens multi-value fields to one
  silently-chosen value; a single column would bake that loss into the schema.
- **UOM is denormalised on three tables** — Item_Master, Inventory and Inventory_Stock
  each carry their own. Creator's design, reproduced rather than collapsed: a stock row
  recorded in a different unit from the item default would otherwise change meaning.
- **`Date_field` → `stock_date`.** The Creator name exists only because `Date` is
  reserved in Deluge; it is not a lookup key, so renaming costs nothing. Picklist
  *values* are never renamed.
- **Money and percentages are `decimal(16,4)`**, asserted against
  `information_schema` — not merely declared.
- **`variance` stores 5 for 5%**, undivided, as Creator does.

### One thing the constraints caught

My first version of `FnbMasterSeedTest` seeded `FnbMasterSeeder` on top of
`DatabaseSeeder`, which already includes it. Every row inserted twice, and
`fnb_item_masters_creator_id_unique` rejected it. The unique index on `creator_id` was
doing exactly what it is for — and it failed in the full suite while passing in
isolation, which is the shape of a double-seed rather than a defect.

---

## 8 · Vendor Order Booking — measured against 11,149 live orders

`master-data/All Vendor Order Bookings.csv`, exported 31-Aug-2026. 28 columns,
11,149 data rows. Git-ignored: it names vendors, villas and **guest stay dates**.

### 8.0 A parser bug of mine, and the 4 fields it hid

My first pass reported 292 fields. The real count is **296**. Mandatory fields are
declared `must have <Name>` rather than as a bare name, and my regex only matched the
bare form — so it silently dropped **every required field in the app**:

| Form | Field | |
|---|---|---|
| `Vendor_Order_Booking` | **`Vendor_Name`** | `accounts.Vendor_Master[Master_Category.F_B == true]` |
| `Vendor_Order_Booking` | **`Order_for`** | `{"Warehouse","Against Booking","Location"}` |
| `Raw_Material_Request` | **`Item_Name`** | `Item_Master.ID` |
| `Raw_Material_Request` | **`Requested_Quantity`** | decimal |

Those are the four most load-bearing fields in the two forms. Worth stating plainly:
the failure was silent, and the corrected numbers are `Vendor_Order_Booking` **36**
fields and `Raw_Material_Request` **25**.

### 8.1 `Order_for` is a discriminator, and the data is clean

`{"Warehouse","Against Booking","Location"}` decides which target field is populated:

| `Order for` | rows | villa | warehouse | location | booking |
|---|---|---|---|---|---|
| Against Booking | 10,484 | 9,902 | **0** | 10,484 | 10,474 |
| Location | 213 | **0** | **0** | 213 | **0** |
| Warehouse | 45 | **0** | 45 | 44 | **0** |
| *(blank)* | 407 | 0 | 0 | 0 | 0 |

Perfectly separated across 11,149 rows — no row carries a warehouse *and* a booking.
So the three modes are genuinely exclusive and worth a CHECK constraint rather than
a hopeful comment.

Two soft spots: 582 "Against Booking" rows have no villa, and 10 have no booking
number. Real data, not import error.

### 8.2 The 407 blank rows are malformed, not a mode

Every one has **no `Order_for`, no `Order No.`, no vendor and no amount.** One reads
`"green peas 5rs"` in the Particulars column with a username beside it — someone
typed a note into a record that was never completed.

They are 3.7% of the export. On import they must be **rejected and counted**, not
silently loaded as zero-value orders.

### 8.3 The money arithmetic, closed

```
raw            = Amount + GST_Amount − Discount
Grand_Total    = ROUND(raw)                       to whole rupees
Adjusted_Amount = Grand_Total − raw               the rounding remainder
Payable_Amount = Grand_Total − Paid_Amount
```

Measured:

- `Grand_Total == raw` exactly on **9,127** rows; `== ROUND(raw)` on a further
  **1,559**; **3** rows fit neither (`EKO/F&BOrder/8923` has `raw = −6000` against a
  grand total of `3000`).
- **`Adjusted_Amount` is the rounding remainder** — non-zero on exactly **1,557**
  rows, range **−0.48 … 0.50**, and
  `Adjusted == Grand_Total − raw` holds on **10,684 of 10,689** rows. That is what
  the field is for; the DS gives it no label at all.
- `Payable_Amount` is **not** a copy of Grand Total. It is 0 on **8,974 of 10,079**
  `Paid` rows and equals Grand Total on **every** `Unpaid` (125) and
  **every** `Payment Inprogress` (477) row. So it is the outstanding balance.

The 1,104 `Paid` rows where payable still equals grand total are a **live data
defect** worth quantifying — marked paid, balance never cleared. Same shape as the
Accounts finding that a parent status never advances.

### 8.4 `Payment Inprogress` confirmed in live data

**477 rows** carry the lowercase-p spelling the DS declares. So the third variant is
real, not a typo in the export — and any status comparison across the cluster that
looks for `Payment InProgress` misses all 477.

Full picture: `Paid` 10,079 · `Payment Inprogress` 477 · blank 465 · `Unpaid` 125.
And `Status`: `Order Received` 10,690 · blank 452 · **`Vendor Fulfilled` 7**. The
middle state is effectively unused — 7 rows of 11,149.

### 8.5 The export header repeats a column

`Modified User` appears **twice** (positions 21 and 28). Exactly the
`Vendor_Master` GST hazard: `array_combine` keeps the last and silently drops the
first. **Read this export positionally**, via `masterDataCsvPositional()`.

### 8.6 What the screenshot adds that no export could

From the list screen:

- Column order as rendered: **Added Time · Order No. · Order Date · Order for ·
  Vendor Name · Location · Booking No. · Villa Name · Checked In Date · Check Out
  Date** — note `Added Time` is first, and `ID` is nowhere near the end.
- **Villa Name renders with a filled green background**, the same treatment
  Accounts gives a status cell. It is a lookup, not a status, so the colour is
  presumably conditional formatting — needs one more look to know the rule.
- `Save Changes` / `Remove Changes` sit in the report bar, so this grid is
  **inline-editable**, like Accounts' COA report.
- The search chip reads `Order No. contains "123"` — same component as Accounts.
- Vendor names carry **trailing spaces** (`VEG PRATHMESH ALIBAUG `), consistent with
  the 328 edge-whitespace names already found in `Vendor_Master`.

### 8.7 The form screenshot — layout, and the sidebar the DS omits

Blank **Add** form, 31-Aug-2026. Two things it settles that no export could.

**The F&B sidebar, in order.** The DS export carries no navigation block, so this is
the only source for it:

```
Dashboard · Bookings · Request Stock for Food · Inventories · Vendor Bills ·
Monthly Checks · Expenses · Transfer Items · Masters · Settings ·
App Preferences · Payment Request · Food Order Details · Recipe Masters ·
Requirements of Recipe
```

**The nav label is `Vendor Bills`, not "Vendor Order Booking".** The form is titled
`Vendor Order Booking` and the report `All Vendor Order Bookings`, but a user reaches
both through **Vendor Bills**. Three names for one thing; the rebuild's nav must say
`Vendor Bills`.

Also note `Dashboard` and `Payment Request` are the two `pages` at `F_B.ds:4618` —
both are embeds, one an Analytics view (`open-view/443703000002693284`), one an iframe
to an Accounts report. Not forms; do not model them as such.

**Layout: two columns, and 13 of 36 fields are hidden on Add.**

| Left column | Right column |
|---|---|
| Vendor Name \* · Vendor Category · Order for \* | Order Date · Billing year · Billing Month |
| Update Fulfilled Qty · Update Received Qty | Billing Cycle · Location · State |
| *(Section 2)* Total Quantity · Amount · Discount · Grand Total | Payment Due Date · Particulars · Vendor Bills |
| | *(Section 2)* Paid Amount · Payable Amount |

The money fields split across BOTH columns — Total Quantity / Amount / Discount /
Grand Total on the left, Paid / Payable on the right. Not a single stacked block.

Hidden on the blank Add form: `Booking_No`, `Request_No`, `Warehouse_Name`,
`Villa_Name`, `Order_No`, `Status`, `Payment_Status`, `Items_Ordered`, `GST_Amount`,
`Adjusted_Amount`, `Books_ID`, `Expense_Updated`, `Order_recived`.

That grouping is coherent: **the three `Order_for` targets** (Booking / Warehouse /
Villa) plus **the generated and system fields** (order number, statuses, Books id,
the audit checkboxes) plus **the line-item grid**.

**And the DS contains no handler that hides them.** `grep` for a
`Vendor_Order_Booking.*` form script returns nothing — the show/hide is Creator
*layout* configuration, which the DS export does not carry. So:

- The conditional display is **inferred from the screenshot, not sourced.** Marked
  **[TODO-FNB-4]** — it needs a second screenshot with `Order for` set to each of the
  three values to confirm which target field appears.
- `Items_Ordered` presumably appears only after the parent is saved, which is
  ordinary Creator behaviour for a grid, but that is an assumption too.

**Formatting confirmed**, matching CLAUDE.md's rules exactly: dates as
`dd-MMM-yyyy` in a **text input with a calendar button**, never a native date
picker · money placeholders `##,##,###.##` with a `₹` suffix button · `Billing year`
as `#######`, a plain number.

### 8.8 The detail view — arithmetic confirmed, and one real discrepancy

Detail panel for **`EKO/F&BOrder/11431`**, 31-Aug-2026. Cross-checked field by field
against the same record in the export: **13 of 14 match exactly.**

**§8.3's arithmetic is confirmed on a single real record**, not just in aggregate:

```
raw          = 1025.50 + 0.00 − 7.00  = 1018.50
Grand_Total  = ROUND(1018.50)         = 1019.00   ✓ shown as ₹ 1,019.00
Adjusted     = 1019.00 − 1018.50      =    0.50   ✓ shown as 0.50
Payable      = 1019.00 − (blank → 0)  = 1019.00   ✓ shown as ₹ 1,019.00
```

Note `Adjusted Amount` renders as **`0.50` with no ₹ symbol** while every money field
carries one. Consistent with the DS: it is `type = decimal`, not `type = INR`. It is a
remainder, not an amount — and Creator's own typing says so.

#### The discrepancy: Villa Name is blank on screen, populated in the export

| | |
|---|---|
| detail view | `Villa Name` **blank** |
| export | `EKOSTAY- Oceanic Villa` |

The record has a `Booking No.` (`EKO10316070`), and villa is presumably derived from
it. So one of two things is true, and they need different handling:

1. The detail **layout** omits the field's value for a derived lookup (a display
   concern), or
2. the stored value really is empty and the export resolves the villa **through the
   booking** (a data concern).

§8.1 already found **582 "Against Booking" rows with no villa** out of 10,484, so
blank-villa records genuinely exist. But this row is not one of them — the export has
a villa for it. **[TODO-FNB-5]**: do not treat `villa_name` as authoritative for an
Against-Booking order until this is settled; derive from the booking.

#### Books ID is a Zoho Books id, same org as the COA accounts

`Books ID = 2508841000011538002` — 19 digits, prefix **`2508841`**, exactly the prefix
on **all 125** populated `coa_accounts.books_account_id` values (§4.1). So F&B orders
are pushed to Zoho Books and the id is stored back.

Two consequences: it is **19 digits and must be a string** like every other id here,
and it confirms a **Books integration exists on this form** — which §17 puts out of
scope for the first pass ("do not implement the Books push"). Store the field, do not
build the push.

#### Field order in the detail view is NOT the form's order

The detail panel runs: Order No. · Order Date · Order for · Location · Booking No. ·
State · Warehouse Name · Status · Payment Status · Payment Due Date · Total Quantity ·
Amount · Vendor Category · Vendor Name · Request No. · Books ID · Update Fulfilled Qty ·
Update Received Qty · Expense Updated · GST Amount · Grand Total · Discount · Paid
Amount · Payable Amount · Adjusted Amount · Particulars · Villa Name · Billing year ·
Billing Month · Billing Cycle · Vendor Bills · Checked In Date · Check Out Date ·
Order recived.

Three layouts, three orders — **form** (two columns, §8.7), **list** (§8.6), and
**detail** (above). Creator lets each be arranged independently, so the rebuild needs
all three recorded separately rather than deriving one from another.

**All 34 non-section fields appear here**, including the 13 hidden on the blank Add
form. So those are hidden **on Add only** — they populate on save or by workflow. That
partly answers TODO-FNB-4 without needing the three-mode screenshots: the fields are
not conditionally *absent*, they are conditionally *editable*.

Also visible: `Checked In Date` / `Check Out Date` are on this form, sourced from the
booking — the guest stay dates that made the export worth git-ignoring. And the detail
view offers **Edit · Print · PDF** plus prev/next record arrows and `Add a comment`.

---

## 9 · Vendor Order Booking Item — 110,510 live line items

`master-data/All Vendor Order Booking Items.csv`, 31-Aug-2026. 22 columns,
**110,510 rows** — the largest table in F&B by an order of magnitude.

### 9.1 `Amount` follows RECEIVED quantity, not Ordered

The decisive test is the 4,523 rows where the two quantities differ:

| | rows |
|---|---|
| `Amount == Received × Price` | **4,438** |
| `Amount == Ordered × Price` | 81 |

```
EKO/F&BOrder/6614   KULFI       ordered 20  received  9   price 20  amount  180  = 9 × 20
EKO/F&BOrder/6636   CABBAGE     ordered  1  received 0.5  price 60  amount   30  = 0.5 × 60
```

**You pay for what arrived.** That is the correct behaviour for a purchase order and it
is not stated anywhere in the DS — `Amount` has no formula attached to it there.

The 81 exceptions are rows where receipt was recorded after the amount, or never
adjusted. Real data, worth a flag rather than a fix.

### 9.2 The parent rolls up from the legs, and 287 orders do not

> **CORRECTED at full volume — see §16.2.** Only **1** order has legs exceeding
> the parent across 10,768 rows. The 287 figure came from the CSV subset and
> conflated "disagrees" with "exceeds".

`parent.Amount == SUM(line.Amount)` holds on **10,455 orders** and fails on **287**.

The screenshot record reconciles exactly on both figures:

```
EKO/F&BOrder/11430   legs sum amount   = 2464.50   screen: ₹ 2,464.5   ✓
                     legs sum received =   91.85   screen: 91.85       ✓
```

So `Total_Quantity` is **SUM(Received Quantity)** — again undocumented in the DS, and
again confirmed against a rendered record.

The 287 mismatches all run the same way — **legs exceed the parent**
(`EKO/F&BOrder/11428`: parent 1,938.50, legs 2,302.50). Consistent with a line item
added or received after the parent total was last computed, which is exactly the
staleness §6.4 warns about in Accounts. **The rebuild must recompute the parent from
its legs rather than storing an independent figure**, and the 287 should be reported
at import rather than reconciled silently.

### 9.3 `Fulfilled Quantity` exists on the form but NOT in the export

The Items Ordered grid renders **Item Name · Item Category · Ordered Quantity · UOM ·
Fulfilled Quantity · Received Quantity**, and the DS declares `Fulfilled_Quantity` as a
decimal. The export carries only Ordered and Received.

So there are **three** quantities per line — ordered, fulfilled (what the vendor said
they would send), received (what arrived) — and the middle one is invisible to
Analytics. Consistent with §12: an export is a view, not the record.

`Update_Fulfilled_Qty` and `Update_Received_Qty` on the parent are the checkboxes that
drive those two, both `true` on the screenshot record.

### 9.4 The grid is edit-mode only, and quantities are read-only there

From the Edit screenshot of `EKO/F&BOrder/11430`:

- **Items Ordered appears only in Edit**, not on Add and not in the detail view. 20
  rows visible, horizontally scrollable.
- **`Ordered Quantity` renders greyed/read-only** while `Fulfilled` and `Received` are
  editable. So the order lines come from the Raw Material Request and are not retyped
  here — the buyer records what was fulfilled and received against them.
- **`Order No.` is read-only** (`EKO/F&BOrder/11430`) — generated, as §8.7 inferred.
- `Amount`, `Grand Total`, `Total Quantity`, `Payable Amount` are all read-only;
  **`Discount` is the only editable money field.** That single input is what moves
  Grand Total, which then rounds and produces `Adjusted Amount`.
- Item Category shows `VEGETABLES` on every row and is per-line, not per-order.
- **UOM per line matches Item Master**: `kg` for most, `Pieces ` for LV CORRIENDER,
  LEMON, EGGS, LV PALAK, LV METHI, `Packets` for BABY CORN — the same values, trailing
  space included, that 70 items join to in `fnb_uoms`.

### 9.5 Villa Name is blank on the record too — TODO-FNB-5 answered

A second detail view (`Request No. EKO/Stock/4392`, Books ID
`2508841000011535003`) shows **Villa Name blank again**, and the Edit form for
`EKO/F&BOrder/11430` **does not render a Villa Name field at all** — while its export
row carries a villa.

So the answer is (2) from §8.8: **the stored field is empty and the export resolves
villa through the booking.** The line-item export confirms it — it has its own `Villa`
column, populated per line.

**Consequence for the rebuild:** `villa_name` on the order is not authoritative for an
Against-Booking order. Derive it from the booking, or from the line items, and treat a
blank parent value as normal rather than as missing data. **TODO-FNB-5 closed.**

---

## 10 · The buying chain, built — 31-Aug-2026

**9 tables, 7 models, 14 tests. Suite: 150 passed, 1,415 assertions.** (Was 144.)

| Table | Rows | Source |
|---|---|---|
| `billing_cycles` | **14** | recovered from the order export |
| `fnb_uoms` | 9 | `fb.UOM` |
| `fnb_item_masters` | 370 | `fb.Item_Master` |
| `fnb_vendor_order_bookings` | 0 | `fb.Vendor_Order_Booking` — structure only |
| `fnb_vendor_order_booking_items` | 0 | `fb.Vendor_Order_Booking_Item` — structure only |
| `fnb_warehouses` + 2 pivots | 0 | `fb.Warehouse` |
| `fnb_inventories`, `fnb_inventory_stocks` | 0 | `fb.Inventory`, `_Stock` |

### `billing_cycles` is no longer empty, and February is spelled two ways

`accounts/CLAUDE.md` lists it under "not seeded, no export exists". Still true of a
cycle *master* — but the order export names 14 distinct cycles across 11,149 orders,
so they were recovered the way `VendorSeeder` recovered `Alleppey`.

**The misspelling is the DOMINANT one:**

| | orders |
|---|---|
| `Feburary - 2026` | **847** |
| `February - 2026` | 34 |

Both kept as separate rows. 847 orders resolve through the misspelling; normalising
it would orphan them. This is `multipe_hccc_names` again — CLAUDE.md: "needs a mapping
table, not a normalisation function". The seeder warns when it sees both, so the
condition is visible rather than buried.

### Four constraints that describe measured reality

Not invented rules — each holds on all 11,149 live orders:

- `order_for IN ('Warehouse','Against Booking','Location')`
- `status IN ('Order Placed','Vendor Fulfilled','Order Received')`
- `payment_status IN (…,'Payment Inprogress',…)` — **the third spelling in the
  cluster**, 477 live rows
- **the discriminator**: a Warehouse order never carries a booking, an
  Against-Booking order never carries a warehouse, a Location order carries neither

### The money is derived, not trusted

287 live orders already have legs exceeding their stored parent total (§9.2), so
`FnbVendorOrderBooking::recomputeTotals()` is the authority and the columns cache it.
`totalsAreCurrent()` exists to *detect* the 287 rather than silently correct them.

**`Money::mul()` had to be added** — the first multiplication in that class. Bills
never needed one because it SPLITS a total that already exists; an F&B line
*derives* its amount as `received × price`. `roundToRupees()` was already there and
already half-away-from-zero, matching Deluge.

Six unit tests pin the arithmetic against the two rendered records, including the
negative remainder (live range −0.48 … 0.50, so the column is signed).

### `.gitignore` inverted for `master-data/`

Four near-misses on that folder, so naming files individually was clearly the wrong
default. It now **ignores every CSV and JSON there**, with the two small safe F&B
masters re-allowed by name. A new export is private until someone decides otherwise.

The four exports that arrived today hold vendor names, villa names, **guest stay
dates** and — in `Request Stock for Food Report` — **guest names**. All ignored,
verified with `git check-ignore`.

### Not built yet, deliberately

**No importer for the 11,149 orders and 110,510 lines.** The tables and the
arithmetic are proven first; loading 122k rows through an unverified mapping is how
the 287 stale parents would become 287 silent errors. The importer needs to reject
the 407 malformed orders (§8.2) and report the 287 rather than reconcile them.

`fb.Booking` is referenced by `booking_no` as a **string**, not a foreign key — the
52-field stay-booking master belongs to another app and is not modelled here.

---

## 11 · Raw Material Request — three real defects in the live form

The blank Add form (screenshot, 31-Aug-2026) shows things that look like rendering
faults. **All three are real, and all three are in the DS.**

### 11.1 `Item_Name` is labelled `"request n"`

`F_B.ds:1980`:

```deluge
must have Item_Name
(
    type = picklist
    displayname = "request n"        <-- the label users see
    values  = Item_Master.ID
    displayformat = [Item_Name]
)
```

So the **first and most important field on the form — the mandatory item picker —
is labelled `request n`.** It reads like a half-typed "request no" left in place.

It is not confined to the form. The label leaks into three reports as
`Item_Name as "request n"` (lines 4164, 19409, 19462) and into a **print template**
at line 773, where the Raw Materials grid header renders `request n` above item
names on a document that goes to vendors.

**Copy-as-built applies to behaviour, not to a label that misinforms the user.** The
column is `item_name`; the rebuild shows **Item Name** and this is logged as
deviation **D-FNB-1** — the first F&B deviation, alongside Accounts' D1–D6. Flag it
to Husain as a one-line Creator fix rather than something we mirror.

### 11.2 Two pairs of fields share one label

| Field | Label | References |
|---|---|---|
| `Warehouse_Name` | `Warehouse Name` | the real one |
| `Warehouse_Name1` | **`Warehouse Name`** | 11 |
| `Vendor_Name` | `Vendor Name` | the real one |
| `Vendor_Name1` | **`Vendor Name`** | 15 |

Identical `displayname` on both members of each pair, so on screen they are
**indistinguishable** — which is exactly what the screenshot shows.

**But they are hidden in the real workflow.** `F_B.ds:7897`:

```deluge
hide Raw_Materials.Warehouse_Name1;
hide Raw_Materials.Vendor_Name1;
hide Raw_Materials.Backend_Warehouse_Quantity;
```

Those three are hidden unconditionally inside
`RequestStock.RequestStockPendingRaw`, the handler that drives the grid. The blank
Add form simply never runs it — which is why it looks broken standing alone and
works in context.

So the duplicates are **internal working fields**, not user inputs: `_1` holds the
alternative-source value while the un-suffixed field holds the chosen one.
`Backend_Warehouse_Quantity` is the same pattern as Accounts' `Backend_*` triplet —
an allocation snapshot, per the closed §10 TODO there.

Modelled as separate columns with the hidden ones marked. Collapsing them would
lose the distinction the logic depends on.

### 11.3 `Request_From` drives the whole form

`{"Warehouse","Order from Vendor"}` — the same discriminator shape as
`Vendor_Order_Booking.Order_for`. It decides whether stock comes off a warehouse or
gets ordered, and therefore which of each duplicated pair is live.

The export confirms both paths are used, and gives the quantity chain:

```
Original_Requested_Quantity   what was first asked
Requested_Quantity            current ask (mandatory)
Delivered_Quantity            what arrived
Pending_Quantity              the remainder
Available_Quantity            warehouse stock at the time
Warehouse_Quantity            taken off the warehouse
Order_Quantity                sent to a vendor
```

Seven quantities on one form. `Warehouse_Quantity + Order_Quantity` is how a request
splits between stock-on-hand and a purchase order — which is the link to
`Vendor_Order_Booking_Item.raw_material_request_no` and explains why
`ordered_quantity` is read-only in the Items Ordered grid (§9.4): it comes from here.

### 11.4 The export repeats two headers

`Request No.` and `ID` each appear **twice** in `All Raw Material Requests.csv` —
the third export to do this after `Vendor_Master` (three `GST No.`) and
`All Vendor Order Bookings` (two `Modified User`).

**Read it positionally.** `masterDataCsv()`'s `array_combine` keeps the last and
silently drops the first.

---

## 12 · The request chain, built — 31-Aug-2026

**11 F&B tables, 9 models, 5 CHECK constraints. Suite: 150 passed, 1,415 assertions.**
55 tables in the schema overall.

| Table | Cols | Creator source | Rows waiting |
|---|---|---|---|
| `fnb_vendor_order_bookings` | 37 | `fb.Vendor_Order_Booking` | 11,205 |
| `fnb_raw_material_requests` | **35** | `fb.Raw_Material_Request` | **160,995** |
| `fnb_request_stock_for_foods` | 22 | `fb.Request_Stock_for_Food` | 4,328 |
| `fnb_vendor_order_booking_items` | 19 | `..._Item` | 110,510 |
| `fnb_item_masters` | 10 | `fb.Item_Master` | **370 seeded** |
| `fnb_inventories` | 10 | `fb.Inventory` | 855 |
| `fnb_inventory_stocks` | 9 | `fb.Inventory_Stock` | — |
| `fnb_warehouses` + 2 pivots | 6+2+2 | `fb.Warehouse` | — |
| `fnb_uoms` | 5 | `fb.UOM` | **9 seeded** |

### Decisions taken in the request chain

- **`uom_text` is TEXT, not a foreign key.** Creator declares UOM on this one form
  as `type = text` while every other UOM field in the app is a picklist over
  `UOM.ID`. A foreign key would reject whatever free text 160,995 live rows hold.
- **The duplicated pairs are modelled as `alt_*` columns**, not collapsed:
  `alt_fnb_warehouse_id` and `alt_vendor_id` are Creator's `Warehouse_Name1` and
  `Vendor_Name1`, hidden at F_B.ds:7897 and holding the alternative-source value.
- **All three Request_No pickers kept** — the plain one plus `_Partial` and
  `_Completed`, which is how a partly-filled request carries forward.
- **`guest_name` is in `$hidden`** on the model. It is real PII sitting beside villa
  and stay dates, and CLAUDE.md already records that the most sensitive read in the
  app (vendors, with PANs) has no authorisation on it. Not a reason to add a second.

### D-FNB-1 — the first F&B deviation

Creator labels `Raw_Material_Request.Item_Name` as **`"request n"`**, and that label
reaches three reports plus a vendor-facing print template. The rebuild labels it
**Item Name**.

Copy-as-built governs *behaviour*; it does not require reproducing a label that
misinforms the user about what a field is. Logged alongside Accounts' D1–D6, and
worth a one-line Creator fix independently of the rebuild.

### Still not built, deliberately

**No importer.** 287,038 rows are sitting in `master-data/` across five exports, and
the tables plus the arithmetic are proven first. When the importer lands it must:

- **reject** the 407 malformed orders (§8.2) rather than load them as zero-value
- **report** the 287 orders whose legs exceed their parent (§9.2) rather than
  reconcile them
- **read positionally** — three of five exports repeat a header
- **never create** a billing cycle, a category or a UOM to satisfy a reference (§6.4)

---

## 13 · Auto_Numbers, and a recipe form that cannot see 35 items

### 13.1 F&B's counters are separate from Accounts', and both defects recur

`fb.Auto_Numbers` (F_B.ds:15) is its own singleton with **four** series — both
READMEs list three and miss `Transfer_Series`:

| series | counter | used by |
|---|---|---|
| `Booking_Series` | `Booking_No` | `fb.Booking` |
| `Request_Series` | `Request_No` | `fb.Request_Stock_for_Food` |
| `Vendor_Booking_Series` | `Vendor_Booking_No` | `fb.Vendor_Order_Booking` |
| `Transfer_Series` | `Transfer_No` | `fb.Transfer_Items` |

**Both of the defects Accounts logged are here too.**

**Non-atomic increment** — `F_B.ds:6710` reads the singleton, formats a number, then
writes `No + 1` with no lock between. Two concurrent orders take the same number.
Accounts logged this as D3; `FnbNumber::allocate()` uses `lockForUpdate()`.

**The padding is dead code AND miswritten.** F_B.ds:6713-6725 pads with
`if (<10) … else if (<100) …` then a **bare** `if (<1000)` rather than an
`else if`. So a two-digit number is padded twice — `"00"` then `"0"` again, three
zeros for two digits. It has never fired: the census over 11,205 live orders is
9,276 four-digit and 1,466 five-digit numbers, nothing shorter. Same shape as
Accounts §7.6. **Not reproduced** — a branch that cannot fire is not behaviour, and
copying it would corrupt any future low-numbered series.

**The counters carry their real live values**, measured from the exports:

```
EKO/F&BOrder   max 11,435  ->  next 11,436
EKO/Stock      max  4,527  ->  next  4,528
```

Booking and Transfer get **no counter at all** — no export names one, and
`allocate()` refuses on a null rather than minting from 1. Accounts' seeder makes
the same point about the payment counter needing the real 20938.

And the same guard: `allocate()` **refuses** while our counter sits at or below the
last number observed live, because Creator keeps minting while this is built.

### 13.2 `Requirements_of_Recipe` hardcodes four categories and misses five

The form (F_B.ds:2547) has four grids, each filtered by a **literal category name**:

```
KIRANA_REQUIREMENTS      Item_Master[Item_Category.Item_Category == "KIRANA"]
DAIRY_REQUIREMENTS       Item_Master[Item_Category.Item_Category == "DAIRY"]
VEGETABLE_REQUIREMENTS   Item_Master[Item_Category.Item_Category == "VEGETABLES"]
MEAT_REQUIREMENTS        Item_Master[Item_Category.Item_Category == "MEAT"]
```

All four resolve and all four are F&B-flagged. But the item census says those grids
reach **335 of 370 items**, leaving **35 unreachable from this form**:

| category | items | in a grid? |
|---|---|---|
| KIRANA | 209 | yes |
| VEGETABLES | 70 | yes |
| DAIRY | 39 | yes |
| MEAT | 17 | yes |
| **F&B GENERAL PURCHASE** | **20** | **no** |
| **FRUITS** | **8** | **no** |
| **F&B TRANSPORT** | **3** | **no** |
| **F&B GAS** | **3** | **no** |
| **BAKERY** | **1** | **no** |

FRUITS and BAKERY are ordinary kitchen inputs, so a recipe cannot list a fruit or
anything from the bakery. This is what a hardcoded list does as data grows — and it
is the same class of defect as Creator's category-scoping mechanisms that §3.1 says
"do not implement all of them".

**Not a copy-as-built case**, but not ours to decide either: whether a recipe should
be able to name a fruit is a business question. Recorded as **TODO-FNB-6** and the
table is built category-agnostic — one child table keyed by item, not four grids —
so either answer is a query change rather than a migration.

---

## 14 · F&B schema complete — 19 of 21 forms, 31-Aug-2026

**22 tables, 14 CHECK constraints, 40 F&B tests. Suite: 223 passed, 1,679
assertions.**

| Built | | Deliberately not built | |
|---|---|---|---|
| Auto_Numbers | 4 series + guard | **Booking** | 46 fields — another app's master |
| Block_Booking_Date | | **Expenses** | 38 fields — Accounts owns `expenses` |
| Chef_Master | PII | | |
| Food_Order_Details | | | |
| Inventory · Inventory_Stock | 855 seeded | | |
| Item_Master · UOM | 370 · 9 seeded | | |
| Monthly_Check + items | | | |
| Raw_Material_Request | | | |
| Recipe_Master + requirements | | | |
| Request_Stock_for_Food | | | |
| Transaction_Items | the stock ledger | | |
| Transfer_Items | | | |
| Vendor_Order_Booking + items | | | |
| Vendor_Price_List | | | |
| Warehouse + 2 pivots | 8 seeded | | |

### Constraints that encode a decision

- **A warehouse cannot transfer to itself.** Creator's `To_Warehouse` picklist
  excludes the source (`Warehouse[Warehouse_Name != …]`), so it is unreachable
  through the UI — but browser-side validation is not a boundary, so it is a CHECK.
- **`Transaction_Type` admits `Reverse`.** That is how a stock mistake is undone,
  the same shape as the payment reversal Accounts built for D4. Stock is never
  edited backwards; a correction is another row. An invented type like `Wastage` is
  rejected.
- **A vendor can price an item once** — unique on (item, vendor).
- **`Chef_Master.Status`** is `{Active, Inactive}` and nothing else.
- **Every counter is positive.** `EKO/F&BOrder/0` would look plausible in a report.

### Why Booking and Expenses are absent

**`fb.Booking`** (46 fields) is the **stay-booking master for the whole business** —
check-in, check-out, guest, villa. F&B reads it; it does not own it. Modelling it
here would put the booking engine's core entity under an F&B prefix. Referenced by
`booking_no` as a string throughout until the app that owns it is rebuilt.

**`fb.Expenses`** (38 fields) — Accounts already has an `expenses` table from the
other developer's work, and the F&B expense rows are created by
`accounts.FB.Accounts()` posting into that ledger (§4.1). Two tables would be two
answers. Needs a conversation about whether F&B expenses are rows in Accounts'
ledger with a source flag, or a separate table that posts into it — the same
question §5.2 asks about `Expenses_Bills`.

### What is still open

| | |
|---|---|
| **No importer** | 288k rows across five exports. Tables and arithmetic proven first. |
| **No UI** | 19 tables, zero screens. Creator's reports are the spec; four screenshots exist. |
| **TODO-FNB-6** | Should a recipe be able to name a FRUITS or BAKERY item? |
| **Block_Booking_Date enforcement** | Accounts found the equivalent is enforced nowhere server-side. Expected to be the same here; not assumed. |

---

## 15 · The Analytics importer, and Analytics TRIMS

`fnb:import` pulls from the 21 `(Zoho Creator-F&B)` source views. Findings while
building it, 31-Aug-2026.

### 15.1 The views were discoverable, not something to ask for

665 views in the `accounts` workspace, and every F&B source table carries the
suffix **`(Zoho Creator-F&B)`**. Listing the workspace found all 21 without a
single ID being supplied.

They are viewType **`Table`** — a plain projection of the Creator form — not
`QueryTable`. That matters: §6 records that a heavy-join QueryTable is what times
out on bulk export (`all_payments` is flagged `avoid` for exactly that), and the
`(F&B)` QueryTables sitting beside these are reporting joins. **Prefer the Tables.**

Near-identical names to be careful of:

| take | not | difference |
|---|---|---|
| `Inventory_Stock` (…683) | `Inventory Stock` (…647) | an underscore |
| `Vendor Order Booking_Items Ordered` (…917) | `Vendor Order Booking Item` (…935) | the grid vs the standalone form |

Also: the `fnb` view already registered by the other developer
(`443703000002007229`) is **"All Expenses (F&B)"** — an expense-shaped join, not a
form table. Both are kept; they answer different questions.

### 15.2 Analytics returns IDs, not names — and that is better

```json
{ "Item Name": "292482000000390893", "Warehouse Name": "292482000000883307",
  "Price": "₹ 200.00", "Transaction Type": "Out" }
```

Lookups arrive as **18-digit record IDs**. The CSV exports gave names, which forced
string matching and is how `Pieces ` nearly orphaned 70 rows. Resolution is now by
`creator_id` throughout.

Money still arrives **pre-formatted as text** (`₹ 200.00`), so it is parsed to a
decimal string and never cast.

Two views break the pattern and return **names**: `Warehouse` gives
`Location: "Lonavala"` and `State: ""`. Handled with separate by-name maps rather
than assumed uniform.

### 15.3 ⚠️ ANALYTICS STRIPS THE TRAILING SPACE

The single most important finding here.

| source | value | length |
|---|---|---|
| `UOM Report.csv` (Creator report export) | `Pieces ` | **7** |
| `All Item Masters.csv` (Creator report export) | `Pieces ` | **7** |
| **Analytics `UOM (Zoho Creator-F&B)` view** | **`Pieces`** | **6** |

Verified with `zoho:inspect`, so it is Analytics doing it and not the importer:
the raw JSON reads `"UOM": "Pieces"`.

**Consequences:**

1. **Analytics is not a faithful mirror of Creator for string keys.** §12 already
   established that it flattens multi-value fields; this adds that it trims
   whitespace. The read plane is lossy in a second, quieter way.
2. **The import still joins correctly**, because it resolves by `creator_id` rather
   than by the string. That is the whole argument for ID-based resolution — the
   trailing space stopped being load-bearing the moment the join stopped using it.
3. **But the stored key CHANGED** — `fnb_uoms.name` went from `Pieces ` to
   `Pieces`. Anything that displays it, or matches a future CSV against it, now
   disagrees with Creator.

**Not resolved unilaterally.** Which spelling is authoritative is a question about
the source of truth, not a preference:

- If Creator's own report export is authoritative, seed masters from CSV and use
  Analytics only for transactional rows.
- If Analytics is authoritative, the CSV-era assertion that `Pieces ` has 7
  characters is wrong and four tests need re-measuring.

Recorded as **TODO-FNB-7**. Until it is answered, **seed the masters from CSV and
import transactions from Analytics** — which is what the dependency order does
anyway, since masters barely change.

### 15.4 A bug in my own importer, and how it hid

`--only=items` reported success and produced **371 rows with no UOM**. Then
`--only=inventories` produced **855 rows with no item, no warehouse and no UOM**.
1,226 orphaned rows across three tables, and the command printed green.

The cause: maps were built only inside `put()`, as each table imported. A full run
is fine — the parent populates the map before the child needs it. `--only` skips
the parents entirely, so every lookup missed and every miss was faithfully counted
as "unresolved" rather than recognised as a broken run.

Fixed by preloading every F&B map from the database at startup. A miss is now a
genuine missing parent rather than an artefact of the flag.

Worth noting the counter *did* report `371 unresolved` — the instrumentation
worked. What was missing was reading it: a lookup failing on **every single row**
is not a data problem, it is a wiring problem, and the importer should say so.

### 15.5 The warehouse Location pivot filled — the CSV had none

`fnb_warehouse_locations` now has **8 rows**. The CSV export flattened
`Warehouse.Location` to nothing; the Analytics view populates it. So §12's
flattening is not uniform — it cost us Villa Name (still blank on every row) but
not Location.

The pivot exists because Creator declares both as multi-value `list` fields. Had
they been modelled as columns, this data would have had nowhere to go.

---

## 16 · Live data — 367,951 rows, 31-Aug-2026

`php artisan fnb:import` pulls all 18 populated tables from the Analytics source
views. `fnb:backfill-ids` sets `creator_id` on masters that were CSV-seeded.

| Table | Rows |
|---|---|
| `fnb_raw_material_requests` | **161,402** |
| `fnb_vendor_order_booking_items` | **110,811** |
| `fnb_transaction_items` | **68,413** |
| `fnb_vendor_order_bookings` | 10,768 |
| `fnb_inventory_stocks` | 6,710 |
| `fnb_request_stock_for_foods` | 4,334 |
| `fnb_vendor_price_lists` | 2,291 |
| `fnb_food_order_details` | 1,229 |
| `fnb_inventories` · `fnb_item_masters` | 857 · 371 |
| `fnb_recipe_masters` · `fnb_chef_masters` | 245 · 138 |
| `billing_cycles` · `fnb_monthly_checks` | **82** · 76 |
| `fnb_transfer_items` · `fnb_uoms` · `fnb_warehouses` | 15 · 9 · 8 |

All 18 render in the browser at `/` → **F&B**, paged server-side.

### 16.1 Findings confirmed at full volume

**`amount` follows RECEIVED quantity** — on the 5,672 rows where ordered and
received differ, **5,672 follow received against 1 for ordered**. §9.1 held.

**The stock ledger's shape:** Out 48,808 · In 10,361 · **Reverse 7,218** ·
Misplaced 1,936 · Damaged 90. Every value is one the CHECK already allowed. The
7,218 reversals confirm stock corrections are made as **new rows, never edits** —
which is why `Reverse` is in the constraint.

**`billing_cycles` has 82 rows, not 14.** `CLAUDE.md` lists it under "no export
exists"; the master is `Billing Cycles` (443703000001623110) and goes back to 2023.
`FnbBillingCycleSeeder` recovered only the 14 names that appeared on orders. Both
February spellings survive.

### 16.2 CORRECTED: the 287 stale parents were a CSV-era artefact

§9.2 reported 287 orders whose line items exceed the stored parent. **At full
volume that is 1.**

The real picture over 10,768 orders:

| | |
|---|---|
| legs **exceed** the parent | **1** |
| legs **below** the parent | 7 |
| legs sum to NULL (all line amounts null) | 297 |

So the earlier figure conflated "disagrees" with "exceeds", and most of the
disagreement is orders whose lines carry no amount at all. `recomputeTotals()` is
still the authority and `totalsAreCurrent()` still detects the difference — but the
scale of the problem was overstated, and the honest number is **one order**.

### 16.3 Three bugs in my own importer, all silent

**Maps built only inside `put()`.** `--only=items` produced 371 rows with no UOM;
`--only=inventories` produced 855 with no item, warehouse or UOM. **1,226 orphaned
rows and the command printed green.** The counter did report "371 unresolved" — the
instrumentation worked; reading it did not. A lookup failing on *every* row is a
wiring problem, not a data problem. Fixed by preloading every map from the database.

**`TRUNCATE` cascades.** Re-importing warehouses emptied `fnb_inventories`, and
through it `fnb_inventory_stocks`, and re-importing those emptied the stock ledger.
**299,070 rows became 4,304 across two runs, reported as success both times** —
because an import counts what it writes and never what it destroyed on the way in.
Replaced with `DELETE`, which raises a foreign-key violation instead: the database
saying the order is wrong rather than silently discarding children.

**A hardcoded table list went stale within a day.** The billing-cycle dedupe checked
`bills.billing_cycle_id`, which does not exist — bills reference a cycle through the
`bill_billing_cycle` pivot. The query threw, the whole removal block aborted, and 14
duplicate cycles survived silently. Replaced with an `information_schema` query.

### 16.4 What Analytics gives that the CSVs did not, and vice versa

**Analytics adds:** `Fulfilled Quantity` on order items (§9.3 recorded it as absent
from the export) · `Warehouse.Location`, filling a pivot the CSV flattened to
nothing · 82 billing cycles against 14 · lookups as **record IDs** rather than
names, so no string matching.

**Analytics loses:** the **trailing space** (§15.3) · `No Decimal Values` on items ·
`Order recived` on orders · **`Guest Name`** on requests — the CSV has real guest
names and the view does not expose it.

So neither source is complete. **Masters are seeded from CSV** (they keep the string
keys) and **transactions imported from Analytics** (it has the volume and the IDs).
That split is not a compromise; it is what each source is actually good for.

### 16.5 The mislabel reached the read plane

`Raw_Material_Request.Item_Name` is labelled `"request n"` in Creator, and
**Analytics has taken that label as the column name** — the view's field is
literally `requestn`.

So D-FNB-1 is worse than a cosmetic problem in three reports and a print template:
anyone importing that table would reasonably map `requestn` to a request number and
fill the wrong column with item IDs. The importer maps it explicitly, with the
reason attached.
