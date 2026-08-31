-- ════════════════════════════════════════════════════════════════════════════
-- Ekostay Accounts — payments, reconciliation, expenses. Migration 002.
--
-- The table is `vendor_payments`, not `payments`. Two other tables on the live
-- host are already called `payments`, and neither is this one
-- (DB_FINDINGS.md §8):
--   serv_ekostay_expense.payments  — guest-side INBOUND, 105k rows
--   serv_ekostay_expense.payouts   — vendor-side outbound, mirrors Creator
-- ════════════════════════════════════════════════════════════════════════════

BEGIN;

-- ════════════════════════════════════════════════════════════════════════════
-- VENDOR PAYMENTS
-- ════════════════════════════════════════════════════════════════════════════

-- Both 'Sent for Approval' and 'Send for Approval' exist, and both appear in
-- live data (18 and 3 rows). 'Open' is written by Create_Payment but is NOT in
-- Creator's declared picklist — the tracker never ingests Payment_Status so its
-- absence downstream proves nothing (DB_FINDINGS.md §4). All kept.
CREATE TYPE payment_status AS ENUM (
  'Draft', 'Submit for Approval', 'Sent for Approval', 'Send for Approval',
  'Approved', 'Approval Rejected', 'Approval Not Required', 'Paid'
);
CREATE TYPE payment_state AS ENUM (
  'Pending', 'paid', 'Cancelled', 'Reverse', 'Open'
);
COMMENT ON TYPE payment_state IS
  'Creator: Payment_Status. Lowercase "paid" and undeclared "Open" are both real — Open is written by the Create_Payment custom action. Dirty enum: preserve on import, normalise in a mapping layer, never at rest.';

