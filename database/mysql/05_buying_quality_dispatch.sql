-- =============================================================================
-- HPOS Factory OS  |  05_buying_quality_dispatch.sql
-- Purchase orders and goods receipt, QC inspections, carton packing, gate scan.
-- =============================================================================
USE hpos;

DELIMITER $$

-- =============================================================================
-- BUYING
-- p_items = '[{"itemId":"...","qty":1000,"rate":94.5}, ...]'
-- =============================================================================
DROP PROCEDURE IF EXISTS sp_po_create $$
CREATE PROCEDURE sp_po_create(
  IN p_actor CHAR(36), IN p_supplier_id CHAR(36), IN p_notes TEXT, IN p_items JSON, OUT p_out_id CHAR(36))
BEGIN
  DECLARE v_expected INT DEFAULT IFNULL(JSON_LENGTH(p_items), 0);
  DECLARE v_no VARCHAR(60);
  DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;

  CALL sp_require_permission(p_actor, 'PurchaseOrders', 'create');
  IF v_expected = 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A purchase order needs at least one line item';
  END IF;

  START TRANSACTION;
    CALL sp_next_doc_no('BUY-PO', v_no);
    SET p_out_id = UUID();
    INSERT INTO purchase_orders (id, po_number, supplier_id, status, notes)
    VALUES (p_out_id, v_no, p_supplier_id, 'DRAFT', p_notes);

    INSERT INTO purchase_order_items (purchase_order_id, item_id, qty, rate)
    SELECT p_out_id, i.id, jt.qty, jt.rate
      FROM JSON_TABLE(p_items, '$[*]' COLUMNS (
             item_id CHAR(36) PATH '$.itemId',
             qty     DECIMAL(16,3) PATH '$.qty',
             rate    DECIMAL(16,2) PATH '$.rate')) jt
      JOIN items i ON i.id = (jt.item_id COLLATE utf8mb4_unicode_ci);
    IF ROW_COUNT() <> v_expected THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'One or more purchase items were not found';
    END IF;
  COMMIT;
END $$

DROP PROCEDURE IF EXISTS sp_po_update $$
CREATE PROCEDURE sp_po_update(
  IN p_actor CHAR(36), IN p_id CHAR(36), IN p_supplier_id CHAR(36), IN p_notes TEXT, IN p_items JSON)
BEGIN
  DECLARE v_expected INT DEFAULT IFNULL(JSON_LENGTH(p_items), 0);
  DECLARE v_status VARCHAR(20);
  DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;

  CALL sp_require_permission(p_actor, 'PurchaseOrders', 'update');
  IF v_expected = 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A purchase order needs at least one line item';
  END IF;

  START TRANSACTION;
    SELECT status INTO v_status FROM purchase_orders WHERE id = p_id FOR UPDATE;
    IF v_status IS NULL THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Purchase order not found';
    END IF;
    IF v_status <> 'DRAFT' THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Only DRAFT purchase orders can be edited';
    END IF;

    UPDATE purchase_orders SET supplier_id = p_supplier_id, notes = p_notes WHERE id = p_id;
    DELETE FROM purchase_order_items WHERE purchase_order_id = p_id;
    INSERT INTO purchase_order_items (purchase_order_id, item_id, qty, rate)
    SELECT p_id, i.id, jt.qty, jt.rate
      FROM JSON_TABLE(p_items, '$[*]' COLUMNS (
             item_id CHAR(36) PATH '$.itemId',
             qty     DECIMAL(16,3) PATH '$.qty',
             rate    DECIMAL(16,2) PATH '$.rate')) jt
      JOIN items i ON i.id = (jt.item_id COLLATE utf8mb4_unicode_ci);
    IF ROW_COUNT() <> v_expected THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'One or more purchase items were not found';
    END IF;
  COMMIT;
END $$

