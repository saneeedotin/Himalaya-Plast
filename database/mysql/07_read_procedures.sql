-- =============================================================================
-- HPOS Factory OS  |  07_read_procedures.sql  -  permission-checked read models
--
-- The PHP application's database account should only have:
--     GRANT EXECUTE ON hpos.* TO 'hpos_app'@'localhost';
--     GRANT SELECT  ON hpos.v_* ... (optional, for report pages)
-- and NO direct INSERT/UPDATE/DELETE on tables. Then every change must go through
-- the procedures in 03-06 and every screen read can be checked here.
-- =============================================================================
USE hpos;

DELIMITER $$

-- ----------------------------- dashboard & stock ------------------------------
DROP PROCEDURE IF EXISTS sp_dashboard $$
CREATE PROCEDURE sp_dashboard(IN p_actor CHAR(36))
BEGIN
  CALL sp_require_permission(p_actor, 'Dashboard', 'read');
  -- the unread count is this person's own (read status is per person), so it replaces the view's global one
  SELECT k.open_orders, k.orders_in_production, k.overdue_orders, k.sales_this_month, k.low_stock_items,
         k.machines_running, k.machines_down, k.meters_today, k.qc_pending, k.receivables, k.payables,
         (SELECT COUNT(*) FROM notifications n
            LEFT JOIN notification_reads r ON r.notification_id = n.id AND r.user_id = p_actor
           WHERE n.status <> 'RESOLVED' AND r.user_id IS NULL AND fn_notif_visible(p_actor, n.audience) = 1) AS unread_notifications
    FROM v_kpi_dashboard k;
END $$

DROP PROCEDURE IF EXISTS sp_inventory_status $$
CREATE PROCEDURE sp_inventory_status(IN p_actor CHAR(36))
BEGIN
  CALL sp_require_permission(p_actor, 'Stock', 'read');
  SELECT * FROM v_inventory_status ORDER BY tier, name;
END $$

DROP PROCEDURE IF EXISTS sp_stock_ledger $$
CREATE PROCEDURE sp_stock_ledger(IN p_actor CHAR(36), IN p_item_id CHAR(36), IN p_limit INT)
BEGIN
  CALL sp_require_permission(p_actor, 'Stock', 'read');
  SET p_limit = IFNULL(p_limit, 100);   -- LIMIT accepts a variable, not an expression
  SELECT * FROM v_stock_ledger_recent
   WHERE p_item_id IS NULL OR item_id = p_item_id
   ORDER BY created_at DESC LIMIT p_limit;
END $$

DROP PROCEDURE IF EXISTS sp_batch_list $$
CREATE PROCEDURE sp_batch_list(IN p_actor CHAR(36), IN p_source VARCHAR(20))
BEGIN
  CALL sp_require_permission(p_actor, 'Stock', 'read');
  SELECT b.id, b.batch_number, b.item_id, i.code AS item_code, i.name AS item_name,
         b.quantity, b.uom, b.source, b.parent_batch_id, b.created_at
    FROM batches b JOIN items i ON i.id = b.item_id
   WHERE p_source IS NULL OR b.source = p_source
   ORDER BY b.created_at DESC;
END $$

-- ----------------------------- masters ---------------------------------------
DROP PROCEDURE IF EXISTS sp_customer_list $$
CREATE PROCEDURE sp_customer_list(IN p_actor CHAR(36))
BEGIN
  CALL sp_require_permission(p_actor, 'Customers', 'read');
  SELECT c.*, (SELECT MAX(expected_next_order_date) FROM customer_follow_ups f WHERE f.customer_id = c.id) AS next_expected_order,
         r.expected_next AS computed_next_order, r.reorder_status
    FROM customers c LEFT JOIN v_customer_reorder r ON r.customer_id = c.id ORDER BY c.name;
END $$

DROP PROCEDURE IF EXISTS sp_customer_get $$
CREATE PROCEDURE sp_customer_get(IN p_actor CHAR(36), IN p_id CHAR(36))
BEGIN
  CALL sp_require_permission(p_actor, 'Customers', 'read');
  SELECT * FROM customers WHERE id = p_id;
  SELECT * FROM customer_follow_ups WHERE customer_id = p_id ORDER BY created_at DESC;
  SELECT id, order_number, status, total_amount, transaction_date FROM sales_orders
   WHERE customer_id = p_id ORDER BY transaction_date DESC LIMIT 5;
  SELECT * FROM v_customer_ledger WHERE customer_id = p_id;
  SELECT * FROM v_customer_reorder WHERE customer_id = p_id;
