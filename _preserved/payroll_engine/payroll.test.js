/**
 * Payroll engine tests.
 *
 * The first block pins LIVE FIGURES from Salary Payouts — Ahmed Accounts,
 * June and July 2026. Those are the acceptance test: if they fail, the port
 * is wrong, regardless of what the rest of the suite says.
 *
 * The remaining blocks pin each deviation and each boundary so that a future
 * "cleanup" cannot quietly change a payslip.
 *
 *   node payroll.test.js
 */

'use strict';

const { CONFIG_V1, splitTotal, computePayout, check, daysInCycle, r2 } = require('./payroll');

let pass = 0, fail = 0;
const failures = [];

function eq(actual, expected, label) {
  const ok = actual === expected;
  if (ok) { pass++; }
  else { fail++; failures.push(`${label}\n      expected ${expected}\n      actual   ${actual}`); }
  console.log(`  ${ok ? '✓' : '✗'} ${label}${ok ? '' : `  → expected ${expected}, got ${actual}`}`);
}

function section(name) { console.log(`\n${name}`); }

/* ═══════════════════════════════════════════════════════════════
   1. LIVE FIGURES — the acceptance test
   ═══════════════════════════════════════════════════════════════
   Ahmed Accounts, from the Salary Payouts record:
     Total ₹25,000 · Head Office Central · Maharashtra
     PF No · ESIC No · Male · age 21
     June 2026: 30 days worked, penalty ₹2,120
     July 2026: 31 days worked, no penalty
   ═══════════════════════════════════════════════════════════════ */

section('1. LIVE FIGURES — Ahmed Accounts');

const ahmed = {
  total: 25000, location: 'Head Office Central', state: 'Maharashtra',
  gender: 'Male', age: 21, pfStatus: 'No', esicStatus: 'No',
};
const ahmedSplit = splitTotal(ahmed.total, ahmed.location, CONFIG_V1);
Object.assign(ahmed, ahmedSplit);

eq(ahmedSplit.basic, 21100, 'base pay = 21100 (fixed band, total in 21k..40k)');
eq(ahmedSplit.hra, 3900, 'HRA = 3900 (total - basic, total <= 31650)');
eq(ahmedSplit.cc, 0, 'CC = 0');

const june = computePayout(
  { cycleMonth: 'June', cycleYear: 2026, daysWorked: 30, penalty: 2120 },
  ahmed, CONFIG_V1);

eq(june.daysInMonth, 30, 'June has 30 days');
eq(june.salary, 25000, 'June salary = 25000 (full month)');
eq(june.basic, 21100, 'June base pay = 21100');
eq(june.hra, 3900, 'June HRA = 3900');
eq(june.employeePF, 0, 'June employee PF = 0 (PF No)');
eq(june.employerPF, 0, 'June employer PF = 0 (PF No)');
eq(june.employeeESIC, 0, 'June employee ESIC = 0 (ESIC No)');
eq(june.pt, 200, 'June PT = 200 (Maharashtra male, >10000, under 65)');
eq(june.payable, 22680, 'June payable = 25000 - 200 PT - 2120 penalty = 22680');
eq(june.ctc, 22880, 'June CTC = 22680 + 200 PT = 22880');

const july = computePayout(
  { cycleMonth: 'July', cycleYear: 2026, daysWorked: 31 },
  ahmed, CONFIG_V1);

eq(july.daysInMonth, 31, 'July has 31 days');
eq(july.salary, 25000, 'July salary = 25000 (full month)');
eq(july.pt, 200, 'July PT = 200');
eq(july.payable, 24800, 'July payable = 25000 - 200 = 24800');
eq(july.ctc, 25000, 'July CTC = 24800 + 200 = 25000');

/* ═══════════════════════════════════════════════════════════════
   2. DEVIATION 1 — negative HRA
   ═══════════════════════════════════════════════════════════════ */

section('2. DEVIATION 1 — negative HRA band');

eq(splitTotal(21000, 'Lonavala', CONFIG_V1).basic, 14500, '21000 → basic 14500 (low band)');
eq(splitTotal(21000, 'Lonavala', CONFIG_V1).hra, 6500, '21000 → HRA 6500');
eq(splitTotal(21000, 'Lonavala', CONFIG_V1).cc, 0, '21000 → CC 0 (not > band edge)');

