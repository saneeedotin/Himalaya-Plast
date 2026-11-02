-- =============================================================================
-- HPOS Factory OS  |  04_masters_orders_production.sql
-- Masters (customers, suppliers, items, workstations, BOM), sales orders with the
-- strict material gate, work orders, job cards, shop-floor logging.
-- Error messages stay under 128 characters (MySQL limit for MESSAGE_TEXT).
-- =============================================================================
USE hpos;

DELIMITER $$

-- =============================================================================
-- MASTERS
-- =============================================================================

-- Create (p_id NULL) or update (p_id set).  Returns the id in p_out_id.
DROP PROCEDURE IF EXISTS sp_customer_save $$
CREATE PROCEDURE sp_customer_save(
  IN p_actor CHAR(36), IN p_id CHAR(36), IN p_name VARCHAR(190), IN p_gstin VARCHAR(15),
  IN p_email VARCHAR(190), IN p_phone VARCHAR(40), OUT p_out_id CHAR(36))
BEGIN
  IF p_id IS NULL THEN
    CALL sp_require_permission(p_actor, 'Customers', 'create');
    SET p_out_id = UUID();
    INSERT INTO customers (id, name, gstin, email, phone)
    VALUES (p_out_id, p_name, NULLIF(p_gstin,''), NULLIF(p_email,''), NULLIF(p_phone,''));
    CALL sp_audit(p_actor, 'CUSTOMER_CREATED', NULL, JSON_OBJECT('customerId', p_out_id));
  ELSE
    CALL sp_require_permission(p_actor, 'Customers', 'update');
    UPDATE customers SET name = p_name, gstin = NULLIF(p_gstin,''),
           email = NULLIF(p_email,''), phone = NULLIF(p_phone,'')
     WHERE id = p_id;
    SET p_out_id = p_id;
    CALL sp_audit(p_actor, 'CUSTOMER_UPDATED', NULL, JSON_OBJECT('customerId', p_id));
  END IF;
END $$

DROP PROCEDURE IF EXISTS sp_customer_delete $$
CREATE PROCEDURE sp_customer_delete(IN p_actor CHAR(36), IN p_id CHAR(36))
BEGIN
  CALL sp_require_permission(p_actor, 'Customers', 'delete');
  IF EXISTS (SELECT 1 FROM sales_orders WHERE customer_id = p_id)
     OR EXISTS (SELECT 1 FROM sales_invoices WHERE customer_id = p_id) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Customer has orders or invoices and cannot be deleted';
  END IF;
  DELETE FROM customers WHERE id = p_id;
  CALL sp_audit(p_actor, 'CUSTOMER_DELETED', NULL, JSON_OBJECT('customerId', p_id));
END $$

DROP PROCEDURE IF EXISTS sp_customer_follow_up_log $$
CREATE PROCEDURE sp_customer_follow_up_log(
  IN p_actor CHAR(36), IN p_customer_id CHAR(36), IN p_notes TEXT,
  IN p_expected_next DATETIME, IN p_reminder DATETIME, OUT p_out_id CHAR(36))
BEGIN
  CALL sp_require_permission(p_actor, 'Customers', 'update');
  SET p_out_id = UUID();
  INSERT INTO customer_follow_ups
    (id, customer_id, notes, expected_next_order_date, reminder_date, assigned_user_id,
     last_order_date)
  VALUES
    (p_out_id, p_customer_id, p_notes, p_expected_next, p_reminder, p_actor,
     (SELECT MAX(transaction_date) FROM sales_orders WHERE customer_id = p_customer_id));
END $$

DROP PROCEDURE IF EXISTS sp_customer_follow_up_set_status $$
CREATE PROCEDURE sp_customer_follow_up_set_status(IN p_actor CHAR(36), IN p_id CHAR(36), IN p_status VARCHAR(20))
BEGIN
  CALL sp_require_permission(p_actor, 'Customers', 'update');
  IF p_status NOT IN ('PENDING','COMPLETED','DISMISSED') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid follow-up status';
  END IF;
  UPDATE customer_follow_ups SET reminder_status = p_status WHERE id = p_id;
END $$

DROP PROCEDURE IF EXISTS sp_customer_follow_up_delete $$
CREATE PROCEDURE sp_customer_follow_up_delete(IN p_actor CHAR(36), IN p_id CHAR(36))
BEGIN
  CALL sp_require_permission(p_actor, 'Customers', 'update');
  DELETE FROM customer_follow_ups WHERE id = p_id;
END $$

DROP PROCEDURE IF EXISTS sp_supplier_save $$
CREATE PROCEDURE sp_supplier_save(
  IN p_actor CHAR(36), IN p_id CHAR(36), IN p_name VARCHAR(190), IN p_gstin VARCHAR(15),
  IN p_email VARCHAR(190), IN p_phone VARCHAR(40), IN p_status VARCHAR(20), OUT p_out_id CHAR(36))
BEGIN
  IF p_id IS NULL THEN
    CALL sp_require_permission(p_actor, 'Suppliers', 'create');
    SET p_out_id = UUID();
    INSERT INTO suppliers (id, name, gstin, email, phone, status)
    VALUES (p_out_id, p_name, NULLIF(p_gstin,''), NULLIF(p_email,''), NULLIF(p_phone,''), IFNULL(p_status,'ACTIVE'));
  ELSE
    CALL sp_require_permission(p_actor, 'Suppliers', 'update');
    UPDATE suppliers SET name = p_name, gstin = NULLIF(p_gstin,''), email = NULLIF(p_email,''),
           phone = NULLIF(p_phone,''), status = IFNULL(p_status, status)
     WHERE id = p_id;
    SET p_out_id = p_id;
  END IF;
END $$

DROP PROCEDURE IF EXISTS sp_supplier_delete $$
CREATE PROCEDURE sp_supplier_delete(IN p_actor CHAR(36), IN p_id CHAR(36))
BEGIN
  CALL sp_require_permission(p_actor, 'Suppliers', 'delete');
  IF EXISTS (SELECT 1 FROM purchase_orders WHERE supplier_id = p_id)
     OR EXISTS (SELECT 1 FROM purchase_invoices WHERE supplier_id = p_id) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Supplier has purchase records; mark it INACTIVE instead';
  END IF;
  DELETE FROM suppliers WHERE id = p_id;
END $$

DROP PROCEDURE IF EXISTS sp_supplier_lead_save $$
CREATE PROCEDURE sp_supplier_lead_save(
  IN p_actor CHAR(36), IN p_id CHAR(36), IN p_company VARCHAR(190), IN p_contact VARCHAR(190),
  IN p_material VARCHAR(190), IN p_source VARCHAR(100), IN p_status VARCHAR(20),
  IN p_next_follow_up DATETIME, IN p_notes TEXT, OUT p_out_id CHAR(36))
BEGIN
  CALL sp_require_permission(p_actor, 'Suppliers', IF(p_id IS NULL, 'create', 'update'));
  IF p_id IS NULL THEN
    SET p_out_id = UUID();
    INSERT INTO supplier_leads (id, company_name, contact_name, material, source, status, next_follow_up, notes, owner_id)
    VALUES (p_out_id, p_company, p_contact, p_material, p_source, IFNULL(p_status,'NEW'), p_next_follow_up, p_notes, p_actor);
  ELSE
    UPDATE supplier_leads SET company_name = p_company, contact_name = p_contact, material = p_material,
           source = p_source, status = IFNULL(p_status, status), next_follow_up = p_next_follow_up, notes = p_notes
     WHERE id = p_id;
    SET p_out_id = p_id;
  END IF;
END $$

DROP PROCEDURE IF EXISTS sp_item_save $$
CREATE PROCEDURE sp_item_save(
  IN p_actor CHAR(36), IN p_id CHAR(36), IN p_code VARCHAR(60), IN p_name VARCHAR(255),
  IN p_category VARCHAR(30), IN p_uom VARCHAR(20), IN p_hsn VARCHAR(12),
  IN p_standard_cost DECIMAL(16,2), IN p_min_stock DECIMAL(16,3), IN p_qc_template JSON,
  OUT p_out_id CHAR(36))
