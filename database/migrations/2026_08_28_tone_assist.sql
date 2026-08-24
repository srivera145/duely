-- Tone assist: per-tenant usage, for both the rate limit and cost tracking.
--
-- Every call is recorded whether it succeeded or not. A failed call still cost
-- tokens if the model answered before we rejected its output, and a run of
-- failures is exactly the thing worth being able to see.
CREATE TABLE IF NOT EXISTS ai_usage (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    user_id INT NULL,
    action VARCHAR(32) NOT NULL,
    model VARCHAR(64) NOT NULL,
    input_tokens INT UNSIGNED NOT NULL DEFAULT 0,
    output_tokens INT UNSIGNED NOT NULL DEFAULT 0,
    cache_read_tokens INT UNSIGNED NOT NULL DEFAULT 0,
    cache_write_tokens INT UNSIGNED NOT NULL DEFAULT 0,
    outcome ENUM('accepted','rejected','failed') NOT NULL DEFAULT 'accepted',
    failure_reason VARCHAR(255) NULL,
    duration_ms INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_ai_usage_tenant FOREIGN KEY (tenant_id) REFERENCES organizations(id) ON DELETE CASCADE,
    CONSTRAINT fk_ai_usage_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    -- The daily rate-limit count is the hot query.
    KEY idx_ai_usage_tenant_day (tenant_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
