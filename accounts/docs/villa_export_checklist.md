# Villa form-level export — column checklist

Derived from the `Villa` form definition in `Admin.ds:781–1320` (51 data fields),
diffed against `master-data/All_Villas.csv` (18 columns).

**The Villa form lives in the `admin` application, not `accounts`.** That is where
this export has to be taken from.

## Already in the current export — don't re-add

Villa Name · Active · Head Office · Max Occ (member) · BHK · Bathroom · Category ·
Primary · Ekostay ID · Haewaya ID · Rent Type · Primary Villa · Secondary Villa
(plus Location, State, ID, Modified Time, Added Time)

## Add these — 25 real fields

Tick them off as columns on the temporary report.

### Load-bearing — the reason this export matters
- [ ] **Hide From Payments** `Hide_From_Payments` (checkbox) — Bills and Payments
      both filter on `Hide_From_Payments == false` (§3.1). Without it we cannot
      reproduce which villas are even selectable.
- [ ] **Status** `Status` (picklist) — the third of the three overlapping active
      flags. §3.1 flags the overlap as an open `[TODO]`; we currently have `Active`
      only, so the question is unanswerable.

### Commercial — needed for §5.1 splits and owner payouts
- [ ] Expense Base Amount `Expense_Base_Amount` (INR)
- [ ] GST % `GST` (percentage)
- [ ] Revenue split for Owner `Revenue_split_for_Owner`
- [ ] Expenses split for Owner `Expenses_split_for_Owner`
- [ ] F&B Revenue split for Owner `F_B_Revenue_split_for_Owner`
- [ ] F&B Expenses split for Owner `F_B_Expenses_split_for_Owner`

> These also settle §3.1's open question — "Owner splits are hidden for EKOSTAY
> types; where are splits stored for those two?" With 0 EKOSTAY villas in the data
> (addendum §15) the answer may simply be "nowhere yet", but the columns will show
> whether values exist on the 65 Revenue Share villas only.

### Category scoping — mechanism A vs B, §3.1's "do not implement all of them"
- [ ] Item Category to `Item_Category_to` (Include/Exclude)
- [ ] Master Category `Master_Category` (list)
- [ ] Item Category `Item_Category` (list)
- [ ] F&B Item Category to `F_B_Item_Category_to`
- [ ] F&B Master Category `F_B_Master_Category`
- [ ] F&B Item Category `F_B_Item_Category`
- [ ] Type `Type_field` (Include/Exclude)
- [ ] Include Item Category `Include_Item_Category` (list)
- [ ] Exclude Item Category `Exclude_Item_Category` (list)

> This is the single most valuable group after the two load-bearing flags. §3.1
> asks which of the three scoping mechanisms is actually live and says **do not
> implement all of them**. Counting how many villas populate each set answers it
> directly: the dead mechanisms will be empty on all 254.

### People and misc
- [ ] Caretaker Name `Caretaker_Name`
- [ ] Caretaker Number `Caretaker_Number`
- [ ] Manager Name `Manager_Name`
- [ ] Manager Number `Manager_Number`
- [ ] Owner Name `Owner_Name`
- [ ] Owner Number `Owner_Number`
- [ ] Inner Circle `Inner_Circle` (checkbox)
- [ ] Date `Date_field`

Skip `Documents` (file upload) and the two `plaintext` layout blocks — no data.

## Two grids will NOT come through

| Grid | Inner fields |
|---|---|
| **Villa Managers** `Villa_Managers` | Primary (checkbox) · Manager Name · Phone · Email |
| **Owner Details** `Owner_Details` | Owner Name · Owner Number · Owner email |

A report export flattens or drops subforms — exactly as the `Approvers` grid was
reduced to a single column of level names on All Approvals (addendum §11). For
these, a screenshot of one villa's detail panel is worth more than any export.

## Method

1. In the **Admin** app builder, create a **new report** on the `Villa` form.
   Do not edit `All Villas` — the team uses it, and column changes are visible to
   everyone.
2. Add every column above.
3. Export as **JSON**. Not CSV-through-a-spreadsheet: villa names carry leading and
   doubled spaces, and `Haewaya ID` is a comma-packed list that a spreadsheet will
   mangle.
4. Run `php artisan export:check <file>` before sending.
5. Delete the temporary report.

If a report cannot hold that many columns, split it in two — the identity columns
plus one half each — and send both. They join on `ID`.
