# Decisions register

Supersedes `ACCOUNTS_REBUILD_CONTEXT.md` §16 (written from the 08-Aug-2026 export).
Last updated 13-Aug-2026, after reading all three DS exports (Accounts 12-Aug 19:39,
Admin 12-Aug 19:55, F&B 13-Aug 12:34).

## Nothing is blocking. Every open question is closed.

**The governing decision: copy as built.** The current system's output is the
specification. Where Creator departs from statute, or from itself, the rebuild
reproduces the departure exactly, documents it, and pins it with a test.

That rule is what makes the rest of this file possible to read. A discrepancy
recorded below is not a to-do — it is a description of intended behaviour.

| Section | |
|---|---|
| **A** | The eight questions that needed Husain. All decided. |
| **B** | Nine things the code answered that the older docs still mark unknown. |
| **C** | Six defects found beyond the original 48 — `#49`–`#54`. |
| **D** | Credential inventory. Rotation handled separately, outside the rebuild. |

Sections C and D are **not** open questions. C is the "do not reproduce this"
list; D is a security record.

---

# A. DECIDED — nothing here blocks the build

All eight items closed 13-Aug-2026. **The governing decision is copy-as-built:
the current system's output is the specification.** Where Creator departs from
statute or from itself, the rebuild reproduces the departure exactly, documents
it, and pins it with a test.

Nothing in this section needs revisiting. It is kept as the record of why the
code does what it does, so a future session does not "fix" a deliberate copy.

| | Question | Decision |
|---|---|---|
| A1 | Blueprints / Approvals | ✅ **Empty** in Accounts and F&B, verified in the Creator Workflow tab. The Deluge is the whole story. |
| A2 | Who holds which profile | ✅ Not needed. Ports the 17 profile definitions; assignment is an admin task post-cutover. |
| A3 | EKOSTAY / Lease owner splits | ✅ **Copy as built** — and live data shows neither EKOSTAY value is in use across 200 villas, so the gap is latent. |
| A4 | Which Payable formula | ✅ **Copy all three**, named distinctly. |
| A5 | Four payroll deviations | ✅ **Copy all four**, including negative HRA. |
| A6 | Backend_Expenses → many payments | ✅ Keep both fields as the source has them. |
| A7 | Stack | ✅ Confirmed — current stack is fine to work with. |
| A8 | Replicate or redesign | ✅ **Replicate Creator.** |

---

## A1. Blueprints and Approval workflows — CLOSED

Both empty, in Accounts and in F&B. Checked in the Creator **Workflow** tab.

This was the one gap no export could close, and it closes clean: `Payment.Status`
transitions are governed entirely by readable Deluge — `UpdatePaymentStatus`
routing, the `Approve` custom action, the WhatsApp reply handler. No hidden
lifecycle rules. `ACCOUNTS_REBUILD_CONTEXT.md` §8's approval design stands and
can be rebuilt from source alone.

## A2. Who holds which profile — CLOSED, not needed

The exports carry profile *definitions* (Accounts 17, Admin 7, F&B 12), which is
what the rebuild needs. Assigning humans to profiles is an administrative task at
cutover, not a design input.

`Accounts_PERMISSIONS.md` records the mechanism and its failure modes.

## A3. EKOSTAY / Lease owner splits — COPY AS BUILT

`admin.Villa.Rent_Type` = `{"Revenue Split EKOSTAY", "Expense Split EKOSTAY",
"Revenue Share", "Lease"}`, plus `others option = true`.

**Behaviour to reproduce:** only `"Revenue Share"` reveals the split fields. For
the other three — and for any free-text value — `Villa.OnLoadCE` and
`OnInputRentTypeCE` both `hide` GST, `Revenue_split_for_Owner`,
`Expenses_split_for_Owner`, `F_B_Revenue_split_for_Owner` and
`F_B_Expenses_split_for_Owner`. Nothing reads them. Nothing is stored.

So the rebuild captures no owner splits for those rent types. Not hidden
elsewhere — genuinely absent.

**Implementation note.** Keep this as a single rule — *"split fields apply to
Revenue Share only"* — in one place, not scattered across show/hide handlers.
Then if EKOSTAY-type splits are ever wanted, it is one config change rather than
an archaeology exercise.

