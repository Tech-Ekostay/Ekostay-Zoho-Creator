import React, { useState, useMemo, useEffect } from "react";

/* ═══════════════════════════════════════════════════════════════
   Backend Expenses — structural replica of the Zoho Creator screens.

   The underlying form has 140 top-level fields, 136 of them declared
   `type = text` regardless of what they hold. This is a landing table for a
   third-party payment provider's transaction feed, so field names, order and
   the untyped values are reproduced verbatim rather than tidied.

   Only `Payment` and `Matched Payments` are real lookups; `dup_checked` is a
   checkbox and `dup_key` a number. Everything else arrives as text.
   ═══════════════════════════════════════════════════════════════ */

/** Every field, in form-definition order. t: text | area | check | num | lookup | lookupList */
const FIELDS = [
  ["Payment", "Payment", "lookup"],
  ["Matched_Payments", "Matched Payments", "lookupList"],
  ["approve_status", "approve_status"], ["assets_path", "assets_path", "area"],
  ["balance", "balance"], ["business_name", "business_name"],
  ["bank_payout_details", "bank_payout_details"], ["bbps_txn_ref_id", "bbps_txn_ref_id"],
  ["bill_upload", "bill_upload"], ["cb_amount", "cb_amount"],
  ["check_in_date", "check_in_date"], ["check_out_date", "check_out_date"],
  ["circle_code", "circle_code"], ["code_title", "code_title"], ["cr_amount", "cr_amount"],
  ["cron_event_admin_verified", "cron_event_admin_verified"], ["cron_event_api_charges", "cron_event_api_charges"],
  ["cron_event_bill_upload", "cron_event_bill_upload"], ["cron_event_bill_verified", "cron_event_bill_verified"],
  ["cron_event_captured", "cron_event_captured"], ["cron_event_duplicate_bill", "cron_event_duplicate_bill"],
  ["cron_event_paid", "cron_event_paid"], ["cron_event_reversed_cr", "cron_event_reversed_cr"],
  ["cron_event_reversed_dr", "cron_event_reversed_dr"],
  ["date_field", "date"], ["dr_amount", "dr_amount"], ["duplicate_date", "duplicate_date"],
  ["dyn_qr_ref_id", "dyn_qr_ref_id"], ["fk_hccc_id", "fk_hccc_id"], ["fk_m_hccc_id", "fk_m_hccc_id"],
  ["fk_order_id", "fk_order_id"], ["fk_safe_id", "fk_safe_id"], ["hard_copy_bill", "hard_copy_bill"],
  ["invoice_bill_date", "invoice_bill_date"], ["invoice_receipt_url", "invoice_receipt_url"],
  ["is_admin_approval_require", "is_admin_approval_require"], ["is_parent", "is_parent"],
  ["is_qr_dynamic", "is_qr_dynamic"], ["is_splitted", "is_splitted"], ["is_verify_require", "is_verify_require"],
  ["isreinitiated", "isreinitiated"], ["lat", "lat"], ["long", "long"],
  ["lvl_four_amt", "lvl_four_amt"], ["lvl_four_msg", "lvl_four_msg"], ["lvl_four_name", "lvl_four_name"],
  ["lvl_one_amt", "lvl_one_amt"], ["lvl_one_msg", "lvl_one_msg"], ["lvl_one_name", "lvl_one_name"],
  ["lvl_three_amt", "lvl_three_amt"], ["lvl_three_msg", "lvl_three_msg"], ["lvl_three_name", "lvl_three_name"],
  ["lvl_two_amt", "lvl_two_amt"], ["lvl_two_msg", "lvl_two_msg"], ["lvl_two_name", "lvl_two_name"],
  ["lvl_verification_status", "lvl_verification_status"], ["multipe_hccc_names", "multipe_hccc_names"],
  ["olab", "olab"], ["olar", "olar"], ["olaret", "olaret"], ["olart", "olart"], ["olas", "olas"], ["olat", "olat"],
  ["paid_by", "paid_by"], ["payment_mode", "payment_mode"], ["payout_change_via", "payout_change_via"],
  ["payout_resp", "payout_resp"], ["payout_via", "payout_via"], ["pg_order_id", "pg_order_id"],
  ["pg_payment_id", "pg_payment_id"], ["pk_tr_id", "pk_tr_id"], ["pr_amount", "pr_amount"],
  ["public_tr_id", "public_tr_id"], ["rbl_trn_id", "rbl_trn_id"], ["receiver_details", "receiver_details"],
  ["receiver_name", "receiver_name"], ["receiver_pusid", "receiver_pusid"], ["receiver_upi_id", "receiver_upi_id"],
  ["reject_reason", "reject_reason"], ["relat_tr_id", "relat_tr_id"], ["remark_cat_name", "remark_cat_name"],
  ["remark_icon", "remark_icon"], ["remark_icon_id", "remark_icon_id"], ["remark_txt", "remark_txt"],
  ["rzp_payout_id", "rzp_payout_id"], ["split_tr_bill_upload_by", "split_tr_bill_upload_by"],
  ["splited_by", "splited_by"], ["sr_amount", "sr_amount"], ["subscription_attach", "subscription_attach"],
  ["tag_name", "tag_name"], ["tai_cost", "tai_cost"], ["tai_gst", "tai_gst"],
  ["tai_invoice_number", "tai_invoice_number"], ["tai_tds", "tai_tds"], ["tai_vendor_name", "tai_vendor_name"],
  ["total_paid_amount", "total_paid_amount"], ["tr_gst_fee", "tr_gst_fee"],
  ["tr_instrument_fee", "tr_instrument_fee"], ["tr_location", "tr_location"], ["tr_status", "tr_status"],
  ["tr_system_fee", "tr_system_fee"], ["tr_total_amount", "tr_total_amount"], ["tr_utr", "tr_utr"],
  ["transaction_for", "transaction_for"], ["transaction_id", "transaction_id"],
  ["transaction_type", "transaction_type"], ["transactor_avtar", "transactor_avtar"],
  ["transactor_id", "transactor_id"], ["transactor_name", "transactor_name"],
  ["txn_option", "txn_option"], ["verification_lvl", "verification_lvl"],
  ["verification_option", "verification_option"], ["verification_type", "verification_type"],
  ["verify_by_lvl_four", "verify_by_lvl_four"], ["verify_by_lvl_one", "verify_by_lvl_one"],
  ["verify_by_lvl_three", "verify_by_lvl_three"], ["verify_by_lvl_two", "verify_by_lvl_two"],
  ["verify_lvl_four", "verify_lvl_four"], ["verify_lvl_one", "verify_lvl_one"],
  ["verify_lvl_three", "verify_lvl_three"], ["verify_lvl_two", "verify_lvl_two"],
  ["webhook_resp", "webhook_resp"], ["approval_txt", "approval_txt"], ["approve_by", "approve_by"],
  ["lvl_four_approve_msg", "lvl_four_approve_msg"], ["lvl_four_approve_time", "lvl_four_approve_time"],
  ["lvl_one_approve_msg", "lvl_one_approve_msg"], ["lvl_one_approve_time", "lvl_one_approve_time"],
  ["lvl_three_approve_msg", "lvl_three_approve_msg"], ["lvl_three_approve_time", "lvl_three_approve_time"],
  ["lvl_two_approve_msg", "lvl_two_approve_msg"], ["lvl_two_approve_time", "lvl_two_approve_time"],
  ["time_stamp_date", "time_stamp_date"],
  ["multipe_hccc_names1", "multipe_hccc_names", "area"],
  ["remark_txt1", "remark_txt", "area"],
  ["receiver_details1", "receiver_details", "area"],
  ["dup_checked", "dup_checked", "check"], ["dup_key", "dup_key", "num"],
].map(([key, label, t]) => ({ key, label, t: t || "text" }));

