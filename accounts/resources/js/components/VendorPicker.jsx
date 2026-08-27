import { useEffect, useRef, useState } from 'react';

/**
 * A searchable vendor field, replacing the `<select>` on the Bills form.
 *
 * WHY THIS EXISTS. The Bills form had a plain dropdown fed with every vendor from
 * `/api/bills/options`. That was fine while the database held one fixture vendor.
 * It now holds 8,063 real ones, and a dropdown of 8,063 options is both unusable
 * and a way of pushing the entire vendor table — PANs, GST numbers, bank details —
 * into the browser to fill a control nobody can scroll. Searching happens on the
 * server; this only renders what comes back.
 *
 * WHY THE HINT MATTERS. 62 vendor names occur on more than one record, so a name
 * alone does not identify a vendor — there are two `Hussain` rows and four
 * `ETRADE MARKETING PRIVATE LIMITED` ones. Each result therefore shows its GST or
 * PAN and its location where it has them, because picking the wrong one puts a bill
 * on the wrong vendor and nothing downstream would notice.
 *
 * MERGED-AWAY VENDORS DO NOT APPEAR HERE, by §13A.1: a vendor Creator has merged
 * into another is not a valid target for a NEW bill. The Vendor Master report still
 * lists them — this is a picker rule, not a visibility rule.
 */
export default function VendorPicker({ id, value, initialLabel, onChange, disabled }) {
  const [term, setTerm] = useState('');
  const [open, setOpen] = useState(false);
  const [results, setResults] = useState([]);
  const [matched, setMatched] = useState(null);
  const [chosen, setChosen] = useState(
    value ? { value: String(value), label: initialLabel ?? '' } : null
  );
  const box = useRef(null);

  /*
   * Editing a bill: the form holds an id and no label. Resolve it once, by id, and
   * accept whatever comes back even if that vendor is no longer selectable — a bill
   * raised last month against a since-merged vendor must still display its vendor.
   */
  useEffect(() => {
    if (!value || (chosen && chosen.value === String(value) && chosen.label !== '')) return;

    fetch(`/api/vendors/lookup?id=${encodeURIComponent(value)}`)
      .then((r) => (r.ok ? r.json() : Promise.reject(new Error(`HTTP ${r.status}`))))
      .then((body) => {
        if (body.results?.[0]) setChosen(body.results[0]);
      })
      .catch(() => { /* leave the id showing rather than clearing a real selection */ });
  }, [value]);   // eslint-disable-line react-hooks/exhaustive-deps

  /* Debounced, because every keystroke is a query against 8,063 rows. */
  useEffect(() => {
    if (!open) return undefined;

    const timer = setTimeout(() => {
      fetch(`/api/vendors/lookup?q=${encodeURIComponent(term)}`)
        .then((r) => (r.ok ? r.json() : Promise.reject(new Error(`HTTP ${r.status}`))))
        .then((body) => { setResults(body.results ?? []); setMatched(body.matched ?? null); })
        .catch(() => { setResults([]); setMatched(null); });
    }, 200);

    return () => clearTimeout(timer);
  }, [term, open]);

  /* Click-away closes it. Without this the panel covers the fields below it. */
  useEffect(() => {
    if (!open) return undefined;

    const away = (event) => {
      if (box.current && !box.current.contains(event.target)) setOpen(false);
    };
    document.addEventListener('mousedown', away);

    return () => document.removeEventListener('mousedown', away);
  }, [open]);

  const pick = (option) => {
    setChosen(option);
    onChange(option.value);
    setOpen(false);
    setTerm('');
  };

  const clear = () => {
    setChosen(null);
    onChange('');
    setTerm('');
  };

  return (
    <div ref={box} style={{ position: 'relative' }}>
      {chosen && !open ? (
        <div style={{ display: 'flex', gap: 6, alignItems: 'center' }}>
          <div
            id={id}
            className="zc-input"
            style={{ flex: 1, display: 'flex', gap: 8, alignItems: 'baseline', minWidth: 0 }}
          >
            <span style={{ overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
              {/* Rendered in a <span> so a trailing space or tab in the name is not
                  visually lost — 328 vendor names carry edge whitespace. */}
              {chosen.label || <em style={{ color: 'var(--ink3)' }}>(no name)</em>}
            </span>
            {chosen.hint && (
              <span style={{ color: 'var(--ink3)', fontSize: 11 }}>{chosen.hint}</span>
            )}
          </div>
          <button type="button" className="zc-btn" onClick={() => setOpen(true)} disabled={disabled}>
            Change
          </button>
          <button type="button" className="zc-btn" onClick={clear} disabled={disabled}>
            Clear
          </button>
        </div>
      ) : (
        <input
          id={id}
          type="text"
          className="zc-input"
          role="combobox"
          aria-expanded={open}
          aria-controls={`${id}-results`}
          autoComplete="off"
          placeholder="Search 8,063 vendors by name, GST or PAN…"
          value={term}
          disabled={disabled}
          onFocus={() => setOpen(true)}
          onChange={(e) => { setTerm(e.target.value); setOpen(true); }}
        />
      )}

      {open && (
        <div
          id={`${id}-results`}
          role="listbox"
          style={{
            position: 'absolute', zIndex: 20, left: 0, right: 0, top: '100%',
            marginTop: 2, maxHeight: 260, overflowY: 'auto',
            background: 'var(--bg)', border: '1px solid var(--line)',
            boxShadow: '0 6px 20px rgba(0,0,0,.12)',
          }}
        >
          {results.length === 0 && (
            <div style={{ padding: '8px 10px', color: 'var(--ink3)', fontSize: 12 }}>
              No vendor matches that.
            </div>
          )}

          {results.map((option) => (
            <button
              key={option.value}
              type="button"
              role="option"
              aria-selected={chosen?.value === option.value}
              onClick={() => pick(option)}
              style={{
                display: 'block', width: '100%', textAlign: 'left', border: 0,
                background: 'transparent', padding: '5px 10px', cursor: 'pointer',
                font: 'inherit',
              }}
            >
              <span>{option.label}</span>
              {option.hint && (
                <span style={{ color: 'var(--ink3)', fontSize: 11, marginLeft: 8 }}>{option.hint}</span>
              )}
              {option.location && (
                <span style={{ color: 'var(--ink3)', fontSize: 11, marginLeft: 8 }}>· {option.location}</span>
              )}
            </button>
          ))}

          {/*
            The list is capped at 30 server-side. Saying so matters: a silently
            truncated list looks like a complete one, and the vendor you want may be
            the 31st match.
          */}
          {matched !== null && matched > results.length && (
            <div style={{ padding: '6px 10px', color: 'var(--ink3)', fontSize: 11, borderTop: '1px solid var(--line)' }}>
              Showing {results.length} of {matched} matches — narrow the search.
            </div>
          )}
        </div>
      )}
    </div>
  );
}
