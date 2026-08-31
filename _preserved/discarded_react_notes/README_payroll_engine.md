# Payroll engine

Standalone, tested port of the Zoho Creator payroll calculation. No UI, no
framework, no dependencies. Survives any stack or UI decision.

```
node payroll.test.js     →  104 passed, 0 failed
```

| File | |
|---|---|
| `payroll.js` | the engine — config, PT rules, `splitTotal`, `computePayout`, `check` |
| `payroll.test.js` | 104 assertions, pinned to live figures |

## Source

Ported from `zoho_source/Accounts_LOGIC.ds`:

- `Salary_Payouts.OnInputTotalAmountCE` → `splitTotal()`
- `Salary_Payouts.OnInputNumberofdaysworkedCE` → `computePayout()`

## Acceptance test

Section 1 pins **Ahmed Accounts, June and July 2026** from the live Salary
Payouts record. If those 18 assertions pass, the port is faithful:

| | June 2026 | July 2026 |
|---|---|---|
| Days worked | 30 of 30 | 31 of 31 |
| Salary | ₹25,000.00 | ₹25,000.00 |
| Base pay | ₹21,100.00 | ₹21,100.00 |
| HRA | ₹3,900.00 | ₹3,900.00 |
| PT | ₹200.00 | ₹200.00 |
| Penalty | ₹2,120.00 | — |
| **Payable** | **₹22,680.00** | **₹24,800.00** |
| **CTC** | **₹22,880.00** | **₹25,000.00** |

## Design rule: copy, do not correct

The current system's output is the specification. Four behaviours deviate from
Indian statutory rules; **all four are reproduced exactly** and each has a test
that would fail if someone "cleaned them up."

Every rate, band, ceiling and basis is config with `version` and
`effectiveFrom`. `computePayout` returns `configVersion` on every payslip, so a
2026 payslip stays reproducible after a 2027 rate change. A future correction is
a new config object, never an edit to a published one.

### The four deviations

**1 — Negative HRA. Broken now, not a judgment call.**

Base pay jumps to the fixed ₹21,100 the moment total crosses ₹21,000. For
totals in ₹21,001–21,099 basic exceeds total, so `hra = total − basic` goes
negative.

```
₹21,000 → basic 14,500, HRA  6,500  ✓
₹21,050 → basic 21,100, HRA    −50  ✗
₹21,100 → basic 21,100, HRA      0  ✓
```

An unfinished band, not a rule anyone chose. `check()` returns `danger` /
`NEGATIVE_HRA`. **Ask: has anyone been paid a total in that 99-rupee window?**
If no, this is latent and safe to fix. If yes, those payslips are wrong.

**2 — ESIC on base pay, not gross wages.** `esicBasis: 'base'`. Statute says
gross. At ₹18,000 total / ₹14,500 basic the employee share is ₹108.75 instead
of ₹135.00 — **₹26.25/month short per enrolled employee.** Under-contribution
is a compliance exposure, not a saving.

Note the asymmetry, preserved from source: the eligibility **gate** tests
prorated *gross* against the ₹21,000 ceiling, while the **basis** is base pay.
So someone on ₹21,500 gets no ESIC in a full month but does in a part month.

**3 — EDLI doubled.** `edliMultiplier: 2`, statutory 1. At a ₹15,000 capped
basic: ₹150.00 instead of ₹75.00 — **₹75.00/month over-accrued per employee.**
Over-provisioning; money set aside unnecessarily.

**4 — PT on prorated salary.** `ptBasis: 'prorated'`. PT is statutorily a
function of monthly salary. A woman on ₹26,000 working 12 of 31 days prorates to
₹10,064.52, falls below the ₹25,000 Maharashtra threshold, and pays **₹0 instead
of ₹200.** Any part month can zero the liability.

Flip `esicBasis: 'gross'` or `ptBasis: 'monthly'` or `edliMultiplier: 1` to see
the statutory figure — the tests already assert both sides.

## What the port revealed

Five things not in the docs, found by reading the Deluge line by line.

**1. CTC adds back recoveries. The old JSX did not.**

```
Deluge:  ctc = payable + eePF + eeESIC + erPF + erESIC + pt + advance + loan
JSX:     ctc = payable + eePF + eeESIC + pt + erPF + erESIC
```

`SalaryPayoutsModule.jsx` omitted `advance + loan`. With a ₹1,000 advance and
₹2,000 loan on ₹30,000, the JSX reported CTC ₹26,500 where the live system
reports ₹29,500 — **₹3,000 wrong.** This module follows Deluge. Pinned by test.