END $$

DROP PROCEDURE IF EXISTS sp_supplier_list $$
CREATE PROCEDURE sp_supplier_list(IN p_actor CHAR(36))
BEGIN
  CALL sp_require_permission(p_actor, 'Suppliers', 'read');
  SELECT * FROM suppliers ORDER BY name;
END $$

DROP PROCEDURE IF EXISTS sp_supplier_get $$
CREATE PROCEDURE sp_supplier_get(IN p_actor CHAR(36), IN p_id CHAR(36))
BEGIN
  CALL sp_require_permission(p_actor, 'Suppliers', 'read');
  SELECT * FROM suppliers WHERE id = p_id;
  SELECT id, po_number, status, total_amount, order_date FROM purchase_orders
   WHERE supplier_id = p_id ORDER BY order_date DESC LIMIT 20;
END $$

DROP PROCEDURE IF EXISTS sp_supplier_lead_list $$
CREATE PROCEDURE sp_supplier_lead_list(IN p_actor CHAR(36))
BEGIN
  CALL sp_require_permission(p_actor, 'Suppliers', 'read');
  SELECT * FROM supplier_leads ORDER BY FIELD(status,'NEW','CONTACTED','QUALIFIED','REJECTED'), next_follow_up;
END $$

DROP PROCEDURE IF EXISTS sp_item_list $$
CREATE PROCEDURE sp_item_list(IN p_actor CHAR(36), IN p_category VARCHAR(30))
BEGIN
  CALL sp_require_permission(p_actor, 'Items', 'read');
  SELECT i.*, v.physical_stock, v.reserved_stock, v.available_stock, v.reorder_alert
    FROM items i JOIN v_inventory_status v ON v.item_id = i.id
   WHERE p_category IS NULL OR i.category = p_category
   ORDER BY i.category, i.name;
END $$

DROP PROCEDURE IF EXISTS sp_item_get $$
CREATE PROCEDURE sp_item_get(IN p_actor CHAR(36), IN p_id CHAR(36))
BEGIN
  CALL sp_require_permission(p_actor, 'Items', 'read');
  SELECT i.*, v.physical_stock, v.reserved_stock, v.available_stock, v.reorder_alert
    FROM items i JOIN v_inventory_status v ON v.item_id = i.id WHERE i.id = p_id;
  SELECT b.*, bi.rm_item_id, r.code AS rm_code, r.name AS rm_name, bi.qty_per_unit
    FROM boms b LEFT JOIN bom_items bi ON bi.bom_id = b.id LEFT JOIN items r ON r.id = bi.rm_item_id
   WHERE b.fg_item_id = p_id ORDER BY b.created_at;
  SELECT * FROM v_stock_ledger_recent WHERE item_id = p_id ORDER BY created_at DESC LIMIT 50;
END $$

DROP PROCEDURE IF EXISTS sp_bom_list $$
CREATE PROCEDURE sp_bom_list(IN p_actor CHAR(36))
BEGIN
  CALL sp_require_permission(p_actor, 'BOMs', 'read');
  SELECT b.*, fg.code AS fg_code, fg.name AS fg_name,
         (SELECT COUNT(*) FROM bom_items x WHERE x.bom_id = b.id) AS material_count
    FROM boms b JOIN items fg ON fg.id = b.fg_item_id ORDER BY fg.name;
END $$

DROP PROCEDURE IF EXISTS sp_bom_get $$
CREATE PROCEDURE sp_bom_get(IN p_actor CHAR(36), IN p_id CHAR(36))
BEGIN
  CALL sp_require_permission(p_actor, 'BOMs', 'read');
  SELECT b.*, fg.code AS fg_code, fg.name AS fg_name FROM boms b JOIN items fg ON fg.id = b.fg_item_id WHERE b.id = p_id;
  SELECT bi.id, bi.rm_item_id, r.code, r.name, r.uom, bi.qty_per_unit
    FROM bom_items bi JOIN items r ON r.id = bi.rm_item_id WHERE bi.bom_id = p_id ORDER BY r.code;
END $$

DROP PROCEDURE IF EXISTS sp_workstation_list $$
CREATE PROCEDURE sp_workstation_list(IN p_actor CHAR(36))
BEGIN
  CALL sp_require_permission(p_actor, 'Workstations', 'read');
  SELECT w.*, d.stops AS stops_30d, d.downtime_mins AS downtime_mins_30d, d.open_stops
    FROM workstations w JOIN v_downtime_30d d ON d.workstation_id = w.id ORDER BY w.code;
