import React, { useState, useMemo, useRef, useEffect } from "react";

/* ═══════════════════════════════════════════════════════════════════════════
   Payment Requests — the three views behind the nav item, from screenshots
   13-Aug-2026.

     1. `Payment Request`       an add-only form. No list. Submit / Reset
     2. `All Payment Requests`  the admin report, 66 records
     3. `User Payment Requests` the requester's own, 24 records, inline-editable
                                with a per-row Re-Send for Approval button

   Replicated verbatim:
     · The add form commits with Submit / **Reset**; the edit form with
       Update / Cancel. Two different footers on the same field set
     · `Payment Amount` carries a ₹ suffix box and the placeholder `##,##,###.##`
     · On the EDIT form, Villa Name and Item Category chips render greyed —
       those two look read-only once the request exists, while Payment Amount
       and Particulars stay editable
     · File fields read `Select File` when empty and `File uploaded` in pink
       once populated; the detail panel shows them as a chip with the filename
     · The detail panel bar is `‹ › Edit  Duplicate  More ⌄  ✕` — **Duplicate**
       is the top-level button here, where every other module has Delete
     · `User Payment Requests` carries the `*` and Save Changes / Remove Changes
     · Villa Name prints one villa per line, so rows are content-height
     · `Vendor Name` appears TWICE on the detail panel: the lookup, then a
       second blank one paired with `Add New Vendor` and `Bank Details` for
       entering a vendor that isn't in the master yet
   ═══════════════════════════════════════════════════════════════════════════ */

const uid = () => Math.random().toString(36).slice(2, 9);
const group = (i) => {
  let last3 = i.slice(-3), rest = i.slice(0, -3);
  if (rest) last3 = "," + last3;
  return rest.replace(/\B(?=(\d{2})+(?!\d))/g, ",") + last3;
};
const money = (n) => {
  if (n === "" || n === null || n === undefined || Number.isNaN(+n)) return "";
  const [i, d] = Math.abs(+n).toFixed(2).split(".");
  return `${+n < 0 ? "-" : ""}₹ ${group(i)}.${d}`;
};
/** plain Indian grouping, no symbol and no decimals — what the edit field shows */
const inr = (n) => (n === "" || n === null || n === undefined || Number.isNaN(+n)
  ? (n ?? "") : group(String(Math.trunc(Math.abs(+n)))));

const REQUESTERS = ["Husain Super Admin", "Sanjay Projapati", "Amit", "Neha", "Priya Nair", "Zeeshan Khan"];
const LOCATIONS = ["Mumbai", "Lonavala", "Alibaug", "Karjat", "Igatpuri", "Panchgani", "Goa",
  "Ooty And Coonoor", "Kodaikanal", "Bangalore", "Head Office Central"];
const VILLAS = ["Assa Villa- Assagaon", "Utopian Villa- Pilerene", "Goa Central", "Under The Pines",
  "Dusk Villa", "Dawn Villa", "Orchid Villa", "Whispering Pines", "The Velvet Slope", "Alibaug Central",
  "Peach Grove Villa- Arpora", "Highland Villa 7BHK", "The Clay House", "Casablanca Villa", "CASA SIA",
  "Hawaiian Villa", "Ark Stone Villa", "Aqua Beach Villa- Candolim Beach", "Ooty Central", "Casa Vayu"];
const ITEM_CATEGORIES = ["AC WORKS", "PRINTING", "STAFF VEHICLE MAINTENANCE", "AMAZON PURCHASE",
  "WIFI", "ELECTRICITY BILL", "LAUNDRY", "HARDWARE MATERIAL", "STAFF FUEL", "GENERAL PURCHASE"];
const VENDORS = ["PAINT SPARK", "amazon", "Amazon", "hussain sir", "MAHAVITRAN", "PAYAL HARDWARE"];
const STATUSES = ["Submit for Approval", "Sent for Approval", "Approved", "Approval Rejected", "Approval Not Required"];

/* ── seed ────────────────────────────────────────────────────────────────
   The first ten All Payment Requests rows are verbatim, real IDs and Added
   Users included. The live report holds 66; rows below the marker are
   synthetic, following the same shape, so the grid has depth. */

const AR = (o) => ({
  paymentNo: "", requestedBy: "", status: "", vendorName: "", location: "", villaName: [],
  itemCategory: [], paymentAmount: "", id: "", addedUser: "", remarks: "",
  billAmount: "", particulars: "", addNewVendor: false, newVendorName: "", bankDetails: "",
  bills: "", supportingDocuments: "", ...o,
});

