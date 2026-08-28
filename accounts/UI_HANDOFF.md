# Ekostay Accounts — UI Handoff v2

**Supersedes `UI_HANDOFF.md` (v1).** Read this and `ACCOUNTS_CONTEXT_ADDENDUM.md`
before writing code. `ACCOUNTS_REBUILD_CONTEXT.md` remains the functional spec for
behaviour and defects, but **the addendum corrects it in several places** — where
they disagree, the addendum wins, because it is built from screenshots and report
exports taken 12–13 Aug 2026.

---

## 1. What this is

Ekostay runs 150+ villa rentals across Maharashtra, Goa, Tamil Nadu, Karnataka,
Uttarakhand — and, it turns out, Kodaikanal and Bangalore too. Accounting runs on
Zoho Creator and has hit platform limits. We are rebuilding the **Accounts** app:
UI designed in Claude chat, functionality built in Claude Code.

Person: **Husain Khatumdi** (founder).

Source material:
- Three Deluge exports — `Accounts.ds` (59,063 lines, 46 forms), `Admin.ds`
  (4,162 lines, 10 forms), `F_B.ds` (21,994 lines, 21 forms). **Not currently in
  the working set — re-upload them if logic tracing is needed.**
- Live-UI screenshots, per module.
- **Seven report exports** with the real master data — see `master-data/`.

---

## 2. ⚠️ The single most important instruction

**Replicate the Creator screens. Do not redesign them.**

An earlier attempt reorganised the screens into a "better" information
architecture. It was rejected. Husain's words:

> "the name of the fields has to be the same as you are seeing in creator right
> now. My point of talking about the ui is that it should be readable and user
> friendly. Right now there are 20 rows but its easier for the team to identify
> the columns so I just want to replicate the exact same structure right now then
> later on we can make the changes."

Same structure, same labels, same column order, same density — rendered cleanly.
Improvements come later, on his signal.

### Concrete rules

1. **Field labels verbatim.** And note the app is not internally consistent: the
   same concept carries different labels on different screens. See §6 of the
   addendum for the divergence table. Pick one per concept in the rebuild, but
   record which screen you took it from.
2. **Column order as the report shows it**, including eye and checkbox columns
   and any per-row action button. Note `ID` is not always last — on All Item
   Categories it sits sixth of seven.
3. **Section names verbatim** — `Overview`, `Commercials`, `Amount Category`,
   `Split Payment`, `Employee Details`, `Account Details`, `Merge Vendor`,
   `Approved By`, `Approvers`, `Payment`, `Books Payment`, `External Payment`.
4. **~20+ rows visible** without scrolling on dense reports; row height ~27px.
   **Exception:** reports whose cells hold multi-select values or long URLs are
   content-height in Creator, and rows can be hundreds of pixels tall. See §3.
5. **Dates render `dd-MMM-yyyy`** (`16-Jul-2026`) via a text input with a calendar
   glyph, never `<input type="date">`. **Exception:** Backend Expenses `date`
   holds a raw string and prints `2026-08-13 13:00:21`.
6. **Currency renders `₹ ##,##,###.##`** with Indian digit grouping.
   **Exception:** `Gross Amount` prints at **three** decimals in at least two
   places — the Payments split grid and All Pending Approvals.
7. **Preserve source spellings.** ~~`Luxery`~~, `ACCOMODATION`, `Maintaince`,
   `multipe_hccc_names`, `stafffuel`, `Payment InProgress`, `Uttarakand`,
   `Bank Reconcilation`, `POP & FASE CEILING`, and the trailing space
   on `F&B STAFF MEDICAL EXPENSE `. These are keys downstream. Normalise at
   display only, never in data.

   **Updated 22-Aug-2026 against the real exports:**
   - `Luxery` **does not occur** — the villa category is spelled `Luxury`
     correctly (Gold 123 · Original 86 · Luxury 34). Stale entry, removed.
   - `Uttarakand` **added** — 7 villas, missing its 'h', a live grouping key.
   - `Bank Reconcilation` **added** — column 61 of the All Payments export.
   - `POP & FASE CEILING` **added** — an item category ("FASE" for "FALSE").
   - `Payment InProgress` stands, but note **both** casings are live in
     `Accounts.ds` (7 vs 10 occurrences) — see addendum §10.
   - The trailing space on `F&B STAFF MEDICAL EXPENSE ` is confirmed present and
     is the only such name in 135 item categories.
