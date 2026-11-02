-- =============================================================================
-- HPOS Factory OS  |  02_views.sql  -  read models used by screens and exports
-- =============================================================================
USE hpos;

-- -----------------------------------------------------------------------------
-- Stock
-- Sign rules are identical to the original app:
--   +  RECEIPT, PRODUCTION_OUTPUT, PRODUCTION, RECOVERY, ADJUSTMENT_IN
--   -  CONSUMPTION, SCRAP, DISPATCH, ADJUSTMENT_OUT
--   TRANSFER is ignored.
-- -----------------------------------------------------------------------------
CREATE OR REPLACE VIEW v_stock_balance AS
SELECT
  l.item_id,
  ROUND(SUM(CASE
        WHEN l.movement_type IN ('RECEIPT','PRODUCTION_OUTPUT','PRODUCTION','RECOVERY','ADJUSTMENT_IN') THEN  l.quantity
        WHEN l.movement_type IN ('CONSUMPTION','SCRAP','DISPATCH','ADJUSTMENT_OUT')                      THEN -l.quantity
        ELSE 0 END), 3) AS physical_stock,
  MAX(l.created_at)      AS last_movement
FROM stock_ledger_entries l
GROUP BY l.item_id;

CREATE OR REPLACE VIEW v_stock_reserved AS
SELECT item_id, ROUND(SUM(quantity), 3) AS reserved_stock
FROM stock_reservations
WHERE status = 'RESERVED'
GROUP BY item_id;

-- 3-tier inventory screen: physical / reserved / available
CREATE OR REPLACE VIEW v_inventory_status AS
SELECT
  i.id              AS item_id,
  i.code,
  i.name,
  i.category        AS tier,
  i.uom,
  i.min_stock_level,
  COALESCE(b.physical_stock, 0)                                         AS physical_stock,
  COALESCE(r.reserved_stock, 0)                                         AS reserved_stock,
  GREATEST(0, COALESCE(b.physical_stock, 0) - COALESCE(r.reserved_stock, 0)) AS available_stock,
  (i.min_stock_level > 0 AND COALESCE(b.physical_stock, 0) < i.min_stock_level) AS reorder_alert,
  b.last_movement
FROM items i
LEFT JOIN v_stock_balance  b ON b.item_id = i.id
LEFT JOIN v_stock_reserved r ON r.item_id = i.id;

CREATE OR REPLACE VIEW v_stock_ledger_recent AS
SELECT l.id, l.item_id, i.code AS item_code, i.name AS item_name, i.uom,
       l.batch_id, bt.batch_number, l.warehouse, l.quantity, l.movement_type,
       l.reference_id, l.notes, l.created_at
FROM stock_ledger_entries l
JOIN items i ON i.id = l.item_id
LEFT JOIN batches bt ON bt.id = l.batch_id;

-- -----------------------------------------------------------------------------
-- BOM + strict material gate
--   required = order_qty / bom.output_qty * qty_per_unit / (1 - scrap_factor)
-- "Default" BOM of a finished good = its oldest BOM (same as the app: boms[0]).
-- -----------------------------------------------------------------------------
CREATE OR REPLACE VIEW v_default_bom AS
SELECT b.*
FROM boms b
WHERE b.id = (SELECT b2.id FROM boms b2
              WHERE b2.fg_item_id = b.fg_item_id
              ORDER BY b2.created_at, b2.id LIMIT 1);

CREATE OR REPLACE VIEW v_order_material_requirement AS
SELECT
  soi.sales_order_id,
  bi.rm_item_id,
  SUM(soi.qty / b.output_qty * bi.qty_per_unit / (1 - b.scrap_factor)) AS required_qty
FROM sales_order_items soi
JOIN v_default_bom b ON b.fg_item_id = soi.item_id
JOIN bom_items bi    ON bi.bom_id = b.id
GROUP BY soi.sales_order_id, bi.rm_item_id;

