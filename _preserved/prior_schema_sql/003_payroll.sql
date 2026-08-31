-- ════════════════════════════════════════════════════════════════════════════
-- Ekostay Accounts — payroll and schedules. Migration 003.
--
-- THE CENTRAL REQUIREMENT (OPEN_QUESTIONS.md §A5, context doc §11.4)
--   Every rate, band, ceiling and basis is VERSIONED CONFIGURATION with an
--   effective date, and each payslip records which version produced it.
--
--   Creator has none of this. Its rates are constants in Deluge, so a change
--   silently reinterprets history: §11.5 shows Ahmed's June payout computed on
--   a total of ₹23,200 while his header now reads ₹25,000 — the row is stale
--   and there is NO RECORD of what the terms were.
--
--   With versioned config, a correction is a new row. Historical payslips stay
--   explicable because the version that produced them is still on file.
-- ════════════════════════════════════════════════════════════════════════════

BEGIN;

-- ════════════════════════════════════════════════════════════════════════════
-- PAYROLL CONFIGURATION
-- ════════════════════════════════════════════════════════════════════════════
-- Mirrors payroll/payroll.js CONFIG_V1 exactly. The engine is the reference
-- implementation and its 104 tests pin it to live June/July figures; this table
-- is where the values live so they can change without a deploy.

CREATE TABLE payroll_configs (
  id                  BIGSERIAL PRIMARY KEY,
  version             TEXT NOT NULL UNIQUE,
  effective_from      DATE,                 -- NULL = open start
  effective_to        DATE,                 -- NULL = current
  published_at        TIMESTAMPTZ,
  published_by        TEXT,
  note                TEXT,

  -- ── base pay banding ──────────────────────────────────────────────────────
  basic_band_low      money_inr NOT NULL,
  basic_low           money_inr NOT NULL,
  basic_band_high     money_inr NOT NULL,
  basic_high          money_inr NOT NULL,
  basic_pct_above     NUMERIC(6,4) NOT NULL,

  -- ── HRA ───────────────────────────────────────────────────────────────────
  hra_balance_up_to   money_inr NOT NULL,
  hra_metro_pct       NUMERIC(6,4) NOT NULL,
  hra_non_metro_pct   NUMERIC(6,4) NOT NULL,
  metro_locations     TEXT[] NOT NULL,

  -- ── provident fund ────────────────────────────────────────────────────────
  pf_pct              NUMERIC(6,4) NOT NULL,
  pf_monthly_cap      money_inr NOT NULL,
  pf_wage_ceiling     money_inr NOT NULL,
  epf_share           NUMERIC(6,4) NOT NULL,
  eps_share           NUMERIC(6,4) NOT NULL,
  edli_pct            NUMERIC(6,4) NOT NULL,
  -- DEVIATION 3: Creator applies 0.005 × 2. Statutory rate is 0.5%, i.e. a
  -- multiplier of 1. Over-accrues ~₹72.50/month per enrolled employee at a
  -- ₹15,000 base. Copied as built; set to 1 to see the statutory figure.
  edli_multiplier     NUMERIC(4,2) NOT NULL DEFAULT 2,

  -- ── ESIC ──────────────────────────────────────────────────────────────────
  esic_employee_pct   NUMERIC(6,4) NOT NULL,
  esic_employer_pct   NUMERIC(6,4) NOT NULL,
  esic_wage_ceiling   money_inr NOT NULL,
  -- DEVIATION 2: Creator computes ESIC on BASE pay. Statute applies it to gross
  -- wages. Under-contributes ~₹26.25/month per enrolled employee at ₹18,000.
  -- Note the asymmetry in source, reproduced by the engine: the eligibility
  -- GATE tests prorated gross against the ceiling, while the BASIS is base pay.
  esic_basis          TEXT NOT NULL DEFAULT 'base' CHECK (esic_basis IN ('base','gross')),

  -- ── professional tax ──────────────────────────────────────────────────────
  -- DEVIATION 4: Creator assesses PT on the PRORATED salary. PT is statutorily
  -- a function of monthly salary, so any part month can drop below a slab and
  -- zero the liability entirely.
  pt_basis            TEXT NOT NULL DEFAULT 'prorated' CHECK (pt_basis IN ('prorated','monthly')),
  pt_age_exemption    SMALLINT NOT NULL DEFAULT 65,

  created_at          TIMESTAMPTZ NOT NULL DEFAULT now(),

  -- DEVIATION 1 — NEGATIVE HRA, and the one deviation that is simply broken.
  -- A total in [basic_band_low+1, basic_high-1] — ₹21,001..₹21,099 — takes the
  -- fixed basic_high of ₹21,100, which EXCEEDS the total, so HRA computes
  -- negative. At ₹21,050: HRA = −₹50.
  --
  -- This is an unfinished band, not a rule anyone chose. It is reproduced
  -- because the current system does it, but a NEW config version cannot be
  -- published with the hole still open — closing it is a deliberate act with a
  -- date attached, which is exactly what versioning is for.
  CONSTRAINT payroll_band_gap_must_be_closed_on_publish CHECK (
    published_at IS NULL OR basic_high <= basic_band_low
  ),
  CONSTRAINT payroll_window_ordered CHECK (
    effective_from IS NULL OR effective_to IS NULL OR effective_to >= effective_from
  )
);

