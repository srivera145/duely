-- Duely — a timezone for the workspace, so timestamps mean something.
--
-- `clients.timezone` has existed since the core migration and ChaseScheduler has
-- always read it, but nothing ever set it: no form field, no CSV column, no
-- import mapping. Every client was therefore UTC, which made the window
-- arithmetic correct and the input to it wrong.
--
-- There was also no workspace timezone at all, so every displayed time was UTC.
-- A user in Montana read every timestamp six hours off their own clock.
--
-- Storage does not change. DATETIME columns stay UTC wall-clock and everything
-- still crosses the boundary through Clock. This column is read at render time
-- and at nothing else.
ALTER TABLE organizations
    ADD COLUMN timezone VARCHAR(64) NOT NULL DEFAULT 'UTC' AFTER slug;

-- Existing clients are all 'UTC' by default, which is almost certainly wrong.
--
-- Deliberately NOT backfilled. There is no safe guess: a workspace in Denver may
-- have clients in Denver, or in London, and rewriting them silently would move
-- every scheduled reminder by hours without telling anybody. The clients list
-- flags rows still on UTC once the workspace is not, with a one-click action, so
-- the person who knows decides.
