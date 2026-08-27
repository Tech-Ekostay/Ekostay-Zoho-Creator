import { useCallback, useEffect, useState } from 'react';
import ReportBar from '../components/ReportBar';
import ReportGrid from '../components/ReportGrid';
import FilterBar from '../components/FilterBar';
import RecordDetail from '../components/RecordDetail';
import { ddMmmYyyy, inr } from '../lib/format';

/**
 * All Expenses — the ledger (§5.2), 66,402 real rows.
 *
 * COLUMN ORDER IS VERIFIED HERE, and this is the only report in the app that can say
 * so. The 34 columns come from twelve screenshots of the live report covering the
 * full horizontal scroll (27-Aug-2026), so a reviewer comparing side by side should
 * find the order right. Everywhere else the order is inferred from a form.
 *
 * THREE THINGS THE SCREENSHOTS SETTLED:
 *
 *  1. `ID BIlls` — capital I in "BIlls", a live misspelling, rendered as spelled.
 *  2. `TDS %` is its own column beside `TDS Amount`.
 *  3. `Update Expense` is a per-record action rendered as a BUTTON INSIDE a column,
 *     second from the left. Bills' `Create Payment` sits in a strip under the grid;
 *     this report puts the action in the row. Reproduced, not normalised — the
 *     difference is Creator's.
 *
 * NINE COLUMNS ARE EMPTY and the page says why. The Analytics view fills 24 of the
 * 33 data columns; the rest are on the report and in no export held here. They are
 * shown — because the order is the spec — with a note rather than quietly dropped or,
 * worse, derived. `Payment Status` is the tempting one to fake from `Status`.
 */
const MONEY = new Set(['Gross Amount', 'TDS Amount', 'GST Amount', 'Amount']);
const STAMP = new Set(['Added Time', 'Modified Time']);
const DATE = new Set(['Payment Date', 'Bill Date']);