COMMENT ON CONSTRAINT payroll_band_gap_must_be_closed_on_publish ON payroll_configs IS
  'The as-built config (v1) is stored UNPUBLISHED precisely because it fails this check: basic_high 21100 > basic_band_low 21000 leaves a 99-rupee window where base exceeds total and HRA goes negative. Keeping v1 unpublished records the defect without letting a new version inherit it silently.';

-- The as-built configuration. Deliberately left unpublished — see above.
INSERT INTO payroll_configs (
  version, effective_from, note,
  basic_band_low, basic_low, basic_band_high, basic_high, basic_pct_above,
  hra_balance_up_to, hra_metro_pct, hra_non_metro_pct, metro_locations,
  pf_pct, pf_monthly_cap, pf_wage_ceiling, epf_share, eps_share,
  edli_pct, edli_multiplier,
  esic_employee_pct, esic_employer_pct, esic_wage_ceiling, esic_basis,
  pt_basis, pt_age_exemption
) VALUES (
  'v1-as-built-2026-08', NULL,
  'Zoho Creator as at 13-Aug-2026. Reproduces four statutory deviations exactly. Unpublished: fails the band-gap check, which is the negative-HRA defect.',
  21000, 14500, 40000, 21100, 0.55,
  31650, 0.50, 0.40,
  ARRAY['Delhi','Mumbai','Head Office Central','Bengaluru','Kolkata','Chennai',
        'Hyderabad','Ahmedabad','Pune'],
  0.12, 1800, 15000, 3.67, 8.33,
  0.005, 2,
  0.0075, 0.0325, 21000, 'base',
  'prorated', 65
);

-- ── professional tax slabs ──────────────────────────────────────────────────
-- Creator hardcodes these as nested if/else per state. As data they can be
-- corrected without a deploy, and a state with no rule is distinguishable from
-- a state that levies nothing — which Creator cannot express: it has no branch
-- for Goa or Uttarakhand and simply leaves PT at its initialised 0.

CREATE TABLE pt_slabs (
  id                BIGSERIAL PRIMARY KEY,
  config_id         BIGINT NOT NULL REFERENCES payroll_configs(id) ON DELETE CASCADE,
  state             TEXT NOT NULL,
  is_levied         BOOLEAN NOT NULL DEFAULT TRUE,
  -- 'monthly' compares the monthly salary; 'half_yearly' compares salary × 6,
  -- as Tamil Nadu and Kerala do
  comparison        TEXT NOT NULL DEFAULT 'monthly'
                      CHECK (comparison IN ('monthly','half_yearly')),
  gender            TEXT CHECK (gender IN ('Male','Female')),   -- NULL = any
  upper_bound       money_inr,                                  -- NULL = no ceiling
  amount            money_inr NOT NULL,
  february_amount   money_inr,                                  -- NULL = same as amount
  note              TEXT,
  UNIQUE (config_id, state, gender, upper_bound)
);

CREATE INDEX pt_slabs_lookup_idx ON pt_slabs(config_id, state, gender);

