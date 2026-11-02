-- =============================================================================
-- HPOS Factory OS  |  19_qc_custom_fields.sql  -  the QC sheet the client can shape. What is measured (pin size, width,
-- fitting, or anything else), its name, unit and limits, and the labels on the sheet header are data, not code. Readings
-- are stored per field. Sheets also know their machine, so there is one sheet per machine, and a machine that is due a
-- reading is flagged. Existing readings are copied across (safe to run again). Supersedes the sheet procedures of file 12;
-- load 19 after 12. Reading needs QC:read, changing the setup needs QC:update. Needs 01-18.
-- =============================================================================
USE hpos;

INSERT IGNORE INTO system_settings (setting_key, setting_value, description) VALUES
  ('qc_reading_minutes', '30', 'Minutes between QC readings on a running machine; HPOS flags a machine after one and a half times this');

-- what is measured on the sheet, in order
CREATE TABLE IF NOT EXISTS qc_fields (
  id          CHAR(36)     NOT NULL DEFAULT (UUID()),
  code        VARCHAR(30)  NOT NULL,                 -- PIN, WIDTH, ... fixed once created (limits and readings point to it)
  label       VARCHAR(60)  NOT NULL,
  kind        VARCHAR(10)  NOT NULL DEFAULT 'NUMBER', -- NUMBER, CHOICE, TEXT
  unit        VARCHAR(12)  NULL,
  choices     VARCHAR(255) NULL,                     -- CHOICE only: "OK=OK;NOT_OK=Not OK" (stored value = shown name)
  bad_values  VARCHAR(255) NULL,                     -- CHOICE only: stored values that count as a fault, e.g. NOT_OK
  has_limits  TINYINT(1)   NOT NULL DEFAULT 0,       -- NUMBER only: a lowest and highest allowed value can be set per product
  sort_no     INT          NOT NULL DEFAULT 0,
  is_active   TINYINT(1)   NOT NULL DEFAULT 1,
  created_at  DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_qf_code (code),
  CONSTRAINT ck_qf_kind CHECK (kind IN ('NUMBER', 'CHOICE', 'TEXT'))
) ENGINE=InnoDB;

-- the words on the sheet header and column heads; the client renames them and can hide what they do not use
CREATE TABLE IF NOT EXISTS qc_labels (
  label_key  VARCHAR(20) NOT NULL,
  label      VARCHAR(60) NOT NULL,
  is_visible TINYINT(1)  NOT NULL DEFAULT 1,
  sort_no    INT         NOT NULL DEFAULT 0,
  PRIMARY KEY (label_key)
) ENGINE=InnoDB;

INSERT IGNORE INTO qc_labels (label_key, label, is_visible, sort_no) VALUES
  ('DIE_NO', 'Die no.', 1, 1), ('MFG_DATE', 'Manf. date', 1, 2), ('BATCH_NO', 'Batch no.', 1, 3), ('DTD', 'DTD', 1, 4),
  ('M_NO', 'M -', 1, 5), ('PRODUCT_CODE', 'Product code', 1, 6), ('COLOUR', 'Colour', 1, 7), ('METRES', 'Metres', 1, 8),
  ('X1', 'Extra 1', 0, 9), ('X2', 'Extra 2', 0, 10), ('X3', 'Extra 3', 0, 11),
  ('SR_NO', 'Sr no.', 1, 20), ('DATE_TIME', 'Date/time', 1, 21), ('AUTHORISED', 'Authorised', 1, 22), ('FOOTER', 'Label approved by :', 1, 23);

INSERT IGNORE INTO qc_fields (id, code, label, kind, unit, choices, bad_values, has_limits, sort_no) VALUES
  (UUID(), 'PIN',     'Pin size',     'NUMBER', NULL, NULL, NULL, 1, 1),
  (UUID(), 'WIDTH',   'Width size',   'NUMBER', NULL, NULL, NULL, 1, 2),
  (UUID(), 'LEG',     'Leg (L) size', 'NUMBER', NULL, NULL, NULL, 1, 3),
  (UUID(), 'WEIGHT',  'Weight',       'NUMBER', NULL, NULL, NULL, 1, 4),
  (UUID(), 'FITTING', 'Fitting',      'CHOICE', NULL, 'OK=OK;NOT_OK=Not OK', 'NOT_OK', 0, 5);

