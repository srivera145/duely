-- Columns the cadence engine needs to send safely.

-- A suppressed client is never emailed again, whatever their invoices say.
-- This is the hard stop that survives someone re-importing a spreadsheet.
ALTER TABLE clients
    ADD COLUMN suppressed_at DATETIME NULL AFTER is_archived,
    ADD COLUMN suppressed_reason VARCHAR(64) NULL AFTER suppressed_at;

ALTER TABLE chase_messages
    -- Rate limits are per mailbox, so a message has to remember which one it
    -- went through even after the chase is repointed at another account.
    ADD COLUMN email_account_id INT NULL AFTER chase_id,

    -- Retry bookkeeping.
    ADD COLUMN attempts TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER status,
    ADD COLUMN next_attempt_at DATETIME NULL AFTER attempts,

    -- Stamped immediately before the message is handed to the transport.
    --
    -- This is what makes crash recovery decidable. A queued row with no
    -- dispatched_at crashed before anything left the building and is safe to
    -- retry; a queued row WITH dispatched_at may or may not have reached the
    -- server, so it is never retried — a missed nudge beats a duplicate.
    ADD COLUMN dispatched_at DATETIME NULL AFTER next_attempt_at,

    ADD CONSTRAINT fk_chase_messages_account FOREIGN KEY (email_account_id)
        REFERENCES email_accounts(id) ON DELETE SET NULL,

    -- Counting an account's sends in the last hour and the last day is the
    -- hot path of the rate limiter.
    ADD KEY idx_chase_messages_account_sent (email_account_id, sent_at),
    ADD KEY idx_chase_messages_retry (status, next_attempt_at);

-- Claiming due chases orders by next_send_at under a row lock; this index
-- keeps that scan off the whole table.
ALTER TABLE chases
    ADD KEY idx_chases_claim (status, next_send_at, id);