Separately: "CTC" is a misnomer. It reconstructs gross-plus-employer-
contributions, not cost to company. Rename in the UI; do not change the maths.

**2. `payable` is floored at zero, and the shortfall vanishes.**

A ₹40,000 recovery against ₹30,000 salary yields payable ₹0 — the ₹10,000
excess is silently dropped, not carried to next month. `computePayout` now
returns `flags.shortfall` so the amount is at least visible. The floor itself is
preserved.

**3. Rounding is half-away-from-zero, and negatives are reachable.**

`Math.round(-0.125)` is `-0.12`; correct is `-0.13`. Deviation 1 produces
negative HRA, so the difference is live, not theoretical. `r2()` handles sign
explicitly.

`r2(1.005)` returns `1.00`, not `1.01` — `1.005` is stored as
`1.00499999...` in IEEE-754. Deluge on the JVM hits the same limit, so this
**matches** the source. Pinned by test with the proof inline, so nobody
"fixes" `r2` and diverges.

This is the concrete argument for **`DECIMAL(16,2)`** in the rebuild — which is
already what the live server uses for every money column (`../DB_FINDINGS.md` §3).

**4. Components are prorated independently and need not sum.**

`salary` comes from Total, `basic` from Amount, `hra` from HRA, `cc` from CC —
four separate multiplications, each rounded once. `salary` is *not* derived as
`basic + hra + cc`, so with negative HRA or an odd day count they can disagree.
Faithful to source. Do not "reconcile" them.

**5. A misspelled month prorates against 30 days.**

`daysInCycle` falls through to 30 for an unrecognised month, matching Deluge's
uninitialised default. Relevant because **defect 51** — F&B spells it
`"Feburary"` and passes it to a function that *creates* billing cycles — means a
junk cycle can reach payroll. `flags.unknownMonth` surfaces it.

## Pre-flight checks

`check(emp, cfg)` returns issues before a payslip is issued:

| level | meaning |
|---|---|
| `danger` | output is wrong — `NEGATIVE_HRA`, `PT_STATE_UNKNOWN`, `PT_GENDER_MISSING` |
| `warn` | matches the current system but departs from statute — `ESIC_BASIS`, `EDLI_MULTIPLIER`, `PT_BASIS` |
| `info` | worth knowing — `ESIC_ABOVE_CEILING` |

## Usage

```js
const { CONFIG_V1, splitTotal, computePayout, check } = require('./payroll');

const emp = { total: 25000, location: 'Head Office Central', state: 'Maharashtra',
              gender: 'Male', age: 21, pfStatus: 'No', esicStatus: 'No' };
Object.assign(emp, splitTotal(emp.total, emp.location, CONFIG_V1));

const issues = check(emp, CONFIG_V1);        // surface before issuing

const slip = computePayout(
  { cycleMonth: 'June', cycleYear: 2026, daysWorked: 30, penalty: 2120 },
  emp, CONFIG_V1);

slip.payable;        // 22680
slip.configVersion;  // 'v1-as-built-2026-08'
slip.flags;          // { floored: false, negativeHra: false, ... }
```

Both `splitTotal` and `computePayout` are pure — same inputs, same output, no
I/O, no clock, no globals.

## When this moves into the backend

1. **Amounts become `DECIMAL(16,2)`** (`NUMERIC(16,2)` in PostgreSQL), matching the
   live server. The rounding points in `computePayout` are load-bearing: each
   component is rounded once, after proration. Preserve that granularity — do not
   round only at the end, and do not round twice.
2. **Config becomes a table** with `version`, `effective_from`, `effective_to`.
   Payslips store `config_version` as a foreign key. Published rows are
   immutable.
3. **PT rules stay code, not data.** They are branching logic with
   state/gender/age/month interactions, not a lookup table. Version them with
   the code.
4. **`check()` runs before every payslip.** A `danger` blocks issue; a `warn`
   is recorded on the payslip.
5. **These tests come along.** Port them first, before any calculation code.
   The Ahmed June/July figures are the contract.

## Open questions for Husain

See `../OPEN_QUESTIONS.md` §A5. Short version:

1. **Has anyone been paid a total between ₹21,001 and ₹21,099?** (Deviation 1.
   Answerable from the Salary Payouts report in a minute.)
2. **Were deviations 2–4 deliberate, and are they defensible?** One question to
   your CA. If deliberate, they stay. If not, fixing them changes historical
   payslips — your call, not mine.