BEGIN
  IF p_id IS NULL THEN
    CALL sp_require_permission(p_actor, 'Items', 'create');
    SET p_out_id = UUID();
    INSERT INTO items (id, code, name, category, uom, hsn_code, standard_cost, min_stock_level, qc_template)
    VALUES (p_out_id, p_code, p_name, p_category, p_uom, NULLIF(p_hsn,''),
            IFNULL(p_standard_cost,0), IFNULL(p_min_stock,0), p_qc_template);
  ELSE
    CALL sp_require_permission(p_actor, 'Items', 'update');
    UPDATE items SET code = p_code, name = p_name, category = p_category, uom = p_uom,
           hsn_code = NULLIF(p_hsn,''), standard_cost = IFNULL(p_standard_cost,0),
           min_stock_level = IFNULL(p_min_stock,0), qc_template = p_qc_template
     WHERE id = p_id;
    SET p_out_id = p_id;
  END IF;
END $$

DROP PROCEDURE IF EXISTS sp_item_delete $$
CREATE PROCEDURE sp_item_delete(IN p_actor CHAR(36), IN p_id CHAR(36))
BEGIN
  CALL sp_require_permission(p_actor, 'Items', 'delete');
  IF EXISTS (SELECT 1 FROM stock_ledger_entries WHERE item_id = p_id)
     OR EXISTS (SELECT 1 FROM sales_order_items WHERE item_id = p_id)
     OR EXISTS (SELECT 1 FROM purchase_order_items WHERE item_id = p_id) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Item is used in transactions and cannot be deleted';
  END IF;
  DELETE FROM items WHERE id = p_id;
END $$

DROP PROCEDURE IF EXISTS sp_workstation_save $$
CREATE PROCEDURE sp_workstation_save(
  IN p_actor CHAR(36), IN p_id CHAR(36), IN p_code VARCHAR(40), IN p_name VARCHAR(190),
  IN p_hourly_rate DECIMAL(16,2), IN p_status VARCHAR(20), OUT p_out_id CHAR(36))
BEGIN
  IF p_id IS NULL THEN
    CALL sp_require_permission(p_actor, 'Workstations', 'create');
    SET p_out_id = UUID();
    INSERT INTO workstations (id, code, name, hourly_rate, status)
    VALUES (p_out_id, p_code, p_name, IFNULL(p_hourly_rate,0), IFNULL(p_status,'IDLE'));
  ELSE
    CALL sp_require_permission(p_actor, 'Workstations', 'update');
    UPDATE workstations SET code = p_code, name = p_name, hourly_rate = IFNULL(p_hourly_rate,0),
           status = IFNULL(p_status, status)
     WHERE id = p_id;
    SET p_out_id = p_id;
  END IF;
END $$

DROP PROCEDURE IF EXISTS sp_workstation_delete $$
CREATE PROCEDURE sp_workstation_delete(IN p_actor CHAR(36), IN p_id CHAR(36))
BEGIN
  CALL sp_require_permission(p_actor, 'Workstations', 'delete');
  IF EXISTS (SELECT 1 FROM job_cards WHERE workstation_id = p_id) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Workstation has job cards and cannot be deleted';
  END IF;
  DELETE FROM workstations WHERE id = p_id;
END $$

DROP PROCEDURE IF EXISTS sp_die_save $$
CREATE PROCEDURE sp_die_save(
  IN p_actor CHAR(36), IN p_id CHAR(36), IN p_code VARCHAR(40), IN p_name VARCHAR(190),
  IN p_threshold DECIMAL(12,2), IN p_status VARCHAR(20), OUT p_out_id CHAR(36))
BEGIN
  IF p_id IS NULL THEN
    CALL sp_require_permission(p_actor, 'Workstations', 'create');
    SET p_out_id = UUID();
    INSERT INTO dies (id, code, name, maintenance_threshold_hours, status)
    VALUES (p_out_id, p_code, p_name, IFNULL(p_threshold, 5000), IFNULL(p_status, 'ACTIVE'));
  ELSE
    CALL sp_require_permission(p_actor, 'Workstations', 'update');
    UPDATE dies SET code = p_code, name = p_name,
           maintenance_threshold_hours = IFNULL(p_threshold, maintenance_threshold_hours),
           status = IFNULL(p_status, status)
     WHERE id = p_id;
    SET p_out_id = p_id;
  END IF;
END $$

DROP PROCEDURE IF EXISTS sp_die_delete $$
CREATE PROCEDURE sp_die_delete(IN p_actor CHAR(36), IN p_id CHAR(36))
BEGIN
  CALL sp_require_permission(p_actor, 'Workstations', 'delete');
  IF EXISTS (SELECT 1 FROM job_cards WHERE die_id = p_id) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Die is used on job cards and cannot be deleted';
  END IF;
  DELETE FROM dies WHERE id = p_id;
END $$

-- p_materials: '[{"rmItemId":"...","qtyPerUnit":0.052}, ...]'
DROP PROCEDURE IF EXISTS sp_bom_save $$
CREATE PROCEDURE sp_bom_save(
  IN p_actor CHAR(36), IN p_id CHAR(36), IN p_name VARCHAR(190), IN p_fg_item_id CHAR(36),
  IN p_output_qty DECIMAL(16,3), IN p_scrap_factor DECIMAL(6,4), IN p_materials JSON,
  OUT p_out_id CHAR(36))
BEGIN
  DECLARE v_expected INT DEFAULT IFNULL(JSON_LENGTH(p_materials), 0);
  DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;

  CALL sp_require_permission(p_actor, 'BOMs', IF(p_id IS NULL, 'create', 'update'));
  IF v_expected = 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A BOM needs at least one raw material';
  END IF;

  START TRANSACTION;
    IF p_id IS NULL THEN
      SET p_out_id = UUID();
      INSERT INTO boms (id, name, fg_item_id, output_qty, scrap_factor)
      VALUES (p_out_id, p_name, p_fg_item_id, IFNULL(p_output_qty,1), IFNULL(p_scrap_factor,0.025));
    ELSE
      SET p_out_id = p_id;
      UPDATE boms SET name = p_name, fg_item_id = p_fg_item_id,
             output_qty = IFNULL(p_output_qty,1), scrap_factor = IFNULL(p_scrap_factor,0.025)
       WHERE id = p_id;
      DELETE FROM bom_items WHERE bom_id = p_id;
    END IF;

    INSERT INTO bom_items (bom_id, rm_item_id, qty_per_unit)
    SELECT p_out_id, i.id, jt.qty_per_unit
      FROM JSON_TABLE(p_materials, '$[*]' COLUMNS (
             rm_item_id   CHAR(36)      PATH '$.rmItemId',
             qty_per_unit DECIMAL(14,6) PATH '$.qtyPerUnit')) jt
      JOIN items i ON i.id = (jt.rm_item_id COLLATE utf8mb4_unicode_ci);
    IF ROW_COUNT() <> v_expected THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'One or more BOM raw materials were not found';
    END IF;
  COMMIT;
END $$

DROP PROCEDURE IF EXISTS sp_bom_delete $$
CREATE PROCEDURE sp_bom_delete(IN p_actor CHAR(36), IN p_id CHAR(36))
BEGIN
  CALL sp_require_permission(p_actor, 'BOMs', 'delete');
  DELETE FROM boms WHERE id = p_id;
END $$

-- =============================================================================
-- SALES ORDERS
-- p_items = '[{"itemId":"...","qty":500,"rate":34.5}, ...]'
-- Line amounts and the order total are maintained by triggers (08). The "only DRAFT orders
-- are editable" rule is enforced in the procedures below.
-- =============================================================================
DROP PROCEDURE IF EXISTS sp_sales_order_create $$
CREATE PROCEDURE sp_sales_order_create(
  IN p_actor CHAR(36), IN p_customer_id CHAR(36), IN p_customer_name VARCHAR(190),
  IN p_customer_gstin VARCHAR(15), IN p_delivery_date DATETIME, IN p_approval_method VARCHAR(20),
  IN p_notes TEXT, IN p_items JSON, OUT p_out_id CHAR(36))
