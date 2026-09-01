import { useEffect, useMemo, useState } from 'react';
import VendorPicker from '../components/VendorPicker';
import { inr } from '../lib/format';
import useOverlayZ from '../lib/useOverlayZ';

/**
 * The Bill form — §6.2, and the split grid that makes it consequential.
 *
 * §5 is blunt about what the split grid is: "An Expenses_Bills row IS one
 * Split_Payments leg, materialised", and §5.2 adds that every villa-month-category
 * figure in the downstream expense-control tool traces back to one. This form is
 * where attribution is decided.
 *
 * NOTHING ABOUT THE SPLIT IS DECIDED IN THE BROWSER.
 *
 *  - The COMBINATIONS come from the server. §5.1 has degradation tiers — villas
 *    only, villas x cycles, the full cross product — and re-deriving them here would
 *    be a second implementation to keep in step.
 *  - SPLIT EQUALLY is an API call. §6.3's rule truncates at paisa and puts the whole
 *    dropped remainder on the LAST row, and the spec says "Reproduce exactly. Do not
 *    substitute banker's rounding." A JavaScript `/` would not.
 *  - The BALANCE CHECK is the server's. §6.4 rule 1 compares at whole rupees, which
 *    is a real loosening that Creator has; the sub-rupee residual comes back as a
 *    warning rather than being hidden. This form shows the running total so the gap
 *    is visible while typing, but it does not decide whether a save is allowed.
 *
 * DATES ARE TEXT INPUTS, never `<input type="date">`. §15.2 records a live fault
 * where native date inputs rendered mm/dd/yyyy; CLAUDE.md makes the text input a
 * hard rule and `dd-MMM-yyyy` the display format.
 */

/** Blank form state. Status starts at Draft, as Creator's field does. */
const EMPTY = {
  bill_no: '',
  bill_date: '',
  due_date: '',
  vendor_id: '',
  tds_rate_id: '',
  status: 'Draft',
  amount: '',
  gst_amount: '',
  tds_amount: '',
  invoice_amount: '',
  payable_amount: '',
  split_equally: false,
  villa_ids: [],
  item_category_ids: [],
  billing_cycle_ids: [],
};

