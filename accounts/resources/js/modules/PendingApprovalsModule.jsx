import { useCallback, useEffect, useState } from 'react';
import ReportBar from '../components/ReportBar';
import ReportGrid from '../components/ReportGrid';
import FilterBar from '../components/FilterBar';
import RecordDetail from '../components/RecordDetail';
import { ddMmmYyyy, rupees } from '../lib/format';

/**
 * All Pending Approvals — 24 columns, and the first screen here that MOVES money.
 *
 * COLUMN ORDER IS VERIFIED from seven screenshots (27-Aug-2026). The three action
 * buttons sit MID-TABLE, between `Payment Date` and `Payable Amount` — not at the left
 * edge where a designer would put them. Reproduced where the live report has them.
 *
 * FOUR THINGS THE SCREENSHOTS SETTLED:
 *
 *  1. **`Gross Amount` prints at THREE decimals** (₹58,614.140) while `Payable Amount`
 *     takes two, on the same row. The server sends the precision per column so this is
 *     not a magic number in the view.
 *
 *     CORRECTED 28-Aug-2026: this said "every other money column in this app is two".
 *     The Deleted Payments Report does the same thing — `₹ 18,000.000` beside
 *     `₹ 18,000.00` — so three decimals is a property of `Gross Amount` wherever it
 *     appears, not a quirk of this one report.
 *  2. **`Pay` is solid on `Approved` rows and pale on `Sent for Approval`** — five pale
 *     and four solid on the live screenshots. Enablement is per row, from the server's
 *     `can`, never computed here from a status string.
 *  3. **`Payment Status` is a solid filled cell**, like `Status` on Expenses.
 *  4. **`Approved By` shows ONE name** because Creator flattens the subform in its own
 *     report (§12 flattening in Creator's UI). Reproduced in the grid — and the detail
 *     panel shows the real grid underneath, so the report matches without the record
 *     lying.
 *
 * THE BUTTONS ARE REAL. Unlike `Update Expense`, these three are wired, because
 * `DecideApproval` and `MarkPaymentPaid` are transcribed from the DS and verified end
 * to end. What is NOT real is authentication: there is no session, so the approver's
 * name is typed. The page says so rather than implying a control.
 *
 * ONE DELIBERATE DIVERGENCE FROM THE SCREENSHOTS. On the live report `Approve` and
 * `Reject` render PALE ON EVERY ROW — presumably because the signed-in user is not the
 * named approver, which is the reading `MarkPaymentPaid`'s docblock already records.
 * Here they are live on every OPEN row, because with no session there is no user to
 * compare against, and a button that is always pale cannot be tested or used. So the
 * enablement rule is `is this approval still open?` rather than `is this me?`.
 *
 * That is a divergence in the direction of MORE permissive, which is the wrong
 * direction, and it resolves the moment §3.3's matrix is wired to a gate: the rule
 * becomes `open AND I am named on it`, and most rows go pale exactly as they do live.
 */
const ACTIONS = new Set(['Approve', 'Reject', 'Pay']);
const STAMP = new Set(['Added Time']);
const DATE = new Set(['Payment Date']);

/** A tiny prompt for the fields there is no session to supply. */
function ActionDialog({ action, row, onCancel, onConfirm }) {
  const [approver, setApprover] = useState('');
  const [reason, setReason] = useState('');
  const [busy, setBusy] = useState(false);

  const needsApprover = action === 'approve' || action === 'reject';
  const needsReason = action === 'reject';
  const ready = (!needsApprover || approver.trim() !== '')
    && (!needsReason || reason.trim().length >= 3);

  return (
    <div className="zc-overlay" role="dialog" aria-modal="true">
      <div className="zc-overlay-head">
        {action === 'approve' ? 'Approve' : action === 'reject' ? 'Reject' : 'Mark paid'}
        {' '}{row['Payment No'] || `#${row.id}`}
      </div>

      <div className="zc-detailbar">
        <button
          type="button"
          className="zc-btn zc-btn-primary"
          disabled={!ready || busy}
          onClick={() => {
            setBusy(true);
            onConfirm({ approver: approver.trim(), reason: reason.trim() })
              .finally(() => setBusy(false));
          }}
        >
          {busy ? 'Working…' : action === 'pay' ? 'Mark paid' : action === 'approve' ? 'Approve' : 'Reject'}
        </button>
        <span className="zc-appbar-spacer" />
        <button type="button" className="zc-btn" onClick={onCancel} disabled={busy}>Cancel</button>
      </div>

      <div className="zc-overlay-body">
        <div style={{ display: 'grid', gap: 14, maxWidth: 620 }}>
          {needsApprover && (
            <label style={{ display: 'grid', gap: 4 }}>
              <span style={{ fontSize: 12, color: 'var(--ink2)' }}>
                Approver <span style={{ color: 'var(--bad)' }}>*</span>
              </span>
              <input
                className="zc-input"
                value={approver}
                onChange={(e) => setApprover(e.target.value)}
                placeholder={row['Approved By'] || 'Name as it appears on the record'}
                autoFocus
              />
              <span className="zc-field-hint">
                Typed, not signed in. There is no session yet, so the server checks this name
                against the record&rsquo;s own approver rows — it cannot check who is asking.
              </span>
            </label>
          )}

          {needsReason && (
            <label style={{ display: 'grid', gap: 4 }}>
              <span style={{ fontSize: 12, color: 'var(--ink2)' }}>
                Reason <span style={{ color: 'var(--bad)' }}>*</span>
              </span>
              <textarea
                className="zc-input"
                rows={3}
                value={reason}
                onChange={(e) => setReason(e.target.value)}
              />
              <span className="zc-field-hint">
                Creator does not ask for one. This does: a rejected payment with no recorded
                explanation is unanswerable a month later.
              </span>
            </label>
          )}

          {action === 'pay' && (
            <p className="zc-field-hint" style={{ margin: 0 }}>
              This records that a payment was made. <strong>It does not make one</strong> — there
              is no bank integration here. Payment date defaults to today, and it is kept
              separate from the status because reports bucket by it.
            </p>
          )}
        </div>
      </div>
    </div>
  );
}

