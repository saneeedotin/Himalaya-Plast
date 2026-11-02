-- =============================================================================
-- HPOS Factory OS  |  16_buying_advice.sql  -  raw-material buying advice. From what each material really uses per
-- day (the last 30 days of consumption), what is in stock, what is already on order, how long the supplier takes and
-- how many days of cover you want, HPOS says what to order and how much. A person ticks the lines and confirms; only
-- then is a draft purchase order created. Needs 01-15 (items.lead_time_days, reorder_qty, preferred_supplier_id:
-- see upgrades/2026-11-buying-advice.sql for databases loaded before it).
-- Reading needs PurchaseOrders:read; creating a draft needs PurchaseOrders:create; item settings need Items:update.
-- =============================================================================
USE hpos;

INSERT IGNORE INTO system_settings (setting_key, setting_value, description) VALUES
  ('cover_days', '14', 'Days of raw material to keep on hand beyond the supplier lead time (buying advice)');

-- Ordered on purchase orders that have gone out but not been fully received
CREATE OR REPLACE VIEW v_rm_incoming AS
SELECT x.item_id, ROUND(SUM(GREATEST(x.ordered - x.received, 0)), 3) AS incoming_qty, COUNT(*) AS open_lines
  FROM (SELECT poi.purchase_order_id, poi.item_id, SUM(poi.qty) AS ordered,
               COALESCE((SELECT SUM(pri.qty) FROM purchase_receipts pr JOIN purchase_receipt_items pri ON pri.purchase_receipt_id = pr.id
                          WHERE pr.purchase_order_id = poi.purchase_order_id AND pr.status = 'SUBMITTED' AND pri.item_id = poi.item_id), 0) AS received
          FROM purchase_order_items poi JOIN purchase_orders po ON po.id = poi.purchase_order_id
         WHERE po.status IN ('SUBMITTED', 'PARTIAL')
         GROUP BY poi.purchase_order_id, poi.item_id) x
 GROUP BY x.item_id;

-- What each material used per day, over the days since it was first used in the last 30 (so a new material is not under-counted)
CREATE OR REPLACE VIEW v_rm_usage AS
SELECT item_id, ROUND(SUM(quantity), 3) AS used_30d,
       ROUND(SUM(quantity) / LEAST(30, GREATEST(1, DATEDIFF(CURDATE(), MIN(DATE(created_at))) + 1)), 3) AS daily_use
  FROM stock_ledger_entries
 WHERE movement_type = 'CONSUMPTION' AND created_at >= NOW() - INTERVAL 30 DAY
 GROUP BY item_id;

-- ORDER_NOW: stock plus what is coming is below the minimum, or runs out inside the lead time
-- ORDER_SOON: runs out within a week after the lead time; OK otherwise
-- suggested_qty: enough to reach (lead time + cover days) of use, at least the minimum stock, at least the item's reorder lot
CREATE OR REPLACE VIEW v_rm_advice AS
SELECT t.*,
       CASE WHEN t.status = 'OK' THEN 0
            ELSE CEIL(GREATEST(t.target_qty - t.position_qty, IFNULL(t.reorder_qty, 0), 0)) END AS suggested_qty
  FROM (
    SELECT i.id AS item_id, i.code, i.name, i.uom, i.category, i.min_stock_level, i.lead_time_days, i.reorder_qty, i.preferred_supplier_id,
           s.name AS supplier_name,
           COALESCE(b.physical_stock, 0) AS physical_stock,
           COALESCE(inc.incoming_qty, 0) AS incoming_qty,
           COALESCE(u.daily_use, 0) AS daily_use,
           COALESCE(b.physical_stock, 0) + COALESCE(inc.incoming_qty, 0) AS position_qty,
           IF(COALESCE(u.daily_use, 0) > 0, ROUND(COALESCE(b.physical_stock, 0) / u.daily_use, 1), NULL) AS days_left,
           GREATEST(COALESCE(u.daily_use, 0) * (IFNULL(i.lead_time_days, 7) + fn_setting_num('cover_days', 14)), i.min_stock_level) AS target_qty,
           COALESCE(NULLIF(i.standard_cost, 0), (SELECT poi.rate FROM purchase_order_items poi JOIN purchase_orders po ON po.id = poi.purchase_order_id
                                                  WHERE poi.item_id = i.id ORDER BY po.created_at DESC LIMIT 1), 0) AS est_rate,
           CASE
             WHEN i.min_stock_level > 0 AND COALESCE(b.physical_stock, 0) + COALESCE(inc.incoming_qty, 0) < i.min_stock_level THEN 'ORDER_NOW'
             WHEN COALESCE(u.daily_use, 0) > 0 AND (COALESCE(b.physical_stock, 0) + COALESCE(inc.incoming_qty, 0)) / u.daily_use <= IFNULL(i.lead_time_days, 7) THEN 'ORDER_NOW'
             WHEN COALESCE(u.daily_use, 0) > 0 AND (COALESCE(b.physical_stock, 0) + COALESCE(inc.incoming_qty, 0)) / u.daily_use <= IFNULL(i.lead_time_days, 7) + 7 THEN 'ORDER_SOON'
             ELSE 'OK'
           END AS status
      FROM items i
      LEFT JOIN v_stock_balance b ON b.item_id = i.id
      LEFT JOIN v_rm_incoming inc ON inc.item_id = i.id
      LEFT JOIN v_rm_usage u ON u.item_id = i.id
      LEFT JOIN suppliers s ON s.id = i.preferred_supplier_id
     WHERE i.category IN ('RAW_MATERIAL', 'PACKAGING')
  ) t;