CREATE TABLE vendor_payments (
  id                  BIGSERIAL PRIMARY KEY,
  creator_id          TEXT UNIQUE,

  -- ── numbering ─────────────────────────────────────────────────────────────
  -- See payment_series in 001. Creator's counter collides: 233 numbers shared
  -- by 494 live rows, still happening as of 13-Aug-2026. So the display number
  -- is NOT unique, while (series, seq) is — allocated by the database, which
  -- the application cannot race.
  series_code         TEXT NOT NULL REFERENCES payment_series(code),
  series_seq          BIGINT NOT NULL,
  payment_no          TEXT NOT NULL,          -- display value, verbatim
  legacy_payment_no   TEXT,                   -- Creator's value where it collided

  -- ── parties and classification ────────────────────────────────────────────
  coa_id              BIGINT NOT NULL REFERENCES chart_of_accounts(id),
  bank_id             BIGINT REFERENCES chart_of_accounts(id),
  vendor_id           BIGINT REFERENCES vendors(id),
  location_id         BIGINT REFERENCES locations(id),
  bill_no             TEXT,                   -- concatenated display string
  booking_no          TEXT,

  payment_mode        TEXT CHECK (payment_mode IN ('Online','Offline')),
  status              payment_status NOT NULL DEFAULT 'Draft',
  state               payment_state NOT NULL DEFAULT 'Pending',

  -- ── dates ─────────────────────────────────────────────────────────────────
  requested_date      DATE,
  payment_date        DATE,
  due_date            DATE,
  timestamp_date      TIMESTAMPTZ,
  -- Creator preserves the original before a bank match overwrites payment_date,
  -- and restores it on full unmatch (Bank_Match_Line.Original_Payment_Date).
  original_payment_date DATE,
  -- Creator stores this one as TEXT, not a date. Kept as TEXT so a malformed
  -- provider value imports and is then visible rather than silently coerced.
  haewaya_timestamp   TEXT,

  -- ── money ─────────────────────────────────────────────────────────────────
  -- Creator declares Amount with decimalplace = 3 (defect 29) while Payable on
  -- the same row shows 2. NUMERIC(16,3) preserves the stored precision; the
  -- display layer decides how many places to show.
  gross_amount        NUMERIC(16,3) NOT NULL DEFAULT 0,
  gst_id              BIGINT REFERENCES taxes(id),
  gst_amount          money_inr NOT NULL DEFAULT 0,
  tds_rate_id         BIGINT REFERENCES tds_rates(id),
  tds_amount          money_inr NOT NULL DEFAULT 0,

  -- statutory deductions, salary path only
  pf_amount           money_inr NOT NULL DEFAULT 0,
  pt_amount           money_inr NOT NULL DEFAULT 0,
  esic_amount         money_inr NOT NULL DEFAULT 0,

  invoice_amount      money_inr NOT NULL DEFAULT 0,
  -- 🔴 NOT the same quantity as bills.payable_amount. Payment omits the paid
  -- term entirely; Bills subtracts it. Verified against 16,405 live rows:
  -- 16,285 have payable == invoice, the rest differ by exactly TDS
  -- (DB_FINDINGS.md §2). Distinct names, identical arithmetic to Creator.
  payable_amount      money_inr NOT NULL DEFAULT 0,
  original_amount     money_inr,

  -- ── references ────────────────────────────────────────────────────────────
  payment_reference_no TEXT,
  -- Creator's "Haewaya UTR Number" packs TWO comma-separated values in one
  -- field: '118103052206,15038'. Split on import; the raw value is retained
  -- because it is what the provider sent.
  haewaya_utr_raw      TEXT,
  haewaya_utr          TEXT,
  haewaya_ref          TEXT,

  -- ── reconciliation flags ──────────────────────────────────────────────────
  -- Derived by recomputeMatchFlags() in Creator from the active match lines.
  -- Here they are maintained by trigger from bank_match_lines, so they cannot
  -- disagree with the lines they summarise.
  bank_reconciled     BOOLEAN NOT NULL DEFAULT FALSE,
  withdrawal_matched  BOOLEAN NOT NULL DEFAULT FALSE,
  deposit_matched     BOOLEAN NOT NULL DEFAULT FALSE,

  -- ── other flags ───────────────────────────────────────────────────────────
  from_bill           BOOLEAN NOT NULL DEFAULT FALSE,   -- Creator: Accounts_Bills
  is_verified         BOOLEAN NOT NULL DEFAULT FALSE,
  is_approved         BOOLEAN NOT NULL DEFAULT FALSE,
  is_recoverable      BOOLEAN NOT NULL DEFAULT FALSE,
  is_external         BOOLEAN NOT NULL DEFAULT FALSE,
  is_salary_payout    BOOLEAN NOT NULL DEFAULT FALSE,
  split_equally       BOOLEAN NOT NULL DEFAULT FALSE,

  particulars         TEXT,
  ground_team_note    TEXT,
  expense_by          TEXT,
  payment_by          TEXT,
  user_made_paid      TEXT,
  document_link       TEXT,
  books_id            TEXT,

  -- ── reversal, replacing Creator's hard delete ─────────────────────────────
  -- Creator ships `Delete Paid Payment` in a report header; 17 real payments
  -- (₹93,884) were destroyed by it (§7.6). A settled payment is reversed here,
  -- never removed: the original keeps its row, its number and its match history.
  reverses_payment_id BIGINT REFERENCES vendor_payments(id),
  reversed_by_payment_id BIGINT REFERENCES vendor_payments(id),
  reversal_reason     TEXT,

  created_by          TEXT,
  created_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at          TIMESTAMPTZ NOT NULL DEFAULT now(),

  -- collision-proof going forward, without rejecting historical rows
  UNIQUE (series_code, series_seq),

  CONSTRAINT payment_not_own_reversal CHECK (reverses_payment_id IS DISTINCT FROM id),
  CONSTRAINT payment_reversal_needs_reason CHECK (
    reverses_payment_id IS NULL OR (reversal_reason IS NOT NULL AND length(trim(reversal_reason)) >= 8)
  ),
  -- a Paid payment must have a payment date. Creator enforces this in
  -- OnValidationCE; here it holds regardless of which code path writes the row.
  CONSTRAINT payment_paid_needs_date CHECK (
    status <> 'Paid' OR payment_date IS NOT NULL
  )
);