const ALL_REQUESTS = [
  AR({ paymentNo: "EKS/PY/20618", requestedBy: "Sanjay Projapati", status: "Approved", vendorName: "",
    location: "Goa", villaName: ["Assa Villa- Assagaon"], itemCategory: ["AC WORKS"],
    paymentAmount: 3200, id: "292482000010335032", addedUser: "sanjayprojapati1983" }),
  AR({ paymentNo: "EKS/PY/20617", requestedBy: "Sanjay Projapati", status: "Approved", vendorName: "PAINT SPARK",
    location: "Goa", villaName: ["Utopian Villa- Pilerene"], itemCategory: ["PRINTING"],
    paymentAmount: 4040, id: "292482000010335020", addedUser: "sanjayprojapati1983" }),
  AR({ paymentNo: "EKS/PY/20619", requestedBy: "Amit", status: "Approved", vendorName: "",
    location: "Goa", villaName: ["Goa Central"], itemCategory: ["STAFF VEHICLE MAINTENANCE"],
    paymentAmount: 3534, id: "292482000010332086", addedUser: "amit7331411" }),
  AR({ paymentNo: "EKS/PY/20559", requestedBy: "Neha", status: "Sent for Approval", vendorName: "amazon",
    location: "Ooty And Coonoor",
    villaName: ["Under The Pines", "Dusk Villa", "Dawn Villa", "Orchid Villa", "Whispering Pines", "The Velvet Slope"],
    itemCategory: ["AMAZON PURCHASE"], paymentAmount: 10610.70,
    id: "292482000010299276", addedUser: "shaikh.nehu091" }),
  /* Requested By blank while Added User is populated — two records of the requester */
  AR({ paymentNo: "", requestedBy: "", status: "Submit for Approval", vendorName: "Amazon",
    location: "Alibaug", villaName: ["Alibaug Central"], itemCategory: ["AMAZON PURCHASE"],
    paymentAmount: 23200, id: "292482000009951241", addedUser: "shaikh.nehu091" }),
  AR({ paymentNo: "EKS/PY/19899", requestedBy: "", status: "Approved", vendorName: "amazon",
    location: "Goa", villaName: ["Peach Grove Villa- Arpora"], itemCategory: ["AMAZON PURCHASE"],
    paymentAmount: 1496, id: "292482000009947104", addedUser: "shaikh.nehu091" }),
  AR({ paymentNo: "", requestedBy: "", status: "Submit for Approval", vendorName: "Amazon",
    location: "Panchgani", villaName: ["Highland Villa 7BHK"], itemCategory: ["AMAZON PURCHASE"],
    paymentAmount: 2992, id: "292482000009944990", addedUser: "shaikh.nehu091" }),
  AR({ paymentNo: "", requestedBy: "", status: "Submit for Approval", vendorName: "amazon",
    location: "Alibaug", villaName: ["The Clay House"], itemCategory: ["AMAZON PURCHASE"],
    paymentAmount: 5984, id: "292482000009944928", addedUser: "shaikh.nehu091" }),
  AR({ paymentNo: "", requestedBy: "", status: "Submit for Approval", vendorName: "Amazon",
    location: "Igatpuri", villaName: ["Casablanca Villa"], itemCategory: ["AMAZON PURCHASE"],
    paymentAmount: 5984, id: "292482000009944795", addedUser: "shaikh.nehu091" }),
  AR({ paymentNo: "EKS/PY/19809", requestedBy: "", status: "Approval Not Required", vendorName: "Amazon",
    location: "Lonavala", villaName: ["CASA SIA"], itemCategory: ["AMAZON PURCHASE"],
    paymentAmount: 5908, id: "292482000009939150", addedUser: "shaikh.nehu091" }),
  /* ── synthetic below ── */
  AR({ paymentNo: "EKS/PY/19788", requestedBy: "Priya Nair", status: "Approved", vendorName: "MAHAVITRAN",
    location: "Lonavala", villaName: ["Casa Vayu"], itemCategory: ["ELECTRICITY BILL"],
    paymentAmount: 41020, id: "292482000009901022", addedUser: "priya.nair" }),
  AR({ paymentNo: "", requestedBy: "Priya Nair", status: "Sent for Approval", vendorName: "PAYAL HARDWARE",
    location: "Alibaug", villaName: ["Ark Stone Villa"], itemCategory: ["HARDWARE MATERIAL"],
    paymentAmount: 7688, id: "292482000009894018", addedUser: "priya.nair" }),
  AR({ paymentNo: "EKS/PY/19702", requestedBy: "Amit", status: "Approved", vendorName: "",
    location: "Goa", villaName: ["Aqua Beach Villa- Candolim Beach"], itemCategory: ["AC WORKS"],
    paymentAmount: 12400, id: "292482000009880114", addedUser: "amit7331411" }),
  AR({ paymentNo: "", requestedBy: "Neha", status: "Submit for Approval", vendorName: "amazon",
    location: "Ooty And Coonoor", villaName: ["Ooty Central"], itemCategory: ["GENERAL PURCHASE"],
    paymentAmount: 3145.5, id: "292482000009872006", addedUser: "shaikh.nehu091" }),
  AR({ paymentNo: "EKS/PY/19655", requestedBy: "Sanjay Projapati", status: "Approval Rejected",
    vendorName: "PAINT SPARK", location: "Goa", villaName: ["Utopian Villa- Pilerene"],
    itemCategory: ["PRINTING"], paymentAmount: 1980, id: "292482000009861044", addedUser: "sanjayprojapati1983" }),
];

/* User Payment Requests — the requester's own view. Every row here is
   `Husain Super Admin` / `hussain sir` / Bangalore / Hawaiian Villa / WIFI in the
   live data, and one row carries a BLANK Status. */
const UR = (o) => AR({ requestedBy: "Husain Super Admin", vendorName: "hussain sir",
  location: "Bangalore", villaName: ["Hawaiian Villa"], itemCategory: ["WIFI"],
  paymentAmount: 2500, particulars: "test",
  bills: "WhatsApp_Image_2026-05-26_at_14.31.07 .jpeg",
  supportingDocuments: "WhatsApp_Image_2026-05-26_at_14.31.22 .jpeg", ...o });

