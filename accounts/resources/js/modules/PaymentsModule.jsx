import { useCallback, useEffect, useMemo, useState } from 'react';
import ReportBar from '../components/ReportBar';
import ReportGrid from '../components/ReportGrid';
import RecordDetail from '../components/RecordDetail';
import FilterBar from '../components/FilterBar';
import PaymentForm from './PaymentForm';
import { ddMmmYyyy, inr, rupees, sameStatus } from '../lib/format';
import usePagedReport from '../lib/usePagedReport';

/**
 * All Payments — §7, and the first screen in this app with a write path behind it.
 *
 * COLUMN ORDER IS PROVISIONAL, and that is worth saying on the screen rather than
 * only in a docblock. handoff §6 item 4: "All Payments column set — the Payments
 * module's column order is inferred, not seen. Recoverable, Bank Reconciliation
 * and Withdrawal Ma... exist and are not in it, and there is a per-row action
 * button." So the order below matches the API, which matches §7.1 plus the
 * reference JSX — and a screenshot would settle it in a minute.
 *
 * GROSS AMOUNT PRINTS AT THREE DECIMALS in the split grid. Not a rounding choice:
 * addendum §5 records it on the live screen, and a reviewer comparing screenshots
 * will look for it. inr(value, 3) is what does that.
 *
 * NO DELETE CONTROL EXISTS HERE, deliberately. Creator's More menu carries
 * `Delete Paid Payment` one click from a settled payment and it destroyed 17 real
 * payments. §7.6 replaces it with a reversal: a linked negative entry, a required
 * reason, the original and its number intact.
 */

/** Payment_Status values that mean money has actually moved. */
const SETTLED = ['paid'];