eq(splitTotal(21001, 'Lonavala', CONFIG_V1).basic, 21100, '21001 → basic 21100 (jumps above total)');
eq(splitTotal(21001, 'Lonavala', CONFIG_V1).hra, -99, '21001 → HRA −99  ⚠ NEGATIVE');
eq(splitTotal(21050, 'Lonavala', CONFIG_V1).hra, -50, '21050 → HRA −50  ⚠ NEGATIVE');
eq(splitTotal(21099, 'Lonavala', CONFIG_V1).hra, -1, '21099 → HRA −1   ⚠ NEGATIVE');
eq(splitTotal(21100, 'Lonavala', CONFIG_V1).hra, 0, '21100 → HRA 0 (band closes)');

eq(check({ total: 21050, state: 'Maharashtra', gender: 'Male', age: 30 })
  .some(w => w.code === 'NEGATIVE_HRA'), true, 'check() raises NEGATIVE_HRA at 21050');
eq(check({ total: 21100, state: 'Maharashtra', gender: 'Male', age: 30 })
  .some(w => w.code === 'NEGATIVE_HRA'), false, 'check() silent at 21100');

/* ═══════════════════════════════════════════════════════════════
   3. DEVIATION 2 — ESIC on base pay, not gross
   ═══════════════════════════════════════════════════════════════ */

section('3. DEVIATION 2 — ESIC basis');

const esicEmp = { total: 18000, location: 'Lonavala', state: 'Maharashtra',
  gender: 'Female', age: 26, pfStatus: 'No', esicStatus: 'Yes' };
Object.assign(esicEmp, splitTotal(esicEmp.total, esicEmp.location, CONFIG_V1));

eq(esicEmp.basic, 14500, '18000 → basic 14500');
const e = computePayout({ cycleMonth: 'July', cycleYear: 2026, daysWorked: 31 }, esicEmp, CONFIG_V1);
eq(e.employeeESIC, r2(14500 * 0.0075), 'employee ESIC on BASE 14500 = 108.75');
eq(e.employerESIC, r2(14500 * 0.0325), 'employer ESIC on BASE 14500 = 471.25');
eq(e.esicBasisAmount, 14500, 'basis recorded as 14500 (base), not 18000 (gross)');

const grossCfg = { ...CONFIG_V1, esicBasis: 'gross', version: 'test-gross' };
const g = computePayout({ cycleMonth: 'July', cycleYear: 2026, daysWorked: 31 }, esicEmp, grossCfg);
eq(g.employeeESIC, r2(18000 * 0.0075), 'statutory basis would give 135.00 — a 26.25 gap');

// The gate uses prorated gross; the basis uses base. Asymmetry preserved.
const ceil = { ...esicEmp, total: 21500 };
Object.assign(ceil, splitTotal(21500, 'Lonavala', CONFIG_V1));
const c = computePayout({ cycleMonth: 'July', cycleYear: 2026, daysWorked: 31 }, ceil, CONFIG_V1);
eq(c.esicApplied, false, '21500 gross → above 21000 ceiling, no ESIC');
const half = computePayout({ cycleMonth: 'July', cycleYear: 2026, daysWorked: 15 }, ceil, CONFIG_V1);
eq(half.esicApplied, true, '21500 gross at 15/31 days → prorated below ceiling, ESIC applies');

/* ═══════════════════════════════════════════════════════════════
   4. DEVIATION 3 — EDLI doubled
   ═══════════════════════════════════════════════════════════════ */

section('4. DEVIATION 3 — EDLI multiplier');

const pfEmp = { total: 25000, location: 'Head Office Central', state: 'Maharashtra',
  gender: 'Female', age: 34, pfStatus: 'Yes', esicStatus: 'No' };
Object.assign(pfEmp, splitTotal(pfEmp.total, pfEmp.location, CONFIG_V1));