`others option = true` means arbitrary strings are storable. The rebuild keeps
free text (copy as built) but logs any value outside the four so unrecognised
rent types are visible rather than silent.

### ✅ Confirmed by live data, 13-Aug — the gap is latent, not active

Across 200 villas and 63k expense rows (`DB_FINDINGS.md` §0):

| `rent_type` | rows | villas |
|---|---|---|
| `Lease` | 44,787 | 144 |
| `Revenue Share` | 2,788 | 48 |
| `NULL` | 15,760 | 10 |

**Neither EKOSTAY value appears anywhere. Nor does any free-text value.** Identical
in both the legacy CSV era and the live API era, so it is not an ingest artefact.

The 10 NULL rows are regional cost centres — Head Office Central, Alibaug Central,
Lonavla Central and so on. Offices and hubs that carry expenses but have no owner,
so NULL is correct for them.

Every real villa is `Lease` or `Revenue Share`, and `Revenue Share` is the branch
Creator handles. **So no owner splits are going uncaptured, and there is no
spreadsheet to go looking for.** The unhandled branch is dead code, not a hole in
the accounting.

Keep the validation anyway: with `others option = true`, one typo in the picker
creates a silent fifth category tomorrow.

## A4. Payable formula — COPY ALL THREE, NAME THEM DISTINCTLY

Three modules, three different quantities, one shared label. All three are
reproduced exactly:

```
bills.payable_amount
  = total_amount − tds_amount − paid_amount + adjusted_amount
    Bills.OnInputAmountCE / OnInputAdjustmentCE

payments.payable_amount
  = invoice_amount − tds_amount                        ← no paid_amount term
    Payment.OnInputGrossAmountCE

payments_scheduled.total_due
  = due_amount + gst − tds,  where due_amount = amount − deductions
    Payments_Scheduled.OnInputLoanAmountCE  (GST/TDS on the NET, not gross)
```

**The one change: distinct names.** Three screens showing "Payable Amount" and
three different numbers is how reconciliation errors are born. The arithmetic is
untouched; only the label differs. Creator's own labels stay on screen where a
user expects them — the distinction lives in the schema and the code.

Salary path, same treatment — `Payment.OnInputGrossAmountCE` swaps TDS for
statutory deductions when `Item_Category` is `STAFF SALARY` or `F&B SALARY`:

```
invoice_amount = amount − pf − pt − esi + gst_amount
payable_amount = amount − pf − pt − esi + gst_amount     ← identical
```

Both assigned the same expression. Reproduced as-is.

## A5. Four payroll deviations — COPY ALL FOUR

Already implemented and tested in `payroll/`. 104 assertions, pinned to Ahmed
Accounts' live June and July 2026 figures (payable ₹22,680 / ₹24,800, to the
rupee).

| # | Deviation | Config key | Reproduced |
|---|---|---|---|
| 1 | Negative HRA in ₹21,001–21,099 | `basicBandLow` / `basicHigh` | ✅ tested at 21001, 21050, 21099 |
| 2 | ESIC on base pay, not gross | `esicBasis: 'base'` | ✅ both bases asserted |
| 3 | EDLI doubled | `edliMultiplier: 2` | ✅ statutory value also asserted |
| 4 | PT on prorated salary | `ptBasis: 'prorated'` | ✅ both bases asserted |

**Negative HRA is reproduced, not fixed.** A total of ₹21,050 yields base pay
₹21,100 and HRA −₹50, exactly as Creator does. Three tests would fail if someone
closed the band.

`check()` returns `danger` / `NEGATIVE_HRA` when a total lands in that window, so
the condition is visible on the record without altering the arithmetic. That is
the whole difference between the rebuild and Creator here: same number, plus a
warning.

Every rate, band, ceiling and basis is versioned config with an effective date,
and `computePayout` stamps `configVersion` on each payslip. A future correction
is a new config row — historical payslips stay reproducible.

## A6. Backend_Expenses → several payments — KEEP BOTH FIELDS

`Payment` is a single lookup; `Matched_Payments` is a list. The source keeps
both, so the rebuild keeps both. Whether one provider transaction ever splits
across payments is a data question answerable later from the records; dropping a
field on a guess is not reversible.

## A7. Stack — CONFIRMED

