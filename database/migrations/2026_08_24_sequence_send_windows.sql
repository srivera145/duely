-- Sequence send windows and the escalation tone vocabulary.
--
-- Reminders should arrive during the client's working day. Sending at 03:00 or
-- on a Sunday reads as automated, which is exactly the impression Duely exists
-- to avoid, so every sequence carries a window and a weekend rule.

ALTER TABLE sequences
    ADD COLUMN send_window_start TIME NOT NULL DEFAULT '09:00:00' AFTER tone,
    ADD COLUMN send_window_end TIME NOT NULL DEFAULT '16:00:00' AFTER send_window_start,
    ADD COLUMN skip_weekends TINYINT(1) NOT NULL DEFAULT 1 AFTER send_window_end;

-- The ladder is described as polite -> firm -> final. `neutral` stays available
-- as a middle option for tenants who want a fourth rung.
--
-- The enum is widened to hold both vocabularies first, so existing rows can be
-- rewritten before the old values are dropped. Narrowing in one step would
-- truncate every 'friendly' row to an empty string.
ALTER TABLE sequences
    MODIFY COLUMN tone ENUM('friendly','neutral','firm','polite','final') NOT NULL DEFAULT 'friendly';

ALTER TABLE sequence_steps
    MODIFY COLUMN tone ENUM('friendly','neutral','firm','polite','final') NOT NULL DEFAULT 'friendly';

UPDATE sequences SET tone = 'polite' WHERE tone = 'friendly';
UPDATE sequence_steps SET tone = 'polite' WHERE tone = 'friendly';

ALTER TABLE sequences
    MODIFY COLUMN tone ENUM('polite','neutral','firm','final') NOT NULL DEFAULT 'polite';

ALTER TABLE sequence_steps
    MODIFY COLUMN tone ENUM('polite','neutral','firm','final') NOT NULL DEFAULT 'polite';