const p = computePayout({ cycleMonth: 'July', cycleYear: 2026, daysWorked: 31 }, pfEmp, CONFIG_V1);
eq(p.employeePF, 1800, 'employee PF capped at 1800 (12% of 21100 = 2532)');
eq(p.edli, r2(Math.min(21100, 15000) * 0.005 * 2), 'EDLI = 15000 × 0.5% × 2 = 150.00');
eq(p.employerPF, r2(1800 + 150), 'employer PF = 1800 EPF+EPS + 150 EDLI = 1950.00');

const statCfg = { ...CONFIG_V1, edliMultiplier: 1, version: 'test-edli-1' };
const s = computePayout({ cycleMonth: 'July', cycleYear: 2026, daysWorked: 31 }, pfEmp, statCfg);
eq(s.edli, 75, 'statutory EDLI = 75.00 — 75.00/month over-accrued per employee');

/* ═══════════════════════════════════════════════════════════════
   5. DEVIATION 4 — PT on prorated salary
   ═══════════════════════════════════════════════════════════════ */

section('5. DEVIATION 4 — PT basis');

const ptEmp = { total: 26000, location: 'Lonavala', state: 'Maharashtra',
  gender: 'Female', age: 30, pfStatus: 'No', esicStatus: 'No' };
Object.assign(ptEmp, splitTotal(ptEmp.total, ptEmp.location, CONFIG_V1));

const full = computePayout({ cycleMonth: 'July', cycleYear: 2026, daysWorked: 31 }, ptEmp, CONFIG_V1);
eq(full.pt, 200, 'full month at 26000 → PT 200 (female, >25000)');

const part = computePayout({ cycleMonth: 'July', cycleYear: 2026, daysWorked: 12 }, ptEmp, CONFIG_V1);
eq(part.salary, r2(26000 * 12 / 31), 'prorated salary = 10064.52');
eq(part.pt, 0, '12/31 days → PT 0, because 10064.52 < 25000 threshold  ⚠ statutory 200');

const monthlyCfg = { ...CONFIG_V1, ptBasis: 'monthly', version: 'test-pt-monthly' };
const m = computePayout({ cycleMonth: 'July', cycleYear: 2026, daysWorked: 12 }, ptEmp, monthlyCfg);
eq(m.pt, 200, 'statutory basis → PT 200 even at 12/31 days');

/* ═══════════════════════════════════════════════════════════════
   6. PT rules — all states, all boundaries
   ═══════════════════════════════════════════════════════════════ */

section('6. PT rules by state');

const ptAt = (state, total, extra = {}) => {
  const emp = { total, location: 'Lonavala', state, gender: 'Male', age: 30,
    pfStatus: 'No', esicStatus: 'No', ...extra };
  Object.assign(emp, splitTotal(total, emp.location, CONFIG_V1));
  return computePayout({ cycleMonth: extra.month || 'July', cycleYear: 2026,
    daysWorked: 31 }, emp, CONFIG_V1).pt;
};

eq(ptAt('Karnataka', 25000), 0, 'Karnataka 25000 → 0 (at threshold)');
eq(ptAt('Karnataka', 25001), 150, 'Karnataka 25001 → 150');
eq(ptAt('Karnataka', 41999), 150, 'Karnataka 41999 → 150');
eq(ptAt('Karnataka', 42000), 200, 'Karnataka 42000 → 200');
eq(ptAt('Karnataka', 42000, { month: 'February' }), 300, 'Karnataka 42000 Feb → 300');

eq(ptAt('Maharashtra', 7500), 0, 'MH male 7500 → 0');
eq(ptAt('Maharashtra', 7501), 175, 'MH male 7501 → 175');
eq(ptAt('Maharashtra', 10000), 175, 'MH male 10000 → 175');
eq(ptAt('Maharashtra', 10001), 200, 'MH male 10001 → 200');
eq(ptAt('Maharashtra', 30000, { month: 'February' }), 300, 'MH male Feb → 300');
eq(ptAt('Maharashtra', 30000, { age: 65 }), 0, 'MH age 65 → exempt');
eq(ptAt('Maharashtra', 30000, { age: 64 }), 200, 'MH age 64 → 200');
eq(ptAt('Maharashtra', 25000, { gender: 'Female' }), 0, 'MH female 25000 → 0');
eq(ptAt('Maharashtra', 25001, { gender: 'Female' }), 200, 'MH female 25001 → 200');
eq(ptAt('Maharashtra', 12000, { gender: 'Female' }), 0, 'MH female 12000 → 0 (male would pay 200)');