export default function BillForm({ options, detail, onClose, onSaved }) {
  const overlayZ = useOverlayZ();
  const isNew = detail === null;

  const [form, setForm] = useState(() => (isNew ? EMPTY : { ...EMPTY, ...detail.values }));
  const [legs, setLegs] = useState(() => (isNew ? [] : detail.split_payments ?? []));
  const [errors, setErrors] = useState({});
  const [message, setMessage] = useState(null);
  const [saving, setSaving] = useState(false);

  const locked = detail?.bill?.locked === true;

  useEffect(() => {
    const onKey = (e) => { if (e.key === 'Escape') onClose(); };
    document.addEventListener('keydown', onKey);
    return () => document.removeEventListener('keydown', onKey);
  }, [onClose]);

  const set = (key, value) => setForm((c) => ({ ...c, [key]: value }));

  /** Multi-selects hold arrays of string ids, matching what the API returns. */
  const toggleMulti = (key, value) => setForm((c) => {
    const current = c[key] ?? [];
    return {
      ...c,
      [key]: current.includes(value) ? current.filter((v) => v !== value) : [...current, value],
    };
  });

  const label = (list, value) => list.find((o) => o.value === String(value))?.label ?? '';

  const splitTotal = useMemo(
    () => legs.reduce((sum, leg) => sum + Number(leg.amount || 0), 0),
    [legs]
  );

  const gross = Number(form.amount || 0);
  const residual = splitTotal - gross;

  /** Ask the server to rebuild the combinations and distribute the gross across them. */
  const splitEqually = () => {
    setMessage(null);

    fetch('/api/bills/split-equally', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({
        amount: form.amount || 0,
        gst_amount: form.gst_amount || null,
        tds_percentage: tdsPercentage(),
        villa_ids: form.villa_ids.map(Number),
        item_category_ids: form.item_category_ids.map(Number),
        billing_cycle_ids: form.billing_cycle_ids.map(Number),
      }),
    })
      .then(async (r) => {
        const body = await r.json();
        if (!r.ok) throw new Error(body.message ?? `HTTP ${r.status}`);
        return body;
      })
      .then((body) => {
        if (body.legs.length === 0) {
          setMessage('Pick at least one villa before splitting — §5.1 needs a villa to build a combination from.');
          return;
        }
        setLegs(body.legs.map((leg) => ({
          ...leg,
          'Villa Name': label(options.villas, leg.villa_id),
          'Item Category': label(options.item_categories, leg.item_category_id),
          'Billing Cycle': label(options.billing_cycles, leg.billing_cycle_id),
        })));
      })
      .catch((e) => setMessage(String(e.message ?? e)));
  };

  /** The chosen TDS rate's percentage, which §6.3 applies per row. */
  const tdsPercentage = () => {
    const chosen = options.tds_rates.find((r) => r.value === String(form.tds_rate_id));
    if (!chosen) return null;
    const match = chosen.label.match(/—\s*([\d.]+)%/);
    return match ? match[1] : null;
  };

  const setLeg = (index, key, value) =>
    setLegs((current) => current.map((leg, i) => (i === index ? { ...leg, [key]: value } : leg)));

  const save = () => {
    setSaving(true);
    setMessage(null);
    setErrors({});

    const payload = {
      ...form,
      vendor_id: form.vendor_id === '' ? null : Number(form.vendor_id),
      tds_rate_id: form.tds_rate_id === '' ? null : Number(form.tds_rate_id),
      bill_date: form.bill_date || null,
      due_date: form.due_date || null,
      gst_amount: form.gst_amount === '' ? null : form.gst_amount,
      tds_amount: form.tds_amount === '' ? null : form.tds_amount,
      invoice_amount: form.invoice_amount === '' ? null : form.invoice_amount,
      payable_amount: form.payable_amount === '' ? null : form.payable_amount,
      villa_ids: form.villa_ids.map(Number),
      item_category_ids: form.item_category_ids.map(Number),
      billing_cycle_ids: form.billing_cycle_ids.map(Number),
      legs: legs.map((leg) => ({
        villa_id: leg.villa_id === '' ? null : Number(leg.villa_id),
        item_category_id: leg.item_category_id === '' ? null : Number(leg.item_category_id),
        billing_cycle_id: leg.billing_cycle_id === '' ? null : Number(leg.billing_cycle_id),
        amount: leg.amount === '' ? null : leg.amount,
        gst_amount: leg.gst_amount === '' ? null : leg.gst_amount,
        tds_amount: leg.tds_amount === '' ? null : leg.tds_amount,
        total_amount: leg.total_amount === '' ? null : leg.total_amount,
      })),
    };

    fetch(isNew ? '/api/bills' : `/api/bills/${detail.bill.id}`, {
      method: isNew ? 'POST' : 'PATCH',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify(payload),
    })
      .then(async (r) => {
        const body = await r.json().catch(() => ({}));
        if (r.status === 422) {
          setErrors(body.errors ?? {});
          throw new Error(body.message ?? 'The bill was not accepted.');
        }
        if (!r.ok) throw new Error(body.message ?? `HTTP ${r.status}`);
        return body;
      })
      .then((body) => onSaved(body.warnings))
      .catch((e) => setMessage(String(e.message ?? e)))
      .finally(() => setSaving(false));
  };

  /**
   * One labelled row.
   *
   * `group` matters for accessibility: a multi-select is a set of checkboxes, not a
   * single control, so `<label for>` has nothing valid to point at. Pointing it at a
   * bare <div> left the label associated with nothing at all. Groups get a labelling
   * span plus `aria-labelledby` on a `role="group"` container instead.
   */
  const field = (key, node, hint, group = false) => (
    <div className="zc-field" key={key}>
      {group ? (
        <span id={`b-${key}-label`} style={{ width: 190, flex: '0 0 190px', paddingTop: 6, color: 'var(--ink2)' }}>
          {FIELD_LABELS[key]}{REQUIRED.has(key) && <span className="zc-req">*</span>}
        </span>
      ) : (
        <label htmlFor={`b-${key}`}>{FIELD_LABELS[key]}{REQUIRED.has(key) && <span className="zc-req">*</span>}</label>
      )}
      <div>
        {node}
        {errors[key] && <div className="zc-field-hint" style={{ color: 'var(--bad)' }}>{errors[key][0]}</div>}
        {hint && <div className="zc-field-hint">{hint}</div>}
      </div>
    </div>
  );

  const text = (key, extra = {}) => (
    <input
      id={`b-${key}`}
      className="zc-input"
      value={form[key] ?? ''}
      onChange={(e) => set(key, e.target.value)}
      {...extra}
    />
  );

  const select = (key, list) => (
    <select id={`b-${key}`} className="zc-select" value={form[key] ?? ''} onChange={(e) => set(key, e.target.value)}>
      <option value="" />
      {list.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
    </select>
  );

  const multi = (key, list) => (
    <div
      id={`b-${key}`}
      role="group"
      aria-labelledby={`b-${key}-label`}
      style={{ border: '1px solid var(--line2)', maxHeight: 132, overflow: 'auto', width: 324, padding: '4px 8px' }}
    >
      {list.length === 0 && <div className="zc-field-hint">No options — none exist yet.</div>}
      {list.map((o) => (
        <label key={o.value} style={{ display: 'flex', gap: 7, alignItems: 'center', padding: '2px 0' }}>
          <input
            type="checkbox"
            className="zc-check"
            checked={(form[key] ?? []).includes(o.value)}
            onChange={() => toggleMulti(key, o.value)}
          />
          <span>{o.label}</span>
        </label>
      ))}
    </div>
  );

  return (
    <div className="zc-overlay" style={{ zIndex: overlayZ }} role="dialog" aria-modal="true" aria-label={isNew ? 'Add Bill' : 'Edit Bill'}>
      <div className="zc-overlay-head">{isNew ? 'Add Bill' : `Edit Bill — ${detail.bill['Bill No']}`}</div>

      <div className="zc-overlay-body">
        {message && <p style={{ color: 'var(--bad)', marginTop: 0 }}>{message}</p>}

        {locked && (
          <p style={{ color: 'var(--bad)', marginTop: 0 }}>
            This bill has {detail.bill.payment_count} payment(s) against it. Its allocation is
            what those payments were built from, so saving is refused (§6.5). Reverse the
            payment first.
          </p>
        )}

        <p className="zc-field-hint" style={{ marginTop: 0, marginBottom: 16 }}>
          Field set and order are <strong>inferred</strong> — §6.1 records more than one
          All Bills report in Creator and which is live is still open, so treat the
          layout as provisional. The columns themselves are real.
        </p>

        {field('bill_no', text('bill_no'))}
        {field('bill_date', text('bill_date', { placeholder: 'YYYY-MM-DD' }),
          'Typed as YYYY-MM-DD, displayed as dd-MMM-yyyy. Never a native date input — §15.2.')}
        {field('due_date', text('due_date', { placeholder: 'YYYY-MM-DD' }))}
        {field('vendor_id',
          <VendorPicker
            id="b-vendor_id"
            value={form.vendor_id}
            initialLabel={detail?.values?.vendor_name}
            disabled={locked}
            onChange={(v) => set('vendor_id', v)}
          />,
          `${options.vendor_count ?? 0} selectable vendors — searched on the server, not listed. `
          + 'Merged-away vendors are excluded (§13A.1); the report still shows them.')}
        {field('status', text('status'),
          'Verbatim. Both `Payment InProgress` and `Payment Inprogress` are live (addendum §10), so this is unconstrained.')}

        <div className="zc-section">Amounts</div>
        {field('amount', text('amount', { inputMode: 'decimal' }), 'Gross. The split must tie to this.')}
        {field('gst_amount', text('gst_amount', { inputMode: 'decimal' }))}
        {field('tds_rate_id', select('tds_rate_id', options.tds_rates),
          '§6.3 applies the percentage PER ROW, so per-row TDS need not sum to TDS on the total.')}
        {field('tds_amount', text('tds_amount', { inputMode: 'decimal' }))}
        {field('invoice_amount', text('invoice_amount', { inputMode: 'decimal' }))}
        {field('payable_amount', text('payable_amount', { inputMode: 'decimal' }),
          '§6.3 records TWO formulas under this one name and which is authoritative is still open, so it is stored as entered rather than derived.')}

        <div className="zc-section">Scope</div>
        {field('villa_ids', multi('villa_ids', options.villas),
          'Filtered to Hide_From_Payments = false, which is the picker Bills actually uses (§6.2).', true)}
        {field('item_category_ids', multi('item_category_ids', options.item_categories),
          'Categories flagged `Disallow Manual Creation` are excluded — a hard block at validate.', true)}
        {field('billing_cycle_ids', multi('billing_cycle_ids', options.billing_cycles),
          options.billing_cycles.length === 0
            ? 'None exist. A cycle is NEVER created on the fly — §6.4: that defect put a junk "9-2026" cycle into live accounting.'
            : 'A cycle must already exist; none is created on the fly (§6.4).', true)}

        <div className="zc-section">Split Payments</div>
        <p className="zc-field-hint" style={{ marginTop: 0 }}>
          One row per villa × billing cycle × item category (§5.1). Per §5.2 each row
          becomes a ledger entry, so this is where attribution is decided. Combinations
          and split-equally both come from the server — the remainder convention in §6.3
          is not re-implemented here.
        </p>

        <div style={{ display: 'flex', gap: 8, alignItems: 'center', marginBottom: 8 }}>
          <button type="button" className="zc-btn" onClick={splitEqually} disabled={locked}>
            Split Equally
          </button>
          <span style={{ fontSize: 12, color: Math.abs(residual) >= 1 ? 'var(--bad)' : 'var(--ink3)' }}>
            {legs.length} leg{legs.length === 1 ? '' : 's'} · total {inr(splitTotal)} of {inr(gross)}
            {Math.abs(residual) >= 0.005 && ` · off by ${inr(residual)}`}
          </span>
        </div>

        {errors.legs && <p style={{ color: 'var(--bad)', fontSize: 12 }}>{errors.legs[0]}</p>}

        {legs.length > 0 && (
          <div style={{ overflowX: 'auto' }}>
            <table className="zc-grid">
              <thead>
                <tr>
                  {['Villa Name', 'Item Category', 'Billing Cycle', 'Amount', 'GST Amount', 'TDS Amount', 'Gross Amount'].map((h) => (
                    <th key={h}>{h}</th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {legs.map((leg, i) => (
                  <tr key={i} style={leg.flagged ? { background: 'var(--pinkl)' } : undefined}>
                    <td>{leg['Villa Name'] || label(options.villas, leg.villa_id)}</td>
                    <td>{leg['Item Category'] || label(options.item_categories, leg.item_category_id)}</td>
                    <td>{leg['Billing Cycle'] || label(options.billing_cycles, leg.billing_cycle_id)}</td>
                    {['amount', 'gst_amount', 'tds_amount', 'total_amount'].map((key) => (
                      <td key={key}>
                        <input
                          className="zc-input"
                          style={{ width: 110 }}
                          inputMode="decimal"
                          value={leg[key] ?? ''}
                          disabled={locked}
                          onChange={(e) => setLeg(i, key, e.target.value)}
                          aria-label={`${key} row ${i + 1}`}
                        />
                      </td>
                    ))}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}

        <div className="zc-commit">
          <button type="button" className="zc-btn zc-btn-primary" disabled={saving || locked} onClick={save}>
            {saving ? 'Saving…' : isNew ? 'Add' : 'Save'}
          </button>
          <button type="button" className="zc-btn" disabled={saving} onClick={onClose}>Cancel</button>
        </div>
      </div>
    </div>
  );
}

const FIELD_LABELS = {
  bill_no: 'Bill No',
  bill_date: 'Bill Date',
  due_date: 'Due Date',
  vendor_id: 'Vendor Name',
  tds_rate_id: 'TDS',
  status: 'Status',
  amount: 'Gross Amount',
  gst_amount: 'GST Amount',
  tds_amount: 'TDS Amount',
  invoice_amount: 'Invoice Amount',
  payable_amount: 'Payable Amount',
  villa_ids: 'Villas',
  item_category_ids: 'Item Category',
  billing_cycle_ids: 'Billing Cycles',
};

const REQUIRED = new Set(['bill_no', 'amount']);
