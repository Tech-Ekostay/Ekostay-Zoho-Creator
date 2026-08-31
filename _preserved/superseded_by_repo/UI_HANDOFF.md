# Ekostay Accounts — UI Handoff

**For a fresh Claude chat continuing the interface work.**
Read this before writing any code. Companion document: `ACCOUNTS_REBUILD_CONTEXT.md`
(the full functional spec, 1,300 lines — the authority on behaviour, data and defects).

---

## 1. What this project is

Ekostay runs 150+ villa rentals across Maharashtra, Goa, Tamil Nadu, Karnataka and
Uttarakhand. Their accounting runs on **Zoho Creator** and has hit platform limits. We are
rebuilding the **Accounts** application: UI designed in Claude chat, functionality built in
Claude Code from `ACCOUNTS_REBUILD_CONTEXT.md`.

The person you are working with is **Husain Khatumdi** (founder). Source material available:
three Deluge Script exports — `Accounts.ds` (59,063 lines, 46 forms), `Admin.ds` (4,162 lines,
10 forms), `F_B.ds` (21,994 lines, 21 forms) — plus live-UI screenshots supplied per module.

---

## 2. ⚠️ The single most important instruction

**Replicate the Creator screens. Do not redesign them.**

An earlier attempt reorganised the screens into a "better" information architecture — renamed
labels, collapsed panels, grouped fields by task, added a role switcher, replaced statuses
with clearer wording. **It was rejected.** Husain's words:

> "the name of the fields has to be the same as you are seeing in creator right now. My point
> of talking about the ui is that it should be readable and user friendly. Right now there are
> 20 rows but its easier for the team to identify the columns so I just want to replicate the
> exact same structure right now then later on we can make the changes."

So the brief is: **same structure, same labels, same column order, same density — rendered
cleanly and legibly.** Improvements come later, on his signal.

### Concrete rules

1. **Field labels verbatim** from the Creator form. `Bill No` not "Bill no". `Item Category`
   not "Category". `COA` not "Chart of account". `Villas` not "Villa". `Head Office`,
   `Amount Category`, `Split Payment`, `Gross Amount`, `Payable Amount`, `Split Equally`,
   `Adjusted Amount`, `Billing Year`, `Billing Months`, `Billing Cycles`, `Main Primary`,
   `GST No.`, `PAN No.` — exactly as they appear.
2. **Column order as the report shows it**, including the eye and checkbox columns, and any
   per-row action button (`Create Payment`, `Update`, `Update Expense`).
3. **Section names verbatim** — `Overview`, `Commercials`, `Amount Category`, `Split Payment`,
   `Employee Details`, `Account Details`, `Merge Vendor`.
4. **~20+ rows visible** without scrolling. Row height ~27px. Horizontal scroll for the full
   column set, as Creator does. Do not trade rows for whitespace.
5. **Dates render `dd-MMM-yyyy`** (`16-Jul-2026`). Use a text input with a calendar glyph, not
   `<input type="date">` — the native picker renders mm/dd/yyyy.
6. **Currency renders `₹ ##,##,###.##`** with Indian digit grouping (`₹ 2,68,000.00`).
7. **Preserve source spellings.** `Luxery`, `ACCOMODATION`, `Maintaince`, `multipe_hccc_names`,
   `stafffuel`, `Payment InProgress` — these are keys downstream. Normalise at display only,
   never silently in data.
8. Footer reads `Showing N of M`.
9. Zoho chrome: navy rail with pink active state, pink `＋` add button, pink primary buttons,
   `SEARCH` chip with a field selector + `contains` + value.

### What may be added

Only things that prevent data loss or surface an existing rule earlier. So far, and accepted:

- Split rows **reconcile** rather than clear when Villas / Item Category / Billing Cycles
  change, so typed amounts survive (§5.1 of the context doc)
- A running `₹ x of ₹ y` tally on the Split Payment header, so a mismatch is visible before
  Submit rather than as an alert at it
- A duplicate-name check on Vendor Master pointing at Merge Vendor
- Exactly-one-Primary enforcement on the Account Details grid

Flag anything else as a suggestion; don't ship it unasked.

---

## 3. The design system in use

Shared across the three finished modules. Copy the `Style()` function from `BillsModule.jsx`.

```css
--rail:#2b2f4a  --rail2:#383d5e            /* navy nav rail */
--pink:#e4407f  --pinkd:#c72e69  --pinkl:#fdeef4   /* Zoho pink: logo, active nav, buttons, links */
--ink:#20242e  --ink2:#4a5160  --ink3:#7b8494  --ink4:#a8afbb
--line:#e6e9ee  --line2:#d2d7df  --bg:#f4f5f7  --white:#fff
--ok:#0f7b5f / #e9f6f1   --bad:#c0392b / #fdeceb
--warn:#9a6206 / #fdf3e2 --info:#2b5fa8 / #eaf1fb
font: 'Inter' 13px · figures in 'Roboto Mono' 12px with tabular-nums
```

Layout: `grid-template-columns: 104px minmax(0,1fr)`, rail then main. Main is a flex column —
appbar 42px, reportbar, optional search row, scrolling grid, 28px footer. Detail opens as a
right-hand panel `min(700px, 58vw)`. Forms open as a centred modal `min(1220px, 100%)`.

