import { useEffect, useMemo, useState } from 'react';
import VendorPicker from '../components/VendorPicker';
import { inr } from '../lib/format';

/**
 * The direct Payment form — a payment entered on its own, not from a bill.
 *
 * WHY IT EXISTS. §7.2's `Create_Payment` was the only creation path the three
 * context docs describe, so Payments' `+` used to send the user to Bills. Husain
 * corrected that on 25-Aug-2026: **a payment can be entered directly.** Sending
 * someone to Bills taught a data model that is not the real one.
 *
 * FIELD ORDER IS NOT INFERRED — unusually for this project. It is parsed from the
 * `Payment` form in `deluge/Accounts.ds` (lines 7273-8673): 130 entries across 10
 * sections, each carrying `row` and `column`, which IS the layout. Section names
 * below are Creator's own (`Commercials`, `Admin`). Same route used for the Villa
 * form. So a reviewer comparing against the live screen should find the order right;
 * what they will find missing is listed at the foot of the form, on screen.
 *
 * THE PICKER FILTERS ARE THE DS's TOO, and two of them corrected shipped code:
 *
 *   COA           -> COA[Hide == true]        47 of 144 — `Hide` means selectable
 *                                             here, not hidden. Settles the open
 *                                             COA `hide` question in addendum §17.5.
 *   Vendor Name   -> Vendor_Master[Main_Primary is not null]
 *                                             6,957 — trade vendors, EXCLUDING the
 *                                             1,107 customer payees, and INCLUDING
 *                                             the 112 merged-away ones. The opposite
 *                                             of what this app had.
 *   Bank Name     -> COA[Bank == true]        the load-bearing flag, not Account_Type
 *   Villa Name    -> Villa[Hide_From_Payments == false]
 *   Item Category -> Item_Category[Disable == false]
 *
 * WHAT THIS DELIBERATELY DOES NOT DO. Nothing here computes money. There is no
 * client-side derivation of Payable, no GST arithmetic and no split-equally: §6.3's
 * remainder convention truncates at paisa and puts the whole dropped remainder on
 * the LAST row, and the spec says "Reproduce exactly. Do not substitute banker's
 * rounding." Re-implementing that in JavaScript would be re-deciding it. The server
 * validates the split against the gross (§6.4 rule 1 / §7.4's missing check).
 */

/** Sections in DS row order, with Creator's own names. */
/*
 * EDIT MODE. `payment` is the `/api/payments/{id}` payload, or null when adding.
 *
 * Husain: "Right now, on edit nothing is working." It was not working because the
 * module refused to open this form at all, citing §7.6 — a misreading. §7.6 forbids
 * DELETING a settled payment and REISSUING its number; the DS gives All Payments'
 * `Update Payment` action no `condition` whatsoever, so Creator lets any payment be
 * opened and saved. One form serves both, as Creator's does.
 */
