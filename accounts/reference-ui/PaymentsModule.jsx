import React, { useState, useMemo, useRef, useEffect } from "react";

/* ═══════════════════════════════════════════════════════════════════════════
   Payments — structural replica of the Zoho Creator screens.

   Rebuilt from ACCOUNTS_REBUILD_CONTEXT.md §5 (split → Expenses_Bills spine),
   §7 (fields, status axes, findings), §8 (approval engine). Replaces the earlier
   redesign, which renamed labels and invented a role switcher.

   Labels, section names, column order and control placement follow Creator.
   Dates dd-MMM-yyyy. Amounts ₹ ##,##,###.## with Indian digit grouping.
   Dirty enums preserved verbatim: "Sent for Approval" AND "Send for Approval",
   lowercase "paid", and "Open" (live but undeclared — Create_Payment writes it).

   Replicated Creator quirks, flagged rather than silently fixed:
     · Split Payment "Gross Amount" renders at three decimals (§7.5)
     · Payment_Reference_Number is labelled "Haewaya UTR Number" and packs two
       comma-separated values (§7.5)
     · Paid_Amount is a CHECKBOX here, a currency field on Bills (§7.1)
     · Approval state is recorded six ways at once (§8.4)
     · COA picker filters COA[Hide == true] — inverse of Bills (§7.5)

   Deliberate departures, each traceable to a stated rebuild requirement:
     · Detail panel shows the split row's real Amount, not the zero column the
       live app concatenates (§7.5 display bug)
     · Split rows reconcile rather than clear when scope changes (§5.1)
     · "Delete Paid Payment" stays in the More menu with its Creator label, but
       on a settled payment it opens a reversing entry instead of hard-deleting
       (§7.6 — 17 real payments, ₹93,884, were destroyed this way)
     · A running ₹ x of ₹ y tally on the Split Payment header. Payments has no
       balance check today (§7.4); the tally warns, it does not block, because
       blocking would change behaviour. Enforce server-side.
   ═══════════════════════════════════════════════════════════════════════════ */

const LOCATIONS = ["Mumbai", "Lonavala", "Alibaug", "Karjat", "Igatpuri", "Panchgani", "Goa", "Ooty And Coonoor"];
const HEAD_OFFICES = ["Central Office", "West Region", "South Region"];

const VILLAS = [
  ["v1", "ONYX Villa", "Mumbai"], ["v2", "Marina Villa", "Lonavala"], ["v3", "Skyfall Dew Drops", "Karjat"],
  ["v4", "Black Mirror Villa", "Alibaug"], ["v5", "Casablanca Villa", "Igatpuri"], ["v6", "Ezra Villa- Anjuna", "Goa"],
  ["v7", "Jungle Beach 12 BHK", "Alibaug"], ["v8", "Blue Pebble Villa", "Lonavala"], ["v9", "Casa Vayu", "Lonavala"],
  ["v10", "Woodside Ivy Villa", "Ooty And Coonoor"], ["v11", "Lonavla Central", "Lonavala"],
  ["v12", "Amani Villa", "Karjat"], ["v13", "Concrete Cove Villa", "Lonavala"], ["v14", "Casa Pino- Pilerne", "Goa"],
  ["v15", "Ooty Central", "Ooty And Coonoor"],
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
  ["i13", "HOUSEKEEPING AND CLEANING MATERIAL", "m3"], ["i14", "F&B GENERAL PURCHASE", "m7"],
  ["i15", "STAY REFUND", "m4"], ["i16", "ACCOMODATION", "m6"],
].map(([id, name, master]) => ({ id, name, master }));

/* Vendor_Name filters Vendor_Master[Main_Primary.Main_Primary is not null] (§7.5).
   primary=false payees are customer refunds, and show an empty Primary Vendor Name. */
const VENDORS = [
  ["d1", "DHVAJ VIJENDRA BALDOTA(ONYX)", "Mumbai", true], ["d2", "DARSHIT MAHENDRA GUNDESHA (ONYX)", "Mumbai", true],
  ["d3", "MAHAVITRAN", "Lonavala", true], ["d4", "Shree Laxmi Laundry Services", "Karjat", true],
  ["d5", "Konkan Plumbing Works", "Alibaug", true], ["d6", "ASHWINI ELECTRICAL", "Igatpuri", true],
  ["d7", "SHANTADURGA TRADERS", "Goa", true], ["d8", "PAYAL HARDWARE", "Alibaug", true],
  ["d9", "MOHD SHADAB", "Lonavala", true], ["d10", "MAJU AND COMPANY", "Ooty And Coonoor", true],
  ["d11", "Renu Sethi", "Mumbai", false], ["d12", "Aakash Menon", "Lonavala", false],
].map(([id, name, location, primary]) => ({ id, name, location, primary }));

/* COA carries Account_Type; the Payments picker filters Hide == true (§7.5). */
const COA_LIST = [
  ["c1", "Accounts Payable", "liability", true], ["c2", "Expense", "expense", true],
  ["c3", "Security Deposit", "other_asset", true], ["c4", "Payment Reverse", "expense", true],
  ["c5", "Bank Transfer", "bank", true], ["c6", "Advance To Vendor", "other_asset", false],
].map(([id, name, accountType, hide]) => ({ id, name, accountType, hide }));

const BANKS = [
  ["b1", "Ekostay LLP1 (4432)", "bank"], ["b2", "Ekostay LLP2 (9081)", "bank"],
  ["b3", "Haewaya Petty Cash (1180)", "petty"], ["b4", "Renu Sethi Kotak Mahindra (7839)", "bank"],
  ["b5", "Ekostay Petty Cash (0022)", "petty"],
].map(([id, name, accountType]) => ({ id, name, accountType }));

const TAXES = [["t0", "IGST0", 0], ["t1", "GST5", 5], ["t2", "GST12", 12], ["t3", "GST18", 18], ["t4", "GST28", 28]]
  .map(([id, name, pct]) => ({ id, name, pct }));

const TDS_LIST = [["s0", "No TDS", 0], ["s1", "Contractor - 1.00", 1], ["s2", "Owner Rent - 10.00", 10], ["s3", "Professional - 10.00", 10]]
  .map(([id, name, pct]) => ({ id, name, pct }));

const EMPLOYEES = [
  ["e1", "Zeeshan Shaikh", "zeeshan@ekostay.com"], ["e2", "Husain Khatumdi", "husain@ekostay.com"],
  ["e3", "Priya Nair", "priya@ekostay.com"], ["e4", "Accounts Head", "accountshead@ekostay.com"],
].map(([id, name, email]) => ({ id, name, email }));

const MONTHS = ["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"];
const BILLING_CYCLES = [];
["2025", "2026", "2027"].forEach((y) => MONTHS.forEach((m) => BILLING_CYCLES.push({ id: `${m}-${y}`, label: `${m} - ${y}` })));

/* Status axes verbatim (§7.3). Both "Sent for" and "Send for" exist in the picklist. */
const STATUSES = ["Draft", "Submit for Approval", "Sent for Approval", "Send for Approval",
  "Approved", "Approval Rejected", "Approval Not Required", "Paid"];
/* Payment_Status: declared {Pending, paid, Cancelled, Reverse}. "Open" is live and undeclared. */
const PAYMENT_STATUSES = ["Pending", "Open", "paid", "Cancelled", "Reverse"];

/* ── formatting, matching Creator ────────────────────────────────────────── */

