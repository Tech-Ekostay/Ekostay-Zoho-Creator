-- ════════════════════════════════════════════════════════════════════════════
-- Ekostay Accounts — core schema, migration 001
--
-- PostgreSQL 16. Money is NUMERIC(16,2) throughout, matching the live server's
-- existing columns (DB_FINDINGS.md §3). Never a float.
--
-- SOURCE
--   zoho_source/Accounts_SCHEMA.md    the 46 Creator forms
--   zoho_source/Admin.ds              Villa, Employee_Master, Location
--   DB_FINDINGS.md                    live-server corrections
--   OPEN_QUESTIONS.md                 the decisions this encodes
--
-- DESIGN RULE — COPY AS BUILT, CONSTRAIN AT THE EDGE
--   Creator's arithmetic is reproduced exactly. What this schema adds is
--   *enforcement of invariants Creator relies on but never states*: a split
--   that must sum to its parent, a match line that must not double-allocate,
--   a payment number that must be unique per series. Every one of those is a
--   rule the current system depends on and breaks in production.
--
--   Where Creator's data would violate a constraint, the constraint is written
--   as DEFERRABLE or as a trigger with a recorded exception — never dropped.
--   A constraint you cannot import against is a constraint you will disable.
-- ════════════════════════════════════════════════════════════════════════════

BEGIN;

CREATE EXTENSION IF NOT EXISTS pgcrypto;   -- gen_random_uuid
CREATE EXTENSION IF NOT EXISTS citext;     -- case-insensitive email

-- ── domains ─────────────────────────────────────────────────────────────────
-- A named money type, so a column cannot silently become a float and so the
-- precision decision lives in one place.

CREATE DOMAIN money_inr AS NUMERIC(16,2);

-- Creator stores Billing_Cycles.Year_field as TEXT and Month_field as a full
-- English month name. Both are preserved for import fidelity, but the sortable
-- integer key is generated rather than maintained by hand — Creator's own
-- MonthIndex is written by two separate handlers and can drift.
CREATE DOMAIN month_name AS TEXT
  CHECK (VALUE IN ('January','February','March','April','May','June','July',
                   'August','September','October','November','December'));

-- ════════════════════════════════════════════════════════════════════════════
-- MASTER DATA
-- ════════════════════════════════════════════════════════════════════════════

