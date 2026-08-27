import { useCallback, useEffect, useRef, useState } from 'react';
import ReportBar from '../components/ReportBar';
import ReportGrid from '../components/ReportGrid';
import RecordDetail from '../components/RecordDetail';
import { ddMmmYyyy } from '../lib/format';

/**
 * Vendor Master — §13A. 8,063 real records.
 *
 * SEARCHING AND PAGING ARE SERVER-SIDE HERE, unlike every other module in the app.
 * Settings filters 135 rows in the browser and Bills filters a handful, which is
 * right at those sizes. At 8,063 rows carrying PANs, GST registrations and
 * free-text bank details, client-side filtering means shipping the whole table to
 * the browser to look at three fields of it.
 *
 * TWO NAV KEYS, ONE REPORT. Creator's nav has both `Vendor Master` and `All Vendor
 * Masters` and no screenshot of either exists, so the difference between them is
 * unverified. Rather than invent a distinction, both keys render this and the
 * screen says so. The likely difference — one hiding merged-away vendors — is
 * offered as a visible filter instead of being applied silently.
 *
 * NO ADD OR EDIT. §13A.1's merge SEMANTICS are settled; the merge ACTION is not.
 * Nothing yet establishes what Creator does to open bills, payments and requests
 * when two vendors are merged, and 112 records point at 93 targets. The reportbar
 * shows `+` disabled with the reason, per the honest-chrome rule.
 */
const SCOPES = [
  { key: 'all', label: 'All' },
  { key: 'active', label: 'Not merged' },
  { key: 'merged', label: 'Merged away' },
];

