import React, { useState, useMemo, useRef, useEffect } from "react";

/* ═══════════════════════════════════════════════════════════════
   Bills — structural replica of the Zoho Creator screens.
   Field labels, column order, section names and control placement match
   Creator. Dates are dd-MMM-yyyy, amounts ₹ ##,##,###.## with Indian
   digit grouping. Row density targets ~20 rows in view.
   ═══════════════════════════════════════════════════════════════ */

const LOCATIONS = ["Mumbai", "Lonavala", "Alibaug", "Karjat", "Igatpuri", "Panchgani", "Goa", "Ooty And Coonoor"];
const HEAD_OFFICES = ["Central Office", "West Region", "South Region"];

const VILLAS = [
  ["v1", "ONYX Villa", "Mumbai"], ["v2", "Marina Villa", "Lonavala"], ["v3", "Skyfall Dew Drops", "Karjat"],
  ["v4", "Black Mirror Villa", "Alibaug"], ["v5", "Casablanca Villa", "Igatpuri"], ["v6", "Ezra Villa- Anjuna", "Goa"],
  ["v7", "Jungle Beach 12 BHK", "Alibaug"], ["v8", "Blue Pebble Villa", "Lonavala"], ["v9", "Casa Vayu", "Lonavala"],
  ["v10", "Woodside Ivy Villa", "Ooty And Coonoor"], ["v11", "Lonavla Central", "Lonavala"],
  ["v12", "Amani Villa", "Karjat"], ["v13", "Concrete Cove Villa", "Lonavala"], ["v14", "Casa Pino- Pilerne", "Goa"],
].map(([id, name, location]) => ({ id, name, location }));

const MASTER_CATEGORIES = [
  ["m1", "Utilities"], ["m2", "Property Repair & Maintenance"], ["m3", "Housekeeping"],
  ["m4", "Finance & Legal"], ["m5", "Employee & Staff Considerations"], ["m6", "Operations & Logistics"], ["m7", "F&B"],
].map(([id, name]) => ({ id, name }));

const ITEM_CATEGORIES = [
  ["i1", "ELECTRICITY BILL", "m1"], ["i2", "LAUNDRY", "m3"], ["i3", "OWNER RENT", "m4"],
  ["i4", "OWNER REVENUE", "m4"], ["i5", "STAFF SALARY", "m5"], ["i6", "PLUMBER WORKS", "m2"],
  ["i7", "CARPENTRY", "m2"], ["i8", "PAINTING WORKS", "m2"], ["i9", "HARDWARE MATERIAL", "m2"],
  ["i10", "DEEP CLEANING", "m3"], ["i11", "POOL CHEMICAL", "m3"], ["i12", "STAFF FUEL", "m6"],
  ["i13", "HOUSEKEEPING AND CLEANING MATERIAL", "m3"], ["i14", "F&B GENERAL PURCHASE", "m7"], ["i15", "STAY REFUND", "m4"],
].map(([id, name, master]) => ({ id, name, master }));

const VENDORS = [
  ["d1", "DHVAJ VIJENDRA BALDOTA(ONYX)", "Mumbai", "Maharashtra", "98200 11234", "dvbaldota@okaxis", false],
  ["d2", "DARSHIT MAHENDRA GUNDESHA (ONYX)", "Mumbai", "Maharashtra", "98330 44190", "dmg@okicici", false],
  ["d3", "MAHAVITRAN", "Lonavala", "Maharashtra", "022 4004 1122", "mahavitran@sbi", true],
  ["d4", "Shree Laxmi Laundry Services", "Karjat", "Maharashtra", "90040 77812", "shreelaxmi@ybl", true],
  ["d5", "Konkan Plumbing Works", "Alibaug", "Maharashtra", "98673 20045", "konkanplumb@paytm", false],
  ["d6", "ASHWINI ELECTRICAL", "Igatpuri", "Maharashtra", "94220 33871", "ashwinielec@ybl", true],
  ["d7", "SHANTADURGA TRADERS", "Goa", "Goa", "0832 246 7710", "shantadurga@sbi", true],
  ["d8", "PAYAL HARDWARE", "Alibaug", "Maharashtra", "98195 42200", "payalhw@okhdfc", true],
  ["d9", "MOHD SHADAB", "Lonavala", "Maharashtra", "82910 55043", "shadab@ybl", false],
  ["d10", "MAJU AND COMPANY", "Ooty And Coonoor", "Tamil Nadu", "0423 244 7788", "majuco@sbi", true],
].map(([id, name, location, state, phone, upi, primary]) => ({ id, name, location, state, phone, upi, source: "", primary }));

const COA_LIST = [["c1", "Expense"], ["c2", "Accounts Payable"], ["c3", "Security Deposit"], ["c4", "Payment Reverse"]]
  .map(([id, name]) => ({ id, name }));

const TAXES = [["t0", "IGST0", 0], ["t1", "GST5", 5], ["t2", "GST12", 12], ["t3", "GST18", 18], ["t4", "GST28", 28]]
  .map(([id, name, pct]) => ({ id, name, pct }));

const TDS_LIST = [["s0", "No TDS", 0], ["s1", "Contractor - 1.00", 1], ["s2", "Owner Rent - 10.00", 10], ["s3", "Professional - 10.00", 10]]
  .map(([id, name, pct]) => ({ id, name, pct }));

const MONTHS = ["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"];
const BILLING_CYCLES = [];
["2025", "2026", "2027"].forEach((y) => MONTHS.forEach((m) => BILLING_CYCLES.push({ id: `${m}-${y}`, label: `${m} - ${y}` })));

/* ── formatting, matching Creator ────────────────────────────── */

const money = (n) => {
  if (n === null || n === undefined || n === "" || Number.isNaN(+n)) return "";
  const neg = +n < 0;
  const [i, d] = Math.abs(+n).toFixed(2).split(".");
  let last3 = i.slice(-3), rest = i.slice(0, -3);
  if (rest) last3 = "," + last3;
  rest = rest.replace(/\B(?=(\d{2})+(?!\d))/g, ",");
  return `${neg ? "-" : ""}₹ ${rest}${last3}.${d}`;
};
const MA = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];
const dmy = (iso) => { if (!iso) return ""; const [y, m, d] = iso.split("-"); return `${d}-${MA[+m - 1]}-${y}`; };
const parseDmy = (s) => {
  const m = /^(\d{1,2})-([A-Za-z]{3})-(\d{4})$/.exec((s || "").trim());
  if (!m) return "";
  const mi = MA.findIndex((x) => x.toLowerCase() === m[2].toLowerCase());
  return mi < 0 ? "" : `${m[3]}-${String(mi + 1).padStart(2, "0")}-${String(m[1]).padStart(2, "0")}`;
};
/** "30-Jun-2026 17:45:03" -> sortable number, so Added Time orders by date not text. */
const stampToSort = (s) => {
  const m = /^(\d{2})-([A-Za-z]{3})-(\d{4})\s+(\d{2}):(\d{2}):(\d{2})$/.exec(s || "");
  if (!m) return 0;
  return +`${m[3]}${String(MA.indexOf(m[2]) + 1).padStart(2, "0")}${m[1]}${m[4]}${m[5]}${m[6]}`;
};
const uid = () => Math.random().toString(36).slice(2, 9);
const byId = (a, id) => a.find((x) => x.id === id);
const villaName = (id) => byId(VILLAS, id)?.name ?? "";
const catName = (id) => byId(ITEM_CATEGORIES, id)?.name ?? "";
const vendorName = (id) => byId(VENDORS, id)?.name ?? "";

/**
 * Bill arithmetic per OnInputGrossAmountCE / OnInputAdjustmentCE.
 *   Gross          = Σ Amount Category rows
 *   GST Amount     = Σ row Amount × row GST%
 *   TDS Amount     = Gross × TDS%
 *   Payable Amount = (Gross + GST) − (TDS + Paid) + Adjusted, floored at zero
 */
function compute(b) {
  const gross = b.amountCategory.reduce((s, r) => s + (+r.amount || 0), 0);
  const gst = b.amountCategory.reduce((s, r) => s + (+r.amount || 0) * ((byId(TAXES, r.gst)?.pct ?? 0) / 100), 0);
  const tdsPct = byId(TDS_LIST, b.tds)?.pct ?? 0;
  const tds = gross * (tdsPct / 100);
  const raw = gross + gst - (tds + (+b.paidAmount || 0)) + (+b.adjustedAmount || 0);
  const allocated = b.splitPayment.reduce((s, r) => s + (+r.amount || 0), 0);
  const remainder = gross - allocated;
  return { gross, gst, tds, tdsPct, invoice: gross + gst, payable: Math.max(0, raw), rawPayable: raw,
    allocated, remainder, balanced: Math.round(remainder) === 0 };
}

