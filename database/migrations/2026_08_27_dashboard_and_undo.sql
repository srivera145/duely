-- Dashboard reporting support, and undoable actions.

ALTER TABLE invoices
    -- How an invoice came to be marked paid. Distinguishing a manual tick from
    -- a payment-provider webhook matters once both exist, and the dashboard
    -- reports recovery differently for each.
    ADD COLUMN paid_source ENUM('manual','stripe','import','reply','unknown') NULL AFTER paid_at,

    -- The dashboard sums and averages over paid_at inside a date window; this
    -- keeps that off a full table scan.
    ADD KEY idx_invoices_tenant_paid (tenant_id, status, paid_at);

-- A short-lived record of what a state change replaced, so it can be put back.
--
-- "Mark paid" is one click and stops a live chase, so it needs to be reversible
-- for a few seconds. Storing the prior state explicitly is more honest than
-- trying to infer it later: by the time someone hits undo, the chase may have
-- been advanced by a worker.
CREATE TABLE IF NOT EXISTS undo_actions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    user_id INT NULL,
    token CHAR(32) NOT NULL,
    action VARCHAR(64) NOT NULL,
    subject_type VARCHAR(64) NOT NULL,
    subject_id INT NOT NULL,
    -- JSON snapshot of the columns this action changed.
    snapshot TEXT NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_undo_actions_tenant FOREIGN KEY (tenant_id) REFERENCES organizations(id) ON DELETE CASCADE,
    CONSTRAINT fk_undo_actions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY uniq_undo_actions_token (tenant_id, token),
    KEY idx_undo_actions_sweep (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