/** Columns of the All Backend Expenses report, in the order Creator shows them. */
const COLUMNS = [
  ["date_field", "date", 176], ["Payment", "Payment", 172], ["addedTime", "Added Time", 158],
  /* renders empty in the live report even though the record holds a UPI deep link:
     the column is bound to the blank member of the receiver_details pair */
  ["receiver_details", "receiver_details", 190], ["dr_amount", "dr_amount", 104],
  ["fk_m_hccc_id", "fk_m_hccc_id", 118], ["multipe_hccc_names", "multipe_hccc_names", 200],
  /* the report carries a SECOND multipe_hccc_names column, which renders empty —
     one of the three duplicate field pairs. See the note above FIELDS. */
  ["multipe_hccc_names1", "multipe_hccc_names", 200],
  ["remark_cat_name", "remark_cat_name", 200], ["receiver_name", "receiver_name", 250],
  ["tai_vendor_name", "tai_vendor_name", 200], ["receiver_upi_id", "receiver_upi_id", 220],
  ["fk_hccc_id", "fk_hccc_id", 104], ["time_stamp_date", "time_stamp_date", 168],
  ["tr_utr", "tr_utr", 132], ["bbps_txn_ref_id", "bbps_txn_ref_id", 132],
  ["approve_status", "approve_status", 116], ["balance", "balance", 100],
  ["assets_path", "assets_path", 250], ["circle_code", "circle_code", 108],
  ["remark_icon", "remark_icon", 250], ["remark_icon_id", "remark_icon_id", 118],
  ["bank_payout_details", "bank_payout_details", 150], ["bill_upload", "bill_upload", 104],
  ["cb_amount", "cb_amount", 100], ["id", "ID", 172], ["tr_status", "tr_status", 100],
  ["lvl_verification_status", "lvl_verification_status", 154], ["transaction_id", "transaction_id", 122],
  ["tai_invoice_number", "tai_invoice_number", 142], ["tr_location", "tr_location", 122],
];