Current stack is fine to work with. **React 19 + JSX + Vite** for the frontend,
as the six modules already are.

Settled regardless of language:

- **Money is never a float.** `DECIMAL(16,2)` — see `DB_FINDINGS.md` §3. The live
  server already uses `decimal` for every money column across three databases;
  `payouts` uses `(16,2)`, so match the wider existing column rather than
  introducing a narrower one.
- **PostgreSQL** for the database — `NUMERIC(16,2)`, CHECK constraints so
  invariants are enforced by the database rather than remembered, `SELECT … FOR
  UPDATE` for concurrent payment writes, partial unique indexes for
  "one active match line per payment per direction" (the invariant
  `resolveDuplicateLines()` currently repairs *after* the fact).
- The payroll engine is already dependency-free and portable — see
  `payroll/README.md` for what must survive the move.

## A8. UI — REPLICATE CREATOR

Confirmed 13-Aug. `UI_HANDOFF.md` §2 governs: verbatim field labels, Creator
column order, preserved source spellings (`Payment InProgress`, `Luxery`,
`multipe_hccc_names`), 27px rows, ~22 visible, `dd-MMM-yyyy` dates,
`₹ ##,##,###.##` currency.

Bills, Vendor Master and Backend Expenses already comply. Payments, Schedule
Payments and Salary Payouts predate the decision and need their layouts rebuilt —
their logic is sound.

---

## Not part of the rebuild

**Credential rotation** — being handled separately. The keys in `zoho_source/`
(DoubleTick, Zoho OAuth) are live; the rebuild reads them from environment
config, never from source. See §D for the inventory.

---

# B. ANSWERED BY THE CODE — older docs still mark these open

## B1. The three villa flags → **only one is functional**  ✅

`admin.Villa` declares three:

```
Active              checkbox
Status              picklist {"Active","In Active"}
Hide_From_Payments  checkbox
```

Grepped all three exports for reads:

| Flag | Read in logic | In lookup filters |
|---|---|---|
| `Hide_From_Payments` | no | **yes — 5 sites** |
| `Active` | no | no |
| `Status` | no | no |

`Hide_From_Payments == false` filters the villa picker on `Bills.Villas`,
`Payment.Villa_Name`, `Salary_Payouts.Villa_Name`, `Schedule_Payment.Villa_Name`,
`Salary_Payout_Schedule.Villa_Name`.

`Active` and `Status` appear only as report columns. Nothing reads either.

⚠️ Do not confuse with `admin.Employee_Master.Status`, which **is** functional —
`Access.Accounts` branches on `== "Active"` to provision or revoke portal access.

**Rebuild:** one `villas.is_active`, plus `villas.hidden_from_payments` only if you
need "active but not offered for new payments." Two flags maximum.

**Residual risk:** proves no *code* reads them. If someone filters the All Villas
report by `Active` to make a manual decision, that is a human process invisible here.

## B2. Rent_Type values → **four, plus free text**  ✅

```deluge
Rent_Type ( values = {"Revenue Split EKOSTAY","Expense Split EKOSTAY",
                      "Revenue Share","Lease"}
            others option = true )
```

`others option = true` is worse than the docs assumed — arbitrary strings are storable.
Only `"Revenue Share"` is handled. See A3 for the business question.

## B3. Which category-scoping mechanism is live → **B; A is orphaned**  ✅

Two mechanisms exist on `Villa`:

- **A** — `Item_Category_to` + `Master_Category` + `Item_Category`. Shown only when
  `Expenses_split_for_Owner > 0`.
- **B** — `Type_field` + `Include_Item_Category` + `Exclude_Item_Category`.

Accounts reads **B**: `Payment.OnInputVillaNameCE` and `Bills.OnInputVillasCE` both use
`input.Villas.Include_Item_Category`. Nothing reads A.

B is **auto-complementary** — `OninputIncludeItemCE` sets Exclude to everything not in
Include, and `OnInputExcludeitemcategoryCE` does the reverse. They are always exact
complements.

**Rebuild:** one category list plus an include/exclude mode flag. Not two lists.

## B4. Role → permission mechanism  ✅

`admin.Access.Accounts(recID)` reads `Employee_Master.User_Role` — **unconstrained
text** — and dispatches on `.contains()`:

