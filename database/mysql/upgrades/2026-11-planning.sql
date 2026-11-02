-- Upgrade for databases loaded before the planning board. Run as the account that loaded 01-13:
--   1. run this file (adds job_cards.planned_start and planned_end)
--   2. run 14_planning.sql
-- No table is dropped or emptied.
USE hpos;

ALTER TABLE job_cards ADD COLUMN planned_start DATE NULL AFTER die_id, ADD COLUMN planned_end DATE NULL AFTER planned_start;
ALTER TABLE job_cards ADD CONSTRAINT ck_jc_plan CHECK ((planned_start IS NULL AND planned_end IS NULL) OR (planned_start IS NOT NULL AND planned_end IS NOT NULL AND planned_end >= planned_start));
