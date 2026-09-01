import { useEffect, useState } from 'react';
import { createRoot } from 'react-dom/client';
import Rail from './components/Rail';
import ReportBar from './components/ReportBar';
import BillsModule from './modules/BillsModule';
import PaymentsModule from './modules/PaymentsModule';
import SettingsModule from './modules/SettingsModule';
import ExpensesModule from './modules/ExpensesModule';
import FnbModule from './modules/FnbModule';
import PendingApprovalsModule from './modules/PendingApprovalsModule';
import VendorMasterModule from './modules/VendorMasterModule';
import { NAV } from './nav';

/**
 * The app shell. Layout per handoff §3: a 104px rail beside a flex column of
 * appbar (42px), reportbar, optional search row, scrolling grid, 28px footer.
 *
 * EVERY PAGE GETS A REPORTBAR, including the unbuilt ones. The chrome has to be
 * the same everywhere or the app reads as half-finished — but a control is only
 * ENABLED where something real sits behind it. `ReportBar` enforces that: pass a
 * handler and the button works, omit it and the button renders disabled with a
 * `title` explaining why. The alternative — drawing live-looking buttons with no
 * `onClick` — is what this app did before, and every one of them was a lie.
 */

/** Which nav keys the Settings module serves. */
const SETTINGS_REPORTS = new Set([
  'all_master_categories',
  'all_item_categories',
  'tds_report',
  'all_taxes',
  'coa_report',
]);

/**
 * Vendor Master — two nav keys, one report. See VendorMasterModule on why the
 * distinction between Creator's two vendor reports is not invented here.
 */
const VENDOR_REPORTS = new Set(['vendor_master', 'all_vendor_masters']);

/**
 * Why each unbuilt screen is unbuilt. Shown on the page and in the disabled
 * button's tooltip, so "not built" is never mistaken for "broken".
 */
const NOT_BUILT_REASON = {
  /*
   * ACCOUNTS IS NOT A REPORT. Husain, 27-Aug-2026: it is a DASHBOARD built
   * externally, not a Creator form or list. So it is not on the screenshot list with
   * the other unbuilt screens and it is not something to replicate column by column
   * — whatever renders it lives outside Creator.
   *
   * What that means for the rebuild is still open, and the two answers differ a lot:
   * if it is embedded in Creator (an iframe or widget) the rebuild hosts the same
   * embed; if it is a separate application the rail merely links out to it. Asked,
   * not assumed.
   */
  accounts: 'A dashboard built externally, not a Creator report — so there is no column order to replicate. Whether the rebuild embeds it or links to it is still open.',
  all_approvals: 'Fully specified in addendum §11 — amount-banded approver matrix. Next in line.',
  block_payment_date: 'The singleton exists, but addendum §16 found its cutoff is enforced NOWHERE server-side. Building the screen before the enforcement would imply a guard that does not exist.',
  auto_numbers: 'The counter is live and seeded (payment no. 20938). A screen that lets it be edited by hand would let payment numbers collide — §7.6.',
  schedule_payments: 'Gated: depends on §11 versioned payroll configuration with effective dates. Without it, re-running a month silently re-decides old payslips.',
  expense_observations: 'Spec §13 — needs the observation rules settled first.',
  backend_expenses: '31 columns, 135 fields. Addendum §4 has corrections but the form is unverified.',
  payment_requests: 'Three views, the clearest evidence for the §3.3 permission matrix (addendum §6). Needs authorisation wired first.',
  backend_payments: 'Form only; no list screenshot exists yet.',
};

/**
 * The report named in `#/<key>`, if it is one we can render.
 *
 * Validated against `nav.js` rather than trusted: a stale bookmark or a typo must fall
 * back to a working screen, not render an empty one. `built: false` keys ARE allowed
 * through — they render the honest "not built yet, and here is why" panel, which is a
 * real destination.
 */
