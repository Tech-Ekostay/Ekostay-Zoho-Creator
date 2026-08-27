import React, { useState, useMemo, useEffect } from "react";
import {
  Wallet, Landmark, ReceiptText, FileSpreadsheet, CalendarClock, Eye, Database,
  Search, Plus, X, ChevronRight, ChevronLeft, ArrowUpDown, Trash2, Pencil, Lock,
  AlertTriangle, Check, Clock, CircleDot, Repeat, ListChecks, Unlink, CalendarDays,
} from "lucide-react";

/* ═══════════════════════════════════════════════════════════════
   Reference data — vocabulary from the live app.
   ═══════════════════════════════════════════════════════════════ */

const LOCATIONS = ["Karjat", "Kodaikanal", "Lonavala", "Goa", "Alibaug", "Head Office Central"];

const VILLAS = [
  { id: "v1", name: "Amani Villa", location: "Karjat" },
  { id: "v2", name: "Amanta Green 8BHK", location: "Karjat" },
  { id: "v3", name: "Chestnut Villa", location: "Karjat" },
  { id: "v4", name: "Apollo Villa", location: "Kodaikanal" },
  { id: "v5", name: "Echo Villa", location: "Kodaikanal" },
  { id: "v6", name: "Concrete Cove Villa", location: "Lonavala" },
  { id: "v7", name: "CASA SIA", location: "Lonavala" },
  { id: "v8", name: "Lonavla Central", location: "Lonavala", central: true },
  { id: "v9", name: "EKOSTAY- Sucasa Villa", location: "Lonavala" },
  { id: "v10", name: "Head Office Central", location: "Head Office Central", central: true },
  { id: "v11", name: "Casa Pino- Pilerne", location: "Goa" },
  { id: "v12", name: "Velvet Goose Villa-Calangute", location: "Goa" },
  { id: "v13", name: "Casa De Aria", location: "Alibaug" },
];

const MASTER_CATS = [
  { id: "m1", name: "Employee & Staff Considerations" },
  { id: "m2", name: "Finance & Legal" },
  { id: "m3", name: "F&B", fb: true },
];

const ITEM_CATS = [
  { id: "i1", name: "STAFF SALARY", master: "m1", payroll: true },
  { id: "i2", name: "STAFF LOAN", master: "m2" },
  { id: "i3", name: "F&B STAFF LOAN", master: "m3" },
  { id: "i4", name: "OWNER RENT", master: "m2" },
];

const VENDORS = [
  { id: "d1", name: "suman (amani ct)", role: "Caretaker" },
  { id: "d2", name: "Sravan (amanta ct)", role: "Caretaker" },
  { id: "d3", name: "Deepak (chestnut new ct)", role: "Caretaker" },
  { id: "d4", name: "Ganesh Ranu Gaikwad (CONCRETE COVE CT)", role: "Caretaker" },
  { id: "d5", name: "Kunal Kumar Paswan (CASA BELLA CT)", role: "Caretaker" },
  { id: "d6", name: "shehnaj", role: "Caretaker" },
  { id: "d7", name: "Priyanka Dinkar Balkawade (vishal wife)", role: "Caretaker" },
  { id: "d8", name: "Abuzar Food", role: "Staff" },
  { id: "d9", name: "RACHIT (PINO)", role: "Owner" },
  { id: "d10", name: "M/S SOYUZ INDUSTRIAL RESOURCES LLP (VELVET & IVORY GOOSE)", role: "Owner" },
  { id: "d11", name: "ATMARAM JAGTAP", role: "Owner" },
];

const BANKS = [
  { id: "b1", name: "EKOSTAY LLP 1", kind: "Company" },
  { id: "b2", name: "Petty Cash Gufran", kind: "Petty cash float" },
  { id: "b3", name: "Aliakber", kind: "Petty cash float" },
  { id: "b4", name: "Staff Loan", kind: "Company" },
];

const COA = [{ id: "c1", name: "Expense" }, { id: "c2", name: "Accounts Payable" }];
const TAXES = [{ id: "t0", name: "IGST0", pct: 0 }, { id: "t1", name: "GST18", pct: 18 }];
const TDS_RATES = [
  { id: "s0", name: "No TDS", pct: 0 },
  { id: "s1", name: "Owner Rent — 10.00", pct: 10 },
  { id: "s2", name: "194C Contractor — 1.00", pct: 1 },
];

const MONTHS = ["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"];
const PAYMENT_TYPES = ["Payment", "Bill & Payment"];

/**
 * Instalment status. "Ready" is the source system's "Click to Proceed" — renamed
 * because that phrase is an instruction, not a state. The action that moves an
 * instalment on is a button here, so the status can just describe where it is.
 */
const INST_FLOW = ["Draft", "Due", "Ready", "Paid"];
const INST_TONE = { Draft: "neutral", Due: "warn", Ready: "info", Paid: "good" };

const today = new Date("2026-08-08");
const iso = (d) => d.toISOString().slice(0, 10);

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
const villaName = (id) => byId(VILLAS, id)?.name ?? "—";
const catName = (id) => byId(ITEM_CATS, id)?.name ?? "—";
const vendorName = (id) => byId(VENDORS, id)?.name ?? "—";
const fmtDate = (s) => {
  if (!s) return "—";
  const d = new Date(s + "T00:00:00");
  return `${String(d.getDate()).padStart(2, "0")} ${d.toLocaleString("en", { month: "short" })} ${d.getFullYear()}`;
};
const fmtDay = (s) => {
  if (!s) return "—";
  const d = new Date(s + "T00:00:00");
  return `${d.toLocaleString("en", { weekday: "short" })} ${String(d.getDate()).padStart(2, "0")} ${d.toLocaleString("en", { month: "short" })} ${d.getFullYear()}`;
};

/** Days in the month *before* the due date — the period being billed. */
function billedMonthDays(dueDate) {
  if (!dueDate) return 30;
  const d = new Date(dueDate + "T00:00:00");
  const prev = new Date(d.getFullYear(), d.getMonth() - 1, 1);
  return new Date(prev.getFullYear(), prev.getMonth() + 1, 0).getDate();
}

/** Billing cycle for an instalment — the month before the due date. */
function billedCycle(dueDate) {
  if (!dueDate) return "";
  const d = new Date(dueDate + "T00:00:00");
  const prev = new Date(d.getFullYear(), d.getMonth() - 1, 1);
  return `${MONTHS[prev.getMonth()]}-${prev.getFullYear()}`;
}

/**
 * Instalment arithmetic, ported from the source system.
 *
 *   perDay        = Amount / days in the billed month
 *   daysDeduction = perDay × (days in month − days worked)
 *   Due Amount    = Amount − loan − penalty − advance − days − PF − PT − ESIC + excess
 *   Total Due     = Due Amount + GST%·Due Amount − TDS%·Due Amount
 *
 * Note GST and TDS apply to Due Amount here, i.e. after deductions — unlike
 * Bills and Payment, which apply them to gross.
 */
function computeInstalment(inst, tdsId, gstId) {
  const amount = +inst.amount || 0;
  const daysInMonth = billedMonthDays(inst.dueDate);
  const perDay = daysInMonth ? amount / daysInMonth : 0;
  const worked = inst.daysWorked === "" || inst.daysWorked === undefined || inst.daysWorked === null ? null : +inst.daysWorked;
  const daysDeduction = worked === null ? (+inst.daysDeduction || 0) : perDay * (daysInMonth - worked);

  const deductions = (+inst.loan || 0) + (+inst.penalty || 0) + (+inst.advance || 0) + daysDeduction
    + (+inst.pf || 0) + (+inst.pt || 0) + (+inst.esic || 0);
  const dueAmount = amount - deductions + (+inst.excess || 0);

  const tdsPct = byId(TDS_RATES, tdsId)?.pct ?? 0;
  const gstPct = byId(TAXES, gstId)?.pct ?? 0;
  const tdsAmt = dueAmount * (tdsPct / 100);
  const gstAmt = dueAmount * (gstPct / 100);
  const totalDue = dueAmount + gstAmt - tdsAmt;

  return { amount, daysInMonth, perDay, daysDeduction, deductions, dueAmount, tdsPct, gstPct, tdsAmt, gstAmt, totalDue,
    reduced: Math.round(dueAmount) !== Math.round(amount) };
}

/**
 * Regenerate the monthly instalment set from start/end/due day, reconciling
 * against what's already there so typed amounts and deductions survive.
 * A row whose due date falls outside the new window is dropped only if it is
 * untouched; if it carries an amount or a status it is kept and flagged.
 */
