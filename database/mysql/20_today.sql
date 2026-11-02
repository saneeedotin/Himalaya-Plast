-- =============================================================================
-- HPOS Factory OS  |  20_today.sql  -  "What to do today": one short list for the person signed in, drawn from the smart
-- parts (repeat orders and leads, buying advice, deadlines, material at the machines, QC readings). Each line says what,
-- names the first few, and links to the screen where the person acts. It only suggests; nothing is created or sent.
-- A person sees only the lines for the screens they may open. Needs 01-19. Safe to run again.
-- =============================================================================
USE hpos;

DELIMITER $$

-- add one line to tmp_today when there is something to do: "3 orders will miss their date", then the first three names
DROP PROCEDURE IF EXISTS sp_today_add $$
CREATE PROCEDURE sp_today_add(IN p_sort INT, IN p_kind VARCHAR(20), IN p_tone VARCHAR(8), IN p_n INT, IN p_one VARCHAR(100), IN p_many VARCHAR(100),
                              IN p_names VARCHAR(400), IN p_url VARCHAR(190))
BEGIN
  IF p_n > 0 THEN
    INSERT INTO tmp_today (sort_no, kind, tone, headline, detail, url, qty)
    VALUES (p_sort, p_kind, p_tone, CONCAT(p_n, ' ', IF(p_n = 1, p_one, p_many)),
            CASE WHEN p_names IS NULL THEN NULL WHEN p_n > 3 THEN CONCAT(p_names, ' and ', p_n - 3, ' more') ELSE p_names END, p_url, p_n);
  END IF;
END $$