const PAYMENTS = ["EKS/Haewaya/31894", "EKS/Haewaya/31889", "EKS/Haewaya/31895", "EKS/Haewaya/31882", "EKS/Haewaya/31879", "EKS/PY/20812"];

const uid = () => Math.random().toString(36).slice(2, 9);

/* ── seed: values transcribed from the live report ───────────── */

const base = () => FIELDS.reduce((o, f) => { o[f.key] = f.t === "check" ? false : ""; return o; }, {});
const R = (o) => ({ ...base(), ...o });

const SEED = [
  R({ id: "292482000010543242", addedTime: "11-Aug-2026 17:44:32", addedUser: "ekostay",
    Payment: "EKS/Haewaya/31894", date_field: "2026-08-11 17:41:27", dr_amount: "200", cr_amount: "0",
    fk_m_hccc_id: "1037", fk_hccc_id: "0", multipe_hccc_names: "Central Office-Central Office",
    remark_cat_name: "Staff fuel", remark_txt: "Staff fuel - staff fuel",
    receiver_name: "Sonal Super Services", receiver_upi_id: "paytmqr28100505010112hcf9u8iwzf@paytm",
    receiver_details: "upi://pay?pa=paytmqr28100505010112hcf9u8iwzf@paytm&pn=Paytm%20Merchant&mc=5499&mode=02&orgid=000000&paytmqr=28100505010112HCF9U8IWZF&sign=MEQCIAmF8dm5YUXwNkVSHsFQ6flvoPDLcg+sBHUIoqO2V0j4AiAuzpz0gx0Far28mhh7kikMhf5nB5ym2OpM5sEAx1r6Iw==",
    tr_utr: "859335712236", approve_status: "0", balance: "22406", bank_payout_details: "na",
    bill_upload: "false", cb_amount: "0", tr_status: "paid", lvl_verification_status: "0",
    transaction_id: "2072215", business_name: "EKOSTAY LLP", duplicate_date: "0001-01-01T00:00:00Z",
    remark_icon: "transaction_remarks/stafffuel_1721190203.png", remark_icon_id: "2104",
    lat: "18.9886107", long: "72.8314067", lvl_one_amt: "3500", lvl_two_amt: "0", lvl_three_amt: "0", lvl_four_amt: "0",
    is_verify_require: "1", is_admin_approval_require: "0", is_parent: "0", is_splitted: "0", isreinitiated: "0",
    hard_copy_bill: "0", pk_tr_id: "0", pr_amount: "0", fk_order_id: "0", fk_safe_id: "0",
    public_tr_id: "c7696ece957d11f1a3d10a417dde49dc", olab: "0", olaret: "0", olart: "0", olas: "0",
    cron_event_admin_verified: "0", cron_event_api_charges: "0", cron_event_bill_upload: "0",
    cron_event_bill_verified: "0", cron_event_captured: "0", cron_event_duplicate_bill: "0",
    cron_event_paid: "0", cron_event_reversed_cr: "0", cron_event_reversed_dr: "0", dup_checked: false }),

  R({ id: "292482000010542056", addedTime: "11-Aug-2026 17:44:33", addedUser: "ekostay",
    Payment: "EKS/Haewaya/31889", date_field: "2026-08-11 16:42:24", dr_amount: "970", cr_amount: "0",
    fk_m_hccc_id: "5753", fk_hccc_id: "0", multipe_hccc_names: "Ezra Villa-Goa",
    remark_cat_name: "Housekeeping and Cleaning Material", remark_txt: "Housekeeping and Cleaning Material",
    receiver_name: "KAVITA SUPER MARKET", tai_vendor_name: "Gayatri sweet mart and baker",
    receiver_upi_id: "paytm.s1b14k3@pty", time_stamp_date: "2026-08-11 04:38:00",
    tr_utr: "849806252236", approve_status: "0", balance: "34803", bank_payout_details: "na",
    bill_upload: "true", cb_amount: "0", tr_status: "paid", lvl_verification_status: "1",
    transaction_id: "2071801", tai_invoice_number: "611", business_name: "EKOSTAY LLP",
    duplicate_date: "0001-01-01T00:00:00Z", remark_icon: "transaction_remarks/housekeeping_1710926425.png",
    remark_icon_id: "1506", is_verify_require: "1", tr_location: "",
    assets_path: "https://hywdocs.s3.ap-southeast-1.amazonaws.com/user_digital_docs/digital_doc_V8qDJ56MwuO279U6e6rjKvYoDr8D6rLt.jpg, user_digital_docs/digital_doc_tRD0a1I8e9qIFwmw7r6X4Weovwv4CcMy.jpg",
    dup_checked: false }),

  R({ id: "292482000010539092", addedTime: "11-Aug-2026 17:55:54", addedUser: "ekostay",
    Payment: "EKS/Haewaya/31895", date_field: "2026-08-11 17:46:51", dr_amount: "405", cr_amount: "0",
    fk_m_hccc_id: "4847", fk_hccc_id: "0", multipe_hccc_names: "General-Lonavala",
    remark_cat_name: "Staff fuel", remark_txt: "Staff fuel - staff fuel",
    receiver_name: "Police Training Center Khandala Welfare Fund", tai_vendor_name: "hp petrol pump",
    receiver_upi_id: "paytmqr60bjd9@ptys", tr_utr: "859878732236", approve_status: "0", balance: "15665",
    bank_payout_details: "na", bill_upload: "true", cb_amount: "0", tr_status: "paid",
    lvl_verification_status: "0", transaction_id: "2072256", tai_invoice_number: "377665",
    business_name: "EKOSTAY LLP", duplicate_date: "0001-01-01T00:00:00Z",
    remark_icon: "transaction_remarks/stafffuel_1724056880.png", remark_icon_id: "2589",
    assets_path: "https://hywdocs.s3.ap-southeast-1.amazonaws.com/user_digital_docs/digital_doc_4qroA2342Q3900HT4eZmD0Jhc9Kp2AwP.jpg, user_digital_docs/digital_doc_x8rcO84jyNcLEDVMBhc51GEiwaA9eGpo.jpg, user_digital_docs/digital_doc_Y3ecb8919e4JcepJl0uR7i9VD1IPSxoG.jpg, user_digital_docs/digital_doc_caQee66qEkxm9IN3k418PP4tU8w9s6EB.jpg",
    dup_checked: false }),

  R({ id: "292482000010539088", addedTime: "11-Aug-2026 17:42:30", addedUser: "ekostay",
    Payment: "EKS/Haewaya/31882", date_field: "2026-08-11 15:36:21", dr_amount: "400", cr_amount: "0",
    fk_m_hccc_id: "618", fk_hccc_id: "0", multipe_hccc_names: "Goa-General",
    remark_cat_name: "Staff fuel", remark_txt: "Staff fuel - staff fuel",
    receiver_name: "Indian Oil Petrol Pump Bhumika Petroleum", tai_vendor_name: "Bhumika petroleum",
    receiver_upi_id: "Q475442541@ybl", time_stamp_date: "2026-08-11 03:29:00",
    tr_utr: "833592952236", approve_status: "0", balance: "7673", bank_payout_details: "na",
    bill_upload: "true", cb_amount: "0", tr_status: "paid", lvl_verification_status: "1",
    transaction_id: "2071395", tai_invoice_number: "6505", business_name: "EKOSTAY LLP",
    duplicate_date: "0001-01-01T00:00:00Z", remark_icon: "transaction_remarks/stafffuel_1721190203.png",
    remark_icon_id: "2104",
    assets_path: "https://hywdocs.s3.ap-southeast-1.amazonaws.com/user_digital_docs/digital_doc_P2rSOcT7K9P1t7mSu3rqD5e7GTkmtsPf.jpg, user_digital_docs/digital_doc_9r3oPcctemvarA8h3G85g7qcLSwF03La.jpg, user_digital_docs/digital_doc_rhMAQuAIcOGMB9kWD9PPLDe3Thz4Hw0E.jpg, user_digital_docs/digital_doc_aGhAc9AWnT2hljC5369c9gjJPaoP3e21.jpg, user_digital_docs/digital_doc_PqA93Pzh0DG3hS0e3915uHkmjwxeL8yV.jpg, user_digital_docs/digital_doc_5a54zPgPluuurRNyUJ2Dw40t7i7FiXie.jpg",
    dup_checked: false }),
];