BEGIN
  DECLARE v_expected INT DEFAULT IFNULL(JSON_LENGTH(p_items), 0);
  DECLARE v_no VARCHAR(60);
  DECLARE v_name VARCHAR(190);
  DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;

  CALL sp_require_permission(p_actor, 'SalesOrders', 'create');
  IF v_expected = 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'An order needs at least one line item';
  END IF;

  SET v_name = NULLIF(p_customer_name, '');
  IF v_name IS NULL AND p_customer_id IS NOT NULL THEN
    SELECT name INTO v_name FROM customers WHERE id = p_customer_id;
  END IF;
  IF v_name IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Customer name is required';
  END IF;

  START TRANSACTION;
    CALL sp_next_doc_no('SO', v_no);
    SET p_out_id = UUID();
    INSERT INTO sales_orders
      (id, order_number, customer_id, customer_name, customer_gstin, delivery_date,
       approval_method, status, notes)
    VALUES
      (p_out_id, v_no, p_customer_id, v_name, NULLIF(p_customer_gstin,''), p_delivery_date,
       IFNULL(p_approval_method,'WHATSAPP'), 'DRAFT', p_notes);

    INSERT INTO sales_order_items (sales_order_id, item_id, qty, rate)
    SELECT p_out_id, i.id, jt.qty, jt.rate
      FROM JSON_TABLE(p_items, '$[*]' COLUMNS (
             item_id CHAR(36) PATH '$.itemId',
             qty     DECIMAL(16,3) PATH '$.qty',
             rate    DECIMAL(16,2) PATH '$.rate')) jt
      JOIN items i ON i.id = (jt.item_id COLLATE utf8mb4_unicode_ci);
    IF ROW_COUNT() <> v_expected THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'One or more order items were not found';
    END IF;
  COMMIT;
END $$

DROP PROCEDURE IF EXISTS sp_sales_order_update $$
CREATE PROCEDURE sp_sales_order_update(
  IN p_actor CHAR(36), IN p_id CHAR(36), IN p_customer_id CHAR(36), IN p_customer_name VARCHAR(190),
  IN p_customer_gstin VARCHAR(15), IN p_delivery_date DATETIME, IN p_approval_method VARCHAR(20),
  IN p_notes TEXT, IN p_items JSON)
BEGIN
  DECLARE v_expected INT DEFAULT IFNULL(JSON_LENGTH(p_items), 0);
  DECLARE v_status VARCHAR(30);
  DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;

  CALL sp_require_permission(p_actor, 'SalesOrders', 'update');
  IF v_expected = 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'An order needs at least one line item';
  END IF;

  START TRANSACTION;
    SELECT status INTO v_status FROM sales_orders WHERE id = p_id FOR UPDATE;
    IF v_status IS NULL THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Order not found';
    END IF;
    IF v_status NOT IN ('DRAFT','PROFORMA_SENT') THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Confirmed orders cannot be edited (stock is already reserved)';
    END IF;

    UPDATE sales_orders
       SET customer_id = p_customer_id, customer_name = p_customer_name,
           customer_gstin = NULLIF(p_customer_gstin,''), delivery_date = p_delivery_date,
           approval_method = IFNULL(p_approval_method, approval_method), notes = p_notes
     WHERE id = p_id;

    DELETE FROM sales_order_items WHERE sales_order_id = p_id;
    INSERT INTO sales_order_items (sales_order_id, item_id, qty, rate)
    SELECT p_id, i.id, jt.qty, jt.rate
      FROM JSON_TABLE(p_items, '$[*]' COLUMNS (
             item_id CHAR(36) PATH '$.itemId',
             qty     DECIMAL(16,3) PATH '$.qty',
             rate    DECIMAL(16,2) PATH '$.rate')) jt
      JOIN items i ON i.id = (jt.item_id COLLATE utf8mb4_unicode_ci);
    IF ROW_COUNT() <> v_expected THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'One or more order items were not found';
    END IF;
  COMMIT;
END $$

DROP PROCEDURE IF EXISTS sp_sales_order_delete $$
CREATE PROCEDURE sp_sales_order_delete(IN p_actor CHAR(36), IN p_id CHAR(36))
BEGIN
  DECLARE v_status VARCHAR(30);
  DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;
  CALL sp_require_permission(p_actor, 'SalesOrders', 'delete');
  SELECT status INTO v_status FROM sales_orders WHERE id = p_id;
  IF v_status IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Order not found';
  END IF;
  IF v_status NOT IN ('DRAFT','PROFORMA_SENT','CANCELLED') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Only draft or cancelled orders can be deleted';
  END IF;
  IF EXISTS (SELECT 1 FROM delivery_notes WHERE sales_order_id = p_id) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Order has delivery notes and cannot be deleted';
  END IF;
  START TRANSACTION;
    DELETE FROM stock_reservations WHERE sales_order_id = p_id;
    DELETE FROM sales_orders WHERE id = p_id;
    CALL sp_audit(p_actor, 'SALES_ORDER_DELETED', NULL, JSON_OBJECT('orderId', p_id));
  COMMIT;
END $$

-- Material check for the order cockpit (also the "why was my confirm refused" detail)
DROP PROCEDURE IF EXISTS sp_check_material_availability $$
CREATE PROCEDURE sp_check_material_availability(IN p_actor CHAR(36), IN p_order_id CHAR(36))
BEGIN
  CALL sp_require_permission(p_actor, 'SalesOrders', 'read');
  SELECT rm_item_id, rm_code, rm_name, rm_uom,
         ROUND(required_qty, 2)      AS required_qty,
         ROUND(physical_stock, 2)    AS physical_stock,
         ROUND(reserved_by_others,2) AS reserved_stock,
         ROUND(available_stock, 2)   AS available_stock,
         deficit_qty, status
    FROM v_order_material_check
   WHERE sales_order_id = p_order_id
   ORDER BY rm_code;
END $$

-- -----------------------------------------------------------------------------
-- STRICT HARD GATE: confirm an order only when 100 % of the raw material exists.
-- Everything happens in one transaction; the raw-material item rows are locked
-- first, so two people confirming orders that need the same compound cannot both
-- succeed on the same stock (the old app could double-allocate).
-- -----------------------------------------------------------------------------
DROP PROCEDURE IF EXISTS sp_sales_order_confirm $$
CREATE PROCEDURE sp_sales_order_confirm(
  IN p_actor CHAR(36), IN p_order_id CHAR(36), IN p_approval_method VARCHAR(20), IN p_notes TEXT)
BEGIN
  DECLARE v_status   VARCHAR(30);
  DECLARE v_number   VARCHAR(40);
  DECLARE v_deficits INT DEFAULT 0;
  DECLARE v_locked   INT DEFAULT 0;
  DECLARE v_reserved INT DEFAULT 0;
  DECLARE v_item_id  CHAR(36);
  DECLARE v_qty      DECIMAL(16,3);
  DECLARE v_wo_no    VARCHAR(60);
  DECLARE v_done     TINYINT DEFAULT 0;
  DECLARE v_msg      VARCHAR(255);
  DECLARE cur_fg CURSOR FOR
    SELECT soi.item_id, soi.qty
      FROM sales_order_items soi JOIN items i ON i.id = soi.item_id
     WHERE soi.sales_order_id = p_order_id AND i.category = 'FINISHED_GOODS';
  DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_done = 1;
  DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;

  CALL sp_require_permission(p_actor, 'SalesOrders', 'update');

  START TRANSACTION;
    SELECT status, order_number INTO v_status, v_number
      FROM sales_orders WHERE id = p_order_id FOR UPDATE;
    IF v_status IS NULL THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Order not found';
    END IF;
    IF v_status NOT IN ('DRAFT','PROFORMA_SENT') THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Order is already confirmed or closed';
    END IF;

    -- lock every raw material this order needs (serialises competing confirmations)
    SELECT COUNT(*) INTO v_locked FROM items
     WHERE id IN (SELECT rm_item_id FROM v_order_material_requirement WHERE sales_order_id = p_order_id)
     FOR UPDATE;

    SELECT COUNT(*) INTO v_deficits FROM v_order_material_check
     WHERE sales_order_id = p_order_id AND status = 'DEFICIT';
    IF v_deficits > 0 THEN
      SET v_msg = CONCAT('Strict Hard Gate: raw material deficit on ', v_deficits,
                         ' item(s). Create a Purchase Order first.');
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = v_msg;
    END IF;

    UPDATE sales_orders
       SET status = 'CONFIRMED', approval_method = IFNULL(p_approval_method, 'WHATSAPP'),
           confirmed_at = NOW(3), confirmed_by_id = p_actor,
           notes = IF(p_notes IS NULL OR p_notes = '', notes, p_notes)
     WHERE id = p_order_id;

    -- fresh reservations for this order
    DELETE FROM stock_reservations WHERE sales_order_id = p_order_id;
    INSERT INTO stock_reservations (item_id, sales_order_id, quantity, status)
    SELECT rm_item_id, p_order_id, ROUND(required_qty, 3), 'RESERVED'
      FROM v_order_material_check
     WHERE sales_order_id = p_order_id AND required_qty > 0;
    SET v_reserved = ROW_COUNT();

    -- auto-create one work order per finished-good line (if none exist yet)
    IF NOT EXISTS (SELECT 1 FROM work_orders WHERE sales_order_id = p_order_id) THEN
      SET v_done = 0;
      OPEN cur_fg;
      fg_loop: LOOP
        FETCH cur_fg INTO v_item_id, v_qty;
        IF v_done = 1 THEN LEAVE fg_loop; END IF;
        CALL sp_next_doc_no('PROD-WO', v_wo_no);
        INSERT INTO work_orders (work_order_number, sales_order_id, fg_item_id, planned_qty, status)
        VALUES (v_wo_no, p_order_id, v_item_id, v_qty, 'PENDING');
      END LOOP;
      CLOSE cur_fg;
    END IF;

    CALL sp_audit(p_actor, 'SALES_ORDER_CONFIRMED', NULL,
      JSON_OBJECT('orderId', p_order_id, 'orderNumber', v_number, 'reservedItemsCount', v_reserved));
  COMMIT;

  SELECT * FROM v_order_summary WHERE id = p_order_id;
