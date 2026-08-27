# Ekostay Accounts — rebuild package

Everything needed to build the Accounts application from scratch.

## Read in this order

1. **`UI_HANDOFF.md`** — how to work on this project, the design system, the
   verify-by-rendering loop, current status, open questions. Start here.
2. **`ACCOUNTS_CONTEXT_ADDENDUM.md`** — corrections to the functional spec, the
   modules verified from live screenshots, and the findings register.
3. **`ACCOUNTS_REBUILD_CONTEXT.md`** — the original functional spec (1,314 lines).
   Authority on behaviour, data flow and the 38-defect register, **except where
   the addendum corrects it.**

## Contents

```
UI_HANDOFF.md                    handoff v2 — supersedes the original
ACCOUNTS_CONTEXT_ADDENDUM.md     corrections + new module specs + findings
ACCOUNTS_REBUILD_CONTEXT.md      original functional spec

master-data/                     REAL data, exported from Creator 12-Aug-2026
  All_Master_Categories.json       10 records
  All_Item_Categories.json        135 records
  All_Approvals.json                9 records
  TDS_Report.json                  35 records
  All_Taxes.json                    8 records
  COA_Report.json                 144 records
  Auto_Numbers.json                 1 record
  Villa_Master_recovered.json     204 villa names, recovered from All_Approvals
  Location_Master_recovered.json   10 locations
  _index.json                     record counts and column lists

SettingsModule.jsx               Settings — 8 reports behind a flyout
PaymentsModule.jsx               Payments — list, detail, form, reverse dialog
PendingApprovalsModule.jsx       Pending Approvals — 24 columns
PaymentRequestsModule.jsx        Payment Requests — 3 views
BackendExpensesModule.jsx        Backend Expenses — 31 columns, 135 fields
BackendPaymentsModule.jsx        Backend Payments — form only
BillsModule.jsx                  Bills          ) built earlier; need the chrome
VendorMasterModule.jsx           Vendor Master  ) corrections in handoff §3
SchedulePaymentsModule.jsx       ⚠️ old redesign — DO NOT SHIP, rebuild
SalaryPayoutsModule.jsx          ⚠️ old redesign — DO NOT SHIP, but the payroll
                                    engine inside it is correct, keep that
```

## What the JSX is and is not

These are **reference replicas**, not the application. Each is a single
self-contained React file with inline seed data and an inline `Style()` block, so
it renders standalone in a screenshot harness. They exist to pin down exact column
order, field order, labels, control types, formatting and per-screen quirks.

For the real build, take from them: the column and field orders, the labels, the
formatting helpers (Indian digit grouping, `dd-MMM-yyyy`), the control behaviours,
and the seed data where it is real. Do not carry over the single-file structure or
the duplicated `Style()` blocks.

Target stack is Laravel 11 / PHP 8.2+ / PostgreSQL 15+.

## Two things to get right before writing much code

**Seed from `master-data/`, not from the JSX.** Some seed arrays in the modules
are marked synthetic where the real data was not available. The JSON is verbatim.
Record IDs are 18 digits — keep them as strings, they lose precision as JS numbers
and as anything narrower than `bigint`.

**Preserve the dirty data.** Trailing spaces, duplicate names, mixed casing and
misspellings in the masters are live keys. Normalising them on import will break
joins that currently work. The addendum lists them; fix them deliberately with a
migration and a mapping table, not silently on the way in.
