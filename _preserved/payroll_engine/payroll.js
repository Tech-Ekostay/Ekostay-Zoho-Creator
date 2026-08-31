/**
 * Payroll engine — faithful port of the Zoho Creator Deluge implementation.
 *
 * SOURCE OF TRUTH
 *   accounts.Salary_Payouts.OnInputTotalAmountCE          (Total -> Base/HRA/CC)
 *   accounts.Salary_Payouts.OnInputNumberofdaysworkedCE   (per-payout computation)
 *   See zoho_source/Accounts_LOGIC.ds and OPEN_QUESTIONS.md §A5.
 *
 * DESIGN RULE — COPY, DO NOT CORRECT.
 *   The current system's output is the specification. Four of its behaviours
 *   deviate from Indian statutory rules; all four are reproduced exactly and
 *   marked DEVIATION below. Changing any of them changes historical payslips,
 *   which is a business decision, not a porting decision.
 *
 *   Every rate, band, ceiling and basis is configuration with an effective
 *   date, so a future correction is a new config row rather than a code edit
 *   that silently rewrites the past. `computePayout` records which config
 *   version produced each payslip in its return value.
 *
 * NO FLOATING-POINT MONEY IN THE REBUILD.
 *   This module uses JS numbers because the source does, and matching the
 *   source bit-for-bit is the whole point. When this logic moves into the new
 *   backend, amounts become integer paise or NUMERIC(14,2). The rounding
 *   points here (r2 after each component) are load-bearing and must survive
 *   that translation — see ROUNDING notes.
 */

'use strict';

/* ─────────────────────────────────────────────────────────────────
   Rounding
   ───────────────────────────────────────────────────────────────── */

/**
 * Deluge's .round(2). Half-away-from-zero, matching Java BigDecimal
 * HALF_UP as Deluge applies it — NOT JS Math.round, which is
 * half-toward-positive-infinity and differs on negative halves.
 *
 * Negative amounts do occur here: the negative-HRA band (DEVIATION 1)
 * produces them, so the difference is reachable in production.
 */
function r2(n) {
  if (n === null || n === undefined || Number.isNaN(+n)) return 0;
  const x = +n;
  const sign = x < 0 ? -1 : 1;
  return sign * Math.round(Math.abs(x) * 100) / 100;
}

/** Deluge ifnull(x, 0) — null and undefined become 0, but 0 stays 0. */
const n0 = (v) => (v === null || v === undefined || v === '' || Number.isNaN(+v) ? 0 : +v);

/* ─────────────────────────────────────────────────────────────────
   Configuration
   ───────────────────────────────────────────────────────────────── */

const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June',
  'July', 'August', 'September', 'October', 'November', 'December'];

/**
 * Config as it stands in the live system on 13-Aug-2026.
 *
 * `version` and `effectiveFrom` exist so a payslip can be reproduced years
 * later: pick the config whose window contains the payout's billing cycle.
 * Never mutate a published version — add a new one.
 */
const CONFIG_V1 = Object.freeze({
  version: 'v1-as-built-2026-08',
  effectiveFrom: null,          // open start — applies to all historical cycles
  effectiveTo: null,            // open end — current

  // ── Base pay banding ────────────────────────────────────────────
  // Deluge: if(total <= 40000) { if(total <= 21000) 14500 else 21100 }
  //         else total * 0.55
  basicBandLow: 21000,
  basicLow: 14500,
  basicBandHigh: 40000,
  basicHigh: 21100,
  basicPctAbove: 0.55,

  // ── HRA ─────────────────────────────────────────────────────────
  // Deluge: if(total <= 31650) hra = total - basePay
  //         else hra = basePay * (metro ? 0.50 : 0.40)
  hraBalanceUpTo: 31650,
  hraMetroPct: 0.50,
  hraNonMetroPct: 0.40,
  metros: Object.freeze(['Delhi', 'Mumbai', 'Head Office Central', 'Bengaluru',
    'Kolkata', 'Chennai', 'Hyderabad', 'Ahmedabad', 'Pune']),

  // ── Provident fund ──────────────────────────────────────────────
  pfPct: 0.12,
  pfMonthlyCap: 1800,
  pfWageCeiling: 15000,
  epfShare: 3.67,               // of the 12 points
  epsShare: 8.33,
  edliPct: 0.005,
  edliMultiplier: 2,            // DEVIATION 3 — statutory is 1

  // ── ESIC ────────────────────────────────────────────────────────
  esicEmployeePct: 0.0075,
  esicEmployerPct: 0.0325,
  esicWageCeiling: 21000,       // tested against prorated salary, per source
  esicBasis: 'base',            // DEVIATION 2 — statutory is 'gross'

  // ── Professional tax ────────────────────────────────────────────
  ptBasis: 'prorated',          // DEVIATION 4 — statutory is 'monthly'
  ptAgeExemption: 65,
});