| `User_Role` contains | Accounts profile | also provisions |
|---|---|---|
| `Account Team-Executive` | Account Team-Executive | — |
| `Account Team-Senior` **or** `accounts head` | Account Team-Senior | — |
| `Food Operator` | Food Operator | `fb.Access.AssignAccess` |
| `Property Manager` | Property Manager | `villa_operation.Access.Access` |
| `Market Head` | Market Head | `villa_operation.Access.Access` |
| `Central Operations` | Central Operations | `villa_operation.Access.Access` |
| `Human Resources` | Human Resources | — |

`Accounts.PortalAccess` then lower-cases the comparison, so the string travels through
two case conventions.

Failure modes: a typo grants nothing while still setting `Access_Given = true`;
substring matching means `"Not Account Team-Senior"` matches; role changes don't take
effect until the record is re-saved.

**Rebuild:** `roles` table, FK from employees, `permissions` table, join. No
`.contains()` in the auth path. Test asserting each of the 10 known roles maps to an
explicit permission set.

## B5. Payroll HR gate  ✅

`admin.Employee.HrEmployee(mail)` returns `Employee_Master[Email == mail].Is_HR`.
`Salary_Payouts.OnLoadCE` uses that one boolean to decide whether `Total_Amount`,
`Make_Calculation` and the Salary Months grid are editable.

**One checkbox on one record is the entire payroll authority gate.**

## B6. Villa hierarchy semantics  ✅

`Primary` checkbox drives it. True → show `Secondary_Villa`, hide `Primary_Villa`.
False → the reverse. `Villa.OnSuccessCE` maintains both sides bidirectionally.
Parent-child grouping, self-maintaining. `demo()` was a one-off backfill setting every
unparented villa as its own primary.

## B7. `Backend_*` triplet purpose  ✅  (was §6.2 TODO)

Running **unpaid balance per split leg**, not a partial-payment display variant.

- `Bills.OnInputValidationCE`: when `Paid_Amount == 0`, resets `Backend_Total_Amount`
  to `Total_Amount`.
- `Payment.OnSuccessCE`: decrements it by each payment
  (`billsbill.Backend_Total_Amount - accbill.Total_Amount`).
- `DeletePaidBill`: adds it back.

## B8. Staff Advance / Staff Loan source  ✅  (was §11.2 TODO)

`Salary_Payouts.OnBillingCycleCE` pulls both from `Expenses_Bills`, matched on vendor +
billing cycle month/year, filtering `Item_Category` for `STAFF ADVANCE` /
`F&B STAFF ADVANCE` / `STAFF LOAN` / `F&B STAFF LOAN`. They flow in from the expense
ledger.

## B9. F&B expense generation  ✅  (F&B export, 13-Aug)

Three near-identical functions — `RequestStock.RequestStockPendingRaw`,
`…PartialRaw`, `…CompletedRaw` — differing only in which of the three
`Raw_Material_Request` buckets they read. Each does FIFO inventory depletion against
`Inventory_Stock` sorted by `Added_Time asc`, writes `Transaction_Items`, and creates
or updates `Expenses`.

`Books.UpdateBills`, `Manulupdatevendorbooking` and `Manulupdatevendorbooking1` are
three more copies of the same expense-generation block. **Four copies of one algorithm.**

**Rebuild:** one function with a bucket parameter.

---

# C. New defects found since the doc's list of 48

