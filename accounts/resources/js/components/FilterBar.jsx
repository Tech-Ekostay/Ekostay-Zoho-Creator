import { useEffect, useState } from 'react';

/**
 * Creator's report filter: column + operator + value, shown as dismissable chips.
 *
 * WHAT THE SCREENSHOT SHOWS, and this is the whole basis for the design. The live
 * All Payments report (25-Aug-2026) renders:
 *
 *     SEARCH   Payment No. contains "EKS/PY/1596…"   (x)
 *
 * A filter is therefore a COLUMN, an OPERATOR and a VALUE, displayed as a chip with
 * a clear control — not the free-text box this app had.
 *
 * TWO operators are now verified. A Bank chip caught in the background of the App
 * Preferences screenshot (27-Aug-2026) reads `Amount is "1713…"`, so Creator spells
 * equality **`is`**, not `equals`, and applies it to a number column. `contains` and
 * `is` are confirmed; the rest of the list is still INFERRED and the panel says so.
 *
 * WHY IT REPLACES THE OLD SEARCH. The free-text box filtered rows the browser had
 * already loaded — the first 1,000 payments of 52,638. So searching for a payment at
 * row 5,000 returned nothing and looked like "no such payment" rather than "not in
 * the page you have". Filtering is now a server round-trip on every report.
 *
 * MULTIPLE CHIPS COMBINE WITH AND. Creator may support OR groups; none has been
 * seen, so none is invented.
 */
export default function FilterBar({
  /** [{ label, type, operators }] from the report's own filter schema. */
  schema = [],
  /** Active filters: [{ column, operator, value }]. */
  filters = [],
  onChange,
  /** Rows matching, for the count beside the chips. */
  matched,
  total,
  busy = false,
}) {
  const [open, setOpen] = useState(false);
  const [draft, setDraft] = useState({ column: '', operator: '', value: '' });

  const spec = schema.find((s) => s.label === draft.column);
  const valueless = ['is empty', 'is not empty', 'is true', 'is false'].includes(draft.operator);

  /* Picking a column resets the operator to that column's first — the operator
     lists differ by type, so carrying one across would offer an invalid pair. */
  useEffect(() => {
    if (!spec) return;
    if (!spec.operators.includes(draft.operator)) {
      setDraft((d) => ({ ...d, operator: spec.operators[0], value: '' }));
    }
  }, [draft.column]);   // eslint-disable-line react-hooks/exhaustive-deps

  const add = () => {
    if (!draft.column || !draft.operator) return;
    if (!valueless && draft.value.trim() === '') return;

    onChange([...filters, { ...draft, value: valueless ? '' : draft.value }]);
    setDraft({ column: '', operator: '', value: '' });
    setOpen(false);
  };

  const remove = (i) => onChange(filters.filter((_, j) => j !== i));

  return (
    <>
      <div className="zc-searchrow" style={{ flexWrap: 'wrap', gap: 8 }}>
        <button type="button" className="zc-btn" onClick={() => setOpen((o) => !o)}
          aria-expanded={open} disabled={schema.length === 0}
          title={schema.length === 0 ? 'This report has no filter schema yet' : 'Add a filter'}>
          {open ? 'Cancel' : '+ Add filter'}
        </button>

        {/* The chips. `Payment No. contains "…"` with a clear control, per the screenshot. */}
        {filters.map((f, i) => (
          <span className="zc-searchchip" key={i} style={{ cursor: 'default' }}>
            <strong>{f.column}</strong>{' '}
            <em style={{ fontStyle: 'normal', color: 'var(--ink3)' }}>{f.operator}</em>
            {f.value !== '' && <> {'"'}{f.value}{'"'}</>}
            <button type="button" onClick={() => remove(i)}
              title="Remove this filter"
              style={{ marginLeft: 6, border: 0, background: 'transparent', cursor: 'pointer', font: 'inherit' }}>
              ✕
            </button>
          </span>
        ))}

        {filters.length > 1 && (
          <span style={{ fontSize: 11, color: 'var(--ink3)' }}>
            all filters must match (AND)
          </span>
        )}

        {filters.length > 0 && (
          <button type="button" className="zc-btn" onClick={() => onChange([])}>Clear all</button>
        )}

        <span className="zc-appbar-spacer" />

        <span style={{ fontSize: 12, color: 'var(--ink3)' }}>
          {busy ? 'filtering…'
            : matched === undefined ? ''
              : filters.length > 0 ? `${matched} of ${total} match`
                : `${total} rows`}
        </span>
      </div>

      {open && (
        <div className="zc-searchrow" style={{ gap: 8, flexWrap: 'wrap', alignItems: 'flex-start' }}>
          <select className="zc-select" value={draft.column}
            onChange={(e) => setDraft((d) => ({ ...d, column: e.target.value }))}
            aria-label="Column to filter on">
            <option value="">— column —</option>
            {schema.map((s) => <option key={s.label} value={s.label}>{s.label}</option>)}
          </select>

          <select className="zc-select" value={draft.operator} disabled={!spec}
            onChange={(e) => setDraft((d) => ({ ...d, operator: e.target.value, value: '' }))}
            aria-label="Operator">
            {(spec?.operators ?? []).map((o) => <option key={o} value={o}>{o}</option>)}
          </select>

          {!valueless && (
            <input className="zc-input" style={{ minWidth: 220 }} value={draft.value}
              placeholder={spec?.type === 'date' ? 'YYYY-MM-DD' : 'value'}
              aria-label="Value"
              onChange={(e) => setDraft((d) => ({ ...d, value: e.target.value }))}
              onKeyDown={(e) => { if (e.key === 'Enter') add(); }} />
          )}

          <button type="button" className="zc-btn zc-btn-primary" onClick={add}
            disabled={!draft.column || !draft.operator || (!valueless && draft.value.trim() === '')}>
            Apply
          </button>

          <div className="zc-field-hint" style={{ flexBasis: '100%', marginTop: 2 }}>
            Filtering happens on the server, so it covers every row — not just the page loaded.
            {' '}<strong>contains</strong> and <strong>is</strong> are confirmed from live filter
            chips; the rest of this list is inferred, since no screenshot shows
            Creator&rsquo;s full operator menu.
          </div>
        </div>
      )}
    </>
  );
}