DROP PROCEDURE IF EXISTS sp_po_set_status $$
CREATE PROCEDURE sp_po_set_status(IN p_actor CHAR(36), IN p_id CHAR(36), IN p_status VARCHAR(20))
BEGIN
  CALL sp_require_permission(p_actor, 'PurchaseOrders', 'update');
  IF p_status NOT IN ('DRAFT','SUBMITTED','PARTIAL','RECEIVED','CLOSED') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid purchase order status';
  END IF;
  -- stock only arrives through sp_po_receive / sp_grn_submit
  IF p_status = 'RECEIVED' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Use the Receive action to book stock into the warehouse';
  END IF;
  UPDATE purchase_orders SET status = p_status WHERE id = p_id;
END $$

DROP PROCEDURE IF EXISTS sp_po_delete $$
CREATE PROCEDURE sp_po_delete(IN p_actor CHAR(36), IN p_id CHAR(36))
BEGIN
  CALL sp_require_permission(p_actor, 'PurchaseOrders', 'delete');
  IF EXISTS (SELECT 1 FROM purchase_orders WHERE id = p_id AND status <> 'DRAFT') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Only DRAFT purchase orders can be deleted';
  END IF;
  DELETE FROM purchase_orders WHERE id = p_id;
END $$

-- One-click receipt of a whole PO: creates a batch per line and posts RECEIPT entries.
DROP PROCEDURE IF EXISTS sp_po_receive $$
CREATE PROCEDURE sp_po_receive(IN p_actor CHAR(36), IN p_po_id CHAR(36))
BEGIN
  DECLARE v_status VARCHAR(20);
  DECLARE v_po_no  VARCHAR(40);
  DECLARE v_item   CHAR(36);
  DECLARE v_qty    DECIMAL(16,3);
  DECLARE v_uom    VARCHAR(20);
  DECLARE v_batch  CHAR(36);
  DECLARE v_bno    VARCHAR(60);
  DECLARE v_lines  INT DEFAULT 0;
  DECLARE v_done   TINYINT DEFAULT 0;
  DECLARE cur CURSOR FOR
    SELECT poi.item_id, poi.qty, i.uom
      FROM purchase_order_items poi JOIN items i ON i.id = poi.item_id
     WHERE poi.purchase_order_id = p_po_id;
  DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_done = 1;
  DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;

  CALL sp_require_permission(p_actor, 'PurchaseOrders', 'update');

  START TRANSACTION;
    SELECT status, po_number INTO v_status, v_po_no FROM purchase_orders WHERE id = p_po_id FOR UPDATE;
    IF v_status IS NULL THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Purchase order not found';
    END IF;
    IF v_status IN ('RECEIVED','CLOSED') THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Purchase order already received';
    END IF;

    SET v_done = 0;
    OPEN cur;
    rcv_loop: LOOP
      FETCH cur INTO v_item, v_qty, v_uom;
      IF v_done = 1 THEN LEAVE rcv_loop; END IF;
      CALL sp_next_doc_no('BATCH-RM', v_bno);
      SET v_batch = UUID();
      INSERT INTO batches (id, batch_number, item_id, quantity, uom, source)
      VALUES (v_batch, v_bno, v_item, v_qty, v_uom, 'PURCHASE');
      INSERT INTO stock_ledger_entries (item_id, batch_id, warehouse, quantity, movement_type, reference_id, notes)
      VALUES (v_item, v_batch, 'MAIN', v_qty, 'RECEIPT', p_po_id, CONCAT('Received from PO ', v_po_no));
      SET v_lines = v_lines + 1;
    END LOOP;
    CLOSE cur;

    IF v_lines = 0 THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Purchase order has no line items';
    END IF;
    UPDATE purchase_orders SET status = 'RECEIVED' WHERE id = p_po_id;
    CALL sp_audit(p_actor, 'PO_RECEIVED', NULL, JSON_OBJECT('poId', p_po_id));
  COMMIT;
END $$