END $$

DROP PROCEDURE IF EXISTS sp_workstation_get $$
CREATE PROCEDURE sp_workstation_get(IN p_actor CHAR(36), IN p_id CHAR(36))
BEGIN
  CALL sp_require_permission(p_actor, 'Workstations', 'read');
  SELECT * FROM workstations WHERE id = p_id;
  SELECT * FROM v_job_card_live WHERE workstation_id = p_id ORDER BY started_at DESC LIMIT 20;
  SELECT dl.*, u.name AS logged_by FROM downtime_logs dl JOIN users u ON u.id = dl.logged_by_id
   WHERE dl.workstation_id = p_id ORDER BY dl.start_time DESC LIMIT 20;
END $$

DROP PROCEDURE IF EXISTS sp_die_list $$
CREATE PROCEDURE sp_die_list(IN p_actor CHAR(36))
BEGIN
  CALL sp_require_permission(p_actor, 'Workstations', 'read');
  SELECT *, (total_running_hours >= maintenance_threshold_hours) AS maintenance_due FROM dies ORDER BY code;
END $$

-- ----------------------------- sales orders ----------------------------------
DROP PROCEDURE IF EXISTS sp_order_list $$
CREATE PROCEDURE sp_order_list(IN p_actor CHAR(36), IN p_status VARCHAR(30))
BEGIN
  CALL sp_require_permission(p_actor, 'SalesOrders', 'read');
  SELECT * FROM v_order_summary WHERE p_status IS NULL OR status = p_status ORDER BY transaction_date DESC;
END $$

-- Result sets, in order:
--  1 order  2 lines  3 material check  4 work orders  5 job cards  6 reservations
--  7 delivery notes  8 cartons  9 QC reports  10 invoices
DROP PROCEDURE IF EXISTS sp_order_detail $$
CREATE PROCEDURE sp_order_detail(IN p_actor CHAR(36), IN p_id CHAR(36))
BEGIN
  CALL sp_require_permission(p_actor, 'SalesOrders', 'read');

  SELECT s.*, so.notes, so.proforma_ref, so.proforma_sent_at,
         c.email AS customer_email, c.phone AS customer_phone, u.name AS confirmed_by_name
    FROM v_order_summary s
    JOIN sales_orders so ON so.id = s.id
    LEFT JOIN customers c ON c.id = s.customer_id
    LEFT JOIN users u ON u.id = s.confirmed_by_id
   WHERE s.id = p_id;

  SELECT soi.id, soi.item_id, i.code, i.name, i.uom, soi.qty, soi.rate, soi.amount
    FROM sales_order_items soi JOIN items i ON i.id = soi.item_id WHERE soi.sales_order_id = p_id;

  SELECT rm_item_id, rm_code, rm_name, rm_uom, ROUND(required_qty,2) AS required_qty,
         ROUND(physical_stock,2) AS physical_stock, ROUND(reserved_by_others,2) AS reserved_stock,
         ROUND(available_stock,2) AS available_stock, deficit_qty, status
    FROM v_order_material_check WHERE sales_order_id = p_id ORDER BY rm_code;

  SELECT wo.*, fg.code AS fg_code, fg.name AS fg_name FROM work_orders wo
    JOIN items fg ON fg.id = wo.fg_item_id WHERE wo.sales_order_id = p_id ORDER BY wo.created_at;

  SELECT * FROM v_job_card_live WHERE sales_order_id = p_id ORDER BY started_at;

  SELECT sr.id, sr.item_id, i.code, i.name, i.uom, sr.quantity, sr.status
    FROM stock_reservations sr JOIN items i ON i.id = sr.item_id WHERE sr.sales_order_id = p_id;

  SELECT * FROM delivery_notes WHERE sales_order_id = p_id ORDER BY created_at;

  SELECT cl.*, dn.dn_number, bt.batch_number, it.name AS item_name, su.name AS scanned_by_name
    FROM carton_labels cl
    JOIN delivery_notes dn ON dn.id = cl.delivery_note_id
    JOIN batches bt ON bt.id = cl.batch_id
    JOIN items it ON it.id = bt.item_id
    LEFT JOIN users su ON su.id = cl.scanned_by_id
   WHERE dn.sales_order_id = p_id ORDER BY cl.created_at;

  SELECT qi.* FROM quality_inspections qi
   WHERE qi.batch_number IN (SELECT fg_batch_number FROM work_orders WHERE sales_order_id = p_id AND fg_batch_number IS NOT NULL)
      OR qi.job_card_id IN (SELECT jc.id FROM job_cards jc JOIN work_orders w ON w.id = jc.work_order_id WHERE w.sales_order_id = p_id)
   ORDER BY qi.inspected_at DESC;

  SELECT id, invoice_number, status, total_amount, outstanding, due_date FROM sales_invoices
   WHERE sales_order_id = p_id ORDER BY created_at;
