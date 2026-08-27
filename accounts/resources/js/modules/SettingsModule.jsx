import { useCallback, useEffect, useMemo, useState } from 'react';
import ReportBar from '../components/ReportBar';
import ReportGrid from '../components/ReportGrid';
import RecordDetail from '../components/RecordDetail';
import RecordForm from '../components/RecordForm';
import { inr } from '../lib/format';

/**
 * The Settings reports, with add and edit actually wired up.
 *
 * WHAT WAS WRONG. The reportbar rendered `Search`, `+`, `…`, `Save Changes` and
 * `Remove Changes` as plain buttons with no `onClick` and no write routes behind
 * them. Every control was chrome. The CSS for forms and overlays
 * (`.zc-overlay`, `.zc-field`, `.zc-input`, `.zc-check`, `.zc-commit`,
 * `.zc-searchrow`) was already in `zc.css`, unused — the intent was there, the
 * wiring never landed.
 *
 * The field definitions come from the API, which reads the same
 * App\Domain\Settings\ReportRegistry that built the grid. One definition, so a
 * form and its report cannot drift.
 *
 * BOOLEANS RENDER AS CHECKBOXES, not the literal text "false". The previous pass
 * printed `String(value)`, so `Exclude for Profit` read "false" on all 135 rows.
 * Creator shows a checkbox.
 *
 * WHITESPACE IS MADE VISIBLE. `F&B STAFF MEDICAL EXPENSE ` has a trailing space
 * and it is a live lookup key — CLAUDE.md forbids normalising it. A trailing space
 * is invisible in a text input, so anyone editing that record would "fix" it
 * without meaning to. `RecordForm` marks it instead.
 */

/** Columns whose values are percentages, so they right-align and format. */
const PERCENT = new Set(['TDS Percentage', 'Tax Percentage', 'Variance']);