-- One row per order x raw material, with the hard-gate verdict.
-- reserved_by_others excludes the order's own reservations, so re-confirming
-- an order does not block itself.
CREATE OR REPLACE VIEW v_order_material_check AS
SELECT
  q.sales_order_id,
  rm.id    AS rm_item_id,
  rm.code  AS rm_code,
  rm.name  AS rm_name,
  rm.uom   AS rm_uom,
  rm.standard_cost,
  q.required_qty,
  COALESCE(sb.physical_stock, 0) AS physical_stock,
  (SELECT COALESCE(SUM(sr.quantity), 0) FROM stock_reservations sr
    WHERE sr.item_id = q.rm_item_id AND sr.status = 'RESERVED'
      AND sr.sales_order_id <> q.sales_order_id) AS reserved_by_others,
  GREATEST(0, COALESCE(sb.physical_stock, 0) -
     (SELECT COALESCE(SUM(sr.quantity), 0) FROM stock_reservations sr
       WHERE sr.item_id = q.rm_item_id AND sr.status = 'RESERVED'
         AND sr.sales_order_id <> q.sales_order_id)) AS available_stock,
  CASE WHEN GREATEST(0, COALESCE(sb.physical_stock, 0) -
         (SELECT COALESCE(SUM(sr.quantity), 0) FROM stock_reservations sr
           WHERE sr.item_id = q.rm_item_id AND sr.status = 'RESERVED'
             AND sr.sales_order_id <> q.sales_order_id)) >= q.required_qty
       THEN 'AVAILABLE' ELSE 'DEFICIT' END AS status,
  CASE WHEN GREATEST(0, COALESCE(sb.physical_stock, 0) -
         (SELECT COALESCE(SUM(sr.quantity), 0) FROM stock_reservations sr
           WHERE sr.item_id = q.rm_item_id AND sr.status = 'RESERVED'
             AND sr.sales_order_id <> q.sales_order_id)) >= q.required_qty
       THEN 0
       ELSE CEIL((q.required_qty - GREATEST(0, COALESCE(sb.physical_stock, 0) -
         (SELECT COALESCE(SUM(sr.quantity), 0) FROM stock_reservations sr
           WHERE sr.item_id = q.rm_item_id AND sr.status = 'RESERVED'
             AND sr.sales_order_id <> q.sales_order_id))) * 100) / 100 END AS deficit_qty
FROM v_order_material_requirement q
JOIN items rm ON rm.id = q.rm_item_id
LEFT JOIN v_stock_balance sb ON sb.item_id = q.rm_item_id;

-- -----------------------------------------------------------------------------
-- Orders / production
-- -----------------------------------------------------------------------------
CREATE OR REPLACE VIEW v_order_summary AS
SELECT
  so.id, so.order_number, so.customer_id, so.customer_name, so.customer_gstin,
  so.status, so.transaction_date, so.delivery_date, so.total_amount,
  so.approval_method, so.confirmed_at, so.confirmed_by_id,
  (so.delivery_date < NOW(3) AND so.status NOT IN ('DISPATCHED','COMPLETED','CANCELLED')) AS is_overdue,
  (SELECT COUNT(*) FROM sales_order_items x WHERE x.sales_order_id = so.id)  AS line_count,
  (SELECT COALESCE(SUM(w.planned_qty),0)  FROM work_orders w WHERE w.sales_order_id = so.id) AS planned_qty,
  (SELECT COALESCE(SUM(w.produced_qty),0) FROM work_orders w WHERE w.sales_order_id = so.id) AS produced_qty,
  (SELECT COUNT(*) FROM carton_labels c JOIN delivery_notes d ON d.id = c.delivery_note_id
     WHERE d.sales_order_id = so.id) AS cartons_total,
  (SELECT COUNT(*) FROM carton_labels c JOIN delivery_notes d ON d.id = c.delivery_note_id
     WHERE d.sales_order_id = so.id AND c.scanned = 1) AS cartons_scanned
FROM sales_orders so;

CREATE OR REPLACE VIEW v_job_card_live AS
SELECT
  jc.id AS job_card_id, jc.status, jc.good_qty, jc.scrap_qty, jc.started_at, jc.completed_at,
  wo.id AS work_order_id, wo.work_order_number, wo.planned_qty, wo.produced_qty, wo.sales_order_id,
  fg.code AS fg_code, fg.name AS fg_name,
  ws.id AS workstation_id, ws.code AS workstation_code, ws.name AS workstation_name, ws.status AS workstation_status,
  u.name AS operator_name, d.code AS die_code
FROM job_cards jc
JOIN work_orders wo ON wo.id = jc.work_order_id
JOIN items fg       ON fg.id = wo.fg_item_id
JOIN workstations ws ON ws.id = jc.workstation_id
LEFT JOIN users u   ON u.id = jc.assigned_user_id
LEFT JOIN dies d    ON d.id = jc.die_id;

