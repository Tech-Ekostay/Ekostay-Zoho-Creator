import React, { useState, useMemo, useEffect } from "react";
import {
  Wallet, Landmark, ReceiptText, FileSpreadsheet, CalendarClock, Eye, Database,
  Search, Plus, X, ChevronRight, ChevronLeft, ChevronDown, ArrowUpDown, Trash2, Pencil,
  AlertTriangle, Check, SlidersHorizontal, Users, Info, Calculator, Copy,
} from "lucide-react";

/* ═══════════════════════════════════════════════════════════════
   Statutory configuration.

   Every rate, band and ceiling the payroll engine uses lives here so it
   can be corrected without touching the calculation. Four of these
   defaults reproduce known deviations in the current system and are
   flagged in the UI where they bite — see WARNINGS below.
   ═══════════════════════════════════════════════════════════════ */

const DEFAULT_CONFIG = {
  // Automatic split of Total Amount into Base Pay / HRA / CC
  basicBandLow: 21000, basicLow: 14500,
  basicBandHigh: 40000, basicHigh: 21100,
  basicPctAbove: 0.55,
  hraBalanceUpTo: 31650,
  hraMetroPct: 0.50, hraNonMetroPct: 0.40,
  metros: ["Delhi", "Mumbai", "Head Office Central", "Bengaluru", "Kolkata", "Chennai", "Hyderabad", "Ahmedabad", "Pune"],

  // Provident fund
  pfPct: 0.12, pfMonthlyCap: 1800, pfWageCeiling: 15000,
  edliPct: 0.005, edliMultiplier: 2,          // current system doubles EDLI

  // Employees' State Insurance
  esicEmployeePct: 0.0075, esicEmployerPct: 0.0325, esicWageCeiling: 21000,
  esicBasis: "base",                           // "base" (current) | "gross" (statutory)

  // Professional tax
  ptBasis: "prorated",                         // "prorated" (current) | "monthly"
  ptAgeExemption: 65,
};

const LOCATIONS = ["Head Office Central", "Lonavala", "Karjat", "Alibaug", "Goa", "Kodaikanal", "Chikmagalur"];
const STATES = ["Maharashtra", "Karnataka", "Tamil Nadu", "Kerala", "Goa", "Uttarakhand"];
const DESIGNATIONS = ["accounts head", "Account Team-Senior", "Account Team-Executive", "administrator", "property manager", "Central Operations"];
const BANKS = [{ id: "b1", name: "EKOSTAY LLP 1" }, { id: "b2", name: "EKOSTAY HOSPITALITY LLP" }, { id: "b3", name: "Haewaya EKOSTAY LLP" }];
const COA = [{ id: "c1", name: "Expense" }, { id: "c2", name: "Accounts Payable" }];
const MONTHS = ["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"];
const VILLAS = [
  { id: "v1", name: "Head Office Central", location: "Head Office Central" },
  { id: "v2", name: "Lonavla Central", location: "Lonavala" },
  { id: "v3", name: "Karjat Central", location: "Karjat" },
];

