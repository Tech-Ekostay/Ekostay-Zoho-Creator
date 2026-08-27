import React, { useState } from "react";

/* ═══════════════════════════════════════════════════════════════════════════
   Backend Payments — the edit form, from screenshots 13-Aug-2026.

   This is the payments counterpart to Backend Expenses: the landing form for
   records the Haewaya integration writes, including `REFUND-stay-*` refunds.
   Two-column layout with a `Commercials` section, ~40 fields, Update / Cancel.

   NOT YET CAPTURED: the list report and the detail panel. Only the form exists
   here, so the module is deliberately form-only rather than guessed around.

   Replicated verbatim, including the parts that look wrong:
     · `Villa Name`, `Location` and `Bank Name` are plain TEXT fields holding
       raw foreign keys — `292482000000368045`, `292482000000170003`, `8` — not
       resolved names. The integration writes IDs and nothing resolves them
     · `Payment Reference Number` holds a Firebase Storage URL, where the same
       field on Payments is labelled `Haewaya UTR Number` and packs two
       comma-separated UTRs
     · `Payment Status` reads `PAID` in caps
     · `Bill No` appears TWICE, one blank
     · `Payment No.` and `Booking No.` carry trailing periods; no other form does
     · `TDS %` here, `TDS Percentage` on the Settings report, `TDS` on Payments
     · `ESI` here, `ESIC` in the Item Category master
     · `F & B Payments` with spaces around the ampersand, `F&B` everywhere else
     · The nav rail labels this module **Backbend Payments** while the form title
       reads **Backend Payments**
   ═══════════════════════════════════════════════════════════════════════════ */

/* Verbatim field order, left column then right, as the two-column form lays out.
   `pair` groups them into rows: [leftField, rightField]. */
const OVERVIEW = [
  [{ k: "payment", label: "Payment", type: "lookup", options: ["REFUND-stay-317374", "EKS/Haewaya/32053", "EKS/PY/20938"] },
   { k: "vendorName", label: "Vendor Name" }],
  [{ k: "coa", label: "COA" }, { k: "billNo", label: "Bill No" }],
  /* `Payment No.` and `Booking No.` keep their trailing periods */
  [{ k: "paymentNo", label: "Payment No." }, { k: "billNo1", label: "Bill No" }],
  [{ k: "requestedDate", label: "Requested Date" }, { k: "billingCycles", label: "Billing Cycles" }],
  [{ k: "paymentDate", label: "Payment Date" }, { k: "timestampDate", label: "Timestamp Date" }],
  /* Villa Name / Location / Bank Name hold unresolved foreign keys */
  [{ k: "dueDate", label: "Due Date" }, { k: "villaName", label: "Villa Name", raw: true }],
  [{ k: "itemCategory", label: "Item Category" }, { k: "location", label: "Location", raw: true }],
  [{ k: "masterCategory", label: "Master Category" }, { k: "headOffice", label: "Head Office" }],
  [{ k: "bankName", label: "Bank Name", raw: true }, { k: "bookingNo", label: "Booking No." }],
  [{ k: "expenseBy", label: "Expense By" }, { k: "paymentSource", label: "Payment Source" }],
  [{ k: "paymentBy", label: "Payment By" }, { k: "accountsRemarks", label: "Accounts Remarks", type: "textarea" }],
  [{ k: "managementRemarks", label: "Management Remarks", type: "textarea" },
   { k: "originalAmount", label: "Original Amount" }],
  [{ k: "particulars", label: "Particulars", type: "textarea" }, null],
];

const COMMERCIALS = [
  [{ k: "grossAmount", label: "Gross Amount" }, { k: "gstType", label: "GST Type" }],
  [{ k: "tdsPct", label: "TDS %" }, { k: "gst", label: "GST" }],
  [{ k: "tdsAmount", label: "TDS Amount" }, { k: "gstAmount", label: "GST Amount" }],
  [{ k: "pt", label: "PT" }, { k: "pf", label: "PF" }],
  [{ k: "esi", label: "ESI" }, { k: "invoiceAmount", label: "Invoice Amount" }],
  /* Payment Reference Number holds a URL on this form */
  [{ k: "payableAmount", label: "Payable Amount" },
   { k: "paymentReferenceNumber", label: "Payment Reference Number" }],
  [{ k: "paymentStatus", label: "Payment Status" }, null],
  [{ k: "fbPayments", label: "F & B Payments" }, null],
  [{ k: "haewayaId", label: "Haewaya ID" }, null],
  [{ k: "creatorId", label: "Creator ID" }, null],
  [{ k: "booksId", label: "Books ID" }, null],
];