-- Karnataka, per OnInputNumberofdaysworkedCE
INSERT INTO pt_slabs (config_id, state, comparison, gender, upper_bound, amount, february_amount, note)
SELECT id, 'Karnataka', 'monthly', NULL, v.ub, v.amt, v.feb, v.n
  FROM payroll_configs, (VALUES
    (25000::money_inr, 0::money_inr,   NULL::money_inr, 'nil to 25,000'),
    (41999,            150,            NULL,            '150 to 41,999'),
    (NULL,             200,            300,             '200 above, 300 in February')
  ) AS v(ub, amt, feb, n)
 WHERE version = 'v1-as-built-2026-08';

-- Maharashtra — gender-specific thresholds, plus the under-65 exemption which
-- the engine applies before any slab
INSERT INTO pt_slabs (config_id, state, comparison, gender, upper_bound, amount, february_amount, note)
SELECT id, 'Maharashtra', 'monthly', v.g, v.ub, v.amt, v.feb, v.n
  FROM payroll_configs, (VALUES
    ('Male'::TEXT,   7500::money_inr,  0::money_inr,   NULL::money_inr, 'men: nil to 7,500'),
    ('Male',         10000,            175,            NULL,            'men: 175 above 7,500'),
    ('Male',         NULL,             200,            300,             'men: 200 above 10,000'),
    ('Female',       25000,            0,              NULL,            'women: nil to 25,000'),
    ('Female',       NULL,             200,            300,             'women: 200 above 25,000')
  ) AS v(g, ub, amt, feb, n)
 WHERE version = 'v1-as-built-2026-08';

-- Tamil Nadu — half-yearly bands. Creator hardcodes the already-divided
-- monthly figures (22.50, 52.50, 170.83, 208.33); do NOT recompute them from
-- the half-yearly amount, because the source's rounding is what reaches payslips.
INSERT INTO pt_slabs (config_id, state, comparison, gender, upper_bound, amount, note)
SELECT id, 'Tamil Nadu', 'half_yearly', NULL, v.ub, v.amt, v.n
  FROM payroll_configs, (VALUES
    (21000::money_inr, 0::money_inr,  'nil'),
    (30000,            22.50,         'monthly equivalent, source-rounded'),
    (45000,            52.50,         'monthly equivalent, source-rounded'),
    (60000,            115.00,        'monthly equivalent'),
    (75000,            170.83,        'monthly equivalent, source-rounded'),
    (NULL,             208.33,        'top slab')
  ) AS v(ub, amt, n)
 WHERE version = 'v1-as-built-2026-08';

-- Kerala — half-yearly
INSERT INTO pt_slabs (config_id, state, comparison, gender, upper_bound, amount, note)
SELECT id, 'Kerala', 'half_yearly', NULL, v.ub, v.amt, 'half-yearly band'
  FROM payroll_configs, (VALUES
    (11999::money_inr, 0::money_inr), (17999, 20), (29999, 30), (44999, 50),
    (59999, 75), (74999, 100), (99999, 125), (124999, 166.67), (NULL, 208.33)
  ) AS v(ub, amt)
 WHERE version = 'v1-as-built-2026-08';

-- Nil-rated states, declared EXPLICITLY. Creator has no branch for these and
-- relies on PT staying at its initialised 0 — correct by accident. Declaring
-- them makes "no rule defined" distinguishable from "levies nothing".
INSERT INTO pt_slabs (config_id, state, is_levied, gender, upper_bound, amount, note)
SELECT id, s, FALSE, NULL, NULL, 0, 'no professional tax levied in this state'
  FROM payroll_configs, (VALUES ('Goa'), ('Uttarakhand')) AS v(s)
 WHERE version = 'v1-as-built-2026-08';

-- ════════════════════════════════════════════════════════════════════════════
-- SALARY STRUCTURES — additive periods
-- ════════════════════════════════════════════════════════════════════════════
-- §11.5 is the requirement here. Creator holds ONE current pay structure per
-- employee and overwrites it, so a historical payout row keeps stale component
-- values with no record of the terms that produced them.
--
-- Periods are additive: changing pay closes the current period and opens a new
-- one. A payslip then references the period in force, and remains explicable.