/** Professional tax by state. Monthly liability. */
const PT_RULES = {
  Karnataka: {
    note: "Nil to ₹25,000 · ₹150 to ₹41,999 · ₹200 above, ₹300 in February",
    calc: (salary, { month }) => (salary <= 25000 ? 0 : salary <= 41999 ? 150 : month === "February" ? 300 : 200),
  },
  Maharashtra: {
    note: "Men: ₹175 above ₹7,500, ₹200 above ₹10,000. Women: ₹200 above ₹25,000. ₹300 in February. Exempt at 65+.",
    calc: (salary, { month, gender, age, cfg }) => {
      if (age >= cfg.ptAgeExemption) return 0;
      if (gender === "Male") {
        if (salary > 10000) return month === "February" ? 300 : 200;
        if (salary > 7500) return 175;
        return 0;
      }
      if (salary > 25000) return month === "February" ? 300 : 200;
      return 0;
    },
  },
  "Tamil Nadu": {
    note: "Half-yearly slabs on salary × 6, converted to a monthly figure",
    calc: (salary) => {
      const h = salary * 6;
      if (h <= 21000) return 0;
      if (h <= 30000) return 22.5;
      if (h <= 45000) return 52.5;
      if (h <= 60000) return 115;
      if (h <= 75000) return 170.83;
      return 208.33;
    },
  },
  Kerala: {
    note: "Half-yearly slabs on salary × 6, converted to a monthly figure",
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
  Goa: { note: "No professional tax levied", calc: () => 0 },
  Uttarakhand: { note: "No professional tax levied", calc: () => 0 },
};

/* ── helpers ─────────────────────────────────────────────────── */

const inr = (n, { paise = true, bare = false } = {}) => {
  if (n === null || n === undefined || Number.isNaN(+n)) return "—";
  const neg = +n < 0;
  const [i, d] = Math.abs(+n).toFixed(2).split(".");
  let last3 = i.slice(-3), rest = i.slice(0, -3);
  if (rest) last3 = "," + last3;
  rest = rest.replace(/\B(?=(\d{2})+(?!\d))/g, ",");
  return `${neg ? "−" : ""}${bare ? "" : "₹"}${rest}${last3}${paise ? "." + d : ""}`;
};
const uid = () => Math.random().toString(36).slice(2, 9);
const byId = (a, id) => a.find((x) => x.id === id);
const r2 = (n) => Math.round(n * 100) / 100;
const fmtDate = (s) => {
  if (!s) return "—";
  const d = new Date(s + "T00:00:00");
  return `${String(d.getDate()).padStart(2, "0")} ${d.toLocaleString("en", { month: "short" })} ${d.getFullYear()}`;
};
const daysIn = (month, year) => {
  const i = MONTHS.indexOf(month);
  if (i < 0) return 30;
  return new Date(+year, i + 1, 0).getDate();
};

/**
 * Split a total monthly cost into Base Pay, HRA and CC.
 * Reproduces the source system's banding, including its behaviour just above
 * the low band where the fixed basic can exceed the total.
 */
function splitTotal(total, location, cfg) {
  const t = +total || 0;
  if (t <= 0) return { basic: 0, hra: 0, cc: 0 };
  let basic;
  if (t <= cfg.basicBandHigh) basic = t <= cfg.basicBandLow ? cfg.basicLow : cfg.basicHigh;
  else basic = r2(t * cfg.basicPctAbove);

  let hra;
  if (t <= cfg.hraBalanceUpTo) hra = t - basic;
  else hra = r2(basic * (cfg.metros.includes(location) ? cfg.hraMetroPct : cfg.hraNonMetroPct));

  const cc = t > cfg.basicBandLow ? t - basic - hra : 0;
  return { basic: r2(basic), hra: r2(hra), cc: r2(cc) };
}

/** Full payslip for one payout row. */
function computePayout(row, emp, cfg) {
  const month = row.cycleMonth, year = row.cycleYear;
  const dim = daysIn(month, year);
  const worked = row.daysWorked === "" || row.daysWorked == null ? dim : Math.min(+row.daysWorked, dim);
  const f = dim ? worked / dim : 0;

  const salary = r2((+emp.total || 0) * f);
  const basic = r2((+emp.basic || 0) * f);
  const hra = r2((+emp.hra || 0) * f);
  const cc = r2((+emp.cc || 0) * f);

  const pfOn = emp.pfStatus === "Yes";
  const pfBase = Math.min(basic * cfg.pfPct, cfg.pfMonthlyCap);
  const employeePF = pfOn ? r2(pfBase) : 0;
  const edli = Math.min(basic, cfg.pfWageCeiling) * cfg.edliPct * cfg.edliMultiplier;
  const employerPF = pfOn ? r2(pfBase + edli) : 0;

  const esicBasis = cfg.esicBasis === "gross" ? salary : basic;
  const esicOn = emp.esicStatus === "Yes" && salary <= cfg.esicWageCeiling;
  const employeeESIC = esicOn ? r2(esicBasis * cfg.esicEmployeePct) : 0;
  const employerESIC = esicOn ? r2(esicBasis * cfg.esicEmployerPct) : 0;

  const ptRule = PT_RULES[emp.state];
  const ptSalary = cfg.ptBasis === "monthly" ? +emp.total || 0 : salary;
  const pt = ptRule ? r2(ptRule.calc(ptSalary, { month, gender: emp.gender, age: +emp.age || 0, cfg })) : 0;

  const advance = +row.advance || 0, loan = +row.loan || 0, penalty = +row.penalty || 0, other = +row.other || 0;
  let payable = salary - employeePF - employeeESIC - pt - advance - loan - penalty + other;
  const floored = payable < 0;
  if (floored) payable = 0;
  payable = r2(payable);

  const ctc = r2(payable + employeePF + employeeESIC + pt + employerPF + employerESIC);
  const employeeDeductions = r2(employeePF + employeeESIC + pt);
  const recoveries = r2(advance + loan + penalty);
  const employerCost = r2(employerPF + employerESIC);

  return { dim, worked, salary, basic, hra, cc, employeePF, employerPF, edli: r2(edli),
    employeeESIC, employerESIC, esicOn, pfOn, pt, ptNote: ptRule?.note, advance, loan, penalty, other,
    payable, ctc, employeeDeductions, recoveries, employerCost, floored };
}

/** Configuration and data issues worth surfacing on the record itself. */
function warnings(emp, cfg) {
  const w = [];
  const t = +emp.total || 0;
  if (t > cfg.basicBandLow && t < cfg.basicHigh)
    w.push({ tone: "danger", text: `A total of ${inr(t, { paise: false })} sits between ${inr(cfg.basicBandLow, { paise: false })} and the fixed basic of ${inr(cfg.basicHigh, { paise: false })}, so HRA computes negative. Raise the total or the band.` });
  if (emp.esicStatus === "Yes" && cfg.esicBasis === "base")
    w.push({ tone: "warn", text: "ESIC is computed on Base Pay. Statutorily it applies to gross wages — switch the basis in settings if that's the intent." });
  if (emp.esicStatus === "Yes" && t > cfg.esicWageCeiling)
    w.push({ tone: "warn", text: `Marked in ESIC but the total of ${inr(t, { paise: false })} is above the ${inr(cfg.esicWageCeiling, { paise: false })} ceiling, so no contribution is calculated.` });
  if (emp.pfStatus === "Yes" && cfg.edliMultiplier !== 1)
    w.push({ tone: "warn", text: `EDLI is applied at ${(cfg.edliPct * cfg.edliMultiplier * 100).toFixed(2)}% of capped basic. The statutory rate is ${(cfg.edliPct * 100).toFixed(2)}%.` });
  if (cfg.ptBasis === "prorated")
    w.push({ tone: "warn", text: "Professional tax is assessed on the prorated salary, so a part month can fall below a slab. Statutorily it applies to monthly salary." });
  if (!PT_RULES[emp.state])
    w.push({ tone: "danger", text: `No professional tax rule defined for ${emp.state}.` });
  return w;
}

/* ── seed ────────────────────────────────────────────────────── */

const mkEmp = (o) => {
  const cfg = DEFAULT_CONFIG;
  const s = splitTotal(o.total, o.location, cfg);
  return { id: uid(), villa: "v1", coa: "c1", bank: "b1", paymentType: "Payment",
    itemCat: "STAFF SALARY", masterCat: "Employee & Staff Considerations",
    periods: [{ id: uid(), start: "May-2026", end: "March-2027" }], payouts: [], entity: "",
    ...s, ...o };
};

const SEED = [
  mkEmp({ name: "Ahmed Accounts", designation: "Account Team-Executive", location: "Head Office Central", state: "Maharashtra",
    total: 25000, pfStatus: "No", esicStatus: "No", gender: "Male", age: 21,
    payouts: [
      { id: uid(), cycleMonth: "June", cycleYear: 2026, paymentDate: "2026-07-07", daysWorked: 30, advance: "", loan: "", penalty: 2120, other: "", created: true },
      { id: uid(), cycleMonth: "July", cycleYear: 2026, paymentDate: "2026-08-07", daysWorked: 31, advance: "", loan: "", penalty: "", other: "", created: false },
    ] }),
  mkEmp({ name: "Archana Rakeshkumar Lodh", designation: "administrator", location: "Head Office Central", state: "Maharashtra",
    total: 25000, pfStatus: "Yes", esicStatus: "No", gender: "Female", age: 34,
    payouts: [{ id: uid(), cycleMonth: "July", cycleYear: 2026, paymentDate: "2026-08-07", daysWorked: 31, advance: "", loan: "", penalty: "", other: "", created: false }] }),
  mkEmp({ name: "Sharmeen Accounts", designation: "Account Team-Executive", location: "Head Office Central", state: "Maharashtra",
    total: 18000, pfStatus: "No", esicStatus: "Yes", gender: "Female", age: 26,
    payouts: [{ id: uid(), cycleMonth: "July", cycleYear: 2026, paymentDate: "2026-08-07", daysWorked: 28, advance: 2000, loan: "", penalty: "", other: "", created: false }] }),
  mkEmp({ name: "Mansi Pandey Accounts", designation: "Account Team-Executive", location: "Head Office Central", state: "Maharashtra",
    total: 25000, pfStatus: "Yes", esicStatus: "No", gender: "Female", age: 29,
    payouts: [{ id: uid(), cycleMonth: "July", cycleYear: 2026, paymentDate: "2026-08-07", daysWorked: 31, advance: "", loan: "", penalty: "", other: "", created: false }] }),
  mkEmp({ name: "Komal Accounts", designation: "Account Team-Senior", location: "Head Office Central", state: "Maharashtra",
    total: 25000, pfStatus: "Yes", esicStatus: "No", gender: "Female", age: 31, payouts: [] }),
  mkEmp({ name: "Aditya Accounts", designation: "accounts head", location: "Head Office Central", state: "Maharashtra",
    total: 28000, pfStatus: "Yes", esicStatus: "No", gender: "Male", age: 33,
    payouts: [{ id: uid(), cycleMonth: "July", cycleYear: 2026, paymentDate: "2026-08-07", daysWorked: 31, advance: "", loan: "", penalty: "", other: "", created: false }] }),
  mkEmp({ name: "Simmi Accounts", designation: "Account Team-Executive", location: "Head Office Central", state: "Maharashtra",
    total: 25000, pfStatus: "No", esicStatus: "No", gender: "Female", age: 24, payouts: [] }),
  mkEmp({ name: "YUVRAJ SHANKAR SHINDE", designation: "", location: "Lonavala", state: "Maharashtra", villa: "v2",
    total: 25000, pfStatus: "Yes", esicStatus: "Yes", gender: "Male", age: 38,
    payouts: [{ id: uid(), cycleMonth: "July", cycleYear: 2026, paymentDate: "2026-08-07", daysWorked: 31, advance: "", loan: 1500, penalty: "", other: "", created: false }] }),
  mkEmp({ name: "OMKAR KHANDU GHOLAP", designation: "property manager", location: "Lonavala", state: "Maharashtra", villa: "v2",
    total: 17000, pfStatus: "Yes", esicStatus: "Yes", gender: "Male", age: 30,
    payouts: [{ id: uid(), cycleMonth: "July", cycleYear: 2026, paymentDate: "2026-08-07", daysWorked: 31, advance: "", loan: "", penalty: "", other: "", created: false }] }),
  mkEmp({ name: "Shibli accounts", designation: "Account Team-Senior", location: "Head Office Central", state: "Maharashtra",
    total: 25000, pfStatus: "No", esicStatus: "No", gender: "Male", age: 27, payouts: [] }),
  mkEmp({ name: "Akash gholap ops", designation: "Central Operations", location: "Head Office Central", state: "Maharashtra",
    total: 15000, pfStatus: "Yes", esicStatus: "Yes", gender: "Male", age: 23,
    payouts: [{ id: uid(), cycleMonth: "July", cycleYear: 2026, paymentDate: "2026-08-07", daysWorked: 31, advance: "", loan: "", penalty: "", other: "", created: false }] }),
  mkEmp({ name: "Mayur Food", designation: "", location: "Head Office Central", state: "Maharashtra",
    total: 23000, pfStatus: "No", esicStatus: "No", gender: "Male", age: 25, payouts: [] }),
  mkEmp({ name: "Sahil Accounts", designation: "Account Team-Executive", location: "Head Office Central", state: "Maharashtra",
    total: 45000, pfStatus: "Yes", esicStatus: "Yes", gender: "Male", age: 36,
    payouts: [{ id: uid(), cycleMonth: "July", cycleYear: 2026, paymentDate: "2026-08-07", daysWorked: 31, advance: "", loan: "", penalty: "", other: "", created: false }] }),
];

/* ═══════════════════════════════════════════════════════════════ */

const NAV = [
  { icon: Wallet, label: "Accounts" }, { icon: ReceiptText, label: "Payments" },
  { icon: Landmark, label: "Bank" }, { icon: FileSpreadsheet, label: "Bills" },
  { icon: ReceiptText, label: "Expenses" }, { icon: CalendarClock, label: "Schedule", active: true },
  { icon: Eye, label: "Observations" }, { icon: Database, label: "Masters" },
];

export default function SalaryPayoutsModule() {
  const [cfg, setCfg] = useState(DEFAULT_CONFIG);
  const [emps, setEmps] = useState(SEED);
  const [openId, setOpenId] = useState(null);
  const [editing, setEditing] = useState(null);
  const [showCfg, setShowCfg] = useState(false);
  const [q, setQ] = useState("");
  const [sort, setSort] = useState({ key: "name", dir: "asc" });

  const rows = useMemo(() => {
    let r = emps.filter((e) => !q.trim() || [e.name, e.designation, e.location, e.state].join(" ").toLowerCase().includes(q.toLowerCase()));
    const dir = sort.dir === "asc" ? 1 : -1;
    return [...r].sort((a, b) => {
      const av = a[sort.key] ?? "", bv = b[sort.key] ?? "";
      return (typeof av === "number" ? av - bv : String(av).localeCompare(String(bv))) * dir;
    });
  }, [emps, q, sort]);

  const totals = useMemo(() => {
    let monthly = 0, employer = 0, flagged = 0, pending = 0;
    emps.forEach((e) => {
      monthly += +e.total || 0;
      if (warnings(e, cfg).some((w) => w.tone === "danger")) flagged += 1;
      e.payouts.forEach((p) => {
        const c = computePayout(p, e, cfg);
        employer += c.employerCost;
        if (!p.created) pending += c.payable;
      });
    });
    return { monthly, employer, flagged, pending, count: emps.length };
  }, [emps, cfg]);

  const open = openId ? emps.find((e) => e.id === openId) : null;
  const openIdx = rows.findIndex((e) => e.id === openId);

  const save = (e) => {
    setEmps((prev) => (prev.some((x) => x.id === e.id) ? prev.map((x) => (x.id === e.id ? e : x)) : [e, ...prev]));
    setEditing(null); setOpenId(e.id);
  };
  const setPayout = (empId, poId, patch) =>
    setEmps((prev) => prev.map((e) => (e.id !== empId ? e
      : { ...e, payouts: e.payouts.map((p) => (p.id === poId ? { ...p, ...patch } : p)) })));

  return (
    <>
      <Style />
      <div className="app">
        <nav className="rail" aria-label="Modules">
          <div className="rail-mark">ACC</div>
          {NAV.map((n) => (
            <button key={n.label} className={"rail-item" + (n.active ? " is-active" : "")} aria-current={n.active ? "page" : undefined}>
              <n.icon size={18} strokeWidth={1.75} /><span>{n.label}</span>
            </button>
          ))}
        </nav>

        <main className="main">
          <header className="topbar">
            <div>
              <h1>Salary payouts</h1>
              <p className="sub">Employee pay structures and the monthly payslips they produce</p>
            </div>
            <div className="topbar-right">
              <button className={"btn btn-ghost" + (showCfg ? " is-pressed" : "")} onClick={() => setShowCfg((s) => !s)}>
                <SlidersHorizontal size={14} />Rates &amp; bands
              </button>
              <div className="who">
                <span className="who-name">Husain Khatumdi</span>
                <span className="who-role">Account Team&nbsp;·&nbsp;Senior</span>
              </div>
            </div>
          </header>

          {showCfg && <ConfigPanel cfg={cfg} setCfg={setCfg} onClose={() => setShowCfg(false)} />}

          <section className="strip" aria-label="Summary">
            <Metric label="On payroll" value={String(totals.count)} />
            <Metric label="Monthly cost to company" value={inr(totals.monthly, { paise: false })} tone="info" />
            <Metric label="Employer contributions" value={inr(totals.employer, { paise: false })} />
            <Metric label="Payouts awaiting payment" value={inr(totals.pending, { paise: false })} tone="warn" />
            <Metric label="Records needing attention" value={String(totals.flagged)} tone={totals.flagged ? "danger" : "good"} />
          </section>

          <div className="toolbar">
            <div className="search">
              <Search size={15} strokeWidth={2} />
              <input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Name, designation, location" aria-label="Search employees" />
              {q && <button className="clear" onClick={() => setQ("")} aria-label="Clear"><X size={13} /></button>}
            </div>
            <button className="btn btn-primary" onClick={() => setEditing("new")}><Plus size={15} strokeWidth={2.5} />Add employee</button>
          </div>

          <div className="table-wrap">
            <table className="grid">
              <thead>
                <tr>
                  <Th s={sort} set={setSort} k="name">Employee</Th>
                  <Th s={sort} set={setSort} k="designation">Designation</Th>
                  <th>Location · State</th>
                  <Th s={sort} set={setSort} k="total" align="right">Monthly cost</Th>
                  <th className="num">Basic</th><th className="num">HRA</th><th className="num">CC</th>
                  <th>Statutory</th><th>Payouts</th><th aria-label="Flags" />
                </tr>
              </thead>
              <tbody>
                {rows.map((e) => {
                  const w = warnings(e, cfg);
                  const danger = w.some((x) => x.tone === "danger");
                  return (
                    <tr key={e.id} onClick={() => setOpenId(e.id)} className={openId === e.id ? "is-open" : ""} tabIndex={0}
                        onKeyDown={(ev) => ev.key === "Enter" && setOpenId(e.id)}>
                      <td className="strong truncate" title={e.name}>{e.name}</td>
                      <td className="dim truncate">{e.designation || "—"}</td>
                      <td className="dim sm nowrap">{e.location}<br /><em className="tinier">{e.state}</em></td>
                      <td className="mono num strong">{inr(e.total, { paise: false })}</td>
                      <td className="mono num">{inr(e.basic, { paise: false })}</td>
                      <td className={"mono num " + (e.hra < 0 ? "tone-danger strong" : "dim")}>{inr(e.hra, { paise: false })}</td>
                      <td className="mono num dim">{inr(e.cc, { paise: false })}</td>
                      <td className="statset">
                        <span className={"pill " + (e.pfStatus === "Yes" ? "is-on" : "")}>PF</span>
                        <span className={"pill " + (e.esicStatus === "Yes" ? "is-on" : "")}>ESIC</span>
                        <span className="pill is-quiet">{e.gender === "Male" ? "M" : "F"} {e.age}</span>
                      </td>
                      <td className="dim sm">{e.payouts.length ? `${e.payouts.filter((p) => p.created).length}/${e.payouts.length} paid` : "—"}</td>
                      <td className="pad-r">
                        {danger ? <span className="warn-dot is-danger" title={w.find((x) => x.tone === "danger").text}><AlertTriangle size={13} strokeWidth={2.25} /></span>
                          : w.length > 0 ? <span className="warn-dot" title={`${w.length} note${w.length > 1 ? "s" : ""}`}><Info size={13} strokeWidth={2.25} /></span> : null}
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
          <p className="count">{rows.length} of {emps.length}</p>
        </main>

        {open && (
          <EmployeeDrawer e={open} cfg={cfg} onClose={() => setOpenId(null)} onEdit={() => setEditing(open)}
            onSetPayout={(poId, patch) => setPayout(open.id, poId, patch)}
            onPrev={openIdx > 0 ? () => setOpenId(rows[openIdx - 1].id) : null}
            onNext={openIdx < rows.length - 1 ? () => setOpenId(rows[openIdx + 1].id) : null} />
        )}
        {editing && <EmployeeForm initial={editing === "new" ? null : editing} cfg={cfg} onCancel={() => setEditing(null)} onSave={save} />}
      </div>
    </>
  );
}

/* ── configuration panel ─────────────────────────────────────── */

function ConfigPanel({ cfg, setCfg, onClose }) {
  const set = (patch) => setCfg((c) => ({ ...c, ...patch }));
  const Num = ({ k, label, note, step = 1 }) => (
    <Field label={label} note={note}>
      <input className="mono ta-r" type="number" step={step} value={cfg[k]} onChange={(e) => set({ [k]: +e.target.value })} />
    </Field>
  );
  return (
    <section className="cfg">
      <header className="cfg-head">
        <div><h3><Calculator size={15} strokeWidth={2} />Rates &amp; bands</h3>
          <p>Every figure the engine uses. Changing one recalculates every payslip on screen.</p></div>
        <button className="icon-btn" onClick={onClose} aria-label="Close"><X size={15} /></button>
      </header>
      <div className="cfg-body">
        <div className="cfg-group">
          <h4>Automatic split</h4>
          <Grid>
            <Num k="basicBandLow" label="Low band up to" />
            <Num k="basicLow" label="Basic in low band" />
            <Num k="basicBandHigh" label="High band up to" />
            <Num k="basicHigh" label="Basic in high band" />
            <Num k="basicPctAbove" label="Basic % above high band" step={0.01} />
            <Num k="hraBalanceUpTo" label="HRA is the balance up to" />
            <Num k="hraMetroPct" label="HRA % — metro" step={0.01} />
            <Num k="hraNonMetroPct" label="HRA % — non-metro" step={0.01} />
          </Grid>
        </div>
        <div className="cfg-group">
          <h4>Provident fund</h4>
          <Grid>
            <Num k="pfPct" label="PF rate" step={0.01} />
            <Num k="pfMonthlyCap" label="Monthly cap" />
            <Num k="pfWageCeiling" label="Wage ceiling for EDLI" />
            <Num k="edliPct" label="EDLI rate" step={0.001} />
            <Num k="edliMultiplier" label="EDLI multiplier" note="1 is statutory; the current system uses 2" step={1} />
          </Grid>
        </div>
        <div className="cfg-group">
          <h4>ESIC</h4>
          <Grid>
            <Num k="esicEmployeePct" label="Employee rate" step={0.0001} />
            <Num k="esicEmployerPct" label="Employer rate" step={0.0001} />
            <Num k="esicWageCeiling" label="Wage ceiling" />
            <Field label="Contribution basis" note="Statutorily gross wages">
              <select value={cfg.esicBasis} onChange={(e) => set({ esicBasis: e.target.value })}>
                <option value="base">Base pay (current)</option>
                <option value="gross">Gross salary (statutory)</option>
              </select>
            </Field>
          </Grid>
        </div>
        <div className="cfg-group">
          <h4>Professional tax</h4>
          <Grid>
            <Field label="Assessed on" note="Statutorily monthly salary">
              <select value={cfg.ptBasis} onChange={(e) => set({ ptBasis: e.target.value })}>
                <option value="prorated">Prorated salary (current)</option>
                <option value="monthly">Full monthly salary (statutory)</option>
              </select>
            </Field>
            <Num k="ptAgeExemption" label="Exempt from age" />
          </Grid>
          <ul className="ptlist">
            {Object.entries(PT_RULES).map(([st, r]) => <li key={st}><b>{st}</b> — {r.note}</li>)}
          </ul>
        </div>
      </div>
    </section>
  );
}

/* ── list bits ───────────────────────────────────────────────── */

const Metric = ({ label, value, tone = "neutral" }) => (
  <div className="metric"><span className="metric-label">{label}</span><span className={"metric-value mono tone-" + tone}>{value}</span></div>
);
function Th({ children, k, s, set, align }) {
  const on = s.key === k;
  return (
    <th className={align === "right" ? "num" : ""}>
      <button className={"th-btn" + (on ? " is-on" : "")} onClick={() => set({ key: k, dir: on && s.dir === "asc" ? "desc" : "asc" })}>
        {children}<ArrowUpDown size={11} strokeWidth={2.25} />
      </button>
    </th>
  );
}
const Grid = ({ children }) => <div className="fgrid">{children}</div>;
const Field = ({ label, req, note, wide, children }) => (
  <div className={"field" + (wide ? " is-wide" : "")}>
    <label>{label}{req && <i className="req" aria-hidden="true">*</i>}</label>
    {children}{note && <p className="fnote">{note}</p>}
  </div>
);
const FSec = ({ n, hint, children }) => (
  <section className="fsec"><div className="fsec-head"><h3>{n}</h3><p>{hint}</p></div><div className="fsec-body">{children}</div></section>
);
const Facts = ({ rows }) => (
  <dl className="facts">{rows.map(([k, v]) => <React.Fragment key={k}><dt>{k}</dt><dd>{v || "—"}</dd></React.Fragment>)}</dl>
);

/* ── employee drawer with payslips ───────────────────────────── */

function EmployeeDrawer({ e, cfg, onClose, onEdit, onSetPayout, onPrev, onNext }) {
  const [expanded, setExpanded] = useState(null);
  useEffect(() => {
    const h = (ev) => ev.key === "Escape" && onClose();
    window.addEventListener("keydown", h);
    return () => window.removeEventListener("keydown", h);
  }, [onClose]);

  const w = warnings(e, cfg);
  const split = splitTotal(e.total, e.location, cfg);

  return (
    <aside className="drawer" role="dialog" aria-label={`Payroll for ${e.name}`}>
      <header className="drawer-head">
        <div className="stepper">
          <button className="icon-btn" onClick={onPrev} disabled={!onPrev} aria-label="Previous"><ChevronLeft size={16} /></button>
          <button className="icon-btn" onClick={onNext} disabled={!onNext} aria-label="Next"><ChevronRight size={16} /></button>
        </div>
        <div className="drawer-actions">
          <button className="btn btn-ghost" onClick={onEdit}><Pencil size={14} />Edit</button>
          <button className="btn btn-ghost"><Copy size={14} />Duplicate</button>
          <button className="icon-btn" onClick={onClose} aria-label="Close"><X size={16} /></button>
        </div>
      </header>

      <div className="drawer-body">
        <div className="dh">
          <span className="eyebrow">{e.designation || "designation not set"}</span>
          <h2>{e.name}</h2>
          <div className="dh-figure mono">{inr(e.total, { paise: false })}<span>monthly cost to company</span></div>
          <div className="dh-meta">
            <span className={"pill " + (e.pfStatus === "Yes" ? "is-on" : "")}>PF {e.pfStatus}</span>
            <span className={"pill " + (e.esicStatus === "Yes" ? "is-on" : "")}>ESIC {e.esicStatus}</span>
            <span className="dim sm">{e.gender} · {e.age} · {e.state}</span>
          </div>
        </div>

        {w.map((x, i) => (
          <div key={i} className={"note " + (x.tone === "danger" ? "note-danger" : "note-warn")}>
            <AlertTriangle size={14} /><span>{x.text}</span>
          </div>
        ))}

        <Section title="Pay structure">
          <div className="ledger">
            <Row label="Base pay" value={split.basic} />
            <Row label="HRA" value={split.hra} sign="+" />
            {!!split.cc && <Row label="City compensatory" value={split.cc} sign="+" />}
            <Row label="Monthly cost" value={e.total} total />
          </div>
          <p className="hint">
            {e.total <= cfg.basicBandLow
              ? `Basic fixed at ${inr(cfg.basicLow, { paise: false })} for totals up to ${inr(cfg.basicBandLow, { paise: false })}.`
              : e.total <= cfg.basicBandHigh
                ? `Basic fixed at ${inr(cfg.basicHigh, { paise: false })} for totals up to ${inr(cfg.basicBandHigh, { paise: false })}.`
                : `Basic at ${(cfg.basicPctAbove * 100).toFixed(0)}% of total.`}
            {" "}
            {e.total <= cfg.hraBalanceUpTo
              ? "HRA is the balance after basic."
              : `HRA at ${((cfg.metros.includes(e.location) ? cfg.hraMetroPct : cfg.hraNonMetroPct) * 100).toFixed(0)}% of basic (${cfg.metros.includes(e.location) ? "metro" : "non-metro"}).`}
          </p>
        </Section>

        <Section title="Employment">
          <Facts rows={[
            ["Location", e.location], ["State", e.state], ["Villa", byId(VILLAS, e.villa)?.name],
            ["Designation", e.designation], ["Item category", e.itemCat], ["Paid from", byId(BANKS, e.bank)?.name],
            ["Chart of account", byId(COA, e.coa)?.name], ["Entity", e.entity],
            ["Salary periods", e.periods.map((p) => `${p.start} → ${p.end}`).join(", ")],
          ]} />
        </Section>

        <Section title={`Payslips · ${e.payouts.length}`}>
          {e.payouts.length === 0 ? (
            <div className="empty-inline"><p>No payouts generated for this employee yet.</p></div>
          ) : (
            <div className="payouts">
              {e.payouts.map((p) => {
                const c = computePayout(p, e, cfg);
                const on = expanded === p.id;
                return (
                  <div key={p.id} className={"payout" + (on ? " is-open" : "")}>
                    <button className="payout-head" onClick={() => setExpanded(on ? null : p.id)} aria-expanded={on}>
                      <ChevronDown size={14} className="chev" />
                      <span className="po-cycle">{p.cycleMonth} {p.cycleYear}</span>
                      <span className="po-days mono">{c.worked}/{c.dim}d</span>
                      <span className="po-amt mono">{inr(c.salary, { paise: false })}</span>
                      <span className={"po-ded mono " + (c.employeeDeductions + c.recoveries ? "tone-warn" : "dim")}>
                        {c.employeeDeductions + c.recoveries ? "−" + inr(c.employeeDeductions + c.recoveries, { paise: false, bare: true }) : "—"}
                      </span>
                      <span className="po-net mono">{inr(c.payable, { paise: false })}</span>
                      {p.created ? <Badge tone="good">Paid</Badge> : <Badge tone="warn">Pending</Badge>}
                    </button>

                    {on && (
                      <div className="payslip">
                        {c.floored && (
                          <div className="note note-danger"><AlertTriangle size={14} />
                            <span>Deductions exceed the salary, so payable has been floored at zero. Check the recoveries.</span></div>
                        )}
                        <div className="slip-cols">
                          <div>
                            <h5>Earnings</h5>
                            <div className="ledger">
                              <Row label="Base pay" value={c.basic} />
                              <Row label="HRA" value={c.hra} />
                              {!!c.cc && <Row label="City compensatory" value={c.cc} />}
                              <Row label="Gross salary" value={c.salary} rule />
                            </div>
                            <h5>Recoveries</h5>
                            <div className="ledger">
                              <Row label="Staff advance" value={c.advance} sign="−" muted />
                              <Row label="Staff loan" value={c.loan} sign="−" muted />
                              <Row label="Penalty" value={c.penalty} sign="−" muted />
                              <Row label="Other expenses" value={c.other} sign="+" muted />
                            </div>
                          </div>
                          <div>
                            <h5>Employee deductions</h5>
                            <div className="ledger">
                              <Row label={c.pfOn ? "Provident fund" : "Provident fund (not enrolled)"} value={c.employeePF} sign="−" muted />
                              <Row label={c.esicOn ? "ESIC" : "ESIC (not applicable)"} value={c.employeeESIC} sign="−" muted />
                              <Row label="Professional tax" value={c.pt} sign="−" muted />
                              <Row label="Total deductions" value={c.employeeDeductions} rule />
                            </div>
                            <h5>Employer cost</h5>
                            <div className="ledger">
                              <Row label="Employer PF" value={c.employerPF} muted />
                              <Row label="Employer ESIC" value={c.employerESIC} muted />
                              <Row label="Cost to company" value={c.ctc} rule />
                            </div>
                          </div>
                        </div>
                        <div className="slip-net">
                          <span>Net payable</span><span className="mono">{inr(c.payable)}</span>
                        </div>
                        {c.ptNote && <p className="hint"><b>{e.state} professional tax</b> — {c.ptNote}</p>}
                        {c.pfOn && <p className="hint">Employer PF includes EDLI of {inr(c.edli)} at {(cfg.edliPct * cfg.edliMultiplier * 100).toFixed(2)}% of capped basic.</p>}
                        <div className="slip-actions">
                          <Field label="Days worked">
                            <input className="mono ta-r" type="number" max={c.dim} value={p.daysWorked ?? ""}
                              onChange={(ev) => onSetPayout(p.id, { daysWorked: ev.target.value })} placeholder={String(c.dim)} />
                          </Field>
                          <Field label="Penalty">
                            <input className="mono ta-r" type="number" value={p.penalty ?? ""}
                              onChange={(ev) => onSetPayout(p.id, { penalty: ev.target.value })} placeholder="0" />
                          </Field>
                          <Field label="Other expenses" note="Added to payable">
                            <input className="mono ta-r" type="number" value={p.other ?? ""}
                              onChange={(ev) => onSetPayout(p.id, { other: ev.target.value })} placeholder="0" />
                          </Field>
                          <div className="slip-btn">
                            {p.created
                              ? <button className="btn btn-ghost" disabled><Check size={14} />Payment created</button>
                              : <button className="btn btn-primary" onClick={() => onSetPayout(p.id, { created: true })}>Create payment</button>}
                          </div>
                        </div>
                      </div>
                    )}
                  </div>
                );
              })}
            </div>
          )}
        </Section>

        <p className="record-id mono">Record ID {e.id}</p>
      </div>
    </aside>
  );
}

const Section = ({ title, children }) => <section className="dsec"><h3>{title}</h3>{children}</section>;
const Badge = ({ tone = "neutral", children }) => <span className={"badge tone-" + tone}>{children}</span>;
const Row = ({ label, value, sign, total, rule, muted }) => (
  <div className={"lrow" + (total ? " is-total" : "") + (rule ? " has-rule" : "") + (muted ? " is-muted" : "")}>
    <span>{label}</span><span className="mono">{sign && <i className="sign">{sign}</i>}{inr(value)}</span>
  </div>
);

/* ── employee form ───────────────────────────────────────────── */

const blankEmp = () => ({
  id: uid(), name: "", designation: "", location: "", state: "Maharashtra", villa: "",
  total: "", basic: 0, hra: 0, cc: 0, mode: "Automatic",
  pfStatus: "No", esicStatus: "No", gender: "Male", age: "",
  itemCat: "STAFF SALARY", masterCat: "Employee & Staff Considerations", paymentType: "Payment",
  coa: "c1", bank: "", entity: "", periods: [{ id: uid(), start: "", end: "" }], payouts: [],
});

function EmployeeForm({ initial, cfg, onCancel, onSave }) {
  const [e, setE] = useState(() => (initial ? JSON.parse(JSON.stringify({ mode: "Automatic", ...initial })) : blankEmp()));
  const [touched, setTouched] = useState(false);
  const auto = e.mode === "Automatic";

  /** In automatic mode the split is derived; in manual it's typed. */
  const set = (patch) => setE((prev) => {
    const next = { ...prev, ...patch };
    if (next.mode === "Automatic") {
      const s = splitTotal(next.total, next.location, cfg);
      return { ...next, ...s };
    }
    if ("basic" in patch || "hra" in patch || "cc" in patch) {
      next.total = r2((+next.basic || 0) + (+next.hra || 0) + (+next.cc || 0));
    }
    return next;
  });

  const w = warnings(e, cfg);
  const problems = [];
  if (!e.name.trim()) problems.push("Enter the employee's name.");
  if (!e.location) problems.push("Choose a location.");
  if (!e.state) problems.push("Choose a state — it determines professional tax.");
  if (!(+e.total)) problems.push("Enter the monthly cost to company.");
  if (!e.age) problems.push("Enter age — it drives the professional tax exemption.");
  if (!e.bank) problems.push("Choose the account salary is paid from.");
  if (e.hra < 0) problems.push("HRA has computed negative. Adjust the total or the basic band.");
  if (!e.periods.some((p) => p.start && p.end)) problems.push("Set at least one salary period.");

  const submit = () => { setTouched(true); if (!problems.length) onSave(e); };

  return (
    <div className="sheet" role="dialog" aria-label={initial ? "Edit employee" : "Add employee"}>
      <header className="sheet-head">
        <div>
          <span className="eyebrow">{initial ? "Editing payroll record" : "New payroll record"}</span>
          <h2>{e.name || "Untitled"}</h2>
        </div>
        <button className="icon-btn" onClick={onCancel} aria-label="Close"><X size={18} /></button>
      </header>

      <div className="sheet-body">
        <div className="sheet-main">
          <FSec n="Who" hint="Identity, posting and the statutory profile">
            <Grid>
              <Field label="Name" req wide>
                <input value={e.name} onChange={(ev) => set({ name: ev.target.value })} placeholder="As it appears on the payslip" />
              </Field>
              <Field label="Designation">
                <select value={e.designation} onChange={(ev) => set({ designation: ev.target.value })}>
                  <option value="">—</option>{DESIGNATIONS.map((d) => <option key={d} value={d}>{d}</option>)}
                </select>
              </Field>
              <Field label="Location" req>
                <select value={e.location} onChange={(ev) => set({ location: ev.target.value })}>
                  <option value="">—</option>{LOCATIONS.map((l) => <option key={l} value={l}>{l}</option>)}
                </select>
              </Field>
              <Field label="State" req note="Determines the professional tax slab">
                <select value={e.state} onChange={(ev) => set({ state: ev.target.value })}>
                  {STATES.map((s) => <option key={s} value={s}>{s}</option>)}
                </select>
              </Field>
              <Field label="Villa">
                <select value={e.villa} onChange={(ev) => set({ villa: ev.target.value })}>
                  <option value="">—</option>{VILLAS.map((v) => <option key={v.id} value={v.id}>{v.name}</option>)}
                </select>
              </Field>
              <Field label="Gender" req note="Maharashtra applies a different PT threshold">
                <select value={e.gender} onChange={(ev) => set({ gender: ev.target.value })}>
                  <option>Male</option><option>Female</option>
                </select>
              </Field>
              <Field label="Age" req note={`Exempt from professional tax at ${cfg.ptAgeExemption}+`}>
                <input className="mono ta-r" type="number" value={e.age} onChange={(ev) => set({ age: ev.target.value })} />
              </Field>
              <Field label="PF status" req>
                <select value={e.pfStatus} onChange={(ev) => set({ pfStatus: ev.target.value })}><option>No</option><option>Yes</option></select>
              </Field>
              <Field label="ESIC status" req note={`Ceiling ${inr(cfg.esicWageCeiling, { paise: false })}`}>
                <select value={e.esicStatus} onChange={(ev) => set({ esicStatus: ev.target.value })}><option>No</option><option>Yes</option></select>
              </Field>
            </Grid>
          </FSec>

          <FSec n="Pay structure" hint="Enter the monthly cost and the split derives, or switch to manual">
            <div className="modepick" role="radiogroup" aria-label="Calculation mode">
              {["Automatic", "Manual"].map((m) => (
                <button key={m} type="button" role="radio" aria-checked={e.mode === m}
                  className={"mode" + (e.mode === m ? " is-on" : "")} onClick={() => set({ mode: m })}>{m}</button>
              ))}
            </div>
            <Grid>
              <Field label="Monthly cost to company" req note={auto ? "Basic, HRA and CC derive from this" : "Sum of the three below"}>
                <input className="mono ta-r" type="number" value={e.total} disabled={!auto}
                  onChange={(ev) => set({ total: ev.target.value })} placeholder="0" />
              </Field>
              <Field label="Base pay" req>
                {auto ? <div className="derived mono">{inr(e.basic)}</div>
                  : <input className="mono ta-r" type="number" value={e.basic} onChange={(ev) => set({ basic: ev.target.value })} />}
              </Field>
              <Field label="HRA">
                {auto ? <div className={"derived mono" + (e.hra < 0 ? " is-bad" : "")}>{inr(e.hra)}</div>
                  : <input className="mono ta-r" type="number" value={e.hra} onChange={(ev) => set({ hra: ev.target.value })} />}
              </Field>
              <Field label="City compensatory">
                {auto ? <div className="derived mono">{inr(e.cc)}</div>
                  : <input className="mono ta-r" type="number" value={e.cc} onChange={(ev) => set({ cc: ev.target.value })} />}
              </Field>
            </Grid>
            {auto && +e.total > 0 && (
              <p className="hint">
                {+e.total <= cfg.basicBandLow ? `Basic fixed at ${inr(cfg.basicLow, { paise: false })}.`
                  : +e.total <= cfg.basicBandHigh ? `Basic fixed at ${inr(cfg.basicHigh, { paise: false })}.`
                  : `Basic at ${(cfg.basicPctAbove * 100).toFixed(0)}% of total.`}{" "}
                {+e.total <= cfg.hraBalanceUpTo ? "HRA takes the balance."
                  : `HRA at ${((cfg.metros.includes(e.location) ? cfg.hraMetroPct : cfg.hraNonMetroPct) * 100).toFixed(0)}% of basic.`}
              </p>
            )}
          </FSec>

          <FSec n="Posting" hint="Where salary lands in the books">
            <Grid>
              <Field label="Paid from" req>
                <select value={e.bank} onChange={(ev) => set({ bank: ev.target.value })}>
                  <option value="">—</option>{BANKS.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
                </select>
              </Field>
              <Field label="Chart of account">
                <select value={e.coa} onChange={(ev) => set({ coa: ev.target.value })}>
                  {COA.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                </select>
              </Field>
              <Field label="Item category"><div className="derived">{e.itemCat}</div></Field>
              <Field label="Master category" note="Set by the item category"><div className="derived">{e.masterCat}</div></Field>
              <Field label="Entity" note="Employing legal entity">
                <input value={e.entity} onChange={(ev) => set({ entity: ev.target.value })} placeholder="Optional" />
              </Field>
            </Grid>
          </FSec>

          <FSec n="Salary periods" hint="A new period records a change in terms rather than overwriting history">
            <table className="edit-table">
              <thead><tr><th>Start month</th><th>End month</th><th /></tr></thead>
              <tbody>
                {e.periods.map((p, i) => (
                  <tr key={p.id}>
                    <td><input value={p.start} placeholder="May-2026"
                      onChange={(ev) => set({ periods: e.periods.map((x, n) => (n === i ? { ...x, start: ev.target.value } : x)) })} /></td>
                    <td><input value={p.end} placeholder="March-2027"
                      onChange={(ev) => set({ periods: e.periods.map((x, n) => (n === i ? { ...x, end: ev.target.value } : x)) })} /></td>
                    <td className="pad-r">
                      <button className="icon-btn sm" disabled={e.periods.length === 1} aria-label="Remove period"
                        onClick={() => set({ periods: e.periods.filter((x) => x.id !== p.id) })}><Trash2 size={13} /></button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
            <button className="btn btn-ghost" onClick={() => set({ periods: [...e.periods, { id: uid(), start: "", end: "" }] })}>
              <Plus size={14} />Add period
            </button>
          </FSec>
        </div>

        <aside className="sheet-side">
          <div className="side-card">
            <h4>Monthly cost</h4>
            <div className="alloc-figure mono">{inr(+e.total || 0, { paise: false })}<span>base + HRA + CC</span></div>
            <div className="ledger" style={{ marginTop: 10 }}>
              <Row label="Base pay" value={+e.basic || 0} />
              <Row label="HRA" value={+e.hra || 0} />
              {!!+e.cc && <Row label="CC" value={+e.cc || 0} />}
            </div>
          </div>

          <div className="side-card">
            <h4>Statutory profile</h4>
            <ul className="notelist">
              <li>PF {e.pfStatus === "Yes" ? `at ${(cfg.pfPct * 100).toFixed(0)}% of basic, capped ${inr(cfg.pfMonthlyCap, { paise: false })}` : "not enrolled"}</li>
              <li>ESIC {e.esicStatus === "Yes" ? `at ${(cfg.esicEmployeePct * 100).toFixed(2)}% employee / ${(cfg.esicEmployerPct * 100).toFixed(2)}% employer` : "not applicable"}</li>
              <li>{PT_RULES[e.state]?.note ?? `No PT rule for ${e.state}`}</li>
            </ul>
          </div>

          {w.length > 0 && (
            <div className="side-card is-warn">
              <h4>Notes on this record</h4>
              <ul className="problems">{w.map((x, i) => <li key={i}>{x.text}</li>)}</ul>
            </div>
          )}
          {touched && problems.length > 0 && (
            <div className="side-card is-warn">
              <h4>Before saving</h4>
              <ul className="problems">{problems.map((p) => <li key={p}>{p}</li>)}</ul>
            </div>
          )}
        </aside>
      </div>

      <footer className="sheet-foot">
        <div className="foot-state"><Users size={15} />{e.name || "New employee"} · {inr(+e.total || 0, { paise: false })}/month</div>
        <div className="foot-actions">
          <button className="btn btn-ghost" onClick={onCancel}>Cancel</button>
          <button className="btn btn-primary" onClick={submit}>{initial ? "Save changes" : "Create record"}</button>
        </div>
      </footer>
    </div>
  );
}

/* ── styles ──────────────────────────────────────────────────── */

function Style() {
  return (
    <style>{`
@import url('https://fonts.googleapis.com/css2?family=Schibsted+Grotesk:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500;600&display=swap');
*, *::before, *::after { box-sizing: border-box; }

.app {
  --ink:#151922; --ink-2:#3d4658; --ink-3:#6b7688; --ink-4:#9aa3b2;
  --slate:#1b2231; --slate-2:#232c3e;
  --paper:#fff; --paper-2:#f7f8fa; --paper-3:#eef0f4;
  --rule:#e3e6ec; --rule-2:#d3d8e1;
  --indigo:#2b3a7a; --indigo-2:#3b4d9c; --indigo-soft:#eaedf8;
  --amber:#a86a08; --amber-soft:#fdf4e3; --amber-line:#e8c07a;
  --verd:#126a5f; --verd-soft:#e6f4f1; --verd-line:#8ec9bf;
  --red:#a3302a; --red-soft:#fbeceb; --red-line:#e0a49f;
  --sans:'Schibsted Grotesk', ui-sans-serif, system-ui, sans-serif;
  --mono:'IBM Plex Mono', ui-monospace, monospace; --r:7px;
  font-family: var(--sans); color: var(--ink); background: var(--paper-2);
  display: grid; grid-template-columns: 68px minmax(0,1fr); min-height: 100vh;
  font-size: 14px; -webkit-font-smoothing: antialiased;
}
.app :focus-visible { outline: 2px solid var(--indigo-2); outline-offset: 2px; border-radius: 3px; }
.mono { font-family: var(--mono); font-variant-numeric: tabular-nums; letter-spacing: -0.01em; }
.num, .ta-r { text-align: right; }
.dim { color: var(--ink-3); } .strong { font-weight: 600; } .sm { font-size: 12px; }
.tinier { font-size: 10.5px; font-style: normal; color: var(--ink-4); }
.nowrap { white-space: nowrap; }
.truncate { max-width: 175px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.pad-r { padding-right: 12px !important; }
.tone-info { color: var(--indigo); } .tone-warn { color: var(--amber); }
.tone-good { color: var(--verd); } .tone-danger { color: var(--red); } .tone-neutral { color: var(--ink-3); }

.rail { background: var(--slate); display: flex; flex-direction: column; align-items: center; gap: 2px; padding: 12px 0; position: sticky; top: 0; height: 100vh; }
.rail-mark { font-weight: 700; font-size: 11px; letter-spacing: .14em; color: #fff; background: var(--indigo-2); width: 40px; height: 40px; border-radius: var(--r); display: grid; place-items: center; margin-bottom: 14px; }
.rail-item { width: 56px; padding: 9px 2px 7px; background: none; border: 0; color: #8e98ad; display: grid; justify-items: center; gap: 4px; border-radius: var(--r); cursor: pointer; font: inherit; }
.rail-item span { font-size: 9.5px; line-height: 1.15; text-align: center; }
.rail-item:hover { color: #cfd6e4; background: var(--slate-2); }
.rail-item.is-active { color: #fff; background: var(--indigo); }

.main { min-width: 0; padding: 22px 26px 34px; }
.topbar { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; margin-bottom: 18px; }
.topbar h1 { margin: 0; font-size: 24px; font-weight: 600; letter-spacing: -.02em; }
.sub { margin: 3px 0 0; color: var(--ink-3); font-size: 13px; }
.topbar-right { display: flex; align-items: center; gap: 14px; }
.who { text-align: right; line-height: 1.35; }
.who-name { display: block; font-weight: 500; font-size: 13px; }
.who-role { display: block; font-size: 11px; color: var(--ink-4); }

.cfg { background: var(--paper); border: 1px solid var(--indigo-soft); border-left: 3px solid var(--indigo); border-radius: var(--r); margin-bottom: 18px; overflow: hidden; }
.cfg-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 14px; padding: 13px 16px; background: var(--indigo-soft); }
.cfg-head h3 { margin: 0; font-size: 13.5px; font-weight: 600; display: flex; align-items: center; gap: 7px; color: var(--indigo); }
.cfg-head p { margin: 3px 0 0; font-size: 12px; color: var(--ink-2); }
.cfg-body { padding: 16px; display: grid; gap: 18px; }
.cfg-group h4 { margin: 0 0 9px; font-size: 10.5px; text-transform: uppercase; letter-spacing: .08em; color: var(--ink-4); font-weight: 600; }
.ptlist { margin: 11px 0 0; padding-left: 17px; font-size: 12px; color: var(--ink-3); line-height: 1.55; }
.ptlist b { color: var(--ink-2); }

.strip { display: grid; grid-template-columns: repeat(auto-fit, minmax(155px,1fr)); gap: 1px; background: var(--rule); border: 1px solid var(--rule); border-radius: var(--r); overflow: hidden; margin-bottom: 16px; }
.metric { background: var(--paper); padding: 12px 14px; }
.metric-label { display: block; font-size: 10.5px; text-transform: uppercase; letter-spacing: .07em; color: var(--ink-4); margin-bottom: 5px; }
.metric-value { font-size: 19px; font-weight: 600; }

.toolbar { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 12px; }
.search { display: flex; align-items: center; gap: 7px; background: var(--paper); border: 1px solid var(--rule-2); border-radius: var(--r); padding: 0 9px; height: 34px; min-width: 250px; color: var(--ink-4); }
.search input { border: 0; outline: 0; font: inherit; background: none; flex: 1; color: var(--ink); }
.clear { border: 0; background: none; color: var(--ink-4); cursor: pointer; display: grid; place-items: center; }

.btn { font: inherit; font-size: 13px; display: inline-flex; align-items: center; gap: 6px; height: 34px; padding: 0 13px; border-radius: var(--r); border: 1px solid transparent; cursor: pointer; white-space: nowrap; }
.btn:disabled { opacity: .45; cursor: not-allowed; }
.btn-primary { background: var(--indigo); color: #fff; font-weight: 500; }
.btn-primary:hover:not(:disabled) { background: var(--indigo-2); }
.btn-ghost { background: var(--paper); border-color: var(--rule-2); color: var(--ink-2); }
.btn-ghost:hover:not(:disabled) { border-color: var(--ink-4); color: var(--ink); }
.btn-ghost.is-pressed { background: var(--indigo-soft); border-color: var(--indigo-2); color: var(--indigo); }
.icon-btn { border: 1px solid var(--rule-2); background: var(--paper); border-radius: var(--r); width: 30px; height: 30px; display: grid; place-items: center; cursor: pointer; color: var(--ink-2); }
.icon-btn:disabled { opacity: .35; cursor: not-allowed; }
.icon-btn:hover:not(:disabled) { border-color: var(--ink-4); }
.icon-btn.sm { width: 25px; height: 25px; border-color: transparent; background: none; color: var(--ink-4); }
.icon-btn.sm:hover:not(:disabled) { color: var(--red); background: var(--red-soft); }

.table-wrap { background: var(--paper); border: 1px solid var(--rule); border-radius: var(--r); overflow-x: auto; }
.grid { width: 100%; border-collapse: collapse; font-size: 13px; }
.grid thead th { text-align: left; font-weight: 500; font-size: 10.5px; text-transform: uppercase; letter-spacing: .07em; color: var(--ink-4); padding: 9px 10px; border-bottom: 1px solid var(--rule-2); background: var(--paper-2); white-space: nowrap; }
.grid thead th.num { text-align: right; }
.th-btn { font: inherit; letter-spacing: inherit; text-transform: inherit; color: inherit; background: none; border: 0; cursor: pointer; display: inline-flex; align-items: center; gap: 4px; padding: 0; }
.th-btn svg { opacity: .4; } .th-btn.is-on { color: var(--indigo); } .th-btn.is-on svg { opacity: 1; }
.grid tbody td { padding: 9px 10px; border-bottom: 1px solid var(--rule); vertical-align: middle; }
.grid tbody tr { cursor: pointer; }
.grid tbody tr:hover { background: var(--paper-2); }
.grid tbody tr.is-open { background: var(--indigo-soft); box-shadow: inset 3px 0 0 var(--indigo); }
.grid tbody tr:last-child td { border-bottom: 0; }
.statset { display: flex; gap: 4px; }
.pill { font-size: 10px; font-weight: 600; letter-spacing: .03em; padding: 2px 6px; border-radius: 4px; background: var(--paper-3); color: var(--ink-4); border: 1px solid var(--rule-2); white-space: nowrap; }
.pill.is-on { background: var(--verd-soft); color: var(--verd); border-color: var(--verd-line); }
.pill.is-quiet { background: none; border-style: dashed; }
.warn-dot { color: var(--amber); display: inline-grid; place-items: center; }
.warn-dot.is-danger { color: var(--red); }
.count { margin: 9px 0 0; font-size: 12px; color: var(--ink-4); }

.badge { display: inline-block; font-size: 11px; font-weight: 500; padding: 3px 8px; border-radius: 99px; border: 1px solid; white-space: nowrap; }
.badge.tone-neutral { background: var(--paper-3); border-color: var(--rule-2); color: var(--ink-2); }
.badge.tone-warn { background: var(--amber-soft); border-color: var(--amber-line); color: var(--amber); }
.badge.tone-good { background: var(--verd-soft); border-color: var(--verd-line); color: var(--verd); }
.badge.tone-danger { background: var(--red-soft); border-color: var(--red-line); color: var(--red); }

.drawer { position: fixed; top: 0; right: 0; bottom: 0; width: min(660px, 96vw); background: var(--paper); border-left: 1px solid var(--rule-2); box-shadow: -18px 0 44px rgba(21,25,34,.13); display: flex; flex-direction: column; z-index: 40; }
.drawer-head { display: flex; justify-content: space-between; align-items: center; gap: 10px; padding: 11px 14px; border-bottom: 1px solid var(--rule); background: var(--paper-2); }
.stepper, .drawer-actions { display: flex; gap: 6px; align-items: center; }
.drawer-body { overflow-y: auto; padding: 18px 20px 34px; }
.dh { margin-bottom: 16px; }
.eyebrow { font-size: 11px; letter-spacing: .08em; text-transform: uppercase; color: var(--ink-4); }
.dh h2 { margin: 4px 0 7px; font-size: 20px; font-weight: 600; letter-spacing: -.01em; line-height: 1.25; }
.dh-figure { font-size: 25px; font-weight: 600; letter-spacing: -.02em; }
.dh-figure span { font-size: 11.5px; font-weight: 400; color: var(--ink-3); margin-left: 7px; letter-spacing: 0; }
.dh-meta { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-top: 10px; }

.note { display: flex; gap: 8px; align-items: flex-start; font-size: 12.5px; padding: 9px 11px; border-radius: var(--r); border: 1px solid; margin-bottom: 10px; line-height: 1.45; }
.note svg { flex: none; margin-top: 1px; }
.note-warn { background: var(--amber-soft); border-color: var(--amber-line); color: var(--amber); }
.note-danger { background: var(--red-soft); border-color: var(--red-line); color: var(--red); }

.dsec { margin: 22px 0; }
.dsec h3 { margin: 0 0 9px; font-size: 11px; text-transform: uppercase; letter-spacing: .08em; color: var(--ink-4); font-weight: 600; }
.ledger { border: 1px solid var(--rule); border-radius: var(--r); overflow: hidden; }
.lrow { display: flex; justify-content: space-between; gap: 14px; padding: 7px 11px; font-size: 12.5px; background: var(--paper); }
.lrow + .lrow { border-top: 1px solid var(--rule); }
.lrow.is-muted { color: var(--ink-3); }
.lrow.has-rule { background: var(--paper-2); font-weight: 600; }
.lrow.is-total { background: var(--slate); color: #fff; font-weight: 600; font-size: 13.5px; border-top: 0; }
.sign { font-style: normal; color: var(--ink-4); margin-right: 3px; }
.lrow.is-total .sign { color: #8e98ad; }
.hint { font-size: 11.5px; color: var(--ink-3); margin: 8px 0 0; line-height: 1.5; }

.facts { display: grid; grid-template-columns: 138px 1fr; margin: 0; border: 1px solid var(--rule); border-radius: var(--r); overflow: hidden; font-size: 13px; }
.facts dt { padding: 8px 12px; color: var(--ink-3); background: var(--paper-2); border-bottom: 1px solid var(--rule); }
.facts dd { margin: 0; padding: 8px 12px; border-bottom: 1px solid var(--rule); word-break: break-word; }
.facts dt:last-of-type, .facts dd:last-of-type { border-bottom: 0; }

.payouts { display: grid; gap: 7px; }
.payout { border: 1px solid var(--rule); border-radius: var(--r); overflow: hidden; background: var(--paper); }
.payout.is-open { border-color: var(--indigo-2); box-shadow: 0 0 0 3px var(--indigo-soft); }
.payout-head { width: 100%; display: grid; grid-template-columns: 16px 1fr 58px 96px 96px 96px auto; align-items: center; gap: 9px;
  font: inherit; font-size: 12.5px; padding: 9px 11px; background: var(--paper-2); border: 0; cursor: pointer; text-align: left; }
.payout-head:hover { background: var(--paper-3); }
.payout-head .chev { color: var(--ink-4); transition: transform 150ms ease; }
.payout.is-open .payout-head .chev { transform: rotate(180deg); }
.po-cycle { font-weight: 600; }
.po-days, .po-amt, .po-ded, .po-net { text-align: right; }
.po-net { font-weight: 600; }
.payslip { padding: 14px; border-top: 1px solid var(--rule); }
.slip-cols { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
.slip-cols h5 { margin: 0 0 7px; font-size: 10px; text-transform: uppercase; letter-spacing: .08em; color: var(--ink-4); font-weight: 600; }
.slip-cols h5:not(:first-child) { margin-top: 13px; }
.slip-net { display: flex; justify-content: space-between; align-items: center; gap: 14px; margin-top: 14px; padding: 11px 13px; background: var(--slate); color: #fff; border-radius: var(--r); font-weight: 600; }
.slip-net span:last-child { font-size: 17px; }
.slip-actions { display: grid; grid-template-columns: repeat(3, 1fr) auto; gap: 10px; align-items: end; margin-top: 14px; padding-top: 13px; border-top: 1px dashed var(--rule-2); }
.slip-btn { display: flex; justify-content: flex-end; }

.record-id { color: var(--ink-4); font-size: 11px; margin: 26px 0 0; }

.sheet { position: fixed; inset: 0; background: var(--paper-2); z-index: 60; display: flex; flex-direction: column; }
.sheet-head { display: flex; justify-content: space-between; align-items: center; gap: 14px; padding: 13px 24px; background: var(--paper); border-bottom: 1px solid var(--rule-2); }
.sheet-head h2 { margin: 3px 0 0; font-size: 19px; font-weight: 600; letter-spacing: -.01em; }
.sheet-body { flex: 1; overflow-y: auto; display: grid; grid-template-columns: minmax(0,1fr) 300px; gap: 24px; padding: 22px 24px 30px; align-items: start; }
.sheet-main { min-width: 0; display: grid; gap: 16px; }
.fsec { background: var(--paper); border: 1px solid var(--rule); border-radius: var(--r); overflow: hidden; }
.fsec-head { padding: 12px 16px; border-bottom: 1px solid var(--rule); background: var(--paper-2); }
.fsec-head h3 { margin: 0; font-size: 13.5px; font-weight: 600; }
.fsec-head p { margin: 2px 0 0; font-size: 12px; color: var(--ink-3); }
.fsec-body { padding: 16px; display: grid; gap: 13px; }
.fgrid { display: grid; grid-template-columns: repeat(3, minmax(0,1fr)); gap: 13px; }
.field { display: grid; gap: 5px; align-content: start; min-width: 0; }
.field.is-wide { grid-column: span 2; }
.field > label { font-size: 11.5px; font-weight: 500; color: var(--ink-2); }
.req { color: var(--red); font-style: normal; margin-left: 2px; }
.fnote { margin: 0; font-size: 11px; color: var(--ink-4); line-height: 1.35; }
.field input, .field select, .edit-table input, .cfg input, .cfg select {
  font: inherit; font-size: 13px; padding: 0 9px; border: 1px solid var(--rule-2); border-radius: 5px;
  background: var(--paper); color: var(--ink); width: 100%; min-width: 0; height: 33px;
}
.field select, .cfg select { cursor: pointer; }
.field input:disabled { background: var(--paper-3); color: var(--ink-3); cursor: not-allowed; }
.field input:focus, .field select:focus, .edit-table input:focus, .cfg input:focus { border-color: var(--indigo-2); }
.derived { font-size: 13px; height: 33px; display: flex; align-items: center; justify-content: flex-end; padding: 0 9px; background: var(--paper-3); border: 1px dashed var(--rule-2); border-radius: 5px; color: var(--ink-2); }
.derived.is-bad, .is-bad { border-color: var(--red) !important; background: var(--red-soft) !important; color: var(--red) !important; }

.modepick { display: inline-flex; gap: 0; border: 1px solid var(--rule-2); border-radius: var(--r); overflow: hidden; width: fit-content; }
.mode { font: inherit; font-size: 12.5px; padding: 7px 15px; background: var(--paper); border: 0; cursor: pointer; color: var(--ink-2); }
.mode + .mode { border-left: 1px solid var(--rule-2); }
.mode.is-on { background: var(--indigo); color: #fff; font-weight: 500; }

.edit-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.edit-table th { text-align: left; font-size: 10.5px; font-weight: 500; text-transform: uppercase; letter-spacing: .06em; color: var(--ink-4); padding: 0 6px 6px; }
.edit-table td { padding: 3px 6px; border-bottom: 1px solid var(--paper-3); }
.edit-table tr:last-child td { border-bottom: 0; }
.empty-inline { padding: 18px; text-align: center; background: var(--paper-2); border: 1px dashed var(--rule-2); border-radius: var(--r); }
.empty-inline p { margin: 0; font-size: 12.5px; color: var(--ink-3); }

.sheet-side { display: grid; gap: 13px; position: sticky; top: 0; }
.side-card { background: var(--paper); border: 1px solid var(--rule); border-radius: var(--r); padding: 14px; }
.side-card h4 { margin: 0 0 10px; font-size: 10.5px; text-transform: uppercase; letter-spacing: .08em; color: var(--ink-4); font-weight: 600; }
.side-card.is-warn { border-color: var(--amber-line); background: var(--amber-soft); }
.alloc-figure { font-size: 22px; font-weight: 600; letter-spacing: -.02em; line-height: 1.15; }
.alloc-figure span { display: block; font-size: 11.5px; font-weight: 400; color: var(--ink-3); margin-top: 3px; }
.problems { margin: 0; padding-left: 17px; font-size: 12.5px; color: var(--amber); line-height: 1.5; }
.notelist { margin: 0; padding-left: 17px; font-size: 12px; color: var(--ink-3); line-height: 1.55; }

.sheet-foot { display: flex; justify-content: space-between; align-items: center; gap: 14px; padding: 11px 24px; background: var(--paper); border-top: 1px solid var(--rule-2); }
.foot-state { display: flex; align-items: center; gap: 7px; font-size: 13px; font-weight: 500; color: var(--ink-2); }
.foot-actions { display: flex; gap: 8px; }

@media (max-width: 1080px) {
  .sheet-body { grid-template-columns: minmax(0,1fr); }
  .sheet-side { position: static; }
  .fgrid { grid-template-columns: repeat(2, minmax(0,1fr)); }
  .slip-cols { grid-template-columns: 1fr; }
  .slip-actions { grid-template-columns: 1fr 1fr; }
}
@media (max-width: 720px) {
  .app { grid-template-columns: 1fr; }
  .rail { flex-direction: row; height: auto; overflow-x: auto; position: static; padding: 8px; }
  .rail-mark { margin: 0 6px 0 0; }
  .fgrid { grid-template-columns: 1fr; }
  .field.is-wide { grid-column: span 1; }
  .drawer { width: 100vw; }
  .payout-head { grid-template-columns: 16px 1fr auto; row-gap: 4px; }
  .po-days { display: none; }
}
@media (prefers-reduced-motion: reduce) { * { transition: none !important; } }
`}</style>
  );
}
