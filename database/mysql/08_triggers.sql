-- =============================================================================
-- HPOS Factory OS  |  08_triggers.sql  -  integrity guards
-- The stock ledger and audit log are append-only. Posted invoices are immutable.
-- =============================================================================
USE hpos;
DELIMITER $$

DROP TRIGGER IF EXISTS trg_sle_no_update $$
CREATE TRIGGER trg_sle_no_update BEFORE UPDATE ON stock_ledger_entries FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Stock ledger is append-only: post a reversing entry instead';
END $$

DROP TRIGGER IF EXISTS trg_sle_no_delete $$
CREATE TRIGGER trg_sle_no_delete BEFORE DELETE ON stock_ledger_entries FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Stock ledger is append-only: post a reversing entry instead';
END $$

DROP TRIGGER IF EXISTS trg_audit_no_update $$
CREATE TRIGGER trg_audit_no_update BEFORE UPDATE ON audit_logs FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Audit log is append-only';
END $$

DROP TRIGGER IF EXISTS trg_audit_no_delete $$
CREATE TRIGGER trg_audit_no_delete BEFORE DELETE ON audit_logs FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Audit log is append-only';
END $$

-- Posted (non-DRAFT) sales invoices: lines cannot be edited or removed.
DROP TRIGGER IF EXISTS trg_sii_lock_ins $$
CREATE TRIGGER trg_sii_lock_ins BEFORE INSERT ON sales_invoice_items FOR EACH ROW
BEGIN
  IF (SELECT status FROM sales_invoices WHERE id = NEW.sales_invoice_id) <> 'DRAFT' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invoice is posted: lines are locked';
  END IF;
END $$

DROP TRIGGER IF EXISTS trg_sii_lock_upd $$
CREATE TRIGGER trg_sii_lock_upd BEFORE UPDATE ON sales_invoice_items FOR EACH ROW
BEGIN
  IF (SELECT status FROM sales_invoices WHERE id = OLD.sales_invoice_id) <> 'DRAFT' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invoice is posted: lines are locked';
  END IF;
END $$

DROP TRIGGER IF EXISTS trg_sii_lock_del $$
CREATE TRIGGER trg_sii_lock_del BEFORE DELETE ON sales_invoice_items FOR EACH ROW
BEGIN
  IF (SELECT status FROM sales_invoices WHERE id = OLD.sales_invoice_id) <> 'DRAFT' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invoice is posted: lines are locked';
  END IF;
END $$

DROP TRIGGER IF EXISTS trg_pii_lock_ins $$
CREATE TRIGGER trg_pii_lock_ins BEFORE INSERT ON purchase_invoice_items FOR EACH ROW
BEGIN
  IF (SELECT status FROM purchase_invoices WHERE id = NEW.purchase_invoice_id) <> 'DRAFT' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invoice is posted: lines are locked';
  END IF;
END $$

DROP TRIGGER IF EXISTS trg_pii_lock_upd $$
CREATE TRIGGER trg_pii_lock_upd BEFORE UPDATE ON purchase_invoice_items FOR EACH ROW
BEGIN
  IF (SELECT status FROM purchase_invoices WHERE id = OLD.purchase_invoice_id) <> 'DRAFT' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invoice is posted: lines are locked';
  END IF;
END $$

DROP TRIGGER IF EXISTS trg_pii_lock_del $$
CREATE TRIGGER trg_pii_lock_del BEFORE DELETE ON purchase_invoice_items FOR EACH ROW
BEGIN
  IF (SELECT status FROM purchase_invoices WHERE id = OLD.purchase_invoice_id) <> 'DRAFT' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invoice is posted: lines are locked';
  END IF;
END $$

-- -----------------------------------------------------------------------------
-- Line amounts, computed on every insert/update so no caller can store a wrong one.
--   order / PO line : amount = qty * rate
--   invoice line    : amount = qty * rate ; tax = amount * tax_rate / 100 ; total = amount + tax
-- Invoice headers are summed by sp_recalc_*_invoice (06). Order and PO headers are summed here.
-- -----------------------------------------------------------------------------
DROP TRIGGER IF EXISTS trg_soi_calc_ins $$
CREATE TRIGGER trg_soi_calc_ins BEFORE INSERT ON sales_order_items FOR EACH ROW
  SET NEW.amount = ROUND(NEW.qty * NEW.rate, 2) $$

DROP TRIGGER IF EXISTS trg_soi_calc_upd $$
CREATE TRIGGER trg_soi_calc_upd BEFORE UPDATE ON sales_order_items FOR EACH ROW
  SET NEW.amount = ROUND(NEW.qty * NEW.rate, 2) $$

