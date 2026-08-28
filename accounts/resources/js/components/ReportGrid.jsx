import { useCallback, useEffect, useRef } from 'react';
import { multi, showing } from '../lib/format';

/**
 * A Creator report grid.
 *
 * Density is the point (handoff §2 rule 4): ~20+ rows visible without scrolling,
 * 27px rows, 31px sticky headers, 1px borders on both axes, EVERY row white.
 *
 * A column is declared as:
 *   { key, label, align?, width?, multi?, fill?, render? }
 *
 *  - `multi`  values print ONE PER LINE, not comma-joined. This is what makes
 *             rows content-height on reports like Backend Expenses.
 *  - `fill`   renders a SOLID filled status cell, edge to edge — not a chip.
 *             Used on reports with conditional formatting (All Payments, All
 *             Pending Approvals).
 *
 * Column ORDER is whatever the caller passes, because the report's own order is
 * the spec — including that `ID` is not always last (it is 6th of 7 on All Item
 * Categories).
 *
 * ---------------------------------------------------------------------------
 * ENDLESS SCROLL, 28-Aug-2026 (Husain: "1000 records load at a time and then when I keep
 * on scrolling the new records continue to load without a loader and without buffer").
 *
 * Three separate things make that true, and only the first is obvious:
 *
 *  1. **The server pages at 1,000** and says whether another page exists. Before this,
 *     `limit(1000)` was a hard ceiling — row 1,001 of 52,639 simply did not exist as far
 *     as the UI was concerned.
 *
 *  2. **The sentinel sits ONE VIEWPORT EARLY.** `rootMargin: '150% 0px'` fires the fetch
 *     when the end of the list is still one and a half screens away, so the next page is
 *     usually already appended by the time it would have been needed. That is what
 *     "without a loader" actually requires: not hiding a spinner, but arriving early
 *     enough not to need one. A spinner is rendered ONLY if the user outruns the fetch.
 *
 *  3. **`content-visibility: auto` on every row** (see zc.css). Appending pages without
 *     this is the real performance trap: at 24 columns, 5 pages is 120,000 cells and the
 *     browser lays out all of them on every scroll. `content-visibility` lets it skip
 *     the layout and paint of off-screen rows entirely, so a 10,000-row list costs about
 *     what a 1,000-row one did. `contain-intrinsic-size: auto 27px` is what keeps the
 *     scrollbar from jumping: `auto` makes the browser remember each row's real measured
 *     height instead of assuming 27px forever, which matters because `multi` columns make
 *     rows content-height.
 *
 * WHY NOT VIRTUALISE with an absolutely-positioned window? Because rows here are
 * genuinely variable height — six villas in one `Villa Name` cell is a real row (§6.2) —
 * and a fixed-height virtualiser would either clip them or need measurement plumbing
 * that `content-visibility` already does in the browser, correctly, for four CSS lines.
 */