-- Goods receipt note (GRN): draft copies the PO lines, submit books the stock.
DROP PROCEDURE IF EXISTS sp_grn_create $$
CREATE PROCEDURE sp_grn_create(
  IN p_actor CHAR(36), IN p_po_id CHAR(36), IN p_receipt_date DATETIME, IN p_notes TEXT, OUT p_out_id CHAR(36))
BEGIN
  DECLARE v_supplier CHAR(36);
  DECLARE v_status VARCHAR(20);
  DECLARE v_no VARCHAR(60);
  DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;

  CALL sp_require_permission(p_actor, 'PurchaseOrders', 'update');
  SELECT supplier_id, status INTO v_supplier, v_status FROM purchase_orders WHERE id = p_po_id;
  IF v_supplier IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Purchase order not found';
  END IF;
  IF v_status IN ('RECEIVED','CLOSED') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Purchase order already received';
  END IF;

  START TRANSACTION;
    CALL sp_next_doc_no('GRN', v_no);
    SET p_out_id = UUID();
    INSERT INTO purchase_receipts (id, receipt_number, purchase_order_id, supplier_id, receipt_date, notes, status)
    VALUES (p_out_id, v_no, p_po_id, v_supplier, IFNULL(p_receipt_date, NOW(3)), p_notes, 'DRAFT');
    INSERT INTO purchase_receipt_items (purchase_receipt_id, item_id, qty, rate)
    SELECT p_out_id, item_id, qty, rate FROM purchase_order_items WHERE purchase_order_id = p_po_id;
  COMMIT;
END $$

DROP PROCEDURE IF EXISTS sp_grn_submit $$
CREATE PROCEDURE sp_grn_submit(IN p_actor CHAR(36), IN p_grn_id CHAR(36))
BEGIN
  DECLARE v_status VARCHAR(20);
  DECLARE v_no     VARCHAR(40);
  DECLARE v_po     CHAR(36);
  DECLARE v_line   CHAR(36);
  DECLARE v_item   CHAR(36);
  DECLARE v_qty    DECIMAL(16,3);
  DECLARE v_uom    VARCHAR(20);
  DECLARE v_batch  CHAR(36);
  DECLARE v_n      INT DEFAULT 0;
  DECLARE v_done   TINYINT DEFAULT 0;
  DECLARE cur CURSOR FOR
    SELECT pri.id, pri.item_id, pri.qty, i.uom
      FROM purchase_receipt_items pri JOIN items i ON i.id = pri.item_id
     WHERE pri.purchase_receipt_id = p_grn_id;
  DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_done = 1;
  DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;

  CALL sp_require_permission(p_actor, 'PurchaseOrders', 'update');

  START TRANSACTION;
    SELECT status, receipt_number, purchase_order_id INTO v_status, v_no, v_po
      FROM purchase_receipts WHERE id = p_grn_id FOR UPDATE;
    IF v_status IS NULL OR v_status <> 'DRAFT' THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid receipt or already submitted';
    END IF;

    SET v_done = 0;
    OPEN cur;
    grn_loop: LOOP
      FETCH cur INTO v_line, v_item, v_qty, v_uom;
      IF v_done = 1 THEN LEAVE grn_loop; END IF;
      SET v_n = v_n + 1;
      SET v_batch = UUID();
      INSERT INTO batches (id, batch_number, item_id, quantity, uom, source)
      VALUES (v_batch, CONCAT('BATCH-', v_no, '-', LPAD(v_n, 2, '0')), v_item, v_qty, v_uom, 'PURCHASE');
      UPDATE purchase_receipt_items SET batch_id = v_batch WHERE id = v_line;
      INSERT INTO stock_ledger_entries (item_id, batch_id, warehouse, quantity, movement_type, reference_id, notes)
      VALUES (v_item, v_batch, 'MAIN', v_qty, 'RECEIPT', p_grn_id, CONCAT('Purchase Receipt: ', v_no));
    END LOOP;
    CLOSE cur;

    UPDATE purchase_receipts SET status = 'SUBMITTED' WHERE id = p_grn_id;
    UPDATE purchase_orders SET status = 'CLOSED' WHERE id = v_po;
    CALL sp_audit(p_actor, 'GRN_SUBMITTED', NULL, JSON_OBJECT('receiptId', p_grn_id));
  COMMIT;