/* Verbatim from the screenshot: a stay refund that landed through the API. */
const RECORD = {
  payment: "REFUND-stay-317374", vendorName: "Upendra",
  coa: "Expense", billNo: "", paymentNo: "REFUND-stay-317374", billNo1: "",
  requestedDate: "11-Aug-2026", billingCycles: "August-2026",
  paymentDate: "12-Aug-2026", timestampDate: "12-Aug-2026 17:54:34",
  dueDate: "12-Aug-2026", villaName: "292482000000368045",
  itemCategory: "STAY REFUND", location: "292482000000170003",
  masterCategory: "", headOffice: "",
  bankName: "8", bookingNo: "EKO10317374",
  expenseBy: "", paymentSource: "", paymentBy: "",
  accountsRemarks: "Gpay number - 7276541545",
  managementRemarks: "", originalAmount: "",
  particulars: "Guest have paid 4k more so refunding the amount",
  grossAmount: "4000", gstType: "", tdsPct: "", gst: "", tdsAmount: "", gstAmount: "",
  pt: "", pf: "", esi: "", invoiceAmount: "4000",
  payableAmount: "4000",
  paymentReferenceNumber: "https://firebasestorage.googleapis.com/v0/b/haewaya-app.appspot.com/o/payment_proof%2FEKO10317374.jpg?alt=media",
  paymentStatus: "PAID", fbPayments: "", haewayaId: "", creatorId: "", booksId: "",
};

/* The rail is longer than the eleven items the handoff records. Four more are
   visible on these screenshots; two of those are truncated in the live rail. */
const NAV = [
  ["Accounts", "calc"], ["Payments", "bank"], ["Bank", "bank2"], ["Bills", "bill"],
  ["Expenses", "exp"], ["Schedule Payments", "sched"], ["Expense Observations", "obs"],
  ["Masters", "mast"], ["Settings", "gear"], ["Backend Expenses", "exp"],
  ["Pending Approvals", "hourglass"], ["App Preferences", "box"], ["Payment Requests", "receipt"],
  ["Zoho app pointers - Payment Ap…", "box"],
  /* the rail spells it Backbend; the form title spells it Backend */
  ["Backbend Payments", "bank3"], ["Preferred Approver", "screen"], ["Ekostay …", "pct"],
];

