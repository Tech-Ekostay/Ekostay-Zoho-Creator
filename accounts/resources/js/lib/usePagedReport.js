import { useCallback, useEffect, useRef, useState } from 'react';

/**
 * A paged report: the first page on mount, further pages appended as the reader scrolls.
 *
 * Written once because four modules need identical behaviour and the failure modes are
 * subtle enough that four copies would diverge. Each of the following is a bug this hook
 * exists to not have four times:
 *
 *  1. **A filter change must RESET, not append.** Appending page 1 of a new filter onto
 *     page 3 of the old one produces a list that matches nothing and looks plausible.
 *  2. **`filters` is compared by VALUE, not identity.** `setFilters([])` returns a new
 *     array every time, so an effect depending on the array itself re-fires forever —
 *     that exact loop was 13 requests where 1 was needed, and it is why `filterKey` is a
 *     JSON string.
 *  3. **A stale page must be discarded.** Scroll fast, change a filter mid-flight, and
 *     the old page 2 lands after the new page 1. A request id makes the late response
 *     identifiable and droppable.
 *  4. **`loadMore` must not fire while a page is in flight**, or a fast scroll queues
 *     five requests for the same offset.
 *
 * @param {string} endpoint  e.g. `/api/pending-approvals`
 * @param {Array}  filters   the current filter chips
 */
export default function usePagedReport(endpoint, filters) {
  const [data, setData] = useState(null);      // the latest envelope (columns, totals…)
  const [rows, setRows] = useState([]);        // ACCUMULATED across pages
  const [error, setError] = useState(null);
  const [filterError, setFilterError] = useState(null);
  const [loadingMore, setLoadingMore] = useState(false);

  const filterKey = JSON.stringify(filters ?? []);

  /*
   * `run` identifies the current query. Bumped on every reset, and a response whose id
   * does not match is dropped — see failure mode 3.
   */
  const run = useRef(0);
  const inFlight = useRef(false);

  const fetchPage = useCallback((offset, runId) => {
    if (inFlight.current) {
      return;
    }

    inFlight.current = true;

    if (offset > 0) {
      setLoadingMore(true);
    }

    const params = new URLSearchParams();

    if (offset > 0) {
      params.set('offset', String(offset));
    }

    if (filters?.length) {
      params.set('filters', JSON.stringify(filters));
    }

    const qs = params.toString();

    fetch(`${endpoint}${qs ? `?${qs}` : ''}`, { headers: { Accept: 'application/json' } })
      .then((r) => r.json().then((body) => ({ ok: r.ok, body })))
      .then(({ ok, body }) => {
        // Dropped: a filter changed while this was in flight.
        if (runId !== run.current) {
          return;
        }

        if (!ok || body.reason === 'bad_filter') {
          setFilterError(body.message ?? 'That filter was rejected.');

          return;
        }

        setData(body);
        setRows((current) => (offset === 0 ? body.rows : [...current, ...body.rows]));
      })
      .catch((e) => {
        if (runId === run.current) {
          setError(String(e.message ?? e));
        }
      })
      .finally(() => {
        inFlight.current = false;

        if (runId === run.current) {
          setLoadingMore(false);
        }
      });
  }, [endpoint, filterKey]);   // eslint-disable-line react-hooks/exhaustive-deps

  /** First page, and a full reset whenever the filters change. */
  const reload = useCallback(() => {
    run.current += 1;
    inFlight.current = false;
    setError(null);
    setFilterError(null);
    setRows([]);
    fetchPage(0, run.current);
  }, [fetchPage]);

  useEffect(() => { reload(); }, [reload]);

  const loadMore = useCallback(() => {
    const next = data?.next_offset;

    if (next === null || next === undefined || inFlight.current) {
      return;
    }

    fetchPage(next, run.current);
  }, [data?.next_offset, fetchPage]);

  return {
    data,
    rows,
    error,
    /*
     * Exposed because modules do their OWN side fetches — Bills loads `/options` and a
     * detail record, Payments loads `/options` — and those failures belong in the same
     * error line as the report's. Without this they would need a second error state and
     * the screen could show one failure while hiding another.
     */
    setError,
    filterError,
    setFilterError,
    loadingMore,
    hasMore: data?.next_offset !== null && data?.next_offset !== undefined,
    loadMore,
    reload,
  };
}