8. Footer reads `Showing N of M`. Creator pages at 1000, so the SHOWN count caps
   there — but **M is the real total**.

   **CORRECTED 27-Aug-2026, on Husain's screenshot.** This rule previously said
   Creator prints `Showing 1000 of ###` because "the total overflows the field", and
   the app reproduced the hashes faithfully. The live All Expenses footer reads
   `Showing 1000 of 66407`. The hashes are a clipped or in-flight render, not the
   settled output. Show the number.
9. Zoho chrome: navy rail, pink active state, pink `＋`, pink primary buttons,
   `SEARCH` chip with field selector + `contains` + value.

### What may be added

Only things that prevent data loss or surface an existing rule earlier. Accepted
so far: split rows **reconcile** rather than clear on scope change; a running
`₹ x of ₹ y` tally on the Split Payment header; a duplicate-name check on Vendor
Master; exactly-one-Primary enforcement on Account Details. Flag anything else as
a suggestion; don't ship it unasked.

---

## 3. The design system

```css
--rail:#2b2f4a  --rail2:#383d5e
--pink:#e4407f  --pinkd:#c72e69  --pinkl:#fdeef4
--ink:#20242e  --ink2:#4a5160  --ink3:#7b8494  --ink4:#a8afbb
--line:#e6e9ee  --line2:#d2d7df  --bg:#f4f5f7  --white:#fff
--ok:#0f7b5f / #e9f6f1   --bad:#c0392b / #fdeceb
--warn:#9a6206 / #fdf3e2 --info:#2b5fa8 / #eaf1fb
--fillgreen:#1ec69f      /* solid status-cell fill, see below */
font: 'Inter' 13px · figures in 'Roboto Mono' 12px with tabular-nums
```

Layout: `grid-template-columns: 104px minmax(0,1fr)`. Main is a flex column —
appbar 42px, reportbar, optional search row, scrolling grid, 28px footer.
Class prefix `zc-` throughout. Grid rows 27px, headers 31px sticky, 1px borders
both axes, hover and selected `--pinkl`.

**Corrections to v1 of this document:**

- **`.zc-main` needs `min-height:0`.** It is a grid item, so it defaults to
  `min-height:auto` and grows past the 100vh container instead of letting its
  child scroll. Every module had this bug; it only shows on content taller than
  the viewport.
- **Zebra striping is wrong.** Creator renders every row white. v1's
  `even { background:#fafbfc }` does not match.
- **Forms are near-full-screen overlays, not centred modals.** v1 says
  `min(1220px, 100%)` centred; the live forms cover the viewport from `left:30px`,
  leaving a sliver of rail. Bills, Vendor Master, Backend Expenses and Payments
  were built as modals and need converting — or confirmation that those four
  really are modals in Creator.
- **Status is a solid filled cell, not a chip**, on reports with conditional
  formatting (All Payments, All Pending Approvals): flat green edge-to-edge behind
  the value. The four earlier modules use tinted chips and need converting.
- **Multi-select values print one per line**, not comma-joined, in both list and
  detail. This makes rows content-height. Detail-panel `th` must be
  `vertical-align:top` — a middle-aligned label in a 3,000px cell renders
  off-screen.
- **A nav flyout cannot live inside the rail.** `overflow-y:auto` on the rail
  makes the horizontal axis a scroll container too and clips the submenu. Use a
  viewport-anchored `position:fixed` panel.
- Detail-panel bars differ per module. Four variants so far:
  `Edit / More` (Payments) · `Edit / Delete / More` (Settings, Pending Approvals)
  · `Edit / Duplicate / More` (Payment Requests) · plus the Bills variant.

---

## 4. Verify by rendering. Every time.

The v1 failure came from writing ~4,000 lines of CSS without once looking at it.
**Do not present UI you have not seen.**

```bash
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
cp YourModule.jsx shot/App.jsx
./node_modules/.bin/esbuild shot/entry.jsx --bundle --outfile=shot/bundle.js \
  --loader:.jsx=jsx --jsx=automatic
node cap.mjs        # playwright 1600×1000, deviceScaleFactor 2
```

`cap.mjs` should capture page errors *and* console errors, and assert on
rendered output — row counts, footer text, header labels — not just take a
picture. Faults this loop caught that static review did not:

