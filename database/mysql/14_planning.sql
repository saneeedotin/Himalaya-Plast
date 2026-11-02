-- =============================================================================
-- HPOS Factory OS  |  14_planning.sql  -  the production planning board: each job card can be given planned
-- start and end dates on its machine; the board shows machines against days, flags overlaps on one machine,
-- and lists the jobs that have no plan yet. Planning is advice for the shop floor; it never blocks a job.
-- Reading needs WorkOrders:read, planning needs WorkOrders:update. Needs 01-13 (and job_cards.planned_start /
-- planned_end: see upgrades/2026-11-planning.sql for databases loaded before it).
-- =============================================================================
USE hpos;

DELIMITER $$

-- p_from: first day shown; p_days: how many days (1 to 62)
DROP PROCEDURE IF EXISTS sp_plan_board $$
CREATE PROCEDURE sp_plan_board(IN p_actor CHAR(36), IN p_from DATE, IN p_days INT)
BEGIN
  DECLARE v_from DATE DEFAULT IFNULL(p_from, CURDATE());
  DECLARE v_to DATE DEFAULT DATE_ADD(IFNULL(p_from, CURDATE()), INTERVAL LEAST(GREATEST(IFNULL(p_days, 14), 1), 62) - 1 DAY);
  CALL sp_require_permission(p_actor, 'WorkOrders', 'read');
  SELECT id, code, name, status FROM workstations ORDER BY code;
  SELECT jc.id AS job_card_id, jc.status, jc.workstation_id, jc.planned_start, jc.planned_end,
         wo.work_order_number, wo.planned_qty, wo.produced_qty, fg.code AS fg_code, fg.name AS fg_name,
         so.delivery_date
    FROM job_cards jc
    JOIN work_orders wo ON wo.id = jc.work_order_id
    JOIN items fg ON fg.id = wo.fg_item_id
    LEFT JOIN sales_orders so ON so.id = wo.sales_order_id
   WHERE jc.planned_start IS NOT NULL AND jc.planned_start <= v_to AND jc.planned_end >= v_from
   ORDER BY jc.workstation_id, jc.planned_start, jc.created_at;
  SELECT jc.id AS job_card_id, jc.status, ws.code AS workstation_code, ws.name AS workstation_name,
         wo.id AS work_order_id, wo.work_order_number, wo.planned_qty, fg.code AS fg_code, fg.name AS fg_name, so.delivery_date
    FROM job_cards jc
    JOIN work_orders wo ON wo.id = jc.work_order_id
    JOIN items fg ON fg.id = wo.fg_item_id
    JOIN workstations ws ON ws.id = jc.workstation_id
    LEFT JOIN sales_orders so ON so.id = wo.sales_order_id
   WHERE jc.planned_start IS NULL AND jc.status IN ('QUEUED', 'ACTIVE')
   ORDER BY so.delivery_date IS NULL, so.delivery_date, wo.work_order_number;
END $$

DROP PROCEDURE IF EXISTS sp_job_card_plan_get $$
CREATE PROCEDURE sp_job_card_plan_get(IN p_actor CHAR(36), IN p_job_card_id CHAR(36))
BEGIN
  CALL sp_require_permission(p_actor, 'WorkOrders', 'read');
  SELECT v.*, jc.workstation_id AS current_workstation_id, jc.planned_start, jc.planned_end, so.delivery_date
    FROM v_job_card_live v
    JOIN job_cards jc ON jc.id = v.job_card_id
    JOIN work_orders wo ON wo.id = jc.work_order_id
    LEFT JOIN sales_orders so ON so.id = wo.sales_order_id
   WHERE v.job_card_id = p_job_card_id;
  SELECT id, code, name, status FROM workstations ORDER BY code;
END $$

-- Gives a job card its planned dates, and optionally another machine (only while it is still queued).
-- Returns the other jobs on that machine that overlap, so the page can warn; the plan is saved either way.
DROP PROCEDURE IF EXISTS sp_job_card_plan $$
CREATE PROCEDURE sp_job_card_plan(
  IN p_actor CHAR(36), IN p_job_card_id CHAR(36), IN p_workstation_id CHAR(36), IN p_start DATE, IN p_end DATE)
BEGIN
  DECLARE v_status VARCHAR(20); DECLARE v_ws CHAR(36); DECLARE v_new_ws CHAR(36);
  DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;
  CALL sp_require_permission(p_actor, 'WorkOrders', 'update');
  IF p_start IS NULL OR p_end IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Give both a start and an end date'; END IF;
  IF p_end < p_start THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The end date cannot be before the start date'; END IF;
  IF DATEDIFF(p_end, p_start) > 120 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A job can be planned for up to 120 days'; END IF;
  SELECT status, workstation_id INTO v_status, v_ws FROM job_cards WHERE id = p_job_card_id;
  IF v_status IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Job card not found'; END IF;
  IF v_status = 'COMPLETED' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'This job is complete, so it cannot be planned'; END IF;
  SET v_new_ws = IFNULL(NULLIF(p_workstation_id, ''), v_ws);
  IF v_new_ws <> v_ws THEN
    IF v_status <> 'QUEUED' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A running job cannot move to another machine'; END IF;
    IF NOT EXISTS (SELECT 1 FROM workstations WHERE id = v_new_ws) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Machine not found'; END IF;
  END IF;
  START TRANSACTION;
    UPDATE job_cards SET planned_start = p_start, planned_end = p_end, workstation_id = v_new_ws WHERE id = p_job_card_id;
    CALL sp_audit(p_actor, 'JOB_PLANNED', NULL, JSON_OBJECT('jobCardId', p_job_card_id, 'start', p_start, 'end', p_end));
  COMMIT;
  SELECT COUNT(*) AS clashes, GROUP_CONCAT(wo.work_order_number ORDER BY jc.planned_start SEPARATOR ', ') AS clash_list
    FROM job_cards jc JOIN work_orders wo ON wo.id = jc.work_order_id
   WHERE jc.workstation_id = v_new_ws AND jc.id <> p_job_card_id AND jc.status <> 'COMPLETED'
     AND jc.planned_start IS NOT NULL AND jc.planned_start <= p_end AND jc.planned_end >= p_start;
END $$

DROP PROCEDURE IF EXISTS sp_job_card_unplan $$
CREATE PROCEDURE sp_job_card_unplan(IN p_actor CHAR(36), IN p_job_card_id CHAR(36))
BEGIN
  CALL sp_require_permission(p_actor, 'WorkOrders', 'update');
  IF NOT EXISTS (SELECT 1 FROM job_cards WHERE id = p_job_card_id) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Job card not found'; END IF;
  UPDATE job_cards SET planned_start = NULL, planned_end = NULL WHERE id = p_job_card_id AND status <> 'COMPLETED';
  CALL sp_audit(p_actor, 'JOB_UNPLANNED', NULL, JSON_OBJECT('jobCardId', p_job_card_id));
END $$

DELIMITER ;