END $$

-- ----------------------------- buying ----------------------------------------
DROP PROCEDURE IF EXISTS sp_po_list $$
CREATE PROCEDURE sp_po_list(IN p_actor CHAR(36))
BEGIN
  CALL sp_require_permission(p_actor, 'PurchaseOrders', 'read');
  SELECT po.*, s.name AS supplier_name,
         (SELECT COUNT(*) FROM purchase_order_items x WHERE x.purchase_order_id = po.id) AS line_count
    FROM purchase_orders po JOIN suppliers s ON s.id = po.supplier_id ORDER BY po.created_at DESC;
END $$

DROP PROCEDURE IF EXISTS sp_po_get $$
CREATE PROCEDURE sp_po_get(IN p_actor CHAR(36), IN p_id CHAR(36))
BEGIN
  CALL sp_require_permission(p_actor, 'PurchaseOrders', 'read');
  SELECT po.*, s.name AS supplier_name FROM purchase_orders po JOIN suppliers s ON s.id = po.supplier_id WHERE po.id = p_id;
  SELECT poi.*, i.code, i.name, i.uom FROM purchase_order_items poi JOIN items i ON i.id = poi.item_id
   WHERE poi.purchase_order_id = p_id;
END $$

DROP PROCEDURE IF EXISTS sp_grn_list $$
CREATE PROCEDURE sp_grn_list(IN p_actor CHAR(36))
BEGIN
  CALL sp_require_permission(p_actor, 'PurchaseOrders', 'read');
  SELECT pr.*, s.name AS supplier_name, po.po_number
    FROM purchase_receipts pr JOIN suppliers s ON s.id = pr.supplier_id
    JOIN purchase_orders po ON po.id = pr.purchase_order_id ORDER BY pr.created_at DESC;
END $$

DROP PROCEDURE IF EXISTS sp_grn_get $$
CREATE PROCEDURE sp_grn_get(IN p_actor CHAR(36), IN p_id CHAR(36))
BEGIN
  CALL sp_require_permission(p_actor, 'PurchaseOrders', 'read');
  SELECT pr.*, s.name AS supplier_name, po.po_number
    FROM purchase_receipts pr JOIN suppliers s ON s.id = pr.supplier_id
    JOIN purchase_orders po ON po.id = pr.purchase_order_id WHERE pr.id = p_id;
  SELECT pri.*, i.code, i.name, i.uom, b.batch_number
    FROM purchase_receipt_items pri JOIN items i ON i.id = pri.item_id
    LEFT JOIN batches b ON b.id = pri.batch_id WHERE pri.purchase_receipt_id = p_id;
END $$

-- ----------------------------- production ------------------------------------
DROP PROCEDURE IF EXISTS sp_work_order_list $$
CREATE PROCEDURE sp_work_order_list(IN p_actor CHAR(36))
BEGIN
  CALL sp_require_permission(p_actor, 'WorkOrders', 'read');
  SELECT wo.*, fg.code AS fg_code, fg.name AS fg_name, so.order_number,
         ROUND(100 * wo.produced_qty / wo.planned_qty, 1) AS pct_complete
    FROM work_orders wo JOIN items fg ON fg.id = wo.fg_item_id
    LEFT JOIN sales_orders so ON so.id = wo.sales_order_id ORDER BY wo.created_at DESC;
END $$