function reconcileInstalments(existing = [], { startDate, endDate, dueDay, amount }) {
  if (!startDate || !endDate || !dueDay) return existing.map((r) => ({ ...r, orphan: false }));
  const start = new Date(startDate + "T00:00:00");
  const end = new Date(endDate + "T00:00:00");
  const day = new Date(dueDay + "T00:00:00").getDate();

  const wanted = [];
  let cur = new Date(start.getFullYear(), start.getMonth(), 1);
  while (cur <= end && wanted.length < 240) {
    const lastDay = new Date(cur.getFullYear(), cur.getMonth() + 1, 0).getDate();
    const due = new Date(cur.getFullYear(), cur.getMonth(), Math.min(day, lastDay));
    if (due >= start && due <= end) wanted.push(iso(due));
    cur = new Date(cur.getFullYear(), cur.getMonth() + 1, 1);
  }

  const want = new Set(wanted);
  const seen = new Set();
  const out = [];
  existing.forEach((r) => {
    if (want.has(r.dueDate) && !seen.has(r.dueDate)) { seen.add(r.dueDate); out.push({ ...r, orphan: false }); }
    else if (+r.amount > 0 || r.status === "Paid") out.push({ ...r, orphan: true });
  });
  wanted.forEach((d) => {
    if (!seen.has(d)) out.push({ id: uid(), dueDate: d, date: d, amount: amount ?? "", cycle: billedCycle(d),
      loan: "", advance: "", penalty: "", daysWorked: "", daysDeduction: "", pf: "", pt: "", esic: "", excess: "",
      remarks: "", status: "Draft", orphan: false });
  });
  return out.sort((a, b) => (a.dueDate < b.dueDate ? -1 : 1));
}

/* ── seed ────────────────────────────────────────────────────── */

const mkInst = (dueDate, amount, status, extra = {}) => ({
  id: uid(), dueDate, date: dueDate, amount, cycle: billedCycle(dueDate),
  loan: "", advance: "", penalty: "", daysWorked: "", daysDeduction: "", pf: "", pt: "", esic: "", excess: "",
  remarks: "", status, ...extra,
});

const SEED = [
  {
    id: "292482000009926711", vendor: "d1", itemCat: "i1", location: "Karjat", villas: ["v1"],
    amount: 15000, tds: "s0", gst: "t0", coa: "c1", bank: "b2", paymentType: "Payment",
    startDate: "2026-07-01", endDate: "2027-12-31", dueDay: "2026-07-15", paymentDate: "2026-07-15",
    addedAt: "2026-07-23 17:44",
    instalments: [
      mkInst("2026-07-15", 15000, "Paid"), mkInst("2026-08-15", 15000, "Ready"),
      mkInst("2026-09-15", 15000, "Draft"), mkInst("2026-10-15", 15000, "Draft"),
      mkInst("2026-11-15", 15000, "Draft"), mkInst("2026-12-15", 15000, "Draft"),
    ],
  },
  {
    id: "292482000009926565", vendor: "d2", itemCat: "i1", location: "Karjat", villas: ["v2"],
    amount: 15000, tds: "s0", gst: "t0", coa: "c1", bank: "b2", paymentType: "Payment",
    startDate: "2026-07-01", endDate: "2027-12-31", dueDay: "2026-07-15", paymentDate: "2026-07-15",
    addedAt: "2026-07-23 17:13",
    instalments: [
      mkInst("2026-07-15", 15000, "Due"), mkInst("2026-08-15", 15000, "Draft"), mkInst("2026-09-15", 15000, "Draft"),
    ],
  },
  {
    id: "292482000009926493", vendor: "d3", itemCat: "i1", location: "Karjat", villas: ["v3"],
    amount: 16000, tds: "s0", gst: "t0", coa: "c1", bank: "b2", paymentType: "Payment",
    startDate: "2026-07-01", endDate: "2027-12-31", dueDay: "2026-07-15", paymentDate: "2026-07-15",
    addedAt: "2026-07-23 16:58",
    instalments: [
      mkInst("2026-07-15", 16000, "Paid"),
      mkInst("2026-08-15", 16000, "Ready", { daysWorked: 27, remarks: "3 days absent, unapproved" }),
      mkInst("2026-09-15", 16000, "Draft"),
    ],
  },
  {
    id: "292482000009903885", vendor: "d4", itemCat: "i1", location: "Kodaikanal", villas: ["v4"],
    amount: 12000, tds: "s0", gst: "t0", coa: "c1", bank: "b1", paymentType: "Payment",
    startDate: "2026-06-01", endDate: "2030-12-31", dueDay: "2026-06-15", paymentDate: "2026-06-15",
    addedAt: "2026-07-22 15:38",
    instalments: [
      mkInst("2026-06-15", 12000, "Due"), mkInst("2026-07-15", 12000, "Due"), mkInst("2026-08-15", 12000, "Draft"),
    ],
  },
  {
    id: "292482000009903737", vendor: "d5", itemCat: "i1", location: "Kodaikanal", villas: ["v4", "v5"],
    amount: 20000, tds: "s0", gst: "t0", coa: "c1", bank: "b1", paymentType: "Payment",
    startDate: "2026-06-01", endDate: "2028-12-31", dueDay: "2026-06-15", paymentDate: "2026-06-15",
    addedAt: "2026-07-22 15:12",
    instalments: [mkInst("2026-06-15", 20000, "Due"), mkInst("2026-07-15", 20000, "Due"), mkInst("2026-08-15", 20000, "Draft")],
  },
  {
    id: "292482000009892104", vendor: "d6", itemCat: "i1", location: "Lonavala", villas: ["v6"],
    amount: 25000, tds: "s0", gst: "t0", coa: "c1", bank: "b3", paymentType: "Payment",
    startDate: "2026-07-01", endDate: "2027-12-31", dueDay: "2026-07-10", paymentDate: "2026-07-10",
    addedAt: "2026-07-21 16:59",
    instalments: [mkInst("2026-07-10", 25000, "Paid"), mkInst("2026-08-10", 25000, "Ready"), mkInst("2026-09-10", 25000, "Draft")],
  },
  {
    id: "292482000009867966", vendor: "d8", itemCat: "i2", location: "Head Office Central", villas: ["v10"],
    amount: 10000, tds: "s0", gst: "t0", coa: "c1", bank: "b4", paymentType: "Payment",
    startDate: "2026-08-08", endDate: "2026-10-08", dueDay: "2026-08-08", paymentDate: "2026-08-08",
    addedAt: "2026-07-21 15:32",
    instalments: [mkInst("2026-08-08", 10000, "Ready"), mkInst("2026-09-08", 10000, "Draft"), mkInst("2026-10-08", 10000, "Draft")],
  },
  {
    id: "292482000009888825", vendor: "d9", itemCat: "i4", location: "Goa", villas: ["v11"],
    amount: 129900, tds: "s1", gst: "t0", coa: "c1", bank: "b1", paymentType: "Bill & Payment",
    startDate: "2026-06-01", endDate: "2027-12-31", dueDay: "2026-06-02", paymentDate: "2026-06-02",
    addedAt: "2026-07-21 16:34",
    instalments: [
      mkInst("2026-06-02", 129900, "Paid"), mkInst("2026-07-02", 129900, "Paid"),
      mkInst("2026-08-02", 129900, "Ready"), mkInst("2026-09-02", 129900, "Draft"),
    ],
  },
  {
    id: "292482000009888779", vendor: "d10", itemCat: "i4", location: "Goa", villas: ["v12"],
    amount: 310000, tds: "s1", gst: "t0", coa: "c1", bank: "b1", paymentType: "Bill & Payment",
    startDate: "2026-06-01", endDate: "2027-12-31", dueDay: "2026-06-05", paymentDate: "2026-06-05",
    addedAt: "2026-07-21 16:30",
    instalments: [mkInst("2026-06-05", 310000, "Paid"), mkInst("2026-07-05", 310000, "Ready"), mkInst("2026-08-05", 310000, "Draft")],
  },
  {
    id: "292482000009867936", vendor: "d11", itemCat: "i4", location: "Alibaug", villas: ["v13"],
    amount: 20000, tds: "s1", gst: "t0", coa: "c1", bank: "b1", paymentType: "Bill & Payment",
    startDate: "2026-06-01", endDate: "2027-12-31", dueDay: "2026-06-05", paymentDate: "2026-06-05",
    addedAt: "2026-07-21 15:26",
    instalments: [mkInst("2026-06-05", 20000, "Paid"), mkInst("2026-07-05", 20000, "Due"), mkInst("2026-08-05", 20000, "Draft")],
  },
];

/* ═══════════════════════════════════════════════════════════════ */

const NAV = [
  { icon: Wallet, label: "Accounts" }, { icon: ReceiptText, label: "Payments" },
  { icon: Landmark, label: "Bank" }, { icon: FileSpreadsheet, label: "Bills" },
  { icon: ReceiptText, label: "Expenses" }, { icon: CalendarClock, label: "Schedule", active: true },
  { icon: Eye, label: "Observations" }, { icon: Database, label: "Masters" },
];

