import { useState } from 'react';

/**
 * The reportbar — the strip every report screen carries: title, the report's own
 * actions, then `Search`, `+` and `…` on the right.
 *
 * WHY THIS IS ONE COMPONENT NOW. Each module was drawing its own bar, so Payments
 * had no `+` at all, the Settings reports had a different set, and the unbuilt
 * pages had no bar whatsoever. The chrome has to be the same everywhere or the app
 * reads as half-finished.
 *
 * THE RULE THAT KEEPS IT HONEST: a control is enabled only when a handler is
 * passed. Give it `onAdd` and `+` works; leave it out and `+` renders disabled with
 * a `title` saying why. That is deliberate — the previous version of this app drew
 * `Search`, `+`, `…`, `Save Changes` and `Remove Changes` as live-looking buttons
 * with no `onClick` behind any of them, and every one of them was a lie. Visible
 * and honestly disabled beats invisible; both beat a button that does nothing when
 * clicked.
 *
 * The `…` menu is disabled on every screen. Its Creator contents have not been seen
 * on any screenshot, and inventing entries would be redesigning rather than
 * replicating (CLAUDE.md's overriding instruction).
 */
export default function ReportBar({
  title,
  required = false,
  /** The report's own buttons — e.g. COA's Save Changes / Remove Changes. */
  children,
  /** Search is enabled when a term handler is supplied. */
  term,
  onTermChange,
  matches,
  searchDisabledReason,
  /** `+` is enabled when this is supplied. */
  onAdd,
  addDisabledReason,
  /** Extra right-side buttons, e.g. Payments' Refresh. */
  extras,
}) {
  const [open, setOpen] = useState(false);

  const searchable = typeof onTermChange === 'function';
  const addable = typeof onAdd === 'function';

  const toggleSearch = () => {
    if (open) onTermChange('');
    setOpen((o) => !o);
  };

  return (
    <>
      <div className="zc-reportbar">
        <span className="zc-reporttitle">{title}</span>
        {required && <span className="zc-req">*</span>}

        {children}

        <span className="zc-appbar-spacer" />

        {extras}

        <button
          type="button"
          className="zc-btn"
          aria-pressed={open}
          disabled={!searchable}
          title={searchable ? 'Search this report' : (searchDisabledReason ?? 'Search needs a built report')}
          onClick={toggleSearch}
        >
          Search
        </button>

        <button
          type="button"
          className="zc-btn zc-btn-plus"
          disabled={!addable}
          title={addable ? `Add to ${title}` : (addDisabledReason ?? 'Add is not available on this report yet')}
          onClick={() => onAdd?.()}
        >
          ＋
        </button>

        <button
          type="button"
          className="zc-btn"
          disabled
          title="More actions — the Creator menu for this report has not been seen on a screenshot yet, so its contents are not guessed at"
        >
          …
        </button>
      </div>

      {open && searchable && (
        <div className="zc-searchrow">
          <input
            className="zc-input"
            autoFocus
            placeholder={`Search ${title}`}
            value={term ?? ''}
            onChange={(e) => onTermChange(e.target.value)}
          />
          {term && (
            <span className="zc-searchchip" onClick={() => onTermChange('')}>
              {matches} match{matches === 1 ? '' : 'es'} · clear
            </span>
          )}
        </div>
      )}
    </>
  );
}