CREATE INDEX vp_payment_no_idx ON vendor_payments(payment_no);
CREATE INDEX vp_vendor_idx ON vendor_payments(vendor_id);
CREATE INDEX vp_status_idx ON vendor_payments(status);
CREATE INDEX vp_payment_date_idx ON vendor_payments(payment_date DESC);
CREATE INDEX vp_reference_idx ON vendor_payments(payment_reference_no)
  WHERE payment_reference_no IS NOT NULL;
CREATE INDEX vp_unmatched_idx ON vendor_payments(status, bank_reconciled)
  WHERE status = 'Paid' AND NOT bank_reconciled;
CREATE INDEX vp_reverses_idx ON vendor_payments(reverses_payment_id)
  WHERE reverses_payment_id IS NOT NULL;

-- many-to-many, mirroring bills
CREATE TABLE payment_villas (
  payment_id BIGINT NOT NULL REFERENCES vendor_payments(id) ON DELETE CASCADE,
  villa_id   BIGINT NOT NULL REFERENCES villas(id),
  PRIMARY KEY (payment_id, villa_id)
);
CREATE TABLE payment_item_categories (
  payment_id       BIGINT NOT NULL REFERENCES vendor_payments(id) ON DELETE CASCADE,
  item_category_id BIGINT NOT NULL REFERENCES item_categories(id),
  PRIMARY KEY (payment_id, item_category_id)
);
CREATE TABLE payment_billing_cycles (
  payment_id       BIGINT NOT NULL REFERENCES vendor_payments(id) ON DELETE CASCADE,
  billing_cycle_id BIGINT NOT NULL REFERENCES billing_cycles(id),
  PRIMARY KEY (payment_id, billing_cycle_id)
);

-- ── payment split legs ──────────────────────────────────────────────────────
-- ⚠️ Creator does NOT validate that these sum to gross (§7.4) — Bills does, but
-- Payment's check fires only outside Draft AND outside Accounts Payable. Since
-- one expense row IS one split leg (§5.2), an unbalanced payment silently
-- misstates every downstream villa-month-category figure.
--
-- The constraint below is DEFERRABLE: it holds at commit, so a multi-statement
-- edit can pass through an intermediate unbalanced state, but an unbalanced
-- payment cannot be committed. Historical import runs with it SET CONSTRAINTS
-- DEFERRED and records exceptions in payment_split_exceptions rather than
-- silently correcting anything.

CREATE TABLE payment_splits (
  id               BIGSERIAL PRIMARY KEY,
  payment_id       BIGINT NOT NULL REFERENCES vendor_payments(id) ON DELETE CASCADE,
  villa_id         BIGINT NOT NULL REFERENCES villas(id),
  billing_cycle_id BIGINT REFERENCES billing_cycles(id),
  item_category_id BIGINT REFERENCES item_categories(id),

  gross_amount     NUMERIC(16,3) NOT NULL DEFAULT 0,
  gst_amount       money_inr NOT NULL DEFAULT 0,
  tds_amount       money_inr NOT NULL DEFAULT 0,
  total_amount     money_inr NOT NULL DEFAULT 0,

  pt_amount        money_inr NOT NULL DEFAULT 0,
  esic_amount      money_inr NOT NULL DEFAULT 0,
  pf_amount        money_inr NOT NULL DEFAULT 0,

  -- running unpaid balance, as on bill_splits
  backend_amount     money_inr NOT NULL DEFAULT 0,
  backend_gst_amount money_inr NOT NULL DEFAULT 0,
  backend_tds_amount money_inr NOT NULL DEFAULT 0,

  percent          NUMERIC(6,3),

  UNIQUE (payment_id, villa_id, billing_cycle_id, item_category_id)
);

CREATE INDEX payment_splits_payment_idx ON payment_splits(payment_id);
CREATE INDEX payment_splits_villa_cycle_idx
  ON payment_splits(villa_id, billing_cycle_id, item_category_id);

