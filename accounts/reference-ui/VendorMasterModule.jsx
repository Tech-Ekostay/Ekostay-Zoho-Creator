import React, { useState, useMemo, useRef, useEffect } from "react";

/* ═══════════════════════════════════════════════════════════════
   Vendor Master — structural replica of the Zoho Creator screens.

   Sections and field labels follow the Vendor_Master form definition:
     Section (unnamed) · Employee Details · Account Details · Merge Vendor
   Employee Details renders only when the Employee checkbox is ticked.
   "Vendor Master" and "All Vendor Masters" are the same form on two reports.
   ═══════════════════════════════════════════════════════════════ */

const LOCATIONS = ["Mumbai", "Lonavala", "Alibaug", "Karjat", "Igatpuri", "Panchgani", "Goa", "Ooty And Coonoor", "Kodaikanal", "Head Office Central"];
const STATES = ["Maharashtra", "Goa", "Tamil Nadu", "Karnataka", "Uttarakhand", "Gujarat"];
const SOURCES = ["Manual", "Haewaya"];
const ENTITIES = ["Ekostay Hospitality", "Ekostay LLP"];
const DESIGNATIONS = ["caretaker", "property manager", "accounts head", "Account Team-Senior", "Account Team-Executive", "administrator", "Central Operations"];
const MARITAL = ["Single", "Married"];
const GENDERS = ["Male", "Female"];
const COUNTRIES = ["India"];

const MASTER_CATEGORIES = [
  ["m1", "Utilities"], ["m2", "Property Repair & Maintenance"], ["m3", "Housekeeping"],
  ["m4", "Finance & Legal"], ["m5", "Employee & Staff Considerations"], ["m6", "Operations & Logistics"], ["m7", "F&B"],
].map(([id, name]) => ({ id, name }));

const ITEM_CATEGORIES = [
  ["i1", "ELECTRICITY BILL"], ["i2", "LAUNDRY"], ["i3", "OWNER RENT"], ["i4", "OWNER REVENUE"],
  ["i5", "STAFF SALARY"], ["i6", "PLUMBER WORKS"], ["i7", "CARPENTRY"], ["i8", "PAINTING WORKS"],
  ["i9", "HARDWARE MATERIAL"], ["i10", "DEEP CLEANING"], ["i11", "STATIONERY"], ["i12", "STAFF FUEL"],
  ["i13", "F&B GENERAL PURCHASE"], ["i14", "SALES INCENTIVE"], ["i15", "EXPERIENCES INCENTIVE"], ["i16", "STAY REFUND"],
].map(([id, name]) => ({ id, name }));

/* ── formatting ──────────────────────────────────────────────── */

const MA = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];
const dmy = (iso) => { if (!iso) return ""; const [y, m, d] = iso.split("-"); return `${d}-${MA[+m - 1]}-${y}`; };
const parseDmy = (s) => {
  const m = /^(\d{1,2})-([A-Za-z]{3})-(\d{4})$/.exec((s || "").trim());
  if (!m) return "";
  const mi = MA.findIndex((x) => x.toLowerCase() === m[2].toLowerCase());
  return mi < 0 ? "" : `${m[3]}-${String(mi + 1).padStart(2, "0")}-${String(m[1]).padStart(2, "0")}`;
};
/** "11-Aug-2026 11:24:47" -> sortable number, so Added Time orders by date not text. */
const stampToSort = (s) => {
  const m = /^(\d{2})-([A-Za-z]{3})-(\d{4})\s+(\d{2}):(\d{2}):(\d{2})$/.exec(s || "");
  return m ? +`${m[3]}${String(MA.indexOf(m[2]) + 1).padStart(2, "0")}${m[1]}${m[4]}${m[5]}${m[6]}` : 0;
};
const uid = () => Math.random().toString(36).slice(2, 9);
const byId = (a, id) => a.find((x) => x.id === id);
const mcName = (id) => byId(MASTER_CATEGORIES, id)?.name ?? "";
const icName = (id) => byId(ITEM_CATEGORIES, id)?.name ?? "";
const emptyAddress = () => ({ line1: "", line2: "", city: "", stateProvince: "", postalCode: "", country: "" });

/* ── seed ────────────────────────────────────────────────────── */

const V = (o) => ({
  id: o.id, vendorName: o.vendorName, location: o.location ?? "", state: o.state ?? "",
  phoneNumber: o.phoneNumber ?? "", upiId: o.upiId ?? "", source: o.source ?? "", primary: !!o.primary,
  gstNeeded: !!o.gstNeeded, employee: !!o.employee,
  vendorCategory: o.vendorCategory ?? [], masterCategory: o.masterCategory ?? "",
  email: o.email ?? "", gstNo: o.gstNo ?? "", panNo: o.panNo ?? "", documents: "", remarks: o.remarks ?? "",
  entity: o.entity ?? "", employeeDesignation: o.employeeDesignation ?? "", gender: o.gender ?? "",
  dateOfBirth: o.dateOfBirth ?? "", bloodGroup: "", maritalStatus: "", emergencyContactNumber: "",
  fatherName: "", motherName: "", spouseName: "", physicallyChallenged: false,
  dateOfJoining: o.dateOfJoining ?? "", uan: "", pan: o.panNo ?? "", aadhaarNumber: "", pfNumber: "",
  pfJoiningDate: "", epsJoiningDate: "", epsExitDate: "", esiInsuranceNumber: "",
  currentAddress: emptyAddress(), sameAsCurrentAddress: false, permanentAddress: emptyAddress(),
  bankAccountNumber: "", ifscCode: "", bankName: "", aadhaarEnrollmentNumber: "", accountHolderName: "", upiId1: "",
  pf: !!o.pf, pt: !!o.pt, esic: !!o.esic,
  accountDetails: o.accountDetails ?? [],
  primaryVendor: "", secondary: [], booksId: "", vendorLedger: "", mainPrimary: o.mainPrimary ?? "",
  vendorKey: o.vendorKey ?? "",
  addedTime: o.addedTime, addedUser: o.addedUser, modifiedTime: o.modifiedTime ?? o.addedTime, modifiedUser: o.modifiedUser ?? o.addedUser,
});