- alphabetical sort on a `"30-Jun-2026 17:45:03"` timestamp string
- negative Payable on settled bills feeding a headline total
- a green "reconciled" badge on an empty form
- native date inputs rendering mm/dd/yyyy
- **18-digit record IDs silently corrupted by passing them through `float()`**
  (`…361075` → `…361100`) — caught only by React's duplicate-key warning
- a boolean mapper comparing to the string `"true"` while the data held real
  booleans, so all 144 COA flags read false
- a nav flyout invisible because the rail clipped it
- a form taller than the viewport with nothing scrolling

---

## 5. Where the work stands

### Screenshot-verified replicas
| File | Module | Notes |
|---|---|---|
| `SettingsModule.jsx` | Settings — all 8 reports | seeded from the real exports |
| `PaymentsModule.jsx` | Payments — list, detail, form, reverse dialog | column set assumed, see below |
| `PendingApprovalsModule.jsx` | Pending Approvals — 24 cols, detail, form | |
| `PaymentRequestsModule.jsx` | Payment Requests — 3 views | |
| `BackendExpensesModule.jsx` | Backend Expenses — **32** cols, 136 fields seen | **report + detail verified 27-Aug-2026**, addendum §4 |
| `BackendPaymentsModule.jsx` | Backbend Payments — 42 fields, 10-col report | **form + list + detail verified 27-Aug-2026**, addendum §7 |
| `BillsModule.jsx` | Bills | from v1, needs the §3 chrome corrections |
| `VendorMasterModule.jsx` | Vendor Master | from v1, same |

### Needs rebuilding — old redesign, do not ship
| File | Why |
|---|---|
| `SchedulePaymentsModule.jsx` | "Ready" instead of `Click to Proceed`, restructured queue |
| `SalaryPayoutsModule.jsx` | payslip redesign; **the payroll engine inside is correct — keep it** |

### Not built
Accounts (first nav item) · Bank · Expenses · Expense Observations (spec §13) ·
Masters beyond Vendor Master · App Preferences · Preferred Approver ·
Zoho app pointers · Ekostay … · the Backend Payments list

### Nav rail — at least 17 items, not the 11 in v1
Accounts · **Bank** *(captured 27-Aug-2026 — 30 cols, inline-editable, Print-only
panel, fed from Zoho Books; addendum §7B)* · Payments · Bills · Expenses ·
Schedule Payments ·
**Expense Observations** *(captured 27-Aug-2026 — the only GROUPED report in the app,
villa bands with true subtotals plus `Show Summary`; addendum §7C)* ·
Masters *(Vendor Master, All Vendor Masters)* ·
Settings *(8 reports, see addendum §2; **All Approvals + the Approvers grid
captured 27-Aug-2026**, addendum §11.7)* · Backend Expenses · Pending Approvals ·
App Preferences *(**captured 27-Aug-2026 — Creator's Manage Integrations panel, a
platform screen with no form to rebuild**; addendum §7D)* · Payment Requests *(3 views)* · Zoho app pointers - Payment Ap… ·
**Backbend** Payments *(rail spells it Backbend, the form says Backend)* ·
Preferred Approver · Ekostay …

**Auto Numbers captured 27-Aug-2026** — four series in one singleton row, only three
of them report columns, and the counter 312 behind live. Addendum §6.10-6.15.

---

## 6. Still outstanding from Husain

1. ~~**Blueprints and Approvals tabs** in the Creator Workflow section — never
   verified. Still the one gap that could invalidate a whole section.~~
   **CLOSED 22-Aug-2026 — both empty.** Screenshots of the builder's Workflow
   section show `Blueprints` and `Approvals` at their zero-state. Nothing is
   configured in either, so **no whole section is invalidated**: §6.5 and §7.3 are
   the complete payment status lifecycle, and the approval engine is entirely
   hand-rolled Deluge. See spec §8.5 and addendum §12.

   This was the project's largest single risk. It is retired, and it resolved the
   good way — nothing already built or documented has to be revisited.
2. **Is the `COA` checkbox the `Hide` field?** Open it in the form builder and
   read the API name. If yes, §7.5's "inverted filter" finding dissolves — see
   addendum §3.
