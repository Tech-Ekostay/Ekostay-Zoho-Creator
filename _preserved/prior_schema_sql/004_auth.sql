-- ═══════════════════════════════════════════════════════════════════════════
-- 004_auth.sql — credentials and sessions
--
-- PostgreSQL 16. Creator has no equivalent to reproduce: it delegates identity
-- to Zoho's portal, and `Access.Accounts()` only *grants* an already-
-- authenticated Zoho account a role (Admin.ds:1178). So there is no legacy
-- behaviour to copy here — this is new, and the standard rules apply rather
-- than copy-as-built.
--
-- Shape follows serv_ekostay_expense.users on the live server (email,
-- password_hash, name, role, active, last_login_at) so the two systems stay
-- recognisable to whoever maintains both. Differences are deliberate and noted.
-- ═══════════════════════════════════════════════════════════════════════════

-- ── credentials ────────────────────────────────────────────────────────────
-- One row per employee who can sign in. Separate from `employees` on purpose:
-- most employees in Employee_Master (caretakers, F&B staff) never log in, and a
-- nullable password_hash on a 200-row employee table invites the bug where
-- "no password set" and "any password accepted" become the same state.
--
-- The FK is the identity. A login resolves to an employee, and the employee's
-- role_id decides permissions — so there is exactly one place roles live
-- (roles/role_permissions from 001_core.sql), unlike the expense tracker's
-- free-text `role varchar(50)`.

CREATE TABLE auth_credentials (
  id              BIGSERIAL PRIMARY KEY,
  employee_id     BIGINT NOT NULL UNIQUE REFERENCES employees(id) ON DELETE CASCADE,

  -- Argon2id, or bcrypt. Never a bare hash: the string carries its own
  -- algorithm and parameters ($argon2id$v=19$m=...$...) so the cost can be
  -- raised later and old hashes still verify.
  password_hash   TEXT NOT NULL,
  CONSTRAINT password_hash_is_a_phc_string
    CHECK (password_hash ~ '^\$(argon2(id|i|d)|2[aby])\$'),

  -- Forces a change on next login. Set on every admin-created account, so a
  -- password chosen by someone else can never become a lasting credential.
  must_change_password BOOLEAN NOT NULL DEFAULT TRUE,

  -- Throttling. Cleared on success.
  failed_attempts INT NOT NULL DEFAULT 0 CHECK (failed_attempts >= 0),
  locked_until    TIMESTAMPTZ,

  last_login_at   TIMESTAMPTZ,
  password_set_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);

COMMENT ON TABLE auth_credentials IS
  'Sign-in credentials. Separate from employees because most employees never log in. Role comes from employees.role_id, never from here.';
COMMENT ON COLUMN auth_credentials.password_hash IS
  'PHC string format, algorithm and parameters included, so cost can be raised without invalidating old hashes.';

-- ── sessions ───────────────────────────────────────────────────────────────
-- Server-side sessions rather than stateless JWTs. The deciding factor is
-- revocation: an accounts app must be able to end a session the moment someone
-- leaves, and a self-contained token cannot be withdrawn before it expires.
-- Deactivating an employee should also cut their sessions immediately, which
-- ON DELETE CASCADE plus an is_active check gives for free.

CREATE TABLE auth_sessions (
  -- The token itself is never stored. This is a SHA-256 of it, so a database
  -- read cannot be replayed as a login.
  token_hash    BYTEA PRIMARY KEY CHECK (octet_length(token_hash) = 32),

  employee_id   BIGINT NOT NULL REFERENCES employees(id) ON DELETE CASCADE,
  issued_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
  expires_at    TIMESTAMPTZ NOT NULL,
  last_seen_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
  ip_address    INET,
  user_agent    TEXT,

  -- Set when signed out or revoked. Kept rather than deleted so "who was
  -- signed in when this payment changed" stays answerable.
  revoked_at    TIMESTAMPTZ,
  revoked_reason TEXT,

  CONSTRAINT session_expires_after_issue CHECK (expires_at > issued_at),
  CONSTRAINT revoked_needs_reason
    CHECK (revoked_at IS NULL OR revoked_reason IS NOT NULL)
);

CREATE INDEX auth_sessions_employee ON auth_sessions(employee_id);
-- Only live sessions are ever looked up by expiry.
CREATE INDEX auth_sessions_live ON auth_sessions(expires_at)
  WHERE revoked_at IS NULL;

COMMENT ON TABLE auth_sessions IS
  'Server-side sessions. Chosen over JWT for revocation: an accounts app must be able to end a session immediately.';
COMMENT ON COLUMN auth_sessions.token_hash IS
  'SHA-256 of the session token. The token itself is never stored, so a database read cannot be replayed as a login.';

-- ── login attempts ─────────────────────────────────────────────────────────
-- Every attempt, successful or not. Two reasons: rate-limiting needs a history
-- per email AND per IP, and "17 payments were destroyed and nobody knows by
-- whom" is a problem this project already has once (§7.6). Attempts are logged
-- against the email string, not a FK, because failures include emails that
-- match no account.

CREATE TABLE auth_login_attempts (
  id          BIGSERIAL PRIMARY KEY,
  email       CITEXT NOT NULL,
  employee_id BIGINT REFERENCES employees(id) ON DELETE SET NULL,
  succeeded   BOOLEAN NOT NULL,
  -- Why it failed. Never shown to the user — the login screen must not reveal
  -- whether an email exists.
  failure     TEXT CHECK (failure IN
                ('no_such_user','bad_password','inactive','locked','no_role')),
  ip_address  INET,
  user_agent  TEXT,
  attempted_at TIMESTAMPTZ NOT NULL DEFAULT now(),

  CONSTRAINT failure_iff_not_succeeded
    CHECK ((succeeded AND failure IS NULL) OR (NOT succeeded AND failure IS NOT NULL))
);

CREATE INDEX auth_attempts_email_time ON auth_login_attempts(email, attempted_at DESC);
CREATE INDEX auth_attempts_ip_time    ON auth_login_attempts(ip_address, attempted_at DESC);

COMMENT ON COLUMN auth_login_attempts.failure IS
  'Recorded for rate-limiting and audit. Never surfaced to the client: the login response must not reveal whether an email exists.';

-- ── password reset ─────────────────────────────────────────────────────────
CREATE TABLE auth_password_resets (
  token_hash  BYTEA PRIMARY KEY CHECK (octet_length(token_hash) = 32),
  employee_id BIGINT NOT NULL REFERENCES employees(id) ON DELETE CASCADE,
  requested_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  expires_at  TIMESTAMPTZ NOT NULL,
  used_at     TIMESTAMPTZ,
  requested_ip INET,
  CONSTRAINT reset_expires_after_request CHECK (expires_at > requested_at)
);

CREATE INDEX auth_resets_employee ON auth_password_resets(employee_id);

-- A reset token is single-use. Enforced here rather than in application code
-- because a reused reset token is a full account takeover.
CREATE UNIQUE INDEX auth_resets_one_live_per_employee
  ON auth_password_resets(employee_id)
  WHERE used_at IS NULL;

COMMENT ON INDEX auth_resets_one_live_per_employee IS
  'At most one unused reset per employee, so requesting a new link invalidates the old one.';