export default function ReportGrid({
  columns,
  rows,
  total,
  selectedId,
  onSelect,
  rowKey = 'id',
  /** Called when the end of the list comes into view. Omit for a non-paging report. */
  onLoadMore,
  /** True while a page is in flight, so the sentinel does not ask twice. */
  loadingMore = false,
  /** False when the server said there is no next page. */
  hasMore = false,
}) {
  const count = total ?? rows.length;
  const scrollRef = useRef(null);
  const sentinelRef = useRef(null);

  /*
   * Held in a ref as well as passed as a prop: the observer callback is created once and
   * would otherwise close over the first render's `hasMore` forever, which is the classic
   * way an infinite list either stops after one page or never stops.
   */
  const state = useRef({ onLoadMore, loadingMore, hasMore });
  state.current = { onLoadMore, loadingMore, hasMore };

  const ask = useCallback(() => {
    const { onLoadMore: load, loadingMore: busy, hasMore: more } = state.current;

    if (load && more && !busy) {
      load();
    }
  }, []);

  useEffect(() => {
    const sentinel = sentinelRef.current;
    const root = scrollRef.current;

    if (!sentinel || !root || !onLoadMore) {
      return undefined;
    }

    /*
     * `root` is the grid's own scroller, not the window — `.zc-gridwrap` carries
     * `overflow: auto`, so a window-rooted observer would never fire.
     */
    const observer = new IntersectionObserver(
      (entries) => { if (entries.some((e) => e.isIntersecting)) ask(); },
      { root, rootMargin: '150% 0px', threshold: 0 },
    );

    observer.observe(sentinel);

    return () => observer.disconnect();
  }, [ask, onLoadMore]);

  /*
   * The observer only fires on a CHANGE of intersection. If a page arrives and the
   * sentinel is still on screen — a short page, or a tall viewport — nothing moves and
   * the list stalls one page in. Re-asking after each append is what keeps it going.
   */
  useEffect(() => {
    if (!onLoadMore || loadingMore || !hasMore) {
      return undefined;
    }

    const sentinel = sentinelRef.current;
    const root = scrollRef.current;

    if (!sentinel || !root) {
      return undefined;
    }

    const gap = sentinel.getBoundingClientRect().top - root.getBoundingClientRect().bottom;

    // Still within the prefetch band, so ask again rather than wait for a scroll that
    // may never come.
    if (gap < root.clientHeight * 1.5) {
      const id = requestAnimationFrame(ask);

      return () => cancelAnimationFrame(id);
    }

    return undefined;
  }, [rows.length, loadingMore, hasMore, onLoadMore, ask]);

  /*
   * PRECOMPUTED PER COLUMN, not per cell.
   *
   * The cell class depends only on `align`, `multi` and `fill` — all COLUMN properties.
   * Building it inside the inner loop meant 34,000 identical array-filter-joins on the
   * All Expenses first page (1,000 rows x 34 columns), recomputed on every render. Now
   * it is 34.
   *
   * `style` is hoisted for the same reason: an inline object literal is a new identity
   * every render, which defeats React's prop comparison on every header cell.
   */
  const prepared = columns.map((column) => ({
    column,
    className: [
      column.align === 'right' ? 'zc-money' : '',
      column.align === 'num' ? 'zc-num' : '',
      column.multi ? 'zc-multi' : '',
      column.fill ? 'zc-fill' : '',
    ].filter(Boolean).join(' ') || undefined,
    headStyle: column.width ? { minWidth: column.width } : undefined,
  }));

  return (
    <>
      <div className="zc-gridwrap" ref={scrollRef}>
        <table className="zc-grid">
          <thead>
            <tr>
              {prepared.map(({ column, headStyle }) => (
                <th key={column.key} style={headStyle}>{column.label}</th>
              ))}
            </tr>
          </thead>
          <tbody>
            {rows.map((row) => (
              <tr
                key={row[rowKey]}
                aria-selected={selectedId === row[rowKey] ? 'true' : undefined}
                onClick={() => onSelect?.(row)}
              >
                {prepared.map(({ column, className }) => {
                  const value = row[column.key];

                  return (
                    <td key={column.key} className={className}>
                      {column.multi
                        ? multi(value).map((line, i) => (
                            <span className="zc-multi-line" key={i}>{line}</span>
                          ))
                        : column.render
                          ? column.render(value, row)
                          : (value ?? '')}
                    </td>
                  );
                })}
              </tr>
            ))}
          </tbody>
        </table>

        {/*
          The trigger. A zero-height div AFTER the table, inside the scroller — a <tr>
          sentinel would need a colspan cell and would count as a row to the reader.
          `aria-hidden` because it is machinery, not content.
        */}
        {onLoadMore && hasMore && (
          <div ref={sentinelRef} className="zc-sentinel" aria-hidden="true" />
        )}
      </div>

      <div className="zc-footer">
        {showing(rows.length, count)}
        {/*
          Only shown if the reader outran the prefetch. Normally the next page lands
          before the end of the list is reached and this never appears.
        */}
        {loadingMore && <span className="zc-footer-note">loading more…</span>}
      </div>
    </>
  );
}