export default function PaymentsModule() {
  const [selected, setSelected] = useState(null);

  /*
   * THE DIRECT ADD PATH. Payments' `+` used to navigate to Bills, on the reading
   * that §7.2's Create_Payment is the only way a payment comes into being. It is
   * not — a payment can be entered directly (Husain, 25-Aug-2026). `options` gates
   * the button because a form with empty pickers is worse than a disabled one.
   */
  /*
   * FILTERS REPLACE THE FREE-TEXT SEARCH, and the reason is not cosmetic.
   *
   * The old box filtered the rows already in the browser — the first 1,000 of
   * 52,638. A payment at row 5,000 came back as "no match", which reads as "no such
   * payment" rather than "not on the page you have". Filters are a server round-trip
   * against every row, and they are column + operator + value as the live All
   * Payments screenshot shows.
   */
  const [filters, setFilters] = useState([]);

  const [adding, setAdding] = useState(false);
  const [options, setOptions] = useState(null);
  const [detail, setDetail] = useState(null);
  const [reversing, setReversing] = useState(false);
  const [editing, setEditing] = useState(false);
  const [reason, setReason] = useState('');
  const [notice, setNotice] = useState(null);

  useEffect(() => {
    fetch('/api/payments/options')
      .then((r) => (r.ok ? r.json() : Promise.reject(new Error(`HTTP ${r.status}`))))
      .then(setOptions)
      .catch(() => setOptions(null));
  }, []);

  /*
   * 1,000 rows a page, appended as the reader scrolls. 52,639 payments, so the old
   * `limit(1000)` left 51,639 unreachable — and a search for a payment at row 5,000
   * came back empty in a way indistinguishable from "no such payment".
   *
   * A rejected filter still surfaces: `filterError` comes from the hook, which shows the
   * server's message rather than leaving an unfiltered grid reading as a filtered one.
   */
  const {
    data, rows, error, setError, filterError, setFilterError,
    loadingMore, hasMore, loadMore, reload: load,
  } = usePagedReport('/api/payments', filters);

  /** Pull the detail — the split grid lives there, not on the list row. */
  useEffect(() => {
    if (selected === null) {
      setDetail(null);
      return;
    }

    setDetail(null);
    fetch(`/api/payments/${selected}`)
      .then((r) => (r.ok ? r.json() : Promise.reject(new Error(r.status))))
      .then(setDetail)
      .catch((e) => setError(String(e)));
  }, [selected]);

  const columns = (data?.columns ?? []).map((label) => ({
    key: label,
    label,
    align: label.endsWith('Amount') ? 'right' : undefined,
    // Status columns render as solid filled cells, per ReportGrid's `fill`
    // contract — All Payments is one of the reports with conditional formatting.
    fill: label === 'Status' || label === 'Payment Status',
    render: (value) => {
      if (label.endsWith('Amount')) return inr(value);
      if (label.endsWith('Date')) return ddMmmYyyy(value);
      return value ?? '';
    },
  }));

  const payment = detail?.payment;
  const settled = payment && SETTLED.some((s) => sameStatus(s, payment.payment_status));
  const alreadyReversed = Boolean(payment?.reversed_by_payment_no);

  const submitReversal = () => {
    setNotice(null);

    fetch(`/api/payments/${payment.id}/reverse`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({ reason }),
    })
      .then(async (response) => {
        const body = await response.json();
        if (!response.ok) throw new Error(body.message ?? `HTTP ${response.status}`);
        return body;
      })
      .then((body) => {
        setNotice({
          kind: 'ok',
          text: `Reversed as ${body.reversal_payment_no}. ${payment['Payment No']} keeps its number and its rows.`,
        });
        setReversing(false);
        setReason('');
        load();
        setSelected(body.reversal_id);
      })
      .catch((e) => setNotice({ kind: 'bad', text: String(e.message ?? e) }));
  };

  /*
   * NO CLIENT-SIDE FILTERING, AND NO 1,000-ROW HORIZON EITHER.
   *
   * This once lowercase-matched the loaded rows, which quietly meant "search the first
   * 1,000 of 52,639" — a payment at row 5,000 read as absent rather than off-page. The
   * server does the filtering. And since 28-Aug-2026 it also PAGES: `rows` comes from
   * `usePagedReport` and accumulates 1,000 at a time as the reader scrolls, so row 5,000
   * is now genuinely reachable rather than merely correctly counted.
   */

  return (
    <>
      <ReportBar
        title="All Payments"
        searchDisabledReason={
          'Search is now a FILTER — column, operator and value, as the live report shows. '
          + 'It runs on the server so it covers all 52,638 rows, not the 1,000 loaded.'
        }
        /*
         * `+` sends you to Bills rather than opening a form. A payment is NOT typed
         * from scratch: §7.2's Create_Payment is a per-record action ON THE BILLS
         * REPORT, and it forces COA, carries eleven fields across and clones the
         * split grid. A blank Payment form would be a different thing wearing the
         * same name. The button is live and honest about where the action lives.
         */
        onAdd={options ? () => setAdding(true) : undefined}
        addDisabledReason="Loading the vendor, COA and villa pickers…"
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

      {error && <div style={{ padding: 14, color: 'var(--bad)' }}>Failed to load: {error}</div>}
      {!data && !error && <div style={{ padding: 14, color: 'var(--ink3)' }}>Loading…</div>}

      {data && rows.length === 0 && filters.length > 0 && (
        <div style={{ padding: 20, color: 'var(--ink3)' }}>
          <p style={{ marginTop: 0 }}>No payment matches these filters.</p>
          <p style={{ fontSize: 12 }}>
            {data.total} payments exist. This searched all of them, not just a loaded page —
            so a nil result here means nil, not off-screen.
          </p>
        </div>
      )}

      {data && rows.length === 0 && filters.length === 0 && (
        <div style={{ padding: 20, color: 'var(--ink3)' }}>
          <p style={{ marginTop: 0 }}>No payments yet.</p>
          <p style={{ fontSize: 12 }}>
            A payment is created from a bill by the <strong>Create_Payment</strong> action
            (§7.2). Seed the fixture bill with
            {' '}<code>php artisan db:seed --class=TestBillSeeder</code>{' '}
            then POST its id to <code>/api/payments</code>.
          </p>
        </div>
      )}

      {data && rows.length > 0 && (
        <ReportGrid
          columns={columns}
          rows={rows}
          total={filters.length > 0 ? data.matched : data.total}
          selectedId={selected}
          onSelect={(row) => setSelected(row.id)}
          onLoadMore={loadMore}
          loadingMore={loadingMore}
          hasMore={hasMore}
        />
      )}

      {/*
        THE SHARED FLOW: grid -> click a row -> this detail overlay -> Edit -> form.
        Husain settled it on 25-Aug-2026 and it is now the same on every report.
        This panel used to be a bottom-anchored strip, which meant Payments,
        Bills, Vendor Master and Settings each behaved differently.

        EDIT IS DELIBERATELY UNAVAILABLE HERE. There is no payment edit path in the
        app and adding one is not a UI decision: §7.6 turns on a payment's number,
        amounts and legs being immutable once issued, which is why a correction is a
        reversing entry rather than an edit. The button says so instead of hiding.
      */}
      {editing && options && detail && (
        <PaymentForm
          options={options}
          payment={detail.form}
          onClose={() => setEditing(false)}
          onSaved={() => {
            setEditing(false);
            setNotice({ kind: 'ok', text: `Updated payment ${payment['Payment No']}.` });
            load();
          }}
        />
      )}

      {adding && options && (
        <PaymentForm
          options={options}
          onClose={() => setAdding(false)}
          onSaved={(body) => {
            setAdding(false);
            setNotice({ kind: 'ok', text: `Created payment ${body.payment_no} — ${body.split_legs} split legs.` });
            load();
          }}
        />
      )}

      {/*
        * `!editing` IS LOad-BEARING. The form above and this detail are both
        * `position: fixed` at `z-index: 50` and cover the same rect, and the form
        * renders FIRST in the DOM. Equal z-index means later-in-DOM paints on top,
        * so leaving the detail mounted buries the form completely: Edit opens it,
        * nothing appears to happen, and the click reads as a dead button. Bills
        * avoids this by closing the detail inside `onEdit`; Payments needs the
        * guard here because `payment` is derived from the row, not from a
        * `viewing` flag that `onEdit` could clear.
        */}
      {payment && !editing && (
        <RecordDetail
          title={data?.title ?? 'All Payments'}
          subtitle={payment['Payment No']}
          fields={(data?.columns ?? []).map((label) => ({
            label,
            // Same rules the grid uses, so a value cannot read differently in
            // the detail view than in the row it was clicked from.
            value: label.endsWith('Amount')
              ? inr(payment[label])
              : (label.endsWith('Date') ? ddMmmYyyy(payment[label]) : payment[label]),
          }))}
          /*
           * EDIT IS ENABLED, and the reason it was not is worth keeping.
           *
           * This carried `editDisabledReason`: "A payment is not editable. §7.6 makes
           * its number, amounts and split legs immutable once issued." That was a
           * MISREADING. §7.6 forbids DELETING a settled payment and REISSUING a
           * number; it says nothing about editing, and All Payments' `Update Payment`
           * custom action in the DS carries no `condition` at all — Creator lets any
           * payment be opened and saved.
           *
           * What §7.6 does protect is now enforced field by field instead of by
           * refusing the whole screen: the number never moves, and `PaymentFieldState`
           * locks whatever the COA and status lock. The one case still refused is D4's
           * reversal pair, which `show()` reports as `is_editable: false`.
           */
          onEdit={detail?.is_editable === false ? undefined : () => setEditing(true)}
          editDisabledReason={
            'This payment is part of a reversal pair. Editing either half would leave '
            + 'the two entries no longer netting to zero, which is what the reversal '
            + 'records. Creator would allow this; we do not (D4).'
          }
          onClose={() => setSelected(null)}
          extras={(
            <>
              {payment.is_reversal && (
                <span style={{ fontSize: 12, color: 'var(--bad)' }}>
                  reversing entry against {payment.reverses_payment_no}
                </span>
              )}
              {alreadyReversed && (
                <span style={{ fontSize: 12, color: 'var(--bad)' }}>
                  reversed by {payment.reversed_by_payment_no}
                </span>
              )}
              {/*
                The reversal control replaces `Delete Paid Payment`. It appears only
                on a settled, not-yet-reversed forward payment — the three conditions
                ReversePayment enforces server-side. The server is the authority; this
                only avoids offering an action that would be refused.
              */}
              {settled && !alreadyReversed && !payment.is_reversal && (
                <button type="button" className="zc-btn" onClick={() => setReversing(true)}>
                  Reverse Payment
                </button>
              )}
            </>
          )}
        >

          {notice && (
            <p style={{ fontSize: 12, color: notice.kind === 'ok' ? 'var(--ink2)' : 'var(--bad)' }}>
              {notice.text}
            </p>
          )}

          {reversing && (
            <div style={{ marginBottom: 10, padding: 10, border: '1px solid var(--line2)', background: 'var(--pinkl)' }}>
              <p style={{ margin: '0 0 6px', fontSize: 12 }}>
                A reversal creates a <strong>new</strong> payment with negative amounts linked to
                this one. {payment['Payment No']} keeps its number, its amounts and its split
                legs — nothing is deleted (§7.6). A reason is required.
              </p>
              <input
                className="zc-input"
                style={{ width: '100%', marginBottom: 6 }}
                placeholder="Why is this being reversed?"
                value={reason}
                onChange={(e) => setReason(e.target.value)}
              />
              <button
                type="button"
                className="zc-btn zc-btn-primary"
                disabled={reason.trim().length < 3}
                onClick={submitReversal}
              >
                Confirm reversal
              </button>
              <button
                type="button"
                className="zc-btn"
                style={{ marginLeft: 6 }}
                onClick={() => { setReversing(false); setReason(''); setNotice(null); }}
              >
                Cancel
              </button>
            </div>
          )}

          {payment.reversal_reason && (
            <p style={{ fontSize: 12, color: 'var(--ink3)' }}>
              Reason: {payment.reversal_reason}
            </p>
          )}

          <div className="zc-section">Split Payments</div>
          {/*
            §5.2: an Expenses_Bills row IS one of these legs, so every downstream
            villa-month-category figure resolves here. Gross Amount at THREE
            decimals — addendum §5.
          */}
          <table className="zc-grid">
            <thead>
              <tr>
                {['Villa Name', 'Item Category', 'Billing Cycle', 'Gross Amount', 'TDS Amount', 'GST Amount', 'Amount']
                  .map((h) => <th key={h}>{h}</th>)}
              </tr>
            </thead>
            <tbody>
              {(detail.split_payments ?? []).map((leg, i) => (
                <tr key={i}>
                  <td>{leg['Villa Name']}</td>
                  <td>{leg['Item Category']}</td>
                  <td>{leg['Billing Cycle']}</td>
                  <td className="zc-money">{inr(leg['Gross Amount'], 3)}</td>
                  <td className="zc-money">{inr(leg['TDS Amount'])}</td>
                  <td className="zc-money">{inr(leg['GST Amount'])}</td>
                  <td className="zc-money">{inr(leg['Amount'])}</td>
                </tr>
              ))}
            </tbody>
          </table>

          <p style={{ fontSize: 12, color: 'var(--ink3)', marginTop: 8 }}>
            Payable {rupees(payment['Payable Amount'])} · COA {payment.coa}
          </p>
        </RecordDetail>
      )}
    </>
  );
}
