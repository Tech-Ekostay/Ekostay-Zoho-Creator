import { useCallback, useEffect, useMemo, useState } from 'react';
import ReportBar from '../components/ReportBar';
import ReportGrid from '../components/ReportGrid';
import FilterBar from '../components/FilterBar';
import RecordDetail from '../components/RecordDetail';
import BillForm from './BillForm';
import { ddMmmYyyy, inr } from '../lib/format';

/**
 * All Bills — §6, and the module everything else flows from. A payment is created
 * FROM a bill (§7.2), so without this screen Payments has nothing to act on.
 *
 * COLUMN ORDER IS PROVISIONAL. §6.1's "which All Bills report is live" is still an
 * open question — there is more than one in Creator — so the order here follows the
 * form's field order rather than a verified report. Flagged, not guessed silently.
 *
 * The split grid and its rules live in the form. Nothing about the split is decided
 * here or in `BillForm`: the combinations, the reconcile and split-equally all come
 * from the API, because §6.3's remainder convention and §5.1's reconcile rule are
 * both things the browser must not re-decide.
 */
const MONEY = new Set([
  'Gross Amount', 'GST Amount', 'TDS Amount', 'Payable Amount', 'Paid Amount',
]);

export default function BillsModule({ onCreatePayment }) {
  const [data, setData] = useState(null);
  const [options, setOptions] = useState(null);
  const [error, setError] = useState(null);

  /* Creator-style filters: column + operator + value, applied server-side. */
  const [filters, setFilters] = useState([]);
  const [filterError, setFilterError] = useState(null);

  /*
   * DEPEND ON THE SERIALISED FILTERS, NOT THE ARRAY.
   *
   * `filters` is a new array object on every change, so using it as a hook
   * dependency compares by IDENTITY. That produced an infinite fetch loop: an effect
   * that both depended on `filters` and called `setFilters([])` re-armed itself
   * forever, and the browser hammered the API — caught by watching the network log,
   * not by reading the code.
   *
   * A JSON string is stable when the contents are unchanged, which is the comparison
   * actually wanted here.
   */
  const filterKey = JSON.stringify(filters);
  const [term, setTerm] = useState('');
  /*
   * SELECTING AND EDITING ARE SEPARATE, deliberately.
   *
   * A row click used to open the edit overlay directly. But Bills also carries a
   * per-record action — §7.2's Create_Payment — which lives in a strip under the
   * grid, and the overlay covered it: the strip appeared and was immediately
   * unreachable behind a dialog. Payments has the same shape (select, then act) and
   * does it this way. `Edit` is now an explicit button in the strip.
   */
  const [selectedId, setSelectedId] = useState(null);
  const [editing, setEditing] = useState(null);   // null · 'new' · bill id
  const [detail, setDetail] = useState(null);
  const [viewing, setViewing] = useState(null);
  const [notice, setNotice] = useState(null);

  const load = useCallback(() => {
    setError(null);
    fetch(`/api/bills${filters.length ? `?filters=${encodeURIComponent(JSON.stringify(filters))}` : ''}`)
      .then((r) => (r.ok ? r.json() : Promise.reject(new Error(`HTTP ${r.status}`))))
      .then((body) => {
        if (body.reason === 'bad_filter') { setFilterError(body.message); return; }
        setData(body);
      })
      .catch((e) => setError(String(e.message ?? e)));
  }, [filterKey]);   // eslint-disable-line react-hooks/exhaustive-deps

  useEffect(() => {
    load();
    fetch('/api/bills/options')
      .then((r) => (r.ok ? r.json() : Promise.reject(new Error(`HTTP ${r.status}`))))
      .then(setOptions)
      .catch((e) => setError(String(e.message ?? e)));
  }, [load]);

  /** The form needs the stored values, which only the detail endpoint has. */
  useEffect(() => {
    if (typeof editing !== 'number') { setDetail(null); return; }

    setDetail(null);
    fetch(`/api/bills/${editing}`)
      .then((r) => (r.ok ? r.json() : Promise.reject(new Error(`HTTP ${r.status}`))))
      .then(setDetail)
      .catch((e) => setError(String(e.message ?? e)));
  }, [editing]);

  const rows = useMemo(() => {
    const all = data?.rows ?? [];
    const needle = term.trim().toLowerCase();
    if (needle === '') return all;

    return all.filter((row) =>
      (data?.columns ?? []).some((label) => String(row[label] ?? '').toLowerCase().includes(needle))
    );
  }, [data, term]);

  const columns = (data?.columns ?? []).map((label) => ({
    key: label,
    label,
    align: MONEY.has(label) ? 'right' : undefined,
    fill: label === 'Status',
    render: (value) => {
      if (MONEY.has(label)) return inr(value);
      if (label.endsWith('Date')) return ddMmmYyyy(value);
      return value ?? '';
    },
  }));

  /**
   * `Create Payment` — Creator runs this per-record from the Bills report (§7.2),
   * which is why the control lives here and not on Payments.
   */
  const createPayment = (row) => {
    setNotice(null);

    fetch('/api/payments', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({ bill_id: row.id }),
    })
      .then(async (response) => {
        const body = await response.json();
        if (!response.ok) throw new Error(body.message ?? `HTTP ${response.status}`);
        return body;
      })
      .then((body) => {
        setNotice({ kind: 'ok', text: `Created payment ${body.payment_no} — ${body.split_legs} split legs.` });
        load();
        onCreatePayment?.();
      })
      .catch((e) => setNotice({ kind: 'bad', text: String(e.message ?? e) }));
  };

  const selected = selectedId === null ? null : rows.find((r) => r.id === selectedId) ?? null;

  return (
    <>
      <ReportBar
        title={data?.title ?? 'All Bills'}
        required
        term={term}
        onTermChange={setTerm}
        matches={rows.length}
        onAdd={options ? () => setEditing('new') : undefined}
        addDisabledReason="Loading the vendor and villa pickers…"
        extras={<button type="button" className="zc-btn" onClick={load}>Refresh</button>}
      />

      {notice && (
        <div style={{ padding: '6px 14px', fontSize: 12, color: notice.kind === 'ok' ? 'var(--ink2)' : 'var(--bad)' }}>
          {notice.text}
        </div>
      )}

      <FilterBar
        schema={data?.filter_schema ?? []}
        filters={filters}
        onChange={(next) => { setFilters(next); setFilterError(null); }}
        matched={data?.matched}
        total={data?.total}
      />

      {filterError && (
        <div style={{ padding: '6px 14px', fontSize: 12, color: 'var(--bad)' }}>{filterError}</div>
      )}

      {error && <div style={{ padding: 14, color: 'var(--bad)' }}>Failed to load: {error}</div>}
      {!data && !error && <div style={{ padding: 14, color: 'var(--ink3)' }}>Loading…</div>}

      {data && data.rows.length === 0 && (
        <div style={{ padding: 20, color: 'var(--ink3)' }}>
          <p style={{ marginTop: 0 }}>No bills yet.</p>
          <p style={{ fontSize: 12 }}>
            Use <strong>＋</strong> to add one. A bill needs at least one villa, item
            category and billing cycle before its split can be allocated — and
            billing cycles are never created on the fly (§6.4), so one has to exist.
          </p>
        </div>
      )}

      {data && data.rows.length > 0 && (
        <ReportGrid
          columns={columns}
          rows={rows}
          total={filters.length > 0 ? data.matched : data.total}
          selectedId={selectedId}
          /*
           * A row click opens the DETAIL view (Husain, 25-Aug-2026 — one flow for
           * every report). The under-grid strip stays as well: it is where §7.2's
           * Create_Payment lives, and that is a per-record action ON the report,
           * not something reached through a detail view.
           */
          onSelect={(row) => { setSelectedId(row.id); setViewing(row.id); }}
        />
      )}

      {selected && (
        <div style={{ borderTop: '1px solid var(--line)', padding: '9px 14px', display: 'flex', gap: 8, alignItems: 'center' }}>
          <strong>{selected['Bill No']}</strong>
          <span style={{ color: 'var(--ink3)', fontSize: 12 }}>{selected['Status']}</span>
          <span className="zc-appbar-spacer" />
          <button type="button" className="zc-btn" onClick={() => setEditing(selected.id)}>
            Edit
          </button>
          {/* §7.2's Create_Payment, a per-record action on the Bills report. */}
          <button type="button" className="zc-btn zc-btn-primary" onClick={() => createPayment(selected)}>
            Create Payment
          </button>
        </div>
      )}

      {viewing !== null && selected && (
        <RecordDetail
          title={data?.title ?? 'All Bills'}
          subtitle={selected['Bill No']}
          fields={(data?.columns ?? []).map((label) => ({
            label,
            value: MONEY.has(label) ? inr(selected[label]) : (label.endsWith('Date') ? ddMmmYyyy(selected[label]) : selected[label]),
          }))}
          onEdit={() => { setViewing(null); setEditing(selected.id); }}
          onClose={() => setViewing(null)}
          extras={(
            <button
              type="button"
              className="zc-btn"
              onClick={() => { setViewing(null); createPayment(selected); }}
            >
              Create Payment
            </button>
          )}
        />
      )}

      {editing !== null && options && (editing === 'new' || detail) && (
        <BillForm
          options={options}
          detail={editing === 'new' ? null : detail}
          onClose={() => setEditing(null)}
          onSaved={(warnings) => {
            setEditing(null);
            setNotice(warnings?.length
              ? { kind: 'bad', text: warnings.join(' ') }
              : { kind: 'ok', text: 'Bill saved.' });
            load();
          }}
        />
      )}
    </>
  );
}
