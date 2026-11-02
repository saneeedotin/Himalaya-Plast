-- Upgrade for databases loaded before the Books (accounting) module. Run as the account that loaded 01-09:
--   1. run this file
--   2. re-run 04_masters_orders_production.sql, 06_accounts_alerts.sql and 08_triggers.sql
--      (08 puts back the stock-ledger guard this file removes for a moment)
--   3. run 10_books.sql and 11_books_screens.sql
--   4. CALL sp_gl_backfill();    -- posts the invoices, bills and payments you already have into the books
-- No table is dropped or emptied.
USE hpos;

ALTER TABLE payment_entries ADD COLUMN account_id CHAR(36) NULL AFTER status;
ALTER TABLE stock_ledger_entries ADD COLUMN is_opening TINYINT(1) NOT NULL DEFAULT 0 AFTER notes;

-- stock that was entered as opening stock is capital, not profit: mark the ones already there
DROP TRIGGER IF EXISTS trg_sle_no_update;
UPDATE stock_ledger_entries SET is_opening = 1 WHERE movement_type = 'ADJUSTMENT_IN' AND notes LIKE 'Opening stock%';
