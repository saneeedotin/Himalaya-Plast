-- =============================================================================
-- HPOS Factory OS  |  12_qc_sheets.sql  -  the production QC sheet: one sheet per batch run on a job card,
-- a reading every half hour or so (pin, width, leg, weight, fitting), flagged when outside the product's
-- tolerance, signed by whoever entered it. Readings are never edited or deleted; a wrong one is voided.
-- Needs 01-11 (and items.colour: see upgrades/2026-11-qc-sheets.sql for databases loaded before it).
-- Reading needs QC:read, opening and adding readings QC:create, voiding and closing QC:update.
-- =============================================================================
USE hpos;

-- per-product tolerance for each measured size; either limit may be left empty
CREATE TABLE IF NOT EXISTS qc_specs (
  item_id    CHAR(36)      NOT NULL,
  parameter  VARCHAR(10)   NOT NULL,    -- PIN, WIDTH, LEG, WEIGHT
  target     DECIMAL(10,3) NULL,
  min_value  DECIMAL(10,3) NULL,
  max_value  DECIMAL(10,3) NULL,
  PRIMARY KEY (item_id, parameter),
  CONSTRAINT fk_qs_item FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE,
  CONSTRAINT ck_qs_param CHECK (parameter IN ('PIN','WIDTH','LEG','WEIGHT')),
  CONSTRAINT ck_qs_range CHECK (min_value IS NULL OR max_value IS NULL OR min_value <= max_value)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS qc_sheets (
  id             CHAR(36)      NOT NULL DEFAULT (UUID()),
  sheet_number   VARCHAR(40)   NOT NULL,
  job_card_id    CHAR(36)      NULL,
  item_id        CHAR(36)      NOT NULL,
  die_id         CHAR(36)      NULL,
  batch_number   VARCHAR(80)   NOT NULL,
  colour         VARCHAR(40)   NULL,
  dtd            DATE          NULL,
  m_no           VARCHAR(20)   NULL,
  mfg_date       DATE          NOT NULL,
  metres         DECIMAL(16,3) NOT NULL DEFAULT 0,
  status         VARCHAR(10)   NOT NULL DEFAULT 'OPEN',
  created_by_id  CHAR(36)      NULL,
  created_at     DATETIME(3)   NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  closed_by_id   CHAR(36)      NULL,
  closed_at      DATETIME(3)   NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_qsh_number (sheet_number),
  KEY ix_qsh_batch (batch_number),
  KEY ix_qsh_status (status),
  CONSTRAINT fk_qsh_jc   FOREIGN KEY (job_card_id)   REFERENCES job_cards(id) ON DELETE SET NULL,
  CONSTRAINT fk_qsh_item FOREIGN KEY (item_id)       REFERENCES items(id),
  CONSTRAINT fk_qsh_die  FOREIGN KEY (die_id)        REFERENCES dies(id)      ON DELETE SET NULL,
  CONSTRAINT fk_qsh_cby  FOREIGN KEY (created_by_id) REFERENCES users(id)     ON DELETE SET NULL,
  CONSTRAINT fk_qsh_clby FOREIGN KEY (closed_by_id)  REFERENCES users(id)     ON DELETE SET NULL,
  CONSTRAINT ck_qsh_status CHECK (status IN ('OPEN','CLOSED')),
  CONSTRAINT ck_qsh_metres CHECK (metres >= 0)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS qc_sheet_rows (
  id              CHAR(36)      NOT NULL DEFAULT (UUID()),
  sheet_id        CHAR(36)      NOT NULL,
  sr_no           INT           NOT NULL,
  pin             DECIMAL(10,3) NULL,
  width           DECIMAL(10,3) NULL,
  leg             DECIMAL(10,3) NULL,
  weight          DECIMAL(10,3) NULL,
  measured_at     DATETIME      NOT NULL,
  fitting         VARCHAR(10)   NULL,
  out_of_range    TINYINT(1)    NOT NULL DEFAULT 0,
  out_params      VARCHAR(60)   NULL,      -- e.g. PIN,WEIGHT
  authorised_by_id CHAR(36)     NULL,
  authorised_name VARCHAR(190)  NOT NULL,
  is_void         TINYINT(1)    NOT NULL DEFAULT 0,
  void_reason     VARCHAR(200)  NULL,
  voided_by_id    CHAR(36)      NULL,
  voided_at       DATETIME(3)   NULL,
  created_at      DATETIME(3)   NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_qsr_sheet_sr (sheet_id, sr_no),
  CONSTRAINT fk_qsr_sheet FOREIGN KEY (sheet_id)         REFERENCES qc_sheets(id),
  CONSTRAINT fk_qsr_by    FOREIGN KEY (authorised_by_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT ck_qsr_fitting CHECK (fitting IS NULL OR fitting IN ('OK','NOT_OK')),
  CONSTRAINT ck_qsr_nonneg CHECK ((pin IS NULL OR pin >= 0) AND (width IS NULL OR width >= 0) AND (leg IS NULL OR leg >= 0) AND (weight IS NULL OR weight >= 0)),
  CONSTRAINT ck_qsr_any CHECK (pin IS NOT NULL OR width IS NOT NULL OR leg IS NOT NULL OR weight IS NOT NULL OR fitting IS NOT NULL)
) ENGINE=InnoDB;

DELIMITER $$

-- readings are a record: never deleted, and the only change allowed is voiding one (with a reason)
DROP TRIGGER IF EXISTS trg_qcsr_no_delete $$
CREATE TRIGGER trg_qcsr_no_delete BEFORE DELETE ON qc_sheet_rows FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'QC readings cannot be deleted. Void the reading instead';
END $$

DROP TRIGGER IF EXISTS trg_qcsr_guard $$
CREATE TRIGGER trg_qcsr_guard BEFORE UPDATE ON qc_sheet_rows FOR EACH ROW
BEGIN
  IF NOT (OLD.is_void = 0 AND NEW.is_void = 1
          AND NEW.sheet_id <=> OLD.sheet_id AND NEW.sr_no <=> OLD.sr_no AND NEW.pin <=> OLD.pin AND NEW.width <=> OLD.width
          AND NEW.leg <=> OLD.leg AND NEW.weight <=> OLD.weight AND NEW.measured_at <=> OLD.measured_at AND NEW.fitting <=> OLD.fitting
          AND NEW.out_of_range <=> OLD.out_of_range AND NEW.authorised_name <=> OLD.authorised_name) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'QC readings cannot be changed. Void the reading and enter it again';
  END IF;
END $$

-- a reading outside the product's tolerance tells QC and Production straight away
DROP TRIGGER IF EXISTS trg_qcsr_notify $$
CREATE TRIGGER trg_qcsr_notify AFTER INSERT ON qc_sheet_rows FOR EACH ROW
BEGIN
  DECLARE v_no VARCHAR(40); DECLARE v_batch VARCHAR(80);
  DECLARE CONTINUE HANDLER FOR SQLEXCEPTION BEGIN END;
  IF NEW.out_of_range = 1 THEN
    SELECT sheet_number, batch_number INTO v_no, v_batch FROM qc_sheets WHERE id = NEW.sheet_id;
    CALL sp_notify_event('QC', NEW.sheet_id, 'ACTION_REQUIRED', CONCAT('QC reading out of range: batch ', v_batch),
      CONCAT('Sheet ', v_no, ', L-', LPAD(NEW.sr_no, 2, '0'), ': ', REPLACE(NEW.out_params, '_', ' '), ' outside the limit.'),
      CONCAT('/quality/sheets/', NEW.sheet_id), 'QC,Production');
  END IF;
END $$

-- ----------------------------- tolerances and colour -------------------------------------
-- p_specs: [{"parameter":"PIN","target":5.0,"min":4.9,"max":5.1}, ...]; the list replaces the old one
DROP PROCEDURE IF EXISTS sp_item_spec_save $$
CREATE PROCEDURE sp_item_spec_save(IN p_actor CHAR(36), IN p_item_id CHAR(36), IN p_colour VARCHAR(40), IN p_specs JSON)
BEGIN
  DECLARE v_bad INT DEFAULT 0;
  DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;
  CALL sp_require_permission(p_actor, 'Items', 'update');
  IF NOT EXISTS (SELECT 1 FROM items WHERE id = p_item_id) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Item not found'; END IF;
  SELECT COUNT(*) INTO v_bad
    FROM JSON_TABLE(IFNULL(p_specs, JSON_ARRAY()), '$[*]' COLUMNS (
           parameter VARCHAR(10) PATH '$.parameter', mn DECIMAL(10,3) PATH '$.min' NULL ON EMPTY NULL ON ERROR,
           mx DECIMAL(10,3) PATH '$.max' NULL ON EMPTY NULL ON ERROR)) jt
   WHERE jt.parameter NOT IN ('PIN','WIDTH','LEG','WEIGHT') OR (jt.mn IS NOT NULL AND jt.mx IS NOT NULL AND jt.mn > jt.mx);
  IF v_bad > 0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Each tolerance needs a known size and a lowest value that is not above the highest'; END IF;
  START TRANSACTION;
    UPDATE items SET colour = NULLIF(TRIM(p_colour), '') WHERE id = p_item_id;
    DELETE FROM qc_specs WHERE item_id = p_item_id;
    INSERT INTO qc_specs (item_id, parameter, target, min_value, max_value)
    SELECT p_item_id, jt.parameter, MAX(jt.tg), MAX(jt.mn), MAX(jt.mx)
      FROM JSON_TABLE(IFNULL(p_specs, JSON_ARRAY()), '$[*]' COLUMNS (
             parameter VARCHAR(10) PATH '$.parameter', tg DECIMAL(10,3) PATH '$.target' NULL ON EMPTY NULL ON ERROR,
             mn DECIMAL(10,3) PATH '$.min' NULL ON EMPTY NULL ON ERROR,
             mx DECIMAL(10,3) PATH '$.max' NULL ON EMPTY NULL ON ERROR)) jt
     WHERE jt.tg IS NOT NULL OR jt.mn IS NOT NULL OR jt.mx IS NOT NULL
     GROUP BY jt.parameter;
  COMMIT;
END $$

DROP PROCEDURE IF EXISTS sp_qc_spec_list $$
CREATE PROCEDURE sp_qc_spec_list(IN p_actor CHAR(36), IN p_item_id CHAR(36))
BEGIN
  IF fn_can(p_actor, 'Items', 'read') = 0 AND fn_can(p_actor, 'QC', 'read') = 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Forbidden: you do not have permission for this action';
  END IF;
  SELECT parameter, target, min_value, max_value FROM qc_specs WHERE item_id = p_item_id
   ORDER BY FIELD(parameter, 'PIN', 'WIDTH', 'LEG', 'WEIGHT');
END $$

-- ----------------------------- sheets ----------------------------------------------------
-- Job cards a sheet can be started on: running or queued, or finished in the last 3 days
DROP PROCEDURE IF EXISTS sp_qc_job_card_pick $$
CREATE PROCEDURE sp_qc_job_card_pick(IN p_actor CHAR(36))
BEGIN
  CALL sp_require_permission(p_actor, 'QC', 'read');
  SELECT jc.id AS job_card_id, jc.status, wo.work_order_number, wo.fg_batch_number AS batch_number, wo.planned_qty,
         i.id AS item_id, i.code AS item_code, i.name AS item_name, i.colour, d.code AS die_code, ws.code AS workstation_code
    FROM job_cards jc
    JOIN work_orders wo ON wo.id = jc.work_order_id
    JOIN items i ON i.id = wo.fg_item_id
    JOIN workstations ws ON ws.id = jc.workstation_id
    LEFT JOIN dies d ON d.id = jc.die_id
   WHERE jc.status IN ('QUEUED', 'ACTIVE') OR (jc.status = 'COMPLETED' AND jc.completed_at >= NOW() - INTERVAL 3 DAY)
   ORDER BY FIELD(jc.status, 'ACTIVE', 'QUEUED', 'COMPLETED'), ws.code;
END $$

DROP PROCEDURE IF EXISTS sp_qc_sheet_open $$
CREATE PROCEDURE sp_qc_sheet_open(
  IN p_actor CHAR(36), IN p_job_card_id CHAR(36), IN p_dtd DATE, IN p_m_no VARCHAR(20), IN p_mfg_date DATE,
  IN p_metres DECIMAL(16,3), IN p_colour VARCHAR(40), OUT p_out CHAR(36))
BEGIN
  DECLARE v_item CHAR(36); DECLARE v_die CHAR(36); DECLARE v_batch VARCHAR(80); DECLARE v_planned DECIMAL(16,3);
  DECLARE v_icolour VARCHAR(40); DECLARE v_no VARCHAR(60); DECLARE v_found INT DEFAULT 0;
  DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;
  CALL sp_require_permission(p_actor, 'QC', 'create');
  SELECT 1, wo.fg_item_id, jc.die_id, wo.fg_batch_number, wo.planned_qty, i.colour
    INTO v_found, v_item, v_die, v_batch, v_planned, v_icolour
    FROM job_cards jc JOIN work_orders wo ON wo.id = jc.work_order_id JOIN items i ON i.id = wo.fg_item_id
   WHERE jc.id = p_job_card_id;
  IF v_found = 0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Choose the job card this sheet is for'; END IF;
  IF IFNULL(v_batch, '') = '' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'This job has no batch yet: log some metres on the shop floor first';
  END IF;
  IF p_mfg_date IS NOT NULL AND p_mfg_date > CURDATE() THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The manufacturing date cannot be in the future'; END IF;
  IF p_metres IS NOT NULL AND p_metres < 0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Metres cannot be negative'; END IF;
  START TRANSACTION;
    CALL sp_next_doc_no('QS', v_no);
    SET p_out = UUID();
    INSERT INTO qc_sheets (id, sheet_number, job_card_id, item_id, die_id, batch_number, colour, dtd, m_no, mfg_date, metres, created_by_id)
    VALUES (p_out, v_no, p_job_card_id, v_item, v_die, v_batch, COALESCE(NULLIF(TRIM(p_colour), ''), v_icolour), p_dtd, NULLIF(TRIM(p_m_no), ''),
            IFNULL(p_mfg_date, CURDATE()), IFNULL(p_metres, v_planned), p_actor);
    CALL sp_audit(p_actor, 'QC_SHEET_OPENED', NULL, JSON_OBJECT('sheetId', p_out, 'batch', v_batch));
  COMMIT;
END $$

DROP PROCEDURE IF EXISTS sp_qc_sheet_add_row $$
CREATE PROCEDURE sp_qc_sheet_add_row(
  IN p_actor CHAR(36), IN p_sheet_id CHAR(36), IN p_pin DECIMAL(10,3), IN p_width DECIMAL(10,3), IN p_leg DECIMAL(10,3),
  IN p_weight DECIMAL(10,3), IN p_measured_at DATETIME, IN p_fitting VARCHAR(10), OUT p_out CHAR(36))
BEGIN
  DECLARE v_status VARCHAR(10); DECLARE v_item CHAR(36); DECLARE v_sr INT; DECLARE v_name VARCHAR(190); DECLARE v_out VARCHAR(60);
  DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;
  CALL sp_require_permission(p_actor, 'QC', 'create');
  IF NULLIF(p_fitting, '') IS NOT NULL AND p_fitting NOT IN ('OK', 'NOT_OK') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Fitting is OK or Not OK'; END IF;
  IF p_pin IS NULL AND p_width IS NULL AND p_leg IS NULL AND p_weight IS NULL AND NULLIF(p_fitting, '') IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Enter at least one reading';
  END IF;
  IF IFNULL(p_pin, 0) < 0 OR IFNULL(p_width, 0) < 0 OR IFNULL(p_leg, 0) < 0 OR IFNULL(p_weight, 0) < 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Readings cannot be negative';
  END IF;
  IF p_measured_at IS NOT NULL AND p_measured_at > NOW() + INTERVAL 10 MINUTE THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The time cannot be in the future';
  END IF;
  SELECT name INTO v_name FROM users WHERE id = p_actor;
  START TRANSACTION;
    SELECT status, item_id INTO v_status, v_item FROM qc_sheets WHERE id = p_sheet_id FOR UPDATE;
    IF v_status IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Sheet not found'; END IF;
    IF v_status <> 'OPEN' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'This sheet is closed. Start a new sheet to keep recording'; END IF;
    SELECT COALESCE(MAX(sr_no), 0) + 1 INTO v_sr FROM qc_sheet_rows WHERE sheet_id = p_sheet_id;
    IF v_sr > 100 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A sheet holds up to 100 readings. Close it and start a new one'; END IF;
    SELECT GROUP_CONCAT(v.param ORDER BY FIELD(v.param, 'PIN', 'WIDTH', 'LEG', 'WEIGHT', 'FITTING') SEPARATOR ',') INTO v_out
      FROM (SELECT 'PIN' AS param, p_pin AS val UNION ALL SELECT 'WIDTH', p_width UNION ALL SELECT 'LEG', p_leg UNION ALL SELECT 'WEIGHT', p_weight) v
      JOIN qc_specs s ON s.item_id = v_item AND s.parameter = v.param
     WHERE v.val IS NOT NULL AND ((s.min_value IS NOT NULL AND v.val < s.min_value) OR (s.max_value IS NOT NULL AND v.val > s.max_value));
    IF p_fitting = 'NOT_OK' THEN SET v_out = CONCAT_WS(',', v_out, 'FITTING'); END IF;
    SET p_out = UUID();
    INSERT INTO qc_sheet_rows (id, sheet_id, sr_no, pin, width, leg, weight, measured_at, fitting, out_of_range, out_params, authorised_by_id, authorised_name)
    VALUES (p_out, p_sheet_id, v_sr, p_pin, p_width, p_leg, p_weight, IFNULL(p_measured_at, NOW()), NULLIF(p_fitting, ''),
            IF(v_out IS NULL, 0, 1), v_out, p_actor, IFNULL(v_name, 'Unknown'));
  COMMIT;
END $$

DROP PROCEDURE IF EXISTS sp_qc_sheet_void_row $$
CREATE PROCEDURE sp_qc_sheet_void_row(IN p_actor CHAR(36), IN p_row_id CHAR(36), IN p_reason VARCHAR(200))
BEGIN
  DECLARE v_sheet CHAR(36); DECLARE v_void TINYINT; DECLARE v_status VARCHAR(10);
  CALL sp_require_permission(p_actor, 'QC', 'update');
  IF NULLIF(TRIM(p_reason), '') IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Say why this reading is being voided'; END IF;
  SELECT r.sheet_id, r.is_void, s.status INTO v_sheet, v_void, v_status FROM qc_sheet_rows r JOIN qc_sheets s ON s.id = r.sheet_id WHERE r.id = p_row_id;
  IF v_sheet IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Reading not found'; END IF;
  IF v_void = 1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'This reading is already void'; END IF;
  IF v_status <> 'OPEN' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'This sheet is closed, so its readings cannot be voided'; END IF;
  UPDATE qc_sheet_rows SET is_void = 1, void_reason = LEFT(TRIM(p_reason), 200), voided_by_id = p_actor, voided_at = NOW(3) WHERE id = p_row_id;
  CALL sp_audit(p_actor, 'QC_READING_VOIDED', NULL, JSON_OBJECT('rowId', p_row_id, 'sheetId', v_sheet));
END $$

DROP PROCEDURE IF EXISTS sp_qc_sheet_close $$
CREATE PROCEDURE sp_qc_sheet_close(IN p_actor CHAR(36), IN p_sheet_id CHAR(36))
BEGIN
  DECLARE v_status VARCHAR(10); DECLARE v_rows INT;
  CALL sp_require_permission(p_actor, 'QC', 'update');
  SELECT status INTO v_status FROM qc_sheets WHERE id = p_sheet_id;
  IF v_status IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Sheet not found'; END IF;
  IF v_status <> 'OPEN' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'This sheet is already closed'; END IF;
  SELECT COUNT(*) INTO v_rows FROM qc_sheet_rows WHERE sheet_id = p_sheet_id AND is_void = 0;
  IF v_rows = 0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A sheet with no readings cannot be closed'; END IF;
  UPDATE qc_sheets SET status = 'CLOSED', closed_by_id = p_actor, closed_at = NOW(3) WHERE id = p_sheet_id;
  CALL sp_audit(p_actor, 'QC_SHEET_CLOSED', NULL, JSON_OBJECT('sheetId', p_sheet_id));
END $$

DROP PROCEDURE IF EXISTS sp_qc_sheet_list $$
CREATE PROCEDURE sp_qc_sheet_list(IN p_actor CHAR(36), IN p_status VARCHAR(10))
BEGIN
  CALL sp_require_permission(p_actor, 'QC', 'read');
  SELECT s.id, s.sheet_number, s.batch_number, s.status, s.mfg_date, s.created_at, i.code AS item_code, i.name AS item_name,
         d.code AS die_code, u.name AS created_by_name,
         (SELECT COUNT(*) FROM qc_sheet_rows r WHERE r.sheet_id = s.id AND r.is_void = 0) AS readings,
         (SELECT COUNT(*) FROM qc_sheet_rows r WHERE r.sheet_id = s.id AND r.is_void = 0 AND r.out_of_range = 1) AS out_of_range
    FROM qc_sheets s JOIN items i ON i.id = s.item_id LEFT JOIN dies d ON d.id = s.die_id LEFT JOIN users u ON u.id = s.created_by_id
   WHERE NULLIF(p_status, '') IS NULL OR s.status = p_status
   ORDER BY s.created_at DESC LIMIT 300;
END $$

DROP PROCEDURE IF EXISTS sp_qc_sheet_get $$
CREATE PROCEDURE sp_qc_sheet_get(IN p_actor CHAR(36), IN p_id CHAR(36))
BEGIN
  CALL sp_require_permission(p_actor, 'QC', 'read');
  SELECT s.*, i.code AS item_code, i.name AS item_name, d.code AS die_code, cu.name AS created_by_name, xu.name AS closed_by_name
    FROM qc_sheets s JOIN items i ON i.id = s.item_id LEFT JOIN dies d ON d.id = s.die_id
    LEFT JOIN users cu ON cu.id = s.created_by_id LEFT JOIN users xu ON xu.id = s.closed_by_id
   WHERE s.id = p_id;
  SELECT r.id, r.sr_no, r.pin, r.width, r.leg, r.weight, r.measured_at, r.fitting, r.out_of_range, r.out_params,
         r.authorised_name, r.is_void, r.void_reason
    FROM qc_sheet_rows r WHERE r.sheet_id = p_id ORDER BY r.sr_no;
  SELECT sp.parameter, sp.target, sp.min_value, sp.max_value
    FROM qc_specs sp JOIN qc_sheets s ON s.item_id = sp.item_id WHERE s.id = p_id
   ORDER BY FIELD(sp.parameter, 'PIN', 'WIDTH', 'LEG', 'WEIGHT');
END $$

DELIMITER ;