CREATE TABLE salary_periods (
  id                  BIGSERIAL PRIMARY KEY,
  employee_id         BIGINT NOT NULL REFERENCES employees(id) ON DELETE CASCADE,
  effective_from      DATE NOT NULL,
  effective_to        DATE,                    -- NULL = current

  total_amount        money_inr NOT NULL,
  calculation_mode    TEXT NOT NULL DEFAULT 'Automatic'
                        CHECK (calculation_mode IN ('Automatic','Manual')),
  -- In Automatic these derive from total via the engine's splitTotal(); in
  -- Manual the user types all three and they need not sum to the total.
  basic_amount        money_inr NOT NULL,
  hra_amount          money_inr NOT NULL,      -- CAN BE NEGATIVE — deviation 1
  cc_amount           money_inr NOT NULL,

  -- maintained by hand in Creator; consider deriving from wage ceilings
  pf_status           BOOLEAN NOT NULL DEFAULT FALSE,
  esic_status         BOOLEAN NOT NULL DEFAULT FALSE,

  location_id         BIGINT REFERENCES locations(id),
  state               TEXT NOT NULL,
  coa_id              BIGINT REFERENCES chart_of_accounts(id),
  bank_id             BIGINT REFERENCES chart_of_accounts(id),
  item_category_id    BIGINT REFERENCES item_categories(id),

  created_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
  created_by          TEXT,

  CONSTRAINT salary_period_ordered CHECK (
    effective_to IS NULL OR effective_to >= effective_from
  )
);

-- one open period per employee, and no overlapping closed ones
CREATE UNIQUE INDEX salary_periods_one_open
  ON salary_periods(employee_id) WHERE effective_to IS NULL;
CREATE INDEX salary_periods_employee_idx ON salary_periods(employee_id, effective_from DESC);

CREATE TABLE salary_period_villas (
  salary_period_id BIGINT NOT NULL REFERENCES salary_periods(id) ON DELETE CASCADE,
  villa_id         BIGINT NOT NULL REFERENCES villas(id),
  PRIMARY KEY (salary_period_id, villa_id)
);

-- ════════════════════════════════════════════════════════════════════════════
-- PAYSLIPS
-- ════════════════════════════════════════════════════════════════════════════
-- A payslip is IMMUTABLE once issued: every computed component is stored, not
-- derived on read, because the config or the pay structure may change later.
-- It carries the config version and the salary period that produced it, so it
-- stays reproducible.

CREATE TABLE payslips (
  id                    BIGSERIAL PRIMARY KEY,
  creator_id            TEXT UNIQUE,
  employee_id           BIGINT NOT NULL REFERENCES employees(id),
  salary_period_id      BIGINT NOT NULL REFERENCES salary_periods(id),
  payroll_config_id     BIGINT NOT NULL REFERENCES payroll_configs(id),
  billing_cycle_id      BIGINT NOT NULL REFERENCES billing_cycles(id),

  payment_date          DATE,
  days_in_month         SMALLINT NOT NULL,
  days_worked           SMALLINT NOT NULL,

  -- earnings, each prorated INDEPENDENTLY from its monthly value. Creator does
  -- not derive salary as basic+hra+cc, so the four need not sum — reproduced.
  salary                money_inr NOT NULL,
  basic                 money_inr NOT NULL,
  hra                   money_inr NOT NULL,   -- may be negative
  cc                    money_inr NOT NULL,

  -- statutory
  employee_pf           money_inr NOT NULL DEFAULT 0,
  employer_pf           money_inr NOT NULL DEFAULT 0,
  edli                  money_inr NOT NULL DEFAULT 0,
  employee_esic         money_inr NOT NULL DEFAULT 0,
  employer_esic         money_inr NOT NULL DEFAULT 0,
  esic_basis_used       TEXT,
  esic_basis_amount     money_inr,
  professional_tax      money_inr NOT NULL DEFAULT 0,
  pt_assessed_on        money_inr,

  -- recoveries. Advance and loan flow in from the STAFF ADVANCE / STAFF LOAN
  -- schedules (§B8), matched on vendor + billing cycle.
  staff_advance         money_inr NOT NULL DEFAULT 0,
  staff_loan            money_inr NOT NULL DEFAULT 0,
  penalty               money_inr NOT NULL DEFAULT 0,
  -- ADDED, not deducted — it is a reimbursement
  other_expenses        money_inr NOT NULL DEFAULT 0,

  payable_amount        money_inr NOT NULL,
  -- Creator's "CTC" is built UP from payable with employer contributions AND
  -- recoveries added back. It is not cost-to-company; anyone reading a payslip
  -- will misinterpret it. Name preserved for traceability, meaning recorded.
  ctc_amount            money_inr NOT NULL,

  -- Payable is floored at zero and Creator DROPS the excess — it is not carried
  -- forward. Recording it at least makes the loss visible.
  was_floored           BOOLEAN NOT NULL DEFAULT FALSE,
  unrecovered_shortfall money_inr NOT NULL DEFAULT 0,

  payment_id            BIGINT REFERENCES vendor_payments(id),
  status                TEXT,
  issued_at             TIMESTAMPTZ,
  issued_by             TEXT,
  created_at            TIMESTAMPTZ NOT NULL DEFAULT now(),

  UNIQUE (employee_id, billing_cycle_id),

  CONSTRAINT payslip_days_sane CHECK (
    days_worked BETWEEN 0 AND days_in_month AND days_in_month BETWEEN 28 AND 31
  ),
  CONSTRAINT payslip_payable_not_negative CHECK (payable_amount >= 0),
  CONSTRAINT payslip_shortfall_implies_floored CHECK (
    (unrecovered_shortfall = 0) OR was_floored
  )
);

