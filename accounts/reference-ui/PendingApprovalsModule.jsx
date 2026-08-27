import React, { useState, useMemo, useRef, useEffect } from "react";

/* ═══════════════════════════════════════════════════════════════════════════
   Pending Approvals — replica of the All Pending Approvals report, its detail
   panel and its form, built from screenshots 13-Aug-2026.

   Structure taken verbatim:
     · 24 columns, with three per-row action buttons — Approve, Reject, Pay —
       and a Link column, all sitting between Payment Date and Payment Status
     · Payment Status renders as a SOLID GREEN FILLED CELL, not a chip. This is
       Creator conditional formatting on the report, the same mechanism seen on
       All Payments
     · Action buttons render disabled once the record is Approved / Paid
     · Gross Amount prints at THREE decimals (₹ 4,272.410) beside a two-decimal
       Payable Amount — the same quirk as the Payments split grid
     · Footer reads `Showing 1000 of ###`: the report pages at 1000 and the true
       total overflows the footer, so Creator prints ### in pink
     · Rows are content-height, driven by Message ID wrapping
     · Form title band reads `Pending Approvals`, not `All Pending Approvals`
     · `Approval Level` is a free-text input, not a picklist
     · `Approved By` is a section heading over a subform: Approver / Approval
       Level / Approved, with `+ Add New`

   Two contradictions visible in the live data, replicated rather than tidied:
     · Every record on a report called "Pending Approvals" has Status = Approved
       and Payment Status = Paid. Nothing clears the queue
     · The `Approved` checkbox inside the Approved By grid is UNCHECKED while
       Status says Approved and Approved By names the approver — a seventh
       disagreeing representation of approval state on top of the six in §8.4
   ═══════════════════════════════════════════════════════════════════════════ */

const uid = () => Math.random().toString(36).slice(2, 9);

const group = (i) => {
  let last3 = i.slice(-3), rest = i.slice(0, -3);
  if (rest) last3 = "," + last3;
  return rest.replace(/\B(?=(\d{2})+(?!\d))/g, ",") + last3;
};
const money = (n, dp = 2) => {
  if (n === "" || n === null || n === undefined || Number.isNaN(+n)) return "";
  const [i, d] = Math.abs(+n).toFixed(dp).split(".");
  return `${+n < 0 ? "-" : ""}₹ ${group(i)}.${d}`;
};
/** Gross Amount prints at three decimals in Creator. Replicated. */
const money3 = (n) => money(n, 3);
const MA = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];
const dmy = (iso) => { if (!iso) return ""; const [y, m, d] = iso.split("-"); return `${d}-${MA[+m - 1]}-${y}`; };
const parseDmy = (s) => {
  const m = /^(\d{1,2})-([A-Za-z]{3})-(\d{4})$/.exec((s || "").trim());
  if (!m) return "";
  const mi = MA.findIndex((x) => x.toLowerCase() === m[2].toLowerCase());
  return mi < 0 ? "" : `${m[3]}-${String(mi + 1).padStart(2, "0")}-${String(m[1]).padStart(2, "0")}`;
};
const stampToSort = (s) => {
  const m = /^(\d{2})-([A-Za-z]{3})-(\d{4})\s+(\d{2}):(\d{2}):(\d{2})$/.exec(s || "");
  return m ? +`${m[3]}${String(MA.indexOf(m[2]) + 1).padStart(2, "0")}${m[1]}${m[4]}${m[5]}${m[6]}` : 0;
};

const APPROVERS = ["Komal Takale", "Zeeshan Khan", "Husain Khatumdi", "Priya Nair", "Rohan Deshpande"];
const STATUSES = ["Draft", "Submit for Approval", "Sent for Approval", "Send for Approval",
  "Approved", "Approval Rejected", "Approval Not Required", "Paid"];
const PAYMENT_STATUSES = ["Pending", "Open", "Paid", "paid", "Cancelled", "Reverse"];
const APPROVAL_TYPES = ["Any", "All"];
const LEVELS = ["Level 1", "Level 2", "Level 3"];

/* ── seed ────────────────────────────────────────────────────────────────
   Rows 1-10 are verbatim from the screenshots: the META ADS / FACEBOOK batch
   EKS/PY/20954-20962 plus EKS/PY/20904. Every one is already Approved and Paid,
   which is what the live report shows. Rows 11-16 are synthetic genuinely-pending
   records, so the enabled state of Approve / Reject / Pay is visible too. */

const META = (no, n, msgId, payDate) => ({
  id: `2924820000106${String(70000 + n * 6).padStart(5, "0")}`,
  addedTime: `13-Aug-2026 14:${String(21 - Math.floor(n / 3)).padStart(2, "0")}:${String(4 + n * 5).padStart(2, "0")}`,
  paymentDate: payDate, link: "",
  paymentStatus: "Paid", payableAmount: 4956, grossAmount: 4272.41,
  location: "Head Office Central", itemCategory: "META ADS", bankName: "EKOSTAY LLP 2",
  vendorName: "FACEBOOK", villaName: "Head Office Central", paymentNo: no,
  masterCategory: "Advertisements", status: "Approved", coa: "Accounts Payable",
  billingCycles: "August - 2026", approvalLevel: "Level 1", nextLevelApprovalRequired: true,
  approvalType: "Any", approvedBy: "Komal Takale", messageId: msgId,
  approvers: ["Komal Takale"], preferredApprover: "",
  /* the grid's Approved box is unchecked on live records — see the header note */
  approvedByRows: [{ id: uid(), approver: "Komal Takale", approvalLevel: "Level 1", approved: false }],
});