/* ═══════════════════════════════════════════════════════════════ */

const NAV = [
  ["Accounts", "calc"], ["Payments", "bank"], ["Bank", "bank2"], ["Bills", "bill"], ["Expenses", "exp"],
  ["Schedule Payments", "sched"], ["Expense Observations", "obs"], ["Masters", "mast"],
  ["Settings", "gear"], ["Backend Expenses", "exp"], ["Pending Approvals", "hour"],
];

export default function BackendExpensesModule() {
  const [rows, setRows] = useState(SEED);
  const [openId, setOpenId] = useState(null);
  const [editing, setEditing] = useState(null);
  const [search, setSearch] = useState({ field: "receiver_name", value: "" });
  const [showSearch, setShowSearch] = useState(true);
  const [checked, setChecked] = useState(new Set());
  const [sort, setSort] = useState({ key: "addedTime", dir: "desc" });

  const view = useMemo(() => {
    let r = rows;
    if (search.value.trim()) {
      const q = search.value.toLowerCase();
      r = r.filter((x) => String(x[search.field] ?? "").toLowerCase().includes(q));
    }
    const dir = sort.dir === "asc" ? 1 : -1;
    return [...r].sort((a, b) => {
      const av = a[sort.key] ?? "", bv = b[sort.key] ?? "";
      const na = +av, nb = +bv;
      if (!Number.isNaN(na) && !Number.isNaN(nb) && av !== "" && bv !== "") return (na - nb) * dir;
      return String(av).localeCompare(String(bv)) * dir;
    });
  }, [rows, search, sort]);

  const open = openId ? rows.find((r) => r.id === openId) : null;
  const openIdx = view.findIndex((r) => r.id === openId);
  const save = (r) => {
    setRows((p) => (p.some((x) => x.id === r.id) ? p.map((x) => (x.id === r.id ? r : x)) : [r, ...p]));
    setEditing(null); setOpenId(r.id);
  };

  return (
    <>
      <Style />
      <div className="zc">
        <aside className="zc-rail">
          <div className="zc-logo">ACC</div>
          {NAV.map(([label, icon]) => (
            <button key={label} className={"zc-navitem" + (label === "Backend Expenses" ? " on" : "")}>
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
            <h1>All Backend Expenses</h1>
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
                  {["receiver_name", "Payment", "tr_utr", "transaction_id", "remark_cat_name", "multipe_hccc_names", "tr_status", "receiver_upi_id"].map((f) => <option key={f}>{f}</option>)}
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
                      onChange={(e) => setChecked(e.target.checked ? new Set(view.map((r) => r.id)) : new Set())} aria-label="Select all" />
                  </th>
                  <th style={{ width: 104 }}>Update</th>
                  {COLUMNS.map(([k, label, w]) => {
                    const on = sort.key === k;
                    return (
                      <th key={k} style={{ width: w }}>
                        <button className="zc-th" onClick={() => setSort({ key: k, dir: on && sort.dir === "asc" ? "desc" : "asc" })}>
                          <span>{label}</span><i className={"zc-caret" + (on ? " on " + sort.dir : "")} />
                        </button>
                      </th>
                    );
                  })}
                </tr>
              </thead>
              <tbody>
                {view.map((r) => (
                  <tr key={r.id} className={openId === r.id ? "sel" : ""} onClick={() => setOpenId(r.id)}>
                    <td className="zc-c-eye">{openId === r.id ? <span className="zc-dots">···</span> : null}</td>
                    <td className="zc-c-chk" onClick={(e) => e.stopPropagation()}>
                      <input type="checkbox" checked={checked.has(r.id)} aria-label={`Select ${r.transaction_id}`}
                        onChange={() => setChecked((p) => { const n = new Set(p); n.has(r.id) ? n.delete(r.id) : n.add(r.id); return n; })} />
                    </td>
                    <td onClick={(e) => e.stopPropagation()}>
                      <button className="zc-rowbtn">Update</button>
                    </td>
                    {COLUMNS.map(([k]) => (
                      <td key={k} className={/amount|balance|_id$|utr|^id$|^lat$|^long$/i.test(k) ? "mono" : ""}>
                        {/* assets_path holds comma-separated S3 URLs and wraps over
                            several lines, which is what drives row height here */}
                        {k === "assets_path"
                          ? String(r[k] ?? "").split(",").map((u, i) => <div key={i} className="zc-wrapline">{u.trim()}</div>)
                          : String(r[k] ?? "")}
                      </td>
                    ))}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          <footer className="zc-footer">
            {/* the live report reads `Showing 1000 of ###` — it pages at 1000 and the
                total is too wide for the footer, so Creator prints ### in pink */}
            <span>Showing {Math.min(view.length, 1000)} of {rows.length > 1000
              ? <b className="zc-overflow">###</b> : rows.length}</span>
            {checked.size > 0 && <span className="zc-selcount">{checked.size} selected</span>}
          </footer>
        </div>

        {open && (
          <DetailPanel r={open} onClose={() => setOpenId(null)} onEdit={() => setEditing(open)}
            onDelete={() => { setRows((p) => p.filter((x) => x.id !== open.id)); setOpenId(null); }}
            onPrev={openIdx > 0 ? () => setOpenId(view[openIdx - 1].id) : null}
            onNext={openIdx < view.length - 1 ? () => setOpenId(view[openIdx + 1].id) : null} />
        )}
        {editing && <BackendForm initial={editing === "new" ? null : editing} onCancel={() => setEditing(null)} onSave={save} />}
      </div>
    </>
  );
}

/* ── detail panel ────────────────────────────────────────────── */

/** Creator lists these fields alphabetically — no layout was ever applied. */
const ALPHA = FIELDS.filter((f) => !["Payment", "Matched_Payments", "assets_path", "business_name",
  "time_stamp_date", "multipe_hccc_names1", "remark_txt1", "receiver_details1", "dup_checked", "dup_key"].includes(f.key))
  .sort((a, b) => a.key.localeCompare(b.key));
const TAIL = ["assets_path", "business_name", "Payment", "time_stamp_date", "multipe_hccc_names1",
  "remark_txt1", "receiver_details1", "Matched_Payments", "dup_checked", "dup_key"]
  .map((k) => FIELDS.find((f) => f.key === k));

function DetailPanel({ r, onClose, onEdit, onDelete, onPrev, onNext }) {
  const [more, setMore] = useState(false);
  useEffect(() => {
    const h = (e) => e.key === "Escape" && onClose();
    window.addEventListener("keydown", h); return () => window.removeEventListener("keydown", h);
  }, [onClose]);

  const val = (f) => {
    const v = r[f.key];
    if (f.t === "check") return String(!!v);
    // the textarea twins mirror their text counterparts in the live data
    if (f.key.endsWith("1")) return String(r[f.key.slice(0, -1)] ?? "");
    return String(v ?? "");
  };

  return (
    <aside className="zc-panel" role="dialog" aria-label={`Backend expense ${r.transaction_id}`}>
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
        <table className="zc-kv"><tbody>
          {[...ALPHA, ...TAIL].map((f) => (
            <tr key={f.key}>
              <th>{f.label}</th>
              <td className={/amount|balance|utr|_id$|^lat$|^long$|^public_tr_id$/i.test(f.key) ? "mono brk" : "brk"}>{val(f)}</td>
            </tr>
          ))}
        </tbody></table>
        <p className="zc-addcomment">Add a comment</p>
      </div>
    </aside>
  );
}

/* ── form ────────────────────────────────────────────────────── */

function BackendForm({ initial, onCancel, onSave }) {
  const [r, setR] = useState(() => (initial ? { ...initial } : { ...base(), id: String(292482000010550000 + Math.floor(Math.random() * 9999)), addedTime: stamp(), addedUser: "Husain Khatumdi" }));
  const set = (k, v) => setR((p) => ({ ...p, [k]: v }));

  return (
    <div className="zc-modalback">
      <div className="zc-modal" role="dialog" aria-label="Backend Expenses">
        <header className="zc-modalbar">
          <span>Backend Expenses</span>
          <button className="zc-iconbtn zc-sq" onClick={onCancel} aria-label="Close">✕</button>
        </header>

        <div className="zc-modalbody">
          <p className="zc-note">
            140 fields, arriving from the payment provider's transaction feed. Everything except
            <b> Payment</b>, <b> Matched Payments</b>, <b> dup_checked</b> and <b> dup_key</b> is written by the sync.
          </p>

          <div className="zc-form3">
            {FIELDS.map((f) => (
              <div className="zc-frow" key={f.key}>
                <label title={f.key}>{f.label}</label>
                <div className="zc-fctl">
                  {f.t === "lookup" ? (
                    <select className="zc-in" value={r[f.key]} onChange={(e) => set(f.key, e.target.value)}>
                      <option value="">-Select-</option>
                      {PAYMENTS.map((p) => <option key={p} value={p}>{p}</option>)}
                    </select>
                  ) : f.t === "lookupList" ? (
                    <select className="zc-in" multiple size={3}
                      value={String(r[f.key] || "").split(",").filter(Boolean)}
                      onChange={(e) => set(f.key, [...e.target.selectedOptions].map((o) => o.value).join(","))}>
                      {PAYMENTS.map((p) => <option key={p} value={p}>{p}</option>)}
                    </select>
                  ) : f.t === "check" ? (
                    <label className="zc-check">
                      <input type="checkbox" checked={!!r[f.key]} onChange={(e) => set(f.key, e.target.checked)} />
                      <span>{f.label}</span>
                    </label>
                  ) : f.t === "area" ? (
                    <textarea className="zc-in zc-ta" rows={2} value={r[f.key]} onChange={(e) => set(f.key, e.target.value)} />
                  ) : (
                    <input className={"zc-in" + (/amount|balance|_id$|utr|^lat$|^long$/i.test(f.key) ? " mono" : "")}
                      value={r[f.key]} onChange={(e) => set(f.key, e.target.value)} />
                  )}
                </div>
              </div>
            ))}
          </div>
        </div>

        <footer className="zc-modalfoot">
          <button className="zc-btn zc-btn-pri" onClick={() => onSave(r)}>{initial ? "Update" : "Submit"}</button>
          <button className="zc-btn zc-btn-out" onClick={onCancel}>{initial ? "Cancel" : "Reset"}</button>
        </footer>
      </div>
    </div>
  );
}

const MA = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];
function stamp() {
  const d = new Date(); const p = (n) => String(n).padStart(2, "0");
  return `${p(d.getDate())}-${MA[d.getMonth()]}-${d.getFullYear()} ${p(d.getHours())}:${p(d.getMinutes())}:${p(d.getSeconds())}`;
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
    hour: <><path d="M7 2h10M7 22h10M7 2c0 5 5 6 5 10M17 2c0 5-5 6-5 10M7 22c0-5 5-6 5-10M17 22c0-5-5-6-5-10" /></>,
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
  --warn:#9a6206; --warnbg:#fdf3e2;
  --sans:'Inter',-apple-system,'Segoe UI',Roboto,sans-serif; --mono:'Roboto Mono',ui-monospace,monospace;
  font-family:var(--sans); color:var(--ink); background:var(--bg);
  display:grid; grid-template-columns:104px minmax(0,1fr); height:100vh; overflow:hidden;
  font-size:13px; -webkit-font-smoothing:antialiased;
}
.zc :focus-visible{outline:2px solid var(--pink); outline-offset:1px}
.mono{font-family:var(--mono); font-variant-numeric:tabular-nums; font-size:12px; letter-spacing:-.2px}
.brk{word-break:break-all}

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
.zc-reportbar h1{margin:0; font-size:16px; font-weight:500}
.zc-reportbar-r{margin-left:auto; display:flex; align-items:center; gap:6px}
.zc-add{width:27px; height:27px; border:0; border-radius:3px; background:var(--pink); color:#fff; font-size:15px; line-height:1; cursor:pointer}
.zc-add:hover{background:var(--pinkd)}
.zc-btn{font:inherit; font-size:12.5px; height:27px; padding:0 10px; border-radius:3px; cursor:pointer; white-space:nowrap}
.zc-btn-out{background:var(--white); border:1px solid var(--line2); color:var(--ink2)}
.zc-btn-out:hover{border-color:var(--ink4); color:var(--ink)}
.zc-btn-pri{background:var(--pink); border:1px solid var(--pink); color:#fff; font-weight:500}
.zc-btn-pri:hover{background:var(--pinkd)}
.zc-rowbtn{font:inherit; font-size:11px; height:20px; padding:0 8px; border:1px solid var(--pink); color:var(--pink);
  background:var(--white); border-radius:3px; cursor:pointer; white-space:nowrap}
.zc-rowbtn:hover{background:var(--pink); color:#fff}

.zc-searchrow{flex:none; display:flex; align-items:center; padding:6px 14px; border-bottom:1px solid var(--line); background:var(--bg)}
.zc-searchlabel{font-size:10px; font-weight:600; letter-spacing:.06em; color:var(--ink3); border:1px solid var(--line2);
  border-right:0; background:var(--white); padding:5px 8px; border-radius:3px 0 0 3px}
.zc-searchchip{display:flex; align-items:center; gap:5px; border:1px solid var(--pink); border-radius:0 3px 3px 0; background:var(--white); padding:2px 6px 2px 4px}
.zc-searchchip select,.zc-searchchip input{border:0; outline:0; font:inherit; font-size:12.5px; background:none; color:var(--ink)}
.zc-searchchip input{width:150px}
.zc-op{font-size:12px; color:var(--ink3)}
.zc-searchchip button{border:0; background:none; color:var(--pink); cursor:pointer; font-size:10px; padding:0 2px}

.zc-gridwrap{flex:1; overflow:auto; min-height:0}
.zc-grid{border-collapse:separate; border-spacing:0; font-size:12.5px; width:max-content; min-width:100%}
.zc-grid thead th{position:sticky; top:0; z-index:2; background:var(--white); text-align:left; font-weight:600; font-size:11.5px;
  color:var(--ink); padding:0 7px; height:31px; border-bottom:1px solid var(--line2); border-right:1px solid var(--line); white-space:nowrap}
.zc-th{width:100%; height:31px; display:flex; align-items:center; gap:4px; justify-content:space-between; font:inherit;
  font-weight:600; font-size:11.5px; color:inherit; background:none; border:0; cursor:pointer; padding:0}
.zc-caret{width:0; height:0; border-left:3.5px solid transparent; border-right:3.5px solid transparent;
  border-top:4.5px solid var(--ink4); opacity:.5; flex:none}
.zc-caret.on{opacity:1; border-top-color:var(--pink)}
.zc-caret.on.asc{border-top:0; border-bottom:4.5px solid var(--pink)}
.zc-grid-tall tbody td{height:auto; white-space:normal; overflow:visible; text-overflow:clip;
  max-width:none; vertical-align:top; padding:7px}
.zc-wrapline{line-height:1.45; word-break:break-all}
.zc-overflow{color:var(--pink); font-weight:600}
.zc-grid tbody td{padding:0 7px; border-bottom:1px solid var(--line); border-right:1px solid var(--line);
  height:27px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:0}
.zc-grid tbody tr{cursor:pointer}
.zc-grid tbody tr:nth-child(even) td{background:#fafbfc}
.zc-grid tbody tr:hover td{background:var(--pinkl)}
.zc-grid tbody tr.sel td{background:var(--pinkl); box-shadow:inset 0 -1px 0 var(--pink)}
.zc-c-eye,.zc-c-chk{width:28px; text-align:center; color:var(--ink4); padding:0 !important}
.zc-c-chk input{accent-color:var(--pink); margin:0}
.zc-dots{color:var(--pink); font-weight:700; letter-spacing:1px}
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
.zc-kv{width:100%; border-collapse:collapse; font-size:12.5px; table-layout:fixed}
.zc-kv th{width:230px; text-align:left; font-weight:400; color:var(--ink2); background:#fafbfc; padding:6px 9px; border:1px solid var(--line); word-break:break-word}
.zc-kv td{padding:6px 9px; border:1px solid var(--line)}
.zc-addcomment{margin:20px 0 0; color:var(--pink); font-size:12.5px; cursor:pointer}

.zc-modalback{position:fixed; inset:0; background:rgba(32,36,46,.35); z-index:60; display:grid; place-items:start center; padding:18px}
.zc-modal{background:var(--white); width:min(1400px,100%); max-height:calc(100vh - 36px); border-radius:4px;
  box-shadow:0 18px 50px rgba(32,36,46,.28); display:flex; flex-direction:column}
.zc-modalbar{flex:none; display:flex; align-items:center; justify-content:space-between; padding:10px 16px;
  border-bottom:1px solid var(--line); background:#fafbfc; font-size:15px; font-weight:500}
.zc-modalbody{overflow-y:auto; padding:14px 18px 20px}
.zc-modalfoot{flex:none; display:flex; gap:8px; padding:10px 18px; border-top:1px solid var(--line)}
.zc-note{margin:0 0 14px; font-size:12.5px; color:var(--warn); background:var(--warnbg);
  border:1px solid #e8c07a; border-radius:3px; padding:8px 11px; line-height:1.5}
.zc-form3{display:grid; grid-template-columns:repeat(3,1fr); gap:0 30px}
.zc-frow{display:grid; grid-template-columns:190px minmax(0,1fr); align-items:start; gap:8px; padding:3px 0; min-height:30px}
.zc-frow > label{font-size:11.5px; color:var(--ink2); padding-top:5px; word-break:break-word}
.zc-fctl{min-width:0}
.zc-in{font:inherit; font-size:12px; height:26px; padding:0 6px; border:1px solid var(--line2); border-radius:3px;
  background:var(--white); color:var(--ink); width:100%}
.zc-in:focus{border-color:var(--pink); outline:0}
.zc-ta{height:auto; padding:5px 6px; resize:vertical; line-height:1.4}
.zc-in[multiple]{height:auto; padding:3px}
.zc-check{display:inline-flex; align-items:center; gap:6px; font-size:12px; cursor:pointer; min-height:26px}
.zc-check input{accent-color:var(--pink)}

@media (max-width:1400px){ .zc-form3{grid-template-columns:repeat(2,1fr)} }
@media (max-width:900px){ .zc-form3{grid-template-columns:1fr} }
@media (max-width:820px){ .zc{grid-template-columns:1fr; height:auto} .zc-rail{flex-direction:row; overflow-x:auto} .zc-panel{width:100vw} }
`}</style>
  );
}