END $$

-- =============================================================================
-- QUALITY
-- p_qc_data = '{"pinSize":4.02,"width":12.04,"legThickness":1.5,"linearWeight":75.2,"fitTest":"PASS - Snug Fit"}'
-- The inspector name is taken from the logged-in user, never from the client.
-- A SCRAP verdict writes the whole batch off (once).
-- =============================================================================
DROP PROCEDURE IF EXISTS sp_qc_submit $$
CREATE PROCEDURE sp_qc_submit(
  IN p_actor CHAR(36), IN p_batch_number VARCHAR(80), IN p_status VARCHAR(20), IN p_sample_size INT,
  IN p_qc_data JSON, IN p_rework_notes TEXT, IN p_job_card_id CHAR(36), OUT p_out_id CHAR(36))
BEGIN
  DECLARE v_no VARCHAR(60);
  DECLARE v_inspector VARCHAR(190);
  DECLARE v_batch CHAR(36);
  DECLARE v_item CHAR(36);
  DECLARE v_qty DECIMAL(16,3);
  DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;

  CALL sp_require_permission(p_actor, 'QC', 'create');
  IF p_batch_number IS NULL OR p_batch_number = '' OR p_status IS NULL OR p_status = '' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Batch number and QC status are required';
  END IF;
  IF p_status NOT IN ('DRAFT','PENDING','PASS','REJECT','SCRAP','REWORK') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid QC status';
  END IF;
  SELECT name INTO v_inspector FROM users WHERE id = p_actor;

  START TRANSACTION;
    CALL sp_next_doc_no('QC', v_no);
    SET p_out_id = UUID();
    INSERT INTO quality_inspections
      (id, report_number, job_card_id, batch_number, status, sample_size, inspector_name,
       inspector_id, rework_notes, qc_data)
    VALUES
      (p_out_id, v_no, NULLIF(p_job_card_id,''), p_batch_number, p_status, IFNULL(p_sample_size,5),
       v_inspector, p_actor, NULLIF(p_rework_notes,''), p_qc_data);

    IF p_status = 'SCRAP' THEN
      SELECT id, item_id, quantity INTO v_batch, v_item, v_qty FROM batches
       WHERE batch_number = p_batch_number FOR UPDATE;
      IF v_batch IS NOT NULL THEN
        IF EXISTS (SELECT 1 FROM stock_ledger_entries
                    WHERE batch_id = v_batch AND movement_type = 'SCRAP' AND warehouse = 'QC_HOLD') THEN
          SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'This batch has already been scrapped';
        END IF;
        INSERT INTO stock_ledger_entries (item_id, batch_id, warehouse, quantity, movement_type, reference_id, notes)
        VALUES (v_item, v_batch, 'QC_HOLD', v_qty, 'SCRAP', p_out_id,
                CONCAT('QC Scrapped via Report ', v_no));
      END IF;
    END IF;

    CALL sp_audit(p_actor, 'QC_SUBMITTED', NULL,
      JSON_OBJECT('reportNumber', v_no, 'batchNumber', p_batch_number, 'status', p_status));
  COMMIT;
END $$

-- Order-cockpit shortcut: attach the inspection to the order's first job card / FG batch
DROP PROCEDURE IF EXISTS sp_qc_quick_submit $$
CREATE PROCEDURE sp_qc_quick_submit(
  IN p_actor CHAR(36), IN p_order_id CHAR(36), IN p_status VARCHAR(20), IN p_qc_data JSON, OUT p_out_id CHAR(36))