DROP PROCEDURE IF EXISTS sp_work_order_get $$
CREATE PROCEDURE sp_work_order_get(IN p_actor CHAR(36), IN p_id CHAR(36))
BEGIN
  CALL sp_require_permission(p_actor, 'WorkOrders', 'read');
  SELECT wo.*, fg.code AS fg_code, fg.name AS fg_name, so.order_number
    FROM work_orders wo JOIN items fg ON fg.id = wo.fg_item_id
    LEFT JOIN sales_orders so ON so.id = wo.sales_order_id WHERE wo.id = p_id;
  SELECT * FROM v_job_card_live WHERE work_order_id = p_id ORDER BY started_at;
  SELECT bi.rm_item_id, r.code, r.name, r.uom,
         ROUND(bi.qty_per_unit * (wo.planned_qty / b.output_qty) / (1 - b.scrap_factor), 2) AS planned_requirement
    FROM work_orders wo
    JOIN v_default_bom b ON b.fg_item_id = wo.fg_item_id
    JOIN bom_items bi ON bi.bom_id = b.id JOIN items r ON r.id = bi.rm_item_id
   WHERE wo.id = p_id;
END $$

-- Shop-floor screen: active job cards with live counters
DROP PROCEDURE IF EXISTS sp_shop_floor $$
CREATE PROCEDURE sp_shop_floor(IN p_actor CHAR(36))
BEGIN
  CALL sp_require_permission(p_actor, 'Production', 'read');
  SELECT * FROM v_job_card_live WHERE status IN ('ACTIVE','QUEUED') ORDER BY workstation_code;
  SELECT * FROM workstations ORDER BY code;
END $$

DROP PROCEDURE IF EXISTS sp_downtime_list $$
CREATE PROCEDURE sp_downtime_list(IN p_actor CHAR(36), IN p_days INT)
BEGIN
  CALL sp_require_permission(p_actor, 'Production', 'read');
  SELECT dl.*, ws.code AS workstation_code, u.name AS logged_by
    FROM downtime_logs dl JOIN workstations ws ON ws.id = dl.workstation_id JOIN users u ON u.id = dl.logged_by_id
   WHERE dl.start_time >= NOW(3) - INTERVAL IFNULL(p_days, 30) DAY ORDER BY dl.start_time DESC;
END $$

DROP PROCEDURE IF EXISTS sp_shift_handover_list $$
CREATE PROCEDURE sp_shift_handover_list(IN p_actor CHAR(36), IN p_status VARCHAR(20))
BEGIN
  CALL sp_require_permission(p_actor, 'Production', 'read');
  SELECT sh.*, ws.code AS workstation_code, o.name AS outgoing_name, i.name AS incoming_name
    FROM shift_handovers sh JOIN workstations ws ON ws.id = sh.workstation_id
    JOIN users o ON o.id = sh.outgoing_operator_id LEFT JOIN users i ON i.id = sh.incoming_operator_id
   WHERE p_status IS NULL OR sh.status = p_status ORDER BY sh.shift_date DESC;
END $$

-- Energy / OEE screens are not in the original schema (no meter-reading table exists),
-- so they cannot be served from the database yet.

-- ----------------------------- quality & dispatch ----------------------------
DROP PROCEDURE IF EXISTS sp_qc_list $$
CREATE PROCEDURE sp_qc_list(IN p_actor CHAR(36), IN p_status VARCHAR(20))
BEGIN
  CALL sp_require_permission(p_actor, 'QC', 'read');
  SELECT * FROM quality_inspections WHERE p_status IS NULL OR status = p_status ORDER BY inspected_at DESC LIMIT 200;
END $$

DROP PROCEDURE IF EXISTS sp_delivery_note_list $$
CREATE PROCEDURE sp_delivery_note_list(IN p_actor CHAR(36))
BEGIN
  CALL sp_require_permission(p_actor, 'Dispatch', 'read');
  SELECT p.*, so.order_number, dn.customer_name, dn.transporter_name, dn.vehicle_number, dn.lr_number, dn.dispatched_at
    FROM v_carton_scan_progress p
    JOIN delivery_notes dn ON dn.id = p.delivery_note_id
    JOIN sales_orders so ON so.id = p.sales_order_id ORDER BY dn.created_at DESC;
END $$

DROP PROCEDURE IF EXISTS sp_delivery_note_get $$
CREATE PROCEDURE sp_delivery_note_get(IN p_actor CHAR(36), IN p_id CHAR(36))
BEGIN
  CALL sp_require_permission(p_actor, 'Dispatch', 'read');
  SELECT dn.*, so.order_number FROM delivery_notes dn JOIN sales_orders so ON so.id = dn.sales_order_id WHERE dn.id = p_id;
  SELECT cl.*, bt.batch_number, it.name AS item_name, it.uom
    FROM carton_labels cl JOIN batches bt ON bt.id = cl.batch_id JOIN items it ON it.id = bt.item_id
   WHERE cl.delivery_note_id = p_id ORDER BY cl.carton_code;