export default function BackendPaymentsModule() {
  const [rec, setRec] = useState(RECORD);
  const set = (k, v) => setRec((p) => ({ ...p, [k]: v }));

  const control = (f) => {
    const v = rec[f.k] ?? "";
    if (f.type === "lookup") return (
      <div className="zc-lookup zc-focus">
        <select className="zc-in" value={v} onChange={(e) => set(f.k, e.target.value)}>
          <option value="">-Select-</option>
          {f.options.map((o) => <option key={o}>{o}</option>)}
        </select>
        {v && <button className="zc-clear" onClick={() => set(f.k, "")} aria-label="Clear">✕</button>}
      </div>
    );
    if (f.type === "textarea") return (
      <textarea className="zc-in zc-ta" rows={4} value={v} onChange={(e) => set(f.k, e.target.value)} />
    );
    return (
      <input className={"zc-in" + (f.raw ? " zc-raw" : "")} value={v}
        onChange={(e) => set(f.k, e.target.value)}
        title={f.raw ? "unresolved foreign key — the integration writes an ID here, not a name" : undefined} />
    );
  };

  const row = (pair, i) => (
    <div className="zc-tworow" key={i}>
      {[0, 1].map((side) => {
        const f = pair[side];
        if (!f) return <div key={side} />;
        return (
          <div className={"zc-formrow" + (f.type === "textarea" ? " top" : "")} key={side}>
            <label>{f.label}</label>
            {control(f)}
          </div>
        );
      })}
    </div>
  );

  return (
    <>
      <Style />
      <div className="zc">
        <aside className="zc-rail">
          <div className="zc-logo">ACC</div>
          {NAV.map(([label, icon]) => (
            <button key={label} className={"zc-navitem" + (label === "Backbend Payments" ? " on" : "")}>
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

          <div className="zc-formtitle">Backend Payments</div>

          <div className="zc-formscroll">
            <div className="zc-formbody">
              {OVERVIEW.map(row)}
              <div className="zc-formsection">Commercials</div>
              {COMMERCIALS.map(row)}
            </div>
            <div className="zc-formfoot">
              <button className="zc-btn zc-btn-pri">Update</button>
              <button className="zc-btn zc-btn-out" onClick={() => setRec(RECORD)}>Cancel</button>
            </div>
          </div>

          <footer className="zc-note">
            List report and detail panel not yet captured — this module is the form only.
          </footer>
        </div>
      </div>
    </>
  );
}

function Icon({ name }) {
  const a = { width: 16, height: 16, viewBox: "0 0 24 24", fill: "none", stroke: "currentColor", strokeWidth: 1.7, strokeLinecap: "round", strokeLinejoin: "round" };
  const s = {
    calc: <><rect x="4" y="2" width="16" height="20" rx="2" /><path d="M8 6h8M8 11h2M12 11h2M8 15h2M12 15h2M16 15v3" /></>,
    bank: <><path d="M3 21h18M5 21V9l7-5 7 5v12M9 21v-6h6v6" /></>,
    bank2: <><path d="M3 10l9-6 9 6M4 10v11h16V10M9 21v-7h6v7" /></>,
    bank3: <><path d="M4 10h16M6 10V6l6-3 6 3v4M5 10v10h14V10M9 20v-6h6v6" /></>,
    bill: <><path d="M6 2h12v20l-3-2-3 2-3-2-3 2V2z" /><path d="M9 7h6M9 11h6M9 15h3" /></>,
    exp: <><circle cx="12" cy="12" r="9" /><path d="M12 7v10M9.5 9.5h5M9.5 14.5h5" /></>,
    sched: <><rect x="3" y="5" width="18" height="16" rx="2" /><path d="M8 3v4M16 3v4M3 11h18" /></>,
    obs: <><path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6-10-6-10-6z" /><circle cx="12" cy="12" r="2.5" /></>,
    mast: <><ellipse cx="12" cy="6" rx="8" ry="3" /><path d="M4 6v6c0 1.7 3.6 3 8 3s8-1.3 8-3V6M4 12v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6" /></>,
    gear: <><circle cx="12" cy="12" r="3" /><path d="M12 2v3M12 19v3M4.2 4.2l2.1 2.1M17.7 17.7l2.1 2.1M2 12h3M19 12h3M4.2 19.8l2.1-2.1M17.7 6.3l2.1-2.1" /></>,
    hourglass: <><path d="M7 3h10M7 21h10M8 3v3l4 4 4-4V3M8 21v-3l4-4 4 4v3" /></>,
    box: <><path d="M21 8l-9-5-9 5v8l9 5 9-5V8z" /><path d="M3 8l9 5 9-5M12 13v8" /></>,
    receipt: <><path d="M5 3h14v18l-2-1.5-2 1.5-2-1.5-2 1.5-2-1.5L7 21l-2-1.5V3z" /><path d="M9 8h6M9 12h6" /></>,
    screen: <><rect x="2" y="4" width="20" height="13" rx="2" /><path d="M8 21h8" /></>,
    pct: <><circle cx="12" cy="12" r="9" /><path d="M9 9h.01M15 15h.01M15 9l-6 6" /></>,
    bell: <><path d="M18 8a6 6 0 10-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9" /><path d="M10.3 21a2 2 0 003.4 0" /></>,
    user: <><circle cx="12" cy="8" r="4" /><path d="M4 21c0-4 3.6-6 8-6s8 2 8 6" /></>,
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
  --line:#e6e9ee; --line2:#d2d7df; --bg:#f4f5f7; --white:#fff; --warn:#9a6206; --warnbg:#fdf3e2;
  --sans:'Inter',-apple-system,'Segoe UI',Roboto,sans-serif; --mono:'Roboto Mono',ui-monospace,monospace;
  font-family:var(--sans); color:var(--ink); background:var(--bg);
  display:grid; grid-template-columns:104px minmax(0,1fr); height:100vh; overflow:hidden;
  font-size:13px; -webkit-font-smoothing:antialiased;
}
.zc :focus-visible{outline:2px solid var(--pink); outline-offset:1px}
.zc-rail{background:var(--rail); display:flex; flex-direction:column; overflow-y:auto}
.zc-logo{background:var(--pink); color:#fff; font-weight:700; font-size:13px; letter-spacing:.1em; height:46px; display:grid; place-items:center; flex:none}
.zc-navitem{background:none; border:0; color:#bcc2d2; font:inherit; font-size:10px; line-height:1.3; padding:9px 5px 7px;
  display:grid; justify-items:center; gap:5px; cursor:pointer; text-align:center; flex:none; word-break:break-word}
.zc-navitem:hover{background:var(--rail2); color:#fff}
.zc-navitem.on{background:var(--pink); color:#fff}
.zc-main{display:flex; flex-direction:column; min-width:0; min-height:0; background:var(--white)}
.zc-appbar{height:42px; flex:none; display:flex; align-items:center; justify-content:space-between; padding:0 14px; border-bottom:1px solid var(--line)}
.zc-appname{font-size:15px; font-weight:500}
.zc-appbar-r{display:flex; align-items:center; gap:9px; color:var(--ink3)}
.zc-user{font-size:12.5px; color:var(--ink2)}
.zc-avatar{width:25px; height:25px; border-radius:50%; background:var(--line); color:var(--ink3); display:grid; place-items:center}
.zc-iconbtn{background:none; border:0; color:var(--ink3); cursor:pointer; padding:3px; display:grid; place-items:center; border-radius:3px}
.zc-iconbtn:hover{color:var(--ink); background:var(--bg)}
.zc-formtitle{flex:none; padding:16px 30px; font-size:17px; font-weight:500; background:#fafbfc; border-bottom:1px solid var(--line)}
.zc-formscroll{flex:1; overflow-y:auto; min-height:0}
.zc-formbody{padding:22px 30px 20px}
.zc-tworow{display:grid; grid-template-columns:minmax(0,620px) minmax(0,620px); gap:0 40px; align-items:start}
.zc-formrow{display:grid; grid-template-columns:200px minmax(0,324px); align-items:center; gap:14px; padding:6px 0}
.zc-formrow.top{align-items:start}
.zc-formrow.top > label{padding-top:8px}
.zc-formrow > label{font-size:13px; color:var(--ink2)}
.zc-formsection{margin:28px 0 16px; font-size:17px; font-weight:500; padding-bottom:12px; border-bottom:1px solid var(--line)}
.zc-formfoot{display:flex; gap:10px; padding:16px 30px 26px; border-top:1px solid var(--line)}
.zc-in{font:inherit; font-size:13px; height:32px; padding:0 8px; border:1px solid var(--line2); border-radius:3px;
  background:var(--white); color:var(--ink); width:100%}
.zc-in:focus{border-color:var(--pink); outline:0}
.zc-ta{height:auto; padding:7px 8px; resize:vertical; font-family:var(--sans); line-height:1.5}
/* fields holding an unresolved foreign key rather than a name */
.zc-raw{font-family:var(--mono); font-size:12px; background:var(--warnbg); border-color:#e8d2a4; color:var(--warn)}
.zc-lookup{position:relative}
.zc-lookup .zc-in{padding-right:46px; appearance:none; -webkit-appearance:none;
  background-image:linear-gradient(45deg,transparent 50%,var(--ink3) 50%),linear-gradient(135deg,var(--ink3) 50%,transparent 50%);
  background-position:calc(100% - 15px) 14px,calc(100% - 10px) 14px; background-size:5px 5px,5px 5px; background-repeat:no-repeat}
.zc-focus .zc-in{border-color:var(--pink)}
.zc-clear{position:absolute; right:26px; top:8px; border:0; background:none; color:var(--ink3); cursor:pointer; font-size:11px; padding:0 2px}
.zc-btn{font:inherit; font-size:12.5px; height:30px; padding:0 16px; border-radius:3px; cursor:pointer}
.zc-btn-pri{background:var(--pink); border:1px solid var(--pink); color:#fff; font-weight:500}
.zc-btn-pri:hover{background:var(--pinkd)}
.zc-btn-out{background:var(--white); border:1px solid var(--line2); color:var(--ink2)}
.zc-btn-out:hover{border-color:var(--ink4); color:var(--ink)}
.zc-note{flex:none; height:28px; display:flex; align-items:center; padding:0 14px; border-top:1px solid var(--line2);
  background:var(--bg); font-size:12px; color:var(--ink3)}
@media (max-width:1320px){ .zc-tworow{grid-template-columns:1fr} }
`}</style>
  );
}
