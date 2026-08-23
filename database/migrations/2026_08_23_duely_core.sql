-- Duely core domain schema.
--
-- Tenancy note: Keel ships its tenant table as `organizations`. Duely keeps the
-- domain-neutral column name `tenant_id` on every owned table and points the
-- foreign key at `organizations(id)`, which is the tenants table in this install.
--
-- Money is always integer cents (BIGINT). No floats anywhere in this schema.

CREATE TABLE IF NOT EXISTS clients (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL,
    company VARCHAR(255) NULL,
    phone VARCHAR(50) NULL,
    timezone VARCHAR(64) NOT NULL DEFAULT 'UTC',
    notes TEXT NULL,
    is_archived TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_clients_tenant FOREIGN KEY (tenant_id) REFERENCES organizations(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_clients_tenant_email (tenant_id, email),
    KEY idx_clients_tenant_name (tenant_id, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS email_accounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    user_id INT NULL,
    provider ENUM('smtp','gmail','outlook') NOT NULL DEFAULT 'smtp',
    from_name VARCHAR(255) NOT NULL,
    from_email VARCHAR(255) NOT NULL,
    reply_to VARCHAR(255) NULL,

    -- Outgoing (SMTP)
    smtp_host VARCHAR(255) NULL,
    smtp_port SMALLINT UNSIGNED NULL,
    smtp_encryption ENUM('none','tls','ssl') NOT NULL DEFAULT 'tls',
    smtp_username VARCHAR(255) NULL,
    smtp_password_encrypted BLOB NULL,

    -- Incoming (IMAP)
    imap_host VARCHAR(255) NULL,
    imap_port SMALLINT UNSIGNED NULL,
    imap_encryption ENUM('none','tls','ssl') NOT NULL DEFAULT 'ssl',
    imap_username VARCHAR(255) NULL,
    imap_password_encrypted BLOB NULL,
    imap_folder VARCHAR(255) NOT NULL DEFAULT 'INBOX',
    imap_last_seen_uid INT UNSIGNED NULL,
    imap_last_polled_at DATETIME NULL,

    -- OAuth (gmail / outlook providers)
    oauth_access_token_encrypted BLOB NULL,
    oauth_refresh_token_encrypted BLOB NULL,
    oauth_expires_at DATETIME NULL,

    status ENUM('unverified','active','needs_reauth','disabled') NOT NULL DEFAULT 'unverified',
    last_verified_at DATETIME NULL,
    last_error TEXT NULL,
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    -- One default account per tenant: NULL rows are ignored by the unique index.
    default_slot TINYINT(1) AS (IF(is_default = 1, 1, NULL)) STORED,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_email_accounts_tenant FOREIGN KEY (tenant_id) REFERENCES organizations(id) ON DELETE CASCADE,
    CONSTRAINT fk_email_accounts_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY uniq_email_accounts_tenant_from (tenant_id, from_email),
    UNIQUE KEY uniq_email_accounts_tenant_default (tenant_id, default_slot),
    KEY idx_email_accounts_tenant_status (tenant_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS invoices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    client_id INT NOT NULL,
    number VARCHAR(64) NOT NULL,
    amount_cents BIGINT NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'USD',
    issue_date DATE NULL,
    due_date DATE NOT NULL,
    status ENUM('open','paid','void') NOT NULL DEFAULT 'open',
    paid_at DATETIME NULL,
    payment_url VARCHAR(2048) NULL,
    external_ref VARCHAR(255) NULL,
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_invoices_tenant FOREIGN KEY (tenant_id) REFERENCES organizations(id) ON DELETE CASCADE,
    CONSTRAINT fk_invoices_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_invoices_tenant_number (tenant_id, number),
    KEY idx_invoices_tenant_status_due (tenant_id, status, due_date),
    KEY idx_invoices_tenant_client (tenant_id, client_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sequences (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT NULL,
    tone ENUM('friendly','neutral','firm') NOT NULL DEFAULT 'friendly',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    -- One default sequence per tenant: NULL rows are ignored by the unique index.
    default_slot TINYINT(1) AS (IF(is_default = 1, 1, NULL)) STORED,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_sequences_tenant FOREIGN KEY (tenant_id) REFERENCES organizations(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_sequences_tenant_name (tenant_id, name),
    UNIQUE KEY uniq_sequences_tenant_default (tenant_id, default_slot)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sequence_steps (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    sequence_id INT NOT NULL,
    position INT NOT NULL,
    -- Counted from the invoice due date, NOT from the send date. Negative values
    -- fire before the due date. An invoice imported 18 days overdue therefore
    -- enters the sequence at the correct rung instead of restarting at step 1.
    offset_days INT NOT NULL,
    subject_template VARCHAR(500) NOT NULL,
    body_template TEXT NOT NULL,
    tone ENUM('friendly','neutral','firm') NOT NULL DEFAULT 'friendly',
    is_final TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_sequence_steps_tenant FOREIGN KEY (tenant_id) REFERENCES organizations(id) ON DELETE CASCADE,
    CONSTRAINT fk_sequence_steps_sequence FOREIGN KEY (sequence_id) REFERENCES sequences(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_sequence_steps_position (sequence_id, position),
    UNIQUE KEY uniq_sequence_steps_offset (sequence_id, offset_days),
    KEY idx_sequence_steps_tenant (tenant_id, sequence_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS chases (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    invoice_id INT NOT NULL,
    sequence_id INT NOT NULL,
    email_account_id INT NULL,
    status ENUM('scheduled','active','paused','completed','stopped') NOT NULL DEFAULT 'scheduled',
    current_position INT NOT NULL DEFAULT 0,
    next_send_at DATETIME NULL,
    paused_reason ENUM('client_replied','invoice_paid','bounced','manual','needs_reauth') NULL,
    paused_at DATETIME NULL,
    -- RFC822 threading anchors: every follow-up references the root message so
    -- the client sees one conversation rather than a pile of new emails.
    thread_id VARCHAR(255) NULL,
    root_message_id VARCHAR(255) NULL,
    started_at DATETIME NULL,
    completed_at DATETIME NULL,
    stopped_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_chases_tenant FOREIGN KEY (tenant_id) REFERENCES organizations(id) ON DELETE CASCADE,
    CONSTRAINT fk_chases_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
    CONSTRAINT fk_chases_sequence FOREIGN KEY (sequence_id) REFERENCES sequences(id) ON DELETE CASCADE,
    CONSTRAINT fk_chases_email_account FOREIGN KEY (email_account_id) REFERENCES email_accounts(id) ON DELETE SET NULL,
    -- One chase per invoice.
    UNIQUE KEY uniq_chases_invoice (invoice_id),
    KEY idx_chases_due (status, next_send_at),
    KEY idx_chases_tenant_status (tenant_id, status),
    KEY idx_chases_thread (tenant_id, thread_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS chase_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    chase_id INT NOT NULL,
    sequence_step_id INT NULL,
    position INT NOT NULL,
    to_email VARCHAR(255) NOT NULL,
    from_email VARCHAR(255) NOT NULL,
    subject VARCHAR(500) NOT NULL,
    body_text MEDIUMTEXT NOT NULL,
    body_html MEDIUMTEXT NULL,
    -- RFC822 threading headers.
    rfc_message_id VARCHAR(255) NOT NULL,
    in_reply_to VARCHAR(255) NULL,
    references_header TEXT NULL,
    status ENUM('queued','sent','failed','bounced') NOT NULL DEFAULT 'queued',
    scheduled_for DATETIME NULL,
    sent_at DATETIME NULL,
    failed_reason TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_chase_messages_tenant FOREIGN KEY (tenant_id) REFERENCES organizations(id) ON DELETE CASCADE,
    CONSTRAINT fk_chase_messages_chase FOREIGN KEY (chase_id) REFERENCES chases(id) ON DELETE CASCADE,
    CONSTRAINT fk_chase_messages_step FOREIGN KEY (sequence_step_id) REFERENCES sequence_steps(id) ON DELETE SET NULL,
    UNIQUE KEY uniq_chase_messages_rfc_id (tenant_id, rfc_message_id),
    UNIQUE KEY uniq_chase_messages_chase_position (chase_id, position),
    KEY idx_chase_messages_tenant_status (tenant_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS reply_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    chase_id INT NULL,
    chase_message_id INT NULL,
    email_account_id INT NULL,
    type ENUM('reply','bounce','auto_reply','complaint','unknown') NOT NULL DEFAULT 'unknown',
    from_email VARCHAR(255) NULL,
    subject VARCHAR(500) NULL,
    snippet TEXT NULL,
    rfc_message_id VARCHAR(255) NOT NULL,
    in_reply_to VARCHAR(255) NULL,
    thread_id VARCHAR(255) NULL,
    raw_headers MEDIUMTEXT NULL,
    received_at DATETIME NOT NULL,
    processed_at DATETIME NULL,
    action_taken VARCHAR(64) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_reply_events_tenant FOREIGN KEY (tenant_id) REFERENCES organizations(id) ON DELETE CASCADE,
    CONSTRAINT fk_reply_events_chase FOREIGN KEY (chase_id) REFERENCES chases(id) ON DELETE CASCADE,
    CONSTRAINT fk_reply_events_message FOREIGN KEY (chase_message_id) REFERENCES chase_messages(id) ON DELETE SET NULL,
    CONSTRAINT fk_reply_events_email_account FOREIGN KEY (email_account_id) REFERENCES email_accounts(id) ON DELETE SET NULL,
    -- Idempotent IMAP ingestion: the same message never lands twice.
    UNIQUE KEY uniq_reply_events_rfc_id (tenant_id, rfc_message_id),
    KEY idx_reply_events_unprocessed (tenant_id, processed_at),
    KEY idx_reply_events_chase (chase_id, received_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