/* ─────────────────────────────────────────────────────────────────
   Professional tax, per state
   ───────────────────────────────────────────────────────────────── */

/**
 * Ported branch-for-branch from OnInputNumberofdaysworkedCE.
 *
 * Deluge has no branch for Goa or Uttarakhand and leaves row.PT at its
 * initialised 0. Neither state levies PT, so that is correct rather than an
 * omission — but it is silence, not an explicit rule. Both are declared
 * here so an unhandled state is distinguishable from a nil-rated one.
 */
const PT_RULES = Object.freeze({
  Karnataka: {
    levied: true,
    note: 'Nil ≤ ₹25,000 · ₹150 ≤ ₹41,999 · ₹200 above (₹300 in February)',
    calc: (salary, { month }) => {
      if (salary <= 25000) return 0;
      if (salary <= 41999) return 150;
      return month === 'February' ? 300 : 200;
    },
  },

  Maharashtra: {
    levied: true,
    note: 'Men: ₹175 above ₹7,500, ₹200 above ₹10,000. Women: ₹200 above ₹25,000. ₹300 in February. Under-65 only.',
    // Deluge nests the age test INSIDE each salary branch, so age >= 65
    // falls through every branch and PT stays 0. Same outcome as an early
    // return; kept as a guard for legibility.
    calc: (salary, { month, gender, age, cfg }) => {
      if (age >= cfg.ptAgeExemption) return 0;
      const feb = month === 'February' ? 300 : 200;
      if (gender === 'Male') {
        if (salary > 10000) return feb;
        if (salary > 7500) return 175;
        return 0;
      }
      // Deluge's else covers Female AND any unset/other gender value.
      if (salary > 25000) return feb;
      return 0;
    },
  },

  'Tamil Nadu': {
    levied: true,
    note: 'Half-yearly slabs on salary × 6, expressed monthly',
    // ROUNDING: source hardcodes 22.50 / 52.50 / 170.83 / 208.33 — the
    // already-divided monthly figures. Do not recompute from the half-yearly
    // amount; the source's rounding is what appears on payslips.
    calc: (salary) => {
      const h = salary * 6;
      if (h <= 21000) return 0;
      if (h <= 30000) return 22.50;
      if (h <= 45000) return 52.50;
      if (h <= 60000) return 115;
      if (h <= 75000) return 170.83;
      return 208.33;
    },
  },

  Kerala: {
    levied: true,
    note: 'Half-yearly slabs on salary × 6, expressed monthly',
    calc: (salary) => {
      const h = salary * 6;
      if (h <= 11999) return 0;
      if (h <= 17999) return 20;
      if (h <= 29999) return 30;
      if (h <= 44999) return 50;
      if (h <= 59999) return 75;
      if (h <= 74999) return 100;
      if (h <= 99999) return 125;
      if (h <= 124999) return 166.67;
      return 208.33;
    },
  },

  Goa: { levied: false, note: 'No professional tax levied', calc: () => 0 },
  Uttarakhand: { levied: false, note: 'No professional tax levied', calc: () => 0 },
});

/* ─────────────────────────────────────────────────────────────────
   Calendar
   ───────────────────────────────────────────────────────────────── */