| # | Where | Defect |
|---|---|---|
| 49 | `Admin.Employee_Master.OnSuccessCE` | Auto-creates an `Employee_Designation` from unmatched free-text `User_Role`. Typos become permanent master data. Same pattern as the billing-cycle defect (#13) |
| 50 | `admin.Employee.GetData` | Parameter is `email`; query uses `input.email`. No `input` scope exists in a standalone function. Called from `Payment.OnLoadC` and `Payment_Request.OnLoadCE` |
| 51 | `fb.Expenses.OnSuccessCE4` and `FB.Expense` | Month name spelled `"Feburary"`. Passed to `accounts.FB.BillingCycle(Month, Year)`, which **creates the cycle if absent** — so a junk `"Feburary"` cycle gets created every February |
| 52 | `fb.Booking.Status` | Picklist has both `"Maintaince"` and `"Maintenance"` |
| 53 | `fb.FB.UpdateVendorBookingPaymentStatus` | `delete from Expenses[Payment_No1 == dataMap.get("Payment_No")]` — hard delete keyed on a payment number that is unstable across delete/recreate |
| 54 | `fb.DeleteAllRecords()` | Deletes every record from 14 forms with no guard, no confirmation, no permission check. Callable from the IDE |

`getlocationHeadoffice` returns only `Location`, despite the name. Callers read
`.get("Location")`, so the code is correct and the name is a lie. Not a defect —
a naming trap. Two near-identical functions (`GetLocation`, `getlocationHeadoffice`)
do the same thing.

---

# D. Credential inventory

**Rotation is being handled separately — outside the rebuild.** This is the
inventory, kept so the rebuild knows what must come from environment config and
never from source.

## What is hardcoded in the Creator source

| Secret | Where |
|---|---|
| DoubleTick API key | `Accounts.Whatsappmessage`, `Accounts.RequestPaymentWhatsapp`, `Standalone.widgetSendWhatsApp` |
| Zoho OAuth clientId + clientSecret | `Standalone.proxyAnalytics` |

Redacted as `[REDACTED-ROTATE-ME]` in `zoho_source/Accounts_LOGIC.ds`.

## What is stored as plaintext record data

`Eko_RS_App_Config` holds `DoubleTick_API_Key`, `Analytics_Refresh_Token`,
`Cached_Access_Token` and `Pin_Hash` in `text` / `textarea` fields.

The **Account Team-Executive** profile's field map hides `DoubleTick_API_Key` and
`Pin_Hash` but leaves `Analytics_Refresh_Token` and `Cached_Access_Token` as
`visibility:true, readonly:true`. A non-admin profile can read a refresh token.

## Rebuild requirements

1. **No secret in source, ever.** Environment config only.
2. **No secret in record storage.** `Eko_RS_App_Config`'s secret fields become
   environment variables; the form keeps only non-secret settings
   (`DoubleTick_Template_Name`, `PDF_Host_Url_Override`, GST slabs).
3. **Token cache is infrastructure, not data.** `Cached_Access_Token` /
   `Token_Expiry_Epoch` belong in a cache layer, not a user-visible form.
4. **`Pin_Hash` needs a real KDF** — bcrypt or argon2 — not a text field.

## 🔴 The delete webhook is failing in production — verified 13-Aug

`Payment.OnDeleteValidate` POSTs to
`https://expense.server.ekostay.com/api/zoho/payment-deleted` with header
`X-Zoho-Token: "PUT_THE_ROTATED_TOKEN_HERE"` — a placeholder still in live source.

**Confirmed on the server.** The receiving end is well built: timing-safe
`hash_equals`, idempotency, an enable flag, and a rule that a settled payout is
never auto-deleted by an inbound call. A real 48-character token is configured and
the endpoint is enabled — so Creator's placeholder gets a **401**.

Four bad-token warnings logged, from two external IPs, on 05-Aug and 07-Aug.
`zoho_payment_deletions` holds 4 rows, three of them from the server's own IP —
manual curl tests, not Creator.

**No delete notification from Creator has ever succeeded.** When a payment is
deleted there, the settlement system is never told. Its own docblock explains why
polling cannot substitute:

> *"our payouts never appear in the expenses export (they are Draft, and ingest
> keeps Paid only), so a sync can NEVER see whether one was deleted — absence
> carries no information."*

The same docblock records why the endpoint exists at all:

> *"a no-force call on a Draft payment now DELETES it (learned the hard way — it
> removed two real payments during testing)"*

**Fix: paste the real token into the Deluge.** One line, not a rebuild item. Until
then, every Creator-side payment deletion leaves the settlement system holding a
payout that no longer exists upstream.

## Two smaller live-data observations

- **35 `payouts` rows where `payable_amount > invoice_amount`.** Impossible under
  `payable = invoice − tds`. Most likely the salary path, where source assigns the
  same expression to both fields. Worth one query when convenient.
- **683 `acco_accounts.expenses` rows with `amount = 0`.** Legitimate zero-value
  postings or ingest artefacts — unknown, and it affects the tracker's baselines
  rather than ours.