COMMENT ON COLUMN payslips.ctc_amount IS
  'Creator name: CTC. Built up from payable with employer contributions and recoveries (advance + loan, but NOT penalty) added back. Not cost-to-company. Rename in any user-facing view; do not change the arithmetic.';

CREATE INDEX payslips_employee_idx ON payslips(employee_id, billing_cycle_id);
CREATE INDEX payslips_cycle_idx ON payslips(billing_cycle_id);
CREATE INDEX payslips_config_idx ON payslips(payroll_config_id);
CREATE INDEX payslips_floored_idx ON payslips(was_floored) WHERE was_floored;

-- Once issued, a payslip is frozen. Correcting one means a new payslip that
-- references it, not an UPDATE — the same principle as reversing a payment
-- rather than deleting it.
CREATE OR REPLACE FUNCTION payslips_immutable_once_issued() RETURNS TRIGGER AS $$
BEGIN
  IF OLD.issued_at IS NOT NULL THEN
    RAISE EXCEPTION
      'Payslip % was issued at % and cannot be modified. Issue a correcting '
      'payslip that references it instead.', OLD.id, OLD.issued_at;
  END IF;
  RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER payslips_no_edit_after_issue
  BEFORE UPDATE ON payslips
  FOR EACH ROW EXECUTE FUNCTION payslips_immutable_once_issued();

-- ════════════════════════════════════════════════════════════════════════════
-- SCHEDULED PAYMENTS
-- ════════════════════════════════════════════════════════════════════════════
-- Parent template plus generated instalments.
--
-- All 813 live parents sit at 'Click to Proceed' — an instruction rendered as
-- state — while their instalments reach Paid. The parent status never advances,
-- so the child's is the operative one (§10.4). Reproduced; the parent column is
-- documented as decorative rather than quietly repurposed.

CREATE TABLE payment_schedules (
  id                BIGSERIAL PRIMARY KEY,
  creator_id        TEXT UNIQUE,
  vendor_id         BIGINT REFERENCES vendors(id),
  location_id       BIGINT REFERENCES locations(id),
  item_category_id  BIGINT REFERENCES item_categories(id),
  master_category_id BIGINT REFERENCES master_categories(id),
  coa_id            BIGINT NOT NULL REFERENCES chart_of_accounts(id),
  bank_id           BIGINT REFERENCES chart_of_accounts(id),

  start_date        DATE NOT NULL,
  end_date          DATE NOT NULL,
  due_date          DATE NOT NULL,      -- the day of month each instalment falls due
  payment_date      DATE NOT NULL,
  amount            money_inr NOT NULL,
  tds_rate_id       BIGINT REFERENCES tds_rates(id),
  gst_id            BIGINT REFERENCES taxes(id),

  payment_type      TEXT NOT NULL CHECK (payment_type IN ('Payment','Bill & Payment')),
  status            TEXT NOT NULL DEFAULT 'Click to Proceed'
                      CHECK (status IN ('Due','Click to Proceed')),

  created_at        TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at        TIMESTAMPTZ NOT NULL DEFAULT now(),

  CONSTRAINT schedule_window_ordered CHECK (end_date >= start_date),
  CONSTRAINT schedule_due_in_window CHECK (due_date BETWEEN start_date AND end_date),
  CONSTRAINT schedule_payment_before_due CHECK (payment_date <= due_date)
);