END $$

-- Create a draft Purchase Order covering the shortage of an order
DROP PROCEDURE IF EXISTS sp_po_create_for_shortage $$
CREATE PROCEDURE sp_po_create_for_shortage(IN p_actor CHAR(36), IN p_order_id CHAR(36), OUT p_po_id CHAR(36))
BEGIN
  DECLARE v_supplier CHAR(36);
  DECLARE v_so_no VARCHAR(40);
  DECLARE v_po_no VARCHAR(60);
  DECLARE v_count INT DEFAULT 0;
  DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;

  CALL sp_require_permission(p_actor, 'PurchaseOrders', 'create');

  SELECT order_number INTO v_so_no FROM sales_orders WHERE id = p_order_id;
  IF v_so_no IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Order not found';
  END IF;
  SELECT COUNT(*) INTO v_count FROM v_order_material_check
   WHERE sales_order_id = p_order_id AND status = 'DEFICIT';
  IF v_count = 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'No material deficits found for this order';
  END IF;

  -- The old app invented a dummy supplier here. We refuse instead: a real supplier must exist.
  SELECT id INTO v_supplier FROM suppliers WHERE status = 'ACTIVE' ORDER BY created_at LIMIT 1;
  IF v_supplier IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'No ACTIVE supplier exists. Add a supplier first';
  END IF;

  START TRANSACTION;
    CALL sp_next_doc_no('BUY-PO', v_po_no);
    SET p_po_id = UUID();
    INSERT INTO purchase_orders (id, po_number, supplier_id, status, notes)
    VALUES (p_po_id, v_po_no, v_supplier, 'DRAFT',
            CONCAT('Auto-generated shortage replenishment for Sales Order ', v_so_no,
                   ' (', v_count, ' deficit items)'));

    INSERT INTO purchase_order_items (purchase_order_id, item_id, qty, rate)
    SELECT p_po_id, rm_item_id, CEIL(deficit_qty), COALESCE(NULLIF(standard_cost, 0), 100)
      FROM v_order_material_check
     WHERE sales_order_id = p_order_id AND status = 'DEFICIT';
  COMMIT;
END $$

DROP PROCEDURE IF EXISTS sp_sales_order_set_status $$
CREATE PROCEDURE sp_sales_order_set_status(
  IN p_actor CHAR(36), IN p_order_id CHAR(36), IN p_status VARCHAR(30),
  IN p_proforma_ref VARCHAR(80), IN p_notes TEXT)
BEGIN
  CALL sp_require_permission(p_actor, 'SalesOrders', 'update');

  IF p_status = 'CONFIRMED' THEN
    CALL sp_sales_order_confirm(p_actor, p_order_id, NULL, p_notes);
  ELSE
    IF p_status NOT IN ('DRAFT','PROFORMA_SENT','APPROVED','IN_PRODUCTION','READY_TO_DISPATCH',
                        'DISPATCH_PREP','DISPATCHED','COMPLETED','CANCELLED') THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid order status';
    END IF;
    UPDATE sales_orders
       SET status = p_status,
           proforma_sent_at = IF(p_status = 'PROFORMA_SENT', NOW(3), proforma_sent_at),
           proforma_ref     = IF(p_status = 'PROFORMA_SENT' AND p_proforma_ref IS NOT NULL, p_proforma_ref, proforma_ref)
     WHERE id = p_order_id;
    IF p_status = 'CANCELLED' THEN
      UPDATE stock_reservations SET status = 'CANCELLED'
       WHERE sales_order_id = p_order_id AND status = 'RESERVED';
    END IF;
    SELECT * FROM v_order_summary WHERE id = p_order_id;
  END IF;
END $$

-- Transporter / vehicle / LR details; creates the delivery note if needed
DROP PROCEDURE IF EXISTS sp_dispatch_gate_update $$
CREATE PROCEDURE sp_dispatch_gate_update(
  IN p_actor CHAR(36), IN p_order_id CHAR(36), IN p_transporter VARCHAR(190),
  IN p_vehicle VARCHAR(40), IN p_lr VARCHAR(60), IN p_consent_method VARCHAR(20), IN p_notes TEXT)
BEGIN
  DECLARE v_dn CHAR(36);
  DECLARE v_dn_no VARCHAR(60);
  DECLARE v_cust VARCHAR(190);
  DECLARE v_old_notes TEXT;
  DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;

  -- Dispatch staff record transport; this must not need SalesOrders:update, which would also let
  -- them confirm, edit or cancel orders.
  CALL sp_require_permission(p_actor, 'Dispatch', 'create');

  START TRANSACTION;
    SELECT customer_name, notes INTO v_cust, v_old_notes FROM sales_orders WHERE id = p_order_id FOR UPDATE;
    IF v_cust IS NULL THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Order not found';
    END IF;

    SELECT id INTO v_dn FROM delivery_notes WHERE sales_order_id = p_order_id ORDER BY created_at LIMIT 1;
    IF v_dn IS NULL THEN
      CALL sp_next_doc_no('DN', v_dn_no);
      SET v_dn = UUID();
      INSERT INTO delivery_notes (id, dn_number, sales_order_id, customer_name, transporter_name, vehicle_number, lr_number)
      VALUES (v_dn, v_dn_no, p_order_id, v_cust, p_transporter, p_vehicle, p_lr);
    ELSE
      UPDATE delivery_notes SET transporter_name = p_transporter, vehicle_number = p_vehicle, lr_number = p_lr
       WHERE id = v_dn;
    END IF;

    UPDATE sales_orders
       SET approval_method = IFNULL(NULLIF(p_consent_method,''), approval_method),
           notes = IF(p_notes IS NULL OR p_notes = '', notes,
                      CONCAT(IF(v_old_notes IS NULL OR v_old_notes = '', '', CONCAT(v_old_notes, '\n')),
                             '[Dispatch Consent (', IFNULL(p_consent_method,'-'), ')]: ', p_notes))
     WHERE id = p_order_id;
  COMMIT;

  SELECT v_dn AS delivery_note_id;
END $$

-- =============================================================================
-- WORK ORDERS / JOB CARDS
-- =============================================================================
DROP PROCEDURE IF EXISTS sp_work_order_create $$
CREATE PROCEDURE sp_work_order_create(
  IN p_actor CHAR(36), IN p_sales_order_id CHAR(36), IN p_fg_item_id CHAR(36),
  IN p_planned_qty DECIMAL(16,3), IN p_fg_batch_number VARCHAR(80), OUT p_out_id CHAR(36))