Class prefix is `zc-` throughout. Grid rows 27px, headers 31px sticky, 1px borders on both
axes, even rows `#fafbfc`, hover and selected `--pinkl`.

---

## 4. Verify by rendering. Every time.

The earlier failure came from writing ~4,000 lines of CSS without once looking at it. **Do not
present UI you have not seen.** The container supports it:

```bash
# one-time setup
cd /home/claude && npm install --silent esbuild lucide-react react react-dom
ln -sfn /home/claude/.npm-global/lib/node_modules/playwright node_modules/playwright
mkdir -p shot
cat > shot/entry.jsx <<'EOF'
import React from "react";
import { createRoot } from "react-dom/client";
import App from "./App.jsx";
createRoot(document.getElementById("root")).render(<App />);
EOF
cat > shot/index.html <<'EOF'
<!doctype html><html><head><meta charset="utf-8"><style>html,body{margin:0}</style></head>
<body><div id="root"></div><script src="bundle.js"></script></body></html>
EOF

# per iteration
cp /mnt/user-data/outputs/YourModule.jsx shot/App.jsx
./node_modules/.bin/esbuild shot/entry.jsx --bundle --outfile=shot/bundle.js \
  --loader:.jsx=jsx --jsx=automatic
node cap.mjs        # playwright: 1600×1000, deviceScaleFactor 2, screenshot list/detail/form
```
Then `view` the PNGs and critique your own output before showing it.

A JSX syntax check is also worth having, since a parse error wastes a whole turn:
```js
import { parse } from "@babel/parser";   // npm i @babel/parser
parse(readFileSync(f,"utf8"), { sourceType:"module", plugins:["jsx"] });
```

**Faults this loop caught that static review did not:** alphabetical sort on a
`"30-Jun-2026 17:45:03"` timestamp string; negative Payable on settled bills feeding a
headline total; a green "reconciled" badge on an empty form; native date inputs rendering
mm/dd/yyyy.

---

## 5. Where the work stands

### All six Creator screens are replicas — as of 19-Aug-2026

| File | Lines | Rebuilt |
|---|---|---|
| `BillsModule.jsx` | 977 | — |
| `PaymentsModule.jsx` | 1,666 | 13-Aug |
| `SchedulePaymentsModule.jsx` | 1,550 | 13-Aug |
| `SalaryPayoutsModule.jsx` | 1,445 | 13-Aug — imports `ekostay-payroll` |
| `VendorMasterModule.jsx` | 942 | — |
| `BackendExpensesModule.jsx` | 584 | — |

The three rebuilt in August replaced earlier redesigns that predated the
replicate-Creator decision. Each was screenshot-verified before being presented.

**The nav rail is live** (fixed 19-Aug). It shipped as `<button>` elements with no
handlers — hovering, looking interactive, doing nothing. Navigation now belongs to
the shell and is passed to each module as `onNavigate`; unbuilt labels say so
rather than failing silently. Guarded by `tools/test-rail.mjs`.

### Not built — Creator shows these, we have not

Expense Observations (spec at context doc §13) · Bank · Accounts (the first nav
item) · Masters beyond Vendor Master · Pending Approvals · Settings

## 6. Still outstanding from Husain

Three of the original five are answered. What remains:

1. **The four action buttons** on the All Scheduled Payments report — `Create
   Payment`, `Due`, `Click to Proceed`, `Paid`. Never seen rendered, so their
   placement in our replica is a guess. Every row in the screenshots was already
   Paid.
2. **Which of the three vendor-merge fields is authoritative** (§13A.1). A person
   can currently exist in both `Vendor_Master` and `Employee_Master` with
   different data.
3. **Does a human ever open Backend Expenses**, or is it purely a sync landing
   table? Live data shows only machine writes (2 sources, both automated), which
   is consistent with the latter but does not prove nobody reads it.

### Answered since

- ✅ **Blueprints and Approvals** — checked 13-Aug. **Empty** in both Accounts and
  F&B, so the Deluge is the whole story and §8's design stands. This had been the
  one gap that could have invalidated a section.
- ✅ **`Matched_Payments` on Backend Expenses** — both fields kept as the source
  has them (§A6). Dropping a column on a guess is not reversible.
- ✅ **The role → permission matrix** — extracted in full to
  `zoho_source/Accounts_PERMISSIONS.md`: 17 profiles, per-form, per-report and
  per-field grants.

## 7. How he works, and what he values

- Sends screenshots per module, then expects a replica built from them plus the DS
- Says when something is wrong, directly. Take it at face value and fix the specific thing
- Wants the `.md` kept current — it is the real deliverable, since the JSX is regenerable
- Appreciates findings from the code he didn't know about: bugs, duplicated fields, packed
  strings, dangerous deletes. Report them alongside the build, with the evidence
- Corrections matter. When an earlier claim turns out wrong, say so plainly — several
  conclusions in this project were revised after better evidence (the "zero splits" that were
  a display bug; the days-worked field that was a naming error, not a sign error)