-- Per-workstation downtime in the last 30 days (feeds the OEE / analytics page)
CREATE OR REPLACE VIEW v_downtime_30d AS
SELECT
  ws.id AS workstation_id, ws.code, ws.name,
  COUNT(dl.id)                                   AS stops,
  COALESCE(SUM(dl.duration_mins), 0)             AS downtime_mins,
  SUM(dl.end_time IS NULL)                       AS open_stops
FROM workstations ws
LEFT JOIN downtime_logs dl ON dl.workstation_id = ws.id AND dl.start_time >= (NOW(3) - INTERVAL 30 DAY)
GROUP BY ws.id, ws.code, ws.name;

CREATE OR REPLACE VIEW v_carton_scan_progress AS
SELECT
  d.id AS delivery_note_id, d.dn_number, d.sales_order_id, d.status,
  COUNT(c.id)               AS total_cartons,
  COALESCE(SUM(c.scanned),0) AS scanned_cartons,
  COALESCE(SUM(c.quantity),0) AS total_qty
FROM delivery_notes d
LEFT JOIN carton_labels c ON c.delivery_note_id = d.id
GROUP BY d.id, d.dn_number, d.sales_order_id, d.status;

-- -----------------------------------------------------------------------------
-- Accounts
-- -----------------------------------------------------------------------------
CREATE OR REPLACE VIEW v_receivables AS
SELECT
  si.id, si.invoice_number, si.customer_id, c.name AS customer_name,
  si.invoice_date, si.due_date, si.status, si.total_amount, si.outstanding,
  GREATEST(0, DATEDIFF(CURDATE(), DATE(si.due_date))) AS days_overdue,
  CASE
    WHEN DATE(si.due_date) >= CURDATE()               THEN 'NOT_DUE'
    WHEN DATEDIFF(CURDATE(), DATE(si.due_date)) <= 30 THEN '1-30'
    WHEN DATEDIFF(CURDATE(), DATE(si.due_date)) <= 60 THEN '31-60'
    WHEN DATEDIFF(CURDATE(), DATE(si.due_date)) <= 90 THEN '61-90'
    ELSE '90+' END AS aging_bucket
FROM sales_invoices si
JOIN customers c ON c.id = si.customer_id
WHERE si.status IN ('UNPAID','PARTIAL') AND si.outstanding > 0;

CREATE OR REPLACE VIEW v_payables AS
SELECT
  pi.id, pi.invoice_number, pi.supplier_id, s.name AS supplier_name,
  pi.invoice_date, pi.due_date, pi.status, pi.total_amount, pi.outstanding,
  GREATEST(0, DATEDIFF(CURDATE(), DATE(pi.due_date))) AS days_overdue
FROM purchase_invoices pi
JOIN suppliers s ON s.id = pi.supplier_id
WHERE pi.status IN ('UNPAID','PARTIAL') AND pi.outstanding > 0;

CREATE OR REPLACE VIEW v_customer_ledger AS
SELECT c.id AS customer_id, c.name,
  COALESCE((SELECT SUM(total_amount) FROM sales_invoices WHERE customer_id = c.id AND status <> 'DRAFT' AND status <> 'CANCELLED'),0) AS invoiced,
  COALESCE((SELECT SUM(outstanding)  FROM sales_invoices WHERE customer_id = c.id AND status IN ('UNPAID','PARTIAL')),0)          AS outstanding
FROM customers c;

