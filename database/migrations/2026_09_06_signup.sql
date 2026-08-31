-- Duely — letting a stranger create an account, and holding their founding slot.
--
-- ---------------------------------------------------------------------------
-- THE DECISION: a founding slot is claimed at SIGNUP, and expires if unused.
--
-- The two options were claim-at-signup and claim-at-first-payment.
--
-- Claim at first payment protects the offer perfectly, but it makes the
-- homepage counter a lie: "12 of 50 places left" would mean twelve people have
-- paid, while the sentence next to it says "the first 50 to sign up". A counter
-- that means something other than what it says is worse than no counter.
--
-- Claim at signup matches the copy and rewards whoever actually showed up
-- first. Its failure mode is fifty tyre-kickers consuming every slot without
-- ever connecting a mailbox — so the hold expires. `reserved_until` is set when
-- the slot is taken, and ReleaseExpiredFoundingSlotsJob puts it back if the
-- workspace has not started a paid subscription by then.
--
-- Thirty days is long enough to be fair to somebody who signed up before a busy
-- month and short enough that the offer does not sit locked up for a quarter.
-- ---------------------------------------------------------------------------
ALTER TABLE founding_slots
    -- When an unpaid hold lapses. NULL on a free row, and left set on a claimed
    -- one even after the workspace pays: the release job checks for an active
    -- subscription, so a paying workspace is never released and the date simply
    -- stops mattering.
    ADD COLUMN reserved_until DATETIME NULL AFTER claimed_at,

    -- So the warning goes out once rather than every day the job runs. The
    -- constraint is to tell somebody *before* they lose the place, and an email
    -- every morning for a week is not a better warning, it is a worse one.
    ADD COLUMN lapse_warning_sent_at DATETIME NULL AFTER reserved_until,

    ADD KEY idx_founding_reserved (reserved_until);

-- Existing claims predate the hold and were made by workspaces that got here
-- some other way. Give them the same thirty days from now rather than expiring
-- them immediately, which would release slots the moment this deploys.
UPDATE founding_slots
SET reserved_until = DATE_ADD(NOW(), INTERVAL 30 DAY)
WHERE tenant_id IS NOT NULL AND reserved_until IS NULL;

