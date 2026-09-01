# F&B — session start, 27-Aug-2026

This session's job: **build the F&B section.** The prior React prototype was
discarded and this repo (`Tech-Ekostay/Ekostay-Zoho-Creator`, commit `d09a38d`)
replaced it.

Read in this order: `accounts/CLAUDE.md` (the operating manual) → `fnb/README.md`
(what the F&B DS says) → `accounts/SETUP_NOTES_THIS_MACHINE.md` (this machine's
deviations) → `_preserved/README.md` (what survived the prior session).

---

## Where things stand

| | |
|---|---|
| Repo installed | ✅ commit `d09a38d`, working tree clean apart from added notes |
| `composer install` | ✅ 81 packages, on PHP 8.5.6 |
| `npm install` + `npm run build` | ✅ 130 packages; 40 modules, ~1.1s |
| `npm run dev` | ✅ **works here** — Node 24, so HMR is available |
| `.env` | ✅ reconstructed (template was missing from the repo) |
| `pdo_pgsql` | ✅ enabled in `php.ini` |
| Laravel boots | ✅ 12.67.0 |
| **PostgreSQL** | ✅ **17.11 running** — ZIP install, see `accounts/docs/POSTGRES_LOCAL.md` |
| `php artisan migrate --seed` | ✅ 30 migrations, 44 tables, real masters seeded |
| `php artisan test` | ✅ **136 passed, 1,008 assertions** |
| App serves | ✅ `http://127.0.0.1:8000` — 135 rows, no console errors |
| `F_B.ds` | ✅ **21,994 lines, recovered** — `fnb/deluge/F_B.ds` |
| `Accounts.ds` / `Admin.ds` | ✅ recovered — `accounts/deluge/` |
| Data seeded | ✅ vendors 8,161 · employees 475 · villas 254 |
| `fnb/` code | 🔨 in progress |

The Accounts app is fully running against real seeded data. Nothing blocks F&B work.

### PostgreSQL is installed and running

`winget` fails on EnterpriseDB's 403 for the `.exe`, but **the ZIP archive of the
same 17.11 returns 200**, so the binaries were fetched and a cluster initialised by
hand. Full detail, including two extraction traps, in
`accounts/docs/POSTGRES_LOCAL.md`.

**There is no Windows service** — start it after a reboot:

```bash
export PGBIN="/c/pgsql_dl/x/pgsql/bin"
"$PGBIN/pg_ctl.exe" -D "C:/pgsql_dl/data" -l "C:/pgsql_dl/pg.log" -o "-p 5432" start
"$PGBIN/pg_isready.exe" -h 127.0.0.1 -p 5432
```

Both databases exist, owned by `ekostay`, both `LC_COLLATE 'C'`, and the Accounts
app runs against them.

---

## `F_B.ds` is here — 21,994 lines

**Recovered 27-Aug-2026** at `fnb/deluge/F_B.ds`. It had been attached to this
session as a document and never written to disk; the transcript still held it. A
complete Creator export, generated 13-Aug-2026 12:34:53.

Verified genuine against `fnb/README.md`, which was written by someone reading a
different copy. Verified: **exactly 47 cross-app calls** into `accounts.`, and `DeleteAllRecords()`
wiping exactly **14 tables**, unguarded.

**But the form count in `fnb/README.md` and the root `README.md` is wrong.** Both say
84 forms; the DS holds **21 distinct forms and 21 distinct reports**. Each declaration
appears four times — once in the form block, three more in the i18n dictionary — so a
naive grep counts 84. `ACCOUNTS_REBUILD_CONTEXT.md` §18's "21 forms" was right all
along.

One offset to carry: `DeleteAllRecords()` is at line **4637** in this copy, not the
4645 the README cites. Cite 4637 here.

`Accounts.ds` (62,264 lines) and `Admin.ds` (4,190) were recovered the same way and
sit in `accounts/deluge/`. Details and the credential warning are in
`accounts/deluge/RECOVERED.md`.

**So there is no source-material blocker left.** F&B logic can be traced to a real
export rather than inferred from a summary.

---

## What F&B actually is

Not accounting — **stock and kitchen**. **21 forms, 21 reports:**

| Area | Forms |
|---|---|
| Orders | `Booking`, `Food_Order_Details`, `Vendor_Order_Booking`, `Vendor_Order_Booking_Item` |
| Stock | `Inventory`, `Inventory_Stock`, `Warehouse`, `Transfer_Items`, `Transaction_Items` |
| Requests | `Raw_Material_Request`, `Request_Stock_for_Food`, `Requirements_of_Recipe` |
| Masters | `Item_Master`, `Chef_Master`, `Recipe_Master`, `Vendor_Price_List`, `UOM` |
| Control | `Auto_Numbers`, `Block_Booking_Date`, `Monthly_Check`, `Expenses` |

Its own `Auto_Numbers` singleton, with series that are **not** Accounts':
`Booking_Series`, `Request_Series`, `Vendor_Booking_Series`. And its own
`Block_Booking_Date`, mirroring Accounts' `Block_Payment_Date`.

