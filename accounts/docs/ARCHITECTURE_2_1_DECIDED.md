# §2.1 — decided 29-Aug-2026: replace the cluster

`ACCOUNTS_REBUILD_CONTEXT.md` §2.1 carried this `[TODO]`:

> Does the rebuild replace this whole cluster, or keep calling the remaining Creator
> apps over API? Scoping decision, not implementation detail.

**Answer: replace it.** Husain's condition — *"all 7 apps will probably live under one
domain but in sub sections"* — is precisely what this option produces, and the DS says
it is the only shape that actually works.

This lifts the §2.1 gate on the F&B write path.

---

## Why the DS makes it the only workable answer

The dependency was described as F&B depending on Accounts. **Measured, it runs both
ways and it is heavier in the other direction:**

| | calls |
|---|---|
| `fb` → `accounts` | 47 |
| **`accounts` → `fb`** | **63** |
| `fb` → `admin` | 29 |

And `accounts.FB.*` is not a table read — it is **19 calls to functions that live in
Accounts and exist only to serve F&B**: `FB.Accounts`, `FB.BillingCycle`,
`FB.ItemCatVendor`, `FB.Vendor`. Accounts already carries an F&B-shaped API surface
inside itself.

An API boundary between these two would mean **110 network round-trips** replacing
what are currently in-process function calls, in both directions, on the hot path of
every form. That is not an integration; it is a distributed system built by accident.

## What "one domain, sub-sections" means concretely

One Laravel application, one PostgreSQL schema, one login, one deployment:

```
app.ekostay.com/accounts     built — 44 tables, real data
app.ekostay.com/fnb          this session's job
app.ekostay.com/admin        villas, locations, employees — already seeded here
app.ekostay.com/…            villa operation, ERS, the rest
```

The masters are already shared, not duplicated. `master_categories.fb` is `true` on
`F&B` alone of the 10 rows, seeded and verified in our database — and **that flag is
how F&B scopes its own vendors and categories.** It lives on the Accounts table. Two
apps, one row.

So `accounts.Vendor_Master[Master_Category.F_B == true]` stops being a cross-app call
and becomes a `WHERE` clause. `fb.Booking` stops being a remote lookup and becomes a
join. This is what §2.1's "one schema" conclusion already implied.

## What this does NOT mean

**Not a big-bang rewrite.** Apps come out one at a time, each only when what it depends
on is already out. Accounts is out. F&B is next because its masters are the ones already
seeded. Villa Operation and ERS can wait months.

**Creator keeps running** until the app that replaces it is verified. Nothing is switched
off to make a deadline.

**No stubbing.** CLAUDE.md is explicit that neither app can be built against a stub of
the other. With one schema, no stub is needed — the tables are simply there.

## What changes for F&B, starting now

- The seven `accounts.*` read shapes become **Eloquent relations**, not HTTP clients.
- The 63 `accounts → fb` calls become **the same thing from the other side** — and they
  are the reason the F&B tables belong in this schema rather than a separate database.
- F&B's own `Auto_Numbers` (`Booking_Series`, `Request_Series`,
  `Vendor_Booking_Series`) stays **separate from Accounts'** payment counter. Same
  table pattern, different rows. Creator keeps two singletons and so do we.
- The write path is **unblocked**, with §17's other limits still standing: no approval
  engine, no Books push in the first pass.

## Still true regardless

`DeleteAllRecords()` at `F_B.ds:4637` is **never reproduced**. Accounts already set the
pattern — `Delete Paid Payment` became a reversing entry guarded on the model, so none
of the 14 unguarded `delete from Payment` sites in its DS could be copied (D4). F&B gets
the same treatment.