/**
 * Days in the BILLING CYCLE month — not the payment date's month.
 * Deluge reads row.Billing_Cycle.Month_field / .Year_field, and Year_field is
 * stored as TEXT on Billing_Cycles, hence the coercion.
 *
 * Leap rule is the full Gregorian one in source (400/4/100), reproduced here
 * rather than delegated to Date so the branch is auditable.
 */
function daysInCycle(month, year) {
  const y = n0(year);
  switch (month) {
    case 'January': case 'March': case 'May': case 'July':
    case 'August': case 'October': case 'December':
      return 31;
    case 'April': case 'June': case 'September': case 'November':
      return 30;
    case 'February':
      return (y % 400 === 0 || (y % 4 === 0 && y % 100 !== 0)) ? 29 : 28;
    default:
      // Deluge initialises daysInMonth = 30 and only overwrites on a match,
      // so an unrecognised month silently prorates against 30.
      return 30;
  }
}

/* ─────────────────────────────────────────────────────────────────
   Total → Base / HRA / CC
   ───────────────────────────────────────────────────────────────── */

/**
 * Splits the monthly total into its three components.
 * Port of OnInputTotalAmountCE, Automatic mode only. Manual mode leaves all
 * three to the user and this function is not called.
 *
 * DEVIATION 1 — NEGATIVE HRA.
 *   A total in [basicBandLow+1, basicHigh-1] — i.e. ₹21,001..₹21,099 — takes
 *   the fixed basicHigh of ₹21,100, which EXCEEDS the total. HRA is then
 *   total − basic, so it goes negative. At ₹21,050: HRA = −₹50.
 *
 *   This is an unfinished band, not a rule anyone chose. It is reproduced
 *   because the current system does it; `check()` flags it as `danger` so it
 *   cannot pass silently.
 */
function splitTotal(total, location, cfg = CONFIG_V1) {
  const t = n0(total);
  if (t <= 0) return { basic: 0, hra: 0, cc: 0 };

  let basic;
  if (t <= cfg.basicBandHigh) {
    basic = t <= cfg.basicBandLow ? cfg.basicLow : cfg.basicHigh;
  } else {
    basic = r2(t * cfg.basicPctAbove);
  }

  let hra;
  if (t <= cfg.hraBalanceUpTo) {
    hra = t - basic;                       // may be negative — DEVIATION 1
  } else {
    const pct = cfg.metros.includes(location) ? cfg.hraMetroPct : cfg.hraNonMetroPct;
    hra = r2(basic * pct);
  }

  // Deluge: cc = 0 unless total > 21000, then total - basePay - hra.
  const cc = t > cfg.basicBandLow ? t - basic - hra : 0;

  return { basic: r2(basic), hra: r2(hra), cc: r2(cc) };
}

/* ─────────────────────────────────────────────────────────────────
   One payout row → full payslip
   ───────────────────────────────────────────────────────────────── */

/**
 * @param {object} row  { cycleMonth, cycleYear, daysWorked,
 *                        staffAdvance, staffLoan, penalty, otherExpenses }
 * @param {object} emp  { total, basic, hra, cc, location, state,
 *                        gender, age, pfStatus, esicStatus }
 * @param {object} cfg
 */
