-- Duely — collecting payment through Stripe Connect Standard.
--
-- Standard, deliberately. The user links their own Stripe account by OAuth,
-- funds settle straight into it, and they are the merchant of record. Express
-- and Custom would make EchoDial LLC liable for negative balances -- a
-- chargeback on a $3,200 invoice clawed back from the platform -- which is not
-- an exposure a solo operation should carry.
--
-- Everything here is optional and per workspace. A workspace that never
-- connects Stripe is untouched by all of it.

ALTER TABLE organizations
    ADD COLUMN stripe_account_id VARCHAR(255) NULL AFTER stripe_customer_id,
    ADD COLUMN stripe_charges_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER stripe_account_id,
    ADD COLUMN stripe_payouts_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER stripe_charges_enabled,
    ADD COLUMN stripe_account_connected_at DATETIME NULL AFTER stripe_payouts_enabled,
    ADD COLUMN stripe_account_last_checked_at DATETIME NULL AFTER stripe_account_connected_at,
    ADD UNIQUE KEY uniq_orgs_stripe_account (stripe_account_id);

-- A link Duely generated is replaceable; one the user pasted is theirs and is
-- never overwritten. Without this flag the two are indistinguishable.
ALTER TABLE invoices
    ADD COLUMN payment_url_is_generated TINYINT(1) NOT NULL DEFAULT 0 AFTER payment_url,
    ADD COLUMN stripe_payment_link_id VARCHAR(255) NULL AFTER payment_url_is_generated;

-- Connect events, kept apart from the subscription webhook's log. They arrive
-- on a different endpoint with a different signing secret and carry an
-- `account` field; sharing a table would mean sharing a replay surface.
CREATE TABLE IF NOT EXISTS connect_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    stripe_event_id VARCHAR(255) NOT NULL,
    stripe_account_id VARCHAR(255) NULL,
    tenant_id INT NULL,
    type VARCHAR(100) NOT NULL,
    status ENUM('processing', 'processed', 'ignored', 'failed') NOT NULL DEFAULT 'processing',
    error VARCHAR(500) NULL,
    payload MEDIUMTEXT NULL,
    received_at DATETIME NOT NULL,
    processed_at DATETIME NULL,

    UNIQUE KEY uniq_connect_event (stripe_event_id),
    KEY idx_connect_events_tenant (tenant_id, received_at),
    CONSTRAINT fk_connect_events_tenant FOREIGN KEY (tenant_id) REFERENCES organizations(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- What actually arrived. A payment is recorded whether or not it settles the
-- invoice, because a part payment has nowhere else to live: the invoice has one
-- amount and a binary open/paid status. See PaymentReceiver for why that is a
-- holding position rather than an oversight.
CREATE TABLE IF NOT EXISTS invoice_payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    invoice_id INT NOT NULL,

    stripe_event_id VARCHAR(255) NOT NULL,
    stripe_object_id VARCHAR(255) NULL,
    amount_cents BIGINT NOT NULL,
    currency CHAR(3) NOT NULL,

    -- `settled` cleared the invoice; `partial` did not and left it open.
    outcome ENUM('settled', 'partial', 'overpaid') NOT NULL,
    created_at DATETIME NOT NULL,

    UNIQUE KEY uniq_invoice_payment_event (stripe_event_id),
    KEY idx_invoice_payments_invoice (tenant_id, invoice_id),
    CONSTRAINT fk_invoice_payments_tenant FOREIGN KEY (tenant_id) REFERENCES organizations(id) ON DELETE CASCADE,
    CONSTRAINT fk_invoice_payments_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
