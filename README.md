# Ekostay Platform

Two applications being rebuilt out of Zoho Creator, in one repository because they
are not independent: they call each other by direct function reference and share a
schema.

```
accounts/     Laravel 12 + React 19. The Accounts app. Built, running, real data.
fnb/          The F&B app. Not started — scaffold and its DS export only.
```

## Why one repository

`F_B.ds` makes **47 cross-app calls into `accounts.`** — `accounts.Vendor_Master`,
`accounts.Item_Category`, `accounts.COA[Bank == true]`, `accounts.Billing_Cycles`,
`accounts.Payment`, `accounts.Expenses_Bills`, `accounts.Tax`. F&B does not own a
vendor, a chart of accounts or a billing cycle; it reads Accounts' masters.

It runs the other way too. The Accounts Bills form carries an F&B lookup **today**,
and `accounts.Item_Category[Master_Category.F_B == true]` is how F&B scopes its
categories — a flag that lives on the Accounts master. So neither app can be built
against a stub of the other, which is the reason `CLAUDE.md` records that "Accounts,
Admin and the F&B reference tables live in one schema."

Separate directories, one schema, one repo. Splitting them into two repositories
would mean versioning that shared schema in two places.

## accounts/

The working application. Read `accounts/CLAUDE.md` first — it is the project's
operating manual and carries the document-precedence order, the toolchain traps and
the build state.

Real data, imported from Zoho Analytics:

| | |
|---|---|
| payments | 52,638 |
| bills | 17,161 |
| vendors | 8,064 |
| villas | 254 |
| billing cycles | 57 |
| item categories / COA / TDS / taxes | 135 / 144 / 35 / 8 |

**What works:** create a bill and split it, create a payment from a bill (§7.2) or
directly, reverse a settled payment (§7.6), add/edit all five Settings masters, and
the grid → detail → edit flow on every built page.

**What does not:** no status transitions, so nothing moves through approval to Paid;
no expense posting, so the split legs never reach a ledger. 18 of 27 nav screens are
unbuilt. The gaps are enumerated honestly in `accounts/CLAUDE.md`.

## fnb/

Not started. What is here is its **DS export** (`fnb/deluge/F_B.ds`, 21,994 lines)
and what has been read out of it:

- **84 forms**, 21 reports. The domain is stock and kitchen, not accounting:
  `Booking`, `Chef_Master`, `Expenses`, `Food_Order_Details`, `Inventory`,
  `Inventory_Stock`, `Item_Master`, `Monthly_Check`, `Raw_Material_Request`,
  `Recipe_Master`, `Request_Stock_for_Food`, `Transaction_Items`, `Transfer_Items`,
  `UOM`, `Vendor_Order_Booking`, `Vendor_Price_List`, `Warehouse`.
- Its **own** `Auto_Numbers` singleton, with series separate from Accounts':
  `Booking_Series`, `Request_Series`, `Vendor_Booking_Series`.
- A `Block_Booking_Date` form, mirroring Accounts' `Block_Payment_Date`.

### Read before touching F&B

**`void DeleteAllRecords()` at `F_B.ds:4645` wipes 14 tables** with
`delete from <table>[ID != null]` — every row, no guard, and standalone Deluge
functions are invocable as REST endpoints. It reads like a dev reset helper left in
production. It is on the live-defects list in `accounts/CLAUDE.md` and **must not be
reproduced.**

Also in there: `manualupdatebooks()` carries ~220 hardcoded order numbers, a one-off
backfill left in the codebase.

## What is deliberately NOT in this repository

Committing any of these would be hard to undo, so they are ignored by design:

- **`accounts/.env`** — holds the Zoho Analytics `client_secret` and
  `refresh_token`. Those are the expense tracker's shared credentials and should be
  replaced with a client of this app's own.
- **`accounts/deluge/Accounts.ds`** — line 22851 is a **live hardcoded DoubleTick API
  key**. Rotate it before this file is ever committed, even privately.
- **`accounts/storage/app/zoho/`** — ~251 MB of exported production data.
- **`accounts/master-data/Vendor_Master.csv`** — 8,063 real vendors with PANs, GST
  registrations, phone numbers and free-text bank details. Git history is permanent;
  PII in it is not removable in any practical sense.

`fnb/deluge/F_B.ds` is ignored for consistency with `Accounts.ds`, though it holds
no credential of its own.

## Running accounts/

Two things bite every time, both documented in `accounts/CLAUDE.md`:

```bash
# PHP is not on PATH — winget installed no shim
export PATH="$LOCALAPPDATA/Microsoft/WinGet/Packages/PHP.PHP.8.4_Microsoft.Winget.Source_8wekyb3d8bbwe:$PATH"

cd accounts
php artisan serve --host=127.0.0.1 --port=8000
```

`npm run dev` **cannot start** on Node 20.11.1 (Vite 7 wants 20.19+). So there is no
HMR: run `npm run build` after any edit under `resources/`.