export default function SchedulePaymentsModule() {
  const [schedules, setSchedules] = useState(SEED);
  const [tab, setTab] = useState("queue");
  const [openId, setOpenId] = useState(null);
  const [editing, setEditing] = useState(null);
  const [editInst, setEditInst] = useState(null); // {scheduleId, instId}

  /* ── flatten instalments for the queue ── */
  const rows = useMemo(() => {
    const out = [];
    schedules.forEach((s) => s.instalments.forEach((i) => out.push({ ...i, schedule: s })));
    return out.sort((a, b) => (a.dueDate < b.dueDate ? -1 : a.dueDate > b.dueDate ? 1 : 0));
  }, [schedules]);

  const setInstalment = (scheduleId, instId, patch) =>
    setSchedules((prev) => prev.map((s) => (s.id !== scheduleId ? s
      : { ...s, instalments: s.instalments.map((i) => (i.id === instId ? { ...i, ...patch } : i)) })));

  const saveSchedule = (sc) => {
    setSchedules((prev) => (prev.some((s) => s.id === sc.id) ? prev.map((s) => (s.id === sc.id ? sc : s)) : [sc, ...prev]));
    setEditing(null); setOpenId(sc.id);
  };

  const open = openId ? schedules.find((s) => s.id === openId) : null;

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
              <h1>Schedule payments</h1>
              <p className="sub">Recurring rent, salary and loan payments, and the instalments they generate</p>
            </div>
            <div className="who">
              <span className="who-name">Husain Khatumdi</span>
              <span className="who-role">Account Team&nbsp;·&nbsp;Senior</span>
            </div>
          </header>

          <div className="tabs" role="tablist">
            <button role="tab" aria-selected={tab === "queue"} className={"tab" + (tab === "queue" ? " is-on" : "")} onClick={() => setTab("queue")}>
              <ListChecks size={15} strokeWidth={2} />What's due
            </button>
            <button role="tab" aria-selected={tab === "schedules"} className={"tab" + (tab === "schedules" ? " is-on" : "")} onClick={() => setTab("schedules")}>
              <Repeat size={15} strokeWidth={2} />Schedules
            </button>
          </div>

          {tab === "queue"
            ? <Queue rows={rows} onEditInst={(sid, iid) => setEditInst({ scheduleId: sid, instId: iid })}
                onSetStatus={(sid, iid, status) => setInstalment(sid, iid, { status })}
                onOpenSchedule={(sid) => { setTab("schedules"); setOpenId(sid); }} />
            : <Schedules schedules={schedules} openId={openId} setOpenId={setOpenId} onAdd={() => setEditing("new")} />}
        </main>

        {open && tab === "schedules" && (
          <ScheduleDrawer s={open} onClose={() => setOpenId(null)} onEdit={() => setEditing(open)}
            onEditInst={(iid) => setEditInst({ scheduleId: open.id, instId: iid })} />
        )}
        {editing && <ScheduleForm initial={editing === "new" ? null : editing} onCancel={() => setEditing(null)} onSave={saveSchedule} />}
        {editInst && (() => {
          const s = schedules.find((x) => x.id === editInst.scheduleId);
          const i = s?.instalments.find((x) => x.id === editInst.instId);
          if (!i) return null;
          return <InstalmentEditor s={s} inst={i} onCancel={() => setEditInst(null)}
            onSave={(patch) => { setInstalment(s.id, i.id, patch); setEditInst(null); }} />;
        })()}
      </div>
    </>
  );
}

/* ── the due queue ───────────────────────────────────────────── */