END $$

-- ----------------------------- accounts --------------------------------------
DROP PROCEDURE IF EXISTS sp_sales_invoice_list $$
CREATE PROCEDURE sp_sales_invoice_list(IN p_actor CHAR(36), IN p_status VARCHAR(20))
BEGIN
  CALL sp_require_permission(p_actor, 'Accounts', 'read');
  SELECT si.*, c.name AS customer_name, so.order_number
    FROM sales_invoices si JOIN customers c ON c.id = si.customer_id
    LEFT JOIN sales_orders so ON so.id = si.sales_order_id
   WHERE p_status IS NULL OR si.status = p_status ORDER BY si.created_at DESC;
END $$

DROP PROCEDURE IF EXISTS sp_sales_invoice_get $$
CREATE PROCEDURE sp_sales_invoice_get(IN p_actor CHAR(36), IN p_id CHAR(36))
BEGIN
  CALL sp_require_permission(p_actor, 'Accounts', 'read');
  SELECT si.*, c.name AS customer_name, c.gstin AS customer_gstin, so.order_number, dn.dn_number
    FROM sales_invoices si JOIN customers c ON c.id = si.customer_id
    LEFT JOIN sales_orders so ON so.id = si.sales_order_id
    LEFT JOIN delivery_notes dn ON dn.id = si.delivery_note_id WHERE si.id = p_id;
  SELECT sii.*, i.code, i.name, i.hsn_code, i.uom FROM sales_invoice_items sii JOIN items i ON i.id = sii.item_id
   WHERE sii.sales_invoice_id = p_id;
  SELECT * FROM payment_entries WHERE sales_invoice_id = p_id ORDER BY payment_date;
END $$

DROP PROCEDURE IF EXISTS sp_purchase_invoice_list $$
CREATE PROCEDURE sp_purchase_invoice_list(IN p_actor CHAR(36), IN p_status VARCHAR(20))
BEGIN
  CALL sp_require_permission(p_actor, 'Accounts', 'read');
  SELECT pi.*, s.name AS supplier_name FROM purchase_invoices pi JOIN suppliers s ON s.id = pi.supplier_id
   WHERE p_status IS NULL OR pi.status = p_status ORDER BY pi.created_at DESC;
END $$

DROP PROCEDURE IF EXISTS sp_purchase_invoice_get $$
CREATE PROCEDURE sp_purchase_invoice_get(IN p_actor CHAR(36), IN p_id CHAR(36))
BEGIN
  CALL sp_require_permission(p_actor, 'Accounts', 'read');
  SELECT pi.*, s.name AS supplier_name FROM purchase_invoices pi JOIN suppliers s ON s.id = pi.supplier_id WHERE pi.id = p_id;
  SELECT pii.*, i.code, i.name, i.hsn_code, i.uom FROM purchase_invoice_items pii JOIN items i ON i.id = pii.item_id
   WHERE pii.purchase_invoice_id = p_id;
  SELECT * FROM payment_entries WHERE purchase_invoice_id = p_id ORDER BY payment_date;
END $$

DROP PROCEDURE IF EXISTS sp_payment_list $$
CREATE PROCEDURE sp_payment_list(IN p_actor CHAR(36))
BEGIN
  CALL sp_require_permission(p_actor, 'Accounts', 'read');
  SELECT pe.*, COALESCE(c.name, s.name) AS party_name, si.invoice_number AS sales_invoice_number,
         pi.invoice_number AS purchase_invoice_number
    FROM payment_entries pe
    LEFT JOIN customers c ON c.id = pe.customer_id
    LEFT JOIN suppliers s ON s.id = pe.supplier_id
    LEFT JOIN sales_invoices si ON si.id = pe.sales_invoice_id
    LEFT JOIN purchase_invoices pi ON pi.id = pe.purchase_invoice_id
   ORDER BY pe.created_at DESC;
END $$

DROP PROCEDURE IF EXISTS sp_receivables $$
CREATE PROCEDURE sp_receivables(IN p_actor CHAR(36))
BEGIN
  CALL sp_require_permission(p_actor, 'Accounts', 'read');
  SELECT * FROM v_receivables ORDER BY days_overdue DESC;
  SELECT aging_bucket, COUNT(*) AS invoices, SUM(outstanding) AS outstanding FROM v_receivables GROUP BY aging_bucket;
END $$