const USER_REQUESTS = [
  UR({ id: "292482000010211008", status: "Sent for Approval" }),
  UR({ id: "292482000010211004", status: "Sent for Approval" }),
  UR({ id: "292482000010210996", status: "Sent for Approval", vendorName: "", location: "Goa",
    villaName: ["Aqua Beach Villa- Candolim Beach"], itemCategory: ["AC WORKS"] }),
  UR({ id: "292482000010210988", status: "Approved", paymentNo: "EKS/PY/16239", remarks: "na",
    location: "Alibaug", villaName: ["Ark Stone Villa"], itemCategory: ["AC WORKS"] }),
  UR({ id: "292482000010210980", status: "Submit for Approval" }),
  UR({ id: "292482000010210972", status: "Submit for Approval" }),
  /* Status is blank on this record in the live report */
  UR({ id: "292482000010210964", status: "" }),
  UR({ id: "292482000010210956", status: "Submit for Approval" }),
  UR({ id: "292482000010210948", status: "Sent for Approval" }),
];

/* ── view configs ───────────────────────────────────────────────────────── */

const ALL_COLUMNS = [
  { k: "paymentNo", label: "Payment No", w: 170 },
  { k: "requestedBy", label: "Requested By", w: 180 },
  { k: "status", label: "Status", w: 200 },
  { k: "vendorName", label: "Vendor Name", w: 220 },
  { k: "location", label: "Location", w: 200 },
  { k: "villaName", label: "Villa Name", w: 260, type: "list" },
  { k: "itemCategory", label: "Item Category", w: 250, type: "list" },
  { k: "paymentAmount", label: "Payment Amount", w: 160, type: "money" },
  { k: "id", label: "ID", w: 210 },
  { k: "addedUser", label: "Added User", w: 190 },
];

const USER_COLUMNS = [
  { k: "_resend", label: "Re-Send for Approval", w: 220, type: "btn" },
  { k: "paymentNo", label: "Payment No", w: 160 },
  { k: "requestedBy", label: "Requested By", w: 190 },
  { k: "status", label: "Status", w: 190 },
  { k: "remarks", label: "Remarks", w: 130 },
  { k: "vendorName", label: "Vendor Name", w: 170 },
  { k: "location", label: "Location", w: 170 },
  { k: "villaName", label: "Villa Name", w: 200, type: "list" },
  { k: "itemCategory", label: "Item Category", w: 190, type: "list" },
  { k: "paymentAmount", label: "Payment Amount", w: 160, type: "money" },
  { k: "id", label: "ID", w: 210 },
];

/* Detail field order verbatim, including the second Vendor Name. */
const DETAIL = [
  ["requestedBy", "Requested By"], ["vendorName", "Vendor Name"], ["location", "Location"],
  ["villaName", "Villa Name"], ["itemCategory", "Item Category"], ["billAmount", "Bill Amount"],
  ["paymentAmount", "Payment Amount"], ["particulars", "Particulars"], ["status", "Status"],
  ["addNewVendor", "Add New Vendor"], ["newVendorName", "Vendor Name"], ["bankDetails", "Bank Details"],
  ["paymentNo", "Payment No"], ["bills", "Bills"], ["supportingDocuments", "Supporting Documents"],
];

/* Add-form field order verbatim. `Add New Vendor` sits between the vendor lookup
   and Location — checkboxes are interleaved, as on every other Creator form. */
const FORM = [
  { k: "requestedBy", label: "Requested By", type: "lookup", options: REQUESTERS },
  { k: "vendorName", label: "Vendor Name", type: "lookup", options: VENDORS },
  { k: "addNewVendor", label: "Add New Vendor", type: "bool" },
  { k: "location", label: "Location", type: "lookup", options: LOCATIONS },
  { k: "villaName", label: "Villa Name", type: "multi", options: VILLAS, lockOnEdit: true },
  { k: "itemCategory", label: "Item Category", type: "multi", options: ITEM_CATEGORIES, lockOnEdit: true },
  { k: "paymentAmount", label: "Payment Amount", type: "rupee", ph: "##,##,###.##" },
  { k: "particulars", label: "Particulars", type: "textarea" },
  { k: "bills", label: "Bills", type: "file" },
  { k: "supportingDocuments", label: "Supporting Documents", type: "file" },
];

const VIEWS = {
  request: { nav: "Payment Request", kind: "form", formTitle: "Payment Request" },
  all: { nav: "All Payment Requests", kind: "report", title: "All Payment Requests",
    columns: ALL_COLUMNS, seed: ALL_REQUESTS,
    search: ["Payment No", "Requested By", "Status", "Vendor Name", "Location", "Added User"] },
  user: { nav: "User Payment Requests", kind: "report", title: "User Payment Requests",
    columns: USER_COLUMNS, seed: USER_REQUESTS, star: true, savable: true, resend: true,
    search: ["Payment No", "Status", "Vendor Name", "Remarks"] },
};
const VIEW_ORDER = ["request", "all", "user"];

const NAV = [
  ["Accounts", "calc"], ["Payments", "bank"], ["Bank", "bank2"], ["Bills", "bill"],
  ["Expenses", "exp"], ["Schedule Payments", "sched"], ["Expense Observations", "obs"],
  ["Masters", "mast"], ["Settings", "gear"], ["Backend Expenses", "exp"],
  ["Pending Approvals", "hourglass"], ["App Preferences", "box"], ["Payment Requests", "receipt"],
];

/* ══ shell ══════════════════════════════════════════════════════════════ */

