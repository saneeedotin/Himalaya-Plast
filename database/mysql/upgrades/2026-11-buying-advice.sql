-- Upgrade for databases loaded before the buying advice. Run as the account that loaded 01-15:
--   1. run this file (adds lead_time_days, reorder_qty and preferred_supplier_id to items)
--   2. run 16_buying_advice.sql
-- No table is dropped or emptied.
USE hpos;

ALTER TABLE items ADD COLUMN lead_time_days INT NULL AFTER colour, ADD COLUMN reorder_qty DECIMAL(16,3) NULL AFTER lead_time_days,
  ADD COLUMN preferred_supplier_id CHAR(36) NULL AFTER reorder_qty;
ALTER TABLE items ADD CONSTRAINT fk_items_pref_supplier FOREIGN KEY (preferred_supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL;
