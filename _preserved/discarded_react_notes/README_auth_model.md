# Accounts — the access model

The header used to read `Husain Khatumdi` because that string was compiled into
all six modules. This directory replaces it with a real session.

```
LoginScreen.jsx  the sign-in screen
credentials.js   test passwords — deleted when the API lands
dashboardData.js dashboard fixtures
roles.js        roles + permissions as data, and Creator's resolver
testUsers.js    13 fictional users, one per branch of the model
villaScope.js   locations, and villas with a real location_id
session.jsx     the provider: who is signed in, what they may do
AccountMenu.jsx the app-bar control
Denied.jsx      shown instead of a module the user may not view
auth.test.mjs   152 assertions   →  npm run test:auth
```

## What Creator does

Access is decided by seven chained `.contains()` tests against
`Employee_Master.User_Role` — a free-text field with `others option = true`
(`Admin.ds:1184-1254`). Four consequences, all real:

| | |
|---|---|
| `"accounts head"` matches **lowercase only** | `"Accounts Head"` falls through every branch → **no access, no error** |
| `.contains()` is a substring test | `"Assistant Property Manager"` gets a Property Manager's rights |
| Branch order breaks ties | `"Property Manager / Market Head"` → Property Manager |
| The Active/Inactive arms test in **different order** | same set, different sequence |

`resolveCreatorRole()` reproduces all four exactly. It exists for **import**:
map the legacy strings once, then never call it again — after import, `role_id`
is a foreign key.

## What the rebuild does instead

Roles and permissions are rows, matching `backend/schema/001_core.sql`. Two
things worth calling out.

**`payment.reverse` is separate from `payment.edit`.** Editing a payment and
unwinding a settled one are different levels of trust; Creator had no such
distinction, and 17 real payments (₹93,884) were destroyed through it. Three
roles may edit; one may reverse.

**Scoping compares IDs, never name substrings.** This is the one place Creator
gets it right — `Villa[Location == recid]` in `SendVillaName` — and the place its
CA views and `LLP_Bank` get it wrong. The fixture proves why: `Lonavla Central`
is spelled without the second `a`, so a name filter for `Lonavala` silently
drops it. Five villas by ID, four by name.

A payment is visible when **any** of its split legs names a permitted villa.
Vikram Rane (Lonavala) sees 4 of 12 payments, and one of those spans Lonavala
and Karjat — it appears, because hiding it would leave his totals unexplainable.

## The Is_HR gate, reproduced

Creator gates *all* payroll editing on one boolean on one record, independent of
role. Sneha and Rohan share the Human Resources role; only Sneha has
`Is_HR = true`, so only Sneha can edit. Rohan opens Salary Payouts and changes
nothing — Creator's real behaviour, kept, with `grantsWithIsHr` making the gate
visible rather than implicit. `Is_HR` alone does **not** escalate a Property
Manager, and that is pinned by test.

## The one deviation

Creator grants **Account Team-Executive** read access to
`Eko_RS_App_Config.Analytics_Refresh_Token` — a live credential readable by the
most junior accounts profile. Not reproduced. A test asserts no role holds any
permission naming a token or secret.

## Test users

Thirteen fictional people on the reserved `@example.test` domain. No real
employee's name, email or phone appears — the live `Employee_Master` was read
only, and nothing personal was copied. Three exist to demonstrate failures:

- **Farida Merchant** — inactive, full role, zero permissions
- **Kabir Sethi** — `User_Role` is `"Accounts Head"`, so Creator's lowercase-only
  match denies them silently
- **Rohan Desai** — the Is_HR half of the payroll pair

## Swapping in real auth

`session.jsx` is the only file that knows where users come from. Replace
`TEST_USERS` with a fetch and `signIn` with a POST; the hooks and every call site
stay as they are. Then set `enableSwitch={false}` on `AccountMenu` and drop the
`as` picker from `Shell.jsx` — both are harness, and both are fenced.

---

## Sign-in

The app opens on a login screen; no session, no app. Creator has no equivalent —
it delegates identity to Zoho's portal and `Access.Accounts()` only grants an
already-authenticated account a role — so this is designed, not copied.

**All test accounts use the password `ekostay2026`.** Click any name in the
Test accounts panel to fill the form.

Two properties are pinned by test:

- **No user enumeration.** A wrong password and an unknown email return the
  *identical* message. The internal `reason` still distinguishes them, for
  `auth_login_attempts.failure`, but it never reaches the client.
- **A correct password is not enough.** Farida (inactive) and Kabir (unmapped
  role) hold the right password and are still refused, at the two points Creator
  refuses them.

`credentials.js` is development-only and says so at the top. Passwords in a file
the browser downloads are fine against fixtures and unacceptable with real data —
the file is deleted when the API lands and verification moves server-side against
`auth_credentials.password_hash`.

### The tables

`backend/schema/004_auth.sql` — 4 tables, 30 constraints:

| Table | Why |
|---|---|
| `auth_credentials` | Separate from `employees`: most employees never log in, and a nullable hash makes "no password set" and "any password accepted" the same state |
| `auth_sessions` | Server-side, not JWT — an accounts app must be able to revoke immediately. Stores a SHA-256, never the token |
| `auth_login_attempts` | Rate limiting per email *and* per IP, plus the audit trail this project already needed once |
| `auth_password_resets` | Single-use, enforced by a partial unique index rather than app code |

## Dashboard

`src/DashboardModule.jsx` — the landing screen after sign-in. Creator's
`Accounts` rail item opens a page with no scripted content, so there was nothing
to reproduce. Three rules held while designing it:

1. **Every tile is scoped.** A Lonavala manager's totals cover Lonavala. A tile
   that quietly mixed in other locations would be worse than no tile.
2. **Tiles a role cannot act on are not rendered** — not greyed out. A disabled
   number still leaks its magnitude.
3. **Nothing is a new calculation.** Tiles sum the rows the modules already show,
   so the dashboard cannot disagree with the report it links to.

Priya (Senior) sees 7 tiles; Vikram (Lonavala) sees 4; a Property Owner sees the
empty state — which is the access model working, not a fault.
