-- Duely — delete inbound mail that was never about an invoice.
--
-- The IMAP poller recorded every new message in the connected mailbox and only
-- then asked whether it belonged to a chase. The matching was correct and
-- attached nothing, so the row was stored with `chase_id` NULL -- and the
-- dashboard rendered those rows as "someone replied".
--
-- The connected mailbox is the user's real inbox. So what accumulated was their
-- ordinary mail, kept with a subject, a from address and a body snippet, none of
-- which Duely has any reason to hold. On at least one workspace that included
-- Duely's own one-time login codes, displayed in full on the dashboard.
--
-- The poller now decides before it writes. This removes what the old order left
-- behind.
--
-- A hard delete, not a flag. The rows contain other people's mail: keeping them
-- marked as hidden would leave the same content in the same table, readable by
-- anything that forgets the flag -- which is precisely the bug being fixed.
DELETE FROM reply_events
WHERE chase_id IS NULL;

-- Nothing here is recoverable and nothing depends on it: an unmatched row was
-- never attached to a chase, never shown on an invoice timeline, and never
-- counted towards anything except a dashboard panel it should not have been on.
