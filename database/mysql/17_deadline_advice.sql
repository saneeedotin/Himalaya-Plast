-- =============================================================================
-- HPOS Factory OS  |  17_deadline_advice.sql  -  deadline-aware orders. For every open order HPOS works out when
-- production can realistically finish (the planning board's dates if the jobs are planned, otherwise metres left over
-- the factory's real output per day, starting when material and a machine are free) and compares it with the delivery
-- date: on track, at risk (no slack) or late. It also warns days before a date and when an order is stuck waiting.
-- This is advice only: the delivery date is still typed by a person. Needs 01-16.
-- Reading needs SalesOrders:read.
-- =============================================================================
USE hpos;

INSERT IGNORE INTO system_settings (setting_key, setting_value, description) VALUES
  ('due_warning_days',       '3',    'Days before a delivery date when HPOS warns that an order is due soon'),
  ('buffer_days',            '1',    'Spare days added to an estimate of when production finishes'),
  ('default_metres_per_day', '1000', 'Metres a machine makes in a day, used until real job cards show the actual figure');

-- Metres per machine-day from job cards completed in the last 90 days, else the default setting
CREATE OR REPLACE VIEW v_output_rate AS
SELECT GREATEST(1, COALESCE(
         (SELECT SUM(x.good_qty) / NULLIF(SUM(x.days), 0)
            FROM (SELECT good_qty, GREATEST(1, CEIL(TIMESTAMPDIFF(MINUTE, started_at, completed_at) / 1440)) AS days
                    FROM job_cards
                   WHERE status = 'COMPLETED' AND started_at IS NOT NULL AND completed_at IS NOT NULL AND good_qty > 0
                     AND completed_at >= NOW() - INTERVAL 90 DAY) x),
         fn_setting_num('default_metres_per_day', 1000))) AS metres_per_day;

-- One row per open order. basis: PLANNED (jobs have dates on the planning board), ESTIMATE, or DONE (nothing left to make)
-- health: READY, ON_TRACK, AT_RISK (finishes on the delivery date, no slack), LATE (finishes after it)
CREATE OR REPLACE VIEW v_order_projection AS
SELECT t.*,
       DATEDIFF(DATE(t.delivery_date), t.projected_finish) AS slack_days,
       DATE_ADD(t.projected_finish, INTERVAL 1 DAY) AS suggested_delivery,
       CASE WHEN t.remaining_metres <= 0 THEN 'READY'
            WHEN DATEDIFF(DATE(t.delivery_date), t.projected_finish) < 0 THEN 'LATE'
            WHEN DATEDIFF(DATE(t.delivery_date), t.projected_finish) = 0 THEN 'AT_RISK'
            ELSE 'ON_TRACK' END AS health
  FROM (
    SELECT z.*,
           CASE WHEN z.remaining_metres <= 0 THEN CURDATE()
                WHEN z.planned_finish IS NOT NULL THEN GREATEST(z.planned_finish, CURDATE())
                ELSE DATE_ADD(z.start_date, INTERVAL (z.days_needed - 1 + CAST(fn_setting_num('buffer_days', 1) AS SIGNED)) DAY) END AS projected_finish,
           CASE WHEN z.remaining_metres <= 0 THEN 'DONE' WHEN z.planned_finish IS NOT NULL THEN 'PLANNED' ELSE 'ESTIMATE' END AS basis
      FROM (
        SELECT y.*, CEIL(y.remaining_metres / y.rate_per_day) AS days_needed,
               GREATEST(DATE_ADD(CURDATE(), INTERVAL y.material_wait_days DAY), y.machine_free_date) AS start_date
          FROM (
            SELECT o.id AS order_id, o.order_number, o.customer_name, o.status, o.delivery_date,
                   COALESCE((SELECT SUM(soi.qty) FROM sales_order_items soi WHERE soi.sales_order_id = o.id), 0) AS total_metres,
                   COALESCE((SELECT SUM(wo.produced_qty) FROM work_orders wo WHERE wo.sales_order_id = o.id), 0) AS made_metres,
                   GREATEST(0, COALESCE((SELECT SUM(soi.qty) FROM sales_order_items soi WHERE soi.sales_order_id = o.id), 0)
                             - COALESCE((SELECT SUM(wo.produced_qty) FROM work_orders wo WHERE wo.sales_order_id = o.id), 0)) AS remaining_metres,
                   (SELECT metres_per_day FROM v_output_rate) AS rate_per_day,
                   COALESCE((SELECT MAX(IFNULL(i.lead_time_days, 7)) FROM v_order_material_check mc JOIN items i ON i.id = mc.rm_item_id
                              WHERE mc.sales_order_id = o.id AND mc.status = 'DEFICIT'), 0) AS material_wait_days,
                   COALESCE((SELECT MIN(COALESCE(DATE_ADD(b.busy_until, INTERVAL 1 DAY), CURDATE()))
                               FROM workstations w
                               LEFT JOIN (SELECT workstation_id, MAX(planned_end) AS busy_until FROM job_cards
                                           WHERE status IN ('QUEUED', 'ACTIVE') AND planned_end IS NOT NULL GROUP BY workstation_id) b ON b.workstation_id = w.id
                              WHERE w.status <> 'MAINTENANCE'), CURDATE()) AS machine_free_date,
                   (SELECT MAX(jc.planned_end) FROM job_cards jc JOIN work_orders wo ON wo.id = jc.work_order_id
                     WHERE wo.sales_order_id = o.id AND jc.status <> 'COMPLETED' AND jc.planned_end IS NOT NULL) AS planned_finish
              FROM sales_orders o
             WHERE o.status IN ('DRAFT', 'PROFORMA_SENT', 'CONFIRMED', 'APPROVED', 'IN_PRODUCTION', 'READY_TO_DISPATCH', 'DISPATCH_PREP')
          ) y
      ) z
  ) t;

DELIMITER $$

-- One order's projection
DROP PROCEDURE IF EXISTS sp_order_projection $$
CREATE PROCEDURE sp_order_projection(IN p_actor CHAR(36), IN p_order_id CHAR(36))
BEGIN
  CALL sp_require_permission(p_actor, 'SalesOrders', 'read');
  SELECT * FROM v_order_projection WHERE order_id = p_order_id;
END $$

-- Every open order with its projection, worst first
DROP PROCEDURE IF EXISTS sp_order_health_list $$
CREATE PROCEDURE sp_order_health_list(IN p_actor CHAR(36))
BEGIN
  CALL sp_require_permission(p_actor, 'SalesOrders', 'read');
  SELECT p.*, ROUND(100 * p.made_metres / NULLIF(p.total_metres, 0)) AS percent_made,
         IF(p.status IN ('DRAFT', 'PROFORMA_SENT'), 1, 0) AS not_confirmed
    FROM v_order_projection p
   ORDER BY FIELD(p.health, 'LATE', 'AT_RISK', 'ON_TRACK', 'READY'), p.delivery_date;
END $$

-- One alert row per kind: "<n> <suffix>". Old wording is closed when the count changes, and the row closes at zero.
DROP PROCEDURE IF EXISTS sp_notify_count $$
CREATE PROCEDURE sp_notify_count(
  IN p_source_type VARCHAR(20), IN p_source_id VARCHAR(64), IN p_count INT, IN p_suffix VARCHAR(160),
  IN p_message TEXT, IN p_url VARCHAR(255), IN p_audience VARCHAR(160))
BEGIN
  UPDATE notifications SET status = 'RESOLVED'
   WHERE source_type = p_source_type AND source_id = p_source_id AND status <> 'RESOLVED' AND title <> CONCAT(p_count, ' ', p_suffix);
  IF p_count > 0 THEN
    CALL sp_notify_upsert(p_source_type, p_source_id, 'ACTION_REQUIRED', CONCAT(p_count, ' ', p_suffix), p_message, p_url, p_audience);
  END IF;
END $$

DROP PROCEDURE IF EXISTS sp_alerts_deadlines $$
CREATE PROCEDURE sp_alerts_deadlines()
BEGIN
  DECLARE v_warn INT DEFAULT CAST(fn_setting_num('due_warning_days', 3) AS SIGNED);
  DECLARE v_n INT DEFAULT 0;

  -- 1. each order due within the warning days that still has metres to make (the due-today alert takes over on the day)
  BEGIN
    DECLARE v_id CHAR(36); DECLARE v_no VARCHAR(40); DECLARE v_days INT; DECLARE v_pct INT; DECLARE v_left DECIMAL(16,3); DECLARE v_done TINYINT DEFAULT 0;
    DECLARE cur CURSOR FOR
      SELECT order_id, order_number, DATEDIFF(DATE(delivery_date), CURDATE()), IFNULL(ROUND(100 * made_metres / NULLIF(total_metres, 0)), 0), remaining_metres
        FROM v_order_projection
       WHERE status IN ('CONFIRMED', 'APPROVED', 'IN_PRODUCTION') AND remaining_metres > 0
         AND DATE(delivery_date) > CURDATE() AND DATE(delivery_date) <= DATE_ADD(CURDATE(), INTERVAL v_warn DAY);
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_done = 1;
    OPEN cur;
    soon_loop: LOOP
      FETCH cur INTO v_id, v_no, v_days, v_pct, v_left;
      IF v_done = 1 THEN LEAVE soon_loop; END IF;
      CALL sp_notify_upsert('ORDER', v_id, 'ACTION_REQUIRED', CONCAT('Order ', v_no, ' is due soon'),
        CONCAT('Due in ', v_days, IF(v_days = 1, ' day', ' days'), ', ', v_pct, '% made, ', FORMAT(v_left, 0), ' m still to make.'),
        CONCAT('/orders/', v_id), 'SalesOrders,WorkOrders');
    END LOOP;
    CLOSE cur;
    UPDATE notifications n SET n.status = 'RESOLVED'
     WHERE n.source_type = 'ORDER' AND n.status <> 'RESOLVED' AND n.title LIKE '% is due soon'
       AND NOT EXISTS (SELECT 1 FROM v_order_projection p
                        WHERE p.order_id = n.source_id COLLATE utf8mb4_unicode_ci AND p.status IN ('CONFIRMED', 'APPROVED', 'IN_PRODUCTION') AND p.remaining_metres > 0
                          AND DATE(p.delivery_date) > CURDATE() AND DATE(p.delivery_date) <= DATE_ADD(CURDATE(), INTERVAL v_warn DAY));
  END;

  -- 2. orders that, going by the plan or the factory's real output, will miss their date
  SELECT COUNT(*) INTO v_n FROM v_order_projection WHERE health = 'LATE' AND status IN ('CONFIRMED', 'APPROVED', 'IN_PRODUCTION');
  CALL sp_notify_count('ORDER', 'late', v_n, 'orders will miss their delivery date',
    'Going by the planning board, or by how much the machines usually make a day. Open Deadlines to see which.', '/deadlines', 'SalesOrders,WorkOrders');

  -- 3. orders waiting on someone: a proforma the client has not confirmed, a confirmed order nobody planned, goods ready but not sent
  SELECT COUNT(*) INTO v_n FROM sales_orders WHERE status = 'PROFORMA_SENT' AND proforma_sent_at < NOW() - INTERVAL 3 DAY;
  CALL sp_notify_count('ORDER', 'stuck-proforma', v_n, 'proformas waiting for the client for over 3 days',
    'Call the client for their confirmation. Open the orders and look for Proforma sent.', '/orders?status=PROFORMA_SENT', 'SalesOrders');
  SELECT COUNT(*) INTO v_n FROM sales_orders o
   WHERE o.status IN ('CONFIRMED', 'APPROVED') AND o.confirmed_at < NOW() - INTERVAL 1 DAY
     AND NOT EXISTS (SELECT 1 FROM job_cards jc JOIN work_orders wo ON wo.id = jc.work_order_id WHERE wo.sales_order_id = o.id);
  CALL sp_notify_count('ORDER', 'stuck-nojob', v_n, 'confirmed orders have no job card yet',
    'Add a job card to the work order so production can be planned.', '/work-orders', 'WorkOrders');
  SELECT COUNT(*) INTO v_n FROM sales_orders WHERE status IN ('READY_TO_DISPATCH', 'DISPATCH_PREP') AND updated_at < NOW() - INTERVAL 2 DAY;
  CALL sp_notify_count('ORDER', 'stuck-dispatch', v_n, 'orders ready to dispatch for over 2 days',
    'Load the cartons and send them, or tell the client when they will go.', '/dispatch', 'Dispatch,SalesOrders');
END $$

DELIMITER ;