DROP TRIGGER IF EXISTS trg_poi_calc_ins $$
CREATE TRIGGER trg_poi_calc_ins BEFORE INSERT ON purchase_order_items FOR EACH ROW
  SET NEW.amount = ROUND(NEW.qty * NEW.rate, 2) $$

DROP TRIGGER IF EXISTS trg_poi_calc_upd $$
CREATE TRIGGER trg_poi_calc_upd BEFORE UPDATE ON purchase_order_items FOR EACH ROW
  SET NEW.amount = ROUND(NEW.qty * NEW.rate, 2) $$

DROP TRIGGER IF EXISTS trg_sii_calc_ins $$
CREATE TRIGGER trg_sii_calc_ins BEFORE INSERT ON sales_invoice_items FOR EACH ROW
BEGIN
  SET NEW.amount       = ROUND(NEW.qty * NEW.rate, 2);
  SET NEW.tax_amount   = ROUND(NEW.amount * NEW.tax_rate / 100, 2);
  SET NEW.total_amount = NEW.amount + NEW.tax_amount;
END $$

DROP TRIGGER IF EXISTS trg_sii_calc_upd $$
CREATE TRIGGER trg_sii_calc_upd BEFORE UPDATE ON sales_invoice_items FOR EACH ROW
BEGIN
  SET NEW.amount       = ROUND(NEW.qty * NEW.rate, 2);
  SET NEW.tax_amount   = ROUND(NEW.amount * NEW.tax_rate / 100, 2);
  SET NEW.total_amount = NEW.amount + NEW.tax_amount;
END $$

DROP TRIGGER IF EXISTS trg_pii_calc_ins $$
CREATE TRIGGER trg_pii_calc_ins BEFORE INSERT ON purchase_invoice_items FOR EACH ROW
BEGIN
  SET NEW.amount       = ROUND(NEW.qty * NEW.rate, 2);
  SET NEW.tax_amount   = ROUND(NEW.amount * NEW.tax_rate / 100, 2);
  SET NEW.total_amount = NEW.amount + NEW.tax_amount;
END $$

DROP TRIGGER IF EXISTS trg_pii_calc_upd $$
CREATE TRIGGER trg_pii_calc_upd BEFORE UPDATE ON purchase_invoice_items FOR EACH ROW
BEGIN
  SET NEW.amount       = ROUND(NEW.qty * NEW.rate, 2);
  SET NEW.tax_amount   = ROUND(NEW.amount * NEW.tax_rate / 100, 2);
  SET NEW.total_amount = NEW.amount + NEW.tax_amount;
END $$

DROP PROCEDURE IF EXISTS sp_recalc_sales_order $$
CREATE PROCEDURE sp_recalc_sales_order(IN p_id CHAR(36))
BEGIN
  UPDATE sales_orders
     SET total_amount = (SELECT COALESCE(SUM(amount), 0) FROM sales_order_items WHERE sales_order_id = p_id)
   WHERE id = p_id;
END $$

DROP PROCEDURE IF EXISTS sp_recalc_purchase_order $$
CREATE PROCEDURE sp_recalc_purchase_order(IN p_id CHAR(36))
BEGIN
  UPDATE purchase_orders
     SET total_amount = (SELECT COALESCE(SUM(amount), 0) FROM purchase_order_items WHERE purchase_order_id = p_id)
   WHERE id = p_id;
END $$

DROP TRIGGER IF EXISTS trg_soi_total_ins $$
CREATE TRIGGER trg_soi_total_ins AFTER INSERT ON sales_order_items FOR EACH ROW
  CALL sp_recalc_sales_order(NEW.sales_order_id) $$

DROP TRIGGER IF EXISTS trg_soi_total_upd $$
CREATE TRIGGER trg_soi_total_upd AFTER UPDATE ON sales_order_items FOR EACH ROW
BEGIN
  CALL sp_recalc_sales_order(NEW.sales_order_id);
  IF OLD.sales_order_id <> NEW.sales_order_id THEN
    CALL sp_recalc_sales_order(OLD.sales_order_id);
  END IF;
END $$

DROP TRIGGER IF EXISTS trg_soi_total_del $$
CREATE TRIGGER trg_soi_total_del AFTER DELETE ON sales_order_items FOR EACH ROW
  CALL sp_recalc_sales_order(OLD.sales_order_id) $$

DROP TRIGGER IF EXISTS trg_poi_total_ins $$
CREATE TRIGGER trg_poi_total_ins AFTER INSERT ON purchase_order_items FOR EACH ROW
  CALL sp_recalc_purchase_order(NEW.purchase_order_id) $$