COMMENT ON COLUMN payment_schedules.status IS
  'Decorative. All 813 live schedules sit at "Click to Proceed" including ones overdue since June, while their instalments reach Paid. The instalment status is the operative one.';

CREATE TABLE payment_schedule_villas (
  schedule_id BIGINT NOT NULL REFERENCES payment_schedules(id) ON DELETE CASCADE,
  villa_id    BIGINT NOT NULL REFERENCES villas(id),
  PRIMARY KEY (schedule_id, villa_id)
);

CREATE TABLE scheduled_instalments (
  id                BIGSERIAL PRIMARY KEY,
  creator_id        TEXT UNIQUE,
  schedule_id       BIGINT NOT NULL REFERENCES payment_schedules(id) ON DELETE CASCADE,
  billing_cycle_id  BIGINT REFERENCES billing_cycles(id),

  due_date          DATE NOT NULL,
  scheduled_date    DATE NOT NULL,      -- Creator: Date_field
  amount            money_inr NOT NULL,

  -- payroll deductions. These live in FIVE places across Creator —
  -- Payments_Scheduled, Payment, Expenses_Bills, Salary_Payout_Schedule and
  -- Salary_Payouts. Consolidating them is a deliberate decision still open;
  -- reproduced here so the import is lossless.
  loan_deduction    money_inr NOT NULL DEFAULT 0,
  advance_deduction money_inr NOT NULL DEFAULT 0,
  penalty           money_inr NOT NULL DEFAULT 0,
  -- Creator's column is No_Of_Days_Not_Worked, but the label reads "Number of
  -- Days Worked" and the arithmetic subtracts it from the month length — so it
  -- means days WORKED (§10.3). RENAMED HERE; the maths is untouched. NULL means
  -- "no deduction", not "zero days worked".
  days_worked       SMALLINT,
  days_deduction    money_inr NOT NULL DEFAULT 0,
  pf_amount         money_inr NOT NULL DEFAULT 0,
  pt_amount         money_inr NOT NULL DEFAULT 0,
  esic_amount       money_inr NOT NULL DEFAULT 0,
  excess_amount     money_inr NOT NULL DEFAULT 0,   -- ADDED, not deducted

  due_amount        money_inr NOT NULL,
  -- ⚠️ GST and TDS apply to due_amount — AFTER deductions. Bills and Payment
  -- apply them to gross. A genuine three-way inconsistency, reproduced (§10.3).
  total_due         money_inr,

  remarks           TEXT,
  status            TEXT NOT NULL DEFAULT 'Due',
  payment_id        BIGINT REFERENCES vendor_payments(id),

  created_at        TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at        TIMESTAMPTZ NOT NULL DEFAULT now(),

  UNIQUE (schedule_id, due_date),

  -- Creator's own validation, and a good one: any variance from Amount requires
  -- Remarks. Kept as a hard constraint.
  CONSTRAINT instalment_variance_needs_remarks CHECK (
    round(due_amount, 2) = round(amount, 2)
    OR (remarks IS NOT NULL AND length(trim(remarks)) > 0)
  )
);

CREATE INDEX si_schedule_idx ON scheduled_instalments(schedule_id);
CREATE INDEX si_due_date_idx ON scheduled_instalments(due_date);
CREATE INDEX si_pending_idx ON scheduled_instalments(due_date, status)
  WHERE status <> 'Paid';

COMMENT ON COLUMN scheduled_instalments.days_worked IS
  'Creator column: No_Of_Days_Not_Worked — a misnomer. Its label and its arithmetic both treat the value as days WORKED. Renamed; maths unchanged. NULL means no days deduction, not zero days worked.';

COMMENT ON COLUMN scheduled_instalments.total_due IS
  'due_amount + GST − TDS, where GST and TDS apply to the NET. Bills and Payment apply them to gross. Nullable because Creator computes it only on specific field inputs, leaving it null on most live records.';

COMMIT;