const group = (i) => {
  let last3 = i.slice(-3), rest = i.slice(0, -3);
  if (rest) last3 = "," + last3;
  return rest.replace(/\B(?=(\d{2})+(?!\d))/g, ",") + last3;
};
const money = (n) => {
  if (n === null || n === undefined || n === "" || Number.isNaN(+n)) return "";
  const neg = +n < 0;
  const [i, d] = Math.abs(+n).toFixed(2).split(".");
  return `${neg ? "-" : ""}₹ ${group(i)}.${d}`;
};
/** Split Payment Gross Amount renders at three decimals in Creator (§7.5). Replicated. */
const money3 = (n) => {
  if (n === null || n === undefined || n === "" || Number.isNaN(+n)) return "";
  const neg = +n < 0;
  const [i, d] = Math.abs(+n).toFixed(3).split(".");
  return `${neg ? "-" : ""}₹ ${group(i)}.${d}`;
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
const coaName = (id) => byId(COA_LIST, id)?.name ?? "";
const bankName = (id) => byId(BANKS, id)?.name ?? "";
const cycleLabel = (id) => byId(BILLING_CYCLES, id)?.label ?? id;
const masterOf = (catIds) => [...new Set(catIds.map((c) => byId(ITEM_CATEGORIES, c)?.master).filter(Boolean))];

/**
 * Payment arithmetic (§7.2).
 *   Invoice Amount  = Gross + GST
 *   Payable Amount  = (Gross + GST) − TDS                          normal path
 *   Payable Amount  = Gross − GST + TDS      when the source bill was Partially
 *                                            Paid and Backend_Total_Amount is set
 *                                            — the signs invert. [TODO] intentional?
 * Total Split Amount is Σ Split Payment Gross Amount. Creator does not check it
 * against Amount here, unlike Bills (§7.4).
 */
function compute(p) {
  const gross = +p.amount || 0;
  const gstPct = byId(TAXES, p.gst)?.pct ?? 0;
  const tdsPct = byId(TDS_LIST, p.tds)?.pct ?? 0;
  const gstAmount = gross * (gstPct / 100);
  const tdsAmount = gross * (tdsPct / 100);
  const invoice = gross + gstAmount;
  const payable = p.backendPath ? gross - gstAmount + tdsAmount : invoice - tdsAmount;
  const allocated = p.splitPayments.reduce((s, r) => s + (+r.amount || 0), 0);
  const remainder = gross - allocated;
  return { gross, gstPct, tdsPct, gstAmount, tdsAmount, invoice, payable, allocated, remainder,
    balanced: Math.round(remainder * 100) === 0, hasSplits: p.splitPayments.length > 0 };
}

/** Per-row split derivation: only Gross Amount is typed, the rest derive (§7.5). */
const splitDerive = (row, gstPct, tdsPct) => {
  const g = +row.amount || 0;
  const gst = g * (gstPct / 100), tds = g * (tdsPct / 100);
  return { gst, tds, total: g + gst - tds };
};

/** Split rows are the Villa Name × Item Category × Billing Cycle product, reconciled (§5.1). */
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
    else if (+r.amount > 0) out.push({ ...r, orphan: true });   // carries money: keep and flag
  });
  combos.forEach((k) => {
    const kk = key3(k.villaName, k.itemCategory, k.billingCycle);
    if (!seen.has(kk)) out.push({ id: uid(), ...k, amount: "", orphan: false });
  });
  return out;
}

/* ── seed: 22 rows so density is real ──────────────────────────────────── */

const RAW = [
  ["20799", "07-Aug-2026 18:45:11", "d8", "Paid", "paid", "2026-08-07", "2026-07-16", "2026-08-10", ["v7"], ["i8", "i9"], ["July-2026"], "c1", "b1", 82635, "t0", "s0", "PY-8811", "118103052206,15038", false],
  ["20798", "07-Aug-2026 18:40:29", "d1", "Sent for Approval", "Open", "", "2026-07-16", "2026-08-12", ["v1"], ["i4"], ["July-2026"], "c1", "b1", 268000, "t0", "s2", "ONYX0810", "", false],
  ["20797", "07-Aug-2026 18:39:29", "d2", "Send for Approval", "Open", "", "2026-08-06", "2026-08-12", ["v1"], ["i4"], ["July-2026"], "c1", "b1", 195000, "t0", "s2", "ONYX081", "", false],
  ["20796", "07-Aug-2026 18:34:56", "d1", "Approved", "Pending", "", "2026-08-06", "2026-08-14", ["v1"], ["i4"], ["August-2026"], "c1", "b2", 268000, "t0", "s2", "ONYX08", "", false],
  ["20795", "06-Aug-2026 11:02:14", "d3", "Submit for Approval", "Open", "", "2026-08-02", "2026-08-18", ["v2", "v3"], ["i1"], ["July-2026"], "c1", "b1", 47710, "t0", "s0", "MSEB-JUL-4471", "", false],
  ["20794", "01-Aug-2026 09:31:40", "d4", "Paid", "paid", "2026-08-01", "2026-07-28", "2026-08-05", ["v3"], ["i2"], ["July-2026"], "c2", "b3", 9120, "t1", "s1", "SLL/2026/0912", "", false],
  ["20793", "02-Jul-2026 16:20:05", "d5", "Paid", "paid", "2026-07-02", "2026-06-30", "2026-07-08", ["v4"], ["i6", "i7"], ["June-2026"], "c2", "b1", 33800, "t3", "s1", "KPW-338", "", true],
  ["20792", "01-Jul-2026 08:14:22", "d4", "Approval Rejected", "Cancelled", "", "2026-06-30", "2026-07-06", ["v2", "v4", "v9"], ["i5"], ["June-2026"], "c1", "b1", 218400, "t0", "s0", "PAY/JUN/STAFF", "", false],
  ["20791", "30-Jun-2026 17:45:03", "d6", "Paid", "paid", "2026-06-30", "2026-06-28", "2026-07-02", ["v5"], ["i14"], ["June-2026"], "c2", "b1", 26441, "t0", "s0", "AE-2026-441", "", false],
  ["20790", "30-Jun-2026 15:12:05", "d7", "Paid", "paid", "2026-06-30", "2026-06-27", "2026-07-04", ["v6"], ["i7"], ["June-2026"], "c2", "b1", 90120, "t3", "s1", "ST-9012", "", false],
  ["20789", "29-Jun-2026 12:30:41", "d8", "Paid", "paid", "2026-06-29", "2026-06-25", "2026-07-01", ["v7"], ["i8", "i9"], ["June-2026"], "c2", "b1", 82635, "t0", "s0", "PH-7734", "", false],
  ["20788", "28-Jun-2026 10:05:18", "d9", "Paid", "paid", "2026-06-28", "2026-06-24", "2026-06-30", ["v8"], ["i14"], ["June-2026"], "c2", "b3", 2310, "t0", "s0", "MS-0231", "", false],
  ["20787", "27-Jun-2026 18:22:09", "d10", "Approval Not Required", "Pending", "", "2026-06-22", "2026-07-05", ["v10", "v15"], ["i12"], ["June-2026"], "c3", "b5", 5510, "t0", "s0", "MC-5510", "", false],
  ["20786", "26-Jun-2026 14:40:37", "d3", "Paid", "paid", "2026-06-26", "2026-06-20", "2026-06-28", ["v9"], ["i1"], ["June-2026"], "c2", "b1", 41020, "t0", "s0", "MSEB-JUN-4102", "", false],
  ["20785", "25-Jun-2026 09:15:02", "d4", "Paid", "paid", "2026-06-25", "2026-06-18", "2026-06-27", ["v13"], ["i2"], ["June-2026"], "c2", "b3", 8770, "t1", "s1", "SLL/2026/0877", "", false],
  ["20784", "24-Jun-2026 16:58:44", "d5", "Paid", "paid", "2026-06-24", "2026-06-15", "2026-06-26", ["v12"], ["i6"], ["June-2026"], "c2", "b1", 32200, "t3", "s1", "KPW-322", "", false],
  ["20783", "23-Jun-2026 11:11:11", "d1", "Paid", "paid", "2026-06-23", "2026-06-12", "2026-06-25", ["v14"], ["i3"], ["June-2026"], "c1", "b1", 145000, "t0", "s2", "PINO-JUN", "118103052210,15044", false],
  ["20782", "22-Jun-2026 15:29:30", "d6", "Draft", "Pending", "", "2026-06-10", "2026-06-24", ["v5"], ["i11"], ["June-2026"], "c2", "b1", 4100, "t0", "s0", "AE-2026-410", "", false],
  ["20781", "21-Jun-2026 09:04:57", "d8", "Paid", "paid", "2026-06-21", "2026-06-08", "2026-06-23", ["v7"], ["i9"], ["June-2026"], "c2", "b1", 76880, "t0", "s0", "PH-7688", "", false],
  ["20780", "20-Jun-2026 08:48:12", "d10", "Paid", "paid", "2026-06-20", "2026-06-05", "2026-06-22", ["v11"], ["i13"], ["June-2026"], "c2", "b1", 54800, "t0", "s0", "MC-5480", "", false],
  ["20779", "19-Jun-2026 17:33:26", "d7", "Paid", "Reverse", "2026-06-19", "2026-06-02", "2026-06-21", ["v6"], ["i10"], ["May-2026"], "c4", "b1", 88900, "t0", "s0", "ST-8890", "", false],
  ["20778", "18-Jun-2026 10:20:48", "d11", "Paid", "paid", "2026-06-18", "2026-05-30", "2026-06-20", ["v9"], ["i15"], ["May-2026"], "c2", "b4", 19800, "t0", "s0", "MS-0198", "", false],
];