-- Rows that could not satisfy the balance rule at import. Recorded, not fixed —
-- correcting a historical amount is an accounting decision, not a migration one.
CREATE TABLE payment_split_exceptions (
  id           BIGSERIAL PRIMARY KEY,
  payment_id   BIGINT NOT NULL REFERENCES vendor_payments(id) ON DELETE CASCADE,
  gross_amount NUMERIC(16,3) NOT NULL,
  split_total  NUMERIC(16,3) NOT NULL,
  variance     NUMERIC(16,3) GENERATED ALWAYS AS (gross_amount - split_total) STORED,
  noted_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
  note         TEXT
);

CREATE OR REPLACE FUNCTION check_payment_split_balance() RETURNS TRIGGER AS $$
DECLARE
  v_gross NUMERIC(16,3);
  v_sum   NUMERIC(16,3);
  v_pid   BIGINT;
  v_coa   TEXT;
  v_status payment_status;
BEGIN
  v_pid := COALESCE(NEW.payment_id, OLD.payment_id);

  SELECT p.gross_amount, p.status, c.name
    INTO v_gross, v_status, v_coa
    FROM vendor_payments p
    JOIN chart_of_accounts c ON c.id = p.coa_id
   WHERE p.id = v_pid;

  -- Creator's own gate, reproduced: the check applies only outside Draft and
  -- outside Accounts Payable. Copy as built — a stricter rule here would
  -- reject data the source permits.
  IF v_status = 'Draft' OR v_coa = 'Accounts Payable' THEN
    RETURN NULL;
  END IF;

  SELECT COALESCE(SUM(gross_amount), 0) INTO v_sum
    FROM payment_splits WHERE payment_id = v_pid;

  IF round(v_sum, 2) <> round(v_gross, 2) THEN
    RAISE EXCEPTION
      'Payment % split legs total % but gross is % (variance %). '
      'Creator permits this outside Draft only for Accounts Payable; every '
      'downstream villa-month-category figure would be misstated by the difference.',
      v_pid, round(v_sum,2), round(v_gross,2), round(v_gross - v_sum, 2);
  END IF;
  RETURN NULL;
END;
$$ LANGUAGE plpgsql;

CREATE CONSTRAINT TRIGGER payment_splits_balance
  AFTER INSERT OR UPDATE OR DELETE ON payment_splits
  DEFERRABLE INITIALLY DEFERRED
  FOR EACH ROW EXECUTE FUNCTION check_payment_split_balance();

-- ── bill allocation ─────────────────────────────────────────────────────────
-- Creator's Bill_Payments grid: which bills this payment settles, and for how
-- much. Not documents — the three document mechanisms are separate (§7.5).

CREATE TABLE payment_bill_allocations (
  id             BIGSERIAL PRIMARY KEY,
  payment_id     BIGINT NOT NULL REFERENCES vendor_payments(id) ON DELETE CASCADE,
  bill_id        BIGINT NOT NULL REFERENCES bills(id),
  villa_id       BIGINT REFERENCES villas(id),
  unpaid_amount  money_inr NOT NULL DEFAULT 0,   -- Creator label: "UnPaid Amount"
  payable_amount money_inr NOT NULL DEFAULT 0,
  pay_full       BOOLEAN NOT NULL DEFAULT FALSE,
  UNIQUE (payment_id, bill_id, villa_id)
);

CREATE INDEX pba_bill_idx ON payment_bill_allocations(bill_id);

-- ════════════════════════════════════════════════════════════════════════════
-- BANK RECONCILIATION
-- ════════════════════════════════════════════════════════════════════════════
-- Creator's Bank_Match_Line is the best-designed object in the app: a real
-- junction table with direction, matched amount, soft delete and original-date
-- preservation. The settlement system on the live server, by contrast, stores
-- its matches as a TEXT blob in bank_transactions.zoho_match_payments — the
-- same list-in-a-column anti-pattern (DB_FINDINGS.md §9).
--
-- This follows Creator's design and adds the one thing it lacks: a database
-- guarantee that a payment cannot be double-allocated in the same direction.
-- Creator repairs that AFTER the fact, in resolveDuplicateLines().

