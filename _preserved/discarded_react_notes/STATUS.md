# Status — 19-Aug-2026

A doc audit, not a plan. What is built, what is verified, what is genuinely left.

```bash
npm test                  # 104 payroll · 30 schedule · 3 migrations, 15 invariants
node tools/capture.mjs    # 6 modules render, 0 console errors
node tools/test-rail.mjs  # 7 rail assertions   (needs the dev server)
```

## Built and verified

| | |
|---|---|
| **6 Creator replicas** | 7,164 lines. All screenshot-verified. Rail navigates. |
| **Payroll engine** | `payroll/` — 104 tests pinned to Ahmed's live June/July rows |
| **Instalment generator** | `schedule/` — 30 tests, month-end clamping, leap rules |
| **Schema** | `backend/schema/` — 38 tables, 429 constraints, 49 indexes |
| **Creator source** | all three DS exports transcribed to `zoho_source/` |
| **Live-server validation** | `DB_FINDINGS.md` — 10 findings, 4 schema corrections |

## The one unverified step

**The migrations have never run against a live PostgreSQL.** There is none on this
machine and no Docker. They parse against a real PostgreSQL grammar and pass 15
asserted invariants, but a parser does not prove a `CHECK` expression is immutable
enough for a constraint, or that a trigger function compiles.

Give me a scratch database — or install Postgres locally — and this closes in an
afternoon. Everything downstream of it (import scripts, API) is wasted effort if
the DDL moves.

## Not built

**No API layer.** The modules run on in-memory fixtures; edits vanish on refresh.
This is the real gap between "six working screens" and "an app". Context doc §17
step 5 — the only step in that build order not done.

**Six Creator screens have no module:** Expense Observations (spec at §13), Bank,
Accounts (the landing dashboard), Masters beyond Vendor Master, Pending Approvals,
Settings.

## Doc state after this audit

`ACCOUNTS_REBUILD_CONTEXT.md` carried 41 `[TODO]`s. Sixteen were already answered
by later work but never marked, which made the doc read as far more open than it is.

- **13 → `[RESOLVED]`**, each annotated in place with the answer and where it came
  from. Deleting them would have thrown away the reasoning, which is usually the
  useful part.
- **3 → `[ANSWERED]`** from live data during this audit (below).
- **25 remain.** All genuine, none blocking: they are questions about Creator's
  intent, not about what it does. Copy-as-built means the rebuild does not need
  them answered to be faithful.

`UI_HANDOFF.md` §5 and §6 were rewritten — §5 still listed the three rebuilt
modules as "do not ship", and §6 listed five outstanding items of which three are
now answered.

## Answered from live data during this audit

**§11.3 — no staff are filed in an unhandled state.** Every location carrying
`SALARY` rows maps to a state the engine implements: Maharashtra (Alibaug,
Lonavala, Head Office Central, Karjat, Igatpuri, Panchgani, Wada), Tamil Nadu
(Ooty and Coonoor, Kodaikanal), Goa. Karnataka and Uttarakhand have rules but no
payroll rows — Chikmagalur and Mussoorie carry none. **The PT coverage is
complete.**

**§13B — the villa `Name- Locality` pattern is not a region marker.** 34 villas use
it, and it is not Goa-only: Goa 22, Lonavala 6, Alibaug 2, Karjat 2, Panvel 2. So
parsing the suffix to derive location would be wrong. Join on `location_id`.

**§9.1 — Backend Expenses is machine-written only.** 63,335 rows from exactly two
sources, both automated. Consistent with a sync landing table, though it does not
prove nobody reads the screen.

## Outstanding for Husain — three, none blocking

1. **The four action buttons** on All Scheduled Payments — `Create Payment`, `Due`,
   `Click to Proceed`, `Paid`. Never seen rendered, so their placement in our
   replica is a guess; every row in the screenshots was already Paid.
2. **Which vendor-merge field is authoritative** (§13A.1). A person can exist in
   both `Vendor_Master` and `Employee_Master` with different data.
3. **Does anyone open Backend Expenses**, or is it purely a sync target?

## Outside the rebuild, still live

**The delete webhook is failing in production.** Creator sends
`X-Zoho-Token: "PUT_THE_ROTATED_TOKEN_HERE"`; a real 48-character token is
configured, so every call 401s. Verified 13-Aug: four bad-token warnings, and
three of the four rows in the deletion log came from the server's own IP — manual
tests, not Creator. **No delete notification from Creator has ever succeeded.** One
paste fixes it.

**Payment numbers collide, today.** 233 numbers shared by 494 rows in the
settlement system, 229 of them Haewaya. `EKS/Haewaya/12539` covers six villas, six
categories and six dates two weeks apart. Fresh duplicates dated 13-Aug. The new
schema makes this impossible going forward via `UNIQUE (series_code, series_seq)`,
but Creator will keep generating them until its counter is fixed.

**35 `payouts` rows where `payable > invoice`** — impossible under
`payable = invoice − tds`. Probably the salary path. One query when convenient.

## What I would do next

**Run the migrations against PostgreSQL**, then build the API layer. In that order:
an import script or endpoint written against unverified DDL is work you may have to
redo.