export default function PaymentForm({ options, onClose, onSaved, payment = null }) {
  const editing = payment !== null;

  const [form, setForm] = useState({
    coa_account_id: '',
    vendor_id: '',
    bank_coa_account_id: '',
    location_id: '',
    item_category_id: '',
    master_category_id: '',
    tds_rate_id: '',
    tax_id: '',
    status: 'Draft',
    payment_status: 'Pending',
    payment_mode: '',
    gst_type: '',
    payment_date: '',
    due_date: '',
    amount: '',
    gst_amount: '',
    tds_amount: '',
    pt_amount: '',
    esic_amount: '',
    pf_amount: '',
    payable_amount: '',
    total_amount: '',
    original_amount: '',
    particulars: '',
    remarks: '',
    management_remarks: '',
    payment_reference_number: '',
    payment_by: '',
    expense_by: '',
    ca_email: '',
    payment_source: '',
    haewaya_utr_number: '',
    billing_year: '',
    billing_months: [],
    billing_cycle_ids: [],
    gst_needed: false,
    split_equally: false,
    multiple_villa: false,
    verified: false,
    accounts_bills: false,
  });

  const [legs, setLegs] = useState([]);

  /*
   * SEED FROM THE RECORD ON OPEN, once.
   *
   * Keyed on the payment's id rather than the object, because `show()` is re-fetched
   * after a save and a fresh object identity would re-seed the form over whatever the
   * user had just typed.
   *
   * Only keys the form already declares are copied. A blind spread would pour the
   * report's display columns ("Payment No", "Vendor Name") into form state alongside
   * the snake_case field names, and the first save would post both.
   */
  useEffect(() => {
    if (!editing) return;

    setForm((current) => {
      const seeded = { ...current };

      for (const key of Object.keys(current)) {
        const value = payment[key];

        if (value === undefined || value === null) continue;

        seeded[key] = Array.isArray(current[key])
          ? (Array.isArray(value) ? value.map(String) : String(value).split(',').filter(Boolean))
          : (typeof current[key] === 'boolean' ? Boolean(value) : String(value));
      }

      return seeded;
    });

    // The split grid is separate state, and it is not optional scenery: §5.2 makes
    // each leg the ledger entry, and an edit form that loaded the header without the
    // legs would save a payment whose legs no longer tie to its gross.
    setLegs((payment.legs ?? []).map((leg) => ({ ...leg })));
  }, [editing, payment?.id]);

  /*
   * CREATOR'S `on user input` HANDLERS, which this form did not have.
   *
   * Picking a TDS rate in Creator does not just store a rate: `OnInputTDSCE`
   * (Accounts.ds:23348) recomputes TDS Amount, Invoice Amount and Payable Amount,
   * and then rewrites EVERY split leg's TDS, GST and Total. Same from
   * OnInputGrossAmountCE and OnInputGSTCE. Without it the form stored what you typed
   * and derived nothing.
   *
   * THE ARITHMETIC IS A SERVER ROUND TRIP, deliberately. `PaymentFormCalculator`
   * shares `Money::percentageOf()` with the bill split, so a rate applied here and
   * the same rate applied on a saved bill cannot drift. A JS copy would be a second
   * implementation, and §6.3 already warns that per-row TDS does not sum to header
   * TDS — with two implementations, a discrepancy that is CORRECT becomes
   * indistinguishable from one that is a bug.
   */
  const [derived, setDerived] = useState(null);
  const [calcWarnings, setCalcWarnings] = useState([]);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState(null);
  const [fieldErrors, setFieldErrors] = useState({});

  const set = (key, value) => setForm((c) => ({ ...c, [key]: value }));

  /*
   * Debounced because `amount` fires per keystroke. 250ms is short enough that the
   * derived fields feel immediate and long enough not to send a request per digit.
   */
  const recalcKey = JSON.stringify([
    form.amount, form.gst_amount, form.tds_rate_id, form.tax_id,
    form.item_category_id, form.pf_amount, form.pt_amount, form.esic_amount,
    legs.map((l) => l.amount),
  ]);

  useEffect(() => {
    // Nothing to derive from an empty gross.
    if (form.amount === '' && form.tds_rate_id === '' && form.tax_id === '') {
      setDerived(null);
      setCalcWarnings([]);

      return undefined;
    }

    const timer = setTimeout(() => {
      fetch('/api/payments/recalculate', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({
          amount: form.amount === '' ? null : form.amount,
          gst_amount: form.gst_amount === '' ? null : form.gst_amount,
          pf: form.pf_amount === '' ? null : form.pf_amount,
          pt: form.pt_amount === '' ? null : form.pt_amount,
          esic: form.esic_amount === '' ? null : form.esic_amount,
          tds_rate_id: form.tds_rate_id === '' ? null : Number(form.tds_rate_id),
          tax_id: form.tax_id === '' ? null : Number(form.tax_id),
          item_category_id: form.item_category_id === '' ? null : Number(form.item_category_id),
          legs: legs.map((l) => ({ amount: l.amount === '' ? 0 : Number(l.amount) })),
        }),
      })
        .then((r) => (r.ok ? r.json() : Promise.reject(new Error(`HTTP ${r.status}`))))
        .then((body) => {
          setDerived(body);
          setCalcWarnings(body.warnings ?? []);

          /*
           * The derived fields are WRITTEN INTO the form, not merely displayed —
           * that is what Creator does, and they are what gets saved. The user can
           * still overtype any of them afterwards, which is also Creator's
           * behaviour: the handler fires on input, it does not lock the field.
           */
          setForm((c) => ({
            ...c,
            tds_amount: body.tds_amount,
            gst_amount: body.gst_amount,
            total_amount: body.total_amount,
            payable_amount: body.payable_amount,
          }));

          // And the legs, which is the half that was missing entirely.
          if (body.legs?.length) {
            setLegs((current) => current.map((leg, i) => ({
              ...leg,
              tds_amount: body.legs[i]?.tds_amount ?? '',
              gst_amount: body.legs[i]?.gst_amount ?? '',
              total_amount: body.legs[i]?.total_amount ?? '',
            })));
          }
        })
        .catch(() => { /* leave the typed values alone on a failed recalc */ });
    }, 250);

    return () => clearTimeout(timer);
  }, [recalcKey]);   // eslint-disable-line react-hooks/exhaustive-deps

  /** The running leg total, shown beside the gross so the §6.4 tie is visible. */
  const legTotal = useMemo(
    () => legs.reduce((sum, leg) => sum + (Number(leg.amount) || 0), 0),
    [legs],
  );

  const grossNumber = Number(form.amount) || 0;
  const balanced = legs.length === 0 || Math.round(legTotal) === Math.round(grossNumber);

  const save = () => {
    setSaving(true);
    setError(null);
    setFieldErrors({});

    const payload = {
      ...form,
      billing_year: form.billing_year === '' ? null : Number(form.billing_year),
      billing_cycle_ids: form.billing_cycle_ids.map(Number),
      legs: legs
        .filter((l) => l.villa_id && l.item_category_id && l.billing_cycle_id)
        .map((l) => ({
          villa_id: Number(l.villa_id),
          item_category_id: Number(l.item_category_id),
          billing_cycle_id: Number(l.billing_cycle_id),
          amount: l.amount === '' ? 0 : Number(l.amount),
        })),
    };

    // Numeric fields go as null rather than '' so the server sees "not supplied".
    for (const key of ['amount', 'gst_amount', 'tds_amount', 'pt_amount', 'esic_amount',
      'pf_amount', 'payable_amount', 'total_amount', 'original_amount']) {
      if (payload[key] === '') payload[key] = null;
    }
    for (const key of ['coa_account_id', 'vendor_id', 'bank_coa_account_id', 'location_id',
      'item_category_id', 'master_category_id', 'tds_rate_id', 'tax_id']) {
      payload[key] = payload[key] === '' ? null : Number(payload[key]);
    }

    /*
     * HIDDEN FIELDS ARE NOT SENT.
     *
     * Creator's form does not post a field it never rendered, and `update()` treats a
     * hidden field in the body as an attempt to write a locked one. Disabled fields
     * ARE still sent — Creator posts those, they are greyed rather than absent, and
     * the server accepts them unchanged.
     */
    for (const key of Object.keys(payload)) {
      if (stateOf(key) === 'hidden') delete payload[key];
    }

    fetch(editing ? `/api/payments/${payment.id}` : '/api/payments/direct', {
      method: editing ? 'PATCH' : 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify(payload),
    })
      .then(async (response) => {
        const body = await response.json();
        if (!response.ok) {
          if (body.errors) setFieldErrors(body.errors);
          throw new Error(body.message ?? `HTTP ${response.status}`);
        }
        return body;
      })
      .then((body) => onSaved?.(body))
      .catch((e) => setError(String(e.message ?? e)))
      .finally(() => setSaving(false));
  };

  /*
   * WHICH FIELDS THIS COA ALLOWS — `Accounts.ds:24240`, LOOKED UP, NOT RE-DERIVED.
   *
   * Creator switches the Payment form between two genuinely different screens on the
   * COA: Accounts Payable hides Gross, TDS and GST and enables Payable Amount; every
   * other COA does the reverse. That is why the live screenshot of an Accounts
   * Payable payment shows no Amount, no TDS and no GST — they are hidden, not absent.
   *
   * The branch itself lives in ONE place, `App\Domain\Payments\PaymentFieldState`,
   * and `/api/payments/options` publishes its three reachable outcomes. Writing the
   * `if` again here would be a second implementation of a rule the server enforces —
   * and a form that disagrees with its own guard shows the user a field they may type
   * into and then refuses the save.
   *
   * On an EXISTING record the state comes from the record itself, which also covers
   * §6.5's Paid lock.
   */
  const fieldStates = useMemo(() => {
    if (editing && payment.field_states) return payment.field_states;

    const payableIds = options.accounts_payable_coa_ids ?? [];
    const isPayable = payableIds.includes(String(form.coa_account_id));

    if (!isPayable) return options.field_states?.other ?? {};

    return form.status === 'Paid'
      ? (options.field_states?.accounts_payable_paid ?? {})
      : (options.field_states?.accounts_payable ?? {});
  }, [editing, payment, options, form.coa_account_id, form.status]);

  const stateOf = (key) => fieldStates[key] ?? 'editable';

  const field = (key, node, hint, group = false) => {
    const state = stateOf(key);

    // Hidden is hidden. Creator does not render it and neither do we — rendering it
    // greyed would suggest a field that exists on this branch and does not.
    if (state === 'hidden') return null;

    return (
    <div className="zc-field" key={key}>
      {group
        ? <span id={`p-${key}-label`} style={{ width: 190, flex: '0 0 190px', paddingTop: 6, color: 'var(--ink2)' }}>{LABELS[key]}</span>
        : <label htmlFor={`p-${key}`}>{LABELS[key]}</label>}
      <div style={{ flex: 1, minWidth: 0 }}>
        {node}
        {fieldErrors[key] && (
          <div className="zc-field-hint" style={{ color: 'var(--bad)' }}>{fieldErrors[key][0]}</div>
        )}
        {hint && <div className="zc-field-hint">{hint}</div>}
        {state === 'disabled' && (
          <div className="zc-field-hint">
            Creator disables this field on this COA (Accounts.ds:24240).
          </div>
        )}
      </div>
    </div>
    );
  };

  /*
   * Every control asks `stateOf` whether it is live.
   *
   * A `disabled` attribute is NOT a security boundary and is not treated as one —
   * `PaymentController::update()` refuses a locked field regardless. This is the
   * replication: Creator greys these, so we grey these.
   */
  const locked = (key) => stateOf(key) !== 'editable';

  const text = (key, extra = {}) => (
    <input id={`p-${key}`} className="zc-input" value={form[key]} disabled={locked(key)}
      onChange={(e) => set(key, e.target.value)} {...extra} />
  );

  const money = (key) => (
    <input id={`p-${key}`} className="zc-input" inputMode="decimal" value={form[key]}
      disabled={locked(key)}
      onChange={(e) => set(key, e.target.value)} placeholder="0.00" />
  );

  const select = (key, list, blank = '— none —') => (
    <select id={`p-${key}`} className="zc-select" value={form[key]} disabled={locked(key)}
      onChange={(e) => set(key, e.target.value)}>
      <option value="">{blank}</option>
      {(list ?? []).map((o) => (
        <option key={o.value ?? o} value={o.value ?? o}>{o.label ?? o}</option>
      ))}
    </select>
  );

  const check = (key) => (
    <input id={`p-${key}`} type="checkbox" className="zc-check" checked={form[key]}
      disabled={locked(key)}
      onChange={(e) => set(key, e.target.checked)} />
  );

  const multi = (key, list) => (
    <div id={`p-${key}`} role="group" aria-labelledby={`p-${key}-label`}
      style={{ maxHeight: 120, overflowY: 'auto', border: '1px solid var(--line)', padding: '4px 8px' }}>
      {(list ?? []).length === 0 && <span style={{ color: 'var(--ink3)', fontSize: 12 }}>none</span>}
      {(list ?? []).map((o) => {
        const value = String(o.value ?? o);
        const on = form[key].includes(value);

        return (
          <label key={value} style={{ display: 'block', fontWeight: 400 }}>
            <input type="checkbox" className="zc-check" checked={on}
              onChange={(e) => set(key, e.target.checked
                ? [...form[key], value]
                : form[key].filter((v) => v !== value))} />
            {' '}{o.label ?? o}
          </label>
        );
      })}
    </div>
  );

  const addLeg = () => setLegs((c) => [...c,
    { villa_id: '', item_category_id: '', billing_cycle_id: '', amount: '' }]);
  const setLeg = (i, key, value) => setLegs((c) =>
    c.map((leg, j) => (j === i ? { ...leg, [key]: value } : leg)));

  return (
    <div className="zc-overlay" role="dialog" aria-modal="true"
      aria-label={editing ? 'Update Payment' : 'Add Payment'}>
      <div className="zc-overlay-head">
        Payment
        {editing && (
          <span style={{ marginLeft: 8, color: 'var(--ink2)', fontWeight: 400 }}>
            {payment['Payment No']}
          </span>
        )}
      </div>

      <div className="zc-overlay-body">
        {calcWarnings.length > 0 && (
          <div style={{ border: '1px solid var(--line2)', background: 'var(--pinkl)', padding: 10, marginTop: 0, marginBottom: 14 }}>
            {calcWarnings.map((w, i) => (
              <p key={i} style={{ margin: i ? '6px 0 0' : 0, fontSize: 12 }}>{w}</p>
            ))}
          </div>
        )}

        {error && (
          <p style={{ color: 'var(--bad)', border: '1px solid var(--bad)', padding: 10, marginTop: 0 }}>
            {error}
          </p>
        )}

        <p className="zc-field-hint" style={{ marginTop: 0, marginBottom: 16 }}>
          Field order and section names come from the <strong>Payment form in Accounts.ds</strong>,
          which carries row/column for every field — so the layout is parsed, not inferred. What is
          not here is listed at the foot of this form.
        </p>

        {/* ------------------------------------------------ DS section, row 1 */}
        <div className="zc-section">Payment</div>
        {field('coa_account_id', select('coa_account_id', options.coa_accounts),
          `${options.coa_accounts?.length ?? 0} of 144 accounts — the form filters COA[Hide == true], which on this screen means selectable, not hidden.`)}
        {field('vendor_id',
          <VendorPicker id="p-vendor_id" value={form.vendor_id}
            onChange={(v) => set('vendor_id', v)} />,
          `${options.vendor_count ?? 0} selectable — trade vendors only. Customer payees are excluded, as Creator excludes them.`)}
        {field('payment_date', text('payment_date', { placeholder: 'YYYY-MM-DD' }),
          'Typed YYYY-MM-DD, displayed dd-MMM-yyyy. Never a native date input (§15.2).')}
        {field('due_date', text('due_date', { placeholder: 'YYYY-MM-DD' }))}
        {field('payment_mode', select('payment_mode', options.payment_modes))}
        {field('bank_coa_account_id', select('bank_coa_account_id', options.bank_accounts),
          'COA[Bank == true] — the load-bearing flag, not Account_Type: 9 accounts are Bank without being typed `bank`.')}
        {field('status', select('status', options.statuses),
          'All 8 DS values, including three spellings of one concept — they are in the picklist, not just the data.')}
        {field('payment_status', select('payment_status', options.payment_statuses),
          '`Open` is live on 7,583 imported payments but is NOT in the picklist (addendum §10), so it is not offered here.')}
        {field('location_id', select('location_id', options.locations))}
        {field('master_category_id', select('master_category_id', options.master_categories))}
        {field('item_category_id', select('item_category_id', options.item_categories),
          'Categories flagged `Disallow Manual Creation` are excluded — a hard block at validate.')}
        {field('particulars', <textarea id="p-particulars" className="zc-input" rows={2}
          value={form.particulars} onChange={(e) => set('particulars', e.target.value)} />)}
        {field('expense_by', text('expense_by'))}
        {field('payment_by', text('payment_by'))}
        {field('payment_source', text('payment_source'))}
        {field('ca_email', text('ca_email', { type: 'email' }))}
        {field('original_amount', money('original_amount'))}
        {field('remarks', <textarea id="p-remarks" className="zc-input" rows={2}
          value={form.remarks} onChange={(e) => set('remarks', e.target.value)} />)}
        {field('management_remarks', <textarea id="p-management_remarks" className="zc-input" rows={2}
          value={form.management_remarks} onChange={(e) => set('management_remarks', e.target.value)} />)}
        {field('verified', check('verified'))}
        {field('multiple_villa', check('multiple_villa'))}

        {/* ------------------------------------------------ DS section, row 3 */}
        <div className="zc-section">Billing</div>
        {field('billing_year', text('billing_year', { inputMode: 'numeric' }))}
        {field('billing_months', multi('billing_months', options.billing_months),
          'Stored comma-packed, as Creator stores it. A month name here NEVER creates a cycle.', true)}
        {field('billing_cycle_ids', multi('billing_cycle_ids', options.billing_cycles),
          (options.billing_cycles?.length ?? 0) === 0
            ? 'None exist. A cycle is NEVER created on the fly — §6.4: that defect put a junk "9-2026" cycle into live accounting. 50 real cycles exist in Creator and have not been imported yet.'
            : 'A cycle must already exist; none is derived from a month name (§6.4).', true)}

        {/* ------------------------------------------------ DS section, row 4 */}
        <div className="zc-section">Commercials</div>
        {field('amount', money('amount'), 'Gross. The split legs below must tie to this (§6.4 rule 1).')}
        {field('tds_rate_id', select('tds_rate_id', options.tds_rates),
          '§6.3 applies the percentage PER ROW, so per-row TDS need not sum to TDS on the total.')}
        {field('tds_amount', money('tds_amount'))}
        {field('pt_amount', money('pt_amount'))}
        {field('esic_amount', money('esic_amount'))}
        {field('pf_amount', money('pf_amount'))}
        {field('gst_needed', check('gst_needed'))}
        {field('gst_type', select('gst_type', options.gst_types),
          '`Enter Manully` is Creator’s spelling and is preserved (handoff §2 rule 7).')}
        {field('tax_id', select('tax_id', options.taxes))}
        {field('gst_amount', money('gst_amount'))}
        {field('total_amount', money('total_amount'))}
        {field('payable_amount', money('payable_amount'),
          '§6.3 records TWO formulas under this one name and which is authoritative is still open, so it is stored as entered rather than derived.')}
        {field('payment_reference_number', text('payment_reference_number'))}
        {field('haewaya_utr_number', text('haewaya_utr_number'))}
        {field('split_equally', check('split_equally'))}
        {field('accounts_bills', check('accounts_bills'))}

        {/* ------------------------------------------------ DS section, row 8 */}
        <div className="zc-section">Split Payments</div>
        <p className="zc-field-hint" style={{ marginTop: 0 }}>
          One row per villa × billing cycle × item category (§5.1). Per §5.2 each row becomes a
          ledger entry, so this is where attribution is decided. The legs must sum to the gross —
          the server enforces it and will refuse the save otherwise.
        </p>

        <table className="zc-grid">
          <thead>
            <tr>
              {['Villa Name', 'Item Category', 'Billing Cycle', 'Amount',
                'TDS Amount', 'GST Amount', 'Total Amount', ''].map((h) => <th key={h}>{h}</th>)}
            </tr>
          </thead>
          <tbody>
            {legs.map((leg, i) => (
              <tr key={i}>
                <td>
                  <select className="zc-select" value={leg.villa_id}
                    onChange={(e) => setLeg(i, 'villa_id', e.target.value)}>
                    <option value="">—</option>
                    {(options.villas ?? []).map((v) => <option key={v.value} value={v.value}>{v.label}</option>)}
                  </select>
                </td>
                <td>
                  <select className="zc-select" value={leg.item_category_id}
                    onChange={(e) => setLeg(i, 'item_category_id', e.target.value)}>
                    <option value="">—</option>
                    {(options.item_categories ?? []).map((c) => <option key={c.value} value={c.value}>{c.label}</option>)}
                  </select>
                </td>
                <td>
                  <select className="zc-select" value={leg.billing_cycle_id}
                    onChange={(e) => setLeg(i, 'billing_cycle_id', e.target.value)}>
                    <option value="">—</option>
                    {(options.billing_cycles ?? []).map((c) => <option key={c.value} value={c.value}>{c.label}</option>)}
                  </select>
                </td>
                <td>
                  <input className="zc-input" inputMode="decimal" value={leg.amount}
                    onChange={(e) => setLeg(i, 'amount', e.target.value)} />
                </td>
                {/*
                  Derived, not editable. The DS handler computes each leg from the
                  leg's own amount — `rec.TDS_Amount = rec.Amount x tdsPct / 100` —
                  so typing over them would be overwritten on the next keystroke.
                  Per-leg TDS need not sum to header TDS (§6.3); that is correct.
                */}
                <td className="zc-money">{leg.tds_amount ? inr(leg.tds_amount) : '—'}</td>
                <td className="zc-money">{leg.gst_amount ? inr(leg.gst_amount) : '—'}</td>
                <td className="zc-money">{leg.total_amount ? inr(leg.total_amount) : '—'}</td>
                <td>
                  <button type="button" className="zc-btn"
                    onClick={() => setLegs((c) => c.filter((_, j) => j !== i))}>
                    Remove
                  </button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>

        <div style={{ display: 'flex', gap: 8, alignItems: 'center', marginTop: 8 }}>
          <button type="button" className="zc-btn" onClick={addLeg}
            disabled={(options.billing_cycles?.length ?? 0) === 0}
            title={(options.billing_cycles?.length ?? 0) === 0
              ? 'No billing cycle exists, and one is never created on the fly (§6.4)'
              : 'Add a split row'}>
            Add split row
          </button>
          {legs.length > 0 && (
            <span style={{ fontSize: 12, color: balanced ? 'var(--ink3)' : 'var(--bad)' }}>
              legs {inr(String(legTotal))} · gross {inr(form.amount || '0')}
              {!balanced && ' — these must tie (§6.4 rule 1); the server will refuse the save'}
            </span>
          )}
        </div>

        {/* What a reviewer will find missing, said here rather than discovered. */}
        <div className="zc-section">Not on this form yet</div>
        <p className="zc-field-hint" style={{ marginTop: 0 }}>
          The DS form has 130 entries over 10 sections. Deliberately absent, with reasons:
          the <strong>Admin</strong> section (Approver 1–3, Bank Reconcilation, Books ID) because the
          approval engine is not built and §8.2's matrix collides with Backend Expenses' second
          one; the <strong>Bill Payments</strong> and <strong>Bills</strong> subform grids, which
          belong to the from-a-bill path (§7.2); <strong>file uploads and OCR</strong>
          (Bills doc, Supporting Documents, Verification Call); the three
          <strong> Messageid</strong> fields, which are outbound-WhatsApp plumbing rather than
          accounting data; and <strong>Payments Scheduled</strong>, whose table does not exist here.
        </p>

        <div className="zc-commit">
          <button type="button" className="zc-btn zc-btn-primary" disabled={saving} onClick={save}>
            {saving ? 'Saving…' : (editing ? 'Update Payment' : 'Add')}
          </button>
          <button type="button" className="zc-btn" disabled={saving} onClick={onClose}>
            Cancel
          </button>
        </div>
      </div>
    </div>
  );
}

/** Labels verbatim from the DS `displayname`, or the field name where it has none. */
const LABELS = {
  coa_account_id: 'COA',
  vendor_id: 'Vendor Name',
  bank_coa_account_id: 'Bank Name',
  location_id: 'Location',
  item_category_id: 'Item Category',
  master_category_id: 'Master Category',
  tds_rate_id: 'TDS %',
  tax_id: 'GST',
  status: 'Status',
  payment_status: 'Payment Status',
  payment_mode: 'Payment Mode',
  gst_type: 'GST Type',
  payment_date: 'Payment Date',
  due_date: 'Due Date',
  amount: 'Gross Amount',
  gst_amount: 'GST Amount',
  tds_amount: 'TDS Amount',
  pt_amount: 'PT',
  esic_amount: 'ESIC',
  pf_amount: 'PF',
  payable_amount: 'Payable Amount',
  total_amount: 'Invoice Amount',
  original_amount: 'Original Amount',
  particulars: 'Particulars',
  remarks: 'Accounts Remarks',
  management_remarks: 'Management Remarks',
  payment_reference_number: 'Payment Reference Number',
  payment_by: 'Payment By',
  expense_by: 'Expense By',
  ca_email: 'CA Email',
  payment_source: 'Payment Source',
  haewaya_utr_number: 'Haewaya UTR Number',
  billing_year: 'Billing Year',
  billing_months: 'Billing Months',
  billing_cycle_ids: 'Billing Cycles',
  gst_needed: 'GST Needed',
  split_equally: 'Split Equally',
  multiple_villa: 'Multiple Villa',
  verified: 'Verified',
  accounts_bills: 'Accounts Bills',
};
