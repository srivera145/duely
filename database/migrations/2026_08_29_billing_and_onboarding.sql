-- Plans, the founding cohort, webhook idempotency, and onboarding progress.

ALTER TABLE organizations
    -- The plan a workspace is on. Duely bills the workspace, not the person,
    -- because a Studio plan has several seats sharing one subscription.
    ADD COLUMN plan VARCHAR(16) NOT NULL DEFAULT 'free' AFTER slug,
    ADD COLUMN trial_ends_at DATETIME NULL AFTER plan,

    -- Set when this workspace holds one of the fifty founding slots. The price
    -- is grandfathered, so a later increase must not touch it.
    ADD COLUMN is_founding TINYINT(1) NOT NULL DEFAULT 0 AFTER trial_ends_at,
    ADD COLUMN founding_slot SMALLINT UNSIGNED NULL AFTER is_founding,

    ADD COLUMN stripe_customer_id VARCHAR(255) NULL AFTER founding_slot,
    ADD KEY idx_organizations_plan (plan),
    ADD KEY idx_organizations_trial (trial_ends_at);

-- Subscriptions belong to a workspace. Keel's table is keyed to a user, which
-- would leave a Studio team with one member paying and the rest locked out.
ALTER TABLE subscriptions
    ADD COLUMN tenant_id INT NULL AFTER id,
    ADD CONSTRAINT fk_subscriptions_tenant FOREIGN KEY (tenant_id) REFERENCES organizations(id) ON DELETE CASCADE,
    ADD KEY idx_subscriptions_tenant (tenant_id, status);

-- The founding cohort, as fifty physical slots.
--
-- Counting existing members and comparing to fifty is a race: two signups can
-- both read 49 and both proceed. Pre-creating the slots turns the claim into a
-- single conditional UPDATE, which the database serialises for us — the loser
-- of a race updates zero rows and simply gets standard pricing.
CREATE TABLE IF NOT EXISTS founding_slots (
    slot_number SMALLINT UNSIGNED NOT NULL PRIMARY KEY,
    tenant_id INT NULL,
    claimed_at DATETIME NULL,
    CONSTRAINT fk_founding_slots_tenant FOREIGN KEY (tenant_id) REFERENCES organizations(id) ON DELETE SET NULL,
    -- A workspace can hold at most one slot.
    UNIQUE KEY uniq_founding_slots_tenant (tenant_id),
    KEY idx_founding_slots_free (tenant_id, slot_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Every Stripe event, recorded once.
--
-- Stripe retries on any non-2xx and may deliver the same event more than once
-- regardless. The unique key on the event id is what makes a replay a no-op
-- rather than a second grant.
CREATE TABLE IF NOT EXISTS stripe_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    stripe_event_id VARCHAR(255) NOT NULL,
    type VARCHAR(100) NOT NULL,
    tenant_id INT NULL,
    payload MEDIUMTEXT NULL,
    status ENUM('received','processed','ignored','failed') NOT NULL DEFAULT 'received',
    error TEXT NULL,
    received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_at DATETIME NULL,
    UNIQUE KEY uniq_stripe_events_event (stripe_event_id),
    KEY idx_stripe_events_type (type, received_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Where a workspace has got to in the first-run wizard.
CREATE TABLE IF NOT EXISTS onboarding_progress (
    tenant_id INT NOT NULL PRIMARY KEY,
    connected_email_at DATETIME NULL,
    added_invoice_at DATETIME NULL,
    reviewed_sequence_at DATETIME NULL,
    started_chasing_at DATETIME NULL,
    skipped_at DATETIME NULL,
    completed_at DATETIME NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_onboarding_tenant FOREIGN KEY (tenant_id) REFERENCES organizations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed the fifty slots.
INSERT INTO founding_slots (slot_number)
SELECT n FROM (
    SELECT ones.d + tens.d * 10 + 1 AS n
    FROM (SELECT 0 d UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4
          UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9) ones
    CROSS JOIN (SELECT 0 d UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4) tens
) numbers
WHERE n <= 50
ON DUPLICATE KEY UPDATE slot_number = founding_slots.slot_number;