function computePayout(row, emp, cfg = CONFIG_V1) {
  const month = row.cycleMonth;
  const year = row.cycleYear;
  const dim = daysInCycle(month, year);

  // Deluge alerts and clamps if days > dim; negative returns without writing.
  // Here: clamp high, treat blank as a full month, and report the clamp.
  const rawDays = row.daysWorked;
  const blank = rawDays === null || rawDays === undefined || rawDays === '';
  let worked = blank ? dim : n0(rawDays);
  const clamped = worked > dim;
  if (clamped) worked = dim;
  const negativeDays = worked < 0;
  if (negativeDays) worked = 0;

  const factor = dim > 0 ? worked / dim : 0;

  /* ── Proration ────────────────────────────────────────────────
     Deluge prorates each component INDEPENDENTLY from the stored monthly
     value: row.Salary from Total_Amount, row.Base_pay from Amount, etc.
     It does NOT derive Salary as basic+hra+cc, so the four need not sum.

     ROUNDING: source assigns these without .round(2), then rounds all of
     them at the end of the handler. Net effect is a single rounding per
     component, which is what r2 here reproduces. Do not round twice. */
  const salary = r2(n0(emp.total) * factor);
  const basic  = r2(n0(emp.basic) * factor);
  const hra    = r2(n0(emp.hra) * factor);
  const cc     = r2(n0(emp.cc) * factor);

  /* ── Provident fund ───────────────────────────────────────────
     Employee: 12% of prorated basic, capped at 1800.
     Employer: EPF 3.67 + EPS 8.33 (which sum to the same capped 12%
     figure) plus EDLI. Source computes epf = pf/12*3.67 and
     eps = pf/12*8.33, then adds — reproduced literally so any float
     drift matches. */
  const pfOn = emp.pfStatus === 'Yes';
  const pfCapped = Math.min(basic * cfg.pfPct, cfg.pfMonthlyCap);
  const employeePF = pfOn ? r2(pfCapped) : 0;

  const epf = pfCapped / 12 * cfg.epfShare;
  const eps = pfCapped / 12 * cfg.epsShare;
  const pfBasicForEdli = Math.min(basic, cfg.pfWageCeiling);
  const edli = pfBasicForEdli * cfg.edliPct * cfg.edliMultiplier;  // DEVIATION 3
  const employerPF = pfOn ? r2(epf + eps + edli) : 0;

  /* ── ESIC ─────────────────────────────────────────────────────
     DEVIATION 2 — basis is prorated BASE PAY, not gross wages.
     Note the asymmetry in source: the eligibility GATE tests
     row.Salary <= 21000 (prorated gross), while the BASIS is
     row.Base_pay. Both reproduced. */
  const esicEligible = emp.esicStatus === 'Yes' && salary <= cfg.esicWageCeiling;
  const esicBasis = cfg.esicBasis === 'gross' ? salary : basic;
  const employeeESIC = esicEligible ? r2(esicBasis * cfg.esicEmployeePct) : 0;
  const employerESIC = esicEligible ? r2(esicBasis * cfg.esicEmployerPct) : 0;

  /* ── Professional tax ─────────────────────────────────────────
     DEVIATION 4 — assessed on the PRORATED salary. PT is statutorily a
     function of monthly salary, so any part month can drop below a slab
     and zero the liability. */
  const rule = PT_RULES[emp.state];
  const ptSalary = cfg.ptBasis === 'monthly' ? n0(emp.total) : salary;
  const pt = rule
    ? r2(rule.calc(ptSalary, { month, gender: emp.gender, age: n0(emp.age), cfg }))
    : 0;

  /* ── Recoveries and payable ───────────────────────────────────
     Other_Expenses is ADDED, not deducted — it is a reimbursement.
     Payable is floored at zero: Deluge sets it to 0 if negative, which
     means a large recovery is silently truncated rather than carried
     forward. `floored` surfaces that. */
  const advance = n0(row.staffAdvance);
  const loan    = n0(row.staffLoan);
  const penalty = n0(row.penalty);
  const other   = n0(row.otherExpenses);

  let payable = salary - employeePF - employeeESIC - pt - advance - loan - penalty + other;
  const floored = payable < 0;
  const shortfall = floored ? r2(-payable) : 0;
  if (floored) payable = 0;
  payable = r2(payable);

  /* ── CTC ──────────────────────────────────────────────────────
     Built UP from payable by adding back everything withheld, INCLUDING
     the recoveries (advance + loan) but NOT penalty.

     This is not cost-to-company in the usual sense — it reconstructs
     gross-plus-employer-contributions. The name misleads anyone reading a
     payslip. Preserved exactly; rename in the UI, not here. */
  const ctc = r2(payable + employeePF + employeeESIC + employerPF + employerESIC
    + pt + advance + loan);

  return {
    // period
    month, year, daysInMonth: dim, daysWorked: worked, prorationFactor: factor,
    // earnings
    salary, basic, hra, cc,
    // statutory
    employeePF, employerPF, edli: r2(edli), pfApplied: pfOn,
    employeeESIC, employerESIC, esicApplied: esicEligible,
    esicBasisUsed: cfg.esicBasis, esicBasisAmount: r2(esicBasis),
    pt, ptRuleNote: rule ? rule.note : null, ptLevied: rule ? rule.levied : null,
    ptAssessedOn: r2(ptSalary),
    // recoveries
    staffAdvance: advance, staffLoan: loan, penalty, otherExpenses: other,
    // totals
    payable, ctc,
    employeeDeductions: r2(employeePF + employeeESIC + pt),
    employerContributions: r2(employerPF + employerESIC),
    recoveries: r2(advance + loan + penalty),
    // audit
    configVersion: cfg.version,
    flags: { floored, shortfall, daysClamped: clamped, negativeDaysCoerced: negativeDays,
      negativeHra: hra < 0, unknownState: !rule, unknownMonth: !MONTHS.includes(month) },
  };
}