export default function VendorMasterModule({ navKey }) {
  const [data, setData] = useState(null);
  const [error, setError] = useState(null);
  const [term, setTerm] = useState('');
  const [scope, setScope] = useState('all');
  const [page, setPage] = useState(1);
  const [selected, setSelected] = useState(null);
  const [detail, setDetail] = useState(null);
  const [loading, setLoading] = useState(false);

  /*
   * REQUESTS ARE SEQUENCED, and this is not premature caution — it was a live bug.
   * Because searching and filtering both happen on the server, two requests are
   * often in flight at once (clear the search, click a filter). Nothing ordered the
   * responses, so a slow earlier reply could land after a fast later one and repaint
   * the grid with the previous query's rows. Caught in a browser: clearing a search
   * and immediately switching to `Merged away` showed 5 rows — the intersection of
   * the two queries — and the grid then sat there looking authoritative.
   *
   * A monotonic ticket, checked on arrival, is the whole fix. AbortController would
   * also work; this keeps the last-write-wins rule visible in one place.
   */
  const ticket = useRef(0);

  const load = useCallback(() => {
    setError(null);
    setLoading(true);
    const mine = ++ticket.current;

    const query = new URLSearchParams({ merged: scope, page: String(page) });
    if (term.trim() !== '') query.set('q', term.trim());

    fetch(`/api/vendors?${query}`)
      .then((r) => (r.ok ? r.json() : Promise.reject(new Error(`HTTP ${r.status}`))))
      .then((body) => { if (mine === ticket.current) { setData(body); setLoading(false); } })
      .catch((e) => {
        if (mine !== ticket.current) return;
        setError(String(e.message ?? e));
        setLoading(false);
      });
  }, [scope, page, term]);

  /* Debounced: every keystroke is a query across 8,063 rows and twelve columns. */
  useEffect(() => {
    const timer = setTimeout(load, 220);

    return () => clearTimeout(timer);
  }, [load]);

  /* A new search or filter invalidates the page number. */
  useEffect(() => { setPage(1); }, [term, scope]);

  useEffect(() => {
    if (selected === null) { setDetail(null); return; }

    setDetail(null);
    fetch(`/api/vendors/${selected}`)
      .then((r) => (r.ok ? r.json() : Promise.reject(new Error(`HTTP ${r.status}`))))
      .then(setDetail)
      .catch(() => setDetail(null));
  }, [selected]);

  const columns = (data?.columns ?? []).map((key) => ({
    key,
    // Creator shows all three GST columns under the same bare `GST No.` heading.
    // The suffix exists only because a JSON row cannot repeat a key.
    label: data?.relabel?.[key] ?? key,
    width: key === 'Account Details' ? 220 : (key === 'Vendor Name' ? 200 : undefined),
    render: key.endsWith('Time')
      ? (value) => ddMmmYyyy(value)
      : (value) => {
          /*
           * Edge whitespace is rendered VISIBLY rather than lost. 326 vendor names
           * carry a leading or trailing space and two end in tabs, and they are
           * distinct records — `ETRADE MARKETING PRIVATE LIMITED ` is not the same
           * vendor as `ETRADE MARKETING PRIVATE LIMITED`. HTML collapses that
           * difference to nothing, so a marker is drawn where the whitespace is.
           * Display-only: the value itself is never touched (CLAUDE.md's rule).
           */
          if (key !== 'Vendor Name' || typeof value !== 'string') return value ?? '';

          /*
           * The 5 nameless vendors sort to the top of the report, so the first thing
           * the screen shows is five empty rows. Faithful, and indistinguishable from
           * a rendering fault. Marked for the same reason the whitespace is: the
           * reader has to be able to tell "this record has no name" from "this cell
           * failed to draw". Display only — the stored value stays ''.
           */
          if (value === '') {
            return <em style={{ color: 'var(--ink3)' }} title="This vendor record has no name in Creator. It is live data — §13A records Payment Requests approved against a blank vendor.">(no name)</em>;
          }

          if (value === value.trim()) return value;

          const lead = value.length - value.trimStart().length;
          const trail = value.length - value.trimEnd().length;

          return (
            <>
              {lead > 0 && <Whitespace text={value.slice(0, lead)} />}
              {value.trim()}
              {trail > 0 && <Whitespace text={value.slice(value.length - trail)} />}
            </>
          );
        },
  }));

  const pages = data?.pages ?? 1;

  return (
    <>
      <ReportBar
        title={data?.title ?? 'All Vendor Masters'}
        required
        term={term}
        onTermChange={setTerm}
        matches={data?.matched ?? 0}
        addDisabledReason={
          'Add is not built for vendors: §13A.1’s merge semantics are settled but the '
          + 'merge ACTION is not — what Creator does to open bills and payments when two '
          + 'vendors are merged is unverified, and 112 records point at 93 targets.'
        }
        extras={<button type="button" className="zc-btn" onClick={load}>Refresh</button>}
      >
        {SCOPES.map((option) => (
          <button
            key={option.key}
            type="button"
            className="zc-btn"
            aria-pressed={scope === option.key}
            onClick={() => setScope(option.key)}
            title={
              option.key === 'active'
                ? 'Hides the 112 vendors merged into another (§13A.1)'
                : option.key === 'merged'
                  ? 'Only the vendors merged into another'
                  : 'Every vendor record'
            }
          >
            {option.label}
            {data?.counts && ` (${data.counts[option.key]})`}
          </button>
        ))}
      </ReportBar>

      {/*
        Said on screen, not just in a docblock: the reader needs to know which parts
        of this replica are verified and which are not.
      */}
      <p className="zc-field-hint" style={{ margin: '6px 14px' }}>
        Column order comes from the report export itself, so it is <strong>verified</strong>
        {' '}— unusually for this rebuild. Which of Creator’s two vendor reports
        (<em>Vendor Master</em> / <em>All Vendor Masters</em>) this is, is not: no screenshot of
        either exists, so <code>{navKey}</code> shows the same report as the other key.
      </p>

      {error && <div style={{ padding: 14, color: 'var(--bad)' }}>Failed to load: {error}</div>}
      {!data && !error && <div style={{ padding: 14, color: 'var(--ink3)' }}>Loading…</div>}

      {/*
        Dimmed rather than blanked while a request is in flight. Blanking makes every
        keystroke flash the grid empty; leaving it undimmed presents the previous
        query's rows as the answer to the current one.
      */}
      {data && (
        <div style={{ opacity: loading ? 0.55 : 1, transition: 'opacity 120ms' }}>
        <ReportGrid
          columns={columns}
          rows={data.rows}
          total={data.matched}
          selectedId={selected}
          onSelect={(row) => setSelected(row.id === selected ? null : row.id)}
        />
        </div>
      )}

      {data && pages > 1 && (
        <div style={{ borderTop: '1px solid var(--line)', padding: '7px 14px', display: 'flex', gap: 8, alignItems: 'center', fontSize: 12 }}>
          <button type="button" className="zc-btn" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>
            Previous
          </button>
          <span style={{ color: 'var(--ink3)' }}>
            Page {data.page} of {pages} · {data.matched} matching {data.total} total
          </span>
          <button type="button" className="zc-btn" disabled={page >= pages} onClick={() => setPage((p) => p + 1)}>
            Next
          </button>
        </div>
      )}

      {/*
        The merge panel — the whole point of settling §13A.1. It says, per record,
        whether this vendor was merged away, whether things were merged into it, and
        where the pointer could not be resolved to a single row.
      */}
      {/*
        The shared grid -> detail -> Edit flow (Husain, 25-Aug-2026). Edit stays
        unavailable: §13A.1's merge SEMANTICS are settled, the merge ACTION is not,
        and 112 records point at 93 targets.
      */}
      {detail && (
        <RecordDetail
          title={data?.title ?? 'All Vendor Masters'}
          subtitle={detail.row['Vendor Name'] || '(no name)'}
          fields={(data?.columns ?? []).map((key) => ({
            label: data?.relabel?.[key] ?? key,
            value: key.endsWith('Time') ? ddMmmYyyy(detail.row[key]) : detail.row[key],
          }))}
          editDisabledReason={
            'Vendors have no write path yet: §13A.1’s merge semantics are settled but the '
            + 'merge ACTION is not — what Creator does to open bills and payments when two '
            + 'vendors are merged is unverified.'
          }
          onClose={() => setSelected(null)}
        >
        <div style={{ fontSize: 12, display: 'flex', gap: 18, flexWrap: 'wrap', marginBottom: 14 }}>

          {detail.merge.primary_vendor === null && !detail.merge.is_merge_target && (
            <span style={{ color: 'var(--ink3)' }}>Not part of any merge.</span>
          )}

          {detail.merge.primary_vendor !== null && (
            <span>
              Merged into <strong>{detail.merge.primary_vendor}</strong>
              {detail.merge.resolved === 'ambiguous' && (
                <span style={{ color: 'var(--bad)' }}>
                  {' '}— that name matches several vendor records, so Creator’s pointer does not
                  identify one. The name is stored; no link is guessed.
                </span>
              )}
            </span>
          )}

          {detail.merge.is_merge_target && (
            <span>
              Merge target — {detail.merge.merged_in.length} vendor
              {detail.merge.merged_in.length === 1 ? '' : 's'} merged into this one
              {detail.merge.merged_in.length > 0 && (
                <span style={{ color: 'var(--ink3)' }}>
                  : {detail.merge.merged_in.map((v) => v.name).join(', ')}
                </span>
              )}
            </span>
          )}

          {detail.row['Main Primary'] !== '' && detail.row['Main Primary'] !== detail.row['Vendor Name'] && (
            <span style={{ color: 'var(--ink3)' }}>
              Main Primary says <strong>{detail.row['Main Primary']}</strong> — a display field
              that goes stale, never used to resolve a merge (§13A.1).
            </span>
          )}
        </div>
        </RecordDetail>
      )}
    </>
  );
}

/** A visible stand-in for edge whitespace in a name. Display only. */
function Whitespace({ text }) {
  const glyph = [...text].map((c) => (c === '\t' ? '⇥' : '·')).join('');

  return (
    <span
      style={{ color: 'var(--bad)', opacity: 0.7 }}
      title={`${text.length} whitespace character(s) stored in this name — it is part of the lookup key`}
    >
      {glyph}
    </span>
  );
}
