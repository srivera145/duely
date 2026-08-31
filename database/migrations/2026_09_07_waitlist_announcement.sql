-- Duely — telling the waitlist that signup is open.
--
-- They gave an address before there was anything to give them, and then
-- confirmed it. That makes them the warmest audience the product has and the
-- easiest to lose: one duplicate launch email spends the goodwill instantly.
--
-- This column is what makes the send once-only. AnnounceSignupToWaitlistJob
-- writes it before sending, conditionally, so two runs cannot both take a row
-- and a mail failure does not queue a retry at the recipient's expense.
ALTER TABLE waitlist_signups
    ADD COLUMN announced_at DATETIME NULL AFTER confirmed_at;