const SEED = [
  META("EKS/PY/20954", 0, "675bced9-b523-4c97-823f-f9e7135b7d93", "2026-08-03"),
  META("EKS/PY/20955", 1, "8c43d0be-7b7b-48d6-9f9f-e4740708d76d", "2026-08-03"),
  META("EKS/PY/20956", 2, "3ec9114d-ed2b-41ff-adcb-e914476557ca", "2026-08-03"),
  META("EKS/PY/20957", 3, "25aa7c80-5901-489a-ac62-ef25bd0938ef", "2026-08-03"),
  META("EKS/PY/20958", 4, "9aa8fe0d-9c69-4ce1-8ca7-f8becfd539a8", "2026-08-03"),
  META("EKS/PY/20959", 5, "2cfe509c-4f3a-4b61-b504-6910df5edf64", "2026-08-03"),
  META("EKS/PY/20960", 6, "dae35b2b-30f9-46a8-a0f0-afa4057f22cd", "2026-08-03"),
  META("EKS/PY/20961", 7, "a8b65f84-616b-40e6-8ce5-10650d3b2295", "2026-08-03"),
  META("EKS/PY/20962", 8, "09aa0846-8f34-4f31-8cff-678a009013f5", "2026-08-04"),
  {
    id: "292482000010628004", addedTime: "12-Aug-2026 11:04:19", paymentDate: "2026-08-02", link: "",
    paymentStatus: "Paid", payableAmount: 268000, grossAmount: 268000,
    location: "Mumbai", itemCategory: "OWNER REVENUE", bankName: "EKOSTAY LLP 1",
    vendorName: "DHVAJ VIJENDRA BALDOTA(ONYX)", villaName: "ONYX Villa", paymentNo: "EKS/PY/20904",
    masterCategory: "Finance & Legal", status: "Approved", coa: "Accounts Payable",
    billingCycles: "July - 2026", approvalLevel: "Level 2", nextLevelApprovalRequired: true,
    approvalType: "Any", approvedBy: "Zeeshan Khan",
    messageId: "b41f77a2-19cc-4c0e-9a3f-2d5e0a7714bb",
    approvers: ["Zeeshan Khan"], preferredApprover: "",
    approvedByRows: [{ id: uid(), approver: "Zeeshan Khan", approvalLevel: "Level 2", approved: false }],
  },
  /* ── synthetic from here: genuinely pending, so the buttons render enabled ── */
  {
    id: "292482000010631002", addedTime: "13-Aug-2026 09:12:40", paymentDate: "", link: "",
    paymentStatus: "Pending", payableAmount: 47710, grossAmount: 47710,
    location: "Lonavala, Karjat", itemCategory: "ELECTRICITY BILL", bankName: "EKOSTAY LLP 1",
    vendorName: "MAHAVITRAN", villaName: "Marina Villa, Skyfall Dew Drops", paymentNo: "EKS/PY/20963",
    masterCategory: "Operations & Logistics", status: "Sent for Approval", coa: "Accounts Payable",
    billingCycles: "July - 2026", approvalLevel: "Level 1", nextLevelApprovalRequired: true,
    approvalType: "Any", approvedBy: "", messageId: "f0c1a934-2b77-4e51-9c02-71ab4e88d1aa",
    approvers: ["Priya Nair"], preferredApprover: "Priya Nair",
    approvedByRows: [{ id: uid(), approver: "Priya Nair", approvalLevel: "Level 1", approved: false }],
  },
  {
    id: "292482000010631006", addedTime: "13-Aug-2026 09:44:02", paymentDate: "", link: "",
    paymentStatus: "Pending", payableAmount: 195000, grossAmount: 195000,
    location: "Mumbai", itemCategory: "OWNER REVENUE", bankName: "EKOSTAY LLP 1",
    vendorName: "DARSHIT MAHENDRA GUNDESHA (ONYX)", villaName: "ONYX Villa", paymentNo: "EKS/PY/20964",
    masterCategory: "Finance & Legal", status: "Sent for Approval", coa: "Accounts Payable",
    billingCycles: "August - 2026", approvalLevel: "Level 2", nextLevelApprovalRequired: true,
    approvalType: "All", approvedBy: "", messageId: "5b2d8e07-cc41-4a9d-b6e8-3f9017c2ad55",
    approvers: ["Zeeshan Khan", "Husain Khatumdi"], preferredApprover: "",
    approvedByRows: [
      { id: uid(), approver: "Zeeshan Khan", approvalLevel: "Level 2", approved: true },
      { id: uid(), approver: "Husain Khatumdi", approvalLevel: "Level 2", approved: false },
    ],
  },
  {
    id: "292482000010631010", addedTime: "13-Aug-2026 10:02:55", paymentDate: "", link: "",
    paymentStatus: "Pending", payableAmount: 33800, grossAmount: 33800,
    location: "Alibaug", itemCategory: "PLUMBER WORKS, CARPENTRY", bankName: "EKOSTAY LLP 1",
    vendorName: "Konkan Plumbing Works", villaName: "Black Mirror Villa", paymentNo: "EKS/PY/20965",
    masterCategory: "Property Repair & Maintenance", status: "Submit for Approval", coa: "Expense",
    billingCycles: "June - 2026", approvalLevel: "Level 1", nextLevelApprovalRequired: false,
    approvalType: "Any", approvedBy: "", messageId: "",
    approvers: ["Rohan Deshpande"], preferredApprover: "",
    approvedByRows: [{ id: uid(), approver: "Rohan Deshpande", approvalLevel: "Level 1", approved: false }],
  },
  {
    id: "292482000010631014", addedTime: "13-Aug-2026 10:31:18", paymentDate: "", link: "",
    paymentStatus: "Pending", payableAmount: 218400, grossAmount: 218400,
    location: "Lonavala, Alibaug", itemCategory: "STAFF SALARY", bankName: "EKOSTAY LLP 1",
    vendorName: "Shree Laxmi Laundry Services", villaName: "Marina Villa, Black Mirror Villa, Casa Vayu",
    paymentNo: "EKS/PY/20966", masterCategory: "Employee & Staff Considerations",
    status: "Sent for Approval", coa: "Accounts Payable",
    billingCycles: "June - 2026", approvalLevel: "Level 3", nextLevelApprovalRequired: false,
    approvalType: "All", approvedBy: "", messageId: "7ce4a1b9-88d3-4f27-a5e1-0c62b9e4d370",
    approvers: ["Husain Khatumdi"], preferredApprover: "Husain Khatumdi",
    approvedByRows: [{ id: uid(), approver: "Husain Khatumdi", approvalLevel: "Level 3", approved: false }],
  },
  {
    id: "292482000010631018", addedTime: "13-Aug-2026 11:08:47", paymentDate: "", link: "",
    paymentStatus: "Pending", payableAmount: 5510, grossAmount: 5510,
    location: "Ooty And Coonoor", itemCategory: "STAFF FUEL", bankName: "Petty Cash Ooty",
    vendorName: "MAJU AND COMPANY", villaName: "Woodside Ivy Villa, Ooty Central",
    paymentNo: "EKS/PY/20967", masterCategory: "Operations & Logistics",
    status: "Submit for Approval", coa: "Expense",
    billingCycles: "June - 2026", approvalLevel: "Level 1", nextLevelApprovalRequired: false,
    approvalType: "Any", approvedBy: "", messageId: "",
    approvers: ["Priya Nair"], preferredApprover: "",
    approvedByRows: [{ id: uid(), approver: "Priya Nair", approvalLevel: "Level 1", approved: false }],
  },
  {
    id: "292482000010631022", addedTime: "13-Aug-2026 11:52:03", paymentDate: "", link: "",
    paymentStatus: "Pending", payableAmount: 90120, grossAmount: 76372.881,
    location: "Goa", itemCategory: "CARPENTRY", bankName: "EKOSTAY LLP 2",
    vendorName: "SHANTADURGA TRADERS", villaName: "Ezra Villa- Anjuna", paymentNo: "EKS/PY/20968",
    masterCategory: "Property Repair & Maintenance", status: "Sent for Approval", coa: "Expense",
    billingCycles: "June - 2026", approvalLevel: "Level 2", nextLevelApprovalRequired: true,
    approvalType: "Any", approvedBy: "", messageId: "a90b3fe1-5d47-42c8-91ab-6e0f7c3d8412",
    approvers: ["Zeeshan Khan"], preferredApprover: "",
    approvedByRows: [{ id: uid(), approver: "Zeeshan Khan", approvalLevel: "Level 2", approved: false }],
  },
];

