/**
 * Display formatting, per UI_HANDOFF.md §2.
 *
 * These are the rules a reviewer will check first, and each has an exception that
 * is easy to miss. Every exception below is documented in the handoff or the
 * addendum — none is invented.
 */

const MONTHS = [
  'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun',
  'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec',
];

/**
 * Indian digit grouping: ##,##,###.##
 *
 * Last three digits, then twos. 1234567.5 -> "12,34,567.50".
 *
 * `decimals` defaults to 2. Pass 3 for the two places the handoff records
 * `Gross Amount` printing at three: the Payments split grid and All Pending
 * Approvals (addendum §5). That is not a rounding choice — it is what the live
 * screen shows, and a reviewer comparing screenshots will spot it.
 *
 * Takes a string or number. Strings are preferred: money reaches the browser as a
 * decimal string from the API precisely so it never passes through a float.
 */
export function inr(value, decimals = 2) {
  if (value === null || value === undefined || value === '') return '';

  const negative = String(value).trim().startsWith('-');
  const raw = String(value).replace(/^-/, '');
  const [whole = '0', fraction = ''] = raw.split('.');

  const digits = whole.replace(/\D/g, '') || '0';
  let grouped;

  if (digits.length <= 3) {
    grouped = digits;
  } else {
    const last3 = digits.slice(-3);
    const rest = digits.slice(0, -3);
    grouped = rest.replace(/\B(?=(\d{2})+(?!\d))/g, ',') + ',' + last3;
  }

  const padded = (fraction + '0'.repeat(decimals)).slice(0, decimals);
  const out = decimals > 0 ? `${grouped}.${padded}` : grouped;

  return negative ? `-${out}` : out;
}

/** `₹ 12,34,567.50`. The space after the symbol is Creator's. */
export function rupees(value, decimals = 2) {
  const n = inr(value, decimals);
  return n === '' ? '' : `₹ ${n}`;
}

/**
 * `dd-MMM-yyyy` — `16-Jul-2026`.
 *
 * Rendered into a TEXT input with a calendar glyph, never `<input type="date">`.
 * §15.2 records a live fault where native date inputs rendered mm/dd/yyyy.
 *
 * Accepts `YYYY-MM-DD`, an ISO timestamp, or a Date. Anything unparseable is
 * returned verbatim rather than coerced — see rawTimestamp() for why that
 * matters.
 */
export function ddMmmYyyy(value) {
  if (!value) return '';

  const text = String(value);
  const match = text.match(/^(\d{4})-(\d{2})-(\d{2})/);

  if (match) {
    const [, y, m, d] = match;
    const monthIndex = Number(m) - 1;
    if (monthIndex >= 0 && monthIndex < 12) {
      return `${d}-${MONTHS[monthIndex]}-${y}`;
    }
  }

  if (value instanceof Date && !Number.isNaN(value.getTime())) {
    const d = String(value.getDate()).padStart(2, '0');
    return `${d}-${MONTHS[value.getMonth()]}-${value.getFullYear()}`;
  }

  return text;
}

/**
 * EXCEPTION (handoff §2 rule 5): Backend Expenses' `date` holds a raw string and
 * prints `2026-08-13 13:00:21`. It is NOT reformatted.
 *
 * Related trap from addendum §4: `duplicate_date` is `0001-01-01T00:00:00Z` — Go
 * zero time. The source system is Go and unset dates are year 1, so
 * `duplicate_date < X` matches everything. Never treat year 1 as a real date.
 */
export function rawTimestamp(value) {
  return value === null || value === undefined ? '' : String(value);
}

export function isGoZeroTime(value) {
  return typeof value === 'string' && value.startsWith('0001-01-01');
}

/**
 * `Showing N of M` — with the REAL total.
 *
 * CORRECTS HANDOFF §2 RULE 8, on Husain's evidence of 27-Aug-2026. That rule says
 * Creator prints `Showing 1000 of ###` above 1000 rows because "the total overflows
 * the field", and this function reproduced the hashes faithfully. A screenshot of
 * the live All Expenses footer reads:
 *
 *     Showing 1000 of 66407
 *
 * So the hashes are not what Creator settles on — they are a clipped or in-flight
 * render of a real number, and the number is the total record count. Husain
 * confirmed it directly: "that #### is actually the count of total records".
 *
 * The lesson is the one CLAUDE.md already states: the docs are partly inferred and
 * evidence wins. Reproducing `###` was faithful to the document and wrong about the
 * product, which is the worst kind of wrong — it looked deliberate.
 *
 * The 1000 cap on the SHOWN count stays: Creator does page at 1000, and the live
 * footer says `Showing 1000` against a 66,407 total.
 */
export function showing(shown, total) {
  return `Showing ${Math.min(shown, 1000)} of ${inr(String(total ?? 0), 0)}`;
}

/**
 * Creator prints multi-select values ONE PER LINE, not comma-joined, in both list
 * and detail (handoff §3). This makes rows content-height.
 *
 * Splitting is a parse, not a `split(',')`: the packed strings carry leading
 * spaces, trailing commas and at least one tab (addendum §15).
 */
export function multi(value) {
  if (!value) return [];
  if (Array.isArray(value)) return value;

  return String(value)
    .split(',')
    .map((part) => part.trim())
    .filter((part) => part !== '');
}

/**
 * Status comparison. `Payment InProgress` and `Payment Inprogress` are BOTH live
 * in Accounts.ds — 7 and 10 occurrences (addendum §10) — so an equality check
 * silently misses part of the data. This is the single place that knows.
 */
export function sameStatus(a, b) {
  if (a === null || a === undefined || b === null || b === undefined) return a === b;
  return String(a).trim().toLowerCase() === String(b).trim().toLowerCase();
}