DROP TRIGGER IF EXISTS trg_poi_total_upd $$
CREATE TRIGGER trg_poi_total_upd AFTER UPDATE ON purchase_order_items FOR EACH ROW
BEGIN
  CALL sp_recalc_purchase_order(NEW.purchase_order_id);
  IF OLD.purchase_order_id <> NEW.purchase_order_id THEN
    CALL sp_recalc_purchase_order(OLD.purchase_order_id);
  END IF;
END $$

DROP TRIGGER IF EXISTS trg_poi_total_del $$
CREATE TRIGGER trg_poi_total_del AFTER DELETE ON purchase_order_items FOR EACH ROW
  CALL sp_recalc_purchase_order(OLD.purchase_order_id) $$

-- =============================================================================
-- Notifications raised by events. Each trigger catches its own errors: a failure to notify must never undo
-- the order, stock movement or payment that caused it. Audiences are resources a person needs the read right on.
-- =============================================================================
DROP TRIGGER IF EXISTS trg_so_notify $$
CREATE TRIGGER trg_so_notify AFTER UPDATE ON sales_orders FOR EACH ROW
BEGIN
  DECLARE CONTINUE HANDLER FOR SQLEXCEPTION BEGIN END;
  IF NEW.status <> OLD.status THEN
    IF NEW.status = 'CONFIRMED' THEN
      CALL sp_notify_event('ORDER', NEW.id, 'UPDATE', CONCAT('Order ', NEW.order_number, ' confirmed'),
        CONCAT(NEW.customer_name, ', total ', NEW.total_amount, '. Material is reserved and the work order is created.'),
        CONCAT('/orders/', NEW.id), 'SalesOrders,WorkOrders,Stock');
    ELSEIF NEW.status = 'CANCELLED' THEN
      CALL sp_notify_event('ORDER', NEW.id, 'ACTION_REQUIRED', CONCAT('Order ', NEW.order_number, ' cancelled'),
        CONCAT(NEW.customer_name, '. Reserved material was released.'),
        CONCAT('/orders/', NEW.id), 'SalesOrders,WorkOrders,Stock');
    ELSEIF NEW.status = 'DISPATCHED' THEN
      CALL sp_notify_event('ORDER', NEW.id, 'SUCCESS', CONCAT('Order ', NEW.order_number, ' dispatched'),
        CONCAT(NEW.customer_name, '. Every carton was scanned at the gate. It is ready to invoice.'),
        CONCAT('/orders/', NEW.id), 'Dispatch,SalesOrders,Accounts');
    END IF;
  END IF;
END $$

DROP TRIGGER IF EXISTS trg_downtime_notify_ins $$
CREATE TRIGGER trg_downtime_notify_ins AFTER INSERT ON downtime_logs FOR EACH ROW
BEGIN
  DECLARE v_code VARCHAR(40);
  DECLARE CONTINUE HANDLER FOR SQLEXCEPTION BEGIN END;
  SELECT code INTO v_code FROM workstations WHERE id = NEW.workstation_id;
  CALL sp_notify_event('MACHINE', NEW.id, 'ACTION_REQUIRED', CONCAT('Machine ', IFNULL(v_code, ''), ' stopped'),
    CONCAT('Reason: ', CASE NEW.reason_code WHEN 'MECHANICAL' THEN 'mechanical breakdown' WHEN 'ELECTRICAL' THEN 'electrical fault' WHEN 'MATERIAL' THEN 'waiting for material' WHEN 'CHANGEOVER' THEN 'die or colour change' WHEN 'QUALITY' THEN 'quality problem' WHEN 'POWER' THEN 'power cut' ELSE LOWER(REPLACE(NEW.reason_code, '_', ' ')) END, '.'), '/downtime', 'Production');
END $$

DROP TRIGGER IF EXISTS trg_downtime_notify_upd $$
CREATE TRIGGER trg_downtime_notify_upd AFTER UPDATE ON downtime_logs FOR EACH ROW
BEGIN
  DECLARE v_code VARCHAR(40);
  DECLARE CONTINUE HANDLER FOR SQLEXCEPTION BEGIN END;
  IF OLD.end_time IS NULL AND NEW.end_time IS NOT NULL THEN
    SELECT code INTO v_code FROM workstations WHERE id = NEW.workstation_id;
    CALL sp_notify_resolve('MACHINE', NEW.id, CONCAT('Machine ', IFNULL(v_code, ''), ' stopped'));
    CALL sp_notify_event('MACHINE', NEW.id, 'SUCCESS', CONCAT('Machine ', IFNULL(v_code, ''), ' back in service'),
      CONCAT('Stopped for ', IFNULL(NEW.duration_mins, 0), ' minutes.'), '/downtime', 'Production');
  END IF;
END $$