const SEED = [
  V({ id: "292482000010519060", vendorName: "Poonam Tiwari(Customer)", addedTime: "11-Aug-2026 11:24:47", addedUser: "ekostay", modifiedTime: "11-Aug-2026 11:24:49" }),
  V({ id: "292482000010513162", vendorName: "SUNIL CHANDRIKA BHARDVAJ", mainPrimary: "self", addedTime: "11-Aug-2026 12:14:34", addedUser: "ekostay", modifiedTime: "11-Aug-2026 12:14:35" }),
  V({ id: "292482000010510020", vendorName: "SANTKRUPA STATIONERY AND GIFT", mainPrimary: "self", vendorCategory: ["i11"], addedTime: "10-Aug-2026 18:54:32", addedUser: "ekostay", modifiedTime: "10-Aug-2026 18:54:32" }),
  V({ id: "292482000010509184", vendorName: "Baby Step", mainPrimary: "self", addedTime: "11-Aug-2026 13:29:31", addedUser: "ekostay", modifiedTime: "11-Aug-2026 13:29:32" }),
  V({ id: "292482000010509116", vendorName: "YASHWANT GANU MORE", mainPrimary: "self", addedTime: "11-Aug-2026 12:54:33", addedUser: "ekostay", modifiedTime: "11-Aug-2026 12:54:34" }),
  V({ id: "292482000010509004", vendorName: "DILIP HARIRAM MADNANI", mainPrimary: "self", addedTime: "10-Aug-2026 18:24:01", addedUser: "husain_ekostay1", modifiedTime: "10-Aug-2026 18:24:01" }),
  V({ id: "292482000010505136", vendorName: "Akshay Agarwal(Customer)", addedTime: "11-Aug-2026 16:44:32", addedUser: "ekostay", modifiedTime: "11-Aug-2026 16:44:33" }),
  V({ id: "292482000010503491", vendorName: "KAVITA SUPER MARKET", mainPrimary: "self", vendorCategory: ["i13"], addedTime: "11-Aug-2026 17:04:34", addedUser: "ekostay", modifiedTime: "11-Aug-2026 17:04:35" }),
  V({ id: "292482000010502130", vendorName: "RUPESH ASHOK BAKARE", mainPrimary: "self", addedTime: "11-Aug-2026 11:19:32", addedUser: "ekostay", modifiedTime: "11-Aug-2026 11:19:39" }),
  V({ id: "292482000010500052", vendorName: "ISHRA MUJAFFAR ATTAR", mainPrimary: "self", addedTime: "11-Aug-2026 10:34:34", addedUser: "ekostay", modifiedTime: "11-Aug-2026 10:34:35" }),
  V({ id: "292482000010497128", vendorName: "VISHAKHA PATIL", mainPrimary: "self", addedTime: "10-Aug-2026 17:27:25", addedUser: "husain_ekostay1", modifiedTime: "10-Aug-2026 17:27:25" }),
  V({ id: "292482000010496028", vendorName: "Yash Aanchaliya(Customer)", addedTime: "10-Aug-2026 16:34:32", addedUser: "ekostay", modifiedTime: "10-Aug-2026 16:34:32" }),
  V({ id: "292482000010490409", vendorName: "FIROZ BECHAN SHAIKH", mainPrimary: "self", panNo: "GJYPP4343E", addedTime: "10-Aug-2026 18:12:55", addedUser: "ops.analyst", modifiedTime: "10-Aug-2026 18:12:55" }),
  V({ id: "292482000010490403", vendorName: "Kaif Rajjak Patel(CASABLANCA CT)", mainPrimary: "self", location: "Igatpuri", state: "Maharashtra",
      panNo: "NA", employee: true, masterCategory: "m7", employeeDesignation: "caretaker", entity: "Ekostay LLP",
      gender: "Male", phoneNumber: "+91 96998 26388", dateOfJoining: "2025-04-01", pf: true, esic: true,
      accountDetails: [{ id: uid(), primary: true, bankName: "BANK OF BARODA", accountNo: "3411010004521", accountHolderName: "Kaif Rajjak Patel", bankBranch: "Igatpuri", ifscCode: "BARB0IGATPU" }],
      addedTime: "10-Aug-2026 18:08:55", addedUser: "ops.analyst", modifiedTime: "10-Aug-2026 18:08:56" }),
  V({ id: "292482000010489117", vendorName: "Sandip Waghmare(AMANI CT)", mainPrimary: "self", location: "Karjat", state: "Maharashtra",
      employee: true, masterCategory: "m7", employeeDesignation: "caretaker", entity: "Ekostay LLP", gender: "Male",
      phoneNumber: "+91 73198 42072", dateOfJoining: "2025-06-15", pf: true, pt: true, esic: true,
      accountDetails: [{ id: uid(), primary: true, bankName: "INDIAN POST PAYMENT BANK", accountNo: "0871200099", accountHolderName: "Sandip Waghmare", bankBranch: "Karjat", ifscCode: "IPOS0000001" }],
      addedTime: "10-Aug-2026 15:44:02", addedUser: "ops.analyst", modifiedTime: "10-Aug-2026 15:44:02" }),
  V({ id: "292482000010488004", vendorName: "MAHAVITRAN", mainPrimary: "self", location: "Lonavala", state: "Maharashtra",
      vendorCategory: ["i1"], masterCategory: "m1", gstNeeded: true, gstNo: "27AAACM1234K1Z5", source: "Manual",
      phoneNumber: "+91 22 4004 1122", upiId: "mahavitran@sbi", primary: true,
      addedTime: "09-Aug-2026 12:10:41", addedUser: "ekostay", modifiedTime: "09-Aug-2026 12:10:41" }),
  V({ id: "292482000010486902", vendorName: "Shree Laxmi Laundry Services", mainPrimary: "self", location: "Karjat", state: "Maharashtra",
      vendorCategory: ["i2"], masterCategory: "m3", gstNeeded: true, gstNo: "27AABCS9012L1ZQ", source: "Manual",
      phoneNumber: "+91 90040 77812", upiId: "shreelaxmi@ybl", primary: true,
      accountDetails: [{ id: uid(), primary: true, bankName: "HDFC BANK", accountNo: "50200041234567", accountHolderName: "Shree Laxmi Laundry Services", bankBranch: "Karjat", ifscCode: "HDFC0001234" }],
      addedTime: "09-Aug-2026 10:02:18", addedUser: "ekostay", modifiedTime: "09-Aug-2026 10:02:18" }),
  V({ id: "292482000010485770", vendorName: "PAYAL HARDWARE", mainPrimary: "self", location: "Alibaug", state: "Maharashtra",
      vendorCategory: ["i9", "i8"], masterCategory: "m2", gstNeeded: true, gstNo: "27AACFP4455M1ZT", source: "Manual",
      phoneNumber: "+91 98195 42200", primary: true,
      addedTime: "08-Aug-2026 16:31:09", addedUser: "ekostay", modifiedTime: "08-Aug-2026 16:31:09" }),
  V({ id: "292482000010484633", vendorName: "SHANTADURGA TRADERS", mainPrimary: "self", location: "Goa", state: "Goa",
      vendorCategory: ["i7"], masterCategory: "m2", gstNeeded: true, source: "Haewaya",
      phoneNumber: "+91 832 246 7710", primary: true,
      addedTime: "08-Aug-2026 14:20:55", addedUser: "ekostay", modifiedTime: "08-Aug-2026 14:20:55" }),
  V({ id: "292482000010483500", vendorName: "MAJU AND COMPANY", mainPrimary: "self", location: "Ooty And Coonoor", state: "Tamil Nadu",
      vendorCategory: ["i12"], masterCategory: "m6", source: "Manual", phoneNumber: "+91 423 244 7788", primary: true,
      addedTime: "08-Aug-2026 11:05:33", addedUser: "husain_ekostay1", modifiedTime: "08-Aug-2026 11:05:33" }),
  V({ id: "292482000010482388", vendorName: "MOHD SHADAB", mainPrimary: "self", location: "Lonavala", state: "Maharashtra",
      vendorCategory: ["i13"], masterCategory: "m7", phoneNumber: "+91 82910 55043", upiId: "shadab@ybl",
      addedTime: "07-Aug-2026 18:47:12", addedUser: "ekostay", modifiedTime: "07-Aug-2026 18:47:12" }),
  V({ id: "292482000010481244", vendorName: "Priya Nair", mainPrimary: "self", location: "Head Office Central", state: "Maharashtra",
      employee: true, masterCategory: "m5", employeeDesignation: "Account Team-Executive", entity: "Ekostay Hospitality",
      gender: "Female", vendorCategory: ["i14", "i15"], pf: true, pt: true,
      addedTime: "07-Aug-2026 09:15:27", addedUser: "husain_ekostay1", modifiedTime: "07-Aug-2026 09:15:27" }),
];
SEED.forEach((v) => { if (v.mainPrimary === "self") v.mainPrimary = v.vendorName; });

