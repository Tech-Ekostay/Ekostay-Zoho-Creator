# End-to-end suite

```bash
npm run test:e2e            # 73 tests, headless, ~45s
npx playwright test --ui    # watch it happen
npx playwright show-report  # after a failure
npm test                    # unit + e2e together — 396 assertions
```

The dev server is started by the runner, so a stale server on 5173 cannot make a
run pass against yesterday's code.

| File | Covers |
|---|---|
| `01-login.spec.js` | 15 — sign-in, refusal paths, session lifecycle |
| `02-permissions.spec.js` | 19 — the role × module matrix, enforcement |
| `03-scoping.spec.js` | 16 — villa scoping, dashboard |
| `04-modules.spec.js` | 23 — the six reports and their Creator behaviours |

## What it actually asserts

Not "the page loaded". The properties that would cost money if they broke:

- **No user enumeration.** A wrong password and an unknown email must return the
  *identical* string, and neither may contain "no such" / "not found".
- **The role × module matrix**, all eight roles, allowed *and* denied. A role
  that gains a screen fails a test.
- **Nothing out of scope leaks.** Not the row count — every visible row is
  checked to touch a permitted villa.
- **`Lonavla Central`** (misspelled, missing an `a`) is still scoped correctly,
  because scoping is by id. A name-substring filter would drop it — the
  `LLP_Bank` bug in `DB_FINDINGS.md`.
- **Reversal needs a reason**, and the confirm button stays disabled without one.
- **`payment.reverse` is rarer than `payment.edit`** — the Executive can edit and
  cannot reverse.
- **Is_HR gates payroll**, and clicking the indicator cannot grant it.
- **Engine values**: Ahmed's ₹22,680 / ₹22,880 must appear, or the module stopped
  using `ekostay-payroll`.
- **Creator's spellings survive** — a test fails if `Payment InProgress` is ever
  "corrected".

## Two bugs it found on the first run

Both were real, both invisible to the eye, both now fixed and pinned:

1. **`Alt+7` did nothing.** The handler matched against the literal `"123456"`,
   so adding the dashboard as a seventh module silently orphaned the last
   shortcut. Now derived from `MODULES`, so it cannot drift again.
2. **The Salary Payouts dashboard tile did not navigate.** `"Salary Payouts"` was
   missing from `RAIL_TO_MODULE`, so the tile looked live and was inert — the
   same class of bug as the dead sidebar.

A third, found by looking rather than asserting: **empty tiles**. A Property
Manager saw "Draft payments · 0 records · ₹0" taking a slot. Zero-count tiles are
now dropped, with a distinct all-clear message so "nothing to do" reads
differently from "no permissions".

## Five test failures that were my fault, not the app's

Worth recording, because the instinct on a red test is to change the code:

- `.zc-search input` — wrong class; it is `.zc-searchchip input`.
- Searching a vendor name while the field selector said **Payment No**. The app
  correctly returned nothing.
- Comparing whole-row text to detect sorting. Ascending leaves the first row
  first, so nothing appeared to change. Now reads the column and asserts order.
- Expecting `−₹50.00`; the app renders `−₹ 50.00`, with a space.
- Expecting ₹22,680 on the grid. It lives in the record's payout table.

Two of my `node -e` edits also stripped `\d` and `\s` from regexes via shell
escaping. Spec files are edited directly now.

## One flaky test, fixed

`every other test account can actually sign in` does eleven sequential sign-ins,
each paying the form's deliberate ~420ms delay. It exceeded the default 30s
timeout once under 8-worker contention, then passed alone and on a clean full
run. The assertion was sound; the budget was not. Marked `test.slow()` rather
than left to flake — a suite people learn to re-run is a suite they stop trusting.
