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
 */
export default function ReportGrid({ columns, rows, total, selectedId, onSelect, rowKey = 'id' }) {
  const count = total ?? rows.length;

  return (
    <>
      <div className="zc-gridwrap">
        <table className="zc-grid">
          <thead>
            <tr>
              {columns.map((column) => (
                <th key={column.key} style={column.width ? { minWidth: column.width } : undefined}>
                  {column.label}
                </th>
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
                {columns.map((column) => {
                  const value = row[column.key];
                  const classes = [
                    column.align === 'right' ? 'zc-money' : '',
                    column.align === 'num' ? 'zc-num' : '',
                    column.multi ? 'zc-multi' : '',
                    column.fill ? 'zc-fill' : '',
                  ].filter(Boolean).join(' ');

                  return (
                    <td key={column.key} className={classes || undefined}>
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
      </div>
      <div className="zc-footer">{showing(rows.length, count)}</div>
    </>
  );
}
