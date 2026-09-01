import { useEffect, useMemo, useRef, useState } from 'react';
import useOverlayZ from '../lib/useOverlayZ';

/**
 * The add / edit form — a near-full-screen OVERLAY, not a centred modal.
 *
 * handoff §3 is explicit: "forms are NEAR-FULL-SCREEN OVERLAYS, not centred
 * modals. v1 used min(1220px,100%) centred; the live forms cover the viewport from
 * left:30px, leaving a sliver of rail." `.zc-overlay` in zc.css already carries
 * `inset: 0 0 0 30px` for exactly that — this uses it rather than re-inventing a
 * dialog.
 *
 * Fields come from the API, which reads App\Domain\Settings\ReportRegistry — the
 * same definition that built the grid.
 *
 * WHITESPACE IS MADE VISIBLE, and this is the part that matters most.
 * `F&B STAFF MEDICAL EXPENSE ` is a live lookup key with a trailing space, and
 * CLAUDE.md forbids normalising it. A trailing space is invisible in a text input,
 * so without a marker the first person to edit that record would "tidy" it and
 * silently break every join that matches on it. The server does not trim
 * (`api/settings/*` is exempt from TrimStrings), so the only remaining risk is a
 * human not seeing what is there. `whitespaceNote()` is the guard against that.
 */

/** Describe leading/trailing whitespace in a value, or null if there is none. */
function whitespaceNote(value) {
  if (typeof value !== 'string' || value === '') return null;

  const leading = value.length - value.replace(/^\s+/, '').length;
  const trailing = value.length - value.replace(/\s+$/, '').length;
  const doubled = /\S {2,}\S/.test(value);

  const parts = [];
  if (leading) parts.push(`${leading} leading space${leading > 1 ? 's' : ''}`);
  if (trailing) parts.push(`${trailing} trailing space${trailing > 1 ? 's' : ''}`);
  if (doubled) parts.push('a doubled space');

  if (parts.length === 0) return null;

  return `Contains ${parts.join(' and ')} — kept exactly as stored. This is a live lookup key; do not tidy it.`;
}