### It shares masters with Accounts — bidirectionally

47 cross-app calls into `accounts.`, all reads:

```
accounts.Vendor_Master.ID                                  vendors
accounts.Vendor_Master[Master_Category.F_B == true].ID      F&B-scoped vendors
accounts.Item_Category[Master_Category.F_B == true].ID      F&B-scoped categories
accounts.COA[Bank == true].ID                               bank accounts
accounts.Billing_Cycles.ID                                  billing cycles
accounts.Payment.ID / accounts.Expenses_Bills.ID            payment + ledger links
accounts.Tax[Tax_Type == "tax_group"].ID                    tax groups
```

F&B owns no vendor, no chart of accounts, no billing cycle. **`Master_Category.F_B`
is the scoping flag and it lives on the Accounts table** (`master_categories.fb`,
true on `F&B` alone of the 10) — which is why the two apps cannot be built against
stubs of each other, and why they share one schema.

`COA[Bank == true]` matters: the load-bearing flag is **`Bank`, not `Account_Type`**.
Nine COA rows are `Bank = true` without being typed `bank`.

---

## Two things in the F&B DS that must NOT be reproduced

1. **`void DeleteAllRecords()` at `F_B.ds:4637`** wipes 14 tables with
   `delete from <table>[ID != null]` — every row, no guard, no confirmation.
   Standalone Deluge functions are **invocable as REST endpoints**, so it is
   reachable. It reads like a dev reset helper left in production.

   **It stays live — the user confirmed 31-Aug-2026 that removing it cannot be done.**
   So the only defence is on our side: never write it, and guard deletes at the model
   layer as Accounts did (D4).
2. **`manualupdatebooks()`** carries ~220 hardcoded order numbers — a one-off
   backfill left in the codebase.

Accounts already sets the pattern for #1: `Delete Paid Payment` became a reversing
entry, **guarded on the model**, so none of the 14 unguarded `delete from Payment`
sites in its DS could be reproduced (deviation D4).

---

## §2.1 RESOLVED 29-Aug-2026 — replace the cluster

All apps become sub-sections of one domain, one Laravel app, one schema. **The F&B
write-path gate is LIFTED.** See `accounts/docs/ARCHITECTURE_2_1_DECIDED.md`.

Measured from the DS the dependency is bidirectional and heavier the other way — 63
`accounts`→`fb` calls against 47 `fb`→`accounts` — so an API seam was never viable.

The other limits from §17 still stand: no approval engine and no Books push in the
first pass.

---

## What to build, in order — nothing is blocked

1. **Read `F_B.ds` properly** — all 21 forms and 21 reports. Trace the domains, the
   `Auto_Numbers` series, and every one of the 47 cross-app calls to its call site.
2. **Scaffold `fnb/` to mirror `accounts/`** — the same Laravel 12 layout, the same
   `app/Domain/…` split, `snake_case`, IDs as opaque strings. No business logic yet.
3. **Model the Accounts-side reads F&B needs.** All seven call shapes above hit
   tables that already exist, are migrated and are seeded. §2.1 does not block this.
4. **Write down the F&B reference tables** that belong in the shared schema, per
   CLAUDE.md's "Accounts, Admin and the F&B reference tables live in one schema".
5. **Ask for form-level exports** for `Item_Master` and `UOM` — F&B will need them
   the way Accounts needed `master-data/`. The DS gives structure, not records.

## Conventions that are not negotiable

From `accounts/CLAUDE.md`, and they apply to F&B unchanged:

- **Replicate the Creator screens. Do not redesign them.** An earlier attempt
  reorganised the information architecture into something "better" and was
  rejected. Field labels verbatim, column order as the report shows it, ~27px rows,
  dates `dd-MMM-yyyy` via text input (never `<input type="date">`), currency
  `₹ ##,##,###.##`, footer `Showing N of M`.
- **Preserve source spellings.** `ACCOMODATION`, `Maintaince`, `multipe_hccc_names`,
  `stafffuel`, `Payment InProgress`, and the trailing space on
  `F&B STAFF MEDICAL EXPENSE `. Live lookup keys. Normalise at display only.
  (Note: `Luxery` is **stale** on that list — the addendum's §15 found it does not
  occur; the category is spelled `Luxury`. `Uttarakand` is a real misspelling, 7
  villas.)
- **Record IDs are 18-digit strings.** `float()` silently corrupts them
  (`…361075` → `…361100`).
- **Do not remove the `TrimStrings` exemption** for `api/settings/*` in
  `bootstrap/app.php`. `F&B STAFF MEDICAL EXPENSE ` is 26 characters stored and 25
  trimmed. Four tests pin it. Any F&B write path needs the same exemption.
- **Never import from a one-row-per-parent Analytics view — import the child rows.**
  Analytics flattens multi-value fields to one silently-chosen value.
- **Verify by rendering.** Do not present UI you have not looked at.