BEGIN
  DECLARE v_wo CHAR(36);
  DECLARE v_batch_no VARCHAR(80);
  DECLARE v_jc CHAR(36);

  CALL sp_require_permission(p_actor, 'QC', 'create');
  SELECT id, fg_batch_number INTO v_wo, v_batch_no FROM work_orders
   WHERE sales_order_id = p_order_id ORDER BY created_at LIMIT 1;
  IF v_wo IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'This order has no work order';
  END IF;
  IF v_batch_no IS NULL OR v_batch_no = '' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Work order has no finished-goods batch number yet';
  END IF;
  SELECT id INTO v_jc FROM job_cards WHERE work_order_id = v_wo ORDER BY created_at LIMIT 1;

  CALL sp_qc_submit(p_actor, v_batch_no, IFNULL(p_status,'PASS'), 5, p_qc_data, NULL, v_jc, p_out_id);
END $$

DROP PROCEDURE IF EXISTS sp_qc_get_template $$
CREATE PROCEDURE sp_qc_get_template(IN p_actor CHAR(36), IN p_batch_number VARCHAR(80))
BEGIN
  CALL sp_require_permission(p_actor, 'QC', 'read');
  SELECT i.id AS item_id, i.code, i.name, i.qc_template AS template
    FROM batches b JOIN items i ON i.id = b.item_id
   WHERE b.batch_number = p_batch_number;
END $$

-- =============================================================================
-- PACKING & DISPATCH
-- =============================================================================

-- p_items = '[{"batchId":"...","cartonCount":5,"qtyPerCarton":250}, ...]'
-- Creates a delivery note and serial-numbered cartons (CTN-2026-00001, ...).
DROP PROCEDURE IF EXISTS sp_delivery_note_create_and_pack $$
CREATE PROCEDURE sp_delivery_note_create_and_pack(
  IN p_actor CHAR(36), IN p_sales_order_id CHAR(36), IN p_customer_name VARCHAR(190),
  IN p_items JSON, OUT p_dn_id CHAR(36))
BEGIN
  DECLARE v_dn_no VARCHAR(60);
  DECLARE v_code  VARCHAR(60);
  DECLARE v_batch CHAR(36);
  DECLARE v_count INT;
  DECLARE v_qty   DECIMAL(16,3);
  DECLARE v_i     INT;
  DECLARE v_done  TINYINT DEFAULT 0;
  DECLARE cur CURSOR FOR
    SELECT jt.batch_id, jt.carton_count, jt.qty_per_carton
      FROM JSON_TABLE(p_items, '$[*]' COLUMNS (
             batch_id       CHAR(36)      PATH '$.batchId',
             carton_count   INT           PATH '$.cartonCount',
             qty_per_carton DECIMAL(16,3) PATH '$.qtyPerCarton')) jt;
  DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_done = 1;
  DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;

  CALL sp_require_permission(p_actor, 'Dispatch', 'create');
  IF IFNULL(JSON_LENGTH(p_items), 0) = 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Nothing to pack';
  END IF;
  IF NOT EXISTS (SELECT 1 FROM sales_orders WHERE id = p_sales_order_id) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Order not found';
  END IF;

  START TRANSACTION;
    CALL sp_next_doc_no('DN', v_dn_no);
    SET p_dn_id = UUID();
    INSERT INTO delivery_notes (id, dn_number, sales_order_id, customer_name, status)
    VALUES (p_dn_id, v_dn_no, p_sales_order_id, p_customer_name, 'DRAFT');

    SET v_done = 0;
    OPEN cur;
    pack_loop: LOOP
      FETCH cur INTO v_batch, v_count, v_qty;
      IF v_done = 1 THEN LEAVE pack_loop; END IF;
      IF v_count IS NULL OR v_count < 1 OR v_count > 2000 OR v_qty IS NULL OR v_qty <= 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Carton count (1-2000) and quantity must be positive';
      END IF;
      IF NOT EXISTS (SELECT 1 FROM batches WHERE id = v_batch) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Batch not found';
      END IF;
      SET v_i = 0;
      WHILE v_i < v_count DO
        CALL sp_next_doc_no('CTN', v_code);
        INSERT INTO carton_labels (carton_code, delivery_note_id, batch_id, quantity)
        VALUES (v_code, p_dn_id, v_batch, v_qty);
        SET v_i = v_i + 1;
      END WHILE;
    END LOOP;
    CLOSE cur;

    UPDATE sales_orders SET status = 'DISPATCH_PREP' WHERE id = p_sales_order_id;
  COMMIT;