eq(ptAt('Tamil Nadu', 3500), 0, 'TN 3500 (×6 = 21000) → 0');
eq(ptAt('Tamil Nadu', 3501), 22.5, 'TN 3501 → 22.50');
eq(ptAt('Tamil Nadu', 20000), 208.33, 'TN 20000 → 208.33 (top slab)');
eq(ptAt('Kerala', 1999), 0, 'Kerala 1999 (×6 = 11994) → 0');
eq(ptAt('Kerala', 2000), 20, 'Kerala 2000 → 20');
eq(ptAt('Kerala', 25000), 208.33, 'Kerala 25000 → 208.33 (top slab)');

eq(ptAt('Goa', 50000), 0, 'Goa → 0 (no PT levied)');
eq(ptAt('Uttarakhand', 50000), 0, 'Uttarakhand → 0 (no PT levied)');
eq(ptAt('Rajasthan', 50000), 0, 'unknown state → 0');
eq(check({ total: 50000, state: 'Rajasthan' }).some(w => w.code === 'PT_STATE_UNKNOWN'),
  true, 'check() raises PT_STATE_UNKNOWN for Rajasthan');

/* ═══════════════════════════════════════════════════════════════
   7. Calendar — days in cycle, leap years
   ═══════════════════════════════════════════════════════════════ */

section('7. Days in billing cycle');

eq(daysInCycle('January', 2026), 31, 'January → 31');
eq(daysInCycle('April', 2026), 30, 'April → 30');
eq(daysInCycle('February', 2026), 28, 'February 2026 → 28');
eq(daysInCycle('February', 2024), 29, 'February 2024 → 29 (÷4)');
eq(daysInCycle('February', 2000), 29, 'February 2000 → 29 (÷400)');
eq(daysInCycle('February', 1900), 28, 'February 1900 → 28 (÷100 not ÷400)');
eq(daysInCycle('February', '2024'), 29, 'year as TEXT → coerced (Year_field is text in source)');
eq(daysInCycle('Feburary', 2026), 30, 'misspelled month → 30 (source default)  ⚠ defect 51');

/* ═══════════════════════════════════════════════════════════════
   8. Proration, floor, and CTC composition
   ═══════════════════════════════════════════════════════════════ */

section('8. Proration and totals');

const base = { total: 30000, location: 'Lonavala', state: 'Goa',
  gender: 'Male', age: 30, pfStatus: 'No', esicStatus: 'No' };
Object.assign(base, splitTotal(30000, 'Lonavala', CONFIG_V1));

const halfMonth = computePayout({ cycleMonth: 'June', cycleYear: 2026, daysWorked: 15 }, base, CONFIG_V1);
eq(halfMonth.salary, 15000, '15/30 days → salary 15000');
eq(halfMonth.prorationFactor, 0.5, 'proration factor 0.5');

const blankDays = computePayout({ cycleMonth: 'June', cycleYear: 2026, daysWorked: '' }, base, CONFIG_V1);
eq(blankDays.daysWorked, 30, 'blank days → full month');

const over = computePayout({ cycleMonth: 'June', cycleYear: 2026, daysWorked: 45 }, base, CONFIG_V1);
eq(over.daysWorked, 30, '45 days in June → clamped to 30');
eq(over.flags.daysClamped, true, 'clamp flagged');

const neg = computePayout({ cycleMonth: 'June', cycleYear: 2026, daysWorked: -5 }, base, CONFIG_V1);
eq(neg.daysWorked, 0, 'negative days → 0');
eq(neg.flags.negativeDaysCoerced, true, 'negative-days coercion flagged');

const bigLoan = computePayout({ cycleMonth: 'June', cycleYear: 2026, daysWorked: 30,
  staffLoan: 40000 }, base, CONFIG_V1);
