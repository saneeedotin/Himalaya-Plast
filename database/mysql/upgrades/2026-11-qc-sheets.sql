-- Upgrade for databases loaded before the QC sheets. Run as the account that loaded 01-11:
--   1. run this file (adds items.colour)
--   2. run 12_qc_sheets.sql
-- No table is dropped or emptied.
USE hpos;

ALTER TABLE items ADD COLUMN colour VARCHAR(40) NULL AFTER qc_template;