END $$

-- Order-cockpit shortcut: add ONE carton to the order's delivery note (creates the note if needed)
DROP PROCEDURE IF EXISTS sp_carton_quick_serialize $$
CREATE PROCEDURE sp_carton_quick_serialize(
  IN p_actor CHAR(36), IN p_order_id CHAR(36), IN p_quantity DECIMAL(16,3), IN p_carton_code VARCHAR(80))
BEGIN
  DECLARE v_dn CHAR(36);
  DECLARE v_dn_no VARCHAR(60);
  DECLARE v_cust VARCHAR(190);
  DECLARE v_status VARCHAR(30);
  DECLARE v_fg CHAR(36);
  DECLARE v_batch CHAR(36);
  DECLARE v_code VARCHAR(80);
  DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;

  CALL sp_require_permission(p_actor, 'Dispatch', 'create');
  IF p_quantity IS NULL OR p_quantity <= 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Carton quantity must be positive';
  END IF;

  START TRANSACTION;
    SELECT customer_name, status INTO v_cust, v_status FROM sales_orders WHERE id = p_order_id FOR UPDATE;
    IF v_cust IS NULL THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Order not found';
    END IF;

    SELECT id INTO v_dn FROM delivery_notes WHERE sales_order_id = p_order_id ORDER BY created_at LIMIT 1;
    IF v_dn IS NULL THEN
      CALL sp_next_doc_no('DN', v_dn_no);
      SET v_dn = UUID();
      INSERT INTO delivery_notes (id, dn_number, sales_order_id, customer_name, status)
      VALUES (v_dn, v_dn_no, p_order_id, v_cust, 'DRAFT');
    END IF;

    -- batch: the work order's FG batch if named, else the newest extrusion batch of the ordered item
    SELECT b.id INTO v_batch
      FROM work_orders wo JOIN batches b ON b.batch_number = wo.fg_batch_number
     WHERE wo.sales_order_id = p_order_id ORDER BY wo.created_at LIMIT 1;
    IF v_batch IS NULL THEN
      SELECT b.id INTO v_batch
        FROM sales_order_items soi JOIN batches b ON b.item_id = soi.item_id AND b.source = 'EXTRUSION'
       WHERE soi.sales_order_id = p_order_id ORDER BY b.created_at DESC LIMIT 1;
    END IF;
    IF v_batch IS NULL THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'No finished-goods batch exists for this order yet';
    END IF;

    IF p_carton_code IS NULL OR p_carton_code = '' THEN
      CALL sp_next_doc_no('CTN', v_code);
    ELSE
      SET v_code = p_carton_code;
    END IF;
    INSERT INTO carton_labels (carton_code, delivery_note_id, batch_id, quantity)
    VALUES (v_code, v_dn, v_batch, p_quantity);

    IF v_status IN ('IN_PRODUCTION','CONFIRMED') THEN
      UPDATE sales_orders SET status = 'READY_TO_DISPATCH' WHERE id = p_order_id;
    END IF;
  COMMIT;

  SELECT v_code AS carton_code, v_dn AS delivery_note_id;
END $$

-- Dock-gate scan. Idempotent per carton: a repeat scan is refused with
-- SQLSTATE 45001 / 'ALREADY_SCANNED' unless p_override = 1 (supervisor re-scan).
-- On the FIRST scan of a carton the goods leave stock (DISPATCH ledger entry).
-- When the last carton of a delivery note is scanned the note and the order become DISPATCHED.
DROP PROCEDURE IF EXISTS sp_dispatch_scan $$
CREATE PROCEDURE sp_dispatch_scan(
  IN p_actor CHAR(36), IN p_carton_code VARCHAR(80), IN p_override TINYINT, IN p_override_note VARCHAR(500))