CREATE TABLE locations (
  id            BIGSERIAL PRIMARY KEY,
  creator_id    TEXT UNIQUE,                    -- Creator's 19-digit record id
  name          TEXT NOT NULL UNIQUE,
  head_office   TEXT,
  state         TEXT,
  is_active     BOOLEAN NOT NULL DEFAULT TRUE,
  created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at    TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- ── villas ──────────────────────────────────────────────────────────────────
-- Creator declares THREE active-ish flags: Active, Status {Active, In Active}
-- and Hide_From_Payments. Only Hide_From_Payments is read by any code — it
-- filters the villa picker in five lookups. Active and Status appear solely as
-- report columns (OPEN_QUESTIONS.md §B1).
--
-- So: two columns, not three. `is_active` carries the intent; `hidden_from_
-- payments` carries the only behaviour Creator actually implements.

CREATE TABLE villas (
  id                      BIGSERIAL PRIMARY KEY,
  creator_id              TEXT UNIQUE,
  name                    TEXT NOT NULL,
  location_id             BIGINT REFERENCES locations(id),
  state                   TEXT,

  is_active               BOOLEAN NOT NULL DEFAULT TRUE,
  hidden_from_payments    BOOLEAN NOT NULL DEFAULT FALSE,

  -- Creator's Category picklist is {'Gold','Luxery','Original'} with
  -- others_option = true. Zoho Analytics serves the CORRECTED spelling
  -- ('Luxury') and its ingest does no mapping — so a transformation layer
  -- exists that no DS export reveals (DB_FINDINGS.md §1).
  --
  -- Store Creator's value verbatim; expose the alias for anything talking to
  -- Analytics or the expense tracker. NEVER join on either label — join on
  -- villa_id. A label join drops every Luxury villa silently.
  category                TEXT,
  category_alias          TEXT GENERATED ALWAYS AS (
                            CASE category WHEN 'Luxery' THEN 'Luxury' ELSE category END
                          ) STORED,

  -- Rent_Type declares four values plus free text. Live data across 200 villas
  -- shows ONLY 'Lease' and 'Revenue Share' in use — neither EKOSTAY value, nor
  -- any free-text value (DB_FINDINGS.md §0). The unhandled branch is dead code,
  -- not an accounting hole. Kept as TEXT so an unrecognised value imports and
  -- is then visible, rather than being rejected at the door.
  rent_type               TEXT,

  -- Owner splits apply to 'Revenue Share' ONLY. Creator hides these fields for
  -- every other rent type and stores nothing. One rule, one place — so if
  -- EKOSTAY-type splits are ever wanted it is a config change, not archaeology.
  revenue_split_owner_pct NUMERIC(5,2) CHECK (revenue_split_owner_pct BETWEEN 0 AND 100),
  expense_split_owner_pct NUMERIC(5,2) CHECK (expense_split_owner_pct BETWEEN 0 AND 100),
  gst_pct                 NUMERIC(5,2) CHECK (gst_pct BETWEEN 0 AND 100),

  -- villa grouping. Creator maintains Primary/Secondary bidirectionally via
  -- OnSuccessCE; a self-referencing FK does the same thing declaratively.
  primary_villa_id        BIGINT REFERENCES villas(id),
  is_primary              BOOLEAN NOT NULL DEFAULT FALSE,

  bhk                     TEXT,
  max_occupancy           NUMERIC(6,2),
  expense_base_amount     money_inr,
  haewaya_id              TEXT,
  ekostay_id              BIGINT,
  created_at              TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at              TIMESTAMPTZ NOT NULL DEFAULT now(),

  -- splits only exist on Revenue Share, enforced rather than merely hidden
  CONSTRAINT villa_splits_revenue_share_only CHECK (
    rent_type = 'Revenue Share'
    OR (revenue_split_owner_pct IS NULL
        AND expense_split_owner_pct IS NULL
        AND gst_pct IS NULL)
  ),
  -- a villa cannot be its own parent
  CONSTRAINT villa_not_own_parent CHECK (primary_villa_id IS DISTINCT FROM id)
);

CREATE INDEX villas_location_idx ON villas(location_id);
CREATE INDEX villas_pickable_idx ON villas(hidden_from_payments) WHERE NOT hidden_from_payments;
CREATE UNIQUE INDEX villas_name_idx ON villas(lower(name));

-- ── chart of accounts ───────────────────────────────────────────────────────
-- Creator's `Hide` flag is MISNAMED, not inverted. Payment filters
-- COA[Hide == true] and live payouts show every account type reaching that
-- picker — expense, accounts_payable, bank, cash, other_asset. So Hide == true
-- means "available for selection" (DB_FINDINGS.md §5).
--
-- Renamed `selectable`, behaviour identical, Creator's name recorded.

CREATE TABLE chart_of_accounts (
  id            BIGSERIAL PRIMARY KEY,
  creator_id    TEXT UNIQUE,
  books_id      TEXT,                      -- Zoho Books account_id
  name          TEXT NOT NULL,
  account_type  TEXT NOT NULL,             -- free text in Creator: bank, cash,
                                           -- expense, other_asset, …
  account_code  TEXT,
  selectable    BOOLEAN NOT NULL DEFAULT TRUE,   -- Creator: `Hide`
  is_bank       BOOLEAN NOT NULL DEFAULT FALSE,  -- Creator: `Bank`
  ca_email      CITEXT,
  created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at    TIMESTAMPTZ NOT NULL DEFAULT now()
);

COMMENT ON COLUMN chart_of_accounts.selectable IS
  'Creator name: Hide. The flag is misnamed — Hide == true means the account IS offered in the Payment COA picker. Verified against live payouts.coa_type, which shows every account type reaching that picker.';

CREATE UNIQUE INDEX coa_name_idx ON chart_of_accounts(lower(name));
CREATE INDEX coa_bank_idx ON chart_of_accounts(is_bank) WHERE is_bank;

-- ── categories ──────────────────────────────────────────────────────────────
-- master_categories.is_fb is THE F&B flag. Filter on it — never on
-- name = 'F&B' (context doc §4.2).

CREATE TABLE master_categories (
  id          BIGSERIAL PRIMARY KEY,
  creator_id  TEXT UNIQUE,
  name        TEXT NOT NULL UNIQUE,
  is_fb       BOOLEAN NOT NULL DEFAULT FALSE,
  haewaya_id  TEXT
);

-- Item category names are NOT normalised anywhere in the pipeline. Live data
-- holds 'MAINTAINENCE' and 'MAINTENANCE' as distinct categories, and
-- 'ACCOMODATION' beside 'ACCOMMODATION' (DB_FINDINGS.md §1). Anything grouping
-- by name is already splitting them. Preserved verbatim; a canonical_name is
-- provided for reporting without touching the key.
CREATE TABLE item_categories (
  id                     BIGSERIAL PRIMARY KEY,
  creator_id             TEXT UNIQUE,
  name                   TEXT NOT NULL,
  canonical_name         TEXT,             -- for reporting; NULL = name is canonical
  master_category_id     BIGINT NOT NULL REFERENCES master_categories(id),
  coa_id                 BIGINT REFERENCES chart_of_accounts(id),
  bank_id                BIGINT REFERENCES chart_of_accounts(id),
  expense_type           TEXT CHECK (expense_type IN ('Direct','Indirect')),
  -- Creator: `Disable`, labelled "Disallow Manual Creation"
  block_manual_creation  BOOLEAN NOT NULL DEFAULT FALSE,
  exclude_for_profit     BOOLEAN NOT NULL DEFAULT FALSE,
  exclude_for_observation BOOLEAN NOT NULL DEFAULT FALSE,
  variance_pct           NUMERIC(5,2),
  haewaya_id             TEXT
);

CREATE UNIQUE INDEX item_cat_name_idx ON item_categories(lower(name));
CREATE INDEX item_cat_master_idx ON item_categories(master_category_id);

-- ── billing cycles ──────────────────────────────────────────────────────────
-- Creator stores Year_field as TEXT and auto-creates cycles from free text in
-- several places, which produced a junk "9-2026" cycle in live accounting
-- (defect 13). Here the year is an integer, the month is a constrained domain,
-- and the pair is unique — so a junk cycle cannot be created at all.

CREATE TABLE billing_cycles (
  id           BIGSERIAL PRIMARY KEY,
  creator_id   TEXT UNIQUE,
  month        month_name NOT NULL,
  year         SMALLINT NOT NULL CHECK (year BETWEEN 2020 AND 2100),
  -- generated, not maintained: Creator's MonthIndex is written by two separate
  -- handlers and can drift out of step with the month/year it describes
  month_index  INTEGER GENERATED ALWAYS AS (
                 year * 100 + CASE month
                   WHEN 'January' THEN 1 WHEN 'February' THEN 2 WHEN 'March' THEN 3
                   WHEN 'April' THEN 4 WHEN 'May' THEN 5 WHEN 'June' THEN 6
                   WHEN 'July' THEN 7 WHEN 'August' THEN 8 WHEN 'September' THEN 9
                   WHEN 'October' THEN 10 WHEN 'November' THEN 11 ELSE 12 END
               ) STORED,
  UNIQUE (month, year)
);

CREATE INDEX billing_cycles_index_idx ON billing_cycles(month_index);

-- ── tax ─────────────────────────────────────────────────────────────────────

CREATE TABLE taxes (
  id          BIGSERIAL PRIMARY KEY,
  creator_id  TEXT UNIQUE,
  books_id    TEXT,
  name        TEXT NOT NULL,
  -- 'tax' = single rate (IGST). 'tax_group' = CGST+SGST, where Creator halves
  -- the amount, rounds EACH HALF to 2dp, then re-adds. That is not the same as
  -- rounding the total — see the bill/payment computation functions.
  tax_type    TEXT NOT NULL CHECK (tax_type IN ('tax','tax_group')),
  percentage  NUMERIC(5,2) NOT NULL CHECK (percentage BETWEEN 0 AND 100),
  is_active   BOOLEAN NOT NULL DEFAULT TRUE
);

CREATE TABLE tds_rates (
  id          BIGSERIAL PRIMARY KEY,
  creator_id  TEXT UNIQUE,
  books_id    TEXT,
  name        TEXT NOT NULL,
  percentage  NUMERIC(5,2) NOT NULL CHECK (percentage BETWEEN 0 AND 100),
  status      TEXT NOT NULL DEFAULT 'Active' CHECK (status IN ('Active','Expired'))
);

-- ── employees and roles ─────────────────────────────────────────────────────
-- Creator dispatches authorisation on Employee_Master.User_Role, which is
-- UNCONSTRAINED TEXT, using .contains() across three apps. A typo grants
-- nothing while still setting Access_Given = true, and OnSuccessCE then
-- auto-creates a designation from the typo, making it permanent master data
-- (defect 49). OPEN_QUESTIONS.md §B4 calls fixing this the highest-value
-- structural change in the master layer.
--
-- Roles as data, FK from employees, no string matching in the auth path.

CREATE TABLE roles (
  id            BIGSERIAL PRIMARY KEY,
  code          TEXT NOT NULL UNIQUE,      -- stable key, e.g. account_team_senior
  creator_label TEXT NOT NULL,             -- the exact User_Role text Creator matched
  name          TEXT NOT NULL,
  description   TEXT
);

CREATE TABLE permissions (
  id          BIGSERIAL PRIMARY KEY,
  code        TEXT NOT NULL UNIQUE,        -- e.g. payment.approve, payroll.edit
  description TEXT NOT NULL
);

CREATE TABLE role_permissions (
  role_id       BIGINT NOT NULL REFERENCES roles(id) ON DELETE CASCADE,
  permission_id BIGINT NOT NULL REFERENCES permissions(id) ON DELETE CASCADE,
  PRIMARY KEY (role_id, permission_id)
);

CREATE TABLE employees (
  id            BIGSERIAL PRIMARY KEY,
  creator_id    TEXT UNIQUE,
  name          TEXT NOT NULL,
  employee_code TEXT,
  email         CITEXT UNIQUE,
  phone         TEXT,
  role_id       BIGINT REFERENCES roles(id),
  designation   TEXT,
  department    TEXT,
  location_id   BIGINT REFERENCES locations(id),
  state         TEXT,
  is_active     BOOLEAN NOT NULL DEFAULT TRUE,

  -- Creator's Is_HR: one boolean on one record gates Total_Amount,
  -- Make_Calculation and the Salary Months grid — the entire payroll authority
  -- (§B5). Kept for import fidelity, but the rebuild should grant
  -- 'payroll.edit' through role_permissions instead and derive this.
  is_hr         BOOLEAN NOT NULL DEFAULT FALSE,

  gender        TEXT CHECK (gender IN ('Male','Female')),
  date_of_birth DATE,
  joining_date  DATE,
  created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at    TIMESTAMPTZ NOT NULL DEFAULT now()
);

COMMENT ON COLUMN employees.is_hr IS
  'Creator: Is_HR. A single boolean that gates all payroll editing. Retained for import fidelity — prefer the payroll.edit permission via role_permissions.';

-- ── vendors ─────────────────────────────────────────────────────────────────
-- Creator's Payment picker filters Vendor_Master[Main_Primary is not null],
-- which distinguishes trade vendors from customer-refund payees (§7.5).
--
-- Caretakers are filed here as vendors with the villa encoded in the NAME
-- string — 'suman(amani ct)' — while Employee_Master exists and is not used
-- for it (§10.4). Reproduced, with the relationship recorded properly.

CREATE TABLE vendors (
  id                 BIGSERIAL PRIMARY KEY,
  creator_id         TEXT UNIQUE,
  books_id           TEXT,
  name               TEXT NOT NULL,
  primary_vendor_id  BIGINT REFERENCES vendors(id),   -- Creator: Main_Primary
  gst_no             TEXT,
  pan_no             TEXT,
  email              CITEXT,
  phone              TEXT,
  location_id        BIGINT REFERENCES locations(id),
  state              TEXT,
  vendor_category_id BIGINT REFERENCES item_categories(id),
  master_category_id BIGINT REFERENCES master_categories(id),

  -- Creator encodes this in the name string; recorded structurally instead.
  is_caretaker       BOOLEAN NOT NULL DEFAULT FALSE,
  caretaker_villa_id BIGINT REFERENCES villas(id),
  employee_id        BIGINT REFERENCES employees(id),

  is_active          BOOLEAN NOT NULL DEFAULT TRUE,
  created_at         TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at         TIMESTAMPTZ NOT NULL DEFAULT now(),

  CONSTRAINT vendor_not_own_primary CHECK (primary_vendor_id IS DISTINCT FROM id)
);

CREATE INDEX vendors_primary_idx ON vendors(primary_vendor_id);
CREATE INDEX vendors_tradeable_idx ON vendors(id) WHERE primary_vendor_id IS NOT NULL;

-- ════════════════════════════════════════════════════════════════════════════
-- PAYMENT NUMBERING
-- ════════════════════════════════════════════════════════════════════════════
-- Creator keeps FOUR parallel series in one Auto_Numbers singleton:
--   EKS/PY/…  ·  EKS/Haewaya/…  ·  REFUND-stay-…  ·  EKS/API/…
--
-- 🔴 LIVE DEFECT, verified 13-Aug-2026 on serv_ekostay_expense.payouts:
--    233 payment numbers are shared by 494 rows. 229 of those are Haewaya.
--    They are NOT split legs of one payment — 'EKS/Haewaya/12539' covers six
--    different villas, six categories and six dates spanning two weeks.
--    The provider-sync counter is colliding, and it is STILL COLLIDING:
--    fresh duplicates dated 08, 10, 12 and 13-Aug-2026.
--
--    This is why Creator added Sync_Locks as a mutex — and why the mutex is
--    not sufficient, since it guards one transaction_id rather than the counter.
--
-- Consequence for the schema: payment_no CANNOT be a primary key, and a plain
-- UNIQUE would reject 494 live rows at import. Instead:
--   · a sequence per series, allocated by the database, so the app cannot race
--   · UNIQUE (series, seq) — collision becomes impossible going forward
--   · the legacy display number kept verbatim, non-unique, for traceability

CREATE TABLE payment_series (
  code          TEXT PRIMARY KEY,          -- 'PY' | 'HAEWAYA' | 'REFUND' | 'API'
  prefix        TEXT NOT NULL,             -- 'EKS/PY/' etc.
  description   TEXT NOT NULL,
  next_value    BIGINT NOT NULL DEFAULT 1,
  is_external   BOOLEAN NOT NULL DEFAULT FALSE
);

INSERT INTO payment_series (code, prefix, description, is_external) VALUES
  ('PY',      'EKS/PY/',        'Manual and bill-derived payments',        FALSE),
  ('HAEWAYA', 'EKS/Haewaya/',   'Provider sync — collides in Creator',     TRUE),
  ('REFUND',  'REFUND-stay-',   'Guest stay refunds',                      TRUE),
  ('API',     'EKS/API/',       'External API payments',                   TRUE);

-- ════════════════════════════════════════════════════════════════════════════
-- BILLS
-- ════════════════════════════════════════════════════════════════════════════

CREATE TYPE bill_status AS ENUM (
  'Draft', 'Paid', 'Partially Paid', 'Overdue', 'Payment InProgress', 'Overpaid'
);
COMMENT ON TYPE bill_status IS
  'Creator spelling preserved: "Payment InProgress" (no space, capital P). It is a key downstream — normalise at display only.';

CREATE TABLE bills (
  id                BIGSERIAL PRIMARY KEY,
  creator_id        TEXT UNIQUE,
  bill_no           TEXT NOT NULL,
  bill_date         DATE NOT NULL,
  due_date          DATE,
  vendor_id         BIGINT NOT NULL REFERENCES vendors(id),
  location_id       BIGINT REFERENCES locations(id),
  coa_id            BIGINT NOT NULL REFERENCES chart_of_accounts(id),
  booking_no        TEXT,                        -- fb.Booking, cross-app
  gst_needed        BOOLEAN NOT NULL DEFAULT FALSE,
  tds_rate_id       BIGINT REFERENCES tds_rates(id),

  gross_amount      money_inr NOT NULL DEFAULT 0,   -- Creator: Amount
  gst_amount        money_inr NOT NULL DEFAULT 0,
  tds_amount        money_inr NOT NULL DEFAULT 0,
  invoice_amount    money_inr NOT NULL DEFAULT 0,   -- Creator: Total_Amount
  paid_amount       money_inr NOT NULL DEFAULT 0,   -- currency HERE; a CHECKBOX
                                                    -- on Payment (defect 27)
  adjusted_amount   NUMERIC(16,2) NOT NULL DEFAULT 0,

  -- Bills: payable = invoice − tds − paid + adjusted.
  -- Payment computes a DIFFERENT quantity under the same label — it omits the
  -- paid term. Both are correct for their own screen; the distinction lives in
  -- the column name so nobody reconciles the two expecting a match
  -- (OPEN_QUESTIONS.md §A4).
  payable_amount    money_inr GENERATED ALWAYS AS
                      (invoice_amount - tds_amount - paid_amount + adjusted_amount) STORED,

  split_equally     BOOLEAN NOT NULL DEFAULT FALSE,
  status            bill_status NOT NULL DEFAULT 'Draft',
  ca_email          CITEXT,
  books_id          TEXT,
  created_by        TEXT,
  created_at        TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at        TIMESTAMPTZ NOT NULL DEFAULT now(),

  CONSTRAINT bill_paid_not_negative CHECK (paid_amount >= 0),
  CONSTRAINT bill_due_after_bill_date CHECK (due_date IS NULL OR due_date >= bill_date)
);

CREATE INDEX bills_vendor_idx ON bills(vendor_id);
CREATE INDEX bills_status_idx ON bills(status);
CREATE INDEX bills_payable_idx ON bills(status) WHERE status <> 'Paid';
CREATE UNIQUE INDEX bills_no_vendor_idx ON bills(lower(bill_no), vendor_id);

-- many-to-many: a bill spans villas, categories and cycles
CREATE TABLE bill_villas (
  bill_id  BIGINT NOT NULL REFERENCES bills(id) ON DELETE CASCADE,
  villa_id BIGINT NOT NULL REFERENCES villas(id),
  PRIMARY KEY (bill_id, villa_id)
);
CREATE TABLE bill_item_categories (
  bill_id          BIGINT NOT NULL REFERENCES bills(id) ON DELETE CASCADE,
  item_category_id BIGINT NOT NULL REFERENCES item_categories(id),
  PRIMARY KEY (bill_id, item_category_id)
);
CREATE TABLE bill_billing_cycles (
  bill_id          BIGINT NOT NULL REFERENCES bills(id) ON DELETE CASCADE,
  billing_cycle_id BIGINT NOT NULL REFERENCES billing_cycles(id),
  PRIMARY KEY (bill_id, billing_cycle_id)
);

-- ── bill split legs ─────────────────────────────────────────────────────────
-- One row per villa × billing cycle × item category — the §5.1 cross product.
--
-- The Backend_* triplet is a RUNNING UNPAID BALANCE per leg, not a display
-- variant (§B7): reset to total when nothing is paid, decremented on each
-- payment, added back on reversal.

CREATE TABLE bill_splits (
  id                    BIGSERIAL PRIMARY KEY,
  bill_id               BIGINT NOT NULL REFERENCES bills(id) ON DELETE CASCADE,
  villa_id              BIGINT NOT NULL REFERENCES villas(id),
  billing_cycle_id      BIGINT REFERENCES billing_cycles(id),
  item_category_id      BIGINT REFERENCES item_categories(id),

  gross_amount          money_inr NOT NULL DEFAULT 0,
  gst_amount            money_inr NOT NULL DEFAULT 0,
  tds_amount            money_inr NOT NULL DEFAULT 0,
  total_amount          money_inr NOT NULL DEFAULT 0,

  backend_total_amount  money_inr NOT NULL DEFAULT 0,
  backend_gst_amount    money_inr NOT NULL DEFAULT 0,
  backend_tds_amount    money_inr NOT NULL DEFAULT 0,

  percent               NUMERIC(6,3),
  partial_paid          BOOLEAN NOT NULL DEFAULT FALSE,

  -- the cross product is unique by construction
  UNIQUE (bill_id, villa_id, billing_cycle_id, item_category_id),
  -- the unpaid balance can never exceed the leg it tracks
  CONSTRAINT split_backend_within_total
    CHECK (backend_total_amount <= total_amount + 0.01),
  CONSTRAINT split_backend_not_negative
    CHECK (backend_total_amount >= -0.01)
);

CREATE INDEX bill_splits_bill_idx ON bill_splits(bill_id);
CREATE INDEX bill_splits_villa_cycle_idx ON bill_splits(villa_id, billing_cycle_id);

COMMIT;
