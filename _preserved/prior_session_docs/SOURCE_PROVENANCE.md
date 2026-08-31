# Zoho Creator Source — Provenance and Fidelity Warning

**Read this before trusting anything in this folder.**

## What these files are

`Accounts.ds` and `Admin.ds` in this folder were **written by Claude from chat-pasted
content**, not exported from Zoho Creator. Husain pasted the full export text into a
conversation on 12-Aug-2026 and asked for it to be saved.

| File | Original export header | How it got here |
|---|---|---|
| `Admin.ds` | Generated on 12-Aug-2026 19:55:01 | reproduced from paste — **near-complete** |
| `Accounts_LOGIC.ds` | Generated on 12-Aug-2026 19:39:44 | reproduced from paste — **PARTIAL, logic only** |
| `Accounts_SCHEMA.md` | same export | field defs, lookup filters, picklists extracted |
| `Accounts_PERMISSIONS.md` | same export | the 17-profile matrix, closes doc §3.3 |
| `F_B.ds` | — | **not received** (21,994 lines, owns `fb.Booking`) |

### Why Accounts is split three ways

The Accounts export is ~59,000 lines. Reproducing it verbatim from a chat paste
would be slow and would multiply the transcription risk across content that has no
bearing on the rebuild (report column widths, quickview layouts, mobile theming).

So it was split by usefulness:

- **`Accounts_LOGIC.ds`** — every function, workflow, schedule and custom action that
  decides money, reproduced as code. Long procedural bodies that are pure plumbing
  (four near-identical Books pagination loops, the 400-line Haewaya sync, the
  1,200-line Bank_Reconcile UI handlers) are present as **structured comments naming
  every branch, literal and side effect** rather than full transcription. Every
  arithmetic formula, guard, and defect is reproduced in full.
- **`Accounts_SCHEMA.md`** — all 46 forms' fields, types, lookup filters and picklist
  values, plus a table of every hardcoded literal that needs moving to config.
- **`Accounts_PERMISSIONS.md`** — the profile matrix and the provisioning mechanism.

**What is therefore NOT here at all:** the `reports` block (~50 reports: column order,
conditional formatting rules, custom HTML layouts) and the `web`/`phone`/`tablet`
presentation blocks. You need the real export for UI replication work.

## Why that matters

Reproduction from context is **not byte-exact**. Risks, in order of danger:

1. **Arithmetic drift** — a dropped `ifnull()`, `>=` becoming `>`, a lost `.round(2)`.
   These files define payroll (PF/ESIC/PT slabs), TDS/GST computation, and split
   allocation. A plausible-looking error is worse than a missing file.
2. **Hardcoded IDs** — e.g. the zero-GST tax records `292482000003927068` /
   `292482000000130718`, Books `organization_id=60040119506`, the F&B master category
   `292482000000124003`. A single wrong digit is undetectable by eye.
3. **Whitespace / structure** — harmless, but means diffs against a real export will
   be noisy.

**Do not treat these as the authority for any figure you are about to implement.**
Use them to navigate and to know what exists. Verify every formula and every literal
against a real export or the live app before it goes into code.

## How to replace these with the real thing

In Zoho Creator, per app: **Settings → Export** (button top-right of the components
browser), or the ⌄ chevron beside the app name → Export Application / Download Deluge
Script. Drop the `.ds` file here, overwriting. Then delete the corresponding row from
the table above and note the swap.

Once real exports are in place, `wc -l` should be roughly:
Accounts ≈ 59,063 · Admin ≈ 4,162 · F&B ≈ 21,994 (counts from the 08-Aug-2026 export
cited in `ACCOUNTS_REBUILD_CONTEXT.md` §2; the 12-Aug app has grown, so expect more).

## What is NOT in any export, ever

Creator does not put these in the script export. Confirmed: neither pasted file
contains a `blueprints` or `approvals` block anywhere.

- **Blueprints** and **Approval workflows** (Workflow tab in Creator)
- Print templates
- Actual user → profile assignments (the export has profile *definitions* only)
- Record data (structure and script only)

`ACCOUNTS_REBUILD_CONTEXT.md` §8.5 flags Blueprints as the one gap that could
invalidate the whole approval design. Still unverified.

## Credential exposure — unrotated as of 13-Aug-2026

Both pasted files contain live secrets in plaintext. They are therefore also in the
chat transcript and in this repository.

| Secret | Where |
|---|---|
| DoubleTick API key | `Accounts.ds` — `Accounts.Whatsappmessage`, `Accounts.RequestPaymentWhatsapp`, `Standalone.widgetSendWhatsApp` (3 occurrences) |
| Zoho OAuth `clientId` + `clientSecret` | `Accounts.ds` — `Standalone.proxyAnalytics` |

Also stored as plain `text`/`textarea` fields on the `Eko_RS_App_Config` form:
`DoubleTick_API_Key`, `Analytics_Refresh_Token`, `Cached_Access_Token`, `Pin_Hash`.
The **Account Team-Executive** profile has read access to
`Eko_RS_App_Config_Report`.

**Rotate both. Then move them to environment config, never source.**