BEGIN
  DECLARE v_id      CHAR(36);
  DECLARE v_scanned TINYINT;
  DECLARE v_dn      CHAR(36);
  DECLARE v_batch   CHAR(36);
  DECLARE v_qty     DECIMAL(16,3);
  DECLARE v_item    CHAR(36);
  DECLARE v_so      CHAR(36);
  DECLARE v_total   INT;
  DECLARE v_scanned_cnt INT;
  DECLARE v_complete TINYINT DEFAULT 0;
  DECLARE v_stock   DECIMAL(16,3) DEFAULT 0;
  DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;

  CALL sp_require_permission(p_actor, 'Dispatch', 'scan');
  IF p_carton_code IS NULL OR p_carton_code = '' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Carton code is required';
  END IF;

  START TRANSACTION;
    SELECT id, scanned, delivery_note_id, batch_id, quantity
      INTO v_id, v_scanned, v_dn, v_batch, v_qty
      FROM carton_labels WHERE carton_code = p_carton_code FOR UPDATE;
    IF v_id IS NULL THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Carton code not recognized in system';
    END IF;
    IF v_scanned = 1 AND IFNULL(p_override, 0) = 0 THEN
      SIGNAL SQLSTATE '45001' SET MESSAGE_TEXT = 'ALREADY_SCANNED: this carton was already scanned';
    END IF;

    UPDATE carton_labels
       SET scanned = 1, scanned_by_id = p_actor, scanned_at = NOW(3),
           dispatch_override = IFNULL(p_override, 0),
           dispatch_override_note = IF(IFNULL(p_override,0) = 1, p_override_note, NULL)
     WHERE id = v_id;

    IF v_scanned = 0 THEN
      SELECT item_id INTO v_item FROM batches WHERE id = v_batch;
      -- goods that are not in stock cannot leave on a vehicle
      SELECT COALESCE(physical_stock, 0) INTO v_stock FROM v_stock_balance WHERE item_id = v_item;
      IF v_stock < v_qty THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Not enough finished-goods stock to dispatch this carton';
      END IF;
      INSERT INTO stock_ledger_entries (item_id, batch_id, warehouse, quantity, movement_type, reference_id, notes)
      VALUES (v_item, v_batch, 'MAIN', v_qty, 'DISPATCH', v_dn, CONCAT('Dispatched carton ', p_carton_code));
    END IF;

    SELECT COUNT(*), COALESCE(SUM(scanned), 0) INTO v_total, v_scanned_cnt
      FROM carton_labels WHERE delivery_note_id = v_dn;
    SET v_complete = (v_total > 0 AND v_total = v_scanned_cnt);

    IF v_complete = 1 THEN
      UPDATE delivery_notes SET status = 'DISPATCHED', dispatched_at = IFNULL(dispatched_at, NOW(3))
       WHERE id = v_dn AND status <> 'DISPATCHED';
      SELECT sales_order_id INTO v_so FROM delivery_notes WHERE id = v_dn;
      IF NOT EXISTS (SELECT 1 FROM delivery_notes WHERE sales_order_id = v_so AND status = 'DRAFT') THEN
        UPDATE sales_orders SET status = 'DISPATCHED'
         WHERE id = v_so AND status IN ('IN_PRODUCTION','READY_TO_DISPATCH','DISPATCH_PREP','CONFIRMED','APPROVED');
      END IF;
    END IF;
  COMMIT;

  SELECT c.carton_code, c.quantity, bt.batch_number, i.name AS item_name,
         CONCAT('Scanned carton ', c.carton_code, ' (', c.quantity, 'm ', i.name, ')') AS message,
         v_scanned_cnt AS scanned_count, v_total AS total_count, v_complete AS is_complete
    FROM carton_labels c
    JOIN batches bt ON bt.id = c.batch_id
    JOIN items i    ON i.id = bt.item_id
   WHERE c.id = v_id;
END $$

DELIMITER ;