/** Split rows are the Villas × Item Category × Billing Cycle product, reconciled. */
const key3 = (v, c, y) => `${v || ""}|${c || ""}|${y || ""}`;
function reconcileSplit(existing = [], villas = [], cats = [], cycles = []) {
  const cy = cycles.length ? cycles : [""], ct = cats.length ? cats : [""];
  const combos = [];
  villas.forEach((v) => cy.forEach((y) => ct.forEach((c) => combos.push({ villaName: v, itemCategory: c, billingCycle: y }))));
  const want = new Set(combos.map((k) => key3(k.villaName, k.itemCategory, k.billingCycle)));
  const seen = new Set(), out = [];
  existing.forEach((r) => {
    const k = key3(r.villaName, r.itemCategory, r.billingCycle);
    if (want.has(k) && !seen.has(k)) { seen.add(k); out.push({ ...r, orphan: false }); }
    else if (+r.amount > 0) out.push({ ...r, orphan: true });
  });
  combos.forEach((k) => {
    const kk = key3(k.villaName, k.itemCategory, k.billingCycle);
    if (!seen.has(kk)) out.push({ id: uid(), ...k, amount: "", tdsAmount: "", gstAmount: "", orphan: false });
  });
  return out;
}

/* ── seed: 22 rows so density is real ────────────────────────── */

const RAW = [
  ["07-Aug-2026 18:45:11", "d1", "ONYX0811", "Payment InProgress", "2026-07-16", "2026-08-15", ["i4"], ["v1"], "2026", ["June"], "c1", 50000, 12000, "s0", 0, false],
  ["07-Aug-2026 18:40:29", "d2", "ONYX0810", "Overpaid", "2026-07-16", "2026-08-10", ["i4"], ["v1"], "2026", ["June"], "c1", 42000, 47500, "s0", 0, false],
  ["07-Aug-2026 18:39:29", "d2", "ONYX081", "Payment InProgress", "2026-08-06", "2026-09-05", ["i4"], ["v1"], "2026", ["July"], "c1", 38000, 0, "s0", 0, false],
  ["07-Aug-2026 18:34:56", "d1", "ONYX08", "Paid", "2026-08-06", "2026-09-05", ["i4"], ["v1"], "2026", ["July"], "c1", 26000, 26000, "s0", 0, false],
  ["06-Aug-2026 11:02:14", "d3", "MSEB-JUL-4471", "Draft", "2026-08-02", "2026-08-20", ["i1"], ["v2", "v3"], "2026", ["July"], "c1", 33700, 0, "s0", 0, true],
  ["01-Aug-2026 09:31:40", "d4", "SLL/2026/0912", "Paid", "2026-07-28", "2026-08-12", ["i2"], ["v3"], "2026", ["July"], "c1", 24720, 25956, "s1", 0, true],
  ["02-Jul-2026 16:20:05", "d5", "KPW-338", "Partially Paid", "2026-06-30", "2026-07-15", ["i6", "i7"], ["v4"], "2026", ["June"], "c1", 292400, 150000, "s1", -2400, true],
  ["01-Jul-2026 08:14:22", "d4", "PAY/JUN/STAFF", "Overdue", "2026-06-30", "2026-07-07", ["i5"], ["v2", "v4", "v3", "v10"], "2026", ["June"], "c2", 268000, 0, "s0", 0, false],
  ["30-Jun-2026 17:45:03", "d6", "AE-2026-441", "Paid", "2026-06-28", "2026-07-10", ["i14"], ["v5"], "2026", ["June"], "c1", 240, 240, "s0", 0, false],
  ["30-Jun-2026 15:12:55", "d7", "ST-9012", "Paid", "2026-06-27", "2026-07-08", ["i7"], ["v6"], "2026", ["June"], "c1", 50, 50, "s0", 0, false],
  ["29-Jun-2026 12:30:41", "d8", "PH-7734", "Partially Paid", "2026-06-25", "2026-07-25", ["i8", "i9"], ["v7"], "2026", ["July"], "c1", 82635, 40000, "s0", 0, true],
  ["28-Jun-2026 10:05:18", "d9", "MS-0231", "Paid", "2026-06-24", "2026-07-04", ["i14"], ["v8"], "2026", ["June"], "c1", 84, 84, "s0", 0, false],
  ["27-Jun-2026 18:22:09", "d10", "MC-5510", "Draft", "2026-06-22", "2026-07-22", ["i12"], ["v10"], "2026", ["June"], "c1", 12400, 0, "s0", 0, false],
  ["26-Jun-2026 14:40:37", "d3", "MSEB-JUN-4102", "Paid", "2026-06-20", "2026-07-05", ["i1"], ["v9"], "2026", ["June"], "c1", 18960, 18960, "s0", 0, true],
  ["25-Jun-2026 09:15:02", "d4", "SLL/2026/0877", "Paid", "2026-06-18", "2026-07-02", ["i2"], ["v13"], "2026", ["June"], "c1", 15400, 15400, "s1", 0, false],
  ["24-Jun-2026 16:58:44", "d5", "KPW-322", "Paid", "2026-06-15", "2026-06-30", ["i6"], ["v12"], "2026", ["June"], "c1", 6565, 6565, "s0", 0, false],
  ["23-Jun-2026 11:11:11", "d1", "PINO-JUN", "Paid", "2026-06-12", "2026-06-28", ["i3"], ["v14"], "2026", ["June"], "c1", 129900, 116910, "s2", 0, false],
  ["22-Jun-2026 15:29:30", "d6", "AE-2026-410", "Overdue", "2026-06-10", "2026-06-25", ["i11"], ["v5"], "2026", ["June"], "c1", 5426, 0, "s0", 0, false],
  ["21-Jun-2026 13:04:57", "d8", "PH-7688", "Paid", "2026-06-08", "2026-06-23", ["i9"], ["v7"], "2026", ["June"], "c1", 41317.5, 41317.5, "s0", 0, true],
  ["20-Jun-2026 08:48:12", "d10", "MC-5480", "Draft", "2026-06-05", "2026-07-05", ["i13"], ["v11"], "2026", ["June"], "c1", 76623, 0, "s0", 0, false],
  ["19-Jun-2026 17:33:26", "d7", "ST-8890", "Paid", "2026-06-02", "2026-06-17", ["i10"], ["v6"], "2026", ["June"], "c1", 9340, 9340, "s0", 0, false],
  ["18-Jun-2026 10:20:48", "d9", "MS-0198", "Paid", "2026-05-30", "2026-06-14", ["i15"], ["v9"], "2026", ["May"], "c4", 2070, 2070, "s0", 0, false],
];

const SEED = RAW.map((r, n) => {
  const [addedTime, vendor, billNo, status, billDate, dueDate, itemCategories, villas, billingYear, billingMonths, coa, gross, paidAmount, tds, adjustedAmount, gstNeeded] = r;
  const b = {
    id: String(292482000031204411 - n * 1137), addedTime, vendor, billNo, status, billDate, dueDate,
    itemCategories, villas, billingYear, billingMonths,
    billingCycles: billingMonths.map((m) => `${m}-${billingYear}`),
    location: [...new Set(villas.map((v) => byId(VILLAS, v)?.location))].join(", "),
    headOffice: "Central Office", coa, tds, paidAmount, adjustedAmount, gstNeeded, splitEqually: true,
    caEmail: gstNeeded ? "jkparmarassociatesca@gmail.com" : "", bookingNo: "",
    addedUser: n % 3 === 0 ? "Husain Khatumdi" : "Priya Nair",
    amountCategory: [{ id: uid(), billFor: catName(itemCategories[0]), amount: gross, gst: gstNeeded ? "t3" : "t0" }],
    splitPayment: [],
  };
  b.splitPayment = reconcileSplit([], b.villas, b.itemCategories, b.billingCycles);
  const per = Math.floor(gross / b.splitPayment.length);
  let run = 0;
  const tdsPct = byId(TDS_LIST, tds)?.pct ?? 0, gstPct = gstNeeded ? 18 : 0;
  b.splitPayment = b.splitPayment.map((x, i) => {
    const amt = i === b.splitPayment.length - 1 ? gross - run : per;
    run += amt;
    return { ...x, amount: amt, tdsAmount: +(amt * tdsPct / 100).toFixed(2), gstAmount: +(amt * gstPct / 100).toFixed(2) };
  });
  return b;
});