3. ~~**Where is the Block Payment Date cutoff enforced?** Needs a DS grep.~~
   **RESOLVED 22-Aug-2026 by DS grep — it is enforced nowhere server-side.**
   All four functional `Block_Payment` references in `Accounts.ds`:
   - **32868** — Payment form, `on user input of Payment_Date`: `alert` then
     `input.Payment_Date = null`. Browser-side, field-level, one field, one form.
   - **24800** — on-load rule that *disables* fields when the payment date precedes
     the cutoff. A display concession, not a guard.
   - **32892 / 32906** — the singleton's own "you cannot add new record" on-load
     alert. Also client-side.
   - **2801 / 13611** — the form and report definitions.

   There is **no check on Bills, on Schedule Payments' monthly generation, or on
   §9.3's delete-and-regenerate**. Every non-interactive write path crosses the
   period lock freely, and even the interactive one is a UI event that a direct
   API call never reaches. Treat the lock as **unenforced today**, and implement it
   server-side on create, on edit, and on both date fields — as the addendum §2
   already argued, but from a worse starting point than it assumed.

   Also note `fetdate = Block_Payment[ID != null]` fetches the singleton with no
   ordering, so if a second record ever exists the cutoff read is arbitrary.
4. **All Payments column set** — the Payments module's column order is inferred,
   not seen. `Recoverable`, `Bank Reconciliation` and `Withdrawal Ma…` exist and
   are not in it, and there is a per-row action button.
   **Now load-bearing:** Payments is built and shipping that inferred order
   (`PaymentController::COLUMNS`). One screenshot settles it; until then this is
   the most likely thing on the screen to be wrong.
5. Which of the **three vendor-merge fields** is authoritative (§13A.1).
6. ~~The **role → permission matrix** (§3.3).~~ **CLOSED 22-Aug-2026 — extracted
   from the DS profiles.** `docs/permission_matrix.json`: 25 roles, 122 permissions,
   127 role-permission pairs, with `docs/parse_permissions.py` as the derivation.
   Nine tests assert every documented role maps to an explicit set and that no
   `string.contains()` survives in the authorisation path. Addendum §13 has the
   working. This was the last of the four §16 "blocking write paths" questions, so
   **§17 step 7's gate on Payments is lifted** — see addendum §16.
7. Screenshots still wanted: Backend Payments list · App Preferences ·
   Preferred Approver · Zoho app pointers · Ekostay …
   ~~any second All Approvals record (for `Exclude Category`, `Type`, and the
   Approvers bands)~~ — **RECEIVED 22-Aug-2026.** Detail, edit form and the
   Approvers grid, plus the form definition at `Accounts.ds:61–200`. Fully
   specified in addendum §11. Headline: **the approval matrix is amount-banded**
   (`Minimum_Amount` / `Maximum_Amount` per approver row, approver is an FK to
   `Employee_Master`), which §8.2 never mentions and which collides with the
   second amount-banded engine in Backend Expenses.

Independent of the rebuild: the **hardcoded DoubleTick API key** at `Accounts.ds`
line 22851 still needs rotating; the **negative-HRA band** (₹21,001–21,099) is
producing bad payslips today; **`Delete Paid Payment`** is live one click from a
settled payment.

Two more, found 22-Aug-2026 while closing the §16 write-path questions:

- **`void DeleteAllRecords()` at `F_B.ds:4645`** runs
  `delete from <table>[ID != null]` across **14 F&B tables** including `Expenses`,
  `Booking` and `Inventory`. `ID != null` matches every row, and standalone Deluge
  functions are invocable as REST endpoints. This looks like a development reset
  helper left in a production app. Addendum §16.4.
- **The §7.2 partially-paid TDS sign overpays vendors** by `2·TDS − GST` — for a
  TDS-only vendor with no GST, by exactly twice the withholding. It is a bug, not
  the convention §7.2 wondered about, and it has been running on every
  partially-paid bill. Addendum §16.3. Worth quantifying against live data.

---

## 7. How he works, and what he values

- Sends screenshots per module, then expects a replica built from them plus the DS
- Says when something is wrong, directly. Take it at face value and fix the thing
- Wants the `.md` kept current — it is the real deliverable, the JSX is regenerable
- Appreciates findings from the data he didn't know about: bugs, duplicated
  fields, packed strings, dangerous deletes. Report them with the evidence
- **Corrections matter.** When an earlier claim turns out wrong, say so plainly.
  Several conclusions in this project were revised after better evidence — the
  "zero splits" that were a display bug; the days-worked field that was a naming
  error not a sign error; and in this session, a claim that the DS exports were
  available when they were not.