function reportFromHash() {
  const key = (window.location.hash || '').replace(/^#\/?/, '').trim();

  if (key === '') {
    return 'payments';
  }

  const known = NAV.flatMap((item) => [item.key, ...(item.children ?? []).map((c) => c.key)]);

  return known.includes(key) ? key : 'payments';
}

export default function App() {
  /*
   * REFRESH USED TO LAND ON SETTINGS. The initial state was hardcoded to
   * `all_item_categories` — one of the eight Settings reports — a leftover from when
   * Settings was the screen being built. Every reload dropped the reader there
   * regardless of where they had been (Husain, 28-Aug-2026).
   *
   * Now the report lives in the URL hash, so a refresh returns you to the same screen
   * and a link can be sent to someone. An unknown or absent hash falls back to
   * `payments`, which is the app's main working screen — not `accounts`, which is an
   * external dashboard and renders nothing here.
   */
  const [report, setReport] = useState(reportFromHash);

  /*
   * Keep the hash in step, so a refresh returns here and the URL can be shared.
   *
   * `replaceState` rather than assigning `location.hash`: assigning pushes a history
   * entry per navigation, which turns the browser Back button into a tour of every
   * report the reader glanced at instead of taking them out of the app.
   */
  const go = (key) => {
    setReport(key);

    try {
      window.history.replaceState(null, '', `#/${key}`);
    } catch {
      // A sandboxed or file:// context can refuse replaceState. Navigation still works;
      // only the refresh-remembers-where-you-were part is lost.
    }
  };

  /* Back/forward and a hand-edited hash both still navigate. */
  useEffect(() => {
    const onHash = () => setReport(reportFromHash());

    window.addEventListener('hashchange', onHash);

    return () => window.removeEventListener('hashchange', onHash);
  }, []);

  const navItem = NAV.find((item) => item.key === report)
    ?? NAV.flatMap((item) => item.flyout ?? []).find((child) => child.key === report);

  const title = navItem?.label ?? report;
  const reason = NOT_BUILT_REASON[report]
    ?? 'No Creator screenshot for this screen yet, so nothing is guessed at.';

  return (
    <div className="zc-app">
      <Rail active={report} onNavigate={go} />

      <main className="zc-main">
        <div className="zc-appbar">
          <strong>Accounts</strong>
          <span className="zc-appbar-spacer" />
          <span style={{ color: 'var(--ink3)' }}>Husain Khatumdi</span>
        </div>

        {report === 'bills' ? (
          // Bills owns Create_Payment, so it can send the user to Payments after
          // minting one (§7.2 opens the new payment in Creator).
          <BillsModule onCreatePayment={() => go('payments')} />
        ) : report === 'payments' ? (
          /*
           * PAYMENTS' `+` NO LONGER GOES TO BILLS. That was wrong, and Husain
           * corrected it on 25-Aug-2026: a payment CAN be entered directly, not only
           * through §7.2's Create_Payment from a bill.
           *
           * I had inferred otherwise because §7.2 is the only creation path the three
           * context docs describe, and §4.4's "five origins" reads as five numbering
           * series rather than five ways in. Sending the user to Bills taught a data
           * model that is not the real one, so it is withdrawn rather than left
           * working-but-misleading.
           *
           * The direct form is not built yet, so `+` is disabled WITH THE REASON —
           * the honest-chrome rule. The field set is not a guess: the Payment form in
           * Accounts.ds (lines 7273-8673) carries ~70 fields each with row/column, so
           * layout comes from the DS the same way the Villa form's did.
           */
          <PaymentsModule />
        ) : report === 'expenses' ? (
          /*
           * All Expenses — the ledger (§5.2), 66,402 real rows. The ONLY report here
           * whose column order is verified rather than inferred: twelve screenshots
           * of the live report covering the full horizontal scroll.
           */
          <ExpensesModule />
        ) : report === 'fnb' ? (
          /*
           * F&B. Read-only, and it says so on screen: 22 tables share this schema
           * per §2.1, but only one of Creator's 21 F&B reports has been
           * screenshotted, so replicating the others would be inventing them.
           */
          <FnbModule />
        ) : report === 'pending_approvals' ? (
          /*
           * All Pending Approvals — 24 columns, order verified from seven screenshots,
           * and the FIRST screen here that moves money. The three action buttons sit
           * mid-table because that is where the live report puts them.
           *
           * `Approve` / `Reject` / `Pay` are wired, unlike every other action button in
           * this app: DecideApproval and MarkPaymentPaid are transcribed from the DS and
           * verified end to end. There is still no authentication behind them, and the
           * page says so.
           */
          <PendingApprovalsModule />
        ) : VENDOR_REPORTS.has(report) ? (
          /*
           * Both vendor nav keys render the same report. Creator has two and no
           * screenshot of either exists, so the difference between them is
           * unverified — the module says so on screen rather than inventing one.
           * `navKey` is passed only so it can name which key the user clicked.
           */
          <VendorMasterModule key={report} navKey={report} />
        ) : SETTINGS_REPORTS.has(report) ? (
          // `key` remounts on report change, so no pending edit or search term
          // survives into a different report.
          <SettingsModule key={report} report={report} />
        ) : (
          <>
            <ReportBar
              title={title}
              searchDisabledReason={`${title} is not built yet — there is nothing to search`}
              addDisabledReason={`${title} is not built yet. ${reason}`}
            />
            <div style={{ padding: 20, color: 'var(--ink3)', maxWidth: 640 }}>
              <p style={{ marginTop: 0 }}>
                <strong>{title}</strong> is not built yet.
              </p>
              <p style={{ fontSize: 12 }}>{reason}</p>
              <p style={{ fontSize: 12 }}>
                The controls above are visible on every screen for consistency, and
                disabled here because there is nothing behind them yet. Hover one to
                see why.
              </p>
              {navItem?.note && <p style={{ fontSize: 12 }}>Note: {navItem.note}</p>}
            </div>
          </>
        )}
      </main>
    </div>
  );
}

/** Mount into the single `#app` div that resources/views/app.blade.php renders. */
createRoot(document.getElementById('app')).render(<App />);
