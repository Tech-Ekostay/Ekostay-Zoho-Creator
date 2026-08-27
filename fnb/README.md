# F&B — not started

A placeholder with real contents: this app has not been built, but its Creator
export has been read and what it says is recorded here so the next person does not
start from nothing.

## What exists

- `deluge/F_B.ds` — 21,994 lines, the Creator DS export of 08-Aug-2026. **Git-ignored**
  (see the root `.gitignore`).
- `docs/` — empty. Drop screenshots and form-level exports here.

## What the DS says

**84 forms, 21 reports.** The domain is stock and kitchen, not accounting:

| Area | Forms |
|---|---|
| Orders | `Booking`, `Food_Order_Details`, `Vendor_Order_Booking`, `Vendor_Order_Booking_Item` |
| Stock | `Inventory`, `Inventory_Stock`, `Warehouse`, `Transfer_Items`, `Transaction_Items` |
| Requests | `Raw_Material_Request`, `Request_Stock_for_Food`, `Requirements_of_Recipe` |
| Masters | `Item_Master`, `Chef_Master`, `Recipe_Master`, `Vendor_Price_List`, `UOM` |
| Control | `Auto_Numbers`, `Block_Booking_Date`, `Monthly_Check`, `Expenses` |

It has its **own** `Auto_Numbers` singleton with series that are not Accounts'
(`Booking_Series`, `Request_Series`, `Vendor_Booking_Series`), and its own
`Block_Booking_Date` mirroring Accounts' `Block_Payment_Date`.

## It depends on Accounts, not the reverse

47 cross-app calls into `accounts.`, and they are all reads of Accounts masters:

```
accounts.Vendor_Master.ID                                  vendors
accounts.Vendor_Master[Master_Category.F_B == true].ID     F&B-scoped vendors
accounts.Item_Category[Master_Category.F_B == true].ID     F&B-scoped categories
accounts.COA[Bank == true].ID                              bank accounts
accounts.Billing_Cycles.ID                                 billing cycles
accounts.Payment.ID / accounts.Expenses_Bills.ID           payment + ledger links
accounts.Tax[Tax_Type == "tax_group"].ID                   tax groups
```

Note `Master_Category.F_B` — a boolean on the **Accounts** master category table
(`master_categories.fb`, true on `F&B` alone of the 10). That flag is how F&B scopes
itself, which is why the two apps cannot be built against stubs of each other.

`COA[Bank == true]` is worth flagging: the load-bearing flag is `Bank`, not
`Account_Type`. Nine COA rows are `Bank = true` without being typed `bank`.

## Two things in this DS that must NOT be reproduced

1. **`void DeleteAllRecords()` at `F_B.ds:4645`** wipes 14 tables with
   `delete from <table>[ID != null]` — every row, no guard, no confirmation. Standalone
   Deluge functions are invocable as REST endpoints, so this is reachable. It reads
   like a dev reset helper left in production.

2. **`manualupdatebooks()`** carries ~220 hardcoded order numbers — a one-off backfill
   left in the codebase.

## Before building

`accounts/CLAUDE.md` records that §2.1 is still open: whether the rebuild replaces the
whole Creator cluster or keeps calling the surviving apps over API. That question
**blocks any F&B write path** and is not ours to decide. Reads can be modelled;
writes wait.