-- -----------------------------------------------------------------------------
-- Founder dashboard: one row of headline numbers
-- -----------------------------------------------------------------------------
CREATE OR REPLACE VIEW v_kpi_dashboard AS
SELECT
  (SELECT COUNT(*) FROM sales_orders WHERE status NOT IN ('DISPATCHED','COMPLETED','CANCELLED','DRAFT')) AS open_orders,
  (SELECT COUNT(*) FROM sales_orders WHERE status = 'IN_PRODUCTION')                                    AS orders_in_production,
  (SELECT COUNT(*) FROM sales_orders WHERE delivery_date < NOW(3)
      AND status NOT IN ('DISPATCHED','COMPLETED','CANCELLED'))                                         AS overdue_orders,
  (SELECT COALESCE(SUM(total_amount),0) FROM sales_orders
      WHERE status NOT IN ('DRAFT','CANCELLED') AND transaction_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')) AS sales_this_month,
  (SELECT COUNT(*) FROM v_inventory_status WHERE reorder_alert = 1)                                     AS low_stock_items,
  (SELECT COUNT(*) FROM workstations WHERE status = 'RUNNING')                                          AS machines_running,
  (SELECT COUNT(*) FROM workstations WHERE status = 'MAINTENANCE')                                      AS machines_down,
  (SELECT COALESCE(SUM(quantity),0) FROM stock_ledger_entries
      WHERE movement_type = 'PRODUCTION_OUTPUT' AND DATE(created_at) = CURDATE())                       AS meters_today,
  (SELECT COUNT(*) FROM quality_inspections WHERE status = 'PENDING')                                   AS qc_pending,
  (SELECT COALESCE(SUM(outstanding),0) FROM sales_invoices WHERE status IN ('UNPAID','PARTIAL'))        AS receivables,
  (SELECT COALESCE(SUM(outstanding),0) FROM purchase_invoices WHERE status IN ('UNPAID','PARTIAL'))     AS payables,
  (SELECT COUNT(*) FROM notifications WHERE status = 'UNREAD')                                          AS unread_notifications;

-- -----------------------------------------------------------------------------
-- Accounting exports (the PHP layer only has to format these rows as CSV / XML)
-- -----------------------------------------------------------------------------
CREATE OR REPLACE VIEW v_export_bookkeeper_sales AS
SELECT
  so.id AS sales_order_id,
  'SALES_INVOICE'                                                AS voucher_type,
  CONCAT('HP-INV-', REGEXP_REPLACE(so.order_number, '^(SAL-ORD-|SO-)', '')) AS voucher_no,
  DATE(COALESCE(so.transaction_date, so.created_at))             AS voucher_date,
  so.order_number,
  REPLACE(so.customer_name, ',', ' ')                            AS party_name,
  COALESCE(NULLIF(so.customer_gstin, ''), c.gstin, 'Unregistered') AS gstin,
  i.code AS item_code, REPLACE(i.name, ',', ' ') AS item_name, i.hsn_code,
  soi.qty, i.uom, soi.rate, soi.amount
FROM sales_orders so
JOIN sales_order_items soi ON soi.sales_order_id = so.id
JOIN items i ON i.id = soi.item_id
LEFT JOIN customers c ON c.id = so.customer_id;

-- Raw material actually consumed per sales order (from the ledger, not from reservations)
CREATE OR REPLACE VIEW v_export_bookkeeper_consumption AS
SELECT
  so.id AS sales_order_id,
  'MATERIAL_CONSUMPTION' AS voucher_type,
  CONCAT('HP-MC-', REGEXP_REPLACE(so.order_number, '^(SAL-ORD-|SO-)', '')) AS voucher_no,
  DATE(l.created_at) AS voucher_date,
  so.order_number, wo.work_order_number,
  i.code AS item_code, REPLACE(i.name, ',', ' ') AS item_name, i.category, i.uom,
  SUM(l.quantity) AS qty, i.standard_cost,
  ROUND(SUM(l.quantity) * i.standard_cost, 2) AS amount
FROM stock_ledger_entries l
JOIN work_orders wo ON wo.id = l.reference_id
JOIN sales_orders so ON so.id = wo.sales_order_id
JOIN items i ON i.id = l.item_id
WHERE l.movement_type = 'CONSUMPTION'
GROUP BY so.id, so.order_number, wo.work_order_number, DATE(l.created_at),
         i.code, i.name, i.category, i.uom, i.standard_cost;

CREATE OR REPLACE VIEW v_export_tally_sales AS
SELECT
  so.id AS sales_order_id, dn.id AS delivery_note_id,
  CONCAT('HP-INV-', REGEXP_REPLACE(so.order_number, '^(SAL-ORD-|SO-)', '')) AS voucher_no,
  so.order_number, so.customer_name AS party_name, so.total_amount,
  i.name AS stock_item_name, i.uom, soi.qty, soi.rate, soi.amount
FROM sales_orders so
JOIN sales_order_items soi ON soi.sales_order_id = so.id
JOIN items i ON i.id = soi.item_id
LEFT JOIN delivery_notes dn ON dn.sales_order_id = so.id;