function Queue({ rows, onEditInst, onSetStatus, onOpenSchedule }) {
  const [q, setQ] = useState("");
  const [status, setStatus] = useState("Open");
  const [picked, setPicked] = useState(new Set());

  const filtered = useMemo(() => rows.filter((r) => {
    if (status === "Open" && r.status === "Paid") return false;
    if (status === "Overdue" && !(r.status !== "Paid" && new Date(r.dueDate) < today)) return false;
    if (!["All", "Open", "Overdue"].includes(status) && r.status !== status) return false;
    if (!q.trim()) return true;
    const s = r.schedule;
    return [vendorName(s.vendor), catName(s.itemCat), s.location, ...s.villas.map(villaName)]
      .join(" ").toLowerCase().includes(q.toLowerCase());
  }), [rows, q, status]);

  /* group by due date, the way the source system does */
  const groups = useMemo(() => {
    const m = new Map();
    filtered.forEach((r) => { if (!m.has(r.dueDate)) m.set(r.dueDate, []); m.get(r.dueDate).push(r); });
    return [...m.entries()].map(([date, items]) => ({ date, items }));
  }, [filtered]);

  const totals = useMemo(() => {
    const t = { due: 0, dueN: 0, overdue: 0, overdueN: 0, ready: 0, readyN: 0 };
    rows.forEach((r) => {
      const c = computeInstalment(r, r.schedule.tds, r.schedule.gst);
      if (r.status === "Paid") return;
      t.due += c.totalDue; t.dueN += 1;
      if (new Date(r.dueDate) < today) { t.overdue += c.totalDue; t.overdueN += 1; }
      if (r.status === "Ready") { t.ready += c.totalDue; t.readyN += 1; }
    });
    return t;
  }, [rows]);

  const toggle = (id) => setPicked((p) => { const n = new Set(p); n.has(id) ? n.delete(id) : n.add(id); return n; });
  const toggleGroup = (items) => setPicked((p) => {
    const n = new Set(p);
    const all = items.every((i) => n.has(i.id));
    items.forEach((i) => (all ? n.delete(i.id) : n.add(i.id)));
    return n;
  });
  const bulk = (st) => {
    rows.filter((r) => picked.has(r.id)).forEach((r) => onSetStatus(r.schedule.id, r.id, st));
    setPicked(new Set());
  };

  const pickedRows = rows.filter((r) => picked.has(r.id));
  const pickedTotal = pickedRows.reduce((a, r) => a + computeInstalment(r, r.schedule.tds, r.schedule.gst).totalDue, 0);

  return (
    <>
      <section className="strip" aria-label="Summary">
        <Metric label={`Outstanding · ${totals.dueN}`} value={inr(totals.due, { paise: false })} tone="info" />
        <Metric label={`Overdue · ${totals.overdueN}`} value={inr(totals.overdue, { paise: false })} tone={totals.overdueN ? "danger" : "good"} />
        <Metric label={`Ready to pay · ${totals.readyN}`} value={inr(totals.ready, { paise: false })} tone="warn" />
        <Metric label="Instalments on file" value={String(rows.length)} />
      </section>

      <div className="toolbar">
        <div className="search">
          <Search size={15} strokeWidth={2} />
          <input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Vendor, villa, category" aria-label="Search instalments" />
          {q && <button className="clear" onClick={() => setQ("")} aria-label="Clear"><X size={13} /></button>}
        </div>
        <div className="chips" role="group" aria-label="Filter">
          {["Open", "Overdue", ...INST_FLOW, "All"].map((s) => (
            <button key={s} className={"chip" + (status === s ? " is-on" : "")} onClick={() => setStatus(s)}>{s}</button>
          ))}
        </div>
      </div>

      {picked.size > 0 && (
        <div className="bulkbar" role="region" aria-label="Bulk actions">
          <span><b>{picked.size}</b> selected · {inr(pickedTotal, { paise: false })}</span>
          <div className="bulk-actions">
            <button className="btn btn-ghost" onClick={() => bulk("Due")}>Mark due</button>
            <button className="btn btn-ghost" onClick={() => bulk("Ready")}>Mark ready to pay</button>
            <button className="btn btn-primary" onClick={() => bulk("Paid")}><Check size={14} />Create payments &amp; mark paid</button>
            <button className="icon-btn" onClick={() => setPicked(new Set())} aria-label="Clear selection"><X size={15} /></button>
          </div>
        </div>
      )}

      {groups.length === 0 ? (
        <div className="empty-block"><p>Nothing matches this view.</p></div>
      ) : (
        <div className="table-wrap">
          <table className="grid">
            <thead>
              <tr>
                <th className="w-check" />
                <th>Vendor</th><th>Villa · Category</th><th>Cycle</th>
                <th className="num">Amount</th><th className="num">Deductions</th><th className="num">Payable</th>
                <th>Status</th><th>Schedule</th><th aria-label="Actions" />
              </tr>
            </thead>
            {groups.map((g) => {
              const overdue = new Date(g.date) < today && g.items.some((i) => i.status !== "Paid");
              const gTotal = g.items.reduce((a, r) => a + computeInstalment(r, r.schedule.tds, r.schedule.gst).totalDue, 0);
              const allPicked = g.items.every((i) => picked.has(i.id));
              return (
                <tbody key={g.date}>
                  <tr className={"grouprow" + (overdue ? " is-overdue" : "")}>
                    <td className="w-check">
                      <input type="checkbox" checked={allPicked} onChange={() => toggleGroup(g.items)}
                        aria-label={`Select all due ${g.date}`} />
                    </td>
                    <td colSpan={8}>
                      <span className="gdate"><CalendarDays size={13} strokeWidth={2} />{fmtDay(g.date)}</span>
                      {overdue && <Badge tone="danger">overdue</Badge>}
                      <span className="gmeta">{g.items.length} instalment{g.items.length > 1 ? "s" : ""} · {inr(gTotal, { paise: false })}</span>
                    </td>
                    <td />
                  </tr>
                  {g.items.map((r) => {
                    const s = r.schedule;
                    const c = computeInstalment(r, s.tds, s.gst);
                    return (
                      <tr key={r.id} className={picked.has(r.id) ? "is-picked" : ""}>
                        <td className="w-check">
                          <input type="checkbox" checked={picked.has(r.id)} onChange={() => toggle(r.id)}
                            aria-label={`Select ${vendorName(s.vendor)} ${r.dueDate}`} />
                        </td>
                        <td className="truncate" title={vendorName(s.vendor)}>{vendorName(s.vendor)}</td>
                        <td className="truncate stack" title={`${s.villas.map(villaName).join(", ")} · ${catName(s.itemCat)}`}>
                          <span>{s.villas.length > 1 ? `${villaName(s.villas[0])} +${s.villas.length - 1}` : villaName(s.villas[0])}</span>
                          <em>{catName(s.itemCat)}</em>
                        </td>
                        <td className="mono dim nowrap">{r.cycle}</td>
                        <td className="mono num">{inr(c.amount, { paise: false })}</td>
                        <td className={"mono num " + (c.deductions ? "tone-warn" : "dim")}>
                          {c.deductions ? "−" + inr(c.deductions, { paise: false, bare: true }) : "—"}
                        </td>
                        <td className="mono num strong">{inr(c.totalDue, { paise: false })}</td>
                        <td><Badge tone={INST_TONE[r.status]}>{r.status}</Badge></td>
                        <td>
                          <button className="linklike" onClick={() => onOpenSchedule(s.id)}>
                            {s.paymentType} · {inr(s.amount, { paise: false })}/mo
                          </button>
                        </td>
                        <td className="pad-r rowacts">
                          {r.status !== "Paid" && (
                            <>
                              <button className="btn btn-ghost sm" onClick={() => onEditInst(s.id, r.id)}><Pencil size={12} />Adjust</button>
                              {r.status === "Ready"
                                ? <button className="btn btn-primary sm" onClick={() => onSetStatus(s.id, r.id, "Paid")}><Check size={12} />Pay</button>
                                : <button className="btn btn-ghost sm" onClick={() => onSetStatus(s.id, r.id, "Ready")}>Ready</button>}
                            </>
                          )}
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              );
            })}
          </table>
        </div>
      )}
      <p className="count">{filtered.length} of {rows.length} instalments</p>
    </>
  );
}

/* ── schedules list ──────────────────────────────────────────── */

function Schedules({ schedules, openId, setOpenId, onAdd }) {
  const [q, setQ] = useState("");
  const [sort, setSort] = useState({ key: "addedAt", dir: "desc" });

  const rows = useMemo(() => {
    let r = schedules.filter((s) => !q.trim() || [vendorName(s.vendor), catName(s.itemCat), s.location, ...s.villas.map(villaName)]
      .join(" ").toLowerCase().includes(q.toLowerCase()));
    const dir = sort.dir === "asc" ? 1 : -1;
    return [...r].sort((a, b) => {
      const g = (x) => (sort.key === "vendor" ? vendorName(x.vendor) : x[sort.key] ?? "");
      const av = g(a), bv = g(b);
      return (typeof av === "number" ? av - bv : String(av).localeCompare(String(bv))) * dir;
    });
  }, [schedules, q, sort]);

  return (
    <>
      <div className="toolbar">
        <div className="search">
          <Search size={15} strokeWidth={2} />
          <input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Vendor, villa, category" aria-label="Search schedules" />
          {q && <button className="clear" onClick={() => setQ("")} aria-label="Clear"><X size={13} /></button>}
        </div>
        <button className="btn btn-primary" onClick={onAdd}><Plus size={15} strokeWidth={2.5} />Add schedule</button>
      </div>

      <div className="table-wrap">
        <table className="grid">
          <thead>
            <tr>
              <Th s={sort} set={setSort} k="vendor">Vendor</Th>
              <th>Villa · Category</th><th>Location</th>
              <Th s={sort} set={setSort} k="amount" align="right">Per month</Th>
              <th>Runs</th><th>Progress</th><th>Type</th><th>Paid from</th><th aria-label="" />
            </tr>
          </thead>
          <tbody>
            {rows.map((s) => {
              const paid = s.instalments.filter((i) => i.status === "Paid").length;
              const overdue = s.instalments.filter((i) => i.status !== "Paid" && new Date(i.dueDate) < today).length;
              const pct = s.instalments.length ? (paid / s.instalments.length) * 100 : 0;
              return (
                <tr key={s.id} onClick={() => setOpenId(s.id)} className={openId === s.id ? "is-open" : ""} tabIndex={0}
                    onKeyDown={(e) => e.key === "Enter" && setOpenId(s.id)}>
                  <td className="truncate strong" title={vendorName(s.vendor)}>{vendorName(s.vendor)}</td>
                  <td className="truncate stack" title={`${s.villas.map(villaName).join(", ")} · ${catName(s.itemCat)}`}>
                    <span>{s.villas.length > 1 ? `${villaName(s.villas[0])} +${s.villas.length - 1}` : villaName(s.villas[0])}</span>
                    <em>{catName(s.itemCat)}</em>
                  </td>
                  <td className="dim">{s.location}</td>
                  <td className="mono num strong">{inr(s.amount, { paise: false })}</td>
                  <td className="dim nowrap sm">{fmtDate(s.startDate)} → {fmtDate(s.endDate)}</td>
                  <td>
                    <div className="prog" title={`${paid} of ${s.instalments.length} paid`}>
                      <div className="prog-bar"><div className="prog-fill" style={{ width: `${pct}%` }} /></div>
                      <span className="mono sm">{paid}/{s.instalments.length}</span>
                    </div>
                  </td>
                  <td><Badge tone={s.paymentType === "Bill & Payment" ? "info" : "neutral"}>{s.paymentType}</Badge></td>
                  <td className="truncate dim sm" title={byId(BANKS, s.bank)?.name}>{byId(BANKS, s.bank)?.name}</td>
                  <td className="pad-r">
                    {overdue > 0 && <span className="warn-dot" title={`${overdue} overdue`}><AlertTriangle size={13} strokeWidth={2.25} /></span>}
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>
      <p className="count">{rows.length} of {schedules.length} schedules</p>
    </>
  );
}

/* ── shared bits ─────────────────────────────────────────────── */

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
const Badge = ({ tone = "neutral", children }) => <span className={"badge tone-" + tone}>{children}</span>;
const Section = ({ title, children }) => <section className="dsec"><h3>{title}</h3>{children}</section>;
const Facts = ({ rows }) => (
  <dl className="facts">{rows.map(([k, v]) => <React.Fragment key={k}><dt>{k}</dt><dd>{v || "—"}</dd></React.Fragment>)}</dl>
);
const Row = ({ label, value, sign, total, rule, muted }) => (
  <div className={"lrow" + (total ? " is-total" : "") + (rule ? " has-rule" : "") + (muted ? " is-muted" : "")}>
    <span>{label}</span><span className="mono">{sign && <i className="sign">{sign}</i>}{inr(value)}</span>
  </div>
);
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

/* ── schedule drawer ─────────────────────────────────────────── */

function ScheduleDrawer({ s, onClose, onEdit, onEditInst }) {
  useEffect(() => {
    const h = (e) => e.key === "Escape" && onClose();
    window.addEventListener("keydown", h);
    return () => window.removeEventListener("keydown", h);
  }, [onClose]);

  const paid = s.instalments.filter((i) => i.status === "Paid");
  const remaining = s.instalments.filter((i) => i.status !== "Paid");
  const totalCommitted = s.instalments.reduce((a, i) => a + (+i.amount || 0), 0);

  return (
    <aside className="drawer" role="dialog" aria-label={`Schedule for ${vendorName(s.vendor)}`}>
      <header className="drawer-head">
        <span className="mono eyebrow">Schedule</span>
        <div className="drawer-actions">
          <button className="btn btn-ghost" onClick={onEdit}><Pencil size={14} />Edit</button>
          <button className="btn btn-ghost danger" disabled={paid.length > 0}
            title={paid.length ? "Instalments have been paid — this schedule can't be deleted" : "Delete this schedule"}>
            <Trash2 size={14} />Delete
          </button>
          <button className="icon-btn" onClick={onClose} aria-label="Close"><X size={16} /></button>
        </div>
      </header>

      <div className="drawer-body">
        <div className="dh">
          <h2>{vendorName(s.vendor)}</h2>
          <div className="dh-figure mono">{inr(s.amount, { paise: false })}<span>per month</span></div>
          <div className="dh-meta">
            <Badge tone={s.paymentType === "Bill & Payment" ? "info" : "neutral"}>{s.paymentType}</Badge>
            <span className="dim sm">{catName(s.itemCat)}</span>
          </div>
        </div>

        {new Date(s.endDate).getFullYear() - new Date(s.startDate).getFullYear() >= 3 && (
          <div className="note note-warn">
            <AlertTriangle size={14} />
            <span>This schedule runs to {fmtDate(s.endDate)} — {s.instalments.length} instalments generated in advance. Consider a shorter horizon and renewing.</span>
          </div>
        )}

        <Section title="Commitment">
          <div className="ledger">
            <Row label="Per instalment" value={s.amount} />
            <Row label={`Instalments · ${s.instalments.length}`} value={totalCommitted} rule />
            <Row label={`Paid · ${paid.length}`} value={paid.reduce((a, i) => a + (+i.amount || 0), 0)} muted />
            <Row label={`Remaining · ${remaining.length}`} value={remaining.reduce((a, i) => a + (+i.amount || 0), 0)} total />
          </div>
        </Section>

        <Section title="Terms">
          <Facts rows={[
            ["Vendor", vendorName(s.vendor)],
            ["Villas", s.villas.map(villaName).join(", ")],
            ["Location", s.location],
            ["Category", catName(s.itemCat)],
            ["Master category", byId(MASTER_CATS, byId(ITEM_CATS, s.itemCat)?.master)?.name],
            ["Runs", `${fmtDate(s.startDate)} → ${fmtDate(s.endDate)}`],
            ["Due on", `day ${new Date(s.dueDay + "T00:00:00").getDate()} of each month`],
            ["Payment type", s.paymentType],
            ["Chart of account", byId(COA, s.coa)?.name],
            ["Paid from", `${byId(BANKS, s.bank)?.name} (${byId(BANKS, s.bank)?.kind})`],
            ["TDS", byId(TDS_RATES, s.tds)?.name],
            ["GST", byId(TAXES, s.gst)?.name],
          ]} />
        </Section>

        <Section title={`Instalments · ${s.instalments.length}`}>
          <table className="mini">
            <thead><tr><th>Due</th><th>Cycle</th><th className="num">Amount</th><th className="num">Payable</th><th>Status</th><th /></tr></thead>
            <tbody>
              {s.instalments.map((i) => {
                const c = computeInstalment(i, s.tds, s.gst);
                const late = i.status !== "Paid" && new Date(i.dueDate) < today;
                return (
                  <tr key={i.id} className={late ? "is-late" : ""}>
                    <td className="mono nowrap">{fmtDate(i.dueDate)}</td>
                    <td className="mono dim">{i.cycle}</td>
                    <td className="mono num">{inr(i.amount, { paise: false })}</td>
                    <td className={"mono num " + (c.reduced ? "tone-warn" : "")}>{inr(c.totalDue, { paise: false })}</td>
                    <td><Badge tone={INST_TONE[i.status]}>{i.status}</Badge></td>
                    <td className="pad-r">
                      {i.status !== "Paid" && <button className="btn btn-ghost sm" onClick={() => onEditInst(i.id)}><Pencil size={12} />Adjust</button>}
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </Section>

        <p className="record-id mono">Record ID {s.id} · added {s.addedAt}</p>
      </div>
    </aside>
  );
}

/* ── instalment editor ───────────────────────────────────────── */

function InstalmentEditor({ s, inst, onCancel, onSave }) {
  const [i, setI] = useState(() => ({ ...inst }));
  const [touched, setTouched] = useState(false);
  const c = computeInstalment(i, s.tds, s.gst);
  const set = (patch) => setI((x) => ({ ...x, ...patch }));

  const problems = [];
  if (c.reduced && !String(i.remarks || "").trim())
    problems.push("The payable differs from the scheduled amount, so a remark is required.");
  if (i.daysWorked !== "" && i.daysWorked !== null && +i.daysWorked > c.daysInMonth)
    problems.push(`Days worked can't exceed ${c.daysInMonth} for this cycle.`);

  const submit = () => { setTouched(true); if (!problems.length) onSave(i); };

  const DED = [
    ["loan", "Loan deduction"], ["advance", "Advance deduction"], ["penalty", "Penalty"],
    ["pf", "PF"], ["pt", "PT"], ["esic", "ESIC"],
  ];

  return (
    <div className="modal-back" onClick={onCancel}>
      <div className="modal is-wide" role="dialog" aria-label="Adjust instalment" onClick={(e) => e.stopPropagation()}>
        <header className="modal-head">
          <div>
            <span className="eyebrow">{vendorName(s.vendor)} · {catName(s.itemCat)}</span>
            <h3>Instalment due {fmtDate(i.dueDate)}</h3>
          </div>
          <button className="icon-btn" onClick={onCancel} aria-label="Close"><X size={16} /></button>
        </header>

        <div className="modal-body">
          <div>
            <FSec n="Deductions" hint={`Billed cycle ${i.cycle} · ${c.daysInMonth} days · ${inr(c.perDay)} per day`}>
              <Grid>
                <Field label="Days worked" note={`Out of ${c.daysInMonth}. Leave blank for a full month.`}>
                  <input className="mono ta-r" type="number" value={i.daysWorked ?? ""} max={c.daysInMonth}
                    onChange={(e) => set({ daysWorked: e.target.value })} placeholder={String(c.daysInMonth)} />
                </Field>
                <Field label="Days deduction" note="From days worked">
                  <div className="derived mono">{inr(c.daysDeduction)}</div>
                </Field>
                {DED.map(([k, label]) => (
                  <Field key={k} label={label}>
                    <input className="mono ta-r" type="number" value={i[k] ?? ""} onChange={(e) => set({ [k]: e.target.value })} placeholder="0" />
                  </Field>
                ))}
                <Field label="Excess amount" note="Added back, not deducted">
                  <input className="mono ta-r" type="number" value={i.excess ?? ""} onChange={(e) => set({ excess: e.target.value })} placeholder="0" />
                </Field>
              </Grid>
            </FSec>

            <FSec n="Remarks" hint={c.reduced ? "Required — the payable differs from the scheduled amount" : "Optional"}>
              <textarea rows={3} value={i.remarks ?? ""} onChange={(e) => set({ remarks: e.target.value })}
                className={touched && c.reduced && !String(i.remarks || "").trim() ? "is-bad" : ""}
                placeholder="Why does this instalment differ?" />
            </FSec>
          </div>

          <aside className="modal-side">
            <div className="side-card">
              <h4>Working</h4>
              <div className="ledger">
                <Row label="Scheduled amount" value={c.amount} />
                {!!c.daysDeduction && <Row label="Days" value={c.daysDeduction} sign="−" muted />}
                {DED.map(([k, label]) => (+i[k] ? <Row key={k} label={label} value={+i[k]} sign="−" muted /> : null))}
                {!!+i.excess && <Row label="Excess" value={+i.excess} sign="+" muted />}
                <Row label="Due amount" value={c.dueAmount} rule />
                {c.gstPct > 0 && <Row label={`GST (${c.gstPct}%)`} value={c.gstAmt} sign="+" muted />}
                {c.tdsPct > 0 && <Row label={`TDS (${c.tdsPct}%)`} value={c.tdsAmt} sign="−" muted />}
                <Row label="Total due" value={c.totalDue} total />
              </div>
              <p className="fnote">GST and TDS apply to the due amount, after deductions.</p>
            </div>
            {touched && problems.length > 0 && (
              <div className="side-card is-warn">
                <h4>Before saving</h4>
                <ul className="problems">{problems.map((p) => <li key={p}>{p}</li>)}</ul>
              </div>
            )}
          </aside>
        </div>

        <div className="modal-foot">
          <button className="btn btn-ghost" onClick={onCancel}>Cancel</button>
          <button className="btn btn-primary" onClick={submit}>Save instalment</button>
        </div>
      </div>
    </div>
  );
}

/* ── schedule form ───────────────────────────────────────────── */

const blankSchedule = () => ({
  id: String(292482000009900000 + Math.floor(Math.random() * 99999)),
  vendor: "", itemCat: "", location: "", villas: [], amount: "",
  tds: "s0", gst: "t0", coa: "c1", bank: "", paymentType: "Payment",
  startDate: "", endDate: "", dueDay: "", paymentDate: "",
  addedAt: new Date().toISOString().slice(0, 16).replace("T", " "), instalments: [],
});

function ScheduleForm({ initial, onCancel, onSave }) {
  const [s, setS] = useState(() => (initial ? JSON.parse(JSON.stringify(initial)) : blankSchedule()));
  const [touched, setTouched] = useState(false);
  const set = (patch) => setS((x) => ({ ...x, ...patch }));

  /** Terms changing reshapes the instalment set without destroying edits. */
  const setTerms = (patch) => setS((prev) => {
    const next = { ...prev, ...patch };
    return { ...next, instalments: reconcileInstalments(next.instalments, next) };
  });

  const villaChoices = VILLAS.filter((v) => !s.location || v.location === s.location || s.villas.includes(v.id));
  const orphans = s.instalments.filter((i) => i.orphan);
  const live = s.instalments.filter((i) => !i.orphan);
  const committed = live.reduce((a, i) => a + (+i.amount || 0), 0);
  const isPayroll = byId(ITEM_CATS, s.itemCat)?.payroll;

  const problems = [];
  if (!s.vendor) problems.push("Choose a vendor.");
  if (!s.itemCat) problems.push("Choose an item category.");
  if (!s.villas.length) problems.push("Choose at least one villa.");
  if (!s.bank) problems.push("Choose the account this is paid from.");
  if (!(+s.amount)) problems.push("Enter the monthly amount.");
  if (!s.startDate || !s.endDate) problems.push("Set the start and end dates.");
  if (!s.dueDay) problems.push("Set the first due date — its day of month repeats.");
  if (s.startDate && s.endDate && s.startDate > s.endDate) problems.push("End date is before the start date.");
  if (orphans.length) problems.push(`${orphans.length} instalment${orphans.length > 1 ? "s" : ""} fall outside the new dates but carry amounts or are paid.`);

  const years = s.startDate && s.endDate
    ? (new Date(s.endDate) - new Date(s.startDate)) / (365.25 * 864e5) : 0;

  const submit = () => { setTouched(true); if (!problems.length) onSave(s); };

  return (
    <div className="sheet" role="dialog" aria-label={initial ? "Edit schedule" : "New schedule"}>
      <header className="sheet-head">
        <div>
          <span className="eyebrow">{initial ? "Editing schedule" : "New schedule"}</span>
          <h2>{s.vendor ? vendorName(s.vendor) : "Untitled schedule"}</h2>
        </div>
        <button className="icon-btn" onClick={onCancel} aria-label="Close"><X size={18} /></button>
      </header>

      <div className="sheet-body">
        <div className="sheet-main">
          <FSec n="Who and what" hint="The recurring commitment this schedule represents">
            <Grid>
              <Field label="Vendor" req wide>
                <select value={s.vendor} onChange={(e) => set({ vendor: e.target.value })}>
                  <option value="">Select a vendor</option>
                  {VENDORS.map((v) => <option key={v.id} value={v.id}>{v.name} — {v.role}</option>)}
                </select>
              </Field>
              <Field label="Item category" req>
                <select value={s.itemCat} onChange={(e) => set({ itemCat: e.target.value })}>
                  <option value="">—</option>
                  {ITEM_CATS.map((i) => <option key={i.id} value={i.id}>{i.name}</option>)}
                </select>
              </Field>
              <Field label="Master category" note="Set by the category">
                <div className="derived">{byId(MASTER_CATS, byId(ITEM_CATS, s.itemCat)?.master)?.name ?? "—"}</div>
              </Field>
              <Field label="Location">
                <select value={s.location} onChange={(e) => set({ location: e.target.value })}>
                  <option value="">All locations</option>
                  {LOCATIONS.map((l) => <option key={l} value={l}>{l}</option>)}
                </select>
              </Field>
              <Field label="Villas" req>
                <select multiple value={s.villas} size={4}
                  onChange={(e) => set({ villas: [...e.target.selectedOptions].map((o) => o.value) })}>
                  {villaChoices.map((v) => <option key={v.id} value={v.id}>{v.name}{v.central ? " (cost centre)" : ""}</option>)}
                </select>
              </Field>
            </Grid>
          </FSec>

          <FSec n="Money and dates" hint="Instalments generate monthly from these">
            <Grid>
              <Field label="Amount per month" req>
                <input className="mono ta-r" type="number" value={s.amount}
                  onChange={(e) => setTerms({ amount: e.target.value })} placeholder="0" />
              </Field>
              <Field label="Payment type" req note="Whether a bill is raised too">
                <select value={s.paymentType} onChange={(e) => set({ paymentType: e.target.value })}>
                  {PAYMENT_TYPES.map((t) => <option key={t} value={t}>{t}</option>)}
                </select>
              </Field>
              <Field label="Paid from" req>
                <select value={s.bank} onChange={(e) => set({ bank: e.target.value })}>
                  <option value="">Select an account</option>
                  {BANKS.map((b) => <option key={b.id} value={b.id}>{b.name} — {b.kind}</option>)}
                </select>
              </Field>
              <Field label="Start date" req><input type="date" value={s.startDate} onChange={(e) => setTerms({ startDate: e.target.value })} /></Field>
              <Field label="End date" req><input type="date" value={s.endDate} onChange={(e) => setTerms({ endDate: e.target.value })} /></Field>
              <Field label="First due date" req note="Its day of month repeats monthly">
                <input type="date" value={s.dueDay} onChange={(e) => setTerms({ dueDay: e.target.value })} />
              </Field>
              <Field label="TDS">
                <select value={s.tds} onChange={(e) => set({ tds: e.target.value })}>
                  {TDS_RATES.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
                </select>
              </Field>
              <Field label="GST">
                <select value={s.gst} onChange={(e) => set({ gst: e.target.value })}>
                  {TAXES.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
                </select>
              </Field>
              <Field label="Chart of account">
                <select value={s.coa} onChange={(e) => set({ coa: e.target.value })}>
                  {COA.map((x) => <option key={x.id} value={x.id}>{x.name}</option>)}
                </select>
              </Field>
            </Grid>
            {years > 3 && (
              <div className="note note-warn">
                <AlertTriangle size={14} />
                <span>That's {years.toFixed(1)} years — {live.length} instalments generated up front. A shorter run you renew is easier to keep accurate.</span>
              </div>
            )}
          </FSec>

          <FSec n="Instalments" hint={`Generated monthly from the dates above${live.length ? ` · ${live.length} instalments · ${inr(committed, { paise: false })} committed` : ""}`}>
            {orphans.length > 0 && (
              <div className="note note-warn">
                <AlertTriangle size={14} />
                <span>{orphans.length === 1 ? "One instalment falls" : `${orphans.length} instalments fall`} outside the new dates but {orphans.length === 1 ? "carries an amount or is paid" : "carry amounts or are paid"} — marked below. Widen the dates or clear them.</span>
              </div>
            )}
            {s.instalments.length === 0 ? (
              <div className="empty-inline"><p>Set the amount, start, end and first due date — instalments appear here.</p></div>
            ) : (
              <table className="edit-table">
                <thead>
                  <tr><th>Due date</th><th>Cycle</th><th className="num w-amt">Amount</th>
                    {isPayroll && <><th className="num w-sm">Days</th><th className="num w-amt">Advance</th><th className="num w-amt">Penalty</th></>}
                    <th>Status</th><th /></tr>
                </thead>
                <tbody>
                  {s.instalments.map((i, idx) => (
                    <tr key={i.id} className={i.orphan ? "is-orphan" : ""}>
                      <td><input type="date" value={i.dueDate}
                        onChange={(e) => set({ instalments: s.instalments.map((x, n) => (n === idx ? { ...x, dueDate: e.target.value, cycle: billedCycle(e.target.value) } : x)) })} /></td>
                      <td className="mono dim nowrap">{i.cycle}</td>
                      <td><input className="mono ta-r" type="number" value={i.amount}
                        onChange={(e) => set({ instalments: s.instalments.map((x, n) => (n === idx ? { ...x, amount: e.target.value } : x)) })} /></td>
                      {isPayroll && <>
                        <td><input className="mono ta-r" type="number" value={i.daysWorked ?? ""} placeholder={String(billedMonthDays(i.dueDate))}
                          onChange={(e) => set({ instalments: s.instalments.map((x, n) => (n === idx ? { ...x, daysWorked: e.target.value } : x)) })} /></td>
                        <td><input className="mono ta-r" type="number" value={i.advance ?? ""}
                          onChange={(e) => set({ instalments: s.instalments.map((x, n) => (n === idx ? { ...x, advance: e.target.value } : x)) })} /></td>
                        <td><input className="mono ta-r" type="number" value={i.penalty ?? ""}
                          onChange={(e) => set({ instalments: s.instalments.map((x, n) => (n === idx ? { ...x, penalty: e.target.value } : x)) })} /></td>
                      </>}
                      <td><Badge tone={INST_TONE[i.status]}>{i.status}</Badge></td>
                      <td className="pad-r">
                        <button className="icon-btn sm" disabled={i.status === "Paid"} aria-label="Remove instalment"
                          title={i.status === "Paid" ? "Paid instalments can't be removed" : "Remove"}
                          onClick={() => set({ instalments: s.instalments.filter((x) => x.id !== i.id) })}>
                          {i.status === "Paid" ? <Lock size={12} /> : <Trash2 size={13} />}
                        </button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
          </FSec>
        </div>

        <aside className="sheet-side">
          <div className="side-card">
            <h4>Commitment</h4>
            <div className="alloc-figure mono">{inr(committed, { paise: false })}
              <span>{live.length} instalments{years ? ` over ${years.toFixed(1)} years` : ""}</span>
            </div>
          </div>
          <div className="side-card">
            <h4>Generation</h4>
            <ul className="notelist">
              <li>Instalments repeat on day {s.dueDay ? new Date(s.dueDay + "T00:00:00").getDate() : "—"} of each month.</li>
              <li>A day past the month's length falls back to its last day.</li>
              <li>Each instalment bills the month <b>before</b> its due date.</li>
              <li>Changing the dates keeps amounts you've already typed.</li>
            </ul>
          </div>
          {touched && problems.length > 0 && (
            <div className="side-card is-warn">
              <h4>Before saving</h4>
              <ul className="problems">{problems.map((p) => <li key={p}>{p}</li>)}</ul>
            </div>
          )}
        </aside>
      </div>

      <footer className="sheet-foot">
        <div className="foot-state">
          {live.length > 0
            ? <><Clock size={15} />{live.length} instalments · {inr(committed, { paise: false })} committed</>
            : <><AlertTriangle size={15} />No instalments yet</>}
        </div>
        <div className="foot-actions">
          <button className="btn btn-ghost" onClick={onCancel}>Cancel</button>
          <button className="btn btn-primary" onClick={submit}>{initial ? "Save changes" : "Create schedule"}</button>
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
.nowrap { white-space: nowrap; }
.truncate { max-width: 190px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
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
.topbar { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; margin-bottom: 16px; }
.topbar h1 { margin: 0; font-size: 24px; font-weight: 600; letter-spacing: -.02em; }
.sub { margin: 3px 0 0; color: var(--ink-3); font-size: 13px; }
.who { text-align: right; line-height: 1.35; }
.who-name { display: block; font-weight: 500; font-size: 13px; }
.who-role { display: block; font-size: 11px; color: var(--ink-4); }

.tabs { display: flex; gap: 2px; border-bottom: 1px solid var(--rule-2); margin-bottom: 18px; }
.tab { font: inherit; font-size: 13.5px; display: inline-flex; align-items: center; gap: 7px; padding: 9px 14px; background: none; border: 0; border-bottom: 2px solid transparent; color: var(--ink-3); cursor: pointer; margin-bottom: -1px; }
.tab:hover { color: var(--ink); }
.tab.is-on { color: var(--indigo); border-bottom-color: var(--indigo); font-weight: 500; }

.strip { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px,1fr)); gap: 1px; background: var(--rule); border: 1px solid var(--rule); border-radius: var(--r); overflow: hidden; margin-bottom: 16px; }
.metric { background: var(--paper); padding: 12px 14px; }
.metric-label { display: block; font-size: 10.5px; text-transform: uppercase; letter-spacing: .07em; color: var(--ink-4); margin-bottom: 5px; }
.metric-value { font-size: 19px; font-weight: 600; }

.toolbar { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 12px; }
.search { display: flex; align-items: center; gap: 7px; background: var(--paper); border: 1px solid var(--rule-2); border-radius: var(--r); padding: 0 9px; height: 34px; min-width: 250px; color: var(--ink-4); }
.search input { border: 0; outline: 0; font: inherit; background: none; flex: 1; color: var(--ink); }
.clear { border: 0; background: none; color: var(--ink-4); cursor: pointer; display: grid; place-items: center; }
.chips { display: flex; gap: 4px; flex-wrap: wrap; }
.chip { font: inherit; font-size: 12px; padding: 5px 10px; border-radius: 99px; border: 1px solid var(--rule-2); background: var(--paper); color: var(--ink-2); cursor: pointer; }
.chip:hover { border-color: var(--ink-4); }
.chip.is-on { background: var(--indigo); border-color: var(--indigo); color: #fff; }

.btn { font: inherit; font-size: 13px; display: inline-flex; align-items: center; gap: 6px; height: 34px; padding: 0 13px; border-radius: var(--r); border: 1px solid transparent; cursor: pointer; white-space: nowrap; }
.btn:disabled { opacity: .42; cursor: not-allowed; }
.btn.sm { height: 26px; font-size: 11.5px; padding: 0 8px; gap: 4px; }
.btn-primary { background: var(--indigo); color: #fff; font-weight: 500; }
.btn-primary:hover:not(:disabled) { background: var(--indigo-2); }
.btn-ghost { background: var(--paper); border-color: var(--rule-2); color: var(--ink-2); }
.btn-ghost:hover:not(:disabled) { border-color: var(--ink-4); color: var(--ink); }
.btn-ghost.danger { color: var(--red); }
.icon-btn { border: 1px solid var(--rule-2); background: var(--paper); border-radius: var(--r); width: 30px; height: 30px; display: grid; place-items: center; cursor: pointer; color: var(--ink-2); }
.icon-btn:disabled { opacity: .35; cursor: not-allowed; }
.icon-btn:hover:not(:disabled) { border-color: var(--ink-4); }
.icon-btn.sm { width: 25px; height: 25px; border-color: transparent; background: none; color: var(--ink-4); }
.icon-btn.sm:hover:not(:disabled) { color: var(--red); background: var(--red-soft); }
.linklike { font: inherit; font-size: 12px; background: none; border: 0; padding: 0; color: var(--indigo); cursor: pointer; text-align: left; border-bottom: 1px solid transparent; }
.linklike:hover { border-bottom-color: var(--indigo); }

.bulkbar { display: flex; justify-content: space-between; align-items: center; gap: 14px; padding: 9px 14px; background: var(--indigo); color: #fff; border-radius: var(--r); margin-bottom: 12px; font-size: 13px; }
.bulkbar b { font-weight: 600; }
.bulk-actions { display: flex; gap: 7px; align-items: center; }

.table-wrap { background: var(--paper); border: 1px solid var(--rule); border-radius: var(--r); overflow-x: auto; }
.grid { width: 100%; border-collapse: collapse; font-size: 13px; }
.grid thead th { text-align: left; font-weight: 500; font-size: 10.5px; text-transform: uppercase; letter-spacing: .07em; color: var(--ink-4); padding: 9px 10px; border-bottom: 1px solid var(--rule-2); background: var(--paper-2); white-space: nowrap; position: sticky; top: 0; z-index: 1; }
.grid thead th.num { text-align: right; }
.w-check { width: 34px; padding-left: 12px !important; }
.w-check input { accent-color: var(--indigo); }
.th-btn { font: inherit; letter-spacing: inherit; text-transform: inherit; color: inherit; background: none; border: 0; cursor: pointer; display: inline-flex; align-items: center; gap: 4px; padding: 0; }
.th-btn svg { opacity: .4; } .th-btn.is-on { color: var(--indigo); } .th-btn.is-on svg { opacity: 1; }
.grid tbody td { padding: 9px 10px; border-bottom: 1px solid var(--rule); vertical-align: middle; }
.grid tbody tr:hover:not(.grouprow) { background: var(--paper-2); }
.grid tbody tr.is-picked { background: var(--indigo-soft); }
.grid tbody tr.is-open { background: var(--indigo-soft); box-shadow: inset 3px 0 0 var(--indigo); }
.grouprow td { background: var(--paper-3); border-bottom: 1px solid var(--rule-2); padding: 7px 10px; }
.grouprow.is-overdue td { background: var(--red-soft); }
.gdate { display: inline-flex; align-items: center; gap: 6px; font-weight: 600; font-size: 12.5px; margin-right: 10px; }
.gmeta { font-size: 11.5px; color: var(--ink-3); margin-left: 10px; }
.stack { display: grid; }
.stack em { font-style: normal; font-size: 11px; color: var(--ink-4); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.rowacts { display: flex; gap: 5px; justify-content: flex-end; }
.warn-dot { color: var(--amber); display: inline-grid; place-items: center; }
.count { margin: 9px 0 0; font-size: 12px; color: var(--ink-4); }
.empty-block { padding: 44px; text-align: center; background: var(--paper); border: 1px solid var(--rule); border-radius: var(--r); color: var(--ink-3); }
.empty-block p { margin: 0; }

.prog { display: flex; align-items: center; gap: 7px; }
.prog-bar { width: 62px; height: 5px; border-radius: 99px; background: var(--paper-3); overflow: hidden; }
.prog-fill { height: 100%; background: var(--verd); border-radius: 99px; }

.badge { display: inline-block; font-size: 11px; font-weight: 500; padding: 3px 8px; border-radius: 99px; border: 1px solid; white-space: nowrap; }
.badge.tone-neutral { background: var(--paper-3); border-color: var(--rule-2); color: var(--ink-2); }
.badge.tone-info { background: var(--indigo-soft); border-color: #c2cbea; color: var(--indigo); }
.badge.tone-warn { background: var(--amber-soft); border-color: var(--amber-line); color: var(--amber); }
.badge.tone-good { background: var(--verd-soft); border-color: var(--verd-line); color: var(--verd); }
.badge.tone-danger { background: var(--red-soft); border-color: var(--red-line); color: var(--red); }

.drawer { position: fixed; top: 0; right: 0; bottom: 0; width: min(580px, 95vw); background: var(--paper); border-left: 1px solid var(--rule-2); box-shadow: -18px 0 44px rgba(21,25,34,.13); display: flex; flex-direction: column; z-index: 40; }
.drawer-head { display: flex; justify-content: space-between; align-items: center; gap: 10px; padding: 11px 14px; border-bottom: 1px solid var(--rule); background: var(--paper-2); }
.drawer-actions { display: flex; gap: 6px; align-items: center; }
.drawer-body { overflow-y: auto; padding: 18px 20px 34px; }
.dh { margin-bottom: 16px; }
.eyebrow { font-size: 11px; letter-spacing: .08em; text-transform: uppercase; color: var(--ink-4); }
.dh h2 { margin: 4px 0 7px; font-size: 19px; font-weight: 600; letter-spacing: -.01em; line-height: 1.3; }
.dh-figure { font-size: 25px; font-weight: 600; letter-spacing: -.02em; }
.dh-figure span { font-size: 11.5px; font-weight: 400; color: var(--ink-3); margin-left: 6px; letter-spacing: 0; }
.dh-meta { display: flex; align-items: center; gap: 9px; flex-wrap: wrap; margin-top: 9px; }

.note { display: flex; gap: 8px; align-items: flex-start; font-size: 12.5px; padding: 9px 11px; border-radius: var(--r); border: 1px solid; margin-bottom: 14px; line-height: 1.45; }
.note svg { flex: none; margin-top: 1px; }
.note-warn { background: var(--amber-soft); border-color: var(--amber-line); color: var(--amber); }

.dsec { margin-bottom: 22px; }
.dsec h3 { margin: 0 0 9px; font-size: 11px; text-transform: uppercase; letter-spacing: .08em; color: var(--ink-4); font-weight: 600; }
.ledger { border: 1px solid var(--rule); border-radius: var(--r); overflow: hidden; }
.lrow { display: flex; justify-content: space-between; gap: 14px; padding: 8px 12px; font-size: 13px; background: var(--paper); }
.lrow + .lrow { border-top: 1px solid var(--rule); }
.lrow.is-muted { color: var(--ink-3); }
.lrow.has-rule { background: var(--paper-2); font-weight: 500; }
.lrow.is-total { background: var(--slate); color: #fff; font-weight: 600; font-size: 14px; border-top: 0; }
.sign { font-style: normal; color: var(--ink-4); margin-right: 3px; }
.lrow.is-total .sign { color: #8e98ad; }

.facts { display: grid; grid-template-columns: 142px 1fr; margin: 0; border: 1px solid var(--rule); border-radius: var(--r); overflow: hidden; font-size: 13px; }
.facts dt { padding: 8px 12px; color: var(--ink-3); background: var(--paper-2); border-bottom: 1px solid var(--rule); }
.facts dd { margin: 0; padding: 8px 12px; border-bottom: 1px solid var(--rule); word-break: break-word; }
.facts dt:last-of-type, .facts dd:last-of-type { border-bottom: 0; }

.mini { width: 100%; border-collapse: collapse; font-size: 12.5px; border: 1px solid var(--rule); border-radius: var(--r); overflow: hidden; }
.mini th { text-align: left; font-weight: 500; font-size: 10px; text-transform: uppercase; letter-spacing: .06em; color: var(--ink-4); padding: 7px 10px; background: var(--paper-2); border-bottom: 1px solid var(--rule); }
.mini th.num { text-align: right; }
.mini td { padding: 7px 10px; border-bottom: 1px solid var(--rule); }
.mini tr:last-child td { border-bottom: 0; }
.mini tr.is-late td:first-child { box-shadow: inset 3px 0 0 var(--red); }
.record-id { color: var(--ink-4); font-size: 11px; margin: 26px 0 0; }

.modal-back { position: fixed; inset: 0; background: rgba(21,25,34,.45); display: grid; place-items: center; z-index: 80; padding: 20px; }
.modal { background: var(--paper); border-radius: 10px; width: min(460px, 100%); box-shadow: 0 24px 60px rgba(21,25,34,.28); }
.modal.is-wide { width: min(880px, 100%); max-height: 92vh; display: flex; flex-direction: column; }
.modal-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 14px; padding: 16px 20px; border-bottom: 1px solid var(--rule); }
.modal-head h3 { margin: 3px 0 0; font-size: 17px; font-weight: 600; }
.modal-body { padding: 18px 20px; overflow-y: auto; display: grid; grid-template-columns: minmax(0,1fr) 262px; gap: 18px; align-items: start; }
.modal-body > div:first-child { display: grid; gap: 14px; }
.modal-side { display: grid; gap: 12px; }
.modal-foot { display: flex; justify-content: flex-end; gap: 8px; padding: 13px 20px; border-top: 1px solid var(--rule); }

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
.field input, .field select, .field textarea, .edit-table input, .edit-table select, .modal textarea, .fsec textarea {
  font: inherit; font-size: 13px; padding: 0 9px; border: 1px solid var(--rule-2); border-radius: 5px;
  background: var(--paper); color: var(--ink); width: 100%; min-width: 0; height: 33px;
}
.field textarea, .modal textarea, .fsec textarea { height: auto; padding: 8px 9px; line-height: 1.45; resize: vertical; }
.field select[multiple] { height: auto; padding: 4px; }
.field select, .edit-table select { cursor: pointer; }
.field input:disabled, .field select:disabled, .edit-table input:disabled { background: var(--paper-3); color: var(--ink-3); cursor: not-allowed; }
.field input:focus, .field select:focus, .field textarea:focus, .edit-table input:focus, .fsec textarea:focus { border-color: var(--indigo-2); }
.derived { font-size: 13px; height: 33px; display: flex; align-items: center; justify-content: flex-end; padding: 0 9px; background: var(--paper-3); border: 1px dashed var(--rule-2); border-radius: 5px; color: var(--ink-2); }
.is-bad { border-color: var(--red) !important; background: var(--red-soft) !important; }

.edit-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.edit-table th { text-align: left; font-size: 10.5px; font-weight: 500; text-transform: uppercase; letter-spacing: .06em; color: var(--ink-4); padding: 0 6px 6px; }
.edit-table th.num { text-align: right; }
.edit-table td { padding: 3px 6px; border-bottom: 1px solid var(--paper-3); }
.edit-table tr:last-child td { border-bottom: 0; }
.edit-table tr.is-orphan td { background: var(--amber-soft); }
.edit-table tr.is-orphan td:first-child { box-shadow: inset 3px 0 0 var(--amber); }
.w-amt { width: 104px; } .w-sm { width: 68px; }
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
  .sheet-body, .modal-body { grid-template-columns: minmax(0,1fr); }
  .sheet-side, .modal-side { position: static; }
  .fgrid { grid-template-columns: repeat(2, minmax(0,1fr)); }
}
@media (max-width: 720px) {
  .app { grid-template-columns: 1fr; }
  .rail { flex-direction: row; height: auto; overflow-x: auto; position: static; padding: 8px; }
  .rail-mark { margin: 0 6px 0 0; }
  .fgrid { grid-template-columns: 1fr; }
  .field.is-wide { grid-column: span 1; }
  .drawer { width: 100vw; }
  .bulkbar { flex-direction: column; align-items: flex-start; }
}
@media (prefers-reduced-motion: reduce) { * { transition: none !important; } }
`}</style>
  );
}