export default function SettingsModule({ report }) {
  const [data, setData] = useState(null);
  const [error, setError] = useState(null);

  const [term, setTerm] = useState('');

  /** null = closed · 'new' = add · a number = editing that record. */
  const [editing, setEditing] = useState(null);

  /*
   * A ROW CLICK OPENS THE DETAIL VIEW, NOT THE FORM.
   *
   * This report used to open the edit form straight from a row click, which was
   * the odd one out: Bills, Payments and Vendor Master all selected first. Husain
   * settled it on 25-Aug-2026 — every report is grid -> detail -> Edit -> form.
   * Landing directly in an editable form also means every look at a live lookup
   * key is one keystroke from changing it.
   */
  const [viewing, setViewing] = useState(null);

  /** COA inline edits, as { [rowId]: { [column]: value } }. */
  const [edits, setEdits] = useState({});
  const [saving, setSaving] = useState(false);
  const [notice, setNotice] = useState(null);

  const load = useCallback(() => {
    setError(null);
    fetch(`/api/settings/reports/${report}`)
      .then((r) => (r.ok ? r.json() : Promise.reject(new Error(`HTTP ${r.status}`))))
      .then(setData)
      .catch((e) => setError(String(e.message ?? e)));
  }, [report]);

  // Switching reports must drop the previous one's pending state, or an edit
  // typed on COA would appear to belong to TDS.
  useEffect(() => {
    setData(null);
    setEdits({});
    setEditing(null);
    setViewing(null);
    setNotice(null);
    setTerm('');
    load();
  }, [report, load]);

  const inline = data?.inline_editable === true;
  const inlineColumns = useMemo(() => new Set(data?.inline_columns ?? []), [data]);
  const columnMap = data?.column_map ?? {};
  const dirtyCount = Object.keys(edits).length;

  /** Cell value, preferring a pending inline edit over the stored one. */
  const cellValue = (row, label) => {
    const column = columnMap[label];
    const pending = edits[row.id]?.[column];
    return pending === undefined ? row[label] : pending;
  };

  const setCell = (row, label, value) => {
    const column = columnMap[label];

    setEdits((current) => {
      const forRow = { ...(current[row.id] ?? {}), [column]: value };

      // Dropping a value back to its stored form should clear the edit, so
      // `Save Changes` does not report changes that are not changes.
      if (String(value) === String(row[label])) {
        delete forRow[column];
      }

      const next = { ...current };
      if (Object.keys(forRow).length === 0) delete next[row.id];
      else next[row.id] = forRow;

      return next;
    });
  };

  const rows = useMemo(() => {
    const all = data?.rows ?? [];
    const needle = term.trim().toLowerCase();

    if (needle === '') return all;

    // Search every displayed column. Creator's search is a single box, not
    // per-column, and it matches on substrings.
    return all.filter((row) =>
      (data?.columns ?? []).some((label) => String(row[label] ?? '').toLowerCase().includes(needle))
    );
  }, [data, term]);

  const columns = useMemo(() => (data?.columns ?? []).map((label) => {
    const column = columnMap[label];
    const editableHere = inline && inlineColumns.has(column);

    return {
      key: label,
      label,
      align: PERCENT.has(label) ? 'num' : undefined,
      render: (value, row) => {
        const current = inline ? cellValue(row, label) : value;

        if (typeof row[label] === 'boolean') {
          const checked = current === true || current === 'true';

          /*
           * A READ-ONLY CHECKBOX MUST NOT EAT THE ROW CLICK.
           *
           * Found in the browser: clicking the middle of a row did nothing at all,
           * because the midpoint of a seven-column row lands on `Exclude for
           * Profit` — a checkbox. A `disabled` input dispatches no click event and
           * does not let one through to its ancestors either, so the row's own
           * handler never fired and the edit form never opened. `pointer-events:
           * none` hands the click to the row instead.
           *
           * `stopPropagation` stays on the EDITABLE case, where it is correct:
           * inline-editable reports do not open a form on row click, so a click in
           * a cell should toggle the box and nothing more.
           */
          if (!editableHere) {
            return (
              <input
                type="checkbox"
                className="zc-check"
                style={{ pointerEvents: 'none' }}
                checked={checked}
                readOnly
                tabIndex={-1}
                aria-label={label}
              />
            );
          }

          return (
            <input
              type="checkbox"
              className="zc-check"
              checked={checked}
              onChange={(e) => setCell(row, label, e.target.checked)}
              onClick={(e) => e.stopPropagation()}
              aria-label={label}
            />
          );
        }

        if (editableHere) {
          return (
            <input
              className="zc-input"
              value={current ?? ''}
              onChange={(e) => setCell(row, label, e.target.value)}
              onClick={(e) => e.stopPropagation()}
              aria-label={label}
            />
          );
        }

        if (PERCENT.has(label)) return inr(value, 3);

        return value ?? '';
      },
    };
  }), [data, inline, inlineColumns, columnMap, edits, term]);

  const commitInline = () => {
    setSaving(true);
    setNotice(null);

    const changes = Object.entries(edits).map(([id, values]) => ({ id: Number(id), values }));

    fetch(`/api/settings/reports/${report}`, {
      method: 'PATCH',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({ changes }),
    })
      .then(async (response) => {
        const body = await response.json();
        if (!response.ok) throw new Error(body.message ?? `HTTP ${response.status}`);
        return body;
      })
      .then((body) => {
        setEdits({});
        setNotice({ kind: 'ok', text: `Saved ${body.count} row${body.count === 1 ? '' : 's'}.` });
        load();
      })
      .catch((e) => setNotice({ kind: 'bad', text: String(e.message ?? e) }))
      .finally(() => setSaving(false));
  };

  const editingRow = typeof editing === 'number'
    ? (data?.rows ?? []).find((row) => row.id === editing)
    : null;

  return (
    <>
      <ReportBar
        title={data?.title ?? report}
        required
        term={term}
        onTermChange={setTerm}
        matches={rows.length}
        onAdd={data ? () => setEditing('new') : undefined}
        addDisabledReason="Loading the report definition…"
      >
        {inline && (
          <>
            <button
              type="button"
              className="zc-btn zc-btn-primary"
              disabled={dirtyCount === 0 || saving}
              onClick={commitInline}
            >
              {saving ? 'Saving…' : `Save Changes${dirtyCount ? ` (${dirtyCount})` : ''}`}
            </button>
            <button
              type="button"
              className="zc-btn"
              disabled={dirtyCount === 0 || saving}
              onClick={() => { setEdits({}); setNotice(null); }}
            >
              Remove Changes
            </button>
          </>
        )}
      </ReportBar>

      {notice && (
        <div style={{ padding: '6px 14px', fontSize: 12, color: notice.kind === 'ok' ? 'var(--ink2)' : 'var(--bad)' }}>
          {notice.text}
        </div>
      )}

      {error && <div style={{ padding: 14, color: 'var(--bad)' }}>Failed to load: {error}</div>}
      {!data && !error && <div style={{ padding: 14, color: 'var(--ink3)' }}>Loading…</div>}

      {data && (
        <ReportGrid
          columns={columns}
          rows={rows}
          total={term ? rows.length : data.total}
          selectedId={viewing ?? (typeof editing === 'number' ? editing : null)}
          // On an inline-editable report a click lands in a cell, so opening an
          // overlay on top of it would fight the thing the user is doing.
          onSelect={inline ? undefined : (row) => setViewing(row.id)}
        />
      )}

      {viewing !== null && data && (() => {
        const row = rows.find((r) => r.id === viewing);
        if (!row) return null;

        return (
          <RecordDetail
            title={data.title}
            subtitle={String(row[data.columns[0]] ?? '')}
            fields={data.columns.map((label) => ({ label, value: row[label] }))}
            onEdit={() => { setViewing(null); setEditing(row.id); }}
            onClose={() => setViewing(null)}
          />
        );
      })()}

      {editing !== null && data && (
        <RecordForm
          title={data.title}
          fields={data.fields}
          fieldsVerified={data.fields_verified}
          values={editingRow?._values ?? null}
          onClose={() => setEditing(null)}
          onSaved={() => { setEditing(null); load(); }}
          endpoint={editing === 'new'
            ? `/api/settings/reports/${report}`
            : `/api/settings/reports/${report}/${editing}`}
          method={editing === 'new' ? 'POST' : 'PATCH'}
        />
      )}
    </>
  );
}