export default function RecordForm({
  title,
  fields,
  fieldsVerified,
  values,
  endpoint,
  method,
  onClose,
  onSaved,
}) {
  const overlayZ = useOverlayZ();
  const isNew = values === null;

  const initial = useMemo(() => {
    const out = {};

    for (const field of fields) {
      if (field.type === 'readonly') continue;
      const stored = values?.[field.column];
      out[field.column] = stored === undefined
        ? (field.type === 'bool' ? false : '')
        : stored;
    }

    return out;
  }, [fields, values]);

  const [form, setForm] = useState(initial);
  const [errors, setErrors] = useState({});
  const [message, setMessage] = useState(null);
  const [saving, setSaving] = useState(false);
  const firstInput = useRef(null);

  useEffect(() => { setForm(initial); setErrors({}); setMessage(null); }, [initial]);
  useEffect(() => { firstInput.current?.focus(); }, []);

  /** Escape closes, matching the rail's flyout. */
  useEffect(() => {
    const onKey = (event) => { if (event.key === 'Escape') onClose(); };
    document.addEventListener('keydown', onKey);
    return () => document.removeEventListener('keydown', onKey);
  }, [onClose]);

  const set = (column, value) => {
    setForm((current) => ({ ...current, [column]: value }));
    setErrors((current) => {
      if (!current[column]) return current;
      const next = { ...current };
      delete next[column];
      return next;
    });
  };

  const save = () => {
    setSaving(true);
    setMessage(null);
    setErrors({});

    fetch(endpoint, {
      method,
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify(form),
    })
      .then(async (response) => {
        const body = await response.json().catch(() => ({}));

        if (response.status === 422) {
          // Laravel's validation shape. Field errors land beside their field;
          // anything else becomes the banner.
          setErrors(body.errors ?? {});
          throw new Error(body.message ?? 'The record was not accepted.');
        }

        if (!response.ok) throw new Error(body.message ?? `HTTP ${response.status}`);

        return body;
      })
      .then(() => onSaved())
      .catch((e) => setMessage(String(e.message ?? e)))
      .finally(() => setSaving(false));
  };

  return (
    <div className="zc-overlay" style={{ zIndex: overlayZ }} role="dialog" aria-modal="true" aria-label={`${isNew ? 'Add' : 'Edit'} ${title}`}>
      <div className="zc-overlay-head">
        {isNew ? `Add ${title}` : `Edit ${title}`}
      </div>

      <div className="zc-overlay-body">
        {message && (
          <p style={{ color: 'var(--bad)', marginTop: 0 }}>{message}</p>
        )}

        {fieldsVerified === false && (
          <p className="zc-field-hint" style={{ marginTop: 0, marginBottom: 16 }}>
            Field set and order are <strong>inferred</strong> from the table and the
            report — no Creator form screenshot exists for this one yet, so treat the
            layout as provisional. The columns themselves are real.
          </p>
        )}

        {fields.map((field, index) => {
          const value = form[field.column];
          const error = errors[field.column]?.[0];
          const note = field.type === 'text' ? whitespaceNote(value) : null;
          const readOnly = field.type === 'readonly';
          const stored = values?.[field.column];

          return (
            <div className="zc-field" key={field.column}>
              <label htmlFor={`f-${field.column}`}>
                {field.label}
                {field.required && <span className="zc-req">*</span>}
              </label>

              <div>
                {readOnly ? (
                  <div style={{ paddingTop: 6, color: 'var(--ink3)' }}>
                    {stored === '' || stored === undefined ? '—' : stored}
                  </div>
                ) : field.type === 'bool' ? (
                  <input
                    id={`f-${field.column}`}
                    type="checkbox"
                    className="zc-check"
                    style={{ marginTop: 7 }}
                    checked={value === true || value === 'true'}
                    onChange={(e) => set(field.column, e.target.checked)}
                  />
                ) : field.type === 'select' ? (
                  <select
                    id={`f-${field.column}`}
                    className="zc-select"
                    value={value ?? ''}
                    onChange={(e) => set(field.column, e.target.value)}
                  >
                    {(field.options ?? []).map((option) => (
                      <option key={option.value} value={option.value}>{option.label}</option>
                    ))}
                  </select>
                ) : field.type === 'decimal' ? (
                  <span style={{ display: 'inline-flex' }}>
                    <input
                      id={`f-${field.column}`}
                      ref={index === 0 ? firstInput : undefined}
                      className="zc-input"
                      style={{ width: 140 }}
                      inputMode="decimal"
                      value={value ?? ''}
                      onChange={(e) => set(field.column, e.target.value)}
                    />
                    {field.suffix && <span className="zc-suffix">{field.suffix}</span>}
                  </span>
                ) : (
                  <input
                    id={`f-${field.column}`}
                    ref={index === 0 ? firstInput : undefined}
                    className="zc-input"
                    value={value ?? ''}
                    onChange={(e) => set(field.column, e.target.value)}
                    // No trimming on blur, on change, or anywhere else.
                    spellCheck={false}
                  />
                )}

                {error && <div className="zc-field-hint" style={{ color: 'var(--bad)' }}>{error}</div>}
                {note && <div className="zc-field-hint" style={{ color: 'var(--pinkd, #b3005c)' }}>{note}</div>}
                {field.hint && <div className="zc-field-hint">{field.hint}</div>}
              </div>
            </div>
          );
        })}

        <div className="zc-commit">
          <button type="button" className="zc-btn zc-btn-primary" disabled={saving} onClick={save}>
            {saving ? 'Saving…' : isNew ? 'Add' : 'Save'}
          </button>
          <button type="button" className="zc-btn" disabled={saving} onClick={onClose}>
            Cancel
          </button>
        </div>
      </div>
    </div>
  );
}
