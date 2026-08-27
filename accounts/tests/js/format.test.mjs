/**
 * Formatter checks — UI_HANDOFF.md §2.
 *
 * Run: node tests/js/format.test.mjs
 *
 * No framework on purpose: the handoff's verification loop is "assert on rendered
 * output — row counts, footer text, header labels — not just take a picture", and
 * these are the primitives that output is built from. Every case below is a rule
 * or a documented exception, not a made-up example.
 */
import {
  inr, rupees, ddMmmYyyy, rawTimestamp, showing, multi, sameStatus, isGoZeroTime,
} from '../../resources/js/lib/format.js';

let failed = 0;

const is = (got, want, label) => {
  const ok = got === want;
  if (!ok) failed++;
  console.log(`${ok ? 'ok  ' : 'FAIL'} ${label.padEnd(46)} got ${JSON.stringify(got)}${ok ? '' : ` want ${JSON.stringify(want)}`}`);
};

// Indian digit grouping: last three, then twos.
is(inr('1234567.5'), '12,34,567.50', 'indian grouping, 7 digits');
is(inr('100'), '100.00', 'under 1000 stays ungrouped');
is(inr('1000'), '1,000.00', 'exactly 1000');
is(inr('10000000'), '1,00,00,000.00', 'one crore');
is(inr('100000'), '1,00,000.00', 'one lakh');
is(inr('-4956'), '-4,956.00', 'negative');
is(inr(''), '', 'empty in, empty out');
is(inr('0'), '0.00', 'zero');

// EXCEPTION: Gross Amount prints at THREE decimals in the Payments split grid
// and All Pending Approvals (addendum §5).
is(inr('4272.41', 3), '4,272.410', 'three decimals for Gross Amount');
is(rupees('4956'), '₹ 4,956.00', 'rupee prefix with a space');

// Dates: dd-MMM-yyyy, never a native date input.
is(ddMmmYyyy('2026-07-16'), '16-Jul-2026', 'dd-MMM-yyyy');
is(ddMmmYyyy('2026-08-13T13:00:21Z'), '13-Aug-2026', 'from an ISO timestamp');
is(ddMmmYyyy(''), '', 'empty date');
is(ddMmmYyyy('not a date'), 'not a date', 'unparseable returned verbatim');

// EXCEPTION: Backend Expenses `date` is a raw string, printed as-is.
is(rawTimestamp('2026-08-13 13:00:21'), '2026-08-13 13:00:21', 'Backend Expenses raw timestamp');
is(isGoZeroTime('0001-01-01T00:00:00Z'), true, 'Go zero time detected');
is(isGoZeroTime('2026-08-13'), false, 'a real date is not zero time');

// EXCEPTION: Creator pages at 1000 and the total overflows the field.
is(showing(9, 9), 'Showing 9 of 9', 'small report');
is(showing(254, 254), 'Showing 254 of 254', 'mid report');
is(showing(1000, 1247), 'Showing 1000 of ###', 'over 1000 prints hashes');

// Multi-selects print one per line; the packed strings are dirty.
is(JSON.stringify(multi('a, b ,,c')), '["a","b","c"]', 'multi parse trims and drops empties');
is(JSON.stringify(multi('10681,')), '["10681"]', 'trailing comma dropped');
is(JSON.stringify(multi('\t8186')), '["8186"]', 'leading tab trimmed');
is(JSON.stringify(multi('')), '[]', 'empty multi');

// Both status spellings are live in the source.
is(sameStatus('Payment InProgress', 'Payment Inprogress'), true, 'both status spellings match');
is(sameStatus('Paid', 'paid'), true, 'Paid / paid / PAID are one state');
is(sameStatus('Paid', 'Draft'), false, 'genuinely different statuses differ');

console.log(failed ? `\n${failed} FAILED` : `\nall formatter checks passed`);
process.exit(failed ? 1 : 0);
