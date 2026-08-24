-- Reply and bounce detection.

ALTER TABLE email_accounts
    -- IMAP cursor. UIDNEXT is where the next poll starts; UIDVALIDITY guards it,
    -- because a mailbox that changes UIDVALIDITY has renumbered every message
    -- and the stored cursor then points at the wrong mail entirely.
    ADD COLUMN imap_uidnext INT UNSIGNED NULL AFTER imap_last_seen_uid,
    ADD COLUMN imap_uidvalidity BIGINT UNSIGNED NULL AFTER imap_uidnext,
    ADD COLUMN imap_last_error TEXT NULL AFTER imap_uidvalidity;

ALTER TABLE clients
    -- Set when mail to this address hard-bounces. Duely stops chasing an
    -- address the world has told us does not exist.
    ADD COLUMN email_invalid_at DATETIME NULL AFTER suppressed_reason,
    ADD COLUMN email_invalid_reason VARCHAR(255) NULL AFTER email_invalid_at;

ALTER TABLE reply_events
    -- The mailbox-local identity of the message: account plus IMAP UID. This is
    -- the dedupe key, because not every inbound message carries a Message-ID —
    -- bounces and autoresponders frequently omit one — and re-polling an
    -- overlapping UID window must never create a second event or a second pause.
    ADD COLUMN provider_message_id VARCHAR(255) NULL AFTER email_account_id,
    ADD COLUMN provider_uid INT UNSIGNED NULL AFTER provider_message_id,
    ADD COLUMN matched_by VARCHAR(32) NULL AFTER type,
    ADD COLUMN is_hard_bounce TINYINT(1) NOT NULL DEFAULT 0 AFTER matched_by,
    ADD UNIQUE KEY uniq_reply_events_provider (email_account_id, provider_message_id);

-- rfc_message_id stays unique per tenant, but a message without one is now
-- given a synthetic id derived from the account and UID, so the column can
-- remain NOT NULL without silently colliding across headerless messages.