export default function PendingApprovalsModule() {
  const [data, setData] = useState(null);
  const [error, setError] = useState(null);
  const [filters, setFilters] = useState([]);
  const [filterError, setFilterError] = useState(null);
  const [selected, setSelected] = useState(null);
  const [detail, setDetail] = useState(null);
  const [notice, setNotice] = useState(null);
  const [dialog, setDialog] = useState(null);

  const filterKey = JSON.stringify(filters);

  const load = useCallback(() => {
    setError(null);
    fetch(`/api/pending-approvals${filters.length ? `?filters=${encodeURIComponent(JSON.stringify(filters))}` : ''}`)
      .then((r) => (r.ok ? r.json() : Promise.reject(new Error(`HTTP ${r.status}`))))
      .then((body) => {
        if (body.reason === 'bad_filter') { setFilterError(body.message); return; }
        setData(body);
      })
      .catch((e) => setError(String(e.message ?? e)));
  }, [filterKey]);   // eslint-disable-line react-hooks/exhaustive-deps

  useEffect(() => { load(); }, [load]);

  useEffect(() => {
    if (selected === null) { setDetail(null); return; }

    setDetail(null);
    fetch(`/api/pending-approvals/${selected}`, { headers: { Accept: 'application/json' } })
      .then((r) => (r.ok ? r.json() : Promise.reject(new Error(`HTTP ${r.status}`))))
      .then(setDetail)
      .catch(() => setDetail(null));
  }, [selected]);

  /** Post one of the three transitions and reload, since a decision moves other rows. */
  const act = (action, row, body) =>
    fetch(`/api/pending-approvals/${row.id}/${action}`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify(body),
    })
      .then((r) => r.json().then((b) => ({ ok: r.ok, b })))
      .then(({ ok, b }) => {
        setNotice(b.message ?? (ok ? 'Done.' : 'Refused.'));
        setDialog(null);
        if (ok) { load(); if (selected === row.id) setSelected(null); }
      })
      .catch((e) => setNotice(String(e.message ?? e)));

  const hints = data?.column_hints ?? {};

  const columns = (data?.columns ?? []).map((label) => ({
    key: label,
    label,
    align: hints[label]?.decimals ? 'right' : undefined,
    fill: hints[label]?.filled === true,
    width: label === 'Message ID' ? 260
      : label === 'Next Level Approval Required?' ? 190
        : STAMP.has(label) ? 150 : undefined,
    render: (value, row) => {
      if (ACTIONS.has(label)) {
        const key = label.toLowerCase();
        const live = row.can?.[key] === true;

        return (
          <button
            type="button"
            className={`zc-btn${live ? ' zc-btn-primary' : ''}`}
            disabled={!live}
            title={live
              ? undefined
              : key === 'pay'
                ? 'Pay is live only once the record is Approved — pale here, as on the live report.'
                : 'This approval is already settled, so it cannot be approved or rejected again.'}
            onClick={(e) => { e.stopPropagation(); setDialog({ action: key, row }); }}
          >
            {label}
          </button>
        );
      }

      /*
         `₹ ` prefixed, and the precision comes from the server. Addendum §5 recorded
         `₹58,614.140` on this report, so the symbol is shown and `Gross Amount` keeps
         its three decimals while `Payable Amount` takes two. First render used `inr()`,
         which groups the digits and omits the symbol — caught by looking at the page.
      */
      const decimals = hints[label]?.decimals;
      if (decimals) return rupees(value, decimals);
      if (DATE.has(label)) return ddMmmYyyy(value);
      if (STAMP.has(label)) {
        if (!value) return '';
        const [d, t] = String(value).split(' ');
        return `${ddMmmYyyy(d)}${t ? ' ' + t : ''}`;
      }

      return value ?? '';
    },
  }));

  return (
    <>
      <ReportBar
        title={data?.title ?? 'All Pending Approvals'}
        required
        addDisabledReason={
          'An approval is not created by hand from this screen — submitting a payment creates '
          + 'one, and `Preferred Approver` re-creates one for a payment that is stuck. '
          + 'Adding a bare approval with no payment behind it is a data fault, not a workflow.'
        }
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

      {notice && (
        <div style={{ padding: '6px 14px', fontSize: 12, color: 'var(--ink2)' }}>{notice}</div>
      )}

      {/*
        Said on screen. Three buttons that move money look like a control, and this is
        not one yet — §3.3's matrix is extracted and tested and not wired to a gate.
      */}
      {data?.unauthenticated && (
        <p className="zc-field-hint" style={{ margin: '6px 14px' }}>
          <strong>These buttons work and there is no authentication behind them.</strong> The
          server checks the approver you name is on the record; it cannot check that the request
          came from that person. Fine locally, a blocker before anyone else sees it.
        </p>
      )}

      {error && <div style={{ padding: 14, color: 'var(--bad)' }}>Failed to load: {error}</div>}
      {!data && !error && <div style={{ padding: 14, color: 'var(--ink3)' }}>Loading…</div>}

      {data && data.rows.length === 0 && (
        <div style={{ padding: 20, color: 'var(--ink3)' }}>
          <p style={{ marginTop: 0 }}>
            {filters.length > 0 ? 'No approval matches these filters.' : 'Nothing awaiting approval.'}
          </p>
          {filters.length === 0 && (
            <p style={{ fontSize: 12 }}>
              Seed a queue with <code>php artisan db:seed --class=PendingApprovalFixtureSeeder</code>.
              It attaches approvals to real imported payments and is deliberately outside
              <code> DatabaseSeeder</code>.
            </p>
          )}
        </div>
      )}

      {data && data.rows.length > 0 && (
        <ReportGrid
          columns={columns}
          rows={data.rows}
          total={filters.length > 0 ? data.matched : data.total}
          selectedId={selected}
          onSelect={(row) => setSelected(row.id === selected ? null : row.id)}
        />
      )}

      {detail && (
        <RecordDetail
          title={data?.title ?? 'All Pending Approvals'}
          subtitle={detail.row['Payment No'] || ''}
          fields={Object.entries(detail.detail).map(([label, value]) => ({ label, value }))}
          editDisabledReason={
            'The live form edits Approvers, Preferred Approver, Approval Type and the '
            + 'Approved By grid. Approving through those fields by hand would bypass the '
            + 'level chain; use the Approve and Reject buttons, which walk it.'
          }
          orderVerified
          onClose={() => setSelected(null)}
          footer={<>
          {/*
            The subform the report flattens. Shown as the grid it actually is, because
            `Approval Type = All` is only expressible one row per approver — and on a
            single-approver record the flattened name and the grid look identical, which
            is exactly when a reader would believe the report.
          */}
          <section style={{ marginTop: 14 }}>
            <h3 style={{ fontSize: 13, margin: '0 0 6px' }}>Approved By</h3>
            <p className="zc-field-hint" style={{ margin: '0 0 8px' }}>
              The report column shows one name because Creator flattens this grid. This is what
              it flattens from — one row per approver per level.
            </p>
            <table className="zc-detail">
              <thead>
                <tr><th>Approver</th><th>Approval Level</th><th>Approved</th></tr>
              </thead>
              <tbody>
                {detail.approved_by_rows.length === 0 && (
                  <tr><td colSpan={3} style={{ color: 'var(--ink3)' }}>No rows.</td></tr>
                )}
                {detail.approved_by_rows.map((r, i) => (
                  <tr key={i}>
                    <td>{r.Approver}</td>
                    <td>{r['Approval Level']}</td>
                    <td>{r.Approved ? 'Yes' : 'No'}</td>
                  </tr>
                ))}
              </tbody>
            </table>

            {detail.chain?.length > 0 && (
              <p className="zc-field-hint" style={{ marginTop: 10 }}>
                Chain: <strong>{detail.chain.join(' → ')}</strong>. Frozen when the payment was
                submitted — Creator recomputes it from the rule on every approval, so editing a
                rule mid-flight silently re-decides an approval already under way. Logged
                deviation D8.
              </p>
            )}
          </section>
          </>}
        />
      )}

      {dialog && (
        <ActionDialog
          action={dialog.action}
          row={dialog.row}
          onCancel={() => setDialog(null)}
          onConfirm={({ approver, reason }) => act(
            dialog.action,
            dialog.row,
            dialog.action === 'pay' ? {}
              : dialog.action === 'reject' ? { approver, reason }
                : { approver },
          )}
        />
      )}
    </>
  );
}