DROP PROCEDURE IF EXISTS sp_today_suggestions $$
CREATE PROCEDURE sp_today_suggestions(IN p_actor CHAR(36))
BEGIN
  DECLARE v_n INT DEFAULT 0;
  DECLARE v_l VARCHAR(400);
  DECLARE v_limit INT DEFAULT CAST(fn_setting_num('qc_reading_minutes', 30) * 1.5 AS SIGNED);
  DROP TEMPORARY TABLE IF EXISTS tmp_today;
  CREATE TEMPORARY TABLE tmp_today (sort_no INT NOT NULL, kind VARCHAR(20) NOT NULL, tone VARCHAR(8) NOT NULL, headline VARCHAR(200) NOT NULL,
    detail VARCHAR(500) NULL, url VARCHAR(190) NOT NULL, qty INT NOT NULL) ENGINE=MEMORY;

  -- orders that will miss their date, and orders with no room to spare
  IF fn_can(p_actor, 'SalesOrders', 'read') = 1 THEN
    SELECT COUNT(*) INTO v_n FROM v_order_projection WHERE health = 'LATE' AND status IN ('CONFIRMED', 'APPROVED', 'IN_PRODUCTION');
    SELECT GROUP_CONCAT(t.label ORDER BY t.o SEPARATOR ', ') INTO v_l FROM (
      SELECT CONCAT(order_number, ' ', customer_name, ' (', -slack_days, IF(slack_days = -1, ' day', ' days'), ' late)') AS label, slack_days AS o
        FROM v_order_projection WHERE health = 'LATE' AND status IN ('CONFIRMED', 'APPROVED', 'IN_PRODUCTION') ORDER BY slack_days LIMIT 3) t;
    CALL sp_today_add(10, 'ORDERS_LATE', 'bad', v_n, 'order will miss its delivery date', 'orders will miss their delivery date', v_l, '/deadlines');

    SELECT COUNT(*) INTO v_n FROM v_order_projection WHERE health = 'AT_RISK' AND status IN ('CONFIRMED', 'APPROVED', 'IN_PRODUCTION');
    SELECT GROUP_CONCAT(t.label ORDER BY t.o SEPARATOR ', ') INTO v_l FROM (
      SELECT CONCAT(order_number, ' ', customer_name) AS label, delivery_date AS o
        FROM v_order_projection WHERE health = 'AT_RISK' AND status IN ('CONFIRMED', 'APPROVED', 'IN_PRODUCTION') ORDER BY delivery_date LIMIT 3) t;
    CALL sp_today_add(20, 'ORDERS_RISK', 'warn', v_n, 'order has no room to spare', 'orders have no room to spare', v_l, '/deadlines');

    SELECT COUNT(*) INTO v_n FROM sales_orders WHERE status = 'PROFORMA_SENT' AND proforma_sent_at < NOW() - INTERVAL 3 DAY;
    SELECT GROUP_CONCAT(t.label ORDER BY t.o SEPARATOR ', ') INTO v_l FROM (
      SELECT CONCAT(order_number, ' ', customer_name) AS label, proforma_sent_at AS o
        FROM sales_orders WHERE status = 'PROFORMA_SENT' AND proforma_sent_at < NOW() - INTERVAL 3 DAY ORDER BY proforma_sent_at LIMIT 3) t;
    CALL sp_today_add(80, 'PROFORMA', 'info', v_n, 'proforma is waiting for the client (over 3 days)', 'proformas are waiting for the client (over 3 days)', v_l, '/orders?status=PROFORMA_SENT');
  END IF;

  -- raw material to buy
  IF fn_can(p_actor, 'PurchaseOrders', 'read') = 1 THEN
    SELECT COUNT(*) INTO v_n FROM v_rm_advice a WHERE a.status = 'ORDER_NOW'
       AND NOT EXISTS (SELECT 1 FROM purchase_order_items poi JOIN purchase_orders po ON po.id = poi.purchase_order_id WHERE poi.item_id = a.item_id AND po.status = 'DRAFT');
    SELECT GROUP_CONCAT(t.label ORDER BY t.o SEPARATOR ', ') INTO v_l FROM (
      SELECT CONCAT(a.name, IF(a.days_left IS NULL, ' (below the minimum)', CONCAT(' (', TRIM(TRAILING '.' FROM TRIM(TRAILING '0' FROM FORMAT(a.days_left, 1))), ' days left)'))) AS label, IFNULL(a.days_left, 0) AS o
        FROM v_rm_advice a WHERE a.status = 'ORDER_NOW'
         AND NOT EXISTS (SELECT 1 FROM purchase_order_items poi JOIN purchase_orders po ON po.id = poi.purchase_order_id WHERE poi.item_id = a.item_id AND po.status = 'DRAFT')
       ORDER BY IFNULL(a.days_left, 0) LIMIT 3) t;
    CALL sp_today_add(30, 'MATERIAL_NOW', 'bad', v_n, 'material to order now', 'materials to order now', v_l, '/buying-advice');

    SELECT COUNT(*) INTO v_n FROM v_rm_advice a WHERE a.status = 'ORDER_SOON'
       AND NOT EXISTS (SELECT 1 FROM purchase_order_items poi JOIN purchase_orders po ON po.id = poi.purchase_order_id WHERE poi.item_id = a.item_id AND po.status = 'DRAFT');
    SELECT GROUP_CONCAT(t.label ORDER BY t.o SEPARATOR ', ') INTO v_l FROM (
      SELECT a.name AS label, IFNULL(a.days_left, 0) AS o FROM v_rm_advice a WHERE a.status = 'ORDER_SOON'
         AND NOT EXISTS (SELECT 1 FROM purchase_order_items poi JOIN purchase_orders po ON po.id = poi.purchase_order_id WHERE poi.item_id = a.item_id AND po.status = 'DRAFT')
       ORDER BY IFNULL(a.days_left, 0) LIMIT 3) t;
    CALL sp_today_add(95, 'MATERIAL_SOON', 'info', v_n, 'material to order soon', 'materials to order soon', v_l, '/buying-advice');
  END IF;

  -- a machine about to run out of the material beside it
  IF fn_can(p_actor, 'Stock', 'read') = 1 OR fn_can(p_actor, 'Production', 'read') = 1 THEN
    SELECT COUNT(*) INTO v_n FROM v_machine_stock WHERE status = 'LOW';
    SELECT GROUP_CONCAT(t.label ORDER BY t.o SEPARATOR ', ') INTO v_l FROM (
      SELECT CONCAT(workstation_code, ' ', item_name, ' (', TRIM(TRAILING '.' FROM TRIM(TRAILING '0' FROM FORMAT(hours_left, 1))), ' h left)') AS label, hours_left AS o
        FROM v_machine_stock WHERE status = 'LOW' ORDER BY hours_left LIMIT 3) t;
    CALL sp_today_add(40, 'MACHINE_LOW', 'bad', v_n, 'machine is nearly out of material', 'machines are nearly out of material', v_l, '/machine-stock');
  END IF;

  -- quality: readings outside the limits on a sheet still open, and running machines that are due a reading
  IF fn_can(p_actor, 'QC', 'read') = 1 THEN
    SELECT COUNT(*) INTO v_n FROM qc_sheet_rows r JOIN qc_sheets s ON s.id = r.sheet_id
     WHERE s.status = 'OPEN' AND r.is_void = 0 AND r.out_of_range = 1 AND r.measured_at >= NOW() - INTERVAL 4 HOUR;
    SELECT GROUP_CONCAT(t.label ORDER BY t.o DESC SEPARATOR ', ') INTO v_l FROM (
      SELECT CONCAT(IFNULL(w.code, s.sheet_number), ' ', s.batch_number, ' (', r.out_params, ')') AS label, r.measured_at AS o
        FROM qc_sheet_rows r JOIN qc_sheets s ON s.id = r.sheet_id LEFT JOIN workstations w ON w.id = s.workstation_id
       WHERE s.status = 'OPEN' AND r.is_void = 0 AND r.out_of_range = 1 AND r.measured_at >= NOW() - INTERVAL 4 HOUR ORDER BY r.measured_at DESC LIMIT 3) t;
    CALL sp_today_add(50, 'QC_BAD', 'bad', v_n, 'QC reading outside the limits in the last 4 hours', 'QC readings outside the limits in the last 4 hours', v_l, '/quality/sheets?status=OPEN');

    SELECT COUNT(*) INTO v_n FROM workstations w
     WHERE EXISTS (SELECT 1 FROM job_cards jc WHERE jc.workstation_id = w.id AND jc.status = 'ACTIVE' AND jc.started_at < NOW() - INTERVAL v_limit MINUTE)
       AND COALESCE((SELECT MAX(GREATEST(COALESCE(r.measured_at, s.created_at), s.created_at)) FROM qc_sheets s LEFT JOIN qc_sheet_rows r ON r.sheet_id = s.id AND r.is_void = 0
                      WHERE s.workstation_id = w.id AND s.status = 'OPEN'), '1970-01-01') < NOW() - INTERVAL v_limit MINUTE;
    SELECT GROUP_CONCAT(t.code ORDER BY t.code SEPARATOR ', ') INTO v_l FROM (
      SELECT w.code FROM workstations w
       WHERE EXISTS (SELECT 1 FROM job_cards jc WHERE jc.workstation_id = w.id AND jc.status = 'ACTIVE' AND jc.started_at < NOW() - INTERVAL v_limit MINUTE)
         AND COALESCE((SELECT MAX(GREATEST(COALESCE(r.measured_at, s.created_at), s.created_at)) FROM qc_sheets s LEFT JOIN qc_sheet_rows r ON r.sheet_id = s.id AND r.is_void = 0
                        WHERE s.workstation_id = w.id AND s.status = 'OPEN'), '1970-01-01') < NOW() - INTERVAL v_limit MINUTE
       ORDER BY w.code LIMIT 3) t;
    CALL sp_today_add(55, 'QC_DUE', 'warn', v_n, 'running machine is due a QC reading', 'running machines are due a QC reading', v_l, '/quality/machines');
  END IF;

  -- customers: who to call about their next order, reminders and new leads
  IF fn_can(p_actor, 'Customers', 'read') = 1 THEN
    SELECT COUNT(*) INTO v_n FROM v_customer_reorder WHERE reorder_status IN ('DUE', 'QUIET');
    SELECT GROUP_CONCAT(t.label ORDER BY t.o DESC SEPARATOR ', ') INTO v_l FROM (
      SELECT CONCAT(name, IF(days_since_last - usual_gap_days < 1, ' (due now)', CONCAT(' (', ROUND(days_since_last - usual_gap_days), ' days over)'))) AS label, days_since_last - usual_gap_days AS o
        FROM v_customer_reorder WHERE reorder_status IN ('DUE', 'QUIET') ORDER BY days_since_last - usual_gap_days DESC LIMIT 3) t;
    CALL sp_today_add(60, 'REORDER_DUE', 'warn', v_n, 'customer is due to reorder', 'customers are due to reorder', v_l, '/reorders');

    SELECT COUNT(*) INTO v_n FROM customer_follow_ups WHERE reminder_status = 'PENDING' AND reminder_date IS NOT NULL AND reminder_date <= NOW();
    CALL sp_today_add(65, 'REMINDERS', 'warn', v_n, 'customer reminder is due', 'customer reminders are due', NULL, '/customers');

    SELECT COUNT(*) INTO v_n FROM customer_leads WHERE status IN ('NEW', 'CONTACTED', 'QUOTED') AND next_follow_up IS NOT NULL AND next_follow_up <= NOW();
    SELECT GROUP_CONCAT(t.label ORDER BY t.o SEPARATOR ', ') INTO v_l FROM (
      SELECT company_name AS label, next_follow_up AS o FROM customer_leads
       WHERE status IN ('NEW', 'CONTACTED', 'QUOTED') AND next_follow_up IS NOT NULL AND next_follow_up <= NOW() ORDER BY next_follow_up LIMIT 3) t;
    CALL sp_today_add(70, 'LEADS', 'warn', v_n, 'lead is waiting for a follow-up', 'leads are waiting for a follow-up', v_l, '/customer-leads');

    SELECT COUNT(*) INTO v_n FROM v_customer_reorder WHERE reorder_status = 'SOON';
    SELECT GROUP_CONCAT(t.label ORDER BY t.o SEPARATOR ', ') INTO v_l FROM (
      SELECT CONCAT(name, ' (about ', DATE_FORMAT(expected_next, '%e %b'), ')') AS label, expected_next AS o FROM v_customer_reorder WHERE reorder_status = 'SOON' ORDER BY expected_next LIMIT 3) t;
    CALL sp_today_add(75, 'REORDER_SOON', 'info', v_n, 'customer will probably reorder within a week', 'customers will probably reorder within a week', v_l, '/reorders');
  END IF;

  -- confirmed orders nobody has planned
  IF fn_can(p_actor, 'WorkOrders', 'read') = 1 THEN
    SELECT COUNT(*) INTO v_n FROM sales_orders o
     WHERE o.status IN ('CONFIRMED', 'APPROVED') AND o.confirmed_at < NOW() - INTERVAL 1 DAY
       AND NOT EXISTS (SELECT 1 FROM job_cards jc JOIN work_orders wo ON wo.id = jc.work_order_id WHERE wo.sales_order_id = o.id);
    SELECT GROUP_CONCAT(t.label ORDER BY t.o SEPARATOR ', ') INTO v_l FROM (
      SELECT CONCAT(o.order_number, ' ', o.customer_name) AS label, o.confirmed_at AS o FROM sales_orders o
       WHERE o.status IN ('CONFIRMED', 'APPROVED') AND o.confirmed_at < NOW() - INTERVAL 1 DAY
         AND NOT EXISTS (SELECT 1 FROM job_cards jc JOIN work_orders wo ON wo.id = jc.work_order_id WHERE wo.sales_order_id = o.id)
       ORDER BY o.confirmed_at LIMIT 3) t;
    CALL sp_today_add(85, 'NO_JOB', 'info', v_n, 'confirmed order has no job card', 'confirmed orders have no job card', v_l, '/work-orders');
  END IF;

  -- goods ready to go
  IF fn_can(p_actor, 'Dispatch', 'read') = 1 THEN
    SELECT COUNT(*) INTO v_n FROM sales_orders WHERE status IN ('READY_TO_DISPATCH', 'DISPATCH_PREP');
    SELECT GROUP_CONCAT(t.label ORDER BY t.o SEPARATOR ', ') INTO v_l FROM (
      SELECT CONCAT(order_number, ' ', customer_name) AS label, updated_at AS o FROM sales_orders WHERE status IN ('READY_TO_DISPATCH', 'DISPATCH_PREP') ORDER BY updated_at LIMIT 3) t;
    CALL sp_today_add(90, 'DISPATCH', 'info', v_n, 'order is ready to dispatch', 'orders are ready to dispatch', v_l, '/dispatch');
  END IF;

  SELECT sort_no, kind, tone, headline, detail, url, qty FROM tmp_today ORDER BY FIELD(tone, 'bad', 'warn', 'info'), sort_no;
  DROP TEMPORARY TABLE tmp_today;
END $$

DELIMITER ;