-- readings: one row per field per reading (the row itself, with its number, time, signature and void, is qc_sheet_rows)
CREATE TABLE IF NOT EXISTS qc_sheet_values (
  row_id     CHAR(36)       NOT NULL,
  field_id   CHAR(36)       NOT NULL,
  num_value  DECIMAL(14,4)  NULL,
  text_value VARCHAR(100)   NULL,
  PRIMARY KEY (row_id, field_id),
  CONSTRAINT fk_qsv_row   FOREIGN KEY (row_id)   REFERENCES qc_sheet_rows(id),
  CONSTRAINT fk_qsv_field FOREIGN KEY (field_id) REFERENCES qc_fields(id)
) ENGINE=InnoDB;

DELIMITER $$

DROP TRIGGER IF EXISTS trg_qsv_no_update $$
CREATE TRIGGER trg_qsv_no_update BEFORE UPDATE ON qc_sheet_values FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'QC readings cannot be changed. Void the reading and enter it again';
END $$
DROP TRIGGER IF EXISTS trg_qsv_no_delete $$
CREATE TRIGGER trg_qsv_no_delete BEFORE DELETE ON qc_sheet_values FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'QC readings cannot be deleted. Void the reading instead';
END $$

-- The schema changes that make the fixed columns optional. Each guarded so the file can be run again.
DROP PROCEDURE IF EXISTS sp_qc_custom_migrate $$
CREATE PROCEDURE sp_qc_custom_migrate()
BEGIN
  IF EXISTS (SELECT 1 FROM information_schema.check_constraints WHERE constraint_schema = DATABASE() AND constraint_name = 'ck_qsr_any') THEN
    ALTER TABLE qc_sheet_rows DROP CHECK ck_qsr_any;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.check_constraints WHERE constraint_schema = DATABASE() AND constraint_name = 'ck_qs_param') THEN
    ALTER TABLE qc_specs DROP CHECK ck_qs_param;
  END IF;
  IF (SELECT character_maximum_length FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'qc_specs' AND column_name = 'parameter') < 30 THEN
    ALTER TABLE qc_specs MODIFY parameter VARCHAR(30) NOT NULL;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'qc_sheets' AND column_name = 'workstation_id') THEN
    ALTER TABLE qc_sheets ADD COLUMN workstation_id CHAR(36) NULL AFTER die_id, ADD COLUMN x1 VARCHAR(80) NULL, ADD COLUMN x2 VARCHAR(80) NULL, ADD COLUMN x3 VARCHAR(80) NULL,
      ADD CONSTRAINT fk_qsh_ws FOREIGN KEY (workstation_id) REFERENCES workstations(id) ON DELETE SET NULL;
    UPDATE qc_sheets s JOIN job_cards jc ON jc.id = s.job_card_id SET s.workstation_id = jc.workstation_id;
  END IF;
END $$
CALL sp_qc_custom_migrate() $$
DROP PROCEDURE IF EXISTS sp_qc_custom_migrate $$

-- Copy readings made before this file (the old fixed columns) into the per-field table. INSERT IGNORE: run again, nothing doubles.
INSERT IGNORE INTO qc_sheet_values (row_id, field_id, num_value)
SELECT r.id, f.id, CASE f.code WHEN 'PIN' THEN r.pin WHEN 'WIDTH' THEN r.width WHEN 'LEG' THEN r.leg ELSE r.weight END
  FROM qc_sheet_rows r JOIN qc_fields f ON f.code IN ('PIN', 'WIDTH', 'LEG', 'WEIGHT')
 WHERE CASE f.code WHEN 'PIN' THEN r.pin WHEN 'WIDTH' THEN r.width WHEN 'LEG' THEN r.leg ELSE r.weight END IS NOT NULL;
INSERT IGNORE INTO qc_sheet_values (row_id, field_id, text_value)
SELECT r.id, f.id, r.fitting FROM qc_sheet_rows r JOIN qc_fields f ON f.code = 'FITTING' WHERE r.fitting IS NOT NULL;