/* ─────────────────────────────────────────────────────────────────
   Pre-flight checks
   ───────────────────────────────────────────────────────────────── */

/**
 * Conditions worth surfacing before a payslip is issued.
 * `danger` = the output is wrong. `warn` = the output matches the current
 * system but departs from statute.
 */
function check(emp, cfg = CONFIG_V1) {
  const out = [];
  const t = n0(emp.total);

  if (t > cfg.basicBandLow && t < cfg.basicHigh) {
    out.push({ level: 'danger', code: 'NEGATIVE_HRA',
      text: `Total of ₹${t} falls between the ₹${cfg.basicBandLow} band edge and the fixed basic of ₹${cfg.basicHigh}. Basic exceeds total, so HRA computes negative (₹${r2(t - cfg.basicHigh)}).` });
  }
  if (emp.esicStatus === 'Yes' && cfg.esicBasis === 'base') {
    out.push({ level: 'warn', code: 'ESIC_BASIS',
      text: 'ESIC computed on Base Pay. Statute applies it to gross wages — under-contributes.' });
  }
  if (emp.esicStatus === 'Yes' && t > cfg.esicWageCeiling) {
    out.push({ level: 'info', code: 'ESIC_ABOVE_CEILING',
      text: `Marked for ESIC but total ₹${t} exceeds the ₹${cfg.esicWageCeiling} ceiling, so no contribution arises.` });
  }
  if (emp.pfStatus === 'Yes' && cfg.edliMultiplier !== 1) {
    out.push({ level: 'warn', code: 'EDLI_MULTIPLIER',
      text: `EDLI applied at ${(cfg.edliPct * cfg.edliMultiplier * 100).toFixed(2)}% of capped basic; statutory rate is ${(cfg.edliPct * 100).toFixed(2)}%. Over-accrues.` });
  }
  if (cfg.ptBasis === 'prorated') {
    out.push({ level: 'warn', code: 'PT_BASIS',
      text: 'Professional tax assessed on prorated salary. Statute assesses monthly salary, so a part month can fall below a slab.' });
  }
  if (!PT_RULES[emp.state]) {
    out.push({ level: 'danger', code: 'PT_STATE_UNKNOWN',
      text: `No professional tax rule for "${emp.state}". PT will compute as zero.` });
  }
  if (emp.state === 'Maharashtra' && !emp.gender) {
    out.push({ level: 'danger', code: 'PT_GENDER_MISSING',
      text: 'Maharashtra PT depends on gender; unset is treated as the ₹25,000 threshold.' });
  }
  if (emp.state === 'Maharashtra' && !n0(emp.age)) {
    out.push({ level: 'warn', code: 'PT_AGE_MISSING',
      text: 'Maharashtra PT exempts 65+. Age unset is treated as 0, so PT applies.' });
  }
  return out;
}

module.exports = {
  CONFIG_V1, PT_RULES, MONTHS,
  r2, n0, daysInCycle, splitTotal, computePayout, check,
};
