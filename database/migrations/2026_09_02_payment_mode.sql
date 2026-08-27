-- Duely — making the pay-button choice explicit.
--
-- Until now `stripe_account_id` did two jobs: it recorded that an account was
-- linked, and it acted as the switch for whether reminders carried a pay
-- button. That conflation meant the only way to stop the buttons was to
-- disconnect Stripe, which revokes the OAuth grant -- pausing and unlinking
-- should not be the same gesture.
--
-- So the switch moves into a column of its own, at two levels: a workspace
-- default, and a per-invoice override.

-- The workspace default. Deliberately independent of `stripe_account_id`:
-- disconnecting Stripe must not reset it, and setting it to `never` must not
-- disconnect Stripe.
--
-- `manual_only` is the interesting value and the reason this is not a boolean.
-- It keeps the connection live -- so an invoice can be given a link on purpose
-- -- without putting a button on everything by default.
--
-- Named for what it governs. It controls links *Duely generates*; a URL the
-- user pasted is theirs, and `never` does not suppress it.
ALTER TABLE organizations
    ADD COLUMN payment_link_mode ENUM('always', 'manual_only', 'never')
        NOT NULL DEFAULT 'always'
        AFTER stripe_account_last_checked_at;

-- The per-invoice override. NULL is the common case and means "whatever the
-- workspace says", so existing rows need no backfill and no new behaviour.
--
--   generate — force a Duely link even in a `manual_only` workspace
--   none     — no pay button on this invoice even in an `always` workspace,
--              for the client on a wire-transfer arrangement or the invoice
--              that is already part paid
ALTER TABLE invoices
    ADD COLUMN payment_link_mode ENUM('default', 'generate', 'none') NULL
        AFTER stripe_payment_link_id;

-- Collecting payment is an optional fifth onboarding step, so it needs the same
-- kind of record the sequence-review step already has: there is nothing to
-- detect about a user who has decided they do not want it, and asking forever
-- is the wizard calling them incomplete for making a choice.
ALTER TABLE onboarding_progress
    ADD COLUMN dismissed_payment_at DATETIME NULL AFTER started_chasing_at;