DROP TRIGGER IF EXISTS trg_qc_notify_ins $$
CREATE TRIGGER trg_qc_notify_ins AFTER INSERT ON quality_inspections FOR EACH ROW
BEGIN
  DECLARE CONTINUE HANDLER FOR SQLEXCEPTION BEGIN END;
  IF NEW.status IN ('REJECT', 'REWORK', 'SCRAP') THEN
    CALL sp_notify_event('QC', NEW.id, IF(NEW.status = 'REWORK', 'ACTION_REQUIRED', 'CRITICAL'),
      CONCAT('Batch ', NEW.batch_number, CASE NEW.status WHEN 'REJECT' THEN ' rejected' WHEN 'REWORK' THEN ' needs rework' ELSE ' scrapped' END),
      CONCAT('Inspection ', NEW.report_number, IF(IFNULL(NEW.rework_notes, '') = '', '.', CONCAT(': ', LEFT(NEW.rework_notes, 200)))),
      CONCAT('/quality/', NEW.id), 'QC,Production');
  END IF;
END $$

DROP TRIGGER IF EXISTS trg_qc_notify_upd $$
CREATE TRIGGER trg_qc_notify_upd AFTER UPDATE ON quality_inspections FOR EACH ROW
BEGIN
  DECLARE CONTINUE HANDLER FOR SQLEXCEPTION BEGIN END;
  IF NEW.status <> OLD.status AND NEW.status IN ('REJECT', 'REWORK', 'SCRAP') THEN
    CALL sp_notify_event('QC', NEW.id, IF(NEW.status = 'REWORK', 'ACTION_REQUIRED', 'CRITICAL'),
      CONCAT('Batch ', NEW.batch_number, CASE NEW.status WHEN 'REJECT' THEN ' rejected' WHEN 'REWORK' THEN ' needs rework' ELSE ' scrapped' END),
      CONCAT('Inspection ', NEW.report_number, IF(IFNULL(NEW.rework_notes, '') = '', '.', CONCAT(': ', LEFT(NEW.rework_notes, 200)))),
      CONCAT('/quality/', NEW.id), 'QC,Production');
  END IF;
END $$

DROP TRIGGER IF EXISTS trg_po_notify $$
CREATE TRIGGER trg_po_notify AFTER UPDATE ON purchase_orders FOR EACH ROW
BEGIN
  DECLARE v_supplier VARCHAR(190);
  DECLARE CONTINUE HANDLER FOR SQLEXCEPTION BEGIN END;
  IF NEW.status <> OLD.status AND NEW.status IN ('PARTIAL', 'RECEIVED') THEN
    SELECT name INTO v_supplier FROM suppliers WHERE id = NEW.supplier_id;
    CALL sp_notify_event('MATERIAL', NEW.id, 'UPDATE',
      CONCAT('Goods received: ', NEW.po_number, IF(NEW.status = 'PARTIAL', ' (part)', '')),
      CONCAT(IFNULL(v_supplier, 'Supplier'), '. The stock is booked in.'), CONCAT('/purchase-orders/', NEW.id), 'PurchaseOrders,Stock');
  END IF;
END $$

DROP TRIGGER IF EXISTS trg_payment_notify $$
CREATE TRIGGER trg_payment_notify AFTER UPDATE ON payment_entries FOR EACH ROW
BEGIN
  DECLARE v_party VARCHAR(190);
  DECLARE CONTINUE HANDLER FOR SQLEXCEPTION BEGIN END;
  IF NEW.status = 'SUBMITTED' AND OLD.status <> 'SUBMITTED' THEN
    IF NEW.party_type = 'CUSTOMER' THEN
      SELECT name INTO v_party FROM customers WHERE id = NEW.customer_id;
      CALL sp_notify_event('FINANCE', NEW.id, 'SUCCESS', CONCAT('Payment received: Rs ', NEW.amount),
        CONCAT(IFNULL(v_party, 'Customer'), ' (', NEW.payment_number, ')'), '/payments', 'Accounts');
    ELSE
      SELECT name INTO v_party FROM suppliers WHERE id = NEW.supplier_id;
      CALL sp_notify_event('FINANCE', NEW.id, 'UPDATE', CONCAT('Payment made: Rs ', NEW.amount),
        CONCAT(IFNULL(v_party, 'Supplier'), ' (', NEW.payment_number, ')'), '/payments', 'Accounts');
    END IF;
  END IF;
END $$

-- stock goes low the moment it drops (and the alert clears when it is topped up)
DROP TRIGGER IF EXISTS trg_sle_low_stock $$
CREATE TRIGGER trg_sle_low_stock AFTER INSERT ON stock_ledger_entries FOR EACH ROW
BEGIN
  IF NEW.movement_type <> 'TRANSFER' THEN
    CALL sp_check_low_stock(NEW.item_id);
  END IF;
END $$

DELIMITER ;