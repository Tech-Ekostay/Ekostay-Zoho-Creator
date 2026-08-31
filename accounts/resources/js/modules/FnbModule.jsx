import { useCallback, useEffect, useMemo, useState } from 'react';
import ReportGrid from '../components/ReportGrid';
import { inr } from '../lib/format';

/**
 * F&B — read-only reports over the 22 tables built so far.
 *
 * THIS IS NOT A CREATOR REPLICA, and says so on screen. Creator's F&B app has 21
 * reports with their own layouts and only ONE of them has been screenshotted
 * (All Vendor Order Bookings, findings §8.6). Replicating a screen nobody has seen
 * would be inventing it. So this is an inspection surface: it shows what the
 * schema holds and is honest about which column orders are verified.
 *
 * When the screenshots arrive, each report gets a proper module with Creator's
 * layout and this one goes away.
 *
 * WHITESPACE IS MADE VISIBLE. `Pieces ` and `Variance ` carry trailing spaces that
 * are live lookup keys — 70 items join to the first. An invisible trailing space is
 * how someone "tidies" one and orphans the rows.
 */
export default function FnbModule() {
  const [reports, setReports] = useState(null);
  const [awaiting, setAwaiting] = useState({});
  const [active, setActive] = useState(null);
  const [data, setData] = useState(null);
  const [query, setQuery] = useState('');
  const [error, setError] = useState(null);

  useEffect(() => {
    fetch('/api/fnb/reports')
      .then((r) => r.json())
      .then((j) => {
        setReports(j.reports ?? []);
        setAwaiting(j.awaiting_import ?? {});
        const first = (j.reports ?? []).find((x) => x.count > 0) ?? (j.reports ?? [])[0];
        if (first) setActive(first.key);
      })
      .catch((e) => setError(String(e)));
  }, []);

  const load = useCallback((key, q) => {
    if (!key) return;
    setData(null);
    const url = `/api/fnb/reports/${key}?per_page=500${q ? `&q=${encodeURIComponent(q)}` : ''}`;
    fetch(url)
      .then((r) => r.json())
      .then(setData)
      .catch((e) => setError(String(e)));
  }, []);

  useEffect(() => { load(active, query); }, [active, query, load]);

  const columns = useMemo(() => (data?.columns ?? []).map((c) => ({
    key: c.key,
    // A trailing space in a HEADER is as load-bearing as one in a value: the
    // export writes `Variance ` and reading it by the trimmed name loses the
    // column. ReportGrid renders `label` as plain text, so the marker goes in the
    // string rather than a prop it does not support.
    label: c.label !== c.label.trim() ? `${c.label.trim()} ␣` : c.label,
    align: ['money', 'qty', 'percent'].includes(c.type) ? 'right' : undefined,
    render: (value) => renderCell(value, c.type),
  })), [data]);

  if (error) {
    return <div style={S.pad}><p style={S.err}>Could not load F&amp;B reports: {error}</p></div>;
  }
  if (!reports) {
    return <div style={S.pad}><p style={S.dim}>Loading…</p></div>;
  }

  return (
    <div style={S.wrap}>
      <div style={S.banner}>
        <strong>Inspection surface, not a Creator replica.</strong> Creator has 21 F&amp;B
        reports and only one has been screenshotted, so these grids show what the schema
        holds rather than reproducing a layout nobody has seen. Read-only: there is no
        write path, and no authorisation on these endpoints.
      </div>

      <div style={S.tabs}>
        {reports.map((r) => (
          <button
            key={r.key}
            onClick={() => { setActive(r.key); setQuery(''); }}
            style={{ ...S.tab, ...(r.key === active ? S.tabOn : null), ...(r.count === 0 ? S.tabEmpty : null) }}
            title={r.note ?? ''}
          >
            {r.label}
            <span style={S.count}>{r.count.toLocaleString('en-IN')}</span>
          </button>
        ))}
      </div>

      {data ? (
        <>
          <div style={S.bar}>
            <h1 style={S.h1}>{data.label}</h1>
            {!data.verified && (
              <span style={S.warn} title="No Creator screenshot exists for this report">
                column order inferred
              </span>
            )}
            <div style={S.spring} />
            {['fnb_item_masters', 'fnb_inventories'].includes(active) && (
              <input
                style={S.search}
                placeholder="Search item name…"
                defaultValue={query}
                onKeyDown={(e) => { if (e.key === 'Enter') setQuery(e.currentTarget.value); }}
              />
            )}
          </div>

          {data.note && <p style={S.note}>{data.note}</p>}

          <ReportGrid
            columns={columns}
            rows={data.rows.map((r, i) => ({ ...r, id: i }))}
            total={data.total}
          />
        </>
      ) : (
        <p style={S.dim}>Loading…</p>
      )}

      <div style={S.awaiting}>
        <strong style={S.awaitingH}>Built, awaiting data — {Object.keys(awaiting).length} reports</strong>
        <p style={S.dim}>
          These tables exist with their constraints and models. A report that is simply
          absent looks like it was never built, so they are listed.
        </p>
        <ul style={S.list}>
          {Object.entries(awaiting).map(([label, why]) => (
            <li key={label} style={S.li}>
              <span>{label}</span>
              <span style={S.why}>{why}</span>
            </li>
          ))}
        </ul>
      </div>
    </div>
  );
}