CREATE TYPE match_direction AS ENUM ('Withdrawal', 'Deposit');

CREATE TABLE bank_transactions (
  id                BIGSERIAL PRIMARY KEY,
  creator_id        TEXT UNIQUE,
  books_txn_id      TEXT,
  txn_key           TEXT UNIQUE,             -- natural key for dedup
  txn_date          DATE NOT NULL,
  reference_no      TEXT,
  description       TEXT,
  account_id        BIGINT NOT NULL REFERENCES chart_of_accounts(id),
  withdrawal        money_inr,
  deposit           money_inr,
  amount            money_inr NOT NULL,
  bank_charges      money_inr NOT NULL DEFAULT 0,
  transaction_type  TEXT,
  status            TEXT,
  is_duplicate      BOOLEAN NOT NULL DEFAULT FALSE,
  is_personal       BOOLEAN NOT NULL DEFAULT FALSE,
  reason            TEXT,
  created_at        TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at        TIMESTAMPTZ NOT NULL DEFAULT now(),

  -- exactly one direction per row, which is what Creator infers from
  -- `Withdrawal != null && Deposit == null`
  CONSTRAINT txn_one_direction CHECK (
    (withdrawal IS NOT NULL AND deposit IS NULL)
    OR (deposit IS NOT NULL AND withdrawal IS NULL)
  )
);

CREATE INDEX bt_date_idx ON bank_transactions(txn_date DESC);
CREATE INDEX bt_account_idx ON bank_transactions(account_id);
CREATE INDEX bt_reference_idx ON bank_transactions(reference_no)
  WHERE reference_no IS NOT NULL;
CREATE INDEX bt_open_idx ON bank_transactions(account_id, txn_date)
  WHERE NOT is_duplicate;

CREATE TABLE bank_match_lines (
  id                    BIGSERIAL PRIMARY KEY,
  bank_transaction_id   BIGINT NOT NULL REFERENCES bank_transactions(id) ON DELETE CASCADE,
  payment_id            BIGINT NOT NULL REFERENCES vendor_payments(id) ON DELETE CASCADE,
  direction             match_direction NOT NULL,
  matched_amount        money_inr NOT NULL CHECK (matched_amount > 0),
  bank_account_id       BIGINT REFERENCES chart_of_accounts(id),
  match_source          TEXT NOT NULL DEFAULT 'Manual'
                          CHECK (match_source IN ('Manual','Auto Opposite','Suggested')),
  match_group           BIGINT,
  -- restored to the payment on full unmatch
  original_payment_date DATE,
  is_active             BOOLEAN NOT NULL DEFAULT TRUE,   -- soft delete
  matched_on            TIMESTAMPTZ NOT NULL DEFAULT now(),
  matched_by            TEXT
);

-- 🔑 THE INVARIANT CREATOR REPAIRS AFTER THE FACT.
-- One ACTIVE line per payment per direction. A partial index means released
-- lines (is_active = false) do not collide, so unmatch-and-rematch works.
CREATE UNIQUE INDEX bml_one_active_per_direction
  ON bank_match_lines(payment_id, direction)
  WHERE is_active;

CREATE INDEX bml_txn_idx ON bank_match_lines(bank_transaction_id) WHERE is_active;
CREATE INDEX bml_payment_idx ON bank_match_lines(payment_id);

-- A transaction can never be over-allocated. Deferred so a multi-line edit can
-- pass through an intermediate state.
CREATE OR REPLACE FUNCTION check_txn_not_overallocated() RETURNS TRIGGER AS $$
DECLARE
  v_txn BIGINT;
  v_amount money_inr;
  v_held   money_inr;