DROP PROCEDURE IF EXISTS sp_payables $$
CREATE PROCEDURE sp_payables(IN p_actor CHAR(36))
BEGIN
  CALL sp_require_permission(p_actor, 'Accounts', 'read');
  SELECT * FROM v_payables ORDER BY days_overdue DESC;
END $$

-- Rows for the Book Keeper CSV (p_voucher_type: SALES_INVOICE or MATERIAL_CONSUMPTION)
DROP PROCEDURE IF EXISTS sp_export_bookkeeper $$
CREATE PROCEDURE sp_export_bookkeeper(IN p_actor CHAR(36), IN p_order_id CHAR(36), IN p_voucher_type VARCHAR(30))
BEGIN
  CALL sp_require_permission(p_actor, 'Accounts', 'read');
  IF p_voucher_type = 'MATERIAL_CONSUMPTION' THEN
    SELECT * FROM v_export_bookkeeper_consumption WHERE sales_order_id = p_order_id ORDER BY item_code;
  ELSE
    SELECT * FROM v_export_bookkeeper_sales WHERE sales_order_id = p_order_id ORDER BY item_code;
  END IF;
END $$

-- Rows for the TallyPrime XML voucher (by order or by delivery note)
DROP PROCEDURE IF EXISTS sp_export_tally $$
CREATE PROCEDURE sp_export_tally(IN p_actor CHAR(36), IN p_order_id CHAR(36), IN p_delivery_note_id CHAR(36))
BEGIN
  CALL sp_require_permission(p_actor, 'Accounts', 'read');
  SELECT * FROM v_export_tally_sales
   WHERE (p_order_id IS NOT NULL AND sales_order_id = p_order_id)
      OR (p_order_id IS NULL AND delivery_note_id = p_delivery_note_id);
END $$

-- Global search box: customers, orders, items, suppliers, POs, batches
DROP PROCEDURE IF EXISTS sp_search $$
CREATE PROCEDURE sp_search(IN p_actor CHAR(36), IN p_q VARCHAR(100))
BEGIN
  DECLARE v_like VARCHAR(110) DEFAULT CONCAT('%', REPLACE(REPLACE(IFNULL(p_q,''), '%', ''), '_', ''), '%');
  IF p_actor IS NULL OR NOT EXISTS (SELECT 1 FROM users WHERE id = p_actor AND is_active = 1) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Forbidden: please sign in';
  END IF;
  -- count only what is searched for: "%%" would otherwise become an empty pattern that matches everything
  IF CHAR_LENGTH(REPLACE(REPLACE(IFNULL(p_q,''), '%', ''), '_', '')) < 2 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Type at least 2 characters to search';
  END IF;

  SELECT * FROM (
    SELECT 'ORDER' AS kind, id, order_number AS title, customer_name AS subtitle, CONCAT('/orders/', id) AS url
      FROM sales_orders WHERE fn_can(p_actor,'SalesOrders','read') = 1 AND (order_number LIKE v_like OR customer_name LIKE v_like)
    UNION ALL
    SELECT 'CUSTOMER', id, name, IFNULL(NULLIF(gstin,''), IFNULL(email, IFNULL(phone,''))), CONCAT('/customers/', id)
      FROM customers WHERE fn_can(p_actor,'Customers','read') = 1 AND (name LIKE v_like OR gstin LIKE v_like OR email LIKE v_like OR phone LIKE v_like)
    UNION ALL
    SELECT 'ITEM', id, code, name, CONCAT('/items/', id)
      FROM items WHERE fn_can(p_actor,'Items','read') = 1 AND (code LIKE v_like OR name LIKE v_like)
    UNION ALL
    SELECT 'SUPPLIER', id, name, IFNULL(NULLIF(gstin,''), IFNULL(email, IFNULL(phone,''))), CONCAT('/suppliers/', id)
      FROM suppliers WHERE fn_can(p_actor,'Suppliers','read') = 1 AND (name LIKE v_like OR gstin LIKE v_like OR email LIKE v_like OR phone LIKE v_like)
    UNION ALL
    SELECT 'USER', id, name, email, CONCAT('/users/', id, '/edit')
      FROM users WHERE fn_can(p_actor,'users','read') = 1 AND (name LIKE v_like OR email LIKE v_like OR employee_code LIKE v_like)
    UNION ALL
    SELECT 'PO', id, po_number, status, CONCAT('/purchase-orders/', id)
      FROM purchase_orders WHERE fn_can(p_actor,'PurchaseOrders','read') = 1 AND po_number LIKE v_like
    UNION ALL
    SELECT 'INVOICE', si.id, si.invoice_number, c.name, CONCAT('/invoices/', si.id)
      FROM sales_invoices si JOIN customers c ON c.id = si.customer_id
     WHERE fn_can(p_actor,'Accounts','read') = 1 AND (si.invoice_number LIKE v_like OR c.name LIKE v_like)
    UNION ALL
    SELECT 'WORK ORDER', id, work_order_number, status, CONCAT('/work-orders/', id)
      FROM work_orders WHERE fn_can(p_actor,'WorkOrders','read') = 1 AND work_order_number LIKE v_like
    UNION ALL
    SELECT 'BATCH', id, batch_number, source, CONCAT('/stock/batches?q=', batch_number)
      FROM batches WHERE fn_can(p_actor,'Stock','read') = 1 AND batch_number LIKE v_like
  ) r LIMIT 60;