/** Whitespace visible, money Indian-grouped, booleans as Creator writes them. */
function renderCell(value, type) {
  if (value === null || value === undefined || value === '') {
    return <span style={S.blank}>—</span>;
  }

  if (type === 'bool') {
    // Creator writes the literal strings `true` / `false` in its exports, and a
    // previous defect here rendered them as the text "false" on all 135 rows.
    return value === true || value === 'true' ? 'Yes' : 'No';
  }

  if (type === 'money') return inr(value);

  if (type === 'percent') return `${Number(value).toFixed(2)}%`;

  if (type === 'qty') {
    // Trailing zeros trimmed for reading, but 0 shown as 0 rather than blank:
    // 390 inventory rows genuinely sit at zero stock and that is not missing data.
    const n = Number(value);
    return Number.isFinite(n) ? String(n) : String(value);
  }

  const s = String(value);
  if (s !== s.trim()) {
    // The whole point. `Pieces ` is a live key and the space must be seen.
    return (
      <span>
        {s.trim()}
        <span style={S.space} title={`stored as ${JSON.stringify(s)} — trailing space is a live lookup key`}>␣</span>
      </span>
    );
  }

  return s;
}

const S = {
  wrap: { padding: '0 0 32px' },
  pad: { padding: 20 },
  banner: {
    margin: '0 0 12px', padding: '9px 14px', fontSize: 12.5, lineHeight: 1.55,
    background: '#fffaf2', borderBottom: '1px solid #f0e0c4', color: '#6b5322',
  },
  tabs: { display: 'flex', gap: 4, flexWrap: 'wrap', padding: '0 14px 10px' },
  tab: {
    display: 'flex', alignItems: 'center', gap: 6, border: '1px solid var(--line)',
    background: '#fff', borderRadius: 4, padding: '5px 10px', fontSize: 12.5,
    cursor: 'pointer', font: 'inherit', color: 'var(--ink2)',
  },
  tabOn: { borderColor: 'var(--pink)', color: 'var(--pink)', fontWeight: 600 },
  tabEmpty: { opacity: 0.55 },
  count: {
    fontSize: 10.5, fontFamily: "'Roboto Mono', monospace",
    background: 'var(--bg)', borderRadius: 3, padding: '1px 5px',
  },
  bar: { display: 'flex', alignItems: 'center', gap: 10, padding: '0 14px 8px' },
  h1: { margin: 0, fontSize: 16, fontWeight: 600 },
  warn: {
    fontSize: 10.5, color: '#8a5a10', background: '#fff3dc',
    borderRadius: 3, padding: '2px 6px', cursor: 'help',
  },
  spring: { flex: 1 },
  search: {
    height: 28, padding: '0 8px', fontSize: 12.5, border: '1px solid #d3d8e1',
    borderRadius: 4, width: 220,
  },
  note: { margin: '0 14px 10px', fontSize: 11.5, lineHeight: 1.6, color: 'var(--ink3)', maxWidth: 900 },
  dim: { margin: '8px 14px', fontSize: 11.5, color: 'var(--ink3)' },
  err: { color: '#b0143c', fontSize: 13 },
  blank: { color: 'var(--ink4)' },
  space: {
    display: 'inline-block', marginLeft: 1, padding: '0 2px', borderRadius: 2,
    background: '#fdeef3', color: 'var(--pink)', cursor: 'help', fontSize: 11,
  },
  awaiting: { margin: '22px 14px 0', padding: '12px 14px', background: '#fbfcfd', border: '1px dashed #cfd5df', borderRadius: 6, maxWidth: 780 },
  awaitingH: { fontSize: 12.5 },
  list: { listStyle: 'none', margin: '8px 0 0', padding: 0 },
  li: { display: 'flex', justifyContent: 'space-between', gap: 12, fontSize: 11.5, padding: '3px 0', borderBottom: '1px solid #eef0f4' },
  why: { color: 'var(--ink3)', whiteSpace: 'nowrap' },
};
