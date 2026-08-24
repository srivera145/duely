-- Duely Phase 10 — the waitlist.
--
-- One row per address, and only one: a second signup from the same person
-- refreshes the row rather than adding another, so the list stays a list of
-- people rather than a list of form submissions.
--
-- Nothing here is tenant-scoped. A waitlist signup happens before there is an
-- account, let alone a workspace, so this is a platform table and the tenant
-- rules that govern every other Duely table do not apply to it.

CREATE TABLE IF NOT EXISTS waitlist_signups (
    id INT AUTO_INCREMENT PRIMARY KEY,

    email VARCHAR(255) NOT NULL,

    -- Double opt-in. A row is worth nothing until the person has proved they
    -- own the address, so `pending` is the only state a form can create.
    status ENUM('pending', 'confirmed', 'unsubscribed') NOT NULL DEFAULT 'pending',

    -- The token is stored hashed, exactly like Keel's auth tokens: a leaked
    -- database must not let anyone confirm somebody else's address.
    confirm_token_hash CHAR(64) NULL,
    confirm_expires_at DATETIME NULL,
    confirm_sent_at DATETIME NULL,
    confirm_send_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,

    confirmed_at DATETIME NULL,
    unsubscribed_at DATETIME NULL,

    -- Where the signup came from. `source` is the form; the rest is whatever
    -- the campaign put in the URL.
    source VARCHAR(64) NOT NULL DEFAULT 'landing',
    landing_path VARCHAR(255) NULL,
    referrer VARCHAR(512) NULL,
    utm_source VARCHAR(128) NULL,
    utm_medium VARCHAR(128) NULL,
    utm_campaign VARCHAR(128) NULL,
    utm_term VARCHAR(128) NULL,
    utm_content VARCHAR(128) NULL,

    -- Hashed, not stored. Enough to spot one machine submitting two hundred
    -- addresses; not enough to be a log of who visited.
    ip_hash CHAR(64) NULL,
    user_agent VARCHAR(255) NULL,

    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,

    UNIQUE KEY uniq_waitlist_email (email),
    KEY idx_waitlist_status (status, created_at),
    KEY idx_waitlist_token (confirm_token_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