BEGIN
  DECLARE v_no VARCHAR(60);
  DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;
  CALL sp_require_permission(p_actor, 'WorkOrders', 'create');
  START TRANSACTION;
    CALL sp_next_doc_no('PROD-WO', v_no);
    SET p_out_id = UUID();
    INSERT INTO work_orders (id, work_order_number, sales_order_id, fg_item_id, planned_qty, fg_batch_number)
    VALUES (p_out_id, v_no, NULLIF(p_sales_order_id,''), p_fg_item_id, p_planned_qty, NULLIF(p_fg_batch_number,''));
  COMMIT;
END $$

-- NOTE: the old app wrote a second PRODUCTION ledger entry when a work order was
-- completed. Output is already posted as PRODUCTION_OUTPUT on every meter log, so
-- that doubled finished-goods stock. This version does NOT post it again.
DROP PROCEDURE IF EXISTS sp_work_order_update $$
CREATE PROCEDURE sp_work_order_update(
  IN p_actor CHAR(36), IN p_id CHAR(36), IN p_planned_qty DECIMAL(16,3),
  IN p_status VARCHAR(20), IN p_fg_batch_number VARCHAR(80))
BEGIN
  CALL sp_require_permission(p_actor, 'WorkOrders', 'update');
  IF p_status IS NOT NULL AND p_status NOT IN ('PENDING','IN_PROGRESS','COMPLETED','CANCELLED') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid work order status';
  END IF;
  UPDATE work_orders
     SET planned_qty     = IFNULL(p_planned_qty, planned_qty),
         status          = IFNULL(p_status, status),
         fg_batch_number = IFNULL(NULLIF(p_fg_batch_number,''), fg_batch_number),
         start_date      = IF(p_status = 'IN_PROGRESS' AND start_date IS NULL, NOW(3), start_date),
         end_date        = IF(p_status = 'COMPLETED' AND end_date IS NULL, NOW(3), end_date)
   WHERE id = p_id;
END $$

DROP PROCEDURE IF EXISTS sp_work_order_delete $$
CREATE PROCEDURE sp_work_order_delete(IN p_actor CHAR(36), IN p_id CHAR(36))
BEGIN
  CALL sp_require_permission(p_actor, 'WorkOrders', 'delete');
  IF EXISTS (SELECT 1 FROM work_orders WHERE id = p_id AND produced_qty > 0) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Work order already has production and cannot be deleted';
  END IF;
  DELETE FROM work_orders WHERE id = p_id;
END $$

DROP PROCEDURE IF EXISTS sp_job_card_create $$
CREATE PROCEDURE sp_job_card_create(
  IN p_actor CHAR(36), IN p_work_order_id CHAR(36), IN p_workstation_id CHAR(36),
  IN p_assigned_user CHAR(36), IN p_die_id CHAR(36), OUT p_out_id CHAR(36))
BEGIN
  CALL sp_require_permission(p_actor, 'WorkOrders', 'create');
  SET p_out_id = UUID();
  INSERT INTO job_cards (id, work_order_id, workstation_id, assigned_user_id, die_id)
  VALUES (p_out_id, p_work_order_id, p_workstation_id, NULLIF(p_assigned_user,''), NULLIF(p_die_id,''));
END $$

DROP PROCEDURE IF EXISTS sp_job_card_set_status $$
CREATE PROCEDURE sp_job_card_set_status(IN p_actor CHAR(36), IN p_id CHAR(36), IN p_status VARCHAR(20))
BEGIN
  DECLARE v_ws CHAR(36);
  DECLARE v_wo CHAR(36);
  DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;
  CALL sp_require_permission(p_actor, 'Production', 'update');
  IF p_status NOT IN ('QUEUED','ACTIVE','COMPLETED') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid job card status';
  END IF;
  START TRANSACTION;
    UPDATE job_cards
       SET status = p_status,
           started_at   = IF(p_status = 'ACTIVE' AND started_at IS NULL, NOW(3), started_at),
           completed_at = IF(p_status = 'COMPLETED', NOW(3), completed_at)
     WHERE id = p_id;
    SELECT workstation_id, work_order_id INTO v_ws, v_wo FROM job_cards WHERE id = p_id;
    IF p_status = 'ACTIVE' THEN
      UPDATE workstations SET status = 'RUNNING' WHERE id = v_ws;
      UPDATE work_orders SET status = 'IN_PROGRESS', start_date = IFNULL(start_date, NOW(3))
       WHERE id = v_wo AND status = 'PENDING';
    ELSEIF p_status = 'COMPLETED' THEN
      UPDATE workstations SET status = 'IDLE'
       WHERE id = v_ws AND NOT EXISTS (SELECT 1 FROM job_cards WHERE workstation_id = v_ws AND status = 'ACTIVE');
      -- the last job card is done and the planned quantity is made: the work order is complete
      UPDATE work_orders SET status = 'COMPLETED', end_date = IFNULL(end_date, NOW(3))
       WHERE id = v_wo AND status IN ('PENDING','IN_PROGRESS') AND produced_qty >= planned_qty
         AND NOT EXISTS (SELECT 1 FROM job_cards WHERE work_order_id = v_wo AND status IN ('QUEUED','ACTIVE'));
    END IF;
  COMMIT;
END $$

DROP PROCEDURE IF EXISTS sp_job_card_delete $$
CREATE PROCEDURE sp_job_card_delete(IN p_actor CHAR(36), IN p_id CHAR(36))
BEGIN
  CALL sp_require_permission(p_actor, 'WorkOrders', 'delete');
  IF EXISTS (SELECT 1 FROM job_cards WHERE id = p_id AND (good_qty > 0 OR scrap_qty > 0)) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Job card already has production and cannot be deleted';
  END IF;
  DELETE FROM job_cards WHERE id = p_id;
END $$

-- =============================================================================
-- SHOP FLOOR: production log (METERS / SCRAP)
--   METERS -> consumes raw material per BOM, posts finished-goods output, releases
--             the order's reservations, advances job card / work order / order status.
--   Negative-stock protection: the whole call fails if ANY ingredient is short.
--   p_job_card_id NULL = use the first ACTIVE job card.
--   Formula (unchanged): consumed = qty_per_unit * (meters / output_qty) / (1 - scrap_factor)
-- =============================================================================
DROP PROCEDURE IF EXISTS sp_production_log $$
CREATE PROCEDURE sp_production_log(
  IN p_actor CHAR(36), IN p_job_card_id CHAR(36), IN p_type VARCHAR(10), IN p_amount DECIMAL(16,3))