-- ----------------------------- setup ---------------------------------------------------
DROP PROCEDURE IF EXISTS sp_qc_field_list $$
CREATE PROCEDURE sp_qc_field_list(IN p_actor CHAR(36))
BEGIN
  IF fn_can(p_actor, 'QC', 'read') = 0 AND fn_can(p_actor, 'Items', 'read') = 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Forbidden: you do not have permission for this action';
  END IF;
  SELECT f.*, (SELECT COUNT(*) FROM qc_sheet_values v WHERE v.field_id = f.id) AS used_count FROM qc_fields f ORDER BY f.is_active DESC, f.sort_no, f.label;
END $$

DROP PROCEDURE IF EXISTS sp_qc_labels_list $$
CREATE PROCEDURE sp_qc_labels_list(IN p_actor CHAR(36))
BEGIN
  CALL sp_require_permission(p_actor, 'QC', 'read');
  SELECT label_key, label, is_visible FROM qc_labels ORDER BY sort_no;
END $$

-- Create (p_id empty) or change a measured field. The code and the kind cannot change once readings exist.
DROP PROCEDURE IF EXISTS sp_qc_field_save $$
CREATE PROCEDURE sp_qc_field_save(
  IN p_actor CHAR(36), IN p_id CHAR(36), IN p_code VARCHAR(30), IN p_label VARCHAR(60), IN p_kind VARCHAR(10), IN p_unit VARCHAR(12),
  IN p_choices VARCHAR(255), IN p_bad VARCHAR(255), IN p_has_limits TINYINT, IN p_sort INT, IN p_active TINYINT, OUT p_out CHAR(36))
BEGIN
  DECLARE v_id CHAR(36) DEFAULT NULLIF(p_id, ''); DECLARE v_kind VARCHAR(10); DECLARE v_used INT DEFAULT 0; DECLARE v_active INT DEFAULT 0;
  CALL sp_require_permission(p_actor, 'QC', 'update');
  SET p_label = TRIM(IFNULL(p_label, ''));
  IF p_label = '' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The field needs a name'; END IF;
  IF p_kind NOT IN ('NUMBER', 'CHOICE', 'TEXT') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Choose a number, a choice or text'; END IF;
  IF p_kind = 'CHOICE' AND (IFNULL(p_choices, '') NOT REGEXP '^[^=;]+=[^=;]+(;[^=;]+=[^=;]+)*$') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'List the choices as VALUE=Name, separated by semicolons, for example OK=OK;NOT_OK=Not OK';
  END IF;
  SELECT COUNT(*) INTO v_active FROM qc_fields WHERE is_active = 1 AND (v_id IS NULL OR id <> v_id);
  IF IFNULL(p_active, 1) = 1 AND v_active >= 12 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A sheet can have up to 12 fields. Switch one off first'; END IF;
  IF v_id IS NULL THEN
    SET p_code = UPPER(TRIM(IFNULL(p_code, '')));
    IF p_code NOT REGEXP '^[A-Z][A-Z0-9_]{1,29}$' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The code is 2 to 30 capital letters, digits or underscores, starting with a letter'; END IF;
    IF EXISTS (SELECT 1 FROM qc_fields WHERE code = p_code) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'That code is already used by another field'; END IF;
    SET p_out = UUID();
    INSERT INTO qc_fields (id, code, label, kind, unit, choices, bad_values, has_limits, sort_no, is_active)
    VALUES (p_out, p_code, p_label, p_kind, NULLIF(TRIM(p_unit), ''), IF(p_kind = 'CHOICE', p_choices, NULL), IF(p_kind = 'CHOICE', NULLIF(p_bad, ''), NULL),
            IF(p_kind = 'NUMBER', IFNULL(p_has_limits, 0), 0), IFNULL(p_sort, 0), IFNULL(p_active, 1));
  ELSE
    SELECT kind INTO v_kind FROM qc_fields WHERE id = v_id;
    IF v_kind IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Field not found'; END IF;
    SELECT COUNT(*) INTO v_used FROM qc_sheet_values WHERE field_id = v_id;
    IF v_used > 0 AND p_kind <> v_kind THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Readings already exist for this field, so its kind cannot change'; END IF;
    UPDATE qc_fields SET label = p_label, kind = p_kind, unit = NULLIF(TRIM(p_unit), ''), choices = IF(p_kind = 'CHOICE', p_choices, NULL),
           bad_values = IF(p_kind = 'CHOICE', NULLIF(p_bad, ''), NULL), has_limits = IF(p_kind = 'NUMBER', IFNULL(p_has_limits, 0), 0),
           sort_no = IFNULL(p_sort, sort_no), is_active = IFNULL(p_active, is_active)
     WHERE id = v_id;
    SET p_out = v_id;
  END IF;
  CALL sp_audit(p_actor, 'QC_FIELD_SAVED', NULL, JSON_OBJECT('fieldId', p_out));
