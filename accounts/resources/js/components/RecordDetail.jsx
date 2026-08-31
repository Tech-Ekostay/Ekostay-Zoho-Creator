import { multi as splitMulti } from '../lib/format';

/**
 * The record detail view — Creator's row-click destination, and now the ONE flow
 * every report in this app uses.
 *
 * THE FLOW, as Husain specified it 25-Aug-2026:
 *
 *     report grid  ->  click a row  ->  THIS (read-only detail)  ->  Edit  ->  form
 *
 * and it must be the same on every page. Before this, all four module families
 * disagreed: Settings opened the edit form straight from a row click, while Bills,
 * Payments and Vendor Master each selected the row and revealed a different action
 * strip. Two of those were mine. None showed the record's fields.
 *
 * WHY A DETAIL STEP MATTERS beyond consistency: clicking a row to land directly in
 * an editable form means every look at a record is one keystroke from changing it.
 * On a settled payment that is the §7.6 problem in miniature.
 *
 * MARKUP IS NOT INVENTED. `.zc-detailbar` and `.zc-detail` already existed in
 * zc.css, from a screenshot-verified pass — a two-column table, 260px label column,
 * with the handoff §3 correction that `vertical-align` MUST be top because a
 * middle-aligned label beside a 3,000px multi-select cell renders off-screen. This
 * component fills in the piece that was missing, it does not redesign it.
 *
 * WHAT IS STILL INFERRED: the field ORDER and which fields a Creator detail view
 * shows. No screenshot of a detail view exists for any report — the grids and forms
 * have been seen, this has not. So each caller passes report column order and the
 * screen says as much, rather than implying it is verified.
 */
export default function RecordDetail({
  /** e.g. "All Payments" — the report this record belongs to. */
  title,
  /** The record's own identity, e.g. "EKS/PY/21272". */
  subtitle,
  /** [{ label, value, multi?, hint? }] in report column order. */
  fields = [],
  /** Supply to enable Edit. Omit and Edit renders disabled with the reason. */
  onEdit,
  editDisabledReason,
  /** Per-record actions — Create Payment on Bills, Reverse on Payments. */
  extras,
  onClose,
  loading = false,
  /** Anything the reader needs to know about this record, e.g. merge state. */
  children,
  /**
   * Rendered AFTER the field table. `children` renders before it, which is right for
   * a lead-in and wrong for a subform: Creator shows `Approved By` as a field in the
   * list and the grid it flattens belongs below, not above.
   */
  footer,
  /** True where a screenshot of THIS report's detail panel settled the field order. */
  orderVerified = false,
}) {
  const editable = typeof onEdit === 'function';

  return (
    <div className="zc-overlay" role="dialog" aria-modal="true" aria-label={`${title} — record`}>
      <div className="zc-overlay-head">
        {title}
        {subtitle && <strong style={{ marginLeft: 10 }}>{subtitle}</strong>}
      </div>

      <div className="zc-detailbar">
        <button
          type="button"
          className="zc-btn zc-btn-primary"
          disabled={!editable}
          title={editable ? 'Edit this record' : (editDisabledReason ?? 'Editing is not available for this report')}
          onClick={() => onEdit?.()}
        >
          Edit
        </button>

        {extras}

        <span className="zc-appbar-spacer" />

        <button type="button" className="zc-btn" onClick={onClose}>Close</button>
      </div>

      <div className="zc-overlay-body">
        {loading && <div style={{ color: 'var(--ink3)' }}>Loading…</div>}

        {!loading && (
          <>
            {children}

            <table className="zc-detail">
              <tbody>
                {fields.map((field) => (
                  <tr key={field.label}>
                    <th scope="row">{field.label}</th>
                    <td>
                      {/*
                        Creator prints multi-select values ONE PER LINE, not
                        comma-joined (handoff §3) — which is what makes these rows
                        content-height. Splitting is a parse, not a split(','): the
                        packed strings carry leading spaces and at least one tab.
                      */}
                      {field.multi
                        ? splitMulti(field.value).map((line, i) => (
                            <span className="zc-multi-line" key={i}>{line}</span>
                          ))
                        : renderValue(field.value)}
                      {field.hint && <div className="zc-field-hint">{field.hint}</div>}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>

            {footer}

            {/*
              CORRECTED 28-Aug-2026. This used to read "No Creator screenshot of a record
              detail view exists for any report", which was true when written and is not
              now — Expenses, Pending Approvals, Backbend Payments, Bank and Expense
              Observations all arrived with detail screenshots, and several of them showed
              the detail order DIFFERS from the form's. So the claim is per-report, and a
              report that knows its order is verified says so.
            */}
            <p className="zc-field-hint" style={{ marginTop: 16 }}>
              {orderVerified
                ? <>Field order is <strong>verified</strong> from a screenshot of the live
                    record detail — which is not the same as the report&rsquo;s column order,
                    and is not derived from it.</>
                : <>Field order follows the report&rsquo;s own column order. No screenshot of
                    this report&rsquo;s record detail exists, so which fields it shows — and in
                    what order — is <strong>inferred</strong>. The values are real.</>}
            </p>
          </>
        )}
      </div>
    </div>
  );
}

/**
 * Blank values render as an em dash rather than an empty cell.
 *
 * §17's browser pass caught the opposite failure — booleans printing as the literal
 * text `false` on 135 rows — and an empty cell has the same problem in reverse: it
 * is indistinguishable from a cell that failed to draw. A dash says "no value here",
 * which on this data is frequently the real answer (`Status` is unset on all 254
 * villas; 22% of payments carry no channel).
 */
function renderValue(value) {
  if (value === null || value === undefined || value === '') {
    return <span style={{ color: 'var(--ink3)' }}>—</span>;
  }

  if (typeof value === 'boolean') {
    return value ? 'true' : 'false';
  }

  return value;
}