BEGIN
  DECLARE v_jc CHAR(36);
  DECLARE v_wo CHAR(36);
  DECLARE v_so CHAR(36);
  DECLARE v_fg CHAR(36);
  DECLARE v_locked INT DEFAULT 0;
  DECLARE v_short_code VARCHAR(60);
  DECLARE v_msg        VARCHAR(255);
  DECLARE v_scrap_item CHAR(36);
  DECLARE v_fg_batch_no VARCHAR(80);
  DECLARE v_fg_batch    CHAR(36);
  DECLARE v_fg_batch_item CHAR(36);
  DECLARE v_fg_uom      VARCHAR(20);
  DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;

  CALL sp_require_permission(p_actor, 'Production', 'create');
  IF p_type NOT IN ('METERS','SCRAP') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid type. Must be METERS or SCRAP';
  END IF;
  IF p_amount IS NULL OR p_amount <= 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A positive amount is required';
  END IF;

  START TRANSACTION;
    IF p_job_card_id IS NULL OR p_job_card_id = '' THEN
      SELECT id INTO v_jc FROM job_cards WHERE status = 'ACTIVE' ORDER BY started_at, created_at LIMIT 1 FOR UPDATE;
    ELSE
      SELECT id INTO v_jc FROM job_cards WHERE id = p_job_card_id FOR UPDATE;
    END IF;
    IF v_jc IS NULL THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'No active job card found';
    END IF;

    SELECT wo.id, wo.sales_order_id, wo.fg_item_id INTO v_wo, v_so, v_fg
      FROM job_cards jc JOIN work_orders wo ON wo.id = jc.work_order_id WHERE jc.id = v_jc;

    IF p_type = 'METERS' THEN
      -- lock the raw-material rows, then verify every ingredient has enough stock
      SELECT COUNT(*) INTO v_locked FROM items
       WHERE id IN (SELECT bi.rm_item_id FROM v_default_bom b JOIN bom_items bi ON bi.bom_id = b.id
                     WHERE b.fg_item_id = v_fg)
       FOR UPDATE;

      SELECT i.code INTO v_short_code
        FROM v_default_bom b
        JOIN bom_items bi ON bi.bom_id = b.id
        JOIN items i ON i.id = bi.rm_item_id
        LEFT JOIN v_stock_balance sb ON sb.item_id = bi.rm_item_id
       WHERE b.fg_item_id = v_fg
         AND COALESCE(sb.physical_stock, 0) <
             ROUND(bi.qty_per_unit * (p_amount / b.output_qty) / (1 - b.scrap_factor), 2)
       LIMIT 1;
      IF v_short_code IS NOT NULL THEN
        SET v_msg = CONCAT('Strict Negative Stock Protection: insufficient stock of ', v_short_code);
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = v_msg;
      END IF;

      -- 1. raw-material consumption
      INSERT INTO stock_ledger_entries (item_id, warehouse, quantity, movement_type, reference_id, notes)
      SELECT bi.rm_item_id, 'MAIN',
             ROUND(bi.qty_per_unit * (p_amount / b.output_qty) / (1 - b.scrap_factor), 2),
             'CONSUMPTION', v_wo,
             CONCAT('BOM consumption for +', p_amount, 'm extrusion on job card ', v_jc)
        FROM v_default_bom b JOIN bom_items bi ON bi.bom_id = b.id
       WHERE b.fg_item_id = v_fg;

      -- 1b. what the machine used from its own stock (material issued next to it), up to what it holds; the rest was
      --     simply taken from the store. Skipped where machine stock is not set up (table missing, error 1146).
      BEGIN
        DECLARE CONTINUE HANDLER FOR 1146 BEGIN END;
        INSERT INTO machine_stock_moves (workstation_id, item_id, kind, qty, reference_id, note, by_user_id, by_name)
        SELECT jc.workstation_id, c.rm_item_id, 'CONSUME', LEAST(c.consumed, ms.on_machine), v_jc,
               CONCAT('Used for +', p_amount, 'm extrusion'), p_actor, (SELECT name FROM users WHERE id = p_actor)
          FROM job_cards jc
          JOIN (SELECT bi.rm_item_id, ROUND(bi.qty_per_unit * (p_amount / b.output_qty) / (1 - b.scrap_factor), 2) AS consumed
                  FROM v_default_bom b JOIN bom_items bi ON bi.bom_id = b.id WHERE b.fg_item_id = v_fg) c ON 1 = 1
          JOIN (SELECT m.item_id, m.workstation_id, SUM(CASE m.kind WHEN 'ISSUE' THEN m.qty ELSE -m.qty END) AS on_machine
                  FROM machine_stock_moves m GROUP BY m.item_id, m.workstation_id) ms ON ms.item_id = c.rm_item_id AND ms.workstation_id = jc.workstation_id
         WHERE jc.id = v_jc AND LEAST(c.consumed, ms.on_machine) > 0;
      END;

      -- 2. release the sales order's reservations by the amount consumed
      IF v_so IS NOT NULL THEN
        UPDATE stock_reservations sr
          JOIN (SELECT bi.rm_item_id,
                       ROUND(bi.qty_per_unit * (p_amount / b.output_qty) / (1 - b.scrap_factor), 2) AS consumed
                  FROM v_default_bom b JOIN bom_items bi ON bi.bom_id = b.id
                 WHERE b.fg_item_id = v_fg) c ON c.rm_item_id = sr.item_id
           SET sr.status   = IF(sr.quantity <= c.consumed, 'CONSUMED', sr.status),
               sr.quantity = IF(sr.quantity <= c.consumed, 0, ROUND(sr.quantity - c.consumed, 3))
         WHERE sr.sales_order_id = v_so AND sr.status = 'RESERVED';
      END IF;

      -- 3. finished-goods output, into the work order's FG batch (named here if nobody named it yet)
      SELECT fg_batch_number INTO v_fg_batch_no FROM work_orders WHERE id = v_wo;
      IF v_fg_batch_no IS NULL OR v_fg_batch_no = '' THEN
        CALL sp_next_doc_no('BATCH-FG', v_fg_batch_no);
        UPDATE work_orders SET fg_batch_number = v_fg_batch_no WHERE id = v_wo;
      END IF;

      SELECT id, item_id INTO v_fg_batch, v_fg_batch_item FROM batches
       WHERE batch_number = v_fg_batch_no FOR UPDATE;
      IF v_fg_batch IS NULL THEN
        SET v_fg_batch = UUID();
        SELECT uom INTO v_fg_uom FROM items WHERE id = v_fg;
        INSERT INTO batches (id, batch_number, item_id, quantity, uom, source)
        VALUES (v_fg_batch, v_fg_batch_no, v_fg, 0, v_fg_uom, 'EXTRUSION');
      ELSEIF v_fg_batch_item <> v_fg THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The work order batch number belongs to a different item';
      END IF;
      UPDATE batches SET quantity = quantity + p_amount WHERE id = v_fg_batch;

      INSERT INTO stock_ledger_entries (item_id, batch_id, warehouse, quantity, movement_type, reference_id, notes)
      VALUES (v_fg, v_fg_batch, 'MAIN', p_amount, 'PRODUCTION_OUTPUT', v_wo,
              CONCAT('Extruded +', p_amount, ' meters on job card ', v_jc));

      -- 4. counters and statuses
      UPDATE job_cards SET good_qty = good_qty + p_amount,
             status = IF(status = 'QUEUED', 'ACTIVE', status),
             started_at = IFNULL(started_at, NOW(3))
       WHERE id = v_jc;
      UPDATE work_orders SET produced_qty = produced_qty + p_amount,
             status = IF(status = 'PENDING', 'IN_PROGRESS', status),
             start_date = IFNULL(start_date, NOW(3))
       WHERE id = v_wo;
      IF v_so IS NOT NULL THEN
        UPDATE sales_orders SET status = 'IN_PRODUCTION'
         WHERE id = v_so AND status IN ('CONFIRMED','APPROVED');
      END IF;

    ELSE  -- SCRAP (kg of purge / trim)
      UPDATE job_cards SET scrap_qty = scrap_qty + p_amount WHERE id = v_jc;

      -- Post scrap against a SCRAP-category item when one exists (kg). With none, the kg
      -- stay on the job card only: posting them against the finished-good item (metres)
      -- would take metres out of stock for every kilo of scrap.
      SELECT id INTO v_scrap_item FROM items WHERE category = 'SCRAP' ORDER BY created_at LIMIT 1;
      IF v_scrap_item IS NOT NULL THEN
        INSERT INTO stock_ledger_entries (item_id, warehouse, quantity, movement_type, reference_id, notes)
        VALUES (v_scrap_item, 'QC_HOLD', p_amount, 'RECOVERY', v_wo,
                CONCAT('Logged ', p_amount, 'kg purge/trim scrap on job card ', v_jc));
      END IF;
    END IF;
  COMMIT;

  SELECT good_qty, scrap_qty FROM job_cards WHERE id = v_jc;
END $$

-- Order-cockpit shortcut: "+100 m" on an order. Uses the order's first work order
-- and job card; creates the job card on the first free line if none exists.
-- (The old app silently invented work orders and fake item ids here; this one
--  raises an error instead.)
DROP PROCEDURE IF EXISTS sp_production_quick_log $$
CREATE PROCEDURE sp_production_quick_log(
  IN p_actor CHAR(36), IN p_order_id CHAR(36), IN p_meters DECIMAL(16,3), IN p_scrap_kg DECIMAL(16,3))
