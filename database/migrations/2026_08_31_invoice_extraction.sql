-- Duely — reading an invoice document with Claude.
--
-- Opt-in per workspace, and off until someone says yes. The writing assistant
-- only ever sees a template with merge tags in place of real values; this
-- feature sends the document itself, which is a different bargain and has to be
-- struck explicitly rather than inherited from having an API key configured.
--
-- The consent timestamp is kept because "did this workspace agree, and when"
-- is a question worth being able to answer later.

ALTER TABLE organizations
    ADD COLUMN ai_extraction_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER plan,
    ADD COLUMN ai_extraction_consented_at DATETIME NULL AFTER ai_extraction_enabled,
    ADD COLUMN ai_extraction_consented_by INT NULL AFTER ai_extraction_consented_at,
    ADD CONSTRAINT fk_orgs_extraction_consent
        FOREIGN KEY (ai_extraction_consented_by) REFERENCES users(id) ON DELETE SET NULL;