export default function PaymentRequestsModule() {
  const [viewKey, setViewKey] = useState("all");
  const [flyout, setFlyout] = useState(false);
  const [flyTop, setFlyTop] = useState(0);
  const [data, setData] = useState({ all: ALL_REQUESTS, user: USER_REQUESTS });
  const [openId, setOpenId] = useState(null);
  const [editing, setEditing] = useState(null);
  const [showSearch, setShowSearch] = useState(false);
  const [search, setSearch] = useState({ field: "Payment No", value: "" });
  const [checked, setChecked] = useState(new Set());
  const [sort, setSort] = useState({ key: "", dir: "asc" });
  const [toast, setToast] = useState("");
  const flyRef = useRef(null);

  useEffect(() => {
    const h = (e) => { if (flyRef.current && !flyRef.current.contains(e.target)) setFlyout(false); };
    document.addEventListener("mousedown", h); return () => document.removeEventListener("mousedown", h);
  }, []);
  const flash = (m) => { setToast(m); setTimeout(() => setToast(""), 2400); };

  const cfg = VIEWS[viewKey];
  const rowsAll = cfg.kind === "report" ? (data[viewKey] ?? []) : [];

  const switchView = (k) => {
    setViewKey(k); setFlyout(false); setOpenId(null); setEditing(null);
    setChecked(new Set()); setSort({ key: "", dir: "asc" });
    setSearch({ field: VIEWS[k].search?.[0] ?? "", value: "" });
  };

  const text = (r, col) => {
    const v = r[col.k];
    if (col.type === "list") return (v ?? []).join(", ");
    if (col.type === "money") return money(v);
    if (typeof v === "boolean") return v ? "true" : "false";
    return v ?? "";
  };

  const view = useMemo(() => {
    let r = rowsAll;
    if (search.value.trim() && cfg.search) {
      const q = search.value.toLowerCase();
      const map = { "Payment No": "paymentNo", "Requested By": "requestedBy", Status: "status",
        "Vendor Name": "vendorName", Location: "location", "Added User": "addedUser", Remarks: "remarks" };
      const k = map[search.field];
      if (k) r = r.filter((x) => String(x[k] ?? "").toLowerCase().includes(q));
    }
    if (!sort.key) return r;
    const dir = sort.dir === "asc" ? 1 : -1;
    const col = cfg.columns?.find((c) => c.k === sort.key);
    return [...r].sort((a, b) => col?.type === "money"
      ? ((+a[sort.key] || 0) - (+b[sort.key] || 0)) * dir
      : String(text(a, col ?? { k: sort.key })).localeCompare(String(text(b, col ?? { k: sort.key }))) * dir);
  }, [rowsAll, search, sort, cfg]);

  const save = (rec) => {
    setData((p) => ({ ...p, [viewKey]: p[viewKey].some((x) => x.id === rec.id)
      ? p[viewKey].map((x) => (x.id === rec.id ? rec : x)) : [rec, ...p[viewKey]] }));
    setEditing(null); setOpenId(rec.id);
  };
  const submitNew = (rec) => {
    const withId = { ...rec, id: `29248200001035${String(Math.floor(1000 + Math.random() * 8999))}`,
      status: "Submit for Approval", addedUser: "husain_ekostay1" };
    setData((p) => ({ ...p, all: [withId, ...p.all], user: [withId, ...p.user] }));
    flash("Payment Request Added Successfully");
    setViewKey("user");
  };
  const duplicate = (rec) => setEditing({ ...rec, id: uid(), paymentNo: "", status: "", remarks: "" });
  const resend = (r) => {
    setData((p) => ({ ...p, [viewKey]: p[viewKey].map((x) => (x.id === r.id
      ? { ...x, status: "Sent for Approval" } : x)) }));
    flash("Re-Sent for Approval");
  };

  const openRec = openId ? rowsAll.find((r) => r.id === openId) : null;
  const idx = view.findIndex((r) => r.id === openId);
  /* Re-Send is live only while the request has not been decided */
  const canResend = (r) => r.status !== "Approved" && r.status !== "Approval Not Required";

  return (
    <>
      <Style />
      <div className="zc">
        <aside className="zc-rail">
          <div className="zc-logo">ACC</div>
          {NAV.map(([label, icon]) => {
            const isPR = label === "Payment Requests";
            return (
              <div key={label} className="zc-navwrap" ref={isPR ? flyRef : null}>
                <button className={"zc-navitem" + (isPR ? " on" : "")}
                  onClick={(e) => { if (!isPR) return;
                    setFlyTop(e.currentTarget.getBoundingClientRect().top); setFlyout((f) => !f); }}>
                  <Icon name={icon} /><span>{label}</span>
                </button>
                {isPR && flyout && (
                  <div className="zc-flyout" style={{ top: Math.min(flyTop, 760) }}>
                    {VIEW_ORDER.map((k) => (
                      <button key={k} className={"zc-flyitem" + (k === viewKey ? " on" : "")}
                        onClick={() => switchView(k)}>
                        <Icon name={VIEWS[k].kind === "form" ? "receipt" : "report"} />
                        <span>{VIEWS[k].nav}</span>
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

          {cfg.kind === "form" ? (
            /* the add form IS the view — it is not a modal over a report */
            <RequestForm key="add" mode="add" onSubmit={submitNew} />
          ) : (
            <>
              <div className="zc-reportbar">
                <h1>{cfg.title}{cfg.star && <i className="zc-req">*</i>}</h1>
                {cfg.savable && <>
                  <button className="zc-btn zc-btn-out">Save Changes</button>
                  <button className="zc-btn zc-btn-out">Remove Changes</button>
                </>}
                <div className="zc-reportbar-r">
                  <button className="zc-iconbtn zc-sq" onClick={() => setShowSearch((s) => !s)} aria-label="Search"><Icon name="search" /></button>
                  <button className="zc-add" onClick={() => switchView("request")} aria-label="Add">＋</button>
                  <button className="zc-iconbtn zc-sq" aria-label="More">···</button>
                </div>
              </div>

              {showSearch && (
                <div className="zc-searchrow">
                  <span className="zc-searchlabel">SEARCH</span>
                  <div className="zc-searchchip">
                    <select value={search.field} onChange={(e) => setSearch((s) => ({ ...s, field: e.target.value }))}>
                      {cfg.search.map((f) => <option key={f}>{f}</option>)}
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
                      {cfg.columns.map((col) => col.type === "btn"
                        ? <th key={col.k} style={{ width: col.w }}>{col.label}</th>
                        : (
                          <th key={col.k} style={{ width: col.w }} className={col.type === "money" ? "num" : ""}>
                            <button className="zc-th" onClick={() => setSort((s) => ({
                              key: col.k, dir: s.key === col.k && s.dir === "asc" ? "desc" : "asc" }))}>
                              <span>{col.label}</span>
                              <i className={"zc-caret" + (sort.key === col.k ? " on " + sort.dir : "")} />
                            </button>
                          </th>
                        ))}
                    </tr>
                  </thead>
                  <tbody>
                    {view.map((r) => (
                      <tr key={r.id} className={openId === r.id ? "sel" : ""} onClick={() => setOpenId(r.id)}>
                        <td className="zc-c-eye">{openId === r.id ? <span className="zc-dots">···</span> : null}</td>
                        <td className="zc-c-chk" onClick={(e) => e.stopPropagation()}>
                          <input type="checkbox" checked={checked.has(r.id)} aria-label="Select row"
                            onChange={() => setChecked((p) => { const n = new Set(p); n.has(r.id) ? n.delete(r.id) : n.add(r.id); return n; })} />
                        </td>
                        {cfg.columns.map((col) => {
                          if (col.type === "btn") return (
                            <td key={col.k} onClick={(e) => e.stopPropagation()}>
                              <button className="zc-rowbtn" disabled={!canResend(r)} onClick={() => resend(r)}>
                                Re-Send for Approval
                              </button>
                            </td>
                          );
                          if (col.type === "list") return (
                            /* one villa per line, which is what makes rows tall */
                            <td key={col.k}>{(r[col.k] ?? []).map((x, i) => <div key={i} className="zc-stackline">{x}</div>)}</td>
                          );
                          const mono = col.type === "money" || col.k === "id";
                          return (
                            <td key={col.k} className={(mono ? "mono " : "") + (col.type === "money" ? "num " : "")}>
                              {text(r, col)}
                            </td>
                          );
                        })}
                      </tr>
                    ))}
                    {view.length === 0 && <tr><td colSpan={cfg.columns.length + 2} className="zc-empty">No records found</td></tr>}
                  </tbody>
                </table>
              </div>

              <footer className="zc-footer">
                <span>Showing {view.length} of {rowsAll.length}</span>
                {checked.size > 0 && <span className="zc-selcount">{checked.size} selected</span>}
              </footer>
            </>
          )}
        </div>

        {toast && <div className="zc-toast"><Icon name="tick" />{toast}</div>}

        {openRec && (
          <DetailPanel rec={openRec} onClose={() => setOpenId(null)} onEdit={() => setEditing(openRec)}
            onDuplicate={() => duplicate(openRec)}
            onPrev={idx > 0 ? () => setOpenId(view[idx - 1].id) : null}
            onNext={idx < view.length - 1 ? () => setOpenId(view[idx + 1].id) : null} />
        )}
        {editing && (
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
            <RequestForm mode="edit" initial={editing} onSubmit={save} onCancel={() => setEditing(null)} />
          </div>
        )}
      </div>
    </>
  );
}

/* ══ the form — one field set, two footers ══════════════════════════════ */

const blankRequest = () => AR({ requestedBy: "Husain Super Admin" });

function RequestForm({ mode, initial, onSubmit, onCancel }) {
  const [rec, setRec] = useState(() => initial ?? blankRequest());
  const set = (k, v) => setRec((p) => ({ ...p, [k]: v }));
  const isEdit = mode === "edit";

  const control = (f) => {
    const v = rec[f.k];
    /* Villa Name and Item Category render locked on the edit form */
    const locked = isEdit && f.lockOnEdit;
    if (f.type === "lookup") return (
      <div className="zc-lookup">
        <select className={"zc-in" + (v ? "" : " ph")} value={v ?? ""} onChange={(e) => set(f.k, e.target.value)}>
          <option value="">-Select-</option>
          {f.options.map((o) => <option key={o}>{o}</option>)}
        </select>
        {v && <button className="zc-clear" onClick={() => set(f.k, "")} aria-label="Clear">✕</button>}
      </div>
    );
    if (f.type === "multi") return <ChipBox options={f.options} value={v ?? []} locked={locked}
      onChange={(x) => set(f.k, x)} />;
    if (f.type === "rupee") return (
      <div className="zc-suffixed">
        <input className="zc-in" value={inr(v)} placeholder={f.ph}
          onChange={(e) => set(f.k, e.target.value.replace(/,/g, ""))} />
        <span className="zc-suffix">₹</span>
      </div>
    );
    if (f.type === "textarea") return (
      <textarea className="zc-in zc-ta" rows={5} value={v ?? ""} onChange={(e) => set(f.k, e.target.value)} />
    );
    if (f.type === "file") return (
      <div className="zc-filerow">
        {/* `Select File` when empty, `File uploaded` in pink once populated */}
        <div className={"zc-in zc-filebox" + (v ? " has" : "")}>{v ? "File uploaded" : "Select File"}</div>
        <button className="zc-upload" aria-label="Upload"
          onClick={() => set(f.k, v ? "" : "WhatsApp_Image_2026-05-26_at_14.31.07 .jpeg")}>
          <Icon name="upload" />
        </button>
      </div>
    );
    return <input className="zc-in" value={v ?? ""} onChange={(e) => set(f.k, e.target.value)} />;
  };

  return (
    <>
      <div className="zc-formtitle">Payment Request</div>
      <div className="zc-formscroll">
        <div className="zc-formbody">
          {FORM.map((f) => f.type === "bool" ? (
            <label key={f.k} className="zc-formcheck">
              <input type="checkbox" checked={!!rec[f.k]} onChange={(e) => set(f.k, e.target.checked)} />
              <span>{f.label}</span>
            </label>
          ) : (
            <div className={"zc-formrow" + (f.type === "multi" || f.type === "textarea" ? " top" : "")} key={f.k}>
              <label>{f.label}</label>
              {control(f)}
            </div>
          ))}
          {rec.addNewVendor && (
            <>
              {/* the second Vendor Name and Bank Details, used when the vendor is not in the master */}
              <div className="zc-formrow">
                <label>Vendor Name</label>
                <input className="zc-in" value={rec.newVendorName}
                  onChange={(e) => set("newVendorName", e.target.value)} />
              </div>
              <div className="zc-formrow top">
                <label>Bank Details</label>
                <textarea className="zc-in zc-ta" rows={3} value={rec.bankDetails}
                  onChange={(e) => set("bankDetails", e.target.value)} />
              </div>
            </>
          )}
        </div>
        <div className="zc-formfoot">
          {/* add commits with Submit / Reset, edit with Update / Cancel */}
          <button className="zc-btn zc-btn-pri" onClick={() => onSubmit(rec)}>{isEdit ? "Update" : "Submit"}</button>
          <button className="zc-btn zc-btn-out"
            onClick={() => (isEdit ? onCancel() : setRec(blankRequest()))}>{isEdit ? "Cancel" : "Reset"}</button>
        </div>
      </div>
    </>
  );
}

/* ══ detail panel — Duplicate sits where Delete sits elsewhere ══════════ */

function DetailPanel({ rec, onClose, onEdit, onDuplicate, onPrev, onNext }) {
  const [menu, setMenu] = useState(false);
  useEffect(() => {
    const h = (e) => { if (e.key === "Escape") onClose(); };
    window.addEventListener("keydown", h); return () => window.removeEventListener("keydown", h);
  }, [onClose]);

  const render = (k) => {
    const v = rec[k];
    if (Array.isArray(v)) return v.map((x, i) => <div key={i} className="zc-stackline">{x}</div>);
    if (typeof v === "boolean") return v ? "true" : "false";
    if (k === "paymentAmount" || k === "billAmount") return money(v);
    if ((k === "bills" || k === "supportingDocuments") && v) return (
      <span className="zc-filechip"><Icon name="image" />{v}</span>
    );
    return v ?? "";
  };

  return (
    <aside className="zc-panel">
      <div className="zc-panelbar">
        <div className="zc-nav2">
          <button className="zc-iconbtn zc-sq" onClick={onPrev} disabled={!onPrev} aria-label="Previous">‹</button>
          <button className="zc-iconbtn zc-sq" onClick={onNext} disabled={!onNext} aria-label="Next">›</button>
        </div>
        <div className="zc-panelacts">
          <button className="zc-btn zc-btn-out" onClick={onEdit}><Icon name="pencil" />Edit</button>
          <button className="zc-btn zc-btn-out" onClick={onDuplicate}><Icon name="copy" />Duplicate</button>
          <div className="zc-menuwrap">
            <button className="zc-btn zc-btn-out" onClick={() => setMenu((m) => !m)}>More ⌄</button>
            {menu && (
              <div className="zc-menu">
                <button onClick={() => setMenu(false)}>Delete</button>
                <button onClick={() => setMenu(false)}>Print</button>
              </div>
            )}
          </div>
          <button className="zc-iconbtn zc-sq" onClick={onClose} aria-label="Close">✕</button>
        </div>
      </div>
      <div className="zc-panelbody">
        <table className="zc-kv"><tbody>
          {DETAIL.map(([k, label], i) => <tr key={k + i}><th>{label}</th><td>{render(k)}</td></tr>)}
        </tbody></table>
        {!rec.paymentNo && rec.status && rec.status !== "Submit for Approval" && (
          <p className="zc-inlinewarn">Status is <b>{rec.status}</b> but Payment No is empty, so no Payment
            record exists for this request yet. On the live report that combination appears on several rows.</p>
        )}
        <p className="zc-addcomment"><Icon name="comment" />Add a comment</p>
      </div>
    </aside>
  );
}

function ChipBox({ options = [], value = [], onChange, locked }) {
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
      <div className={"zc-chipbox" + (locked ? " locked" : "")} onClick={() => !locked && setOpen(true)}>
        {value.map((v) => (
          <span className="zc-chip" key={v}>
            {!locked && <button onClick={(e) => { e.stopPropagation(); onChange(value.filter((x) => x !== v)); }} aria-label="Remove">✕</button>}
            {locked && <span className="zc-chipx">✕</span>}
            {v}
          </span>
        ))}
        {!locked && <input value={q} onChange={(e) => { setQ(e.target.value); setOpen(true); }}
          onFocus={() => setOpen(true)} placeholder={value.length ? "" : "-Select-"} />}
      </div>
      {open && !locked && avail.length > 0 && (
        <ul className="zc-droplist">
          {avail.slice(0, 12).map((o) => (
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
    report: <><rect x="2" y="4" width="20" height="14" rx="2" /><path d="M8 21h8M12 18v3" /></>,
    bell: <><path d="M18 8a6 6 0 10-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9" /><path d="M10.3 21a2 2 0 003.4 0" /></>,
    user: <><circle cx="12" cy="8" r="4" /><path d="M4 21c0-4 3.6-6 8-6s8 2 8 6" /></>,
    search: <><circle cx="11" cy="11" r="7" /><path d="M20 20l-4.3-4.3" /></>,
    eye: <><path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6-10-6-10-6z" /><circle cx="12" cy="12" r="2.5" /></>,
    pencil: <><path d="M12 20h9" /><path d="M16.5 3.5a2.1 2.1 0 013 3L7 19l-4 1 1-4z" /></>,
    copy: <><rect x="9" y="9" width="12" height="12" rx="2" /><path d="M5 15V5a2 2 0 012-2h10" /></>,
    comment: <><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z" /></>,
    upload: <><path d="M12 19V5M6 11l6-6 6 6" /></>,
    image: <><rect x="3" y="3" width="18" height="18" rx="2" /><circle cx="8.5" cy="8.5" r="1.5" /><path d="M21 15l-5-5L5 21" /></>,
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
  --bad:#c0392b; --badbg:#fdeceb;
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
.zc-navwrap{position:relative; flex:none}
.zc-navitem{width:100%; background:none; border:0; color:#bcc2d2; font:inherit; font-size:10px; line-height:1.3;
  padding:9px 5px 7px; display:grid; justify-items:center; gap:5px; cursor:pointer; text-align:center; word-break:break-word}
.zc-navitem:hover{background:var(--rail2); color:#fff}
.zc-navitem.on{background:var(--pink); color:#fff}
.zc-flyout{position:fixed; left:104px; z-index:40; min-width:250px; background:var(--rail2);
  box-shadow:6px 6px 22px rgba(20,22,34,.34); padding:8px 0}
.zc-flyitem{width:100%; background:none; border:0; color:#e4e7f0; font:inherit; font-size:13.5px; text-align:left;
  padding:9px 18px; display:flex; align-items:center; gap:11px; cursor:pointer; white-space:nowrap}
.zc-flyitem:hover{background:#464c72; color:#fff}
.zc-flyitem.on{color:#fff; font-weight:500; box-shadow:inset 3px 0 0 var(--pink)}
.zc-flyitem svg{opacity:.72; flex:none}

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
.zc-reportbar h1{margin:0 4px 0 0; font-size:16px; font-weight:500}
.zc-req{color:var(--pink); font-style:normal; margin-left:6px; vertical-align:top; font-size:15px; line-height:1}
.zc-reportbar-r{margin-left:auto; display:flex; align-items:center; gap:6px}
.zc-add{width:27px; height:27px; border:0; border-radius:3px; background:var(--pink); color:#fff; font-size:15px; line-height:1; cursor:pointer}
.zc-add:hover{background:var(--pinkd)}
.zc-btn{font:inherit; font-size:12.5px; height:27px; padding:0 10px; border-radius:3px; cursor:pointer; white-space:nowrap;
  display:inline-flex; align-items:center; gap:6px}
.zc-btn svg{width:13px; height:13px}
.zc-btn-out{background:var(--white); border:1px solid var(--line2); color:var(--ink2)}
.zc-btn-out:hover{border-color:var(--ink4); color:var(--ink)}
.zc-btn-pri{background:var(--pink); border:1px solid var(--pink); color:#fff; font-weight:500}
.zc-btn-pri:hover{background:var(--pinkd)}
.zc-rowbtn{font:inherit; font-size:12.5px; height:28px; padding:0 12px; border-radius:3px; cursor:pointer;
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
  max-width:none; vertical-align:top; padding:8px 7px}
.zc-stackline{line-height:1.5}
.zc-grid tbody tr{cursor:pointer}
.zc-grid tbody tr:hover td{background:var(--pinkl)}
.zc-grid tbody tr.sel td{background:var(--pinkl); box-shadow:inset 0 -1px 0 var(--pink)}
.zc-c-eye,.zc-c-chk{width:28px; text-align:center; color:var(--ink4); padding:0 !important}
.zc-c-chk input{accent-color:var(--pink); margin:0}
.zc-dots{color:var(--pink); font-weight:700; letter-spacing:1px}
.zc-empty{color:var(--ink3); text-align:center; padding:14px !important; font-size:12px; max-width:none !important}
.zc-footer{flex:none; display:flex; align-items:center; gap:14px; height:28px; padding:0 14px;
  border-top:1px solid var(--line2); background:var(--bg); font-size:12px; color:var(--ink2)}
.zc-selcount{color:var(--pink); font-weight:500}

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
.zc-kv th{width:47%; text-align:left; vertical-align:top; font-weight:400; color:var(--ink);
  background:#fafbfc; padding:9px 14px; border:1px solid var(--line)}
.zc-kv td{padding:9px 14px; border:1px solid var(--line); word-break:break-word; vertical-align:top}
.zc-filechip{display:inline-flex; align-items:center; gap:8px; border:1px solid var(--line2); border-radius:3px;
  padding:5px 10px; font-size:12.5px; color:var(--ink2); background:#fafbfc}
.zc-filechip svg{color:#2e9e6b; width:15px; height:15px; flex:none}
.zc-inlinewarn{margin:14px 0 0; font-size:12.5px; color:var(--bad); background:var(--badbg);
  border:1px solid #f1cdc9; border-radius:3px; padding:8px 11px; line-height:1.55}
.zc-addcomment{margin:22px 0 0; color:var(--pink); font-size:13px; cursor:pointer; display:flex; align-items:center; gap:7px}

.zc-formpage{position:fixed; top:0; right:0; bottom:0; left:30px; background:var(--white); z-index:60; display:flex; flex-direction:column}
.zc-formtitle{flex:none; padding:16px 30px; font-size:17px; font-weight:500; background:#fafbfc; border-bottom:1px solid var(--line)}
.zc-formscroll{flex:1; overflow-y:auto}
.zc-formbody{padding:22px 30px 20px}
.zc-formcheck{display:flex; align-items:center; gap:9px; font-size:13px; cursor:pointer; margin:6px 0 12px}
.zc-formcheck input{appearance:none; -webkit-appearance:none; width:16px; height:16px; margin:0;
  border:1.5px solid var(--line2); border-radius:3px; background:var(--white); cursor:pointer; position:relative}
.zc-formcheck input:checked{background:var(--pink); border-color:var(--pink)}
.zc-formcheck input:checked::after{content:''; position:absolute; left:4px; top:1px; width:5px; height:9px;
  border:solid #fff; border-width:0 1.6px 1.6px 0; transform:rotate(42deg)}
.zc-formrow{display:grid; grid-template-columns:190px 324px; align-items:center; gap:14px; padding:6px 0}
.zc-formrow.top{align-items:start}
.zc-formrow.top > label{padding-top:8px}
.zc-formrow > label{font-size:13px; color:var(--ink2)}
.zc-formfoot{display:flex; gap:10px; padding:16px 30px 24px; border-top:1px solid var(--line)}
.zc-in{font:inherit; font-size:13px; height:32px; padding:0 8px; border:1px solid var(--line2); border-radius:3px;
  background:var(--white); color:var(--ink); width:100%}
.zc-in:focus{border-color:var(--pink); outline:0}
.zc-in::placeholder{color:var(--ink4)}
.zc-ta{height:auto; padding:7px 8px; resize:vertical; font-family:var(--sans); line-height:1.5}
.zc-lookup{position:relative}
.zc-lookup .zc-in{padding-right:46px; appearance:none; -webkit-appearance:none;
  background-image:linear-gradient(45deg,transparent 50%,var(--ink3) 50%),linear-gradient(135deg,var(--ink3) 50%,transparent 50%);
  background-position:calc(100% - 15px) 14px,calc(100% - 10px) 14px; background-size:5px 5px,5px 5px; background-repeat:no-repeat}
.zc-lookup .zc-in.ph{color:var(--ink4)}
.zc-clear{position:absolute; right:26px; top:8px; border:0; background:none; color:var(--ink3); cursor:pointer; font-size:11px; padding:0 2px}
.zc-clear:hover{color:var(--bad)}
.zc-suffixed{display:flex}
.zc-suffixed .zc-in{border-radius:3px 0 0 3px}
.zc-suffix{width:38px; display:grid; place-items:center; font-size:13px; color:var(--ink2);
  border:1px solid var(--line2); border-left:0; border-radius:0 3px 3px 0; background:#fafbfc}
.zc-filerow{display:flex}
.zc-filebox{display:flex; align-items:center; color:var(--ink4); border-radius:3px 0 0 3px}
.zc-filebox.has{color:var(--pink)}
.zc-upload{width:38px; display:grid; place-items:center; border:1px solid var(--line2); border-left:0;
  border-radius:0 3px 3px 0; background:#fafbfc; color:var(--ink2); cursor:pointer}
.zc-upload:hover{color:var(--pink)}
.zc-chipwrap{position:relative}
.zc-chipbox{display:flex; flex-wrap:wrap; gap:5px; align-content:flex-start; min-height:70px; padding:7px 8px;
  border:1px solid var(--line2); border-radius:3px; background:var(--white); cursor:text}
/* Villa Name and Item Category read as locked on the edit form */
.zc-chipbox.locked{cursor:default; background:var(--white)}
.zc-chipbox.locked .zc-chip{color:var(--ink4)}
.zc-chipbox input{border:0; outline:0; font:inherit; font-size:13px; background:none; flex:1; min-width:70px; height:22px; padding:0}
.zc-chipbox input::placeholder{color:var(--ink4)}
.zc-chip{display:inline-flex; align-items:flex-start; gap:5px; font-size:12.5px; background:var(--white);
  border:1px solid var(--line2); border-radius:3px; padding:3px 7px 3px 5px; color:var(--ink2); line-height:1.35}
.zc-chip button{border:0; background:none; color:var(--ink4); cursor:pointer; font-size:10px; padding:1px 0 0}
.zc-chip button:hover{color:var(--bad)}
.zc-chipx{color:var(--ink4); font-size:10px; padding-top:1px}
.zc-droplist{position:absolute; z-index:10; top:calc(100% + 2px); left:0; right:0; margin:0; padding:2px; list-style:none;
  background:var(--white); border:1px solid var(--line2); border-radius:3px; box-shadow:0 8px 20px rgba(32,36,46,.14); max-height:220px; overflow-y:auto}
.zc-droplist button{width:100%; text-align:left; font:inherit; font-size:13px; padding:6px 8px; border:0; background:none; cursor:pointer; border-radius:2px}
.zc-droplist button:hover{background:var(--pinkl)}
@media (max-width:820px){ .zc{grid-template-columns:1fr; height:auto} .zc-rail{flex-direction:row; overflow-x:auto} .zc-panel{width:100vw} }
`}</style>
  );
}
