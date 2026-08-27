-- Duely — the operator panel, and the record of what the operator did.
--
-- The panel can open a customer's data and sign in as their user. That is real
-- access, and what makes it defensible rather than surveillance is entirely in
-- these two tables: the reason has to be stated, the record cannot be erased,
-- and the customer can read it themselves.

-- Every super-admin action, including reads.
--
-- Deliberately separate from `activity_log`. Two reasons. `activity_log` is
-- written by ordinary product code all over the application, so anything with a
-- database handle can already insert into it; the audit of the operator should
-- not share a table with rows the operator's own features write. And the
-- customer-visible feed is built from `activity_log` -- support entries are
-- mirrored there on purpose, but this is the copy that has to survive.
--
-- APPEND-ONLY. There is no UPDATE and no DELETE against this table anywhere in
-- the codebase, and a test asserts that. The operator being audited must not be
-- able to erase the audit; a trail its subject can edit is not a trail.
CREATE TABLE IF NOT EXISTS support_access_log (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,

    -- Who. Never nullable: an unattributable entry is not an audit entry.
    super_admin_user_id INT NOT NULL,
    super_admin_email VARCHAR(255) NOT NULL,

    -- What was touched. Both nullable because some actions are platform-wide
    -- (viewing the operations page) rather than aimed at one account.
    tenant_id INT NULL,
    target_user_id INT NULL,

    -- 'view' for a page load, 'action' for a mutation, 'impersonation' for a
    -- session start or end. Read access is the access that matters here, so it
    -- is recorded with the same weight as a change.
    kind ENUM('view', 'action', 'impersonation') NOT NULL,
    action VARCHAR(100) NOT NULL,

    -- Required before tenant data opens. Ten characters minimum, enforced in
    -- the service rather than here so the user gets a message rather than a
    -- database error.
    reason VARCHAR(500) NULL,

    metadata TEXT NULL,
    ip_address VARCHAR(45) NULL,
    created_at DATETIME NOT NULL,

    KEY idx_support_log_tenant (tenant_id, created_at),
    KEY idx_support_log_admin (super_admin_user_id, created_at),
    KEY idx_support_log_kind (kind, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- An impersonation session.
--
-- Not a normal user session and never mints one: `Session::get('user_id')` is
-- untouched, and the impersonated identity lives here, looked up per request.
-- That means the session cannot outlive this row, and ending it is a database
-- write rather than a hope that a cookie was cleared.
--
-- Hard expiry, no renewal column on purpose. Carrying on means a new row with a
-- new reason, which gets recorded like the first one.
CREATE TABLE IF NOT EXISTS impersonation_sessions (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,

    impersonator_user_id INT NOT NULL,
    target_user_id INT NOT NULL,
    tenant_id INT NULL,

    reason VARCHAR(500) NOT NULL,
    started_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    ended_at DATETIME NULL,

    ip_address VARCHAR(45) NULL,

    KEY idx_impersonation_active (impersonator_user_id, ended_at, expires_at),
    KEY idx_impersonation_target (target_user_id, started_at),
    CONSTRAINT fk_impersonation_impersonator FOREIGN KEY (impersonator_user_id)
        REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_impersonation_target FOREIGN KEY (target_user_id)
        REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Disabling an account is an administrative state, not a deletion. Kept on the
-- organization so re-enabling restores exactly what was there.
ALTER TABLE organizations
    ADD COLUMN disabled_at DATETIME NULL AFTER payment_link_mode,
    ADD COLUMN disabled_reason VARCHAR(255) NULL AFTER disabled_at;

-- Forcing a session reset invalidates every session issued before this moment.
-- A timestamp rather than a token so one write logs everybody out, including
-- sessions on machines nobody can reach.
ALTER TABLE users
    ADD COLUMN sessions_invalidated_at DATETIME NULL AFTER is_super_admin;