/* Column order verbatim from the report. Approve / Reject / Link / Pay sit
   between Payment Date and Payable Amount. */
const COLUMNS = [
  { k: "addedTime", label: "Added Time", w: 172, sort: true },
  { k: "paymentDate", label: "Payment Date", w: 132, type: "date", sort: true },
  { k: "_approve", label: "Approve", w: 118, type: "btn" },
  { k: "_reject", label: "Reject", w: 108, type: "btn" },
  { k: "link", label: "Link", w: 300, sort: true },
  { k: "paymentStatus", label: "Payment Status", w: 150, type: "fill", sort: true },
  { k: "_pay", label: "Pay", w: 96, type: "btn" },
  { k: "payableAmount", label: "Payable Amount", w: 150, type: "money", sort: true },
  { k: "location", label: "Location", w: 180, sort: true },
  { k: "grossAmount", label: "Gross Amount", w: 150, type: "money3", sort: true },
  { k: "itemCategory", label: "Item Category", w: 220, sort: true },
  { k: "bankName", label: "Bank Name", w: 210, sort: true },
  { k: "vendorName", label: "Vendor Name", w: 260, sort: true },
  { k: "villaName", label: "Villa Name", w: 220, sort: true },
  { k: "paymentNo", label: "Payment No", w: 160, sort: true },
  { k: "masterCategory", label: "Master Category", w: 210, sort: true },
  { k: "status", label: "Status", w: 150, sort: true },
  { k: "coa", label: "COA", w: 170, sort: true },
  { k: "billingCycles", label: "Billing Cycles", w: 150, sort: true },
  { k: "approvalLevel", label: "Approval Level", w: 140, sort: true },
  { k: "nextLevelApprovalRequired", label: "Next Level Approval Required?", w: 210, type: "bool", sort: true },
  { k: "approvalType", label: "Approval Type", w: 140, sort: true },
  { k: "approvedBy", label: "Approved By", w: 170, sort: true },
  { k: "messageId", label: "Message ID", w: 250, sort: true },
];

/* Detail field order verbatim from the panel. */
const DETAIL = ["paymentNo", "status", "approvalLevel", "nextLevelApprovalRequired",
  "approvalType", "approvedBy", "approvers", "preferredApprover", "itemCategory"];

/* Form field order verbatim, Approvers first and Approval Level as free text. */
const FORM = [
  { k: "approvers", label: "Approvers", type: "multi", options: APPROVERS },
  { k: "preferredApprover", label: "Preferred Approver", type: "lookup", options: APPROVERS },
  { k: "paymentNo", label: "Payment No", type: "lookup", options: SEED.map((r) => r.paymentNo) },
  { k: "status", label: "Status", type: "lookup", options: STATUSES },
  { k: "approvalLevel", label: "Approval Level" },
  { k: "nextLevelApprovalRequired", label: "Next Level Approval Required?", type: "bool" },
  { k: "approvalType", label: "Approval Type", type: "lookup", options: APPROVAL_TYPES },
];

const NAV = [
  ["Accounts", "calc"], ["Payments", "bank"], ["Bank", "bank2"], ["Bills", "bill"],
  ["Expenses", "exp"], ["Schedule Payments", "sched"], ["Expense Observations", "obs"],
  ["Masters", "mast"], ["Settings", "gear"], ["Backend Expenses", "exp"],
  ["Pending Approvals", "hourglass"],
  /* both of these appear on the live rail and are in no prior note */
  ["App Preferences", "box"], ["Payment Requests", "receipt"],
];

const SEARCH_FIELDS = ["Payment No", "Vendor Name", "Status", "Payment Status", "Approved By", "Approval Level"];

/* ══ list ═══════════════════════════════════════════════════════════════ */

