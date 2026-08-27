/**
 * The nav rail — UI_HANDOFF.md §5.
 *
 * "at least 17 items, not the 11 in v1". Order and spelling are Creator's.
 *
 * NOTE THE MISSPELLING: the rail reads **Backbend** Payments while the form says
 * Backend Payments (addendum §8). It is reproduced here because handoff §2 rule 1
 * requires labels verbatim, and rule 7 requires source spellings preserved. The
 * API name stays `backend_payments`.
 *
 * Truncated labels (`Zoho app pointers - Payment Ap…`, `Ekostay …`) are shown as
 * captured. Their full text was never seen — flagged, not guessed.
 */
export const NAV = [
  { key: 'accounts', label: 'Accounts', built: false },
  /*
   * UNGATED 22-Aug-2026. §17 step 7 said stop here until the four §16
   * "blocking write paths" questions were answered. They are: the §3.3 matrix
   * is extracted, §7.6's padding turned out to be dead code, and §7.2's sign
   * and §12.4's delete turned out to be defects — fixed, not replicated.
   * Schedule Payments and Salary Payouts stay gated: they depend on §11's
   * versioned payroll configuration, which does not exist yet.
   */
  { key: 'payments', label: 'Payments', built: true },
  { key: 'bank', label: 'Bank', built: false },
  // Bills carries §7.2's Create_Payment per-record action, so Payments
  // depends on this screen existing.
  { key: 'bills', label: 'Bills', built: true },
  { key: 'expenses', label: 'Expenses', built: false },
  { key: 'schedule_payments', label: 'Schedule Payments', built: false, gated: 'step 7' },
  { key: 'expense_observations', label: 'Expense Observations', built: false },
  {
    key: 'masters',
    label: 'Masters',
    built: false,
    flyout: [
      { key: 'vendor_master', label: 'Vendor Master' },
      { key: 'all_vendor_masters', label: 'All Vendor Masters' },
    ],
  },
  {
    key: 'settings',
    label: 'Settings',
    built: true,
    // Eight reports, in the order the flyout lists them (addendum §2).
    flyout: [
      { key: 'all_master_categories', label: 'All Master Categories' },
      { key: 'all_item_categories', label: 'All Item Categories' },
      { key: 'all_approvals', label: 'All Approvals' },
      { key: 'tds_report', label: 'TDS Report' },
      { key: 'all_taxes', label: 'All Taxes' },
      { key: 'coa_report', label: 'COA Report' },
      { key: 'block_payment_date', label: 'Block Payment Date' },
      { key: 'auto_numbers', label: 'Auto Numbers' },
    ],
  },
  { key: 'backend_expenses', label: 'Backend Expenses', built: false },
  { key: 'pending_approvals', label: 'Pending Approvals', built: false },
  { key: 'app_preferences', label: 'App Preferences', built: false },
  {
    key: 'payment_requests',
    label: 'Payment Requests',
    built: false,
    // Three views — the clearest evidence for the §3.3 permission matrix
    // (addendum §6): a requester can create and inline-edit their own, an admin
    // can read across everyone but not edit inline.
    flyout: [
      { key: 'payment_request', label: 'Payment Request' },
      { key: 'all_payment_requests', label: 'All Payment Requests' },
      { key: 'user_payment_requests', label: 'User Payment Requests' },
    ],
  },
  { key: 'zoho_app_pointers', label: 'Zoho app pointers - Payment Ap…', built: false, truncated: true },
  { key: 'backend_payments', label: 'Backbend Payments', built: false, note: 'rail spells it Backbend; the form says Backend' },
  { key: 'preferred_approver', label: 'Preferred Approver', built: false },
  { key: 'ekostay', label: 'Ekostay …', built: false, truncated: true },
];