DELIMITER $$

DROP PROCEDURE IF EXISTS sp_buying_advice $$
CREATE PROCEDURE sp_buying_advice(IN p_actor CHAR(36))
BEGIN
  CALL sp_require_permission(p_actor, 'PurchaseOrders', 'read');
  SELECT a.*, (SELECT po.po_number FROM purchase_order_items poi JOIN purchase_orders po ON po.id = poi.purchase_order_id
                WHERE poi.item_id = a.item_id AND po.status = 'DRAFT' ORDER BY po.created_at DESC LIMIT 1) AS draft_po
    FROM v_rm_advice a
   ORDER BY FIELD(a.status, 'ORDER_NOW', 'ORDER_SOON', 'OK'), a.supplier_name IS NULL, a.supplier_name, a.name;
  SELECT id, name FROM suppliers WHERE status = 'ACTIVE' ORDER BY name;
END $$

-- Lead time, reorder lot and preferred supplier of a material; all optional
DROP PROCEDURE IF EXISTS sp_item_buying_save $$
CREATE PROCEDURE sp_item_buying_save(
  IN p_actor CHAR(36), IN p_item_id CHAR(36), IN p_lead_time_days INT, IN p_reorder_qty DECIMAL(16,3), IN p_supplier_id CHAR(36))
BEGIN
  CALL sp_require_permission(p_actor, 'Items', 'update');
  IF NOT EXISTS (SELECT 1 FROM items WHERE id = p_item_id) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Item not found'; END IF;
  IF p_lead_time_days IS NOT NULL AND (p_lead_time_days < 0 OR p_lead_time_days > 365) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The lead time is 0 to 365 days'; END IF;
  IF p_reorder_qty IS NOT NULL AND p_reorder_qty < 0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The reorder quantity cannot be negative'; END IF;
  IF NULLIF(p_supplier_id, '') IS NOT NULL AND NOT EXISTS (SELECT 1 FROM suppliers WHERE id = p_supplier_id) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Supplier not found'; END IF;
  UPDATE items SET lead_time_days = p_lead_time_days, reorder_qty = p_reorder_qty, preferred_supplier_id = NULLIF(p_supplier_id, '') WHERE id = p_item_id;
  CALL sp_audit(p_actor, 'ITEM_BUYING_SET', NULL, JSON_OBJECT('itemId', p_item_id));
END $$