export default function PendingApprovalsModule() {
  const [rows, setRows] = useState(SEED);
  const [openId, setOpenId] = useState(null);
  const [editing, setEditing] = useState(null);
  const [showSearch, setShowSearch] = useState(false);
  const [search, setSearch] = useState({ field: "Payment No", value: "" });
  const [checked, setChecked] = useState(new Set());
  const [sort, setSort] = useState({ key: "addedTime", dir: "desc" });
  const [toast, setToast] = useState("");

  const flash = (m) => { setToast(m); setTimeout(() => setToast(""), 2400); };

  const cellText = (r, col) => {
    const v = r[col.k];
    if (col.type === "date") return dmy(v);
    if (col.type === "bool") return v === true ? "true" : v === false ? "false" : "";
    if (col.type === "money") return money(v);
    if (col.type === "money3") return money3(v);
    return v ?? "";
  };

  const view = useMemo(() => {
    let r = rows;
    if (search.value.trim()) {
      const q = search.value.toLowerCase();
      const map = { "Payment No": "paymentNo", "Vendor Name": "vendorName", Status: "status",
        "Payment Status": "paymentStatus", "Approved By": "approvedBy", "Approval Level": "approvalLevel" };
      const k = map[search.field];
      r = r.filter((x) => String(x[k] ?? "").toLowerCase().includes(q));
    }
    const dir = sort.dir === "asc" ? 1 : -1;
    return [...r].sort((a, b) => {
      if (sort.key === "addedTime") return (stampToSort(a.addedTime) - stampToSort(b.addedTime)) * dir;
      const col = COLUMNS.find((c) => c.k === sort.key);
      if (col && (col.type === "money" || col.type === "money3")) return ((+a[sort.key] || 0) - (+b[sort.key] || 0)) * dir;
      return String(a[sort.key] ?? "").localeCompare(String(b[sort.key] ?? "")) * dir;
    });
  }, [rows, search, sort]);

  /* Approve / Reject / Pay are live only while the record is still open. */
  const actionable = (r) => r.status !== "Approved" && r.status !== "Approval Rejected" && r.paymentStatus !== "Paid";
  const payable = (r) => r.status === "Approved" && r.paymentStatus !== "Paid";

  const decide = (r, verdict) => {
    setRows((prev) => prev.map((x) => (x.id === r.id ? {
      ...x,
      status: verdict === "approve" ? "Approved" : "Approval Rejected",
      approvedBy: verdict === "approve" ? (x.approvers[0] ?? "") : "",
      approvedByRows: x.approvedByRows.map((a) => ({ ...a, approved: verdict === "approve" })),
    } : x)));
    flash(verdict === "approve" ? "Approved Successfully" : "Rejected Successfully");
  };
  const pay = (r) => {
    setRows((prev) => prev.map((x) => (x.id === r.id
      ? { ...x, paymentStatus: "Paid", paymentDate: new Date().toISOString().slice(0, 10) } : x)));
    flash("Payment Updated Successfully");
  };
  const save = (rec) => {
    setRows((prev) => (prev.some((x) => x.id === rec.id) ? prev.map((x) => (x.id === rec.id ? rec : x)) : [rec, ...prev]));
    setEditing(null); setOpenId(rec.id);
  };
  const remove = (id) => { setRows((prev) => prev.filter((x) => x.id !== id)); setOpenId(null); };

  const openRec = openId ? rows.find((r) => r.id === openId) : null;
  const idx = view.findIndex((r) => r.id === openId);
  /* the live report pages at 1000 and prints ### when the total overflows */
  const CAP = 1000;

  return (
    <>
      <Style />
      <div className="zc">
        <aside className="zc-rail">
          <div className="zc-logo">ACC</div>
          {NAV.map(([label, icon]) => (
            <button key={label} className={"zc-navitem" + (label === "Pending Approvals" ? " on" : "")}>
              <Icon name={icon} /><span>{label}</span>
            </button>
          ))}
        </aside>

        <div className="zc-main">
          <header className="zc-appbar">
            <span className="zc-appname">Accounts</span>
            <div className="zc-appbar-r">
              <button className="zc-iconbtn" aria-label="Settings"><Icon name="gear" /></button>
              <button className="zc-iconbtn" aria-label="Notifications"><Icon name="bell" /></button>
              <span className="zc-user">Husain Khatumdi</span>
              <span className="zc-avatar"><Icon name="user" /></span>
            </div>
          </header>

          <div className="zc-reportbar">
            <h1>All Pending Approvals</h1>
            <div className="zc-reportbar-r">
              <button className="zc-iconbtn zc-sq" onClick={() => setShowSearch((s) => !s)} aria-label="Search"><Icon name="search" /></button>
              <button className="zc-add" onClick={() => setEditing("new")} aria-label="Add">＋</button>
              <button className="zc-iconbtn zc-sq" aria-label="More">···</button>
            </div>
          </div>

          {showSearch && (
            <div className="zc-searchrow">
              <span className="zc-searchlabel">SEARCH</span>
              <div className="zc-searchchip">
                <select value={search.field} onChange={(e) => setSearch((s) => ({ ...s, field: e.target.value }))}>
                  {SEARCH_FIELDS.map((f) => <option key={f}>{f}</option>)}
                </select>
                <span className="zc-op">contains</span>
                <input value={search.value} onChange={(e) => setSearch((s) => ({ ...s, value: e.target.value }))} placeholder="…" />
                {search.value && <button onClick={() => setSearch((s) => ({ ...s, value: "" }))} aria-label="Clear">✕</button>}
              </div>
            </div>
          )}

          <div className="zc-gridwrap">
            <table className="zc-grid zc-grid-tall">
              <thead>
                <tr>
                  <th className="zc-c-eye"><Icon name="eye" /></th>
                  <th className="zc-c-chk">
                    <input type="checkbox" checked={checked.size === view.length && view.length > 0}
                      onChange={(e) => setChecked(e.target.checked ? new Set(view.map((r) => r.id)) : new Set())}
                      aria-label="Select all" />
                  </th>
                  {COLUMNS.map((col) => (
                    col.sort ? (
                      <th key={col.k} style={{ width: col.w }}
                        className={col.type === "money" || col.type === "money3" ? "num" : ""}>
                        <button className="zc-th" onClick={() => setSort((s) => ({
                          key: col.k, dir: s.key === col.k && s.dir === "asc" ? "desc" : "asc" }))}>
                          <span>{col.label}</span>
                          <i className={"zc-caret" + (sort.key === col.k ? " on " + sort.dir : "")} />
                        </button>
                      </th>
                    ) : <th key={col.k} style={{ width: col.w }}>{col.label}</th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {view.slice(0, CAP).map((r) => (
                  <tr key={r.id} className={openId === r.id ? "sel" : ""} onClick={() => setOpenId(r.id)}>
                    <td className="zc-c-eye">{openId === r.id ? <span className="zc-dots">···</span> : null}</td>
                    <td className="zc-c-chk" onClick={(e) => e.stopPropagation()}>
                      <input type="checkbox" checked={checked.has(r.id)} aria-label={`Select ${r.paymentNo}`}
                        onChange={() => setChecked((p) => { const n = new Set(p); n.has(r.id) ? n.delete(r.id) : n.add(r.id); return n; })} />
                    </td>
                    {COLUMNS.map((col) => {
                      if (col.type === "btn") {
                        const [label, on, act] = col.k === "_approve" ? ["Approve", actionable(r), () => decide(r, "approve")]
                          : col.k === "_reject" ? ["Reject", actionable(r), () => decide(r, "reject")]
                          : ["Pay", payable(r), () => pay(r)];
                        return (
                          <td key={col.k} onClick={(e) => e.stopPropagation()}>
                            <button className="zc-rowbtn" disabled={!on} onClick={act}>{label}</button>
                          </td>
                        );
                      }
                      if (col.type === "fill") {
                        /* solid filled cell, not a chip — Creator conditional formatting */
                        const cls = r.paymentStatus === "Paid" || r.paymentStatus === "paid" ? "fill-paid"
                          : r.paymentStatus === "Cancelled" || r.paymentStatus === "Reverse" ? "fill-bad" : "";
                        return <td key={col.k} className={cls}>{r.paymentStatus}</td>;
                      }
                      const mono = col.type === "money" || col.type === "money3" || col.k === "addedTime"
                        || col.type === "date" || col.k === "messageId";
                      return (
                        <td key={col.k} className={(mono ? "mono " : "") + (col.type === "money" || col.type === "money3" ? "num " : "")}>
                          {col.k === "messageId"
                            ? <span className="zc-wrapline">{r.messageId}</span>
                            : cellText(r, col)}
                        </td>
                      );
                    })}
                  </tr>
                ))}
                {view.length === 0 && <tr><td colSpan={COLUMNS.length + 2} className="zc-empty">No records found</td></tr>}
              </tbody>
            </table>
          </div>

          <footer className="zc-footer">
            <span>Showing {Math.min(view.length, CAP)} of{" "}
              {rows.length > CAP ? <b className="zc-overflow">###</b> : rows.length}</span>
            {checked.size > 0 && <span className="zc-selcount">{checked.size} selected</span>}
          </footer>
        </div>

        {toast && <div className="zc-toast"><Icon name="tick" />{toast}</div>}

        {openRec && (
          <DetailPanel rec={openRec} onClose={() => setOpenId(null)} onEdit={() => setEditing(openRec)}
            onDelete={() => remove(openRec.id)}
            onPrev={idx > 0 ? () => setOpenId(view[idx - 1].id) : null}
            onNext={idx < view.length - 1 ? () => setOpenId(view[idx + 1].id) : null} />
        )}
        {editing && <RecordForm initial={editing === "new" ? null : editing}
          onCancel={() => setEditing(null)} onSave={save} />}
      </div>
    </>
  );
}

/* ══ detail panel ═══════════════════════════════════════════════════════ */

function DetailPanel({ rec, onClose, onEdit, onDelete, onPrev, onNext }) {
  const [menu, setMenu] = useState(false);
  useEffect(() => {
    const h = (e) => { if (e.key === "Escape") onClose(); };
    window.addEventListener("keydown", h); return () => window.removeEventListener("keydown", h);
  }, [onClose]);
  const label = (k) => COLUMNS.find((c) => c.k === k)?.label ?? FORM.find((f) => f.k === k)?.label
    ?? ({ approvers: "Approvers", preferredApprover: "Preferred Approver" })[k] ?? k;
  const val = (k) => {
    const v = rec[k];
    if (Array.isArray(v)) return v.map((x, i) => <div key={i} className="zc-stackline">{x}</div>);
    if (typeof v === "boolean") return v ? "true" : "false";
    return v ?? "";
  };
  const contradiction = rec.status === "Approved" && rec.approvedByRows.some((a) => !a.approved);

  return (
    <aside className="zc-panel">
      <div className="zc-panelbar">
        <div className="zc-nav2">
          <button className="zc-iconbtn zc-sq" onClick={onPrev} disabled={!onPrev} aria-label="Previous">‹</button>
          <button className="zc-iconbtn zc-sq" onClick={onNext} disabled={!onNext} aria-label="Next">›</button>
        </div>
        <div className="zc-panelacts">
          <button className="zc-btn zc-btn-edit" onClick={onEdit}><Icon name="pencil" />Edit</button>
          <button className="zc-btn zc-btn-out" onClick={onDelete}><Icon name="trash" />Delete</button>
          <div className="zc-menuwrap">
            <button className="zc-btn zc-btn-out" onClick={() => setMenu((m) => !m)}>More ⌄</button>
            {menu && (
              <div className="zc-menu">
                <button onClick={() => setMenu(false)}>Duplicate</button>
                <button onClick={() => setMenu(false)}>Print</button>
              </div>
            )}
          </div>
          <button className="zc-iconbtn zc-sq" onClick={onClose} aria-label="Close">✕</button>
        </div>
      </div>
      <div className="zc-panelbody">
        <table className="zc-kv"><tbody>
          {DETAIL.map((k) => <tr key={k}><th>{label(k)}</th><td>{val(k)}</td></tr>)}
        </tbody></table>

        {contradiction && (
          <p className="zc-inlinewarn">Status reads <b>Approved</b> and Approved By names {rec.approvedBy || "nobody"},
            yet the Approved box on the Approved By row is unchecked. That is a seventh place approval state
            is recorded, disagreeing with the six already listed in §8.4. One has to be authoritative.</p>
        )}

        <p className="zc-addcomment"><Icon name="comment" />Add a comment</p>
      </div>
    </aside>
  );
}

/* ══ form ═══════════════════════════════════════════════════════════════ */

const blank = () => ({
  id: `29248200001063${String(Math.floor(1000 + Math.random() * 8999))}`,
  addedTime: "", paymentDate: "", link: "", paymentStatus: "Pending",
  payableAmount: "", grossAmount: "", location: "", itemCategory: "", bankName: "",
  vendorName: "", villaName: "", paymentNo: "", masterCategory: "", status: "",
  coa: "", billingCycles: "", approvalLevel: "", nextLevelApprovalRequired: false,
  approvalType: "", approvedBy: "", messageId: "",
  approvers: [], preferredApprover: "", approvedByRows: [],
});

function RecordForm({ initial, onCancel, onSave }) {
  const [rec, setRec] = useState(() => initial ?? blank());
  const set = (k, v) => setRec((p) => ({ ...p, [k]: v }));
  const setRow = (id, k, v) => setRec((p) => ({ ...p,
    approvedByRows: p.approvedByRows.map((r) => (r.id === id ? { ...r, [k]: v } : r)) }));

  const control = (f, v, onSet) => {
    if (f.type === "lookup") return (
      <div className="zc-lookup">
        <select className={"zc-in" + (v ? "" : " ph")} value={v ?? ""} onChange={(e) => onSet(e.target.value)}>
          <option value="">-Select-</option>
          {f.options.map((o) => <option key={o}>{o}</option>)}
        </select>
        {v && <button className="zc-clear" onClick={() => onSet("")} aria-label="Clear">✕</button>}
      </div>
    );
    if (f.type === "multi") return <ChipBox options={f.options} value={v ?? []} onChange={onSet} />;
    return <input className="zc-in" value={v ?? ""} onChange={(e) => onSet(e.target.value)} />;
  };

  return (
    <div className="zc-formpage">
      <header className="zc-appbar">
        <span className="zc-appname">Accounts</span>
        <div className="zc-appbar-r">
          <button className="zc-iconbtn" aria-label="Settings"><Icon name="gear" /></button>
          <button className="zc-iconbtn" aria-label="Notifications"><Icon name="bell" /></button>
          <span className="zc-user">Husain Khatumdi</span>
          <span className="zc-avatar"><Icon name="user" /></span>
        </div>
      </header>
      {/* the form title band reads `Pending Approvals`, not the report name */}
      <div className="zc-formtitle">Pending Approvals</div>

      <div className="zc-formscroll">
        <div className="zc-formbody">
          {FORM.map((f) => f.type === "bool" ? (
            <label key={f.k} className="zc-formcheck">
              <input type="checkbox" checked={!!rec[f.k]} onChange={(e) => set(f.k, e.target.checked)} />
              <span>{f.label}</span>
            </label>
          ) : (
            <div className="zc-formrow" key={f.k}>
              <label>{f.label}</label>
              {control(f, rec[f.k], (v) => set(f.k, v))}
            </div>
          ))}

          <div className="zc-formsection">Approved By</div>
          <table className="zc-subedit">
            <thead>
              <tr><th style={{ width: 300 }}>Approver</th><th style={{ width: 320 }}>Approval Level</th>
                <th style={{ width: 110 }}>Approved</th><th style={{ width: 30 }} /></tr>
            </thead>
            <tbody>
              {rec.approvedByRows.map((a) => (
                <tr key={a.id}>
                  <td>{control({ type: "lookup", options: APPROVERS }, a.approver, (v) => setRow(a.id, "approver", v))}</td>
                  <td><input className="zc-in" value={a.approvalLevel} onChange={(e) => setRow(a.id, "approvalLevel", e.target.value)} /></td>
                  <td className="zc-mid">
                    <input type="checkbox" checked={!!a.approved} aria-label="Approved"
                      onChange={(e) => setRow(a.id, "approved", e.target.checked)} />
                  </td>
                  <td><button className="zc-x" aria-label="Remove row"
                    onClick={() => setRec((p) => ({ ...p, approvedByRows: p.approvedByRows.filter((x) => x.id !== a.id) }))}>✕</button></td>
                </tr>
              ))}
              {rec.approvedByRows.length === 0 && <tr><td colSpan={4} className="zc-empty">No rows</td></tr>}
            </tbody>
          </table>
          <button className="zc-addnew" onClick={() => setRec((p) => ({ ...p,
            approvedByRows: [...p.approvedByRows, { id: uid(), approver: "", approvalLevel: "", approved: false }] }))}>
            + Add New
          </button>
        </div>

        <div className="zc-formfoot">
          <button className="zc-btn zc-btn-pri" onClick={() => onSave(rec)}>{initial ? "Update" : "Submit"}</button>
          <button className="zc-btn zc-btn-out" onClick={onCancel}>Cancel</button>
        </div>
      </div>
    </div>
  );
}

function ChipBox({ options = [], value = [], onChange }) {
  const [q, setQ] = useState("");
  const [open, setOpen] = useState(false);
  const ref = useRef(null);
  useEffect(() => {
    const h = (e) => { if (ref.current && !ref.current.contains(e.target)) setOpen(false); };
    document.addEventListener("mousedown", h); return () => document.removeEventListener("mousedown", h);
  }, []);
  const avail = options.filter((o) => !value.includes(o) && o.toLowerCase().includes(q.toLowerCase()));
  return (
    <div className="zc-chipwrap" ref={ref}>
      <div className="zc-chipbox" onClick={() => setOpen(true)}>
        {value.map((v) => (
          <span className="zc-chip" key={v}>
            <button onClick={(e) => { e.stopPropagation(); onChange(value.filter((x) => x !== v)); }} aria-label="Remove">✕</button>
            {v}
          </span>
        ))}
        <input value={q} onChange={(e) => { setQ(e.target.value); setOpen(true); }}
          onFocus={() => setOpen(true)} placeholder={value.length ? "" : "-Select-"} />
      </div>
      {open && avail.length > 0 && (
        <ul className="zc-droplist">
          {avail.slice(0, 10).map((o) => (
            <li key={o}><button onClick={() => { onChange([...value, o]); setQ(""); }}>{o}</button></li>
          ))}
        </ul>
      )}
    </div>
  );
}

function Icon({ name }) {
  const a = { width: 16, height: 16, viewBox: "0 0 24 24", fill: "none", stroke: "currentColor", strokeWidth: 1.7, strokeLinecap: "round", strokeLinejoin: "round" };
  const s = {
    calc: <><rect x="4" y="2" width="16" height="20" rx="2" /><path d="M8 6h8M8 11h2M12 11h2M8 15h2M12 15h2M16 15v3" /></>,
    bank: <><path d="M3 21h18M5 21V9l7-5 7 5v12M9 21v-6h6v6" /></>,
    bank2: <><path d="M3 10l9-6 9 6M4 10v11h16V10M9 21v-7h6v7" /></>,
    bill: <><path d="M6 2h12v20l-3-2-3 2-3-2-3 2V2z" /><path d="M9 7h6M9 11h6M9 15h3" /></>,
    exp: <><circle cx="12" cy="12" r="9" /><path d="M12 7v10M9.5 9.5h5M9.5 14.5h5" /></>,
    sched: <><rect x="3" y="5" width="18" height="16" rx="2" /><path d="M8 3v4M16 3v4M3 11h18" /></>,
    obs: <><path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6-10-6-10-6z" /><circle cx="12" cy="12" r="2.5" /></>,
    mast: <><ellipse cx="12" cy="6" rx="8" ry="3" /><path d="M4 6v6c0 1.7 3.6 3 8 3s8-1.3 8-3V6M4 12v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6" /></>,
    gear: <><circle cx="12" cy="12" r="3" /><path d="M12 2v3M12 19v3M4.2 4.2l2.1 2.1M17.7 17.7l2.1 2.1M2 12h3M19 12h3M4.2 19.8l2.1-2.1M17.7 6.3l2.1-2.1" /></>,
    hourglass: <><path d="M7 3h10M7 21h10M8 3v3l4 4 4-4V3M8 21v-3l4-4 4 4v3" /></>,
    box: <><path d="M21 8l-9-5-9 5v8l9 5 9-5V8z" /><path d="M3 8l9 5 9-5M12 13v8" /></>,
    receipt: <><path d="M5 3h14v18l-2-1.5-2 1.5-2-1.5-2 1.5-2-1.5L7 21l-2-1.5V3z" /><path d="M9 8h6M9 12h6" /></>,
    bell: <><path d="M18 8a6 6 0 10-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9" /><path d="M10.3 21a2 2 0 003.4 0" /></>,
    user: <><circle cx="12" cy="8" r="4" /><path d="M4 21c0-4 3.6-6 8-6s8 2 8 6" /></>,
    search: <><circle cx="11" cy="11" r="7" /><path d="M20 20l-4.3-4.3" /></>,
    eye: <><path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6-10-6-10-6z" /><circle cx="12" cy="12" r="2.5" /></>,
    pencil: <><path d="M12 20h9" /><path d="M16.5 3.5a2.1 2.1 0 013 3L7 19l-4 1 1-4z" /></>,
    trash: <><path d="M3 6h18M8 6V4h8v2M5 6l1 14h12l1-14" /></>,
    comment: <><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z" /></>,
    tick: <><path d="M20 6L9 17l-5-5" /></>,
  };
  return <svg {...a} aria-hidden="true">{s[name]}</svg>;
}

function Style() {
  return (
    <style>{`
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Roboto+Mono:wght@400;500&display=swap');
*,*::before,*::after{box-sizing:border-box}
.zc{
  --rail:#2b2f4a; --rail2:#383d5e; --pink:#e4407f; --pinkd:#c72e69; --pinkl:#fdeef4;
  --ink:#20242e; --ink2:#4a5160; --ink3:#7b8494; --ink4:#a8afbb;
  --line:#e6e9ee; --line2:#d2d7df; --bg:#f4f5f7; --white:#fff;
  --ok:#0f7b5f; --okbg:#e9f6f1; --bad:#c0392b; --badbg:#fdeceb;
  --fillgreen:#1ec69f; --fillred:#e8756a;
  --sans:'Inter',-apple-system,'Segoe UI',Roboto,sans-serif; --mono:'Roboto Mono',ui-monospace,monospace;
  font-family:var(--sans); color:var(--ink); background:var(--bg);
  display:grid; grid-template-columns:104px minmax(0,1fr); height:100vh; overflow:hidden;
  font-size:13px; -webkit-font-smoothing:antialiased;
}
.zc :focus-visible{outline:2px solid var(--pink); outline-offset:1px}
.mono{font-family:var(--mono); font-variant-numeric:tabular-nums; font-size:12px; letter-spacing:-.2px}
.num{text-align:right}

.zc-rail{background:var(--rail); display:flex; flex-direction:column; overflow:visible}
.zc-logo{background:var(--pink); color:#fff; font-weight:700; font-size:13px; letter-spacing:.1em; height:46px; display:grid; place-items:center; flex:none}
.zc-navitem{background:none; border:0; color:#bcc2d2; font:inherit; font-size:10px; line-height:1.3; padding:9px 5px 7px;
  display:grid; justify-items:center; gap:5px; cursor:pointer; text-align:center; flex:none; word-break:break-word}
.zc-navitem:hover{background:var(--rail2); color:#fff}
.zc-navitem.on{background:var(--pink); color:#fff}

.zc-main{display:flex; flex-direction:column; min-width:0; min-height:0; background:var(--white)}
.zc-appbar{height:42px; flex:none; display:flex; align-items:center; justify-content:space-between; padding:0 14px; border-bottom:1px solid var(--line); background:var(--white)}
.zc-appname{font-size:15px; font-weight:500}
.zc-appbar-r{display:flex; align-items:center; gap:9px; color:var(--ink3)}
.zc-user{font-size:12.5px; color:var(--ink2)}
.zc-avatar{width:25px; height:25px; border-radius:50%; background:var(--line); color:var(--ink3); display:grid; place-items:center}
.zc-iconbtn{background:none; border:0; color:var(--ink3); cursor:pointer; padding:3px; display:grid; place-items:center; border-radius:3px; font:inherit}
.zc-iconbtn:hover:not(:disabled){color:var(--ink); background:var(--bg)}
.zc-iconbtn:disabled{opacity:.3; cursor:not-allowed}
.zc-sq{width:25px; height:25px; border:1px solid var(--line2); font-size:12px; line-height:1}

.zc-reportbar{flex:none; display:flex; align-items:center; gap:8px; padding:8px 14px; border-bottom:1px solid var(--line)}
.zc-reportbar h1{margin:0; font-size:16px; font-weight:500}
.zc-reportbar-r{margin-left:auto; display:flex; align-items:center; gap:6px}
.zc-add{width:27px; height:27px; border:0; border-radius:3px; background:var(--pink); color:#fff; font-size:15px; line-height:1; cursor:pointer}
.zc-add:hover{background:var(--pinkd)}
.zc-btn{font:inherit; font-size:12.5px; height:27px; padding:0 10px; border-radius:3px; cursor:pointer; white-space:nowrap;
  display:inline-flex; align-items:center; gap:5px}
.zc-btn svg{width:13px; height:13px}
.zc-btn-out{background:var(--white); border:1px solid var(--line2); color:var(--ink2)}
.zc-btn-out:hover{border-color:var(--ink4); color:var(--ink)}
.zc-btn-edit{background:var(--pinkl); border:1px solid var(--pink); color:var(--pink); font-weight:500}
.zc-btn-edit:hover{background:var(--pink); color:#fff}
.zc-btn-pri{background:var(--pink); border:1px solid var(--pink); color:#fff; font-weight:500}
.zc-btn-pri:hover{background:var(--pinkd)}
/* per-row action button: pink outline, faded when the record is settled */
.zc-rowbtn{font:inherit; font-size:12.5px; height:26px; padding:0 11px; border-radius:3px; cursor:pointer;
  background:var(--white); border:1px solid var(--pink); color:var(--pink); white-space:nowrap}
.zc-rowbtn:hover:not(:disabled){background:var(--pink); color:#fff}
.zc-rowbtn:disabled{opacity:.42; cursor:not-allowed}

.zc-searchrow{flex:none; display:flex; align-items:center; padding:6px 14px; border-bottom:1px solid var(--line); background:var(--bg)}
.zc-searchlabel{font-size:10px; font-weight:600; letter-spacing:.06em; color:var(--ink3); border:1px solid var(--line2);
  border-right:0; background:var(--white); padding:5px 8px; border-radius:3px 0 0 3px}
.zc-searchchip{display:flex; align-items:center; gap:5px; border:1px solid var(--pink); border-radius:0 3px 3px 0; background:var(--white); padding:2px 6px 2px 4px}
.zc-searchchip select,.zc-searchchip input{border:0; outline:0; font:inherit; font-size:12.5px; background:none; color:var(--ink)}
.zc-searchchip input{width:140px}
.zc-op{font-size:12px; color:var(--ink3)}
.zc-searchchip button{border:0; background:none; color:var(--pink); cursor:pointer; font-size:10px; padding:0 2px}

.zc-gridwrap{flex:1; overflow:auto; min-height:0}
.zc-grid{border-collapse:separate; border-spacing:0; font-size:12.5px; width:max-content; min-width:100%}
.zc-grid thead th{position:sticky; top:0; z-index:2; background:var(--white); text-align:left; font-weight:600; font-size:11.5px;
  color:var(--ink); padding:0 7px; height:31px; border-bottom:1px solid var(--line2); border-right:1px solid var(--line); white-space:nowrap}
.zc-grid thead th.num{text-align:right}
.zc-grid thead th.num .zc-th{justify-content:flex-end}
.zc-grid thead th:has(.zc-th){padding:0}
.zc-th{width:100%; height:31px; display:flex; align-items:center; gap:4px; justify-content:space-between; font:inherit;
  font-weight:600; font-size:11.5px; color:inherit; background:none; border:0; cursor:pointer; padding:0 7px}
.zc-caret{width:0; height:0; border-left:3.5px solid transparent; border-right:3.5px solid transparent;
  border-top:4.5px solid var(--ink4); opacity:.5; flex:none}
.zc-caret.on{opacity:1; border-top-color:var(--pink)}
.zc-caret.on.asc{border-top:0; border-bottom:4.5px solid var(--pink)}
.zc-grid tbody td{padding:0 7px; border-bottom:1px solid var(--line); border-right:1px solid var(--line);
  height:27px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:0}
.zc-grid-tall tbody td{height:auto; white-space:normal; overflow:visible; text-overflow:clip;
  max-width:none; vertical-align:middle; padding:8px 7px}
.zc-wrapline{word-break:break-all; line-height:1.4}
.zc-grid tbody tr{cursor:pointer}
.zc-grid tbody tr:hover td{background:var(--pinkl)}
.zc-grid tbody tr:hover td.fill-paid,.zc-grid tbody tr.sel td.fill-paid{background:var(--fillgreen)}
.zc-grid tbody tr.sel td{background:var(--pinkl); box-shadow:inset 0 -1px 0 var(--pink)}
/* solid filled status cell — conditional formatting on the report, not a chip */
.zc-grid tbody td.fill-paid{background:var(--fillgreen); color:#0d3b30; font-weight:500}
.zc-grid tbody td.fill-bad{background:var(--fillred); color:#4a1512; font-weight:500}
.zc-c-eye,.zc-c-chk{width:28px; text-align:center; color:var(--ink4); padding:0 !important}
.zc-c-chk input{accent-color:var(--pink); margin:0}
.zc-dots{color:var(--pink); font-weight:700; letter-spacing:1px}
.zc-empty{color:var(--ink3); text-align:center; padding:14px !important; font-size:12px; max-width:none !important}
.zc-footer{flex:none; display:flex; align-items:center; gap:14px; height:28px; padding:0 14px;
  border-top:1px solid var(--line2); background:var(--bg); font-size:12px; color:var(--ink2)}
.zc-selcount{color:var(--pink); font-weight:500}
.zc-overflow{color:var(--pink); font-weight:600}

.zc-toast{position:fixed; top:9px; left:50%; transform:translateX(-50%); z-index:80; background:var(--pink); color:#fff;
  display:flex; align-items:center; gap:9px; padding:11px 22px; border-radius:3px; font-size:14px; font-weight:500;
  box-shadow:0 4px 14px rgba(32,36,46,.2)}

.zc-panel{position:fixed; top:0; right:0; bottom:0; width:min(900px,62vw); background:var(--white);
  border-left:1px solid var(--line2); box-shadow:-8px 0 26px rgba(32,36,46,.10); display:flex; flex-direction:column; z-index:30}
.zc-panelbar{flex:none; display:flex; align-items:center; justify-content:space-between; gap:10px; padding:9px 14px; border-bottom:1px solid var(--line)}
.zc-nav2,.zc-panelacts{display:flex; align-items:center; gap:7px}
.zc-menuwrap{position:relative}
.zc-menu{position:absolute; right:0; top:31px; background:var(--white); border:1px solid var(--line2); border-radius:3px;
  box-shadow:0 6px 18px rgba(32,36,46,.14); padding:3px; min-width:130px; z-index:5}
.zc-menu button{width:100%; text-align:left; font:inherit; font-size:12.5px; padding:6px 9px; border:0; background:none; cursor:pointer; color:var(--ink2)}
.zc-menu button:hover{background:var(--bg)}
.zc-panelbody{overflow-y:auto; padding:18px 20px 28px}
.zc-kv{width:100%; border-collapse:collapse; font-size:13px; table-layout:fixed}
.zc-kv th{width:50%; text-align:left; vertical-align:top; font-weight:400; color:var(--ink);
  background:#fafbfc; padding:9px 14px; border:1px solid var(--line)}
.zc-kv td{padding:9px 14px; border:1px solid var(--line); word-break:break-word; vertical-align:top}
.zc-stackline{line-height:1.5}
.zc-inlinewarn{margin:14px 0 0; font-size:12.5px; color:var(--bad); background:var(--badbg);
  border:1px solid #f1cdc9; border-radius:3px; padding:8px 11px; line-height:1.55}
.zc-addcomment{margin:22px 0 0; color:var(--pink); font-size:13px; cursor:pointer; display:flex; align-items:center; gap:7px}

.zc-formpage{position:fixed; top:0; right:0; bottom:0; left:30px; background:var(--white); z-index:60; display:flex; flex-direction:column}
.zc-formtitle{flex:none; padding:16px 30px; font-size:17px; font-weight:500; background:#fafbfc; border-bottom:1px solid var(--line)}
.zc-formscroll{flex:1; overflow-y:auto}
.zc-formbody{padding:22px 30px 20px}
.zc-formcheck{display:flex; align-items:center; gap:9px; font-size:13px; cursor:pointer; margin:8px 0 14px}
.zc-formcheck input{appearance:none; -webkit-appearance:none; width:16px; height:16px; margin:0;
  border:1.5px solid var(--pink); border-radius:3px; background:var(--white); cursor:pointer; position:relative}
.zc-formcheck input:checked{background:var(--pink)}
.zc-formcheck input:checked::after{content:''; position:absolute; left:4px; top:1px; width:5px; height:9px;
  border:solid #fff; border-width:0 1.6px 1.6px 0; transform:rotate(42deg)}
.zc-formrow{display:grid; grid-template-columns:190px 330px; align-items:center; gap:14px; padding:6px 0}
.zc-formrow > label{font-size:13px; color:var(--ink2)}
.zc-formsection{margin:26px 0 14px; font-size:17px; font-weight:500}
.zc-formfoot{display:flex; gap:10px; padding:16px 30px 24px; border-top:1px solid var(--line)}
.zc-in{font:inherit; font-size:13px; height:32px; padding:0 8px; border:1px solid var(--line2); border-radius:3px;
  background:var(--white); color:var(--ink); width:100%}
.zc-in:focus{border-color:var(--pink); outline:0}
.zc-lookup{position:relative}
.zc-lookup .zc-in{padding-right:46px; appearance:none; -webkit-appearance:none;
  background-image:linear-gradient(45deg,transparent 50%,var(--ink3) 50%),linear-gradient(135deg,var(--ink3) 50%,transparent 50%);
  background-position:calc(100% - 15px) 14px,calc(100% - 10px) 14px; background-size:5px 5px,5px 5px; background-repeat:no-repeat}
.zc-lookup .zc-in.ph{color:var(--ink4)}
.zc-clear{position:absolute; right:26px; top:8px; border:0; background:none; color:var(--ink3); cursor:pointer; font-size:11px; padding:0 2px}
.zc-clear:hover{color:var(--bad)}
.zc-chipwrap{position:relative}
.zc-chipbox{display:flex; flex-wrap:wrap; gap:5px; align-content:flex-start; min-height:70px; padding:7px 8px;
  border:1px solid var(--line2); border-radius:3px; background:var(--white); cursor:text}
.zc-chipbox input{border:0; outline:0; font:inherit; font-size:13px; background:none; flex:1; min-width:70px; height:22px; padding:0}
.zc-chipbox input::placeholder{color:var(--ink4)}
.zc-chip{display:inline-flex; align-items:flex-start; gap:5px; font-size:12.5px; background:var(--white);
  border:1px solid var(--line2); border-radius:3px; padding:3px 7px 3px 5px; color:var(--ink2); line-height:1.35}
.zc-chip button{border:0; background:none; color:var(--ink4); cursor:pointer; font-size:10px; padding:1px 0 0}
.zc-chip button:hover{color:var(--bad)}
.zc-droplist{position:absolute; z-index:10; top:calc(100% + 2px); left:0; right:0; margin:0; padding:2px; list-style:none;
  background:var(--white); border:1px solid var(--line2); border-radius:3px; box-shadow:0 8px 20px rgba(32,36,46,.14); max-height:220px; overflow-y:auto}
.zc-droplist button{width:100%; text-align:left; font:inherit; font-size:13px; padding:6px 8px; border:0; background:none; cursor:pointer; border-radius:2px}
.zc-droplist button:hover{background:var(--pinkl)}
.zc-subedit{width:100%; border-collapse:collapse; font-size:12.5px; max-width:820px}
.zc-subedit th{text-align:left; font-weight:600; color:var(--ink2); background:#fafbfc; padding:9px 12px;
  border:1px solid var(--line); white-space:nowrap; font-size:13px}
.zc-subedit td{padding:8px 12px; border:1px solid var(--line); vertical-align:middle}
.zc-mid{text-align:center}
.zc-mid input{accent-color:var(--pink); width:15px; height:15px}
.zc-x{border:0; background:none; color:var(--ink4); cursor:pointer; font-size:10px; padding:3px 5px; border-radius:2px}
.zc-x:hover{color:var(--bad); background:var(--badbg)}
.zc-addnew{margin-top:10px; font:inherit; font-size:13px; color:var(--pink); background:none; border:0; cursor:pointer; padding:3px 2px}
@media (max-width:820px){ .zc{grid-template-columns:1fr; height:auto} .zc-rail{flex-direction:row; overflow-x:auto} .zc-panel{width:100vw} }
`}</style>
  );
}