END $$

-- The header's search-as-you-type: the same matching as sp_search, but at most 4 rows per kind
-- (so a common word cannot fill the list with orders and hide customers, items and staff).
DROP PROCEDURE IF EXISTS sp_search_suggest $$
CREATE PROCEDURE sp_search_suggest(IN p_actor CHAR(36), IN p_q VARCHAR(100))
BEGIN
  DECLARE v_like VARCHAR(110) DEFAULT CONCAT('%', REPLACE(REPLACE(IFNULL(p_q,''), '%', ''), '_', ''), '%');
  IF p_actor IS NULL OR NOT EXISTS (SELECT 1 FROM users WHERE id = p_actor AND is_active = 1) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Forbidden: please sign in';
  END IF;
  IF CHAR_LENGTH(REPLACE(REPLACE(IFNULL(p_q,''), '%', ''), '_', '')) < 2 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Type at least 2 characters to search';
  END IF;

  (SELECT 'ORDER' AS kind, id, order_number AS title, customer_name AS subtitle, CONCAT('/orders/', id) AS url
     FROM sales_orders WHERE fn_can(p_actor,'SalesOrders','read') = 1 AND (order_number LIKE v_like OR customer_name LIKE v_like)
    ORDER BY created_at DESC LIMIT 4)
  UNION ALL
  (SELECT 'INVOICE', si.id, si.invoice_number, c.name, CONCAT('/invoices/', si.id)
     FROM sales_invoices si JOIN customers c ON c.id = si.customer_id
    WHERE fn_can(p_actor,'Accounts','read') = 1 AND (si.invoice_number LIKE v_like OR c.name LIKE v_like)
    ORDER BY si.created_at DESC LIMIT 4)
  UNION ALL
  (SELECT 'CUSTOMER', id, name, IFNULL(NULLIF(gstin,''), IFNULL(email, IFNULL(phone,''))), CONCAT('/customers/', id)
     FROM customers WHERE fn_can(p_actor,'Customers','read') = 1 AND (name LIKE v_like OR gstin LIKE v_like OR email LIKE v_like OR phone LIKE v_like)
    ORDER BY name LIMIT 4)
  UNION ALL
  (SELECT 'SUPPLIER', id, name, IFNULL(NULLIF(gstin,''), IFNULL(email, IFNULL(phone,''))), CONCAT('/suppliers/', id)
     FROM suppliers WHERE fn_can(p_actor,'Suppliers','read') = 1 AND (name LIKE v_like OR gstin LIKE v_like OR email LIKE v_like OR phone LIKE v_like)
    ORDER BY name LIMIT 4)
  UNION ALL
  (SELECT 'ITEM', id, code, name, CONCAT('/items/', id)
     FROM items WHERE fn_can(p_actor,'Items','read') = 1 AND (code LIKE v_like OR name LIKE v_like)
    ORDER BY code LIMIT 4)
  UNION ALL
  (SELECT 'BATCH', id, batch_number, source, CONCAT('/stock/batches?q=', batch_number)
     FROM batches WHERE fn_can(p_actor,'Stock','read') = 1 AND batch_number LIKE v_like
    ORDER BY batch_number LIMIT 4)
  UNION ALL
  (SELECT 'USER', id, name, email, CONCAT('/users/', id, '/edit')
     FROM users WHERE fn_can(p_actor,'users','read') = 1 AND (name LIKE v_like OR email LIKE v_like OR employee_code LIKE v_like)
    ORDER BY name LIMIT 4);
END $$

DELIMITER ;