BEGIN
  v_txn := COALESCE(NEW.bank_transaction_id, OLD.bank_transaction_id);
  SELECT amount INTO v_amount FROM bank_transactions WHERE id = v_txn;
  SELECT COALESCE(SUM(matched_amount), 0) INTO v_held
    FROM bank_match_lines WHERE bank_transaction_id = v_txn AND is_active;

  IF round(v_held, 2) > round(v_amount, 2) + 0.01 THEN
    RAISE EXCEPTION
      'Bank transaction % is over-allocated: % matched against an amount of %.',
      v_txn, round(v_held,2), round(v_amount,2);
  END IF;
  RETURN NULL;
END;
$$ LANGUAGE plpgsql;

CREATE CONSTRAINT TRIGGER bml_no_overallocation
  AFTER INSERT OR UPDATE OR DELETE ON bank_match_lines
  DEFERRABLE INITIALLY DEFERRED
  FOR EACH ROW EXECUTE FUNCTION check_txn_not_overallocated();

-- Keep the payment's three reconciliation flags in step with its active lines,
-- and restore original_payment_date on full release — Creator does both in
-- recomputeMatchFlags(), which can be skipped if a caller forgets.
CREATE OR REPLACE FUNCTION sync_payment_match_flags() RETURNS TRIGGER AS $$
DECLARE
  v_pid BIGINT;
  v_w   BOOLEAN;
  v_d   BOOLEAN;
  v_orig DATE;
BEGIN
  v_pid := COALESCE(NEW.payment_id, OLD.payment_id);

  SELECT bool_or(direction = 'Withdrawal'), bool_or(direction = 'Deposit')
    INTO v_w, v_d
    FROM bank_match_lines WHERE payment_id = v_pid AND is_active;

  v_w := COALESCE(v_w, FALSE);
  v_d := COALESCE(v_d, FALSE);

  IF NOT v_w AND NOT v_d THEN
    SELECT original_payment_date INTO v_orig
      FROM bank_match_lines
     WHERE payment_id = v_pid AND original_payment_date IS NOT NULL
     ORDER BY matched_on DESC LIMIT 1;

    UPDATE vendor_payments
       SET withdrawal_matched = FALSE, deposit_matched = FALSE,
           bank_reconciled = FALSE,
           payment_date = COALESCE(v_orig, payment_date),
           updated_at = now()
     WHERE id = v_pid;
  ELSE
    UPDATE vendor_payments
       SET withdrawal_matched = v_w, deposit_matched = v_d,
           bank_reconciled = TRUE, updated_at = now()
     WHERE id = v_pid;
  END IF;
  RETURN NULL;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER bml_sync_flags
  AFTER INSERT OR UPDATE OR DELETE ON bank_match_lines
  FOR EACH ROW EXECUTE FUNCTION sync_payment_match_flags();

-- ════════════════════════════════════════════════════════════════════════════
-- EXPENSES — the flattened ledger
-- ════════════════════════════════════════════════════════════════════════════
-- One row = one materialised split leg (§5.2). Creator regenerates these by
-- setting Bill_Available = false on every existing row, rebuilding, then
-- DELETING whatever is still false (defect 30). A sweep keyed on a boolean: if
-- regeneration fails midway, rows are gone.
--
-- Here it is an upsert on a stable key with soft delete, so a failed
-- regeneration leaves the previous state intact.