END $$

DROP PROCEDURE IF EXISTS sp_qc_label_save $$
CREATE PROCEDURE sp_qc_label_save(IN p_actor CHAR(36), IN p_key VARCHAR(20), IN p_label VARCHAR(60), IN p_visible TINYINT)
BEGIN
  CALL sp_require_permission(p_actor, 'QC', 'update');
  IF NOT EXISTS (SELECT 1 FROM qc_labels WHERE label_key = p_key) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Unknown label'; END IF;
  IF IFNULL(TRIM(p_label), '') = '' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A label cannot be empty'; END IF;
  UPDATE qc_labels SET label = TRIM(p_label), is_visible = IF(p_key IN ('SR_NO', 'DATE_TIME', 'AUTHORISED', 'FOOTER'), 1, IFNULL(p_visible, is_visible)) WHERE label_key = p_key;
  CALL sp_audit(p_actor, 'QC_LABEL_SAVED', NULL, JSON_OBJECT('key', p_key));
END $$

-- ----------------------------- limits per product (replaces file 12's) -----------------------------
-- p_specs: [{"parameter":"PIN","target":5.0,"min":4.9,"max":5.1}, ...]; the parameter is a field code with limits switched on
DROP PROCEDURE IF EXISTS sp_item_spec_save $$
CREATE PROCEDURE sp_item_spec_save(IN p_actor CHAR(36), IN p_item_id CHAR(36), IN p_colour VARCHAR(40), IN p_specs JSON)
BEGIN
  DECLARE v_bad INT DEFAULT 0;
  DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;
  CALL sp_require_permission(p_actor, 'Items', 'update');
  IF NOT EXISTS (SELECT 1 FROM items WHERE id = p_item_id) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Item not found'; END IF;
  SELECT COUNT(*) INTO v_bad
    FROM JSON_TABLE(IFNULL(p_specs, JSON_ARRAY()), '$[*]' COLUMNS (
           parameter VARCHAR(30) PATH '$.parameter', mn DECIMAL(10,3) PATH '$.min' NULL ON EMPTY NULL ON ERROR,
           mx DECIMAL(10,3) PATH '$.max' NULL ON EMPTY NULL ON ERROR)) jt
   WHERE NOT EXISTS (SELECT 1 FROM qc_fields f WHERE f.code = (jt.parameter COLLATE utf8mb4_unicode_ci) AND f.kind = 'NUMBER' AND f.has_limits = 1)
      OR (jt.mn IS NOT NULL AND jt.mx IS NOT NULL AND jt.mn > jt.mx);
  IF v_bad > 0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Each tolerance needs a known size and a lowest value that is not above the highest'; END IF;
  START TRANSACTION;
    UPDATE items SET colour = NULLIF(TRIM(p_colour), '') WHERE id = p_item_id;
    DELETE FROM qc_specs WHERE item_id = p_item_id;
    INSERT INTO qc_specs (item_id, parameter, target, min_value, max_value)
    SELECT p_item_id, jt.parameter, MAX(jt.tg), MAX(jt.mn), MAX(jt.mx)
      FROM JSON_TABLE(IFNULL(p_specs, JSON_ARRAY()), '$[*]' COLUMNS (
             parameter VARCHAR(30) PATH '$.parameter', tg DECIMAL(10,3) PATH '$.target' NULL ON EMPTY NULL ON ERROR,
             mn DECIMAL(10,3) PATH '$.min' NULL ON EMPTY NULL ON ERROR, mx DECIMAL(10,3) PATH '$.max' NULL ON EMPTY NULL ON ERROR)) jt
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
  SELECT s.parameter, s.target, s.min_value, s.max_value FROM qc_specs s LEFT JOIN qc_fields f ON f.code = s.parameter
   WHERE s.item_id = p_item_id ORDER BY f.sort_no, s.parameter;
END $$

-- ----------------------------- sheets (replace file 12's) ----------------------------------------
DROP PROCEDURE IF EXISTS sp_qc_sheet_open $$
CREATE PROCEDURE sp_qc_sheet_open(
  IN p_actor CHAR(36), IN p_job_card_id CHAR(36), IN p_dtd DATE, IN p_m_no VARCHAR(20), IN p_mfg_date DATE,
  IN p_metres DECIMAL(16,3), IN p_colour VARCHAR(40), IN p_x1 VARCHAR(80), IN p_x2 VARCHAR(80), IN p_x3 VARCHAR(80), OUT p_out CHAR(36))
BEGIN
  DECLARE v_item CHAR(36); DECLARE v_die CHAR(36); DECLARE v_ws CHAR(36); DECLARE v_wscode VARCHAR(40); DECLARE v_batch VARCHAR(80); DECLARE v_planned DECIMAL(16,3);
  DECLARE v_icolour VARCHAR(40); DECLARE v_no VARCHAR(60); DECLARE v_found INT DEFAULT 0;
  DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;
  CALL sp_require_permission(p_actor, 'QC', 'create');
  SELECT 1, wo.fg_item_id, jc.die_id, jc.workstation_id, ws.code, wo.fg_batch_number, wo.planned_qty, i.colour
    INTO v_found, v_item, v_die, v_ws, v_wscode, v_batch, v_planned, v_icolour
    FROM job_cards jc JOIN work_orders wo ON wo.id = jc.work_order_id JOIN items i ON i.id = wo.fg_item_id JOIN workstations ws ON ws.id = jc.workstation_id
   WHERE jc.id = p_job_card_id;
  IF v_found = 0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Choose the job card this sheet is for'; END IF;
  IF IFNULL(v_batch, '') = '' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'This job has no batch yet: log some metres on the shop floor first'; END IF;
  IF p_mfg_date IS NOT NULL AND p_mfg_date > CURDATE() THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The manufacturing date cannot be in the future'; END IF;
  IF p_metres IS NOT NULL AND p_metres < 0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Metres cannot be negative'; END IF;
  START TRANSACTION;
    CALL sp_next_doc_no('QS', v_no);
    SET p_out = UUID();
    INSERT INTO qc_sheets (id, sheet_number, job_card_id, item_id, die_id, workstation_id, batch_number, colour, dtd, m_no, mfg_date, metres, x1, x2, x3, created_by_id)
    VALUES (p_out, v_no, p_job_card_id, v_item, v_die, v_ws, v_batch, COALESCE(NULLIF(TRIM(p_colour), ''), v_icolour), p_dtd, COALESCE(NULLIF(TRIM(p_m_no), ''), v_wscode),
            IFNULL(p_mfg_date, CURDATE()), IFNULL(p_metres, v_planned), NULLIF(TRIM(p_x1), ''), NULLIF(TRIM(p_x2), ''), NULLIF(TRIM(p_x3), ''), p_actor);
    CALL sp_audit(p_actor, 'QC_SHEET_OPENED', NULL, JSON_OBJECT('sheetId', p_out, 'batch', v_batch));
  COMMIT;
END $$

-- p_values: [{"code":"PIN","value":"5.02"}, {"code":"FITTING","value":"OK"}, ...]; empty values are ignored
DROP PROCEDURE IF EXISTS sp_qc_sheet_add_row $$
CREATE PROCEDURE sp_qc_sheet_add_row(IN p_actor CHAR(36), IN p_sheet_id CHAR(36), IN p_measured_at DATETIME, IN p_values JSON, OUT p_out CHAR(36))
BEGIN
  DECLARE v_status VARCHAR(10); DECLARE v_item CHAR(36); DECLARE v_sr INT; DECLARE v_name VARCHAR(190); DECLARE v_out VARCHAR(60); DECLARE v_n INT DEFAULT 0; DECLARE v_bad VARCHAR(120);
  DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;
  CALL sp_require_permission(p_actor, 'QC', 'create');
  DROP TEMPORARY TABLE IF EXISTS tmp_qc_vals;
  CREATE TEMPORARY TABLE tmp_qc_vals (field_id CHAR(36) NOT NULL, code VARCHAR(30) NOT NULL, label VARCHAR(60) NOT NULL, kind VARCHAR(10) NOT NULL, choices VARCHAR(255) NULL,
    bad_values VARCHAR(255) NULL, raw_value VARCHAR(200) NOT NULL, num_value DECIMAL(14,4) NULL, is_bad TINYINT NOT NULL DEFAULT 0, PRIMARY KEY (field_id)) ENGINE=MEMORY;
  INSERT INTO tmp_qc_vals (field_id, code, label, kind, choices, bad_values, raw_value)
  SELECT f.id, f.code, f.label, f.kind, f.choices, f.bad_values, TRIM(jt.val)
    FROM JSON_TABLE(IFNULL(p_values, JSON_ARRAY()), '$[*]' COLUMNS (code VARCHAR(30) PATH '$.code', val VARCHAR(200) PATH '$.value')) jt
    JOIN qc_fields f ON f.code = (jt.code COLLATE utf8mb4_unicode_ci) AND f.is_active = 1
   WHERE TRIM(IFNULL(jt.val, '')) <> ''
   ON DUPLICATE KEY UPDATE raw_value = VALUES(raw_value);
  SELECT COUNT(*) INTO v_n FROM tmp_qc_vals;
  IF v_n = 0 THEN DROP TEMPORARY TABLE tmp_qc_vals; SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Enter at least one reading'; END IF;
  -- numbers must be numbers, zero or more; choices must be one of the listed values
  UPDATE tmp_qc_vals SET num_value = CAST(raw_value AS DECIMAL(14,4)) WHERE kind = 'NUMBER' AND raw_value REGEXP '^[0-9]+(\\.[0-9]+)?$';
  SELECT label INTO v_bad FROM tmp_qc_vals WHERE kind = 'NUMBER' AND num_value IS NULL LIMIT 1;
  IF v_bad IS NOT NULL THEN DROP TEMPORARY TABLE tmp_qc_vals; SET v_bad = CONCAT(LEFT(v_bad, 60), ' must be a number, zero or more'); SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = v_bad; END IF;
  SELECT label INTO v_bad FROM tmp_qc_vals WHERE kind = 'CHOICE' AND CONCAT(';', choices) NOT LIKE CONCAT('%;', raw_value, '=%') LIMIT 1;
  IF v_bad IS NOT NULL THEN DROP TEMPORARY TABLE tmp_qc_vals; SET v_bad = CONCAT(LEFT(v_bad, 60), ' must be one of the listed choices'); SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = v_bad; END IF;
  IF p_measured_at IS NOT NULL AND p_measured_at > NOW() + INTERVAL 10 MINUTE THEN DROP TEMPORARY TABLE tmp_qc_vals; SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The time cannot be in the future'; END IF;
  SELECT name INTO v_name FROM users WHERE id = p_actor;
  START TRANSACTION;
    SELECT status, item_id INTO v_status, v_item FROM qc_sheets WHERE id = p_sheet_id FOR UPDATE;
    IF v_status IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Sheet not found'; END IF;
    IF v_status <> 'OPEN' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'This sheet is closed. Start a new sheet to keep recording'; END IF;
    SELECT COALESCE(MAX(sr_no), 0) + 1 INTO v_sr FROM qc_sheet_rows WHERE sheet_id = p_sheet_id;
    IF v_sr > 100 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A sheet holds up to 100 readings. Close it and start a new one'; END IF;
    -- outside the product's limits, or a choice that counts as a fault
    UPDATE tmp_qc_vals v JOIN qc_fields f ON f.id = v.field_id JOIN qc_specs s ON s.item_id = v_item AND s.parameter = v.code SET v.is_bad = 1
     WHERE v.kind = 'NUMBER' AND f.has_limits = 1 AND ((s.min_value IS NOT NULL AND v.num_value < s.min_value) OR (s.max_value IS NOT NULL AND v.num_value > s.max_value));
    UPDATE tmp_qc_vals v SET v.is_bad = 1
     WHERE v.kind = 'CHOICE' AND v.bad_values IS NOT NULL AND CONCAT(',', REPLACE(v.bad_values, ';', ','), ',') LIKE CONCAT('%,', v.raw_value, ',%');
    SELECT GROUP_CONCAT(v.code ORDER BY f.sort_no SEPARATOR ',') INTO v_out FROM tmp_qc_vals v JOIN qc_fields f ON f.id = v.field_id WHERE v.is_bad = 1;
    SET p_out = UUID();
    INSERT INTO qc_sheet_rows (id, sheet_id, sr_no, measured_at, out_of_range, out_params, authorised_by_id, authorised_name)
    VALUES (p_out, p_sheet_id, v_sr, IFNULL(p_measured_at, NOW()), IF(v_out IS NULL, 0, 1), v_out, p_actor, IFNULL(v_name, 'Unknown'));
    INSERT INTO qc_sheet_values (row_id, field_id, num_value, text_value)
    SELECT p_out, field_id, num_value, IF(kind = 'NUMBER', NULL, LEFT(raw_value, 100)) FROM tmp_qc_vals;
  COMMIT;
  DROP TEMPORARY TABLE tmp_qc_vals;
END $$

DROP PROCEDURE IF EXISTS sp_qc_sheet_list $$
CREATE PROCEDURE sp_qc_sheet_list(IN p_actor CHAR(36), IN p_status VARCHAR(10), IN p_workstation_id CHAR(36))
BEGIN
  CALL sp_require_permission(p_actor, 'QC', 'read');
  SELECT s.id, s.sheet_number, s.batch_number, s.status, s.mfg_date, s.created_at, i.code AS item_code, i.name AS item_name,
         d.code AS die_code, ws.code AS machine_code, u.name AS created_by_name,
         (SELECT COUNT(*) FROM qc_sheet_rows r WHERE r.sheet_id = s.id AND r.is_void = 0) AS readings,
         (SELECT COUNT(*) FROM qc_sheet_rows r WHERE r.sheet_id = s.id AND r.is_void = 0 AND r.out_of_range = 1) AS out_of_range
    FROM qc_sheets s JOIN items i ON i.id = s.item_id LEFT JOIN dies d ON d.id = s.die_id LEFT JOIN users u ON u.id = s.created_by_id LEFT JOIN workstations ws ON ws.id = s.workstation_id
   WHERE (NULLIF(p_status, '') IS NULL OR s.status = p_status) AND (NULLIF(p_workstation_id, '') IS NULL OR s.workstation_id = p_workstation_id)
   ORDER BY s.created_at DESC LIMIT 300;
END $$

-- 1 header, 2 rows, 3 limits, 4 values (row, field code), 5 fields shown: the active ones and any that this sheet has readings for
DROP PROCEDURE IF EXISTS sp_qc_sheet_get $$
CREATE PROCEDURE sp_qc_sheet_get(IN p_actor CHAR(36), IN p_id CHAR(36))
BEGIN
  CALL sp_require_permission(p_actor, 'QC', 'read');
  SELECT s.*, i.code AS item_code, i.name AS item_name, d.code AS die_code, ws.code AS machine_code, ws.name AS machine_name,
         cu.name AS created_by_name, xu.name AS closed_by_name
    FROM qc_sheets s JOIN items i ON i.id = s.item_id LEFT JOIN dies d ON d.id = s.die_id LEFT JOIN workstations ws ON ws.id = s.workstation_id
    LEFT JOIN users cu ON cu.id = s.created_by_id LEFT JOIN users xu ON xu.id = s.closed_by_id
   WHERE s.id = p_id;
  SELECT r.id, r.sr_no, r.measured_at, r.out_of_range, r.out_params, r.authorised_name, r.is_void, r.void_reason
    FROM qc_sheet_rows r WHERE r.sheet_id = p_id ORDER BY r.sr_no;
  SELECT sp.parameter, sp.target, sp.min_value, sp.max_value FROM qc_specs sp JOIN qc_sheets s ON s.item_id = sp.item_id WHERE s.id = p_id;
  SELECT v.row_id, f.code AS field_code, v.num_value, v.text_value
    FROM qc_sheet_values v JOIN qc_fields f ON f.id = v.field_id JOIN qc_sheet_rows r ON r.id = v.row_id WHERE r.sheet_id = p_id;
  SELECT f.id, f.code, f.label, f.kind, f.unit, f.choices, f.has_limits, f.is_active
    FROM qc_fields f
   WHERE f.is_active = 1 OR EXISTS (SELECT 1 FROM qc_sheet_values v JOIN qc_sheet_rows r ON r.id = v.row_id WHERE v.field_id = f.id AND r.sheet_id = p_id)
   ORDER BY f.sort_no, f.label;
END $$

-- The machines: each one's running job, its open QC sheet, and when the next reading is due
DROP PROCEDURE IF EXISTS sp_qc_machine_board $$
CREATE PROCEDURE sp_qc_machine_board(IN p_actor CHAR(36))
BEGIN
  CALL sp_require_permission(p_actor, 'QC', 'read');
  SELECT b.*,
         CASE WHEN b.job_card_id IS NOT NULL AND b.job_started < NOW() - INTERVAL CAST(fn_setting_num('qc_reading_minutes', 30) * 1.5 AS SIGNED) MINUTE
                   AND GREATEST(IFNULL(b.last_reading, '1970-01-01'), IFNULL(b.sheet_started, '1970-01-01')) < NOW() - INTERVAL CAST(fn_setting_num('qc_reading_minutes', 30) * 1.5 AS SIGNED) MINUTE
              THEN 1 ELSE 0 END AS due
    FROM (
  SELECT w.id AS workstation_id, w.code, w.name, w.status,
         (SELECT jc.started_at FROM job_cards jc WHERE jc.workstation_id = w.id AND jc.status = 'ACTIVE' ORDER BY jc.started_at DESC LIMIT 1) AS job_started,
         (SELECT jc.id FROM job_cards jc WHERE jc.workstation_id = w.id AND jc.status = 'ACTIVE' ORDER BY jc.started_at DESC LIMIT 1) AS job_card_id,
         (SELECT wo.fg_batch_number FROM job_cards jc JOIN work_orders wo ON wo.id = jc.work_order_id WHERE jc.workstation_id = w.id AND jc.status = 'ACTIVE' ORDER BY jc.started_at DESC LIMIT 1) AS batch_number,
         (SELECT s.id FROM qc_sheets s WHERE s.workstation_id = w.id AND s.status = 'OPEN' ORDER BY s.created_at DESC LIMIT 1) AS sheet_id,
         (SELECT s.sheet_number FROM qc_sheets s WHERE s.workstation_id = w.id AND s.status = 'OPEN' ORDER BY s.created_at DESC LIMIT 1) AS sheet_number,
         (SELECT COUNT(*) FROM qc_sheets s JOIN qc_sheet_rows r ON r.sheet_id = s.id AND r.is_void = 0 WHERE s.workstation_id = w.id AND s.status = 'OPEN') AS readings,
         (SELECT MAX(r.measured_at) FROM qc_sheets s JOIN qc_sheet_rows r ON r.sheet_id = s.id AND r.is_void = 0 WHERE s.workstation_id = w.id AND s.status = 'OPEN') AS last_reading,
         (SELECT s.created_at FROM qc_sheets s WHERE s.workstation_id = w.id AND s.status = 'OPEN' ORDER BY s.created_at DESC LIMIT 1) AS sheet_started
    FROM workstations w
    ) b ORDER BY b.code;
END $$

-- A running machine that is due a QC reading: no open sheet, or the last reading is older than 1.5 x the interval
DROP PROCEDURE IF EXISTS sp_alerts_qc $$
CREATE PROCEDURE sp_alerts_qc()
BEGIN
  DECLARE v_n INT DEFAULT 0;
  DECLARE v_limit INT DEFAULT CAST(fn_setting_num('qc_reading_minutes', 30) * 1.5 AS SIGNED);
  SELECT COUNT(*) INTO v_n FROM workstations w
   WHERE EXISTS (SELECT 1 FROM job_cards jc WHERE jc.workstation_id = w.id AND jc.status = 'ACTIVE' AND jc.started_at < NOW() - INTERVAL v_limit MINUTE)
     AND COALESCE((SELECT MAX(GREATEST(COALESCE(r.measured_at, s.created_at), s.created_at)) FROM qc_sheets s LEFT JOIN qc_sheet_rows r ON r.sheet_id = s.id AND r.is_void = 0
                    WHERE s.workstation_id = w.id AND s.status = 'OPEN'), '1970-01-01') < NOW() - INTERVAL v_limit MINUTE;
  CALL sp_notify_count('QC', 'readings-due', v_n, 'running machines are due a QC reading',
    'No QC reading has been taken on these machines for longer than the usual gap. Open QC machines to see which.', '/quality/machines', 'QC,Production');
END $$

DELIMITER ;