/* ═══════════════════════════════════════════════════════════════ */

const NAV = [
  ["Accounts", "calc"], ["Payments", "bank"], ["Bank", "bank2"], ["Bills", "bill"],
  ["Expenses", "exp"], ["Schedule Payments", "sched"], ["Expense Observations", "obs"], ["Masters", "mast"],
];

export default function BillsModule() {
  const [bills, setBills] = useState(SEED);
  const [openId, setOpenId] = useState(null);
  const [editing, setEditing] = useState(null);
  const [search, setSearch] = useState({ field: "Villas", value: "" });
  const [showSearch, setShowSearch] = useState(true);
  const [checked, setChecked] = useState(new Set());
  const [sort, setSort] = useState({ key: "addedTime", dir: "desc" });

  const rows = useMemo(() => {
    let r = bills;
    if (search.value.trim()) {
      const q = search.value.toLowerCase();
      r = r.filter((b) => (({
        Villas: b.villas.map(villaName).join(" "), "Vendor Name": vendorName(b.vendor),
        "Bill No": b.billNo, Status: b.status,
        "Item Category": b.itemCategories.map(catName).join(" "), Location: b.location,
      })[search.field] ?? "").toLowerCase().includes(q));
    }
    const dir = sort.dir === "asc" ? 1 : -1;
    return [...r].sort((a, b) => {
      const g = (x) => {
        if (sort.key === "vendor") return vendorName(x.vendor);
        if (sort.key === "gross") return compute(x).gross;
        if (sort.key === "addedTime") return stampToSort(x.addedTime);
        if (sort.key === "itemCategories") return x.itemCategories.map(catName).join(", ");
        return x[sort.key] ?? "";
      };
      const av = g(a), bv = g(b);
      return (typeof av === "number" ? av - bv : String(av).localeCompare(String(bv))) * dir;
    });
  }, [bills, search, sort]);

  const save = (bill) => {
    setBills((p) => (p.some((x) => x.id === bill.id) ? p.map((x) => (x.id === bill.id ? bill : x)) : [bill, ...p]));
    setEditing(null); setOpenId(bill.id);
  };
  const openBill = openId ? bills.find((b) => b.id === openId) : null;
  const openIdx = rows.findIndex((b) => b.id === openId);

  return (
    <>
      <Style />
      <div className="zc">
        <aside className="zc-rail">
          <div className="zc-logo">ACC</div>
          {NAV.map(([label, icon]) => (
            <button key={label} className={"zc-navitem" + (label === "Bills" ? " on" : "")}>
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
            <h1>All Bills <i className="zc-req">*</i></h1>
            <button className="zc-btn zc-btn-out">Save Changes</button>
            <button className="zc-btn zc-btn-out">Remove Changes</button>
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
                  {["Villas", "Vendor Name", "Bill No", "Status", "Item Category", "Location"].map((f) => <option key={f}>{f}</option>)}
                </select>
                <span className="zc-op">contains</span>
                <input value={search.value} onChange={(e) => setSearch((s) => ({ ...s, value: e.target.value }))} placeholder="…" />
                {search.value && <button onClick={() => setSearch((s) => ({ ...s, value: "" }))} aria-label="Clear">✕</button>}
              </div>
            </div>
          )}

          <div className="zc-gridwrap">
            <table className="zc-grid">
              <thead>
                <tr>
                  <th className="zc-c-eye"><Icon name="eye" /></th>
                  <th className="zc-c-chk">
                    <input type="checkbox" checked={checked.size === rows.length && rows.length > 0}
                      onChange={(e) => setChecked(e.target.checked ? new Set(rows.map((r) => r.id)) : new Set())} aria-label="Select all" />
                  </th>
                  <Th k="addedTime" s={sort} set={setSort} w={158}>Added Time</Th>
                  <th style={{ width: 132 }}>Create Payment</th>
                  <Th k="vendor" s={sort} set={setSort} w={250}>Vendor Name</Th>
                  <Th k="billNo" s={sort} set={setSort} w={136}>Bill No</Th>
                  <Th k="status" s={sort} set={setSort} w={132}>Status</Th>
                  <Th k="billDate" s={sort} set={setSort} w={104}>Bill Date</Th>
                  <Th k="itemCategories" s={sort} set={setSort} w={230}>Item Category</Th>
                  <th style={{ width: 200 }}>Villas</th>
                  <Th k="location" s={sort} set={setSort} w={132}>Location</Th>
                  <th style={{ width: 132 }}>Billing Cycles</th>
                  <Th k="gross" s={sort} set={setSort} w={126} num>Gross Amount</Th>
                  <th style={{ width: 126 }} className="num">Payable Amount</th>
                  <th style={{ width: 172 }}>ID</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((b) => {
                  const c = compute(b);
                  const hasPayment = b.status !== "Draft";
                  return (
                    <tr key={b.id} className={openId === b.id ? "sel" : ""} onClick={() => setOpenId(b.id)}>
                      <td className="zc-c-eye">{openId === b.id ? <span className="zc-dots">···</span> : null}</td>
                      <td className="zc-c-chk" onClick={(e) => e.stopPropagation()}>
                        <input type="checkbox" checked={checked.has(b.id)} aria-label={`Select ${b.billNo}`}
                          onChange={() => setChecked((p) => { const n = new Set(p); n.has(b.id) ? n.delete(b.id) : n.add(b.id); return n; })} />
                      </td>
                      <td className="mono nowrap">{b.addedTime}</td>
                      <td onClick={(e) => e.stopPropagation()}>
                        <button className="zc-rowbtn" disabled={hasPayment}
                          title={hasPayment ? "A payment already exists for this bill" : "Create Payment"}>Create Payment</button>
                      </td>
                      <td title={vendorName(b.vendor)}>{vendorName(b.vendor)}</td>
                      <td className="mono">{b.billNo}</td>
                      <td><Status v={b.status} /></td>
                      <td className="mono nowrap">{dmy(b.billDate)}</td>
                      <td title={b.itemCategories.map(catName).join(", ")}>{b.itemCategories.map(catName).join(", ")}</td>
                      <td title={b.villas.map(villaName).join(", ")}>{b.villas.map(villaName).join(", ")}</td>
                      <td>{b.location}</td>
                      <td className="nowrap">{b.billingCycles.map((k) => byId(BILLING_CYCLES, k)?.label).join(", ")}</td>
                      <td className="mono num">{money(c.gross)}</td>
                      <td className="mono num">{money(c.payable)}</td>
                      <td className="mono zc-id">{b.id}</td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>

          <footer className="zc-footer">
            <span>Showing {rows.length} of {bills.length}</span>
            {checked.size > 0 && <span className="zc-selcount">{checked.size} selected</span>}
          </footer>
        </div>

        {openBill && (
          <DetailPanel bill={openBill} onClose={() => setOpenId(null)} onEdit={() => setEditing(openBill)}
            onDelete={() => { if (openBill.status === "Draft") { setBills((p) => p.filter((x) => x.id !== openBill.id)); setOpenId(null); } }}
            onPrev={openIdx > 0 ? () => setOpenId(rows[openIdx - 1].id) : null}
            onNext={openIdx < rows.length - 1 ? () => setOpenId(rows[openIdx + 1].id) : null} />
        )}
        {editing && <BillForm initial={editing === "new" ? null : editing} onCancel={() => setEditing(null)} onSave={save} />}
      </div>
    </>
  );
}

function Th({ children, k, s, set, w, num }) {
  const on = s.key === k;
  return (
    <th style={{ width: w }} className={num ? "num" : ""}>
      <button className="zc-th" onClick={(e) => { e.stopPropagation(); set({ key: k, dir: on && s.dir === "asc" ? "desc" : "asc" }); }}>
        <span>{children}</span><i className={"zc-caret" + (on ? " on " + s.dir : "")} />
      </button>
    </th>
  );
}

const Status = ({ v }) => {
  const cls = { Paid: "ok", Overpaid: "bad", Overdue: "bad", "Partially Paid": "warn", "Payment InProgress": "info" }[v] ?? "";
  return <span className={"zc-status " + cls}>{v}</span>;
};

/* ── detail panel ────────────────────────────────────────────── */

function DetailPanel({ bill, onClose, onEdit, onDelete, onPrev, onNext }) {
  const c = compute(bill);
  const v = byId(VENDORS, bill.vendor);
  const [more, setMore] = useState(false);
  useEffect(() => {
    const h = (e) => e.key === "Escape" && onClose();
    window.addEventListener("keydown", h); return () => window.removeEventListener("keydown", h);
  }, [onClose]);

  return (
    <aside className="zc-panel" role="dialog" aria-label={`Bill ${bill.billNo}`}>
      <header className="zc-panelbar">
        <div className="zc-nav2">
          <button className="zc-iconbtn zc-sq" onClick={onPrev} disabled={!onPrev} aria-label="Previous">‹</button>
          <button className="zc-iconbtn zc-sq" onClick={onNext} disabled={!onNext} aria-label="Next">›</button>
        </div>
        <div className="zc-panelacts">
          <button className="zc-btn zc-btn-out" onClick={onEdit}>Edit</button>
          <button className="zc-btn zc-btn-out" onClick={onDelete} disabled={bill.status !== "Draft"}
            title={bill.status !== "Draft" ? `Only Draft bills can be deleted — this one is ${bill.status}` : "Delete"}>Delete</button>
          <div className="zc-menuwrap">
            <button className="zc-btn zc-btn-out" onClick={() => setMore((m) => !m)}>More ⌄</button>
            {more && <div className="zc-menu"><button onClick={() => setMore(false)}>Print</button></div>}
          </div>
          <button className="zc-iconbtn zc-sq" onClick={onClose} aria-label="Close">✕</button>
        </div>
      </header>

      <div className="zc-panelbody">
        <h2 className="zc-secttl">Overview</h2>
        <KV rows={[["Vendor Name", vendorName(bill.vendor)], ["Bill No", bill.billNo], ["Bill Date", dmy(bill.billDate)],
          ["Booking No.", bill.bookingNo], ["Location", bill.location], ["Head Office", bill.headOffice],
          ["Gross Amount", money(c.gross), "hl"]]} />

        <h2 className="zc-secttl">Vendor Name</h2>
        <KV rows={[["Vendor Name", v?.name], ["Location", v?.location], ["State", v?.state], ["Phone", v?.phone],
          ["UPI ID", v?.upi], ["Source", v?.source], ["Primary", String(!!v?.primary)]]} />

        <h2 className="zc-secttl">Item Category</h2>
        <table className="zc-sub">
          <thead><tr><th>Item Category</th><th>Master Category</th><th>Expense Type</th><th>Exclude for Profit</th><th>Exclude for Observation</th></tr></thead>
          <tbody>{bill.itemCategories.map((id) => (
            <tr key={id}><td>{catName(id)}</td>
              <td>{byId(MASTER_CATEGORIES, byId(ITEM_CATEGORIES, id)?.master)?.name}</td>
              <td /><td>false</td><td>false</td></tr>))}
          </tbody>
        </table>

        <h2 className="zc-secttl">Amount Category</h2>
        <table className="zc-sub">
          <thead><tr><th>Bill For</th><th className="num">Amount</th><th>GST</th><th className="num">GST Amount</th><th className="num">Total Amount</th></tr></thead>
          <tbody>{bill.amountCategory.map((r) => {
            const g = (+r.amount || 0) * ((byId(TAXES, r.gst)?.pct ?? 0) / 100);
            return <tr key={r.id}><td>{r.billFor}</td><td className="mono num">{money(r.amount)}</td>
              <td>{byId(TAXES, r.gst)?.name}</td><td className="mono num">{money(g)}</td>
              <td className="mono num">{money((+r.amount || 0) + g)}</td></tr>;
          })}</tbody>
        </table>

        <h2 className="zc-secttl">Commercials</h2>
        <KV rows={[["Gross Amount", money(c.gross)], ["Paid Amount", money(bill.paidAmount)],
          ["TDS Amount", money(c.tds)], ["Payable Amount", money(c.payable), "hl"],
          ["Split Equally", String(!!bill.splitEqually)], ["Adjusted Amount", money(bill.adjustedAmount)]]} />

        <h2 className="zc-secttl">Split Payment</h2>
        <table className="zc-sub">
          <thead><tr><th>Villa Name</th><th>Item Category</th><th>Billing Cycle</th>
            <th className="num">Amount</th><th className="num">TDS Amount</th><th className="num">GST Amount</th></tr></thead>
          <tbody>
            {bill.splitPayment.map((r) => (
              <tr key={r.id}><td>{villaName(r.villaName)}</td><td>{catName(r.itemCategory)}</td>
                <td>{byId(BILLING_CYCLES, r.billingCycle)?.label}</td>
                <td className="mono num">{money(r.amount)}</td><td className="mono num">{money(r.tdsAmount)}</td>
                <td className="mono num">{money(r.gstAmount)}</td></tr>))}
            <tr className="tot"><td colSpan={3}>Total</td><td className="mono num">{money(c.allocated)}</td><td /><td /></tr>
          </tbody>
        </table>
        {!c.balanced && <p className="zc-inlinewarn">Split Payment total {money(c.allocated)} does not match Gross Amount {money(c.gross)}.</p>}
        <p className="zc-addcomment">Add a comment</p>
      </div>
    </aside>
  );
}

const KV = ({ rows }) => (
  <table className="zc-kv"><tbody>
    {rows.map(([k, val, cls]) => (
      <tr key={k}><th>{k}</th><td className={(cls === "hl" ? "hl " : "") + (/₹/.test(String(val)) ? "mono" : "")}>{val}</td></tr>
    ))}
  </tbody></table>
);

/* ── form ────────────────────────────────────────────────────── */

const blank = () => ({
  id: String(292482000031200000 + Math.floor(Math.random() * 99999)),
  addedTime: "", addedUser: "Husain Khatumdi", billDate: "", billingYear: "", vendor: "",
  billingMonths: [], dueDate: "", billingCycles: [], itemCategories: [], billNo: "",
  villas: [], location: "", coa: "c1", headOffice: "", bookingNo: "", caEmail: "", gstNeeded: false,
  amountCategory: [{ id: uid(), billFor: "", amount: "", gst: "t0" }],
  tds: "s0", paidAmount: "", adjustedAmount: "", splitEqually: false, splitPayment: [], status: "Draft",
});

function BillForm({ initial, onCancel, onSave }) {
  const [b, setB] = useState(() => (initial ? JSON.parse(JSON.stringify(initial)) : blank()));
  const [tried, setTried] = useState(false);
  const c = compute(b);
  const locked = b.status === "Paid";
  const masters = [...new Set(b.itemCategories.map((i) => byId(ITEM_CATEGORIES, i)?.master))].filter(Boolean);
  const cycles = b.billingMonths.map((m) => `${m}-${b.billingYear}`).filter((k) => byId(BILLING_CYCLES, k));

  const set = (patch) => setB((p) => ({ ...p, ...patch }));
  const setScope = (patch) => setB((p) => {
    const n = { ...p, ...patch };
    const cy = n.billingMonths.map((m) => `${m}-${n.billingYear}`).filter((k) => byId(BILLING_CYCLES, k));
    return { ...n, billingCycles: cy,
      location: [...new Set(n.villas.map((v) => byId(VILLAS, v)?.location))].filter(Boolean).join(", "),
      splitPayment: reconcileSplit(n.splitPayment, n.villas, n.itemCategories, cy) };
  });

  const orphans = b.splitPayment.filter((r) => r.orphan);
  const errs = [];
  if (!b.billDate) errs.push("Bill Date is required.");
  if (!b.billingYear) errs.push("Billing Year is required.");
  if (!b.vendor) errs.push("Vendor Name is required.");
  if (!b.itemCategories.length) errs.push("Item Category is required.");
  if (!b.billingCycles.length) errs.push("Billing Cycles is required.");
  if (!b.billNo.trim()) errs.push("Bill No is required.");
  if (!b.villas.length) errs.push("Villas is required.");
  if (!c.balanced) errs.push(`Split Payment total ${money(c.allocated)} must equal Gross Amount ${money(c.gross)}.`);
  if (b.gstNeeded && b.amountCategory.some((r) => (byId(TAXES, r.gst)?.pct ?? 0) === 0))
    errs.push("GST Needed is checked, so no Amount Category row may use a 0% tax.");
  if (orphans.length) errs.push(`${orphans.length} Split Payment row(s) no longer match the Villas, Item Category or Billing Cycles selected.`);

  const setAC = (id, patch) => set({ amountCategory: b.amountCategory.map((r) => (r.id === id ? { ...r, ...patch } : r)) });
  const setSP = (id, patch) => set({ splitPayment: b.splitPayment.map((r) => (r.id === id ? { ...r, ...patch } : r)) });

  const doSplitEqually = (on) => {
    if (!on) return set({ splitEqually: false });
    const live = b.splitPayment.filter((r) => !r.orphan);
    if (!live.length) return set({ splitEqually: true });
    const per = Math.floor(c.gross / live.length), perG = Math.floor(c.gst / live.length);
    const tdsPct = byId(TDS_LIST, b.tds)?.pct ?? 0;
    let run = 0, runG = 0, i = 0;
    set({ splitEqually: true, splitPayment: b.splitPayment.map((r) => {
      if (r.orphan) return r;
      i += 1;
      const amt = i === live.length ? c.gross - run : per;
      const gst = i === live.length ? +(c.gst - runG).toFixed(2) : perG;
      run += amt; runG += gst;
      return { ...r, amount: amt, gstAmount: gst, tdsAmount: +(amt * tdsPct / 100).toFixed(2) };
    }) });
  };

  const submit = () => { setTried(true); if (!errs.length) onSave({ ...b, addedTime: b.addedTime || stamp() }); };

  return (
    <div className="zc-modalback">
      <div className="zc-modal" role="dialog" aria-label="Bills">
        <header className="zc-modalbar">
          <span>Bills</span>
          <button className="zc-iconbtn zc-sq" onClick={onCancel} aria-label="Close">✕</button>
        </header>

        <div className="zc-modalbody">
          {locked && <p className="zc-inlinewarn">This bill is Paid. Fields are read-only.</p>}

          <div className="zc-form2">
            <FRow label="Bill Date" req><DateBox value={b.billDate} disabled={locked} onChange={(v) => set({ billDate: v })} /></FRow>
            <FRow label="Billing Year" req>
              <input className="zc-in" value={b.billingYear} disabled={locked} placeholder="#######"
                onChange={(e) => setScope({ billingYear: e.target.value.replace(/\D/g, "").slice(0, 4) })} />
            </FRow>
            <FRow label="Vendor Name" req>
              <select className="zc-in" value={b.vendor} disabled={locked} onChange={(e) => set({ vendor: e.target.value })}>
                <option value="">-Select-</option>{VENDORS.map((v) => <option key={v.id} value={v.id}>{v.name}</option>)}
              </select>
            </FRow>
            <FRow label="Billing Months">
              <MultiBox options={MONTHS.map((m) => ({ id: m, label: m }))} value={b.billingMonths}
                disabled={locked || !b.billingYear} placeholder={b.billingYear ? "-Select-" : "Enter Billing Year first"}
                onChange={(v) => setScope({ billingMonths: v })} />
            </FRow>
            <FRow label="Due Date"><DateBox value={b.dueDate} disabled={locked} onChange={(v) => set({ dueDate: v })} /></FRow>
            <FRow label="Billing Cycles" req>
              <div className="zc-derived">
                {b.billingCycles.length ? b.billingCycles.map((k) => <span className="zc-tag" key={k}>{byId(BILLING_CYCLES, k)?.label}</span>)
                  : <span className="zc-ph">Set by Billing Year and Billing Months</span>}
              </div>
            </FRow>
            <FRow label="Item Category" req>
              <MultiBox options={ITEM_CATEGORIES.map((i) => ({ id: i.id, label: i.name }))} value={b.itemCategories}
                disabled={locked} placeholder="-Select-" onChange={(v) => setScope({ itemCategories: v })} />
            </FRow>
            <FRow label="Bill No" req>
              <input className="zc-in" value={b.billNo} disabled={locked} onChange={(e) => set({ billNo: e.target.value })} />
            </FRow>
            <FRow label="Master Category">
              <div className="zc-derived">
                {masters.length ? masters.map((m) => <span className="zc-tag" key={m}>{byId(MASTER_CATEGORIES, m)?.name}</span>)
                  : <span className="zc-ph">Set by Item Category</span>}
              </div>
            </FRow>
            <FRow label="Villas" req>
              <MultiBox options={VILLAS.map((v) => ({ id: v.id, label: v.name, meta: v.location }))} value={b.villas}
                disabled={locked} placeholder="-Select-" onChange={(v) => setScope({ villas: v })} />
            </FRow>
            <FRow label="COA" req>
              <select className="zc-in" value={b.coa} disabled={locked} onChange={(e) => set({ coa: e.target.value })}>
                <option value="">-Select-</option>{COA_LIST.map((x) => <option key={x.id} value={x.id}>{x.name}</option>)}
              </select>
            </FRow>
            <FRow label="Location"><div className="zc-derived">{b.location || <span className="zc-ph">Set by Villas</span>}</div></FRow>
            <FRow label="CA Email">
              <input className="zc-in" type="email" value={b.caEmail} disabled={locked} onChange={(e) => set({ caEmail: e.target.value })} />
            </FRow>
            <FRow label="Head Office">
              <select className="zc-in" value={b.headOffice} disabled={locked} onChange={(e) => set({ headOffice: e.target.value })}>
                <option value="">-Select-</option>{HEAD_OFFICES.map((h) => <option key={h}>{h}</option>)}
              </select>
            </FRow>
            <FRow label="GST Needed">
              <label className="zc-check">
                <input type="checkbox" checked={b.gstNeeded} disabled={locked} onChange={(e) => set({ gstNeeded: e.target.checked })} />
                <span>Bill carries GST</span>
              </label>
            </FRow>
            <FRow label="Booking No.">
              <select className="zc-in" value={b.bookingNo} disabled={locked || !b.villas.length} onChange={(e) => set({ bookingNo: e.target.value })}>
                <option value="">{b.villas.length ? "-Select-" : "Select Villas first"}</option>
                <option value="EKO10332581">EKO10332581</option><option value="EKO10334902">EKO10334902</option>
              </select>
            </FRow>
          </div>

          <h3 className="zc-fsect">Amount Category</h3>
          <table className="zc-subedit">
            <thead><tr><th>Bill For</th><th className="num" style={{ width: 130 }}>Amount</th>
              <th style={{ width: 106 }}>GST</th><th className="num" style={{ width: 126 }}>GST Amount</th>
              <th className="num" style={{ width: 136 }}>Total Amount</th><th style={{ width: 32 }} /></tr></thead>
            <tbody>
              {b.amountCategory.map((r) => {
                const pct = byId(TAXES, r.gst)?.pct ?? 0, g = (+r.amount || 0) * (pct / 100);
                const bad = b.gstNeeded && pct === 0;
                return (
                  <tr key={r.id}>
                    <td><input className="zc-in" value={r.billFor} disabled={locked} onChange={(e) => setAC(r.id, { billFor: e.target.value })} /></td>
                    <td><input className="zc-in mono num" value={r.amount} disabled={locked} placeholder="##,##,###.##"
                      onChange={(e) => setAC(r.id, { amount: e.target.value.replace(/[^\d.-]/g, "") })} /></td>
                    <td><select className={"zc-in" + (bad ? " bad" : "")} value={r.gst} disabled={locked} onChange={(e) => setAC(r.id, { gst: e.target.value })}>
                      {TAXES.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}</select></td>
                    <td className="mono num zc-cell">{money(g)}</td>
                    <td className="mono num zc-cell">{money((+r.amount || 0) + g)}</td>
                    <td><button className="zc-x" disabled={locked || b.amountCategory.length === 1} aria-label="Remove row"
                      onClick={() => set({ amountCategory: b.amountCategory.filter((x) => x.id !== r.id) })}>✕</button></td>
                  </tr>
                );
              })}
            </tbody>
          </table>
          <button className="zc-addnew" disabled={locked}
            onClick={() => set({ amountCategory: [...b.amountCategory, { id: uid(), billFor: "", amount: "", gst: b.amountCategory.at(-1)?.gst ?? "t0" }] })}>＋ Add New</button>

          <h3 className="zc-fsect">Commercials</h3>
          <div className="zc-form2">
            <FRow label="Gross Amount"><div className="zc-ro mono num">{money(c.gross)}</div></FRow>
            <FRow label="Paid Amount">
              <input className="zc-in mono num" value={b.paidAmount} disabled={locked} placeholder="##,##,###.##"
                onChange={(e) => set({ paidAmount: e.target.value.replace(/[^\d.-]/g, "") })} />
            </FRow>
            <FRow label="TDS">
              <select className="zc-in" value={b.tds} disabled={locked} onChange={(e) => set({ tds: e.target.value })}>
                {TDS_LIST.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}</select>
            </FRow>
            <FRow label="TDS Amount"><div className="zc-ro mono num">{money(c.tds)}</div></FRow>
            <FRow label="Adjusted Amount">
              <input className="zc-in mono num" value={b.adjustedAmount} disabled={locked} placeholder="#######.##"
                onChange={(e) => set({ adjustedAmount: e.target.value.replace(/[^\d.-]/g, "") })} />
            </FRow>
            <FRow label="Payable Amount"><div className="zc-ro mono num strong">{money(c.payable)}</div></FRow>
          </div>

          <h3 className="zc-fsect">
            Split Payment
            <label className="zc-check zc-inlinechk">
              <input type="checkbox" checked={b.splitEqually} disabled={locked} onChange={(e) => doSplitEqually(e.target.checked)} />
              <span>Split Equally</span>
            </label>
            <span className={"zc-tally " + (!c.gross ? "" : c.balanced ? "ok" : "bad")}>
              {money(c.allocated)} of {money(c.gross)}
              {!c.balanced && ` · ${money(Math.abs(c.remainder))} ${c.remainder > 0 ? "unallocated" : "over"}`}
            </span>
          </h3>
          <table className="zc-subedit">
            <thead><tr><th style={{ width: 200 }}>Villa Name</th><th style={{ width: 220 }}>Item Category</th>
              <th style={{ width: 146 }}>Billing Cycle</th><th className="num" style={{ width: 126 }}>Amount</th>
              <th className="num" style={{ width: 116 }}>TDS Amount</th><th className="num" style={{ width: 116 }}>GST Amount</th>
              <th className="num" style={{ width: 126 }}>Total Amount</th><th style={{ width: 32 }} /></tr></thead>
            <tbody>
              {b.splitPayment.length === 0 && (
                <tr><td colSpan={8} className="zc-empty">Rows appear once Villas, Item Category and Billing Cycles are selected.</td></tr>
              )}
              {b.splitPayment.map((r) => (
                <tr key={r.id} className={r.orphan ? "orph" : ""}>
                  <td><select className="zc-in" value={r.villaName} disabled={locked} onChange={(e) => setSP(r.id, { villaName: e.target.value })}>
                    <option value="">-Select-</option>
                    {[...new Set([...b.villas, r.villaName].filter(Boolean))].map((v) => <option key={v} value={v}>{villaName(v)}</option>)}</select></td>
                  <td><select className="zc-in" value={r.itemCategory} disabled={locked} onChange={(e) => setSP(r.id, { itemCategory: e.target.value })}>
                    <option value="">-Select-</option>
                    {[...new Set([...b.itemCategories, r.itemCategory].filter(Boolean))].map((i) => <option key={i} value={i}>{catName(i)}</option>)}</select></td>
                  <td><select className="zc-in" value={r.billingCycle} disabled={locked} onChange={(e) => setSP(r.id, { billingCycle: e.target.value })}>
                    <option value="">-Select-</option>
                    {[...new Set([...cycles, r.billingCycle].filter(Boolean))].map((k) => <option key={k} value={k}>{byId(BILLING_CYCLES, k)?.label}</option>)}</select></td>
                  <td><input className="zc-in mono num" value={r.amount} disabled={locked}
                    onChange={(e) => setSP(r.id, { amount: e.target.value.replace(/[^\d.-]/g, "") })} /></td>
                  <td><input className="zc-in mono num" value={r.tdsAmount} disabled={locked}
                    onChange={(e) => setSP(r.id, { tdsAmount: e.target.value.replace(/[^\d.-]/g, "") })} /></td>
                  <td><input className="zc-in mono num" value={r.gstAmount} disabled={locked}
                    onChange={(e) => setSP(r.id, { gstAmount: e.target.value.replace(/[^\d.-]/g, "") })} /></td>
                  <td className="mono num zc-cell">{money((+r.amount || 0) + (+r.gstAmount || 0) - (+r.tdsAmount || 0))}</td>
                  <td><button className="zc-x" disabled={locked} aria-label="Remove row"
                    onClick={() => set({ splitPayment: b.splitPayment.filter((x) => x.id !== r.id) })}>✕</button></td>
                </tr>
              ))}
            </tbody>
          </table>

          {tried && errs.length > 0 && (
            <div className="zc-errbox"><b>Cannot submit</b><ul>{errs.map((e) => <li key={e}>{e}</li>)}</ul></div>
          )}
        </div>

        <footer className="zc-modalfoot">
          <button className="zc-btn zc-btn-pri" onClick={submit} disabled={locked}>{initial ? "Update" : "Submit"}</button>
          <button className="zc-btn zc-btn-out" onClick={onCancel}>{initial ? "Cancel" : "Reset"}</button>
        </footer>
      </div>
    </div>
  );
}

function stamp() {
  const d = new Date();
  const p = (n) => String(n).padStart(2, "0");
  return `${p(d.getDate())}-${MA[d.getMonth()]}-${d.getFullYear()} ${p(d.getHours())}:${p(d.getMinutes())}:${p(d.getSeconds())}`;
}

const FRow = ({ label, req, children }) => (
  <div className="zc-frow"><label>{label}{req && <i className="zc-req">*</i>}</label><div className="zc-fctl">{children}</div></div>
);

/** dd-MMM-yyyy text field, as Creator uses — not a native date picker. */
function DateBox({ value, onChange, disabled }) {
  const [txt, setTxt] = useState(dmy(value));
  useEffect(() => setTxt(dmy(value)), [value]);
  return (
    <div className="zc-datebox">
      <input className="zc-in mono" value={txt} disabled={disabled} placeholder="dd-MMM-yyyy"
        onChange={(e) => setTxt(e.target.value)}
        onBlur={() => { const iso = parseDmy(txt); onChange(iso); setTxt(iso ? dmy(iso) : ""); }} />
      <span className="zc-cal">▤</span>
    </div>
  );
}

function MultiBox({ options, value, onChange, placeholder, disabled }) {
  const [q, setQ] = useState("");
  const [open, setOpen] = useState(false);
  const ref = useRef(null);
  useEffect(() => {
    const h = (e) => { if (ref.current && !ref.current.contains(e.target)) setOpen(false); };
    document.addEventListener("mousedown", h); return () => document.removeEventListener("mousedown", h);
  }, []);
  const avail = options.filter((o) => !value.includes(o.id) && o.label.toLowerCase().includes(q.toLowerCase()));
  return (
    <div className="zc-multi" ref={ref}>
      <div className={"zc-multibox" + (disabled ? " off" : "")} onClick={() => !disabled && setOpen(true)}>
        {value.map((id) => {
          const o = options.find((x) => x.id === id);
          return <span className="zc-tag" key={id}>{o?.label ?? id}
            {!disabled && <button onClick={(e) => { e.stopPropagation(); onChange(value.filter((v) => v !== id)); }} aria-label="Remove">✕</button>}</span>;
        })}
        {!disabled && <input value={q} onChange={(e) => { setQ(e.target.value); setOpen(true); }}
          onFocus={() => setOpen(true)} placeholder={value.length ? "" : placeholder} />}
      </div>
      {open && avail.length > 0 && (
        <ul className="zc-droplist">
          {avail.slice(0, 10).map((o) => (
            <li key={o.id}><button onClick={() => { onChange([...value, o.id]); setQ(""); }}>
              {o.label}{o.meta && <em>{o.meta}</em>}</button></li>
          ))}
        </ul>
      )}
    </div>
  );
}

function Icon({ name }) {
  const p = { width: 16, height: 16, viewBox: "0 0 24 24", fill: "none", stroke: "currentColor", strokeWidth: 1.7, strokeLinecap: "round", strokeLinejoin: "round" };
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
    bell: <><path d="M18 8a6 6 0 10-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9" /><path d="M10.3 21a2 2 0 003.4 0" /></>,
    user: <><circle cx="12" cy="8" r="4" /><path d="M4 21c0-4 3.6-6 8-6s8 2 8 6" /></>,
    search: <><circle cx="11" cy="11" r="7" /><path d="M20 20l-4.3-4.3" /></>,
    eye: <><path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6-10-6-10-6z" /><circle cx="12" cy="12" r="2.5" /></>,
  };
  return <svg {...p} aria-hidden="true">{s[name]}</svg>;
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
  --warn:#9a6206; --warnbg:#fdf3e2; --info:#2b5fa8; --infobg:#eaf1fb;
  --sans:'Inter',-apple-system,'Segoe UI',Roboto,sans-serif; --mono:'Roboto Mono',ui-monospace,monospace;
  font-family:var(--sans); color:var(--ink); background:var(--bg);
  display:grid; grid-template-columns:104px minmax(0,1fr); height:100vh; overflow:hidden;
  font-size:13px; -webkit-font-smoothing:antialiased;
}
.zc :focus-visible{outline:2px solid var(--pink); outline-offset:1px}
.mono{font-family:var(--mono); font-variant-numeric:tabular-nums; font-size:12px; letter-spacing:-.2px}
.num{text-align:right} .nowrap{white-space:nowrap} .strong{font-weight:600}

.zc-rail{background:var(--rail); display:flex; flex-direction:column; overflow-y:auto}
.zc-logo{background:var(--pink); color:#fff; font-weight:700; font-size:13px; letter-spacing:.1em; height:46px; display:grid; place-items:center; flex:none}
.zc-navitem{background:none; border:0; color:#bcc2d2; font:inherit; font-size:10px; line-height:1.3; padding:10px 5px 8px;
  display:grid; justify-items:center; gap:5px; cursor:pointer; text-align:center; flex:none; word-break:break-word}
.zc-navitem:hover{background:var(--rail2); color:#fff}
.zc-navitem.on{background:var(--pink); color:#fff}

.zc-main{display:flex; flex-direction:column; min-width:0; background:var(--white)}
.zc-appbar{height:42px; flex:none; display:flex; align-items:center; justify-content:space-between; padding:0 14px; border-bottom:1px solid var(--line)}
.zc-appname{font-size:15px; font-weight:500}
.zc-appbar-r{display:flex; align-items:center; gap:9px; color:var(--ink3)}
.zc-user{font-size:12.5px; color:var(--ink2)}
.zc-avatar{width:25px; height:25px; border-radius:50%; background:var(--line); color:var(--ink3); display:grid; place-items:center}
.zc-iconbtn{background:none; border:0; color:var(--ink3); cursor:pointer; padding:3px; display:grid; place-items:center; border-radius:3px; font:inherit}
.zc-iconbtn:hover:not(:disabled){color:var(--ink); background:var(--bg)}
.zc-iconbtn:disabled{opacity:.3; cursor:not-allowed}
.zc-sq{width:25px; height:25px; border:1px solid var(--line2); font-size:12px; line-height:1}

.zc-reportbar{flex:none; display:flex; align-items:center; gap:8px; padding:8px 14px; border-bottom:1px solid var(--line)}
.zc-reportbar h1{margin:0 4px 0 0; font-size:16px; font-weight:500}
.zc-req{color:var(--pink); font-style:normal; margin-left:2px}
.zc-reportbar-r{margin-left:auto; display:flex; align-items:center; gap:6px}
.zc-add{width:27px; height:27px; border:0; border-radius:3px; background:var(--pink); color:#fff; font-size:15px; line-height:1; cursor:pointer}
.zc-add:hover{background:var(--pinkd)}
.zc-btn{font:inherit; font-size:12.5px; height:27px; padding:0 10px; border-radius:3px; cursor:pointer; white-space:nowrap}
.zc-btn:disabled{opacity:.4; cursor:not-allowed}
.zc-btn-out{background:var(--white); border:1px solid var(--line2); color:var(--ink2)}
.zc-btn-out:hover:not(:disabled){border-color:var(--ink4); color:var(--ink)}
.zc-btn-pri{background:var(--pink); border:1px solid var(--pink); color:#fff; font-weight:500}
.zc-btn-pri:hover:not(:disabled){background:var(--pinkd)}

.zc-searchrow{flex:none; display:flex; align-items:center; padding:6px 14px; border-bottom:1px solid var(--line); background:var(--bg)}
.zc-searchlabel{font-size:10px; font-weight:600; letter-spacing:.06em; color:var(--ink3); border:1px solid var(--line2);
  border-right:0; background:var(--white); padding:5px 8px; border-radius:3px 0 0 3px}
.zc-searchchip{display:flex; align-items:center; gap:5px; border:1px solid var(--pink); border-radius:0 3px 3px 0; background:var(--white); padding:2px 6px 2px 4px}
.zc-searchchip select,.zc-searchchip input{border:0; outline:0; font:inherit; font-size:12.5px; background:none; color:var(--ink)}
.zc-searchchip input{width:130px}
.zc-op{font-size:12px; color:var(--ink3)}
.zc-searchchip button{border:0; background:none; color:var(--pink); cursor:pointer; font-size:10px; padding:0 2px}

.zc-gridwrap{flex:1; overflow:auto; min-height:0}
.zc-grid{border-collapse:separate; border-spacing:0; font-size:12.5px; width:max-content; min-width:100%}
.zc-grid thead th{position:sticky; top:0; z-index:2; background:var(--white); text-align:left; font-weight:600; font-size:11.5px;
  color:var(--ink); padding:0; height:31px; border-bottom:1px solid var(--line2); border-right:1px solid var(--line); white-space:nowrap}
.zc-grid thead th.num{text-align:right}
.zc-grid thead th.num .zc-th{justify-content:flex-end}
.zc-th{width:100%; height:31px; display:flex; align-items:center; gap:4px; justify-content:space-between; font:inherit;
  font-weight:600; font-size:11.5px; color:inherit; background:none; border:0; cursor:pointer; padding:0 7px}
.zc-caret{width:0; height:0; border-left:3.5px solid transparent; border-right:3.5px solid transparent;
  border-top:4.5px solid var(--ink4); opacity:.5; flex:none}
.zc-caret.on{opacity:1; border-top-color:var(--pink)}
.zc-caret.on.asc{border-top:0; border-bottom:4.5px solid var(--pink)}
.zc-grid tbody td{padding:0 7px; border-bottom:1px solid var(--line); border-right:1px solid var(--line);
  height:27px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:0}
.zc-grid tbody tr{cursor:pointer}
.zc-grid tbody tr:nth-child(even) td{background:#fafbfc}
.zc-grid tbody tr:hover td{background:var(--pinkl)}
.zc-grid tbody tr.sel td{background:var(--pinkl); box-shadow:inset 0 -1px 0 var(--pink)}
.zc-c-eye,.zc-c-chk{width:28px; text-align:center; color:var(--ink4); padding:0 !important}
.zc-c-chk input{accent-color:var(--pink); margin:0}
.zc-dots{color:var(--pink); font-weight:700; letter-spacing:1px}
.zc-id{color:var(--ink3); font-size:11px}
.zc-rowbtn{font:inherit; font-size:11px; height:20px; padding:0 7px; border:1px solid var(--pink); color:var(--pink);
  background:var(--white); border-radius:3px; cursor:pointer; white-space:nowrap}
.zc-rowbtn:hover:not(:disabled){background:var(--pink); color:#fff}
.zc-rowbtn:disabled{border-color:#f5cddc; color:#eaa7c2; cursor:not-allowed; background:var(--white)}
.zc-status{display:inline-block; padding:1px 6px; border-radius:2px; font-size:11.5px; font-weight:500}
.zc-status.ok{background:var(--okbg); color:var(--ok)}
.zc-status.bad{background:var(--badbg); color:var(--bad)}
.zc-status.warn{background:var(--warnbg); color:var(--warn)}
.zc-status.info{background:var(--infobg); color:var(--info)}
.zc-footer{flex:none; display:flex; align-items:center; gap:14px; height:28px; padding:0 14px;
  border-top:1px solid var(--line2); background:var(--bg); font-size:12px; color:var(--ink2)}
.zc-selcount{color:var(--pink); font-weight:500}

.zc-panel{position:fixed; top:0; right:0; bottom:0; width:min(700px,58vw); background:var(--white);
  border-left:1px solid var(--line2); box-shadow:-8px 0 26px rgba(32,36,46,.10); display:flex; flex-direction:column; z-index:30}
.zc-panelbar{flex:none; display:flex; align-items:center; justify-content:space-between; gap:10px; padding:7px 12px; border-bottom:1px solid var(--line)}
.zc-nav2,.zc-panelacts{display:flex; align-items:center; gap:6px}
.zc-menuwrap{position:relative}
.zc-menu{position:absolute; right:0; top:30px; background:var(--white); border:1px solid var(--line2); border-radius:3px;
  box-shadow:0 6px 18px rgba(32,36,46,.14); padding:3px; min-width:110px; z-index:5}
.zc-menu button{width:100%; text-align:left; font:inherit; font-size:12.5px; padding:6px 9px; border:0; background:none; cursor:pointer; color:var(--ink2)}
.zc-menu button:hover{background:var(--bg)}
.zc-panelbody{overflow-y:auto; padding:12px 16px 28px}
.zc-secttl{margin:17px 0 6px; font-size:13px; font-weight:600; padding-bottom:4px; border-bottom:1px solid var(--line)}
.zc-secttl:first-child{margin-top:0}
.zc-kv{width:100%; border-collapse:collapse; font-size:12.5px; table-layout:fixed}
.zc-kv th{width:200px; text-align:left; font-weight:400; color:var(--ink2); background:#fafbfc; padding:6px 9px; border:1px solid var(--line)}
.zc-kv td{padding:6px 9px; border:1px solid var(--line); word-break:break-word}
.zc-kv td.hl{background:#fbdcd9; font-weight:600}
.zc-sub{width:100%; border-collapse:collapse; font-size:12px}
.zc-sub th{text-align:left; font-weight:600; color:var(--ink2); background:#fafbfc; padding:5px 8px; border:1px solid var(--line); white-space:nowrap}
.zc-sub th.num{text-align:right}
.zc-sub td{padding:5px 8px; border:1px solid var(--line)}
.zc-sub tr.tot td{background:#fafbfc; font-weight:600}
.zc-inlinewarn{margin:9px 0 0; font-size:12.5px; color:var(--bad); background:var(--badbg); border:1px solid #f1cdc9; border-radius:3px; padding:6px 9px}
.zc-addcomment{margin:20px 0 0; color:var(--pink); font-size:12.5px; cursor:pointer}

.zc-modalback{position:fixed; inset:0; background:rgba(32,36,46,.35); z-index:60; display:grid; place-items:start center; padding:18px}
.zc-modal{background:var(--white); width:min(1220px,100%); max-height:calc(100vh - 36px); border-radius:4px;
  box-shadow:0 18px 50px rgba(32,36,46,.28); display:flex; flex-direction:column}
.zc-modalbar{flex:none; display:flex; align-items:center; justify-content:space-between; padding:10px 16px;
  border-bottom:1px solid var(--line); background:#fafbfc; font-size:15px; font-weight:500}
.zc-modalbody{overflow-y:auto; padding:14px 18px 20px}
.zc-modalfoot{flex:none; display:flex; gap:8px; padding:10px 18px; border-top:1px solid var(--line)}
.zc-form2{display:grid; grid-template-columns:1fr 1fr; gap:0 40px}
.zc-frow{display:grid; grid-template-columns:126px minmax(0,1fr); align-items:start; gap:10px; padding:4px 0; min-height:32px}
.zc-frow > label{font-size:12.5px; color:var(--ink2); padding-top:5px}
.zc-fctl{min-width:0}
.zc-in{font:inherit; font-size:12.5px; height:27px; padding:0 6px; border:1px solid var(--line2); border-radius:3px;
  background:var(--white); color:var(--ink); width:100%; max-width:280px}
.zc-in.num{text-align:right}
.zc-in:focus{border-color:var(--pink); outline:0}
.zc-in:disabled{background:#f6f7f9; color:var(--ink3); cursor:not-allowed}
.zc-in.bad{border-color:var(--bad); background:var(--badbg)}
.zc-ro{font-size:12.5px; min-height:27px; display:flex; align-items:center; justify-content:flex-end; padding:0 6px;
  background:#f6f7f9; border:1px solid var(--line); border-radius:3px; max-width:280px}
.zc-derived{display:flex; flex-wrap:wrap; gap:3px; align-items:center; min-height:27px; padding:2px 5px;
  border:1px dashed var(--line2); border-radius:3px; background:#fafbfc; max-width:280px}
.zc-ph{color:var(--ink4); font-size:11.5px}
.zc-check{display:inline-flex; align-items:center; gap:6px; font-size:12.5px; cursor:pointer; min-height:27px}
.zc-check input{accent-color:var(--pink)}
.zc-datebox{position:relative; max-width:280px}
.zc-cal{position:absolute; right:7px; top:5px; color:var(--ink4); font-size:11px; pointer-events:none}
.zc-fsect{margin:20px 0 7px; font-size:13px; font-weight:600; padding-bottom:5px; border-bottom:1px solid var(--line);
  display:flex; align-items:center; gap:14px}
.zc-inlinechk{font-weight:400}
.zc-tally{margin-left:auto; color:var(--ink3); background:#f6f7f9; font-family:var(--mono); font-size:11.5px; font-weight:500; padding:2px 7px; border-radius:2px}
.zc-tally.ok{color:var(--ok); background:var(--okbg)}
.zc-tally.bad{color:var(--bad); background:var(--badbg)}
.zc-subedit{width:100%; border-collapse:collapse; font-size:12px}
.zc-subedit th{text-align:left; font-weight:600; color:var(--ink2); background:#fafbfc; padding:5px 7px;
  border:1px solid var(--line); white-space:nowrap; font-size:11.5px}
.zc-subedit th.num{text-align:right}
.zc-subedit td{padding:2px 4px; border:1px solid var(--line); vertical-align:middle}
.zc-subedit td.zc-cell{padding:5px 7px; background:#fafbfc; color:var(--ink2)}
.zc-subedit .zc-in{max-width:none; height:25px}
.zc-subedit tr.orph td{background:var(--warnbg)}
.zc-subedit tr.orph td:first-child{box-shadow:inset 3px 0 0 var(--warn)}
.zc-empty{color:var(--ink3); text-align:center; padding:13px !important; font-size:12px}
.zc-x{border:0; background:none; color:var(--ink4); cursor:pointer; font-size:10px; padding:3px 5px; border-radius:2px}
.zc-x:hover:not(:disabled){color:var(--bad); background:var(--badbg)}
.zc-x:disabled{opacity:.3; cursor:not-allowed}
.zc-addnew{margin-top:6px; font:inherit; font-size:12.5px; color:var(--pink); background:none; border:0; cursor:pointer; padding:3px 2px}
.zc-addnew:disabled{opacity:.4; cursor:not-allowed}
.zc-errbox{margin-top:14px; border:1px solid #f1cdc9; background:var(--badbg); border-radius:3px; padding:9px 11px; font-size:12.5px; color:var(--bad)}
.zc-errbox b{display:block; margin-bottom:4px}
.zc-errbox ul{margin:0; padding-left:16px; line-height:1.6}
.zc-multi{position:relative; max-width:280px}
.zc-multibox{display:flex; flex-wrap:wrap; gap:3px; align-items:center; min-height:27px; padding:2px 4px;
  border:1px solid var(--line2); border-radius:3px; background:var(--white); cursor:text}
.zc-multibox.off{background:#f6f7f9}
.zc-multibox input{border:0; outline:0; font:inherit; font-size:12.5px; background:none; flex:1; min-width:56px; height:19px; padding:0}
.zc-tag{display:inline-flex; align-items:center; gap:3px; font-size:11px; background:#eef0f4; border:1px solid var(--line2);
  border-radius:2px; padding:1px 3px 1px 5px; color:var(--ink2); white-space:nowrap}
.zc-tag button{border:0; background:none; color:var(--ink3); cursor:pointer; font-size:9px; padding:0 1px}
.zc-tag button:hover{color:var(--bad)}
.zc-droplist{position:absolute; z-index:10; top:calc(100% + 2px); left:0; right:0; margin:0; padding:2px; list-style:none;
  background:var(--white); border:1px solid var(--line2); border-radius:3px; box-shadow:0 8px 20px rgba(32,36,46,.14); max-height:200px; overflow-y:auto}
.zc-droplist button{width:100%; text-align:left; font:inherit; font-size:12.5px; padding:5px 6px; border:0; background:none;
  cursor:pointer; border-radius:2px; display:flex; justify-content:space-between; gap:8px}
.zc-droplist button:hover{background:var(--pinkl)}
.zc-droplist em{font-style:normal; font-size:10.5px; color:var(--ink4); white-space:nowrap}
@media (max-width:1240px){ .zc-form2{grid-template-columns:1fr} }
@media (max-width:820px){ .zc{grid-template-columns:1fr; height:auto} .zc-rail{flex-direction:row; overflow-x:auto} .zc-panel{width:100vw} }
`}</style>
  );
}