const SEED = RAW.map(([no, addedTime, vendor, status, paymentStatus, paymentDate, requestedDate, dueDate,
  villas, cats, cycles, coa, bank, amount, gst, tds, billNo, utr, backendPath], n) => {
  const p = {
    id: `2924820000${String(11400 + n * 37).padStart(8, "0")}`,
    paymentNo: `EKS/PY/${no}`,
    addedTime, vendor, status,
    paymentStatus,
    requestedDate, paymentDate, dueDate,
    villas, itemCategories: cats, billingCycles: cycles,
    location: [...new Set(villas.map((v) => byId(VILLAS, v)?.location))].join(", "),
    headOffice: "Central Office",
    coa, bank, bookingNo: n % 5 === 0 ? `BK-2026-${1200 + n}` : "",
    billNo, particulars: "", utr,
    amount, gst, tds, originalAmount: amount, backendPath,
    paidAmountFlag: status === "Paid", gstNeeded: gst !== "t0", ocr: "",
    expenseBy: n % 3 === 0 ? "Priya Nair" : "ekostay", addedUser: "ekostay",
    caEmail: "ca@ekostay.com",
    pt: "", esic: "", pf: "",
    splitPayments: [], billPayments: [], docs: [],
    approver1: "", approver2: "", approver3: "", approved: false, approvedPersons: "",
    messageidLevel1: "", messageidLevel2: "",
    billsDoc: "", supportingDocuments: "",
  };
  p.splitPayments = reconcileSplit([], p.villas, p.itemCategories, p.billingCycles);
  const per = p.amount / p.splitPayments.length;
  p.splitPayments = p.splitPayments.map((r, i) => ({
    ...r,
    // row 20795 is left deliberately short, to exercise the missing balance check (§7.4)
    amount: no === "20795" && i === 1 ? Math.round(per * 0.4 * 100) / 100 : Math.round(per * 100) / 100,
  }));
  p.billPayments = [{ id: uid(), billNo, billAmount: p.amount }];
  if (p.status === "Sent for Approval" || p.status === "Send for Approval") {
    p.messageidLevel2 = `wamid.HBgMOTE5${8100 + n}`;
    p.particulars = "rent and acc paid from llp1 approved by zeeshan sir";
  }
  if (p.status === "Approved") { p.approved = true; p.approvedPersons = "Zeeshan Shaikh"; p.approver1 = "e1"; }
  if (p.itemCategories.includes("i5")) { p.pt = 200; p.esic = 0; p.pf = 1800; }
  if (no === "20787") p.docs = [{ id: uid(), url: "https://hywdocs.s3.ap-southeast-1.amazonaws.com/user_digital_docs/mc5510.pdf" }];
  return p;
});

const NAV = [
  ["Accounts", "calc"], ["Payments", "bank"], ["Bank", "bank2"], ["Bills", "bill"],
  ["Expenses", "exp"], ["Schedule Payments", "sched"], ["Expense Observations", "obs"], ["Masters", "mast"],
];

const SEARCH_FIELDS = ["Villa Name", "Vendor Name", "Payment No", "Status", "Payment Status", "Item Category", "Location", "Bill No"];

/* ══ list ═══════════════════════════════════════════════════════════════ */

