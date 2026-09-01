# Analytics views needed for F&B — 31-Aug-2026

What to ask Tushar for, derived from `F_B.ds` and checked against the 22 tables
already built. Creator declares **21 F&B reports**; `ZohoViews` currently knows
**one** F&B view, and its columns are expense-shaped rather than form-shaped.

Format for each: the Analytics **view name** and its **numeric view id**, plus which
**workspace** it sits in. A bare id is not enough — the guide's §6 lists two
workspaces and the ids are not interchangeable, and a raw numeric id resolves
against the default (`accounts`) and fails for the wrong reason.

---

## Priority 1 — the four that unblock testing with real volume

These four already have CSV exports sitting in `master-data/`, so an Analytics view
would let them **sync** rather than be hand-imported. That is the difference between
a one-off snapshot and something that refreshes.

| Creator report | Rows (CSV) | Our table |
|---|---|---|
| `All_Vendor_Order_Bookings` | 11,205 | `fnb_vendor_order_bookings` |
| `All_Vendor_Order_Booking_Items` | 110,510 | `fnb_vendor_order_booking_items` |
| `All_Raw_Material_Requests` | 160,995 | `fnb_raw_material_requests` |
| `Request_Stock_for_Food_Report` | 4,328 | `fnb_request_stock_for_foods` |

⚠️ **Three of these will be `large`** — over 100k rows. `bookings` (~114k) and
`booking_payment_type` (~221k) already OOM'd the other team's server as JSON, and
our own `fnb` view exhausted a 128MB limit on 27,950 rows because it was not
flagged. So `All_Raw_Material_Requests` and `All_Vendor_Order_Booking_Items` must be
registered `'large' => true` from the start.

⚠️ **`Request_Stock_for_Food_Report` carries GUEST NAMES.** Real PII beside villa
and stay dates. It needs authorisation on any endpoint that reads it, and this app
has none yet.

---

## Priority 2 — nine tables with NO data source at all

Built, constrained, empty, and no export exists for any of them. These are the ones
where an Analytics view is the *only* route short of a manual export.

| Creator report | Our table | Why it matters |
|---|---|---|
| `All_Transaction_Items` | `fnb_transaction_items` | **the stock ledger** — every movement in, out, transferred, damaged, reversed. The most valuable of the nine. |
| `All_Transfer_Items` | `fnb_transfer_items` | warehouse to warehouse |
| `All_Inventory_Stocks` | `fnb_inventory_stocks` | the dated stock rows under each Inventory |
| `All_Vendor_Price_Lists` | `fnb_vendor_price_lists` | price per vendor per item — how an order gets its price |
| `All_Chef_Masters` | `fnb_chef_masters` | ⚠️ PII: name, phone, email, address |
| `All_Recipe_Masters` | `fnb_recipe_masters` | |
| `Requirements_of_Recipe_Report` | `fnb_recipe_requirements` | |
| `All_Monthly_Checks` | `fnb_monthly_checks` | stock counts |
| `All_Food_Order_Details` | `fnb_food_order_details` | what a booking ordered to eat |
| `All_Block_Booking_Dates` | `fnb_block_booking_dates` | one date; a screenshot would do |

**`All_Transaction_Items` is the one to get first** if only one is available. It is
the stock ledger, it is the only table that can prove `fnb_inventories.available_qty`
is correct, and `Transaction_Type` includes `Reverse` — so it also shows how
corrections are actually made in practice.

---

## Priority 3 — two we may not need, and one that is not ours

**`All_Auto_Numbers`** — a *screenshot* is better than a view here. It is one row,
and what matters is the current counter value at a moment in time. Our counters are
derived from the export maxima (`EKO/F&BOrder` 11,435, `EKO/Stock` 4,527) and
`FnbNumber::allocate()` refuses while it is behind the observed value. A fresh
reading re-arms it; a synced view of a single row does not add much.

**`All_Item_Masters` / `UOM_Report` / `All_Inventories` / `All_Warehouses`** — already
seeded from CSV and stable. A view would only be worth it for refresh.

**`All_Bookings` and `All_Expenses` are NOT wanted here.**

- `fb.Booking` (46 fields) is the **stay-booking master for the whole business**, not
  an F&B table. `ZohoViews` already has a `bookings` view in the `live` workspace
  (~114k rows). It belongs to whichever app owns bookings.
- `fb.Expenses` (38 fields) — Accounts already has an `expenses` table and F&B
  expense rows are created by `accounts.FB.Accounts()` posting into that ledger.
  Two tables would be two answers. **This needs a decision before a view is useful.**

---

## Grids: probably no separate view

Nineteen of the F&B fields are `grid`s — child tables rendered inside a parent form.
Some have their own report (`Vendor_Order_Booking_Item` does, and its CSV exists),
but several are just a filtered view of a table we already have:

```
Requirements_of_Recipe   4 grids   all filtered views of Item_Master
Monthly_Check            Items_List        -> Item_Master
Transfer_Items           Items             -> Item_Master
Warehouse                Inventory_Items   -> Inventory
Item_Master              Price_List        -> Vendor_Price_List
```

So **do not ask for a view per grid.** Ask for the parent table's report and the
grid becomes a query. The three `Raw_Materials` / `_Partial` / `_Completed` grids on
`Request_Stock_for_Food` are all the same table filtered by state — one view covers
all three.

---

## Before any of this is scheduled

**The export concurrency limit is account-wide and shared with a live production
app.** A collision once stalled both apps' syncs for two days. The expense tracker
owns minutes `:00 :12 :24 :42 :48`, and `ZohoViews::assertScheduleIsClear()` refuses
them with tests behind it — **but the guard cannot see their job table**, so any
schedule must be agreed with Tushar rather than assumed clear.

Manual `php artisan zoho:inspect <view>` runs are fine; they hold a slot for
seconds.

**Inspect before importing.** §11 measured that field key names are per-view and
unpredictable — `Payment No.` / `Payment` / `payment_no` for one field — and the
other team's conclusion was they "could never predict it, only discover it per
view". `zoho:inspect` writes nothing to the database and reports the real keys.

**Never import from a one-row-per-parent view.** §12 measured that Analytics
FLATTENS multi-value fields to one silently-chosen value. We have already seen it
here: `fb.Warehouse.Villa_Name` is a multi-value list and it flattened to **nothing**
on all 855 inventory rows, which is why `fnb_warehouse_villas` is empty. Import the
child rows.

---

## The short version to send

> For F&B I need the Analytics view name + numeric id + workspace for these, in
> priority order:
>
> 1. `All_Transaction_Items` — the stock ledger, nothing else can prove stock is right
> 2. `All_Vendor_Order_Bookings` and `All_Vendor_Order_Booking_Items`
> 3. `All_Raw_Material_Requests` and `Request_Stock_for_Food_Report`
> 4. `All_Inventory_Stocks`, `All_Vendor_Price_Lists`, `All_Transfer_Items`
> 5. `All_Chef_Masters`, `All_Recipe_Masters`, `Requirements_of_Recipe_Report`,
>    `All_Monthly_Checks`, `All_Food_Order_Details`, `All_Block_Booking_Dates`
>
> Flag which are over ~50k rows so they go on the CSV streaming path from the start.
>
> Not needed: `All_Bookings` (another app owns it), `All_Expenses` (needs a decision
> first), `All_Auto_Numbers` (a screenshot is more useful).