export default function ExpensesModule() {
  const [data, setData] = useState(null);
  const [error, setError] = useState(null);
  const [filters, setFilters] = useState([]);
  const [filterError, setFilterError] = useState(null);
  const [selected, setSelected] = useState(null);
  const [detail, setDetail] = useState(null);
  const [notice, setNotice] = useState(null);

  const filterKey = JSON.stringify(filters);

  const load = useCallback(() => {
    setError(null);
    fetch(`/api/expenses${filters.length ? `?filters=${encodeURIComponent(JSON.stringify(filters))}` : ''}`)
      .then((r) => (r.ok ? r.json() : Promise.reject(new Error(`HTTP ${r.status}`))))
      .then((body) => {
        if (body.reason === 'bad_filter') { setFilterError(body.message); return; }
        setData(body);
      })
      .catch((e) => setError(String(e.message ?? e)));
  }, [filterKey]);   // eslint-disable-line react-hooks/exhaustive-deps

  useEffect(() => { load(); }, [load]);

  useEffect(() => {
    if (selected === null) { setDetail(null); return; }

    setDetail(null);
    fetch(`/api/expenses/${selected}`)
      .then((r) => (r.ok ? r.json() : Promise.reject(new Error(`HTTP ${r.status}`))))
      .then(setDetail)
      .catch(() => setDetail(null));
  }, [selected]);

  const unsourced = new Set(data?.unsourced ?? []);

  const columns = (data?.columns ?? []).map((label) => ({
    key: label,
    label,
    align: MONEY.has(label) ? 'right' : undefined,
    fill: label === 'Status',
    width: label === 'Particulars' ? 240
      : label === 'Link' || label === 'Bills' ? 200
        : STAMP.has(label) ? 150 : undefined,
    render: (value, row) => {
      /*
       * The action column. A button in the row, as the report has it — and it does
       * NOTHING yet, deliberately: what `Update Expense` does to a ledger entry is
       * unverified, and a live-looking button that mutates the ledger on a guess is
       * exactly the class of thing this project keeps refusing to ship.
       */
      if (label === 'Update Expense') {
        return (
          <button
            type="button"
            className="zc-btn"
            disabled
            title="Update Expense is a per-record action on the live report. What it does is not verified yet, so it is not wired — a button that mutates a ledger entry is not something to guess at."
            onClick={(e) => e.stopPropagation()}
          >
            Update Expense
          </button>
        );
      }

      if (MONEY.has(label)) return inr(value);
      if (DATE.has(label)) return ddMmmYyyy(value);
      if (STAMP.has(label)) {
        // The report shows `27-Aug-2026 19:06:48` — date AND time, not just the date.
        if (!value) return '';
        const [d, t] = String(value).split(' ');
        return `${ddMmmYyyy(d)}${t ? ' ' + t : ''}`;
      }

      return value ?? '';
    },
  }));

  return (
    <>
      <ReportBar
        title={data?.title ?? 'All Expenses'}
        required
        addDisabledReason={
          'An expense is not entered by hand — it is a ledger entry produced by a payment '
          + '(§5.2). 56% of ledger rows come from a payment with no bill, 44% from both. '
          + 'The posting that generates them is not built yet.'
        }
        extras={<button type="button" className="zc-btn" onClick={load}>Refresh</button>}
      />

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

      {notice && (
        <div style={{ padding: '6px 14px', fontSize: 12, color: 'var(--ink2)' }}>{notice}</div>
      )}

      {/*
        Said on screen, because nine blank columns look like a bug otherwise.
      */}
      {unsourced.size > 0 && (
        <p className="zc-field-hint" style={{ margin: '6px 14px' }}>
          Column order is <strong>verified</strong> from the live report — unusually for this
          rebuild. {unsourced.size} columns render blank because the Analytics view does not
          carry them: <em>{[...unsourced].join(', ')}</em>. They are shown in place because the
          order is the specification; the values need a different view or a form-level export.
        </p>
      )}

      {error && <div style={{ padding: 14, color: 'var(--bad)' }}>Failed to load: {error}</div>}
      {!data && !error && <div style={{ padding: 14, color: 'var(--ink3)' }}>Loading…</div>}

      {data && data.rows.length === 0 && (
        <div style={{ padding: 20, color: 'var(--ink3)' }}>
          <p style={{ marginTop: 0 }}>
            {filters.length > 0 ? 'No expense matches these filters.' : 'No expenses yet.'}
          </p>
          {filters.length > 0 && (
            <p style={{ fontSize: 12 }}>
              {data.total} expenses exist and all of them were searched — a nil result here
              means nil, not off-screen.
            </p>
          )}
        </div>
      )}

      {data && data.rows.length > 0 && (
        <ReportGrid
          columns={columns}
          rows={data.rows}
          total={filters.length > 0 ? data.matched : data.total}
          selectedId={selected}
          onSelect={(row) => setSelected(row.id === selected ? null : row.id)}
        />
      )}

      {/*
        The shared grid -> detail -> Edit flow. Field ORDER here is the DETAIL view's
        own, from the screenshots — it leads with Payment Date and the categories and
        puts the amounts in the middle, which is not the grid's order.
      */}
      {detail && (
        <RecordDetail
          title={data?.title ?? 'All Expenses'}
          subtitle={detail.row['Particulars'] || detail.row['ID'] || ''}
          fields={Object.entries(detail.detail).map(([label, value]) => ({
            label,
            value: MONEY.has(label) || label === 'Net Paid Amount' || label === 'PT'
              || label === 'ESIC' || label === 'PF' ? inr(value) : value,
          }))}
          editDisabledReason={
            'An expense is a ledger entry, not a form. The live report offers `Update Expense` '
            + 'as a per-record action and what it changes is unverified, so nothing here writes.'
          }
          onClose={() => setSelected(null)}
        />
      )}
    </>
  );
}