export default function PaymentsModule() {
  const [payments, setPayments] = useState(SEED);
  const [openId, setOpenId] = useState(null);
  const [editing, setEditing] = useState(null);
  const [reversing, setReversing] = useState(null);
  const [search, setSearch] = useState({ field: "Villa Name", value: "" });
  const [showSearch, setShowSearch] = useState(true);
  const [checked, setChecked] = useState(new Set());
  const [sort, setSort] = useState({ key: "addedTime", dir: "desc" });

  const rows = useMemo(() => {
    let r = payments;
    if (search.value.trim()) {
      const q = search.value.toLowerCase();
      r = r.filter((p) => (({
        "Villa Name": p.villas.map(villaName).join(" "), "Vendor Name": vendorName(p.vendor),
        "Payment No": p.paymentNo, Status: p.status, "Payment Status": p.paymentStatus,
        "Item Category": p.itemCategories.map(catName).join(" "), Location: p.location, "Bill No": p.billNo,
      })[search.field] ?? "").toLowerCase().includes(q));
    }
    const dir = sort.dir === "asc" ? 1 : -1;
    return [...r].sort((a, b) => {
      const g = (x) => {
        if (sort.key === "addedTime") return stampToSort(x.addedTime);
        if (sort.key === "vendor") return vendorName(x.vendor);
        if (sort.key === "coa") return coaName(x.coa);
        if (sort.key === "bank") return bankName(x.bank);
        if (sort.key === "itemCategories") return x.itemCategories.map(catName).join(", ");
        if (sort.key === "amount") return compute(x).gross;
        if (sort.key === "payable") return compute(x).payable;
        if (sort.key === "split") return compute(x).allocated;
        return x[sort.key] ?? "";
      };
      const av = g(a), bv = g(b);
      return (typeof av === "number" ? av - bv : String(av).localeCompare(String(bv))) * dir;
    });
  }, [payments, search, sort]);

  const save = (p) => {
    setPayments((prev) => (prev.some((x) => x.id === p.id) ? prev.map((x) => (x.id === p.id ? p : x)) : [p, ...prev]));
    setEditing(null); setOpenId(p.id);
  };
  const duplicate = (p) => {
    const next = String(20800 + payments.length);
    setEditing({ ...p, id: uid(), paymentNo: `EKS/PY/${next}`, status: "Draft", paymentStatus: "Pending",
      paymentDate: "", requestedDate: new Date().toISOString().slice(0, 10), utr: "", paidAmountFlag: false,
      approved: false, approvedPersons: "", approver1: "", approver2: "", approver3: "",
      messageidLevel1: "", messageidLevel2: "", duplicateOf: p.paymentNo, addedTime: stamp() });
  };
  const commitReverse = (p, reason) => {
    const next = String(20800 + payments.length);
    const rev = { ...p, id: uid(), paymentNo: `EKS/PY/${next}`, addedTime: stamp(),
      status: "Approval Not Required", paymentStatus: "Reverse", coa: "c4",
      amount: -Math.abs(+p.amount || 0), originalAmount: -Math.abs(+p.originalAmount || 0),
      reversalOf: p.paymentNo, reversalReason: reason, paymentDate: new Date().toISOString().slice(0, 10),
      splitPayments: p.splitPayments.map((r) => ({ ...r, id: uid(), amount: -Math.abs(+r.amount || 0) })),
      billPayments: p.billPayments.map((r) => ({ ...r, id: uid(), billAmount: -Math.abs(+r.billAmount || 0) })) };
    setPayments((prev) => [rev, ...prev.map((x) => (x.id === p.id ? { ...x, reversedBy: rev.paymentNo } : x))]);
    setReversing(null); setOpenId(rev.id);
  };

  const openPayment = openId ? payments.find((p) => p.id === openId) : null;
  const openIdx = rows.findIndex((p) => p.id === openId);

  return (
    <>
      <Style />
      <div className="zc">
        <aside className="zc-rail">
          <div className="zc-logo">ACC</div>
          {NAV.map(([label, icon]) => (
            <button key={label} className={"zc-navitem" + (label === "Payments" ? " on" : "")}>
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
            <h1>All Payments <i className="zc-req">*</i></h1>
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
                  {SEARCH_FIELDS.map((f) => <option key={f}>{f}</option>)}
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
                  <Th k="paymentNo" s={sort} set={setSort} w={128}>Payment No</Th>
                  <Th k="vendor" s={sort} set={setSort} w={244}>Vendor Name</Th>
                  <Th k="status" s={sort} set={setSort} w={152}>Status</Th>
                  <Th k="paymentStatus" s={sort} set={setSort} w={122}>Payment Status</Th>
                  <Th k="paymentDate" s={sort} set={setSort} w={106}>Payment Date</Th>
                  <Th k="dueDate" s={sort} set={setSort} w={104}>Due Date</Th>
                  <Th k="itemCategories" s={sort} set={setSort} w={228}>Item Category</Th>
                  <th style={{ width: 200 }}>Villa Name</th>
                  <Th k="location" s={sort} set={setSort} w={128}>Location</Th>
                  <th style={{ width: 132 }}>Billing Cycles</th>
                  <Th k="coa" s={sort} set={setSort} w={140}>COA</Th>
                  <Th k="bank" s={sort} set={setSort} w={190}>Bank Name</Th>
                  <Th k="amount" s={sort} set={setSort} w={124} num>Amount</Th>
                  <th style={{ width: 112 }} className="num">TDS Amount</th>
                  <th style={{ width: 112 }} className="num">GST Amount</th>
                  <Th k="payable" s={sort} set={setSort} w={128} num>Payable Amount</Th>
                  <Th k="split" s={sort} set={setSort} w={136} num>Total Split Amount</Th>
                  <Th k="billNo" s={sort} set={setSort} w={132}>Bill No</Th>
                  <th style={{ width: 118 }}>Booking No</th>
                  <Th k="requestedDate" s={sort} set={setSort} w={116}>Requested Date</Th>
                  <th style={{ width: 132 }}>Expense By</th>
                  <th style={{ width: 118 }}>Added User</th>
                  <th style={{ width: 176 }}>ID</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((p) => {
                  const c = compute(p);
                  return (
                    <tr key={p.id} className={openId === p.id ? "sel" : ""} onClick={() => setOpenId(p.id)}>
                      <td className="zc-c-eye">{openId === p.id ? <span className="zc-dots">···</span> : null}</td>
                      <td className="zc-c-chk" onClick={(e) => e.stopPropagation()}>
                        <input type="checkbox" checked={checked.has(p.id)} aria-label={`Select ${p.paymentNo}`}
                          onChange={() => setChecked((prev) => { const n = new Set(prev); n.has(p.id) ? n.delete(p.id) : n.add(p.id); return n; })} />
                      </td>
                      <td className="mono nowrap">{p.addedTime}</td>
                      <td className="mono">{p.paymentNo}</td>
                      <td title={vendorName(p.vendor)}>{vendorName(p.vendor)}</td>
                      <td><StatusChip v={p.status} /></td>
                      <td><PayStatusChip v={p.paymentStatus} /></td>
                      <td className="mono nowrap">{dmy(p.paymentDate)}</td>
                      <td className="mono nowrap">{dmy(p.dueDate)}</td>
                      <td title={p.itemCategories.map(catName).join(", ")}>{p.itemCategories.map(catName).join(", ")}</td>
                      <td title={p.villas.map(villaName).join(", ")}>{p.villas.map(villaName).join(", ")}</td>
                      <td>{p.location}</td>
                      <td className="nowrap">{p.billingCycles.map(cycleLabel).join(", ")}</td>
                      <td>{coaName(p.coa)}</td>
                      <td title={bankName(p.bank)}>{bankName(p.bank)}</td>
                      <td className="mono num">{money(c.gross)}</td>
                      <td className="mono num">{money(c.tdsAmount)}</td>
                      <td className="mono num">{money(c.gstAmount)}</td>
                      <td className="mono num">{money(c.payable)}</td>
                      <td className={"mono num" + (c.balanced ? "" : " zc-off")}
                        title={c.balanced ? "" : `Off by ${money(c.remainder)} against Amount`}>{money(c.allocated)}</td>
                      <td className="mono">{p.billNo}</td>
                      <td className="mono">{p.bookingNo}</td>
                      <td className="mono nowrap">{dmy(p.requestedDate)}</td>
                      <td>{p.expenseBy}</td>
                      <td>{p.addedUser}</td>
                      <td className="mono zc-id">{p.id}</td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>

          <footer className="zc-footer">
            <span>Showing {rows.length} of {payments.length}</span>
            {checked.size > 0 && <span className="zc-selcount">{checked.size} selected</span>}
          </footer>
        </div>

        {openPayment && (
          <DetailPanel p={openPayment} onClose={() => setOpenId(null)} onEdit={() => setEditing(openPayment)}
            onDuplicate={() => duplicate(openPayment)} onDeletePaid={() => setReversing(openPayment)}
            onPrev={openIdx > 0 ? () => setOpenId(rows[openIdx - 1].id) : null}
            onNext={openIdx < rows.length - 1 ? () => setOpenId(rows[openIdx + 1].id) : null} />
        )}
        {editing && <PaymentForm initial={editing === "new" ? null : editing} n={payments.length}
          onCancel={() => setEditing(null)} onSave={save} />}
        {reversing && <ReverseDialog p={reversing} onCancel={() => setReversing(null)}
          onConfirm={(reason) => commitReverse(reversing, reason)} />}
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

const StatusChip = ({ v }) => {
  const cls = { Paid: "ok", Approved: "ok", "Approval Rejected": "bad", "Approval Not Required": "warn",
    "Submit for Approval": "info", "Sent for Approval": "info", "Send for Approval": "info" }[v] ?? "";
  return <span className={"zc-status " + cls}>{v}</span>;
};
const PayStatusChip = ({ v }) => {
  const cls = { paid: "ok", Pending: "warn", Open: "info", Cancelled: "bad", Reverse: "bad" }[v] ?? "";
  return <span className={"zc-status " + cls}>{v}</span>;
};

/* ══ detail panel ═══════════════════════════════════════════════════════ */

function DetailPanel({ p, onClose, onEdit, onDuplicate, onDeletePaid, onPrev, onNext }) {
  const [menu, setMenu] = useState(false);
  const c = compute(p);
  const settled = p.paymentStatus === "paid" || p.status === "Paid";
  useEffect(() => {
    const h = (e) => { if (e.key === "Escape") onClose(); };
    window.addEventListener("keydown", h); return () => window.removeEventListener("keydown", h);
  }, [onClose]);

  return (
    <aside className="zc-panel">
      <div className="zc-panelbar">
        <div className="zc-nav2">
          <button className="zc-iconbtn zc-sq" onClick={onPrev} disabled={!onPrev} aria-label="Previous">‹</button>
          <button className="zc-iconbtn zc-sq" onClick={onNext} disabled={!onNext} aria-label="Next">›</button>
          <strong className="mono" style={{ marginLeft: 4 }}>{p.paymentNo}</strong>
          <StatusChip v={p.status} />
        </div>
        <div className="zc-panelacts">
          <button className="zc-btn zc-btn-out" onClick={onEdit}>Edit</button>
          <div className="zc-menuwrap">
            <button className="zc-btn zc-btn-out" onClick={() => setMenu((m) => !m)}>More ▾</button>
            {menu && (
              <div className="zc-menu">
                <button onClick={() => { setMenu(false); onDuplicate(); }}>Duplicate Payment</button>
                <button onClick={() => { setMenu(false); onDeletePaid(); }}>Delete Paid Payment</button>
                <button onClick={() => setMenu(false)}>Print</button>
              </div>
            )}
          </div>
          <button className="zc-iconbtn zc-sq" onClick={onClose} aria-label="Close">✕</button>
        </div>
      </div>

      <div className="zc-panelbody">
        {p.reversalOf && (
          <p className="zc-inlineinfo">Reversing entry for <b className="mono">{p.reversalOf}</b>. Reason: {p.reversalReason}</p>
        )}
        {p.reversedBy && (
          <p className="zc-inlineinfo">Reversed by <b className="mono">{p.reversedBy}</b>.</p>
        )}
        {p.duplicateOf && (
          <p className="zc-inlineinfo">Duplicated from <b className="mono">{p.duplicateOf}</b>.</p>
        )}

        <h3 className="zc-secttl">Overview</h3>
        <KV rows={[
          ["Payment No", <span className="mono">{p.paymentNo}</span>],
          ["Status", <StatusChip v={p.status} />],
          ["Payment Status", <PayStatusChip v={p.paymentStatus} />],
          ["Vendor Name", vendorName(p.vendor)],
          ["Primary Vendor Name", byId(VENDORS, p.vendor)?.primary ? vendorName(p.vendor) : ""],
          ["COA", coaName(p.coa)],
          ["Bank Name", bankName(p.bank)],
          ["Requested Date", <span className="mono">{dmy(p.requestedDate)}</span>],
          ["Payment Date", <span className="mono">{dmy(p.paymentDate)}</span>],
          ["Due Date", <span className="mono">{dmy(p.dueDate)}</span>],
          ["Bill No", <span className="mono">{p.billNo}</span>],
          ["Booking No", <span className="mono">{p.bookingNo}</span>],
          ["Villa Name", p.villas.map(villaName).join(", ")],
          ["Location", p.location],
          ["Head Office", p.headOffice],
          ["Master Category", masterOf(p.itemCategories).map((m) => byId(MASTER_CATEGORIES, m)?.name).join(", ")],
          ["Item Category", p.itemCategories.map(catName).join(", ")],
          ["Billing Cycles", p.billingCycles.map(cycleLabel).join(", ")],
          ["Haewaya UTR Number", <span className="mono">{p.utr}</span>],
          ["Particulars", p.particulars],
          ["Expense By", p.expenseBy],
          ["CA Email", p.caEmail],
          ["Added User", p.addedUser],
          ["Added Time", <span className="mono">{p.addedTime}</span>],
          ["ID", <span className="mono zc-id">{p.id}</span>],
        ]} />

        <h3 className="zc-secttl">Commercials</h3>
        <KV rows={[
          ["Original Amount", <span className="mono">{money(p.originalAmount)}</span>],
          ["Amount", <span className="mono">{money(c.gross)}</span>],
          ["GST", byId(TAXES, p.gst)?.name],
          ["GST Amount", <span className="mono">{money(c.gstAmount)}</span>],
          ["TDS", byId(TDS_LIST, p.tds)?.name],
          ["TDS Amount", <span className="mono">{money(c.tdsAmount)}</span>],
          ["Invoice Amount", <span className="mono">{money(c.invoice)}</span>],
          ["Payable Amount", <span className="mono strong">{money(c.payable)}</span>],
          ["Total Split Amount", <span className="mono">{money(c.allocated)}</span>],
          ["Paid Amount", p.paidAmountFlag ? "Yes" : "No"],
          ["GST Needed", p.gstNeeded ? "Yes" : "No"],
        ]} />
        {p.backendPath && (
          <p className="zc-inlinewarn">Payable Amount computed on the Backend columns with inverted
            signs — Amount − GST + TDS — because the source bill was Partially Paid (§7.2).</p>
        )}

        <h3 className="zc-secttl">Split Payment
          <span className={"zc-tally" + (c.balanced ? " ok" : " bad")}>
            {money(c.allocated)} of {money(c.gross)}
          </span>
        </h3>
        <table className="zc-sub">
          <thead>
            <tr>
              <th>Villa Name</th><th>Item Category</th><th>Billing Cycle</th>
              <th className="num">Gross Amount</th><th className="num">TDS Amount</th>
              <th className="num">GST Amount</th><th className="num">Total Amount</th>
            </tr>
          </thead>
          <tbody>
            {p.splitPayments.map((r) => {
              const d = splitDerive(r, c.gstPct, c.tdsPct);
              return (
                <tr key={r.id}>
                  <td>{villaName(r.villaName)}</td>
                  <td>{catName(r.itemCategory)}</td>
                  <td className="nowrap">{cycleLabel(r.billingCycle)}</td>
                  <td className="mono num">{money3(r.amount)}</td>
                  <td className="mono num">{money(d.tds)}</td>
                  <td className="mono num">{money(d.gst)}</td>
                  <td className="mono num">{money(d.total)}</td>
                </tr>
              );
            })}
            {p.splitPayments.length === 0 && <tr><td colSpan={7} className="zc-empty">No rows</td></tr>}
            <tr className="tot">
              <td colSpan={3}>Total</td>
              <td className="mono num">{money3(c.allocated)}</td>
              <td className="mono num">{money(c.tdsAmount)}</td>
              <td className="mono num">{money(c.gstAmount)}</td>
              <td className="mono num">{money(c.payable)}</td>
            </tr>
          </tbody>
        </table>
        {!c.balanced && c.hasSplits && (
          <p className="zc-inlinewarn">Split total is off Amount by {money(c.remainder)}. Creator does not
            check this on Payments (§7.4) — every Expenses_Bills leg generated from these rows inherits
            the error. Enforce server-side.</p>
        )}

        <h3 className="zc-secttl">Bill Payments</h3>
        <table className="zc-sub">
          <thead><tr><th>Bill No</th><th className="num">Bill Amount</th></tr></thead>
          <tbody>
            {p.billPayments.map((r) => (
              <tr key={r.id}><td className="mono">{r.billNo}</td><td className="mono num">{money(r.billAmount)}</td></tr>
            ))}
            {p.billPayments.length === 0 && <tr><td colSpan={2} className="zc-empty">No rows</td></tr>}
          </tbody>
        </table>

        {(p.pt !== "" || p.esic !== "" || p.pf !== "") && (
          <>
            <h3 className="zc-secttl">Statutory Deductions</h3>
            <KV rows={[
              ["PT", <span className="mono">{money(p.pt)}</span>],
              ["ESIC", <span className="mono">{money(p.esic)}</span>],
              ["PF", <span className="mono">{money(p.pf)}</span>],
            ]} />
          </>
        )}

        <h3 className="zc-secttl">Bills</h3>
        <KV rows={[
          ["Bills doc", p.billsDoc || ""],
          ["Supporting Documents", p.supportingDocuments || ""],
        ]} />
        <table className="zc-sub">
          <thead><tr><th>Document URL</th></tr></thead>
          <tbody>
            {p.docs.map((d) => <tr key={d.id}><td className="zc-url">{d.url}</td></tr>)}
            {p.docs.length === 0 && <tr><td className="zc-empty">No rows</td></tr>}
          </tbody>
        </table>

        <h3 className="zc-secttl">Approval</h3>
        <KV rows={[
          ["Approver 1", byId(EMPLOYEES, p.approver1)?.name ?? ""],
          ["Approver 2", byId(EMPLOYEES, p.approver2)?.name ?? ""],
          ["Approver 3", byId(EMPLOYEES, p.approver3)?.name ?? ""],
          ["Approved", p.approved ? "Yes" : "No"],
          ["Approved Persons", p.approvedPersons],
          ["Messageid Level 1", <span className="mono">{p.messageidLevel1}</span>],
          ["Messageid Level 2", <span className="mono">{p.messageidLevel2}</span>],
        ]} />
        {(p.status === "Sent for Approval" || p.status === "Send for Approval") && (
          <p className="zc-inlinewarn">Approval state appears in six places at once and they disagree —
            Approver 1/2/3 empty, Approved false, Messageid Level 2 set with Level 1 empty, and the real
            approval sitting in Particulars free text (§8.4). One representation has to win.</p>
        )}

        {settled && (
          <p className="zc-inlinewarn"><b>Delete Paid Payment</b> is live in the More menu on this settled
            payment. Here it raises a reversing entry and keeps {p.paymentNo} intact (§7.6).</p>
        )}

        <p className="zc-addcomment">Add a comment</p>
      </div>
    </aside>
  );
}

const KV = ({ rows }) => (
  <table className="zc-kv"><tbody>
    {rows.map(([k, v], i) => <tr key={i}><th>{k}</th><td>{v ?? ""}</td></tr>)}
  </tbody></table>
);

/* ══ form ═══════════════════════════════════════════════════════════════ */

const blank = (n) => ({
  id: uid(), paymentNo: `EKS/PY/${20800 + n}`, addedTime: stamp(),
  vendor: "", coa: "c1", bank: "", status: "Draft", paymentStatus: "Pending",
  requestedDate: new Date().toISOString().slice(0, 10), paymentDate: "", dueDate: "",
  villas: [], itemCategories: [], billingCycles: [],
  location: "", headOffice: "", bookingNo: "", billNo: "", particulars: "", utr: "",
  amount: "", gst: "t0", tds: "s0", originalAmount: "", backendPath: false,
  paidAmountFlag: false, gstNeeded: false, ocr: "",
  expenseBy: "", addedUser: "ekostay", caEmail: "", pt: "", esic: "", pf: "",
  splitPayments: [], billPayments: [], docs: [],
  approver1: "", approver2: "", approver3: "", approved: false, approvedPersons: "",
  messageidLevel1: "", messageidLevel2: "", billsDoc: "", supportingDocuments: "",
});

function PaymentForm({ initial, n, onCancel, onSave }) {
  const [p, setP] = useState(() => initial ?? blank(n));
  const [errs, setErrs] = useState([]);
  const c = compute(p);
  const set = (patch) => setP((prev) => ({ ...prev, ...patch }));

  /* Villas / Item Category / Billing Cycles change → reconcile, never clear (§5.1).
     Location derives from the villas, as Bills and Payment both do. */
  const setScope = (patch) => setP((prev) => {
    const next = { ...prev, ...patch };
    next.splitPayments = reconcileSplit(prev.splitPayments, next.villas, next.itemCategories, next.billingCycles);
    next.location = [...new Set(next.villas.map((v) => byId(VILLAS, v)?.location).filter(Boolean))].join(", ");
    return next;
  });

  const setSplitAmount = (id, v) =>
    setP((prev) => ({ ...prev, splitPayments: prev.splitPayments.map((r) => (r.id === id ? { ...r, amount: v } : r)) }));
  const dropSplit = (id) =>
    setP((prev) => ({ ...prev, splitPayments: prev.splitPayments.filter((r) => r.id !== id) }));

  const isSalary = p.itemCategories.includes("i5");
  const orphans = p.splitPayments.filter((r) => r.orphan);

  const submit = () => {
    const e = [];
    if (!p.vendor) e.push("Vendor Name is required.");
    if (!p.coa) e.push("COA is required.");
    if (!p.villas.length) e.push("Villa Name is required.");
    if (!p.itemCategories.length) e.push("Item Category is required.");
    if (!p.billingCycles.length) e.push("Billing Cycles is required.");
    if (!(+p.amount > 0)) e.push("Amount must be greater than zero.");
    if (orphans.length) e.push(`${orphans.length} split row(s) no longer match the selected scope but still carry an amount. Clear or restore them.`);
    setErrs(e);
    if (e.length) return;
    onSave({ ...p, addedTime: p.addedTime || stamp() });
  };

  return (
    <div className="zc-modalback">
      <div className="zc-modal">
        <div className="zc-modalbar">
          <span>{initial ? "Edit Payment" : "Payment"}</span>
          <button className="zc-iconbtn" onClick={onCancel} aria-label="Close">✕</button>
        </div>

        <div className="zc-modalbody">
          <h3 className="zc-fsect">Overview</h3>
          <div className="zc-form2">
            <div>
              <FRow label="Payment No"><input className="zc-in mono" value={p.paymentNo} disabled /></FRow>
              <FRow label="Vendor Name" req>
                <select className="zc-in" value={p.vendor} onChange={(e) => set({ vendor: e.target.value })}>
                  <option value="">— Select —</option>
                  {VENDORS.filter((v) => v.primary).map((v) => <option key={v.id} value={v.id}>{v.name}</option>)}
                </select>
              </FRow>
              <FRow label="COA" req>
                <select className="zc-in" value={p.coa} onChange={(e) => set({ coa: e.target.value })}>
                  <option value="">— Select —</option>
                  {COA_LIST.filter((x) => x.hide).map((x) => <option key={x.id} value={x.id}>{x.name}</option>)}
                </select>
              </FRow>
              <FRow label="Bank Name">
                <select className="zc-in" value={p.bank} onChange={(e) => set({ bank: e.target.value })}>
                  <option value="">— Select —</option>
                  {BANKS.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
                </select>
              </FRow>
              <FRow label="Bill No"><input className="zc-in mono" value={p.billNo} onChange={(e) => set({ billNo: e.target.value })} /></FRow>
              <FRow label="Booking No"><input className="zc-in mono" value={p.bookingNo} onChange={(e) => set({ bookingNo: e.target.value })} /></FRow>
              <FRow label="Villa Name" req>
                <MultiBox options={VILLAS.map((v) => ({ id: v.id, label: v.name, meta: v.location }))}
                  value={p.villas} onChange={(villas) => setScope({ villas })} placeholder="Select villas" />
              </FRow>
              <FRow label="Location"><input className="zc-in" value={p.location} disabled /></FRow>
              <FRow label="Head Office">
                <select className="zc-in" value={p.headOffice} onChange={(e) => set({ headOffice: e.target.value })}>
                  <option value="">— Select —</option>
                  {HEAD_OFFICES.map((h) => <option key={h}>{h}</option>)}
                </select>
              </FRow>
            </div>
            <div>
              <FRow label="Requested Date"><DateBox value={p.requestedDate} onChange={(requestedDate) => set({ requestedDate })} /></FRow>
              <FRow label="Payment Date"><DateBox value={p.paymentDate} onChange={(paymentDate) => set({ paymentDate })} /></FRow>
              <FRow label="Due Date"><DateBox value={p.dueDate} onChange={(dueDate) => set({ dueDate })} /></FRow>
              <FRow label="Item Category" req>
                <MultiBox options={ITEM_CATEGORIES.map((i) => ({ id: i.id, label: i.name }))}
                  value={p.itemCategories} onChange={(itemCategories) => setScope({ itemCategories })} placeholder="Select categories" />
              </FRow>
              <FRow label="Master Category">
                <div className="zc-derived">
                  {masterOf(p.itemCategories).map((m) => <span className="zc-tag" key={m}>{byId(MASTER_CATEGORIES, m)?.name}</span>)}
                  {!p.itemCategories.length && <span className="zc-ph">derives from Item Category</span>}
                </div>
              </FRow>
              <FRow label="Billing Cycles" req>
                <MultiBox options={BILLING_CYCLES.map((b) => ({ id: b.id, label: b.label }))}
                  value={p.billingCycles} onChange={(billingCycles) => setScope({ billingCycles })} placeholder="Select cycles" />
              </FRow>
              <FRow label="Status">
                <select className="zc-in" value={p.status} onChange={(e) => set({ status: e.target.value })}>
                  {STATUSES.map((s) => <option key={s}>{s}</option>)}
                </select>
              </FRow>
              <FRow label="Payment Status">
                <select className="zc-in" value={p.paymentStatus} onChange={(e) => set({ paymentStatus: e.target.value })}>
                  {PAYMENT_STATUSES.map((s) => <option key={s}>{s}</option>)}
                </select>
              </FRow>
              <FRow label="Expense By"><input className="zc-in" value={p.expenseBy} onChange={(e) => set({ expenseBy: e.target.value })} /></FRow>
              <FRow label="CA Email"><input className="zc-in" value={p.caEmail} onChange={(e) => set({ caEmail: e.target.value })} /></FRow>
            </div>
          </div>

          <h3 className="zc-fsect">Commercials</h3>
          <div className="zc-form2">
            <div>
              <FRow label="Original Amount">
                <input className="zc-in num mono" value={p.originalAmount} onChange={(e) => set({ originalAmount: e.target.value })} />
              </FRow>
              <FRow label="Amount" req>
                <input className={"zc-in num mono" + (errs.length && !(+p.amount > 0) ? " bad" : "")}
                  value={p.amount} onChange={(e) => set({ amount: e.target.value })} placeholder="0.00" />
              </FRow>
              <FRow label="GST">
                <select className="zc-in" value={p.gst} onChange={(e) => set({ gst: e.target.value, gstNeeded: e.target.value !== "t0" })}>
                  {TAXES.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
                </select>
              </FRow>
              <FRow label="GST Amount"><div className="zc-ro mono">{money(c.gstAmount)}</div></FRow>
              <FRow label="GST Needed">
                <label className="zc-check"><input type="checkbox" checked={p.gstNeeded}
                  onChange={(e) => set({ gstNeeded: e.target.checked })} /> Yes</label>
              </FRow>
            </div>
            <div>
              <FRow label="TDS">
                <select className="zc-in" value={p.tds} onChange={(e) => set({ tds: e.target.value })}>
                  {TDS_LIST.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
                </select>
              </FRow>
              <FRow label="TDS Amount"><div className="zc-ro mono">{money(c.tdsAmount)}</div></FRow>
              <FRow label="Invoice Amount"><div className="zc-ro mono">{money(c.invoice)}</div></FRow>
              <FRow label="Payable Amount"><div className="zc-ro mono strong">{money(c.payable)}</div></FRow>
              <FRow label="Paid Amount">
                <label className="zc-check"><input type="checkbox" checked={p.paidAmountFlag}
                  onChange={(e) => set({ paidAmountFlag: e.target.checked })} /> Yes</label>
              </FRow>
              <FRow label="Haewaya UTR Number">
                <input className="zc-in mono" value={p.utr} onChange={(e) => set({ utr: e.target.value })} placeholder="118103052206,15038" />
              </FRow>
              <FRow label="OCR">
                <div className="zc-derived"><span className="zc-ph">Upload payment screenshot — native OCR field</span></div>
              </FRow>
            </div>
          </div>
          <FRow label="Particulars">
            <textarea className="zc-in zc-ta" rows={2} value={p.particulars} onChange={(e) => set({ particulars: e.target.value })} />
          </FRow>

          {isSalary && (
            <>
              <h3 className="zc-fsect">Statutory Deductions
                <span className="zc-hint">rendered for STAFF SALARY — trigger unconfirmed (§7.5)</span>
              </h3>
              <div className="zc-form2">
                <div>
                  <FRow label="PT"><input className="zc-in num mono" value={p.pt} onChange={(e) => set({ pt: e.target.value })} /></FRow>
                  <FRow label="ESIC"><input className="zc-in num mono" value={p.esic} onChange={(e) => set({ esic: e.target.value })} /></FRow>
                </div>
                <div>
                  <FRow label="PF"><input className="zc-in num mono" value={p.pf} onChange={(e) => set({ pf: e.target.value })} /></FRow>
                </div>
              </div>
            </>
          )}

          <h3 className="zc-fsect">Split Payment
            {c.hasSplits && (
              <span className={"zc-tally" + (c.balanced ? " ok" : " bad")}>
                {money(c.allocated)} of {money(c.gross)}
              </span>
            )}
          </h3>
          <table className="zc-subedit">
            <thead>
              <tr>
                <th>Villa Name</th><th>Item Category</th><th>Billing Cycle</th>
                <th className="num" style={{ width: 130 }}>Gross Amount</th>
                <th className="num" style={{ width: 110 }}>TDS Amount</th>
                <th className="num" style={{ width: 110 }}>GST Amount</th>
                <th className="num" style={{ width: 120 }}>Total Amount</th>
                <th style={{ width: 28 }} />
              </tr>
            </thead>
            <tbody>
              {p.splitPayments.map((r) => {
                const d = splitDerive(r, c.gstPct, c.tdsPct);
                return (
                  <tr key={r.id} className={r.orphan ? "orph" : ""}>
                    <td className="zc-cell">{villaName(r.villaName)}</td>
                    <td className="zc-cell">{catName(r.itemCategory) || <span className="zc-ph">—</span>}</td>
                    <td className="zc-cell nowrap">{cycleLabel(r.billingCycle) || <span className="zc-ph">—</span>}</td>
                    <td><input className="zc-in num mono" value={r.amount}
                      onChange={(e) => setSplitAmount(r.id, e.target.value)} placeholder="0.00" /></td>
                    <td className="zc-cell num mono">{money(d.tds)}</td>
                    <td className="zc-cell num mono">{money(d.gst)}</td>
                    <td className="zc-cell num mono">{money(d.total)}</td>
                    <td><button className="zc-x" onClick={() => dropSplit(r.id)} aria-label="Remove row">✕</button></td>
                  </tr>
                );
              })}
              {p.splitPayments.length === 0 && (
                <tr><td colSpan={8} className="zc-empty">
                  Select Villa Name, Item Category and Billing Cycles — rows generate as the cross-product.
                </td></tr>
              )}
            </tbody>
          </table>
          {orphans.length > 0 && (
            <p className="zc-inlinewarn">{orphans.length} row(s) shaded amber no longer match the selected
              Villa Name × Item Category × Billing Cycle scope but still hold an amount. Creator would have
              cleared them and lost the figures; they are kept here and block Submit until resolved (§5.1).</p>
          )}

          <h3 className="zc-fsect">Bill Payments</h3>
          <table className="zc-subedit">
            <thead><tr><th>Bill No</th><th className="num" style={{ width: 140 }}>Bill Amount</th><th style={{ width: 28 }} /></tr></thead>
            <tbody>
              {p.billPayments.map((r) => (
                <tr key={r.id}>
                  <td><input className="zc-in mono" value={r.billNo}
                    onChange={(e) => setP((prev) => ({ ...prev, billPayments: prev.billPayments.map((x) => x.id === r.id ? { ...x, billNo: e.target.value } : x) }))} /></td>
                  <td><input className="zc-in num mono" value={r.billAmount}
                    onChange={(e) => setP((prev) => ({ ...prev, billPayments: prev.billPayments.map((x) => x.id === r.id ? { ...x, billAmount: e.target.value } : x) }))} /></td>
                  <td><button className="zc-x" aria-label="Remove row"
                    onClick={() => setP((prev) => ({ ...prev, billPayments: prev.billPayments.filter((x) => x.id !== r.id) }))}>✕</button></td>
                </tr>
              ))}
              {p.billPayments.length === 0 && <tr><td colSpan={3} className="zc-empty">No rows</td></tr>}
            </tbody>
          </table>
          <button className="zc-addnew"
            onClick={() => setP((prev) => ({ ...prev, billPayments: [...prev.billPayments, { id: uid(), billNo: "", billAmount: "" }] }))}>
            + Add Bill Payments
          </button>

          <h3 className="zc-fsect">Bills</h3>
          <div className="zc-form2">
            <div>
              <FRow label="Bills doc"><div className="zc-derived"><span className="zc-ph">Single upload — S3</span></div></FRow>
            </div>
            <div>
              <FRow label="Supporting Documents"><div className="zc-derived"><span className="zc-ph">Single upload — S3</span></div></FRow>
            </div>
          </div>
          <table className="zc-subedit">
            <thead><tr><th>Document URL</th><th style={{ width: 28 }} /></tr></thead>
            <tbody>
              {p.docs.map((d) => (
                <tr key={d.id}>
                  <td><input className="zc-in mono" value={d.url}
                    onChange={(e) => setP((prev) => ({ ...prev, docs: prev.docs.map((x) => x.id === d.id ? { ...x, url: e.target.value } : x) }))} /></td>
                  <td><button className="zc-x" aria-label="Remove row"
                    onClick={() => setP((prev) => ({ ...prev, docs: prev.docs.filter((x) => x.id !== d.id) }))}>✕</button></td>
                </tr>
              ))}
              {p.docs.length === 0 && <tr><td colSpan={2} className="zc-empty">No rows</td></tr>}
            </tbody>
          </table>
          <button className="zc-addnew"
            onClick={() => setP((prev) => ({ ...prev, docs: [...prev.docs, { id: uid(), url: "" }] }))}>+ Add Bills</button>

          {errs.length > 0 && (
            <div className="zc-errbox"><b>Cannot submit</b><ul>{errs.map((e, i) => <li key={i}>{e}</li>)}</ul></div>
          )}
        </div>

        <div className="zc-modalfoot">
          <button className="zc-btn zc-btn-pri" onClick={submit}>Submit</button>
          <button className="zc-btn zc-btn-out" onClick={onCancel}>Cancel</button>
          {!c.balanced && c.hasSplits && (
            <span className="zc-footnote">Split total off Amount by {money(c.remainder)} — Creator allows this on Payments.</span>
          )}
        </div>
      </div>
    </div>
  );
}

/* ══ reverse instead of delete (§7.6) ═══════════════════════════════════ */

function ReverseDialog({ p, onCancel, onConfirm }) {
  const [reason, setReason] = useState("");
  const settled = p.paymentStatus === "paid" || p.status === "Paid";
  return (
    <div className="zc-modalback">
      <div className="zc-modal zc-modal-sm">
        <div className="zc-modalbar">
          <span>Delete Paid Payment</span>
          <button className="zc-iconbtn" onClick={onCancel} aria-label="Close">✕</button>
        </div>
        <div className="zc-modalbody">
          <p className="zc-inlinewarn" style={{ marginTop: 0 }}>
            {settled
              ? <>Payment <b className="mono">{p.paymentNo}</b> is settled. A hard delete would drop the record and
                free its number — prior field notes record 17 real payments, ₹93,884, lost this way. This raises a
                reversing entry instead: negative amounts, this reason attached, {p.paymentNo} left intact.</>
              : <>Payment <b className="mono">{p.paymentNo}</b> is not settled, so Creator would allow a delete here.
                A reversing entry is still the safer path.</>}
          </p>
          <KV rows={[
            ["Payment No", <span className="mono">{p.paymentNo}</span>],
            ["Vendor Name", vendorName(p.vendor)],
            ["Amount", <span className="mono">{money(p.amount)}</span>],
            ["Payment Date", <span className="mono">{dmy(p.paymentDate)}</span>],
            ["Status", <StatusChip v={p.status} />],
          ]} />
          <FRow label="Reason" req>
            <textarea className="zc-in zc-ta" rows={3} value={reason} onChange={(e) => setReason(e.target.value)}
              placeholder="Why is this payment being reversed?" />
          </FRow>
        </div>
        <div className="zc-modalfoot">
          <button className="zc-btn zc-btn-pri" disabled={!reason.trim()} onClick={() => onConfirm(reason.trim())}>
            Create Reversing Entry
          </button>
          <button className="zc-btn zc-btn-out" onClick={onCancel}>Cancel</button>
        </div>
      </div>
    </div>
  );
}

/* ══ shared controls ════════════════════════════════════════════════════ */

function stamp() {
  const d = new Date();
  const q = (x) => String(x).padStart(2, "0");
  return `${q(d.getDate())}-${MA[d.getMonth()]}-${d.getFullYear()} ${q(d.getHours())}:${q(d.getMinutes())}:${q(d.getSeconds())}`;
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
    bell: <><path d="M18 8a6 6 0 10-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9" /><path d="M10.3 21a2 2 0 003.4 0" /></>,
    user: <><circle cx="12" cy="8" r="4" /><path d="M4 21c0-4 3.6-6 8-6s8 2 8 6" /></>,
    search: <><circle cx="11" cy="11" r="7" /><path d="M20 20l-4.3-4.3" /></>,
    eye: <><path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6-10-6-10-6z" /><circle cx="12" cy="12" r="2.5" /></>,
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

.zc-main{display:flex; flex-direction:column; min-width:0; min-height:0; background:var(--white)}
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
.zc-grid tbody tr{cursor:pointer}
.zc-grid tbody tr:nth-child(even) td{background:#fafbfc}
.zc-grid tbody tr:hover td{background:var(--pinkl)}
.zc-grid tbody tr.sel td{background:var(--pinkl); box-shadow:inset 0 -1px 0 var(--pink)}
.zc-c-eye,.zc-c-chk{width:28px; text-align:center; color:var(--ink4); padding:0 !important}
.zc-c-chk input{accent-color:var(--pink); margin:0}
.zc-dots{color:var(--pink); font-weight:700; letter-spacing:1px}
.zc-id{color:var(--ink3); font-size:11px}
.zc-off{color:var(--bad)}
.zc-status{display:inline-block; padding:1px 6px; border-radius:2px; font-size:11.5px; font-weight:500; white-space:nowrap}
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
  box-shadow:0 6px 18px rgba(32,36,46,.14); padding:3px; min-width:168px; z-index:5}
.zc-menu button{width:100%; text-align:left; font:inherit; font-size:12.5px; padding:6px 9px; border:0; background:none; cursor:pointer; color:var(--ink2)}
.zc-menu button:hover{background:var(--bg)}
.zc-panelbody{overflow-y:auto; padding:12px 16px 28px}
.zc-secttl{margin:17px 0 6px; font-size:13px; font-weight:600; padding-bottom:4px; border-bottom:1px solid var(--line);
  display:flex; align-items:center; gap:12px}
.zc-secttl:first-child{margin-top:0}
.zc-kv{width:100%; border-collapse:collapse; font-size:12.5px; table-layout:fixed}
.zc-kv th{width:200px; text-align:left; font-weight:400; color:var(--ink2); background:#fafbfc; padding:6px 9px; border:1px solid var(--line)}
.zc-kv td{padding:6px 9px; border:1px solid var(--line); word-break:break-word}
.zc-sub{width:100%; border-collapse:collapse; font-size:12px}
.zc-sub th{text-align:left; font-weight:600; color:var(--ink2); background:#fafbfc; padding:5px 8px; border:1px solid var(--line); white-space:nowrap}
.zc-sub th.num{text-align:right}
.zc-sub td{padding:5px 8px; border:1px solid var(--line)}
.zc-sub td.num{white-space:nowrap}
.zc-sub tr.tot td{background:#fafbfc; font-weight:600}
.zc-url{font-family:var(--mono); font-size:11px; word-break:break-all; color:var(--ink2)}
.zc-inlinewarn{margin:9px 0 0; font-size:12.5px; color:var(--bad); background:var(--badbg); border:1px solid #f1cdc9; border-radius:3px; padding:6px 9px; line-height:1.5}
.zc-inlineinfo{margin:0 0 10px; font-size:12.5px; color:var(--info); background:var(--infobg); border:1px solid #cfdff5; border-radius:3px; padding:6px 9px}
.zc-addcomment{margin:20px 0 0; color:var(--pink); font-size:12.5px; cursor:pointer}

.zc-modalback{position:fixed; inset:0; background:rgba(32,36,46,.35); z-index:60; display:grid; place-items:start center; padding:18px}
.zc-modal{background:var(--white); width:min(1220px,100%); max-height:calc(100vh - 36px); border-radius:4px;
  box-shadow:0 18px 50px rgba(32,36,46,.28); display:flex; flex-direction:column}
.zc-modal-sm{width:min(620px,100%)}
.zc-modalbar{flex:none; display:flex; align-items:center; justify-content:space-between; padding:10px 16px;
  border-bottom:1px solid var(--line); background:#fafbfc; font-size:15px; font-weight:500}
.zc-modalbody{overflow-y:auto; padding:14px 18px 20px}
.zc-modalfoot{flex:none; display:flex; gap:8px; align-items:center; padding:10px 18px; border-top:1px solid var(--line)}
.zc-footnote{font-size:12px; color:var(--bad)}
.zc-form2{display:grid; grid-template-columns:1fr 1fr; gap:0 40px}
.zc-frow{display:grid; grid-template-columns:150px minmax(0,1fr); align-items:start; gap:10px; padding:4px 0; min-height:32px}
.zc-frow > label{font-size:12.5px; color:var(--ink2); padding-top:5px}
.zc-fctl{min-width:0}
.zc-in{font:inherit; font-size:12.5px; height:27px; padding:0 6px; border:1px solid var(--line2); border-radius:3px;
  background:var(--white); color:var(--ink); width:100%; max-width:280px}
.zc-in.num{text-align:right}
.zc-in:focus{border-color:var(--pink); outline:0}
.zc-in:disabled{background:#f6f7f9; color:var(--ink3); cursor:not-allowed}
.zc-in.bad{border-color:var(--bad); background:var(--badbg)}
.zc-ta{height:auto; padding:5px 6px; max-width:560px; resize:vertical; font-family:var(--sans)}
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
.zc-hint{font-weight:400; font-size:11.5px; color:var(--ink3)}
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
.zc-addnew{margin-top:6px; font:inherit; font-size:12.5px; color:var(--pink); background:none; border:0; cursor:pointer; padding:3px 2px}
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
