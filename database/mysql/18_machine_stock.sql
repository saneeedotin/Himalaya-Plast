-- =============================================================================
-- HPOS Factory OS  |  18_machine_stock.sql  -  raw material kept next to the machines. Material is received into the
-- store; a person issues it to a machine in lots (for example 50 sacks at a time) and can return what is not used.
-- When a machine makes metres, what it uses is taken from its own stock first. Total stock, the order hard gate, low-stock
-- alerts and the books do not change: stock at a machine is still stock, it is only being tracked by place.
-- Store stock = total stock - stock at machines. Reading needs Stock:read (or Production:read for the levels);
-- issuing and returning need Stock:create. Needs 01-17; 04 records the consumption.
-- =============================================================================
USE hpos;

INSERT IGNORE INTO system_settings (setting_key, setting_value, description) VALUES
  ('issue_lot_sacks',   '50', 'Sacks the Issue to machine screen fills in by default'),
  ('machine_low_hours', '2',  'Hours of material left at a machine below which HPOS warns');

CREATE TABLE IF NOT EXISTS machine_stock_moves (
  id             CHAR(36)      NOT NULL DEFAULT (UUID()),
  workstation_id CHAR(36)      NOT NULL,
  item_id        CHAR(36)      NOT NULL,
  kind           VARCHAR(10)   NOT NULL,        -- ISSUE (store to machine), RETURN (machine to store), CONSUME (used in production)
  qty            DECIMAL(16,3) NOT NULL,
  bags           INT           NULL,
  reference_id   VARCHAR(64)   NULL,            -- the job card for CONSUME
  note           VARCHAR(200)  NULL,
  by_user_id     CHAR(36)      NULL,
  by_name        VARCHAR(190)  NULL,
  created_at     DATETIME(3)   NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY ix_msm_ws_item (workstation_id, item_id),
  KEY ix_msm_created (created_at),
  CONSTRAINT fk_msm_ws   FOREIGN KEY (workstation_id) REFERENCES workstations(id),
  CONSTRAINT fk_msm_item FOREIGN KEY (item_id) REFERENCES items(id),
  CONSTRAINT fk_msm_user FOREIGN KEY (by_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT ck_msm_kind CHECK (kind IN ('ISSUE', 'RETURN', 'CONSUME')),
  CONSTRAINT ck_msm_qty CHECK (qty > 0)
) ENGINE=InnoDB;

DELIMITER $$

-- the record of what moved is never edited or removed
DROP TRIGGER IF EXISTS trg_msm_no_update $$
CREATE TRIGGER trg_msm_no_update BEFORE UPDATE ON machine_stock_moves FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Machine stock movements cannot be changed. Issue or return material to correct it';
END $$
DROP TRIGGER IF EXISTS trg_msm_no_delete $$
CREATE TRIGGER trg_msm_no_delete BEFORE DELETE ON machine_stock_moves FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Machine stock movements cannot be deleted. Issue or return material to correct it';
END $$

-- Stock at one machine of one item (issued - returned - used)
DROP FUNCTION IF EXISTS fn_machine_stock $$
CREATE FUNCTION fn_machine_stock(p_ws CHAR(36), p_item CHAR(36)) RETURNS DECIMAL(16,3)
READS SQL DATA
BEGIN
  RETURN COALESCE((SELECT SUM(CASE kind WHEN 'ISSUE' THEN qty ELSE -qty END) FROM machine_stock_moves WHERE workstation_id = p_ws AND item_id = p_item), 0);
END $$

DELIMITER ;

-- Per machine and item: what is there, what it uses per hour (last 7 days of its own use) and how many hours that lasts.
-- LOW when under the warning hours, including a machine that used material and now has none.
CREATE OR REPLACE VIEW v_machine_stock AS
SELECT t.*, IF(t.per_hour > 0, ROUND(t.on_machine / t.per_hour, 1), NULL) AS hours_left,
       CASE WHEN t.per_hour > 0 AND t.on_machine / t.per_hour < fn_setting_num('machine_low_hours', 2) THEN 'LOW' ELSE 'OK' END AS status
  FROM (
    SELECT m.workstation_id, w.code AS workstation_code, w.name AS workstation_name, m.item_id, i.code AS item_code, i.name AS item_name, i.uom,
           ROUND(SUM(CASE m.kind WHEN 'ISSUE' THEN m.qty ELSE -m.qty END), 3) AS on_machine,
           COALESCE((SELECT SUM(c.qty) / (LEAST(7, GREATEST(1, DATEDIFF(CURDATE(), MIN(DATE(c.created_at))) + 1)) * 24)
                       FROM machine_stock_moves c
                      WHERE c.workstation_id = m.workstation_id AND c.item_id = m.item_id AND c.kind = 'CONSUME' AND c.created_at >= NOW() - INTERVAL 7 DAY), 0) AS per_hour,
           MAX(m.created_at) AS last_move
      FROM machine_stock_moves m
      JOIN workstations w ON w.id = m.workstation_id
      JOIN items i ON i.id = m.item_id
     GROUP BY m.workstation_id, w.code, w.name, m.item_id, i.code, i.name, i.uom
  ) t
 WHERE t.on_machine > 0 OR t.per_hour > 0;

DELIMITER $$

-- Machines and the materials that can be issued, with what is in the store and at the machines
DROP PROCEDURE IF EXISTS sp_machine_stock_pick $$
CREATE PROCEDURE sp_machine_stock_pick(IN p_actor CHAR(36))
BEGIN
  CALL sp_require_permission(p_actor, 'Stock', 'read');
  SELECT id, code, name, status FROM workstations ORDER BY code;
  SELECT i.id AS item_id, i.code, i.name, i.uom, COALESCE(b.physical_stock, 0) AS total_stock,
         COALESCE((SELECT SUM(CASE m.kind WHEN 'ISSUE' THEN m.qty ELSE -m.qty END) FROM machine_stock_moves m WHERE m.item_id = i.id), 0) AS at_machines
    FROM items i LEFT JOIN v_stock_balance b ON b.item_id = i.id
   WHERE i.category IN ('RAW_MATERIAL', 'PACKAGING')
   ORDER BY i.category, i.name;
END $$

-- The levels at every machine, for the Machine stock screen and the shop floor cards
DROP PROCEDURE IF EXISTS sp_machine_stock_levels $$
CREATE PROCEDURE sp_machine_stock_levels(IN p_actor CHAR(36))
BEGIN
  IF fn_can(p_actor, 'Stock', 'read') = 0 AND fn_can(p_actor, 'Production', 'read') = 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Forbidden: you do not have permission for this action';
  END IF;
  SELECT * FROM v_machine_stock ORDER BY workstation_code, item_name;
END $$

-- The latest movements, for the Machine stock screen
DROP PROCEDURE IF EXISTS sp_machine_stock_recent $$
CREATE PROCEDURE sp_machine_stock_recent(IN p_actor CHAR(36), IN p_limit INT)
BEGIN
  CALL sp_require_permission(p_actor, 'Stock', 'read');
  SELECT m.created_at, m.kind, m.qty, m.bags, m.note, m.by_name, w.code AS workstation_code, i.code AS item_code, i.name AS item_name, i.uom
    FROM machine_stock_moves m JOIN workstations w ON w.id = m.workstation_id JOIN items i ON i.id = m.item_id
   ORDER BY m.created_at DESC, m.id LIMIT 100;
END $$

-- In store vs at the machines, per raw material (for the Stock page columns)
DROP PROCEDURE IF EXISTS sp_stock_locations $$
CREATE PROCEDURE sp_stock_locations(IN p_actor CHAR(36))
BEGIN
  CALL sp_require_permission(p_actor, 'Stock', 'read');
  SELECT item_id, ROUND(SUM(CASE kind WHEN 'ISSUE' THEN qty ELSE -qty END), 3) AS at_machines FROM machine_stock_moves GROUP BY item_id HAVING at_machines <> 0;
END $$

DROP PROCEDURE IF EXISTS sp_machine_stock_issue $$
CREATE PROCEDURE sp_machine_stock_issue(
  IN p_actor CHAR(36), IN p_workstation_id CHAR(36), IN p_item_id CHAR(36), IN p_qty DECIMAL(16,3), IN p_bags INT, IN p_note VARCHAR(200), OUT p_out_id CHAR(36))
BEGIN
  DECLARE v_total DECIMAL(16,3) DEFAULT 0; DECLARE v_at DECIMAL(16,3) DEFAULT 0; DECLARE v_name VARCHAR(190); DECLARE v_uom VARCHAR(20); DECLARE v_msg VARCHAR(200);
  DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;
  CALL sp_require_permission(p_actor, 'Stock', 'create');
  IF p_qty IS NULL OR p_qty <= 0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The quantity must be above zero'; END IF;
  IF NOT EXISTS (SELECT 1 FROM workstations WHERE id = p_workstation_id) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Choose the machine'; END IF;
  SELECT uom INTO v_uom FROM items WHERE id = p_item_id AND category IN ('RAW_MATERIAL', 'PACKAGING');
  IF v_uom IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Choose a raw material or packaging item'; END IF;
  SELECT name INTO v_name FROM users WHERE id = p_actor;
  START TRANSACTION;
    SELECT id INTO p_out_id FROM items WHERE id = p_item_id FOR UPDATE;
    SELECT COALESCE(physical_stock, 0) INTO v_total FROM v_stock_balance WHERE item_id = p_item_id;
    SELECT COALESCE(SUM(CASE kind WHEN 'ISSUE' THEN qty ELSE -qty END), 0) INTO v_at FROM machine_stock_moves WHERE item_id = p_item_id;
    IF p_qty > v_total - v_at THEN
      SET v_msg = CONCAT('Only ', TRIM(TRAILING '.' FROM TRIM(TRAILING '0' FROM FORMAT(GREATEST(v_total - v_at, 0), 3))), ' ', v_uom, ' is in the store to issue');
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = v_msg;
    END IF;
    SET p_out_id = UUID();
    INSERT INTO machine_stock_moves (id, workstation_id, item_id, kind, qty, bags, note, by_user_id, by_name)
    VALUES (p_out_id, p_workstation_id, p_item_id, 'ISSUE', p_qty, p_bags, NULLIF(TRIM(p_note), ''), p_actor, v_name);
    CALL sp_audit(p_actor, 'MACHINE_STOCK_ISSUED', NULL, JSON_OBJECT('workstationId', p_workstation_id, 'itemId', p_item_id, 'qty', p_qty));
  COMMIT;
END $$

DROP PROCEDURE IF EXISTS sp_machine_stock_return $$
CREATE PROCEDURE sp_machine_stock_return(
  IN p_actor CHAR(36), IN p_workstation_id CHAR(36), IN p_item_id CHAR(36), IN p_qty DECIMAL(16,3), IN p_note VARCHAR(200), OUT p_out_id CHAR(36))
BEGIN
  DECLARE v_here DECIMAL(16,3) DEFAULT 0; DECLARE v_name VARCHAR(190); DECLARE v_uom VARCHAR(20); DECLARE v_msg VARCHAR(200);
  DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;
  CALL sp_require_permission(p_actor, 'Stock', 'create');
  IF p_qty IS NULL OR p_qty <= 0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The quantity must be above zero'; END IF;
  SELECT uom INTO v_uom FROM items WHERE id = p_item_id;
  IF v_uom IS NULL OR NOT EXISTS (SELECT 1 FROM workstations WHERE id = p_workstation_id) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Choose the machine and the material'; END IF;
  SELECT name INTO v_name FROM users WHERE id = p_actor;
  START TRANSACTION;
    SELECT id INTO p_out_id FROM items WHERE id = p_item_id FOR UPDATE;
    SET v_here = fn_machine_stock(p_workstation_id, p_item_id);
    IF p_qty > v_here THEN
      SET v_msg = CONCAT('That machine only has ', TRIM(TRAILING '.' FROM TRIM(TRAILING '0' FROM FORMAT(GREATEST(v_here, 0), 3))), ' ', v_uom, ' to return');
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = v_msg;
    END IF;
    SET p_out_id = UUID();
    INSERT INTO machine_stock_moves (id, workstation_id, item_id, kind, qty, note, by_user_id, by_name)
    VALUES (p_out_id, p_workstation_id, p_item_id, 'RETURN', p_qty, NULLIF(TRIM(p_note), ''), p_actor, v_name);
    CALL sp_audit(p_actor, 'MACHINE_STOCK_RETURNED', NULL, JSON_OBJECT('workstationId', p_workstation_id, 'itemId', p_item_id, 'qty', p_qty));
  COMMIT;
END $$

DROP PROCEDURE IF EXISTS sp_alerts_machines $$
CREATE PROCEDURE sp_alerts_machines()
BEGIN
  DECLARE v_n INT DEFAULT 0;
  SELECT COUNT(*) INTO v_n FROM v_machine_stock WHERE status = 'LOW';
  CALL sp_notify_count('MACHINE', 'low-material', v_n, 'machine and material pairs are running low',
    'A machine has under two hours of a material left at its recent rate, or has run out. Issue more from the store on the Machine stock screen.',
    '/machine-stock', 'Stock,Production');
END $$

DELIMITER ;
