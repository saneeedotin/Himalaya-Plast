-- =============================================================================
-- HPOS Factory OS  |  13_job_checklist.sql  -  the pre-run checklist on a job card: material verification,
-- material change, hopper loading, die change, air pressure check, trial production, first quality
-- verification, production started. Each step is ticked by a person (or marked not needed, for the two
-- optional ones) and keeps who and when. It guides the operator; it does not block starting the job.
-- Reading needs Production:read, ticking needs Production:create. Needs 01-12.
-- =============================================================================
USE hpos;

CREATE TABLE IF NOT EXISTS job_card_steps (
  id             CHAR(36)      NOT NULL DEFAULT (UUID()),
  job_card_id    CHAR(36)      NOT NULL,
  step_key       VARCHAR(20)   NOT NULL,
  status         VARCHAR(12)   NOT NULL,
  reading        DECIMAL(10,3) NULL,        -- the air pressure, in bar
  note           VARCHAR(200)  NULL,
  done_by_id     CHAR(36)      NULL,
  done_by_name   VARCHAR(190)  NOT NULL,
  done_at        DATETIME(3)   NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_jcs_step (job_card_id, step_key),
  CONSTRAINT fk_jcs_jc   FOREIGN KEY (job_card_id) REFERENCES job_cards(id) ON DELETE CASCADE,
  CONSTRAINT fk_jcs_user FOREIGN KEY (done_by_id)  REFERENCES users(id)     ON DELETE SET NULL,
  CONSTRAINT ck_jcs_key CHECK (step_key IN ('MATERIAL_VERIFY','MATERIAL_CHANGE','HOPPER_LOADING','DIE_CHANGE','AIR_PRESSURE','TRIAL_PRODUCTION','FIRST_QUALITY','PRODUCTION_STARTED')),
  CONSTRAINT ck_jcs_status CHECK (status IN ('DONE','NOT_NEEDED')),
  CONSTRAINT ck_jcs_reading CHECK (reading IS NULL OR reading >= 0)
) ENGINE=InnoDB;

DELIMITER $$

DROP PROCEDURE IF EXISTS sp_job_steps_get $$
CREATE PROCEDURE sp_job_steps_get(IN p_actor CHAR(36), IN p_job_card_id CHAR(36))
BEGIN
  CALL sp_require_permission(p_actor, 'Production', 'read');
  SELECT * FROM v_job_card_live WHERE job_card_id = p_job_card_id;
  SELECT step_key, status, reading, note, done_by_name, done_at FROM job_card_steps WHERE job_card_id = p_job_card_id;
END $$

-- p_action: DONE, NOT_NEEDED (only the two optional steps) or RESET (back to not done)
DROP PROCEDURE IF EXISTS sp_job_step_set $$
CREATE PROCEDURE sp_job_step_set(
  IN p_actor CHAR(36), IN p_job_card_id CHAR(36), IN p_step VARCHAR(20), IN p_action VARCHAR(12),
  IN p_reading DECIMAL(10,3), IN p_note VARCHAR(200))
BEGIN
  DECLARE v_status VARCHAR(20); DECLARE v_name VARCHAR(190);
  CALL sp_require_permission(p_actor, 'Production', 'create');
  IF p_step NOT IN ('MATERIAL_VERIFY','MATERIAL_CHANGE','HOPPER_LOADING','DIE_CHANGE','AIR_PRESSURE','TRIAL_PRODUCTION','FIRST_QUALITY','PRODUCTION_STARTED') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Unknown checklist step';
  END IF;
  IF p_action NOT IN ('DONE','NOT_NEEDED','RESET') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Choose done, not needed or undo'; END IF;
  IF p_action = 'NOT_NEEDED' AND p_step NOT IN ('MATERIAL_CHANGE','DIE_CHANGE') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Only a material change or a die change can be marked not needed';
  END IF;
  IF p_reading IS NOT NULL AND p_reading < 0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The air pressure cannot be negative'; END IF;
  SELECT status INTO v_status FROM job_cards WHERE id = p_job_card_id;
  IF v_status IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Job card not found'; END IF;
  IF v_status = 'COMPLETED' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'This job is complete, so its checklist is final'; END IF;
  SELECT name INTO v_name FROM users WHERE id = p_actor;
  IF p_action = 'RESET' THEN
    DELETE FROM job_card_steps WHERE job_card_id = p_job_card_id AND step_key = p_step;
  ELSE
    INSERT INTO job_card_steps (job_card_id, step_key, status, reading, note, done_by_id, done_by_name)
    VALUES (p_job_card_id, p_step, p_action, IF(p_step = 'AIR_PRESSURE', p_reading, NULL), NULLIF(TRIM(p_note), ''), p_actor, IFNULL(v_name, 'Unknown'))
    ON DUPLICATE KEY UPDATE status = VALUES(status), reading = VALUES(reading), note = VALUES(note),
      done_by_id = VALUES(done_by_id), done_by_name = VALUES(done_by_name), done_at = CURRENT_TIMESTAMP(3);
  END IF;
  CALL sp_audit(p_actor, 'JOB_STEP', NULL, JSON_OBJECT('jobCardId', p_job_card_id, 'step', p_step, 'action', p_action));
END $$

-- how many steps each running or queued job has ticked (the shop floor shows "3 of 8")
DROP PROCEDURE IF EXISTS sp_job_steps_summary $$
CREATE PROCEDURE sp_job_steps_summary(IN p_actor CHAR(36))
BEGIN
  CALL sp_require_permission(p_actor, 'Production', 'read');
  SELECT s.job_card_id, COUNT(*) AS steps_done
    FROM job_card_steps s JOIN job_cards jc ON jc.id = s.job_card_id
   WHERE jc.status IN ('QUEUED', 'ACTIVE') GROUP BY s.job_card_id;
END $$

DELIMITER ;