-- Creates ONE draft purchase order for a supplier from the lines a person ticked: [{"itemId":"...","qty":500}, ...]
-- The rate is the item's standard cost, else the last price paid. Refused when a line is already on a draft order.
DROP PROCEDURE IF EXISTS sp_buying_create_po $$
CREATE PROCEDURE sp_buying_create_po(IN p_actor CHAR(36), IN p_supplier_id CHAR(36), IN p_lines JSON, OUT p_po_id CHAR(36))
BEGIN
  DECLARE v_n INT DEFAULT IFNULL(JSON_LENGTH(p_lines), 0); DECLARE v_ok INT DEFAULT 0; DECLARE v_items JSON; DECLARE v_dup VARCHAR(60); DECLARE v_dupname VARCHAR(255);
  CALL sp_require_permission(p_actor, 'PurchaseOrders', 'create');
  IF v_n = 0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Tick at least one material'; END IF;
  IF NULLIF(p_supplier_id, '') IS NULL OR NOT EXISTS (SELECT 1 FROM suppliers WHERE id = p_supplier_id AND status = 'ACTIVE') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Choose an active supplier';
  END IF;
  SELECT COUNT(*) INTO v_ok
    FROM JSON_TABLE(p_lines, '$[*]' COLUMNS (item_id CHAR(36) PATH '$.itemId', qty DECIMAL(16,3) PATH '$.qty' DEFAULT '0' ON EMPTY DEFAULT '0' ON ERROR)) jt
    JOIN items i ON i.id = (jt.item_id COLLATE utf8mb4_unicode_ci) AND i.category IN ('RAW_MATERIAL', 'PACKAGING')
   WHERE jt.qty > 0;
  IF v_ok <> v_n THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Every line needs a raw material or packaging item and a quantity above zero'; END IF;
  SELECT po.po_number, i.name INTO v_dup, v_dupname
    FROM JSON_TABLE(p_lines, '$[*]' COLUMNS (item_id CHAR(36) PATH '$.itemId')) jt
    JOIN purchase_order_items poi ON poi.item_id = (jt.item_id COLLATE utf8mb4_unicode_ci)
    JOIN purchase_orders po ON po.id = poi.purchase_order_id AND po.status = 'DRAFT'
    JOIN items i ON i.id = poi.item_id
   LIMIT 1;
  IF v_dup IS NOT NULL THEN
    SET v_dupname = LEFT(v_dupname, 60);
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A line is already on a draft purchase order. Open that order instead';
  END IF;
  SELECT JSON_ARRAYAGG(JSON_OBJECT('itemId', x.item_id, 'qty', x.qty, 'rate', x.rate)) INTO v_items
    FROM (SELECT i.id AS item_id, jt.qty AS qty,
                 COALESCE(NULLIF(i.standard_cost, 0), (SELECT poi.rate FROM purchase_order_items poi JOIN purchase_orders po ON po.id = poi.purchase_order_id
                                                        WHERE poi.item_id = i.id ORDER BY po.created_at DESC LIMIT 1), 0) AS rate
            FROM JSON_TABLE(p_lines, '$[*]' COLUMNS (item_id CHAR(36) PATH '$.itemId', qty DECIMAL(16,3) PATH '$.qty')) jt
            JOIN items i ON i.id = (jt.item_id COLLATE utf8mb4_unicode_ci)) x;
  CALL sp_po_create(p_actor, p_supplier_id, 'Created from buying advice', v_items, p_po_id);
END $$

-- Incoming quantity per material, for the order page's shortage lines (anyone who can read orders)
DROP PROCEDURE IF EXISTS sp_incoming_by_item $$
CREATE PROCEDURE sp_incoming_by_item(IN p_actor CHAR(36))
BEGIN
  CALL sp_require_permission(p_actor, 'SalesOrders', 'read');
  SELECT inc.item_id, inc.incoming_qty,
         (SELECT po.po_number FROM purchase_order_items poi JOIN purchase_orders po ON po.id = poi.purchase_order_id
           WHERE poi.item_id = inc.item_id AND po.status IN ('SUBMITTED', 'PARTIAL') ORDER BY po.created_at LIMIT 1) AS po_number
    FROM v_rm_incoming inc WHERE inc.incoming_qty > 0;
END $$

DROP PROCEDURE IF EXISTS sp_alerts_buying $$
CREATE PROCEDURE sp_alerts_buying()
BEGIN
  DECLARE v_n INT DEFAULT 0;
  SELECT COUNT(*) INTO v_n FROM v_rm_advice WHERE status = 'ORDER_NOW';
  UPDATE notifications SET status = 'RESOLVED'
   WHERE source_type = 'MATERIAL' AND source_id = 'advice' AND status <> 'RESOLVED' AND title <> CONCAT(v_n, ' materials to reorder');
  IF v_n > 0 THEN
    CALL sp_notify_upsert('MATERIAL', 'advice', 'ACTION_REQUIRED', CONCAT(v_n, ' materials to reorder'),
      'Stock plus what is on order runs out inside the supplier lead time, or is below the minimum. Open Buying advice.', '/buying-advice', 'Stock,PurchaseOrders');
  END IF;
END $$

DELIMITER ;