BEGIN
  DECLARE v_wo CHAR(36);
  DECLARE v_jc CHAR(36);
  DECLARE v_ws CHAR(36);
  DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;

  CALL sp_require_permission(p_actor, 'Production', 'create');

  SELECT id INTO v_wo FROM work_orders WHERE sales_order_id = p_order_id ORDER BY created_at LIMIT 1;
  IF v_wo IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'This order has no work order. Confirm the order first';
  END IF;

  SELECT id INTO v_jc FROM job_cards WHERE work_order_id = v_wo ORDER BY created_at LIMIT 1;
  IF v_jc IS NULL THEN
    SELECT id INTO v_ws FROM workstations ORDER BY (status = 'RUNNING') DESC, code LIMIT 1;
    IF v_ws IS NULL THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'No workstation defined';
    END IF;
    SET v_jc = UUID();
    INSERT INTO job_cards (id, work_order_id, workstation_id, status, started_at)
    VALUES (v_jc, v_wo, v_ws, 'ACTIVE', NOW(3));
  END IF;

  IF p_meters IS NOT NULL AND p_meters > 0 THEN
    CALL sp_production_log(p_actor, v_jc, 'METERS', p_meters);
  END IF;
  IF p_scrap_kg IS NOT NULL AND p_scrap_kg > 0 THEN
    CALL sp_production_log(p_actor, v_jc, 'SCRAP', p_scrap_kg);
  END IF;
END $$

-- Downtime ------------------------------------------------------------------
DROP PROCEDURE IF EXISTS sp_downtime_start $$
CREATE PROCEDURE sp_downtime_start(
  IN p_actor CHAR(36), IN p_workstation_id CHAR(36), IN p_job_card_id CHAR(36),
  IN p_reason_code VARCHAR(60), IN p_notes TEXT, OUT p_out_id CHAR(36))
BEGIN
  DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;
  CALL sp_require_permission(p_actor, 'Production', 'create');
  START TRANSACTION;
    SET p_out_id = UUID();
    INSERT INTO downtime_logs (id, workstation_id, job_card_id, reason_code, notes, logged_by_id)
    VALUES (p_out_id, p_workstation_id, NULLIF(p_job_card_id,''), p_reason_code, p_notes, p_actor);
    UPDATE workstations SET status = 'MAINTENANCE' WHERE id = p_workstation_id;
  COMMIT;
END $$

-- The old app never closed a stop; the machine stayed in MAINTENANCE for ever.
DROP PROCEDURE IF EXISTS sp_downtime_end $$
CREATE PROCEDURE sp_downtime_end(IN p_actor CHAR(36), IN p_id CHAR(36))
BEGIN
  DECLARE v_ws CHAR(36);
  DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;
  CALL sp_require_permission(p_actor, 'Production', 'update');
  START TRANSACTION;
    SELECT workstation_id INTO v_ws FROM downtime_logs WHERE id = p_id AND end_time IS NULL FOR UPDATE;
    IF v_ws IS NULL THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Downtime record not found or already closed';
    END IF;
    UPDATE downtime_logs
       SET end_time = NOW(3), duration_mins = TIMESTAMPDIFF(MINUTE, start_time, NOW(3))
     WHERE id = p_id;
    IF NOT EXISTS (SELECT 1 FROM downtime_logs WHERE workstation_id = v_ws AND end_time IS NULL) THEN
      UPDATE workstations
         SET status = IF(EXISTS (SELECT 1 FROM job_cards WHERE workstation_id = v_ws AND status = 'ACTIVE'),
                         'RUNNING', 'IDLE')
       WHERE id = v_ws;
    END IF;
  COMMIT;
END $$

-- Shift handover --------------------------------------------------------------
DROP PROCEDURE IF EXISTS sp_shift_handover_create $$
CREATE PROCEDURE sp_shift_handover_create(
  IN p_actor CHAR(36), IN p_shift_type VARCHAR(20), IN p_workstation_id CHAR(36),
  IN p_scrap_qty DECIMAL(16,3), IN p_notes TEXT, OUT p_out_id CHAR(36))
BEGIN
  CALL sp_require_permission(p_actor, 'Production', 'create');
  IF p_scrap_qty IS NOT NULL AND p_scrap_qty < 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Scrap quantity cannot be negative';
  END IF;
  SET p_out_id = UUID();
  INSERT INTO shift_handovers
    (id, shift_date, shift_type, workstation_id, outgoing_operator_id, scrap_generated_qty, notes, status)
  VALUES (p_out_id, NOW(3), p_shift_type, p_workstation_id, p_actor, IFNULL(p_scrap_qty,0), p_notes, 'PENDING');
END $$

DROP PROCEDURE IF EXISTS sp_shift_handover_accept $$
CREATE PROCEDURE sp_shift_handover_accept(IN p_actor CHAR(36), IN p_id CHAR(36))
BEGIN
  CALL sp_require_permission(p_actor, 'Production', 'create');
  UPDATE shift_handovers
     SET incoming_operator_id = p_actor, status = 'ACCEPTED', accepted_at = NOW(3)
   WHERE id = p_id AND status = 'PENDING';
  IF ROW_COUNT() = 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Handover not found or already accepted';
  END IF;
END $$

-- Tablet raw-material scanning (replaces the Laravel receive-rm / verify-mixing-bom stubs)
-- Receives one scanned bag/lot of a raw material into stock.
DROP PROCEDURE IF EXISTS sp_stock_receive_rm_bag $$
CREATE PROCEDURE sp_stock_receive_rm_bag(
  IN p_actor CHAR(36), IN p_barcode VARCHAR(60), IN p_item_code VARCHAR(60), IN p_qty DECIMAL(16,3))
BEGIN
  DECLARE v_item CHAR(36);
  DECLARE v_uom  VARCHAR(20);
  DECLARE v_name VARCHAR(255);
  DECLARE v_batch CHAR(36);
  DECLARE v_batch_no VARCHAR(80);
  DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;

  CALL sp_require_permission(p_actor, 'Stock', 'create');
  SELECT id, uom, name INTO v_item, v_uom, v_name FROM items
   WHERE code = IFNULL(NULLIF(p_item_code,''), 'RM-PVC-K67') AND category = 'RAW_MATERIAL';
  IF v_item IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Raw material item not found';
  END IF;
  IF p_barcode IS NULL OR p_barcode = '' THEN
    -- no barcode on the bag (the usual case): the lot gets the next serial number, BATCH-RM-<year>-<n>
    CALL sp_next_doc_no('BATCH-RM', v_batch_no);
    SET p_barcode = v_batch_no;
  ELSE
    -- a barcode or the supplier's own lot reference names the lot, and cannot be received twice
    SET v_batch_no = CONCAT('BATCH-RM-', p_barcode);
    IF EXISTS (SELECT 1 FROM batches WHERE batch_number = v_batch_no) THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'This lot reference has already been received';
    END IF;
  END IF;

  START TRANSACTION;
    SET v_batch = UUID();
    INSERT INTO batches (id, batch_number, item_id, quantity, uom, source)
    VALUES (v_batch, v_batch_no, v_item, IFNULL(p_qty, 25), v_uom, 'PURCHASE');
    INSERT INTO stock_ledger_entries (item_id, batch_id, warehouse, quantity, movement_type, reference_id, notes)
    VALUES (v_item, v_batch, 'MAIN', IFNULL(p_qty, 25), 'RECEIPT', p_barcode, 'Received into stock');
  COMMIT;

  SELECT TRUE AS success, v_batch_no AS batch_number, v_name AS material, IFNULL(p_qty,25) AS qty, NOW(3) AS received_at;
END $$

-- Stock adjustment: opening stock, a count correction, or damage and loss. The only way to
-- change stock without a purchase, production run or dispatch, so it always leaves a ledger
-- entry (ADJUSTMENT_IN / ADJUSTMENT_OUT) and an audit row. Adding makes a new BATCH-ADJ lot so
-- the stock can be traced and inspected; removing never takes more than the free (unreserved) stock.
DROP PROCEDURE IF EXISTS sp_stock_adjust $$
CREATE PROCEDURE sp_stock_adjust(
  IN p_actor CHAR(36), IN p_item_id CHAR(36), IN p_direction VARCHAR(3), IN p_qty DECIMAL(16,3),
  IN p_reason VARCHAR(20), IN p_notes VARCHAR(400), IN p_ip VARCHAR(64), OUT p_batch_no VARCHAR(80))
