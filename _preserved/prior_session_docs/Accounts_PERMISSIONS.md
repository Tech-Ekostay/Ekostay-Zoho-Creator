# Accounts app — permission matrix

From the 12-Aug-2026 export paste. **See `SOURCE_PROVENANCE.md`.**

This **closes doc §3.3's "Full role→permission matrix per module" [TODO]** for the
Accounts app. The doc inferred the model from role-scoped reports; this is the actual
declaration.

Note what this does *not* give you: which humans hold which profile. That lives in
Settings → Users and is not in any export.

---

## How authorisation actually works today

Two layers, and the first one is the problem.

**Layer 1 — provisioning.** `admin.Access.Accounts(recID)` reads
`Employee_Master.User_Role`, which is **unconstrained text**, and dispatches on
`.contains()`:

| `User_Role` contains | → Accounts profile | → also |
|---|---|---|
| `"Account Team-Executive"` | Account Team-Executive | |
| `"Account Team-Senior"` **or** `"accounts head"` | Account Team-Senior | ← lowercase special case |
| `"Food Operator"` | Food Operator | `fb.Access.AssignAccess` |
| `"Property Manager"` | Property Manager | `villa_operation.Access.Access` |
| `"Market Head"` | Market Head | `villa_operation.Access.Access` |
| `"Central Operations"` | Central Operations | `villa_operation.Access.Access` |
| `"Human Resources"` | Human Resources | |

On `Status != "Active"` the mirror-image `DeleteAccess` calls run.
CA users provision separately via `accounts.CAAccess.Payments(bank, email)` →
`Accounts.CAPortalaccess` → profile `"CA Team"`.

`Accounts.PortalAccess(Email, UserName)` then **lower-cases the comparison**
(`if(UserName == "account team-executive")`), so the string travels through two
different case conventions.

**Failure modes this creates:**
- A typo in `User_Role` silently grants nothing. `Access_Given` is still set `true`.
- `Employee_Master.OnSuccessCE` **auto-creates an `Employee_Designation`** from any
  unmatched `User_Role` free text (defect 49) — so typos become permanent master data.
- Substring matching means `"Not Account Team-Senior"` would match.
- Someone whose role changes keeps the old profile until the record is re-saved.