eq(bigLoan.payable, 0, 'recovery exceeding salary → payable floored to 0');
eq(bigLoan.flags.floored, true, 'floor flagged');
eq(bigLoan.flags.shortfall, 10000, 'shortfall 10000 reported, NOT carried forward');

const reimb = computePayout({ cycleMonth: 'June', cycleYear: 2026, daysWorked: 30,
  otherExpenses: 5000 }, base, CONFIG_V1);
eq(reimb.payable, 35000, 'Other Expenses ADDED, not deducted');

// CTC adds back advance + loan but not penalty.
const ctcCase = computePayout({ cycleMonth: 'June', cycleYear: 2026, daysWorked: 30,
  staffAdvance: 1000, staffLoan: 2000, penalty: 500 }, base, CONFIG_V1);
eq(ctcCase.payable, 26500, 'payable = 30000 − 1000 − 2000 − 500 = 26500');
eq(ctcCase.ctc, 29500, 'CTC = 26500 + 1000 + 2000 = 29500 (penalty NOT added back)');

/* ═══════════════════════════════════════════════════════════════
   9. Metro HRA and the >40k band
   ═══════════════════════════════════════════════════════════════ */

section('9. Metro HRA and percentage band');

eq(splitTotal(50000, 'Lonavala', CONFIG_V1).basic, 27500, '50000 → basic 27500 (55%)');
eq(splitTotal(50000, 'Lonavala', CONFIG_V1).hra, 11000, '50000 non-metro → HRA 40% of basic');
eq(splitTotal(50000, 'Head Office Central', CONFIG_V1).hra, 13750, '50000 metro → HRA 50% of basic');
eq(splitTotal(50000, 'Head Office Central', CONFIG_V1).cc, r2(50000 - 27500 - 13750),
  '50000 metro → CC = remainder 8750');
eq(splitTotal(31650, 'Head Office Central', CONFIG_V1).hra, r2(31650 - 21100),
  '31650 → HRA = total − basic (at hraBalanceUpTo edge)');

/* ═══════════════════════════════════════════════════════════════
   10. Rounding — half-away-from-zero, incl. negatives
   ═══════════════════════════════════════════════════════════════ */

section('10. Rounding');

// Half-away-from-zero, verified symmetric. This is the property that JS
// Math.round alone does NOT have: Math.round(-0.5) is -0, not -1.
eq(r2(0.125), 0.13, 'r2(0.125) = 0.13 (exactly representable, rounds up)');
eq(r2(-0.125), -0.13, 'r2(-0.125) = -0.13 (away from zero — Math.round gives -0.12)');
eq(r2(2.675), 2.68, 'r2(2.675) = 2.68');
eq(r2(-2.675), -2.68, 'r2(-2.675) = -2.68 (symmetric)');
eq(r2(null), 0, 'r2(null) = 0');
eq(r2(undefined), 0, 'r2(undefined) = 0');
eq(r2(''), 0, "r2('') = 0");

/* ── FLOAT LIMIT — documented, not a bug ─────────────────────────
   1.005 has no exact binary representation; it is stored as
   1.00499999999999989341858963598497211933135986328125. Rounding that
   to 2dp gives 1.00, and no amount of care in r2 changes it. Deluge runs
   on the JVM and hits the identical limit, so this MATCHES the source.

   It is also the reason the rebuild must use integer paise or
   NUMERIC(14,2) rather than floats. Pinned here so nobody "fixes" r2
   and silently diverges from the live system. */
eq(r2(1.005), 1, 'r2(1.005) = 1.00 — float limit, matches Deluge/JVM');
eq(r2(-1.005), -1, 'r2(-1.005) = -1.00 — same limit, symmetric');
eq(1.005 * 100 < 100.5, true, '1.005 × 100 is BELOW 100.5 in IEEE-754 — proof of the limit');

/* ═══════════════════════════════════════════════════════════════ */

console.log(`\n${'─'.repeat(66)}`);
console.log(`  ${pass} passed, ${fail} failed`);
if (fail) {
  console.log(`\nFAILURES:\n`);
  failures.forEach((f, i) => console.log(`  ${i + 1}. ${f}\n`));
}
console.log(`${'─'.repeat(66)}\n`);
process.exit(fail ? 1 : 0);