BEGIN
  DECLARE v_uom VARCHAR(20);
  DECLARE v_phys DECIMAL(16,3) DEFAULT 0;
  DECLARE v_res  DECIMAL(16,3) DEFAULT 0;
  DECLARE v_batch CHAR(36);
  DECLARE v_note VARCHAR(500);
  DECLARE v_msg VARCHAR(200);
  DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;

  CALL sp_require_permission(p_actor, 'Stock', 'create');

  IF p_direction IS NULL OR p_direction NOT IN ('IN','OUT') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Choose whether stock is being added or removed';
  END IF;
  IF p_qty IS NULL OR p_qty <= 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The quantity must be above zero';
  END IF;
  IF p_reason IS NULL OR p_reason NOT IN ('OPENING','COUNT','DAMAGE','OTHER') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Choose a reason for the adjustment';
  END IF;
  IF p_reason = 'OTHER' AND IFNULL(TRIM(p_notes), '') = '' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Describe the reason in the note';
  END IF;
  SELECT uom INTO v_uom FROM items WHERE id = p_item_id;
  IF v_uom IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Item not found';
  END IF;

  SET v_note = LEFT(CONCAT(CASE p_reason WHEN 'OPENING' THEN 'Opening stock' WHEN 'COUNT' THEN 'Stock count correction'
                                          WHEN 'DAMAGE' THEN 'Damage or loss' ELSE 'Adjustment' END,
                           IF(IFNULL(TRIM(p_notes), '') = '', '', CONCAT(': ', TRIM(p_notes)))), 500);

  START TRANSACTION;
    IF p_direction = 'IN' THEN
      CALL sp_next_doc_no('BATCH-ADJ', p_batch_no);
      SET v_batch = UUID();
      INSERT INTO batches (id, batch_number, item_id, quantity, uom, source)
      VALUES (v_batch, p_batch_no, p_item_id, p_qty, v_uom, 'ADJUSTMENT');
      INSERT INTO stock_ledger_entries (item_id, batch_id, warehouse, quantity, movement_type, reference_id, notes, is_opening)
      VALUES (p_item_id, v_batch, 'MAIN', p_qty, 'ADJUSTMENT_IN', 'ADJUSTMENT', v_note, IF(p_reason = 'OPENING', 1, 0));
    ELSE
      SELECT IFNULL(MAX(physical_stock), 0) INTO v_phys FROM v_stock_balance WHERE item_id = p_item_id;
      SELECT IFNULL(MAX(reserved_stock), 0) INTO v_res  FROM v_stock_reserved WHERE item_id = p_item_id;
      IF p_qty > v_phys - v_res THEN
        SET v_msg = CONCAT('Only ', TRIM(TRAILING '.' FROM TRIM(TRAILING '0' FROM FORMAT(GREATEST(v_phys - v_res, 0), 3))),
                           ' ', v_uom, ' is free to remove (the rest is reserved for orders or not in stock)');
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = v_msg;
      END IF;
      INSERT INTO stock_ledger_entries (item_id, warehouse, quantity, movement_type, reference_id, notes)
      VALUES (p_item_id, 'MAIN', p_qty, 'ADJUSTMENT_OUT', 'ADJUSTMENT', v_note);
    END IF;
    CALL sp_audit(p_actor, 'STOCK_ADJUSTED', p_ip,
                  JSON_OBJECT('item', p_item_id, 'direction', p_direction, 'qty', p_qty, 'reason', p_reason));
  COMMIT;
END $$

-- The raw-material lots to choose from on the mixing check (newest first). Needs Production:create, not Stock:read,
-- because the operator at the machine does the check.
DROP PROCEDURE IF EXISTS sp_mixing_lot_pick $$
CREATE PROCEDURE sp_mixing_lot_pick(IN p_actor CHAR(36))
BEGIN
  CALL sp_require_permission(p_actor, 'Production', 'create');
  SELECT b.batch_number, b.quantity, b.uom, b.created_at, i.code AS item_code, i.name AS item_name
    FROM batches b JOIN items i ON i.id = b.item_id
   WHERE i.category = 'RAW_MATERIAL'
   ORDER BY b.created_at DESC LIMIT 300;
END $$

-- Mixing check: both lots (chosen from the list, or scanned) must be raw materials listed in the job card's BOM.
-- Every scan is logged (MATCH / MISMATCH). Returns success + error text.
-- (The old Laravel version was a stub that only looked for the text "ERR".)
DROP PROCEDURE IF EXISTS sp_material_verify_mixing $$
CREATE PROCEDURE sp_material_verify_mixing(
  IN p_actor CHAR(36), IN p_job_card_id CHAR(36), IN p_barcode_1 VARCHAR(80), IN p_barcode_2 VARCHAR(80))
BEGIN
  DECLARE v_fg CHAR(36);
  DECLARE v_ok TINYINT DEFAULT 1;
  DECLARE v_b1 CHAR(36);  DECLARE v_i1 CHAR(36);
  DECLARE v_b2 CHAR(36);  DECLARE v_i2 CHAR(36);
  DECLARE v_in1 TINYINT DEFAULT 0;
  DECLARE v_in2 TINYINT DEFAULT 0;
  DECLARE v_msg VARCHAR(200) DEFAULT NULL;

  CALL sp_require_permission(p_actor, 'Production', 'create');

  SELECT wo.fg_item_id INTO v_fg FROM job_cards jc JOIN work_orders wo ON wo.id = jc.work_order_id
   WHERE jc.id = p_job_card_id;
  IF v_fg IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Job card not found';
  END IF;

  -- A lot is found by its batch number, or by the bag barcode it was received under
  -- (receiving a bag names its batch BATCH-RM-<barcode>), so either label can be scanned.
  SELECT id, item_id INTO v_b1, v_i1 FROM batches
   WHERE batch_number IN (p_barcode_1, CONCAT('BATCH-RM-', p_barcode_1)) ORDER BY (batch_number = p_barcode_1) DESC LIMIT 1;
  SELECT id, item_id INTO v_b2, v_i2 FROM batches
   WHERE batch_number IN (p_barcode_2, CONCAT('BATCH-RM-', p_barcode_2)) ORDER BY (batch_number = p_barcode_2) DESC LIMIT 1;

  IF v_b1 IS NOT NULL THEN
    SELECT EXISTS (SELECT 1 FROM v_default_bom b JOIN bom_items bi ON bi.bom_id = b.id
                    WHERE b.fg_item_id = v_fg AND bi.rm_item_id = v_i1) INTO v_in1;
    INSERT INTO material_scan_logs (job_card_id, scanned_batch_id, expected_item_id, status, scanned_by_id)
    VALUES (p_job_card_id, v_b1, v_i1, IF(v_in1 = 1, 'MATCH', 'MISMATCH'), p_actor);
  END IF;
  IF v_b2 IS NOT NULL THEN
    SELECT EXISTS (SELECT 1 FROM v_default_bom b JOIN bom_items bi ON bi.bom_id = b.id
                    WHERE b.fg_item_id = v_fg AND bi.rm_item_id = v_i2) INTO v_in2;
    INSERT INTO material_scan_logs (job_card_id, scanned_batch_id, expected_item_id, status, scanned_by_id)
    VALUES (p_job_card_id, v_b2, v_i2, IF(v_in2 = 1, 'MATCH', 'MISMATCH'), p_actor);
  END IF;

  IF v_b1 IS NULL OR v_b2 IS NULL OR v_in1 = 0 OR v_in2 = 0 OR v_i1 = v_i2 THEN
    SET v_ok = 0;
  END IF;

  -- say which lot is wrong, not just "mismatch"
  SET v_msg = CASE
    WHEN v_ok = 1 THEN NULL
    WHEN v_b1 IS NULL THEN CONCAT('Mismatch Detected! The first lot "', p_barcode_1, '" was not found. Check the label and scan it again.')
    WHEN v_b2 IS NULL THEN CONCAT('Mismatch Detected! The second lot "', p_barcode_2, '" was not found. Check the label and scan it again.')
    WHEN v_in1 = 0 THEN CONCAT('Mismatch Detected! The first lot (', (SELECT code FROM items WHERE id = v_i1), ') is not on this job''s bill of materials.')
    WHEN v_in2 = 0 THEN CONCAT('Mismatch Detected! The second lot (', (SELECT code FROM items WHERE id = v_i2), ') is not on this job''s bill of materials.')
    ELSE 'Mismatch Detected! Both lots are the same material. Scan two different raw materials.'
  END;
  SELECT v_ok AS success, v_msg AS error;
END $$

DELIMITER ;