**Rebuild requirement (doc §3.3 — "highest-value structural fix in the master
layer"):** a `roles` table with an FK from employees, a `permissions` table, and a
join. No string `.contains()` anywhere in the authorisation path. A test asserting
each of the 10 known roles maps to an explicit permission set.

**Layer 2 — profile grants**, below. Note these are *Creator* profiles; the app also
gates behaviour inline by email address (`Payment.OnLoadCE` checks
`zoho.loginuserid == "varun@ekostayhospitality.com"`;
`Accounts.DeletePermanentlyTrash` checks `"husain@ekostayhospitality.com"`). Those
inline checks are invisible to this matrix and must be caught separately.

---

## Profiles

17 declared. Types: `Users_Permissions` (paid Creator seats), `Customer_Portal`
(portal users), `Developer`.

| Profile | Type | Notes |
|---|---|---|
| Administrator | Users_Permissions | all permissions |
| Developer | Developer | |
| Read | Users_Permissions | view-only across all components |
| Write | Users_Permissions | full CRUD; **Deleted_Payments is Viewall+Export only** |
| Write - same as admin | Users_Permissions | Write + Eko_RS_* + Salary_Payout_Schedule + Flagged_Payments |
| **Account Team-Senior** | Users_Permissions **and** Customer_Portal | declared twice — see below |
| Account Team-Executive | Customer_Portal | the field-level-restricted one |
| Human Resources | Customer_Portal | payroll |
| CA Team | Customer_Portal | read-only, LLP-scoped |
| Food Operator | Customer_Portal | Payment_Request only |
| Property Manager | Customer_Portal | Payment_Request only |
| Market Head | Customer_Portal | Payment_Request only |
| Central Operations | Customer_Portal | Payment_Request only |
| Payment Request | Customer_Portal | Payment_Request only |
| Salary Data Entry | Customer_Portal | Schedule_Payment + Payments_Scheduled |
| Dependant Property Owner | Customer_Portal | Expenses_Bills read |
| Independant Property Owner | Customer_Portal | Expenses_Bills read |
| Customer | Customer_Portal | default, add+view |

**`Account Team-Senior` exists as both a `Users_Permissions` profile and a
`Customer_Portal` profile**, with different grants. `Accounts.PortalAccess` calls
`assignUserInProfile` which targets the portal one. Worth confirming nobody holds the
seat version by accident.

---

## The four operational profiles in detail

### Account Team-Senior (Customer_Portal) — the widest non-admin role

Full CRUD on the transaction spine:

| Module | Grants | Reports |
|---|---|---|
| Payment | Create, Viewall, Modifyall, Import, Export, Tab, comments | All_Payments {View,Edit,Delete} · Payments {View,Edit} · CA_Payments {View,Delete} |
| Bills | Create, Viewall, Modifyall, Import, Export, Tab, comments | All_Bills {View,Edit,Delete} |
| Bank_Transactions | Create, Viewall, Modifyall, Import, Export, Tab | All_Bank_Transactions {View,Edit} |
| Vendor_Master | Create, Viewall, Modifyall, Import, Export, Tab | Vendor_Master1 {View,Edit} |
| Schedule_Payment / Payments_Scheduled | full | {View,Edit} |
| Salary_Payouts | Create, Viewall, Modifyall, Export, Tab | Salary_Payouts_Report {View,Edit} |
| Salary_Payout_Schedule | Viewall, Modifyall, Export | {View,Edit} |
| Expense_Observation | full | {View,Edit} |
| Item_Category / Master_Category | full | {View,Edit} |
| Expenses_Bills | Create, **Viewall, Export, Tab** | All_Expenses {View} · All_Bills1 {View} — **no Edit** |
| Pending_Approvals | Create, Viewall, Import, Export, Tab | {View} — **no Edit** |
| Deleted_Payments | Viewall, Export | {View} |
| Eko_RS_* (all five) | Create, Viewall, Modifyall, Tab | {View,Edit} |
| Backend_Expenses | Create, Viewall, Export, Tab | {View} |
| COA, TDS, Tax, Billing_Cycles, Auto_Numbers, Approval | Create, Tab only | — |

Popup forms granted: Match_Payments, Debit_Match_Payments, Update_Expense,
Update_Payment, Add_Payment_Reference_Number, Personal_Payment, Error_Message,
Preferred_Approver.

Pages: Accounts, Revenue_Share.

**Holds `Delete` on All_Payments — so `Delete Paid Payment` is reachable.**

### Account Team-Executive — the only profile with FIELD-level restrictions

Broadly similar to Senior, with three material differences:

**1. `Expenses_Bills` is `Viewall, Export` only** — no Create, no Modify — and it
carries an explicit per-field visibility map. Hidden: `PT`, `PF`, `ESIC`,
`Old_Billing_Cycles`, `Last_Updated_User`, `Updated_By_Widget`, `Link`, the A/B/C/D
flags and all eight `*_Updated_User_*` fields, and every `Section*` header.
Everything else visible, nothing read-only.

**2. `Eko_RS_App_Config` is `Create, Viewall, Tab` with a field map that hides the
secrets** — `DoubleTick_API_Key`, `Pin_Hash`, `Booking_Overrides_Json`,
`Default_Clusters_Json`, `DoubleTick_From_Number1`, `PDF_Host_Url_Override1` all
`visibility:false`. But **`Analytics_Refresh_Token`, `Cached_Access_Token` and
`Token_Expiry_Epoch` are `visibility:true, readonly:true`** — visible, and readable.

> A refresh token is a credential. Read access is compromise. This is the concrete
> reason to rotate the Zoho OAuth secret and move all of `Eko_RS_App_Config`'s
> secret fields out of record storage.

**3. `Pending_Approvals` is `Create, Tab` with `{View}` only** — cannot approve.

Also: `Bank_Transactions` gets `Create, Viewall, Export, Tab` with
All_Bank_Transactions `{View,Edit}`; `Payment` gets full CRUD including Delete;
`Bills` full CRUD including Delete.

### Human Resources — payroll, read-only on money

| Module | Grants |
|---|---|
| Salary_Payouts | Create, Viewall, Modifyall, Export, Tab → `{View,Edit}` |
| Vendor_Master | Create, Viewall, Modifyall, Export, Tab → Vendor_Master1 `{View,Edit}` |
| Payment | **Viewall, Export** → `View_Payments {View}` |
| Bank_Transactions | **Viewall, Export** → `View_Bank_Transactions {View}` |
| Schedule_Payment / Payments_Scheduled | **Viewall, Export** → `{View}` |
| everything else | no grants |

So HR can create and edit payslips, and can *see* payments and bank rows but not
touch them. That is a sensible separation.

**But note:** the actual payroll authority gate is not this profile — it is
`admin.Employee_Master.Is_HR`, a single checkbox read by
`admin.Employee.HrEmployee(mail)`, which `Salary_Payouts.OnLoadCE` uses to decide
whether `Total_Amount`, `Make_Calculation` and the Salary Months grid are editable.
**One boolean on one record controls whether someone can change salary figures.**

### CA Team — read-only, LLP-scoped by string matching

| Module | Grants | Report |
|---|---|---|
| Payment | Viewall, Export | `CA_Payments {View}` |
| Bills | Viewall, Export | `CA_Bills {View}` |
| Expenses_Bills | Viewall, Export | `CA_Expenses {View}` |
| Bank_Transactions | Viewall, Export | `LLP_Bank {View}` |
| Vendor_Master | Viewall, Export | Vendor_Master1, All_Vendor_Masters `{View}` |

**The scoping is the problem, not the grants.** These reports filter by substring:

```
CA_Expenses : Bank_Name.Account_Name.contains("LLP")
            || .contains("Petty") || .contains("Haewaya")
CA_Bills    : CA_Email == zoho.loginuserid
CA_Payments : COA.Account_Name.contains("ekostay")
            || Bank_Name.Account_Name.contains("LLP")
            || .contains("hospitality")
            || (.contains("petty") && !.contains("lonavala petty"))
LLP_Bank    : Account_Name.Account_Name == "EKOSTAY LLP 2" || "EKOSTAY LLP 1"
            || "EKOSTAY LLP ICICI" || "EKOSTAY HOSPITALITY LLP"
```

`Renu Sethi Kotak Mahindra (7839)` matches none of the CA_Expenses tokens, so it
**silently vanishes from the CA's view**. Any new account named without those tokens
disappears the same way. `CA_Bills` uses a different mechanism entirely
(`CA_Email == loginuserid`), so a CA sees bills assigned to them but expenses matched
by bank-name substring — two inconsistent scoping models for the same user.

**Rebuild:** scope by an explicit `entity_id` / `ca_id` foreign key. Never by name
substring.

---

## Portal profiles with a single capability

**Food Operator · Property Manager · Market Head · Central Operations · Payment
Request** all have exactly one grant:

```
Payment_Request  enabled = Create, Viewall, Export, Tab
                 User_Payment_Requests {View, Edit}
```
(Property Manager also gets `read_comm, write_comm`.)

Every other module is declared with `allFieldsVisible = true` and **no `enabled`
clause** — which grants nothing. That is the pattern for "listed but not accessible".

`User_Payment_Requests` is filtered `Requested_By.Email == zoho.loginuserid`, so
each requester sees only their own.

**Salary Data Entry:**
```
Payments_Scheduled  Create, Viewall, Export, Tab, comments → {View,Edit}
Schedule_Payment    Create, Viewall, Modifyall, Export, Tab → {View,Edit}
```
Nothing else. Can create schedules and edit instalments; cannot see payments.

**Dependant / Independant Property Owner** — identical grants:
```
Expenses_Bills  Create, Export, Tab → All_Expenses {View}, All_Bills1 {View}
```
Both profiles are the same. The distinction exists in naming only.

---

## Report-level scoping summary

The permission model is partly expressed as **separate reports over the same form** —
doc §3.3 called this "the permission model expressed as separate views." Confirmed:

| Form | Reports | Scoping |
|---|---|---|
| Payment | All_Payments | unfiltered |
| | Payments | `!Payment_No.contains("Haewaya")` |
| | CA_Payments | bank/COA name substring |
| | View_Payments | unfiltered (HR read-only) |
| | All_Payments_Hussain | unfiltered + `Villa_Name.BHK` column |
| Bills | All_Bills | unfiltered |
| | CA_Bills | `CA_Email == zoho.loginuserid` |
| Expenses_Bills | All_Expenses | unfiltered |
| | All_Bills1 | `Type_field == "Bill"` |
| | CA_Expenses | bank name substring |
| Bank_Transactions | All_Bank_Transactions | unfiltered |
| | LLP_Bank | four exact account names |
| | Admin_Bank_Transactions | unfiltered, different action conditions |
| | View_Bank_Transactions | unfiltered, no actions |
| | CA_Bank_Transactions | unfiltered — **displayName "LLP Bank Transactions - old"** |
| Payment_Request | All_Payment_Requests | unfiltered |
| | User_Payment_Requests | `Requested_By.Email == zoho.loginuserid` |

**`All_Payments_Hussain` and `View_Payments` are unfiltered copies of `All_Payments`**
differing only in which actions are exposed. `All_Payments_Hussain` still carries
`Delete Paid Payment` and `Duplicate Payment` in its view header.

---

## Where `Delete Paid Payment` is reachable

`workflow = DeletePaidBill`, `show action in view header = true`,
`condition = (Bank_Reconcilation == false)`:

- `All_Payments`
- `All_Payments_Hussain`
- `View_Payments`

Three reports. Any profile with `{Delete}` on any of them can reach it. Currently:
Write, Write-same-as-admin, Account Team-Senior, Account Team-Executive,
Administrator.

Prior field notes: **17 real payments (₹93,884) destroyed** via a delete path treated
as safe.

---

## Findings to carry into the rebuild

1. **Roles as data, not strings.** `roles` + `permissions` tables, FK from employees,
   zero `.contains()` in the auth path.
2. **Scope by ID, never by name substring.** The CA views and `LLP_Bank` both silently
   drop records whose account name lacks a magic token.
3. **One scoping model per user type.** CA gets bills by email and expenses by bank
   name — pick one.
4. **Secrets out of record storage.** `Eko_RS_App_Config` holds a refresh token that
   Account Team-Executive can read.
5. **No hard delete on a settled payment**, and certainly not from a report header.
6. **Payroll authority needs more than one checkbox.** `Is_HR` on one record is the
   entire gate.
7. **Inline email checks must become permissions.** Two behaviours are gated on
   literal email addresses in Deluge; they are invisible to any permission audit.