/* ═══════════════════════════════════════════════════════════════ */

const NAV = [
  ["Accounts", "calc"], ["Payments", "bank"], ["Bank", "bank2"], ["Bills", "bill"], ["Expenses", "exp"],
  ["Schedule Payments", "sched"], ["Expense Observations", "obs"], ["Masters", "mast"],
  ["Settings", "gear"], ["Backend Expenses", "exp"],
];
const MASTER_REPORTS = ["Vendor Master", "All Vendor Masters"];

export default function VendorMasterModule() {
  const [vendors, setVendors] = useState(SEED);
  const [report, setReport] = useState("Vendor Master");
  const [showMenu, setShowMenu] = useState(false);
  const [openId, setOpenId] = useState(null);
  const [editing, setEditing] = useState(null);
  const [search, setSearch] = useState({ field: "Vendor Name", value: "" });
  const [showSearch, setShowSearch] = useState(true);
  const [checked, setChecked] = useState(new Set());
  const [sort, setSort] = useState({ key: "addedTime", dir: "desc" });

  const rows = useMemo(() => {
    let r = vendors;
    if (search.value.trim()) {
      const q = search.value.toLowerCase();
      r = r.filter((v) => (({
        "Vendor Name": v.vendorName, "Main Primary": v.mainPrimary, Location: v.location, State: v.state,
        "Master Category": mcName(v.masterCategory), "GST No.": v.gstNo, "PAN No.": v.panNo,
        "Employee Designation": v.employeeDesignation,
      })[search.field] ?? "").toLowerCase().includes(q));
    }
    const dir = sort.dir === "asc" ? 1 : -1;
    return [...r].sort((a, b) => {
      const g = (x) => {
        if (sort.key === "addedTime" || sort.key === "modifiedTime") return stampToSort(x[sort.key]);
        if (sort.key === "masterCategory") return mcName(x.masterCategory);
        return x[sort.key] ?? "";
      };
      const av = g(a), bv = g(b);
      return (typeof av === "number" ? av - bv : String(av).localeCompare(String(bv))) * dir;
    });
  }, [vendors, search, sort]);

  const save = (v) => {
    setVendors((p) => (p.some((x) => x.id === v.id) ? p.map((x) => (x.id === v.id ? v : x)) : [v, ...p]));
    setEditing(null); setOpenId(v.id);
  };
  const open = openId ? vendors.find((v) => v.id === openId) : null;
  const openIdx = rows.findIndex((v) => v.id === openId);

  return (
    <>
      <Style />
      <div className="zc">
        <aside className="zc-rail">
          <div className="zc-logo">ACC</div>
          {NAV.map(([label, icon]) => {
            const on = label === "Masters";
            return (
              <div key={label} className="zc-navwrap"
                onMouseEnter={() => on && setShowMenu(true)} onMouseLeave={() => on && setShowMenu(false)}>
                <button className={"zc-navitem" + (on ? " on" : "")}><Icon name={icon} /><span>{label}</span></button>
                {on && showMenu && (
                  <div className="zc-submenu">
                    {MASTER_REPORTS.map((r) => (
                      <button key={r} className={r === report ? "on" : ""} onClick={() => { setReport(r); setShowMenu(false); }}>
                        <Icon name="mast" />{r}
                      </button>
                    ))}
                  </div>
                )}
              </div>
            );
          })}
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
            <h1>{report}</h1>
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
                  {["Vendor Name", "Main Primary", "Location", "State", "Master Category", "GST No.", "PAN No.", "Employee Designation"].map((f) => <option key={f}>{f}</option>)}
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
                  <Th k="vendorName" s={sort} set={setSort} w={248}>Vendor Name</Th>
                  <Th k="mainPrimary" s={sort} set={setSort} w={248}>Main Primary</Th>
                  <th style={{ width: 168 }}>Primary Vendor</th>
                  <th style={{ width: 106 }}>Primary Status</th>
                  <Th k="location" s={sort} set={setSort} w={128}>Location</Th>
                  <Th k="masterCategory" s={sort} set={setSort} w={186}>Master Category</Th>
                  <Th k="employeeDesignation" s={sort} set={setSort} w={158}>Employee Designation</Th>
                  <th style={{ width: 84 }}>Employee</th>
                  <Th k="state" s={sort} set={setSort} w={116}>State</Th>
                  <th style={{ width: 168 }}>Email</th>
                  <th style={{ width: 148 }}>GST No.</th>
                  <th style={{ width: 142 }}>Phone</th>
                  <th style={{ width: 208 }}>Account Details</th>
                  <Th k="addedTime" s={sort} set={setSort} w={158}>Added Time</Th>
                  <th style={{ width: 122 }}>Added User</th>
                  <th style={{ width: 168 }}>ID</th>
                  <th style={{ width: 116 }}>PAN No.</th>
                  <th style={{ width: 122 }}>Modified User</th>
                  <Th k="modifiedTime" s={sort} set={setSort} w={158}>Modified Time</Th>
                </tr>
              </thead>
              <tbody>
                {rows.map((v) => (
                  <tr key={v.id} className={openId === v.id ? "sel" : ""} onClick={() => setOpenId(v.id)}>
                    <td className="zc-c-eye">{openId === v.id ? <span className="zc-dots">···</span> : null}</td>
                    <td className="zc-c-chk" onClick={(e) => e.stopPropagation()}>
                      <input type="checkbox" checked={checked.has(v.id)} aria-label={`Select ${v.vendorName}`}
                        onChange={() => setChecked((p) => { const n = new Set(p); n.has(v.id) ? n.delete(v.id) : n.add(v.id); return n; })} />
                    </td>
                    <td title={v.vendorName}>{v.vendorName}</td>
                    <td title={v.mainPrimary}>{v.mainPrimary}</td>
                    <td>{v.primaryVendor}</td>
                    <td>{String(!!v.primary)}</td>
                    <td>{v.location}</td>
                    <td title={mcName(v.masterCategory)}>{mcName(v.masterCategory)}</td>
                    <td>{v.employeeDesignation}</td>
                    <td>{v.employee ? "Yes" : "No"}</td>
                    <td>{v.state}</td>
                    <td title={v.email}>{v.email}</td>
                    <td className="mono">{v.gstNo}</td>
                    <td className="mono zc-phone">{v.phoneNumber}</td>
                    <td title={v.accountDetails.map((a) => a.bankName).join(", ")}>{v.accountDetails.map((a) => a.bankName).join(", ")}</td>
                    <td className="mono nowrap">{v.addedTime}</td>
                    <td>{v.addedUser}</td>
                    <td className="mono zc-id">{v.id}</td>
                    <td className="mono">{v.panNo}</td>
                    <td>{v.modifiedUser}</td>
                    <td className="mono nowrap">{v.modifiedTime}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          <footer className="zc-footer">
            <span>Showing {rows.length} of {vendors.length}</span>
            {checked.size > 0 && <span className="zc-selcount">{checked.size} selected</span>}
          </footer>
        </div>

        {open && (
          <DetailPanel v={open} onClose={() => setOpenId(null)} onEdit={() => setEditing(open)}
            onDelete={() => { setVendors((p) => p.filter((x) => x.id !== open.id)); setOpenId(null); }}
            onPrev={openIdx > 0 ? () => setOpenId(rows[openIdx - 1].id) : null}
            onNext={openIdx < rows.length - 1 ? () => setOpenId(rows[openIdx + 1].id) : null} />
        )}
        {editing && <VendorForm initial={editing === "new" ? null : editing} all={vendors} onCancel={() => setEditing(null)} onSave={save} />}
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

/* ── detail panel ────────────────────────────────────────────── */

function DetailPanel({ v, onClose, onEdit, onDelete, onPrev, onNext }) {
  const [more, setMore] = useState(false);
  useEffect(() => {
    const h = (e) => e.key === "Escape" && onClose();
    window.addEventListener("keydown", h); return () => window.removeEventListener("keydown", h);
  }, [onClose]);

  const addr = (a) => [a.line1, a.line2, a.city, a.stateProvince, a.postalCode, a.country].filter(Boolean).join(", ");

  return (
    <aside className="zc-panel" role="dialog" aria-label={`Vendor ${v.vendorName}`}>
      <header className="zc-panelbar">
        <div className="zc-nav2">
          <button className="zc-iconbtn zc-sq" onClick={onPrev} disabled={!onPrev} aria-label="Previous">‹</button>
          <button className="zc-iconbtn zc-sq" onClick={onNext} disabled={!onNext} aria-label="Next">›</button>
        </div>
        <div className="zc-panelacts">
          <button className="zc-btn zc-btn-out" onClick={onEdit}>Edit</button>
          <button className="zc-btn zc-btn-out" onClick={onDelete}>Delete</button>
          <div className="zc-menuwrap">
            <button className="zc-btn zc-btn-out" onClick={() => setMore((m) => !m)}>More ⌄</button>
            {more && <div className="zc-menu"><button onClick={() => setMore(false)}>Print</button></div>}
          </div>
          <button className="zc-iconbtn zc-sq" onClick={onClose} aria-label="Close">✕</button>
        </div>
      </header>

      <div className="zc-panelbody">
        <KV rows={[
          ["Vendor Name", v.vendorName], ["Location", v.location], ["Master Category", mcName(v.masterCategory)],
          ["State", v.state], ["Email", v.email], ["Phone", v.phoneNumber], ["GST No.", v.gstNo],
          ["Secondary", v.secondary.map((id) => id).join(", ")], ["Books ID", v.booksId],
          ["Vendor Category", v.vendorCategory.map(icName).join(", ")],
          ["Account Details", v.accountDetails.map((a) => a.bankName).join(", ")],
          ["UPI ID", v.upiId], ["Documents", v.documents], ["Remarks", v.remarks],
          ["Vendor Ledger", v.vendorLedger], ["Source", v.source],
        ]} />

        {v.accountDetails.length > 0 && (
          <>
            <h2 className="zc-secttl">Account Details</h2>
            <table className="zc-sub">
              <thead><tr><th>Primary</th><th>Bank Name</th><th>Account No.</th><th>Account Holder Name</th><th>Bank Branch</th><th>IFSC Code</th></tr></thead>
              <tbody>
                {v.accountDetails.map((a) => (
                  <tr key={a.id}><td>{String(!!a.primary)}</td><td>{a.bankName}</td><td className="mono">{a.accountNo}</td>
                    <td>{a.accountHolderName}</td><td>{a.bankBranch}</td><td className="mono">{a.ifscCode}</td></tr>
                ))}
              </tbody>
            </table>
          </>
        )}

        {v.employee && (
          <>
            <h2 className="zc-secttl">Employee Details</h2>
            <KV rows={[
              ["Entity", v.entity], ["Employee Designation", v.employeeDesignation], ["Gender", v.gender],
              ["Date of Birth", dmy(v.dateOfBirth)], ["Blood Group", v.bloodGroup], ["Marital Status", v.maritalStatus],
              ["Emergency Contact Number", v.emergencyContactNumber], ["Father name", v.fatherName],
              ["Mother name", v.motherName], ["Spouse Name", v.spouseName],
              ["Physically Challenged", String(!!v.physicallyChallenged)],
              ["Date of Joining", dmy(v.dateOfJoining)], ["UAN", v.uan], ["PAN", v.pan],
              ["Aadhaar Number", v.aadhaarNumber], ["PF Number", v.pfNumber],
              ["PF Joining Date", dmy(v.pfJoiningDate)], ["EPS Joining Date", dmy(v.epsJoiningDate)],
              ["EPS Exit Date", dmy(v.epsExitDate)], ["ESI Insurance Number", v.esiInsuranceNumber],
              ["Current Address", addr(v.currentAddress)], ["Permanent Address", addr(v.permanentAddress)],
              ["PF", String(!!v.pf)], ["PT", String(!!v.pt)], ["ESIC", String(!!v.esic)],
            ]} />
          </>
        )}

        <p className="zc-addcomment">Add a comment</p>
      </div>
    </aside>
  );
}

const KV = ({ rows }) => (
  <table className="zc-kv"><tbody>
    {rows.map(([k, val]) => <tr key={k}><th>{k}</th><td>{val}</td></tr>)}
  </tbody></table>
);

/* ── form ────────────────────────────────────────────────────── */

const blank = () => ({
  id: String(292482000010520000 + Math.floor(Math.random() * 9999)),
  vendorName: "", location: "", state: "", phoneNumber: "", upiId: "", source: "", primary: false,
  gstNeeded: false, employee: false, vendorCategory: [], masterCategory: "", email: "", gstNo: "", panNo: "",
  documents: "", remarks: "", entity: "", employeeDesignation: "", gender: "", dateOfBirth: "", bloodGroup: "",
  maritalStatus: "", emergencyContactNumber: "", fatherName: "", motherName: "", spouseName: "",
  physicallyChallenged: false, dateOfJoining: "", uan: "", pan: "", aadhaarNumber: "", pfNumber: "",
  pfJoiningDate: "", epsJoiningDate: "", epsExitDate: "", esiInsuranceNumber: "",
  currentAddress: emptyAddress(), sameAsCurrentAddress: false, permanentAddress: emptyAddress(),
  bankAccountNumber: "", ifscCode: "", bankName: "", aadhaarEnrollmentNumber: "", accountHolderName: "", upiId1: "",
  pf: false, pt: false, esic: false, accountDetails: [], primaryVendor: "", secondary: [],
  booksId: "", vendorLedger: "", mainPrimary: "", vendorKey: "",
  addedTime: "", addedUser: "Husain Khatumdi", modifiedTime: "", modifiedUser: "Husain Khatumdi",
});

function VendorForm({ initial, all, onCancel, onSave }) {
  const [v, setV] = useState(() => (initial ? JSON.parse(JSON.stringify(initial)) : blank()));
  const [tried, setTried] = useState(false);
  const set = (patch) => setV((p) => ({ ...p, ...patch }));
  const setAddr = (which, patch) => setV((p) => ({ ...p, [which]: { ...p[which], ...patch } }));

  /** "Same as Current Address" mirrors the current address into the permanent one. */
  const toggleSame = (on) => setV((p) => ({ ...p, sameAsCurrentAddress: on,
    permanentAddress: on ? { ...p.currentAddress } : p.permanentAddress }));

  const errs = [];
  if (!v.vendorName.trim()) errs.push("Vendor Name is required.");
  if (v.gstNeeded && !v.gstNo.trim()) errs.push("GST Needed is checked, so GST No. is required.");
  if (v.employee && !v.employeeDesignation) errs.push("Employee is checked, so Employee Designation is required.");
  if (v.employee && !v.entity) errs.push("Employee is checked, so Entity is required.");
  if (v.accountDetails.length > 1 && v.accountDetails.filter((a) => a.primary).length !== 1)
    errs.push("Exactly one Account Details row must be marked Primary.");
  const dupe = all.find((x) => x.id !== v.id && x.vendorName.trim().toLowerCase() === v.vendorName.trim().toLowerCase());
  if (dupe) errs.push(`A vendor named "${dupe.vendorName}" already exists. Use Merge Vendor rather than creating a duplicate.`);

  const setAD = (id, patch) => set({ accountDetails: v.accountDetails.map((a) => (a.id === id ? { ...a, ...patch } : a)) });
  const submit = () => { setTried(true); if (!errs.length) onSave({ ...v, addedTime: v.addedTime || stamp(), modifiedTime: stamp() }); };

  return (
    <div className="zc-modalback">
      <div className="zc-modal" role="dialog" aria-label="Vendor Master">
        <header className="zc-modalbar">
          <span>Vendor Master</span>
          <button className="zc-iconbtn zc-sq" onClick={onCancel} aria-label="Close">✕</button>
        </header>

        <div className="zc-modalbody">
          <div className="zc-form2">
            <FRow label="Vendor Name" req>
              <input className="zc-in" value={v.vendorName} onChange={(e) => set({ vendorName: e.target.value })} />
            </FRow>
            <FRow label="Vendor Category">
              <MultiBox options={ITEM_CATEGORIES.map((i) => ({ id: i.id, label: i.name }))} value={v.vendorCategory}
                placeholder="-Select-" onChange={(x) => set({ vendorCategory: x })} />
            </FRow>
            <FRow label="Location">
              <select className="zc-in" value={v.location} onChange={(e) => set({ location: e.target.value })}>
                <option value="">-Select-</option>{LOCATIONS.map((l) => <option key={l}>{l}</option>)}
              </select>
            </FRow>
            <FRow label="Master Category">
              <select className="zc-in" value={v.masterCategory} onChange={(e) => set({ masterCategory: e.target.value })}>
                <option value="">-Select-</option>{MASTER_CATEGORIES.map((m) => <option key={m.id} value={m.id}>{m.name}</option>)}
              </select>
            </FRow>
            <FRow label="State">
              <select className="zc-in" value={v.state} onChange={(e) => set({ state: e.target.value })}>
                <option value="">-Select-</option>{STATES.map((s) => <option key={s}>{s}</option>)}
              </select>
            </FRow>
            <FRow label="Email">
              <div className="zc-withicon">
                <input className="zc-in" type="email" value={v.email} onChange={(e) => set({ email: e.target.value })} />
                <span className="zc-affix"><Icon name="mail" /></span>
              </div>
            </FRow>
            <FRow label="Phone"><PhoneBox value={v.phoneNumber} onChange={(x) => set({ phoneNumber: x })} /></FRow>
            <FRow label="GST No.">
              <input className="zc-in mono" value={v.gstNo} onChange={(e) => set({ gstNo: e.target.value.toUpperCase() })} />
            </FRow>
            <FRow label="UPI ID">
              <input className="zc-in" value={v.upiId} onChange={(e) => set({ upiId: e.target.value })} />
            </FRow>
            <FRow label="PAN No.">
              <input className="zc-in mono" value={v.panNo} onChange={(e) => set({ panNo: e.target.value.toUpperCase() })} />
            </FRow>
            <FRow label="Source">
              <select className="zc-in" value={v.source} onChange={(e) => set({ source: e.target.value })}>
                <option value="">-Select-</option>{SOURCES.map((s) => <option key={s}>{s}</option>)}
              </select>
            </FRow>
            <FRow label="Documents">
              <div className="zc-withicon">
                <input className="zc-in" placeholder="Select File" readOnly />
                <span className="zc-affix"><Icon name="upload" /></span>
              </div>
            </FRow>
            <FRow label="">
              <div className="zc-checkstack">
                <label className="zc-check"><input type="checkbox" checked={v.gstNeeded} onChange={(e) => set({ gstNeeded: e.target.checked })} /><span>GST Needed</span></label>
                <label className="zc-check"><input type="checkbox" checked={v.employee} onChange={(e) => set({ employee: e.target.checked })} /><span>Employee</span></label>
                <label className="zc-check"><input type="checkbox" checked={v.primary} onChange={(e) => set({ primary: e.target.checked })} /><span>Primary</span></label>
              </div>
            </FRow>
            <FRow label="Remarks">
              <textarea className="zc-in zc-ta" rows={4} value={v.remarks} onChange={(e) => set({ remarks: e.target.value })} />
            </FRow>
          </div>

          {v.employee && (
            <>
              <h3 className="zc-fsect">Employee Details</h3>
              <div className="zc-form2">
                <FRow label="Entity" req>
                  <select className="zc-in" value={v.entity} onChange={(e) => set({ entity: e.target.value })}>
                    <option value="">-Select-</option>{ENTITIES.map((x) => <option key={x}>{x}</option>)}
                  </select>
                </FRow>
                <FRow label="Date of Joining"><DateBox value={v.dateOfJoining} onChange={(x) => set({ dateOfJoining: x })} /></FRow>
                <FRow label="Employee Designation" req>
                  <select className="zc-in" value={v.employeeDesignation} onChange={(e) => set({ employeeDesignation: e.target.value })}>
                    <option value="">-Select-</option>{DESIGNATIONS.map((d) => <option key={d}>{d}</option>)}
                  </select>
                </FRow>
                <FRow label="UAN"><input className="zc-in mono" value={v.uan} onChange={(e) => set({ uan: e.target.value })} /></FRow>
                <FRow label="Gender">
                  <select className="zc-in" value={v.gender} onChange={(e) => set({ gender: e.target.value })}>
                    <option value="">-Select-</option>{GENDERS.map((g) => <option key={g}>{g}</option>)}
                  </select>
                </FRow>
                <FRow label="PAN"><input className="zc-in mono" value={v.pan} disabled /></FRow>
                <FRow label="Date of Birth"><DateBox value={v.dateOfBirth} onChange={(x) => set({ dateOfBirth: x })} /></FRow>
                <FRow label="Aadhaar Number"><input className="zc-in mono" value={v.aadhaarNumber} onChange={(e) => set({ aadhaarNumber: e.target.value })} /></FRow>
                <FRow label="Blood Group"><input className="zc-in" value={v.bloodGroup} onChange={(e) => set({ bloodGroup: e.target.value })} /></FRow>
                <FRow label="PF Number"><input className="zc-in mono" value={v.pfNumber} onChange={(e) => set({ pfNumber: e.target.value })} /></FRow>
                <FRow label="Marital Status">
                  <select className="zc-in" value={v.maritalStatus} onChange={(e) => set({ maritalStatus: e.target.value })}>
                    <option value="">-Select-</option>{MARITAL.map((m) => <option key={m}>{m}</option>)}
                  </select>
                </FRow>
                <FRow label="PF Joining Date"><DateBox value={v.pfJoiningDate} onChange={(x) => set({ pfJoiningDate: x })} /></FRow>
                <FRow label="Emergency Contact Number"><PhoneBox value={v.emergencyContactNumber} onChange={(x) => set({ emergencyContactNumber: x })} /></FRow>
                <FRow label="EPS Joining Date"><DateBox value={v.epsJoiningDate} onChange={(x) => set({ epsJoiningDate: x })} /></FRow>
                <FRow label="Father name"><input className="zc-in" value={v.fatherName} onChange={(e) => set({ fatherName: e.target.value })} /></FRow>
                <FRow label="EPS Exit Date"><DateBox value={v.epsExitDate} onChange={(x) => set({ epsExitDate: x })} /></FRow>
                <FRow label="Mother name"><input className="zc-in" value={v.motherName} onChange={(e) => set({ motherName: e.target.value })} /></FRow>
                <FRow label="ESI Insurance Number"><input className="zc-in mono" value={v.esiInsuranceNumber} onChange={(e) => set({ esiInsuranceNumber: e.target.value })} /></FRow>
                <FRow label="Spouse Name"><input className="zc-in" value={v.spouseName} onChange={(e) => set({ spouseName: e.target.value })} /></FRow>
                <FRow label="UPI ID"><input className="zc-in" value={v.upiId1} disabled /></FRow>
                <FRow label="">
                  <div className="zc-checkstack">
                    <label className="zc-check"><input type="checkbox" checked={v.physicallyChallenged} onChange={(e) => set({ physicallyChallenged: e.target.checked })} /><span>Physically Challenged</span></label>
                    <label className="zc-check"><input type="checkbox" checked={v.pf} onChange={(e) => set({ pf: e.target.checked })} /><span>PF</span></label>
                    <label className="zc-check"><input type="checkbox" checked={v.pt} onChange={(e) => set({ pt: e.target.checked })} /><span>PT</span></label>
                    <label className="zc-check"><input type="checkbox" checked={v.esic} onChange={(e) => set({ esic: e.target.checked })} /><span>ESIC</span></label>
                  </div>
                </FRow>
              </div>

              <div className="zc-form2 zc-addrgrid">
                <FRow label="Current Address"><AddressBox a={v.currentAddress} onChange={(p) => setAddr("currentAddress", p)} /></FRow>
                <FRow label="Permanent Address">
                  <label className="zc-check zc-samechk">
                    <input type="checkbox" checked={v.sameAsCurrentAddress} onChange={(e) => toggleSame(e.target.checked)} />
                    <span>Same as Current Address</span>
                  </label>
                  <AddressBox a={v.permanentAddress} disabled={v.sameAsCurrentAddress} onChange={(p) => setAddr("permanentAddress", p)} />
                </FRow>
              </div>
            </>
          )}

          <h3 className="zc-fsect">Account Details</h3>
          <table className="zc-subedit">
            <thead><tr><th style={{ width: 70 }}>Primary</th><th style={{ width: 200 }}>Bank Name</th>
              <th style={{ width: 180 }}>Account No.</th><th style={{ width: 210 }}>Account Holder Name</th>
              <th style={{ width: 150 }}>Bank Branch</th><th style={{ width: 150 }}>IFSC Code</th><th style={{ width: 32 }} /></tr></thead>
            <tbody>
              {v.accountDetails.length === 0 && <tr><td colSpan={7} className="zc-empty">No account details added.</td></tr>}
              {v.accountDetails.map((a) => (
                <tr key={a.id}>
                  <td style={{ textAlign: "center" }}>
                    <input type="checkbox" checked={!!a.primary} aria-label="Primary"
                      onChange={(e) => set({ accountDetails: v.accountDetails.map((x) => ({ ...x, primary: x.id === a.id ? e.target.checked : e.target.checked ? false : x.primary })) })} />
                  </td>
                  <td><input className="zc-in" value={a.bankName} onChange={(e) => setAD(a.id, { bankName: e.target.value })} /></td>
                  <td><input className="zc-in mono" value={a.accountNo} onChange={(e) => setAD(a.id, { accountNo: e.target.value })} /></td>
                  <td><input className="zc-in" value={a.accountHolderName} onChange={(e) => setAD(a.id, { accountHolderName: e.target.value })} /></td>
                  <td><input className="zc-in" value={a.bankBranch} onChange={(e) => setAD(a.id, { bankBranch: e.target.value })} /></td>
                  <td><input className="zc-in mono" value={a.ifscCode} onChange={(e) => setAD(a.id, { ifscCode: e.target.value.toUpperCase() })} /></td>
                  <td><button className="zc-x" aria-label="Remove row"
                    onClick={() => set({ accountDetails: v.accountDetails.filter((x) => x.id !== a.id) })}>✕</button></td>
                </tr>
              ))}
            </tbody>
          </table>
          <button className="zc-addnew"
            onClick={() => set({ accountDetails: [...v.accountDetails, { id: uid(), primary: v.accountDetails.length === 0, bankName: "", accountNo: "", accountHolderName: "", bankBranch: "", ifscCode: "" }] })}>＋ Add New</button>

          <h3 className="zc-fsect">Merge Vendor</h3>
          <div className="zc-form2">
            <FRow label="Secondary">
              <MultiBox options={all.filter((x) => x.id !== v.id).map((x) => ({ id: x.id, label: x.vendorName }))}
                value={v.secondary} placeholder="-Select-" onChange={(x) => set({ secondary: x })} />
            </FRow>
            <FRow label="Primary Vendor">
              <select className="zc-in" value={v.primaryVendor} onChange={(e) => set({ primaryVendor: e.target.value })}>
                <option value="">-Select-</option>
                {all.filter((x) => x.id !== v.id).map((x) => <option key={x.id} value={x.id}>{x.vendorName}</option>)}
              </select>
            </FRow>
            <FRow label="Vendor Ledger">
              <input className="zc-in" value={v.vendorLedger} placeholder="https://" onChange={(e) => set({ vendorLedger: e.target.value })} />
            </FRow>
            <FRow label="Books ID">
              <input className="zc-in mono" value={v.booksId} onChange={(e) => set({ booksId: e.target.value.replace(/\D/g, "") })} />
            </FRow>
          </div>

          {tried && errs.length > 0 && (
            <div className="zc-errbox"><b>Cannot submit</b><ul>{errs.map((e) => <li key={e}>{e}</li>)}</ul></div>
          )}
        </div>

        <footer className="zc-modalfoot">
          <button className="zc-btn zc-btn-pri" onClick={submit}>{initial ? "Update" : "Submit"}</button>
          <button className="zc-btn zc-btn-out" onClick={onCancel}>{initial ? "Cancel" : "Reset"}</button>
        </footer>
      </div>
    </div>
  );
}

function stamp() {
  const d = new Date(); const p = (n) => String(n).padStart(2, "0");
  return `${p(d.getDate())}-${MA[d.getMonth()]}-${d.getFullYear()} ${p(d.getHours())}:${p(d.getMinutes())}:${p(d.getSeconds())}`;
}

const FRow = ({ label, req, children }) => (
  <div className="zc-frow"><label>{label}{req && <i className="zc-req">*</i>}</label><div className="zc-fctl">{children}</div></div>
);

/** Composite address field, matching Creator's stacked sub-fields with captions. */
function AddressBox({ a, onChange, disabled }) {
  const F = ({ k, cap, half }) => (
    <div className={"zc-addrf" + (half ? " half" : "")}>
      <input className="zc-in" value={a[k]} disabled={disabled} onChange={(e) => onChange({ [k]: e.target.value })} />
      <span className="zc-cap">{cap}</span>
    </div>
  );
  return (
    <div className="zc-addr">
      <F k="line1" cap="Address Line 1" />
      <F k="line2" cap="Address Line 2" />
      <div className="zc-addrrow"><F k="city" cap="City / District" half /><F k="stateProvince" cap="State / Province" half /></div>
      <div className="zc-addrrow">
        <F k="postalCode" cap="Postal Code" half />
        <div className="zc-addrf half">
          <select className="zc-in" value={a.country} disabled={disabled} onChange={(e) => onChange({ country: e.target.value })}>
            <option value="">-Select-</option>{COUNTRIES.map((c) => <option key={c}>{c}</option>)}
          </select>
          <span className="zc-cap">Country</span>
        </div>
      </div>
    </div>
  );
}

function PhoneBox({ value, onChange }) {
  const local = (value || "").replace(/^\+91\s*/, "");
  return (
    <div className="zc-phonebox">
      <span className="zc-cc">🇮🇳 +91 ▾</span>
      <input className="zc-in mono" value={local} placeholder="81234 56789"
        onChange={(e) => onChange(e.target.value ? "+91 " + e.target.value : "")} />
    </div>
  );
}

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
            {!disabled && <button onClick={(e) => { e.stopPropagation(); onChange(value.filter((x) => x !== id)); }} aria-label="Remove">✕</button>}</span>;
        })}
        {!disabled && <input value={q} onChange={(e) => { setQ(e.target.value); setOpen(true); }}
          onFocus={() => setOpen(true)} placeholder={value.length ? "" : placeholder} />}
      </div>
      {open && avail.length > 0 && (
        <ul className="zc-droplist">
          {avail.slice(0, 10).map((o) => (
            <li key={o.id}><button onClick={() => { onChange([...value, o.id]); setQ(""); }}>{o.label}</button></li>
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
    mail: <><rect x="2.5" y="5" width="19" height="14" rx="2" /><path d="M3 7l9 6 9-6" /></>,
    upload: <><path d="M12 16V4M7 9l5-5 5 5M4 20h16" /></>,
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
  --bad:#c0392b; --badbg:#fdeceb;
  --sans:'Inter',-apple-system,'Segoe UI',Roboto,sans-serif; --mono:'Roboto Mono',ui-monospace,monospace;
  font-family:var(--sans); color:var(--ink); background:var(--bg);
  display:grid; grid-template-columns:104px minmax(0,1fr); height:100vh; overflow:hidden;
  font-size:13px; -webkit-font-smoothing:antialiased;
}
.zc :focus-visible{outline:2px solid var(--pink); outline-offset:1px}
.mono{font-family:var(--mono); font-variant-numeric:tabular-nums; font-size:12px; letter-spacing:-.2px}
.num{text-align:right} .nowrap{white-space:nowrap}

.zc-rail{background:var(--rail); display:flex; flex-direction:column; overflow-y:auto; overflow-x:visible}
.zc-logo{background:var(--pink); color:#fff; font-weight:700; font-size:13px; letter-spacing:.1em; height:46px; display:grid; place-items:center; flex:none}
.zc-navwrap{position:relative; flex:none}
.zc-navitem{width:100%; background:none; border:0; color:#bcc2d2; font:inherit; font-size:10px; line-height:1.3; padding:10px 5px 8px;
  display:grid; justify-items:center; gap:5px; cursor:pointer; text-align:center; word-break:break-word}
.zc-navitem:hover{background:var(--rail2); color:#fff}
.zc-navitem.on{background:var(--pink); color:#fff}
.zc-submenu{position:absolute; left:100%; top:0; z-index:40; background:var(--rail2); min-width:200px;
  box-shadow:4px 4px 14px rgba(0,0,0,.22); padding:4px}
.zc-submenu button{width:100%; display:flex; align-items:center; gap:9px; font:inherit; font-size:12.5px;
  padding:9px 11px; border:0; background:none; color:#dfe3ee; cursor:pointer; text-align:left}
.zc-submenu button:hover{background:var(--pink); color:#fff}
.zc-submenu button.on{background:var(--pink); color:#fff}

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
.zc-reportbar h1{margin:0; font-size:16px; font-weight:500}
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
.zc-th{width:100%; height:31px; display:flex; align-items:center; gap:4px; justify-content:space-between; font:inherit;
  font-weight:600; font-size:11.5px; color:inherit; background:none; border:0; cursor:pointer; padding:0}
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
.zc-phone{color:var(--pink)}
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
.zc-kv{width:100%; border-collapse:collapse; font-size:12.5px; table-layout:fixed}
.zc-kv th{width:210px; text-align:left; font-weight:400; color:var(--ink2); background:#fafbfc; padding:6px 9px; border:1px solid var(--line)}
.zc-kv td{padding:6px 9px; border:1px solid var(--line); word-break:break-word}
.zc-sub{width:100%; border-collapse:collapse; font-size:12px}
.zc-sub th{text-align:left; font-weight:600; color:var(--ink2); background:#fafbfc; padding:5px 8px; border:1px solid var(--line); white-space:nowrap}
.zc-sub td{padding:5px 8px; border:1px solid var(--line)}
.zc-addcomment{margin:20px 0 0; color:var(--pink); font-size:12.5px; cursor:pointer}

.zc-modalback{position:fixed; inset:0; background:rgba(32,36,46,.35); z-index:60; display:grid; place-items:start center; padding:18px}
.zc-modal{background:var(--white); width:min(1220px,100%); max-height:calc(100vh - 36px); border-radius:4px;
  box-shadow:0 18px 50px rgba(32,36,46,.28); display:flex; flex-direction:column}
.zc-modalbar{flex:none; display:flex; align-items:center; justify-content:space-between; padding:10px 16px;
  border-bottom:1px solid var(--line); background:#fafbfc; font-size:15px; font-weight:500}
.zc-modalbody{overflow-y:auto; padding:14px 18px 20px}
.zc-modalfoot{flex:none; display:flex; gap:8px; padding:10px 18px; border-top:1px solid var(--line)}
.zc-form2{display:grid; grid-template-columns:1fr 1fr; gap:0 40px; align-items:start}
.zc-addrgrid{margin-top:6px}
.zc-frow{display:grid; grid-template-columns:150px minmax(0,1fr); align-items:start; gap:10px; padding:4px 0; min-height:32px}
.zc-frow > label{font-size:12.5px; color:var(--ink2); padding-top:5px}
.zc-fctl{min-width:0}
.zc-in{font:inherit; font-size:12.5px; height:27px; padding:0 6px; border:1px solid var(--line2); border-radius:3px;
  background:var(--white); color:var(--ink); width:100%; max-width:280px}
.zc-in.num{text-align:right}
.zc-in:focus{border-color:var(--pink); outline:0}
.zc-in:disabled{background:#f6f7f9; color:var(--ink3); cursor:not-allowed}
.zc-ta{height:auto; padding:6px; resize:vertical; line-height:1.45}
.zc-withicon{position:relative; max-width:280px; display:flex}
.zc-affix{position:absolute; right:0; top:0; height:27px; width:28px; display:grid; place-items:center;
  border-left:1px solid var(--line2); color:var(--ink3); background:#fafbfc; border-radius:0 3px 3px 0}
.zc-withicon .zc-in{padding-right:34px}
.zc-check{display:inline-flex; align-items:center; gap:6px; font-size:12.5px; cursor:pointer; min-height:24px}
.zc-check input{accent-color:var(--pink)}
.zc-checkstack{display:grid; gap:3px}
.zc-samechk{margin-bottom:6px}
.zc-datebox{position:relative; max-width:280px}
.zc-cal{position:absolute; right:7px; top:5px; color:var(--ink4); font-size:11px; pointer-events:none}
.zc-phonebox{display:flex; align-items:center; gap:0; max-width:280px}
.zc-cc{font-size:12px; color:var(--ink2); border:1px solid var(--line2); border-right:0; border-radius:3px 0 0 3px;
  height:27px; display:flex; align-items:center; padding:0 6px; background:#fafbfc; white-space:nowrap}
.zc-phonebox .zc-in{border-radius:0 3px 3px 0}
.zc-addr{display:grid; gap:7px; max-width:340px}
.zc-addrrow{display:grid; grid-template-columns:1fr 1fr; gap:9px}
.zc-addrf{display:grid; gap:2px}
.zc-addrf .zc-in{max-width:none}
.zc-cap{font-size:10.5px; color:var(--ink4)}
.zc-fsect{margin:20px 0 7px; font-size:13px; font-weight:600; padding-bottom:5px; border-bottom:1px solid var(--line)}
.zc-subedit{width:100%; border-collapse:collapse; font-size:12px}
.zc-subedit th{text-align:left; font-weight:600; color:var(--ink2); background:#fafbfc; padding:5px 7px;
  border:1px solid var(--line); white-space:nowrap; font-size:11.5px}
.zc-subedit td{padding:2px 4px; border:1px solid var(--line); vertical-align:middle}
.zc-subedit .zc-in{max-width:none; height:25px}
.zc-subedit input[type=checkbox]{accent-color:var(--pink)}
.zc-empty{color:var(--ink3); text-align:center; padding:13px !important; font-size:12px}
.zc-x{border:0; background:none; color:var(--ink4); cursor:pointer; font-size:10px; padding:3px 5px; border-radius:2px}
.zc-x:hover{color:var(--bad); background:var(--badbg)}
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
.zc-droplist button{width:100%; text-align:left; font:inherit; font-size:12.5px; padding:5px 6px; border:0; background:none; cursor:pointer; border-radius:2px}
.zc-droplist button:hover{background:var(--pinkl)}
@media (max-width:1240px){ .zc-form2{grid-template-columns:1fr} }
@media (max-width:820px){ .zc{grid-template-columns:1fr; height:auto} .zc-rail{flex-direction:row; overflow-x:auto} .zc-panel{width:100vw} }
`}</style>
  );
}