CREATE TABLE expenses (
  id                 BIGSERIAL PRIMARY KEY,
  creator_id         TEXT UNIQUE,

  source_type        TEXT NOT NULL CHECK (source_type IN ('Expense','Bill')),
  bill_id            BIGINT REFERENCES bills(id) ON DELETE CASCADE,
  payment_id         BIGINT REFERENCES vendor_payments(id) ON DELETE CASCADE,

  -- the stable identity of a leg: parent × villa × cycle × category
  villa_id           BIGINT NOT NULL REFERENCES villas(id),
  billing_cycle_id   BIGINT REFERENCES billing_cycles(id),
  item_category_id   BIGINT REFERENCES item_categories(id),
  master_category_id BIGINT REFERENCES master_categories(id),
  location_id        BIGINT REFERENCES locations(id),
  vendor_id          BIGINT REFERENCES vendors(id),
  coa_id             BIGINT REFERENCES chart_of_accounts(id),
  bank_id            BIGINT REFERENCES chart_of_accounts(id),

  bill_no            TEXT,
  booking_no         TEXT,
  bill_date          DATE,
  due_date           DATE,
  payment_date       DATE,

  gross_amount       money_inr NOT NULL DEFAULT 0,
  gst_amount         money_inr NOT NULL DEFAULT 0,
  tds_amount         money_inr NOT NULL DEFAULT 0,
  amount             money_inr NOT NULL DEFAULT 0,
  net_paid_amount    money_inr NOT NULL DEFAULT 0,
  pf_amount          money_inr NOT NULL DEFAULT 0,
  pt_amount          money_inr NOT NULL DEFAULT 0,
  esic_amount        money_inr NOT NULL DEFAULT 0,

  -- Creator packs '{Category} - {note},{date},{date}' into one field, and one
  -- live row contains a customer's full bank account in plaintext. Kept as the
  -- raw value plus parsed parts, so the packing is visible and reversible.
  particulars_raw    TEXT,
  particulars_note   TEXT,
  accounts_remark    TEXT,
  management_remark  TEXT,

  status             TEXT,
  ca_email           CITEXT,
  books_id           TEXT,
  document_link      TEXT,

  -- re-classification audit: Creator lets Expenses_Bills.Old_Billing_Cycles be
  -- edited directly, which is a re-classification tool with no trail
  previous_billing_cycle_id BIGINT REFERENCES billing_cycles(id),
  reclassified_at    TIMESTAMPTZ,
  reclassified_by    TEXT,

  -- soft delete, replacing the Bill_Available sweep
  is_active          BOOLEAN NOT NULL DEFAULT TRUE,
  deactivated_at     TIMESTAMPTZ,

  created_at         TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at         TIMESTAMPTZ NOT NULL DEFAULT now(),

  -- exactly one parent
  CONSTRAINT expense_one_parent CHECK (
    (bill_id IS NOT NULL AND payment_id IS NULL)
    OR (payment_id IS NOT NULL AND bill_id IS NULL)
  )
);

-- the upsert keys that replace the delete sweep
CREATE UNIQUE INDEX expenses_bill_leg_idx
  ON expenses(bill_id, villa_id, billing_cycle_id, item_category_id)
  WHERE bill_id IS NOT NULL;
CREATE UNIQUE INDEX expenses_payment_leg_idx
  ON expenses(payment_id, villa_id, billing_cycle_id, item_category_id)
  WHERE payment_id IS NOT NULL;

CREATE INDEX expenses_villa_cycle_idx ON expenses(villa_id, billing_cycle_id) WHERE is_active;
CREATE INDEX expenses_category_idx ON expenses(item_category_id) WHERE is_active;
CREATE INDEX expenses_payment_date_idx ON expenses(payment_date DESC) WHERE is_active;

-- ════════════════════════════════════════════════════════════════════════════
-- SYNC LOCKS
-- ════════════════════════════════════════════════════════════════════════════
-- Creator's Sync_Locks with a unique Lock_Key, used as a mutex by
-- Haewya.SyncExpenses. It has stale-lock recovery (deletes locks older than ten
-- minutes) and a lost-race check after insert — genuinely careful code.
--
-- It is NOT sufficient: it guards one transaction_id, not the payment-number
-- counter, which is why 233 numbers still collide. The (series, seq) unique key
-- in vendor_payments is what actually closes that hole.

CREATE TABLE sync_locks (
  lock_key    TEXT PRIMARY KEY,
  acquired_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  acquired_by TEXT,
  expires_at  TIMESTAMPTZ NOT NULL DEFAULT (now() + INTERVAL '10 minutes')
);

CREATE INDEX sync_locks_expiry_idx ON sync_locks(expires_at);

COMMIT;
