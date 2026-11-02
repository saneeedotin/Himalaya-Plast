-- =============================================================================
-- HPOS Factory OS  |  03_core_procedures.sql  -  numbering, RBAC, audit, auth
--
-- Conventions used by every procedure in 03/04:
--   * First argument p_user_id = the logged-in user (PHP gets it from the session).
--   * Every write procedure starts with  CALL sp_require_permission(...)  so the
--     database itself enforces who may do what, whatever PHP page calls it.
--   * Errors are raised with SIGNAL SQLSTATE '45000' and a readable message.
--     Messages beginning "Forbidden" mean 403, everything else is a 400-type error.
--   * Multi-table changes run inside START TRANSACTION ... COMMIT and roll back on error.
--   * Procedures that return data return it as ordinary result sets.
--
-- Import with:  mysql -u USER -p hpos < 03_core_procedures.sql
-- (phpMyAdmin / Adminer imports handle the DELIMITER lines automatically.)
-- =============================================================================
USE hpos;

DELIMITER $$

-- -----------------------------------------------------------------------------
-- Document numbers: SO-2026-00001, BUY-PO-2026-00001, QC-2026-00001 ...
-- The upsert locks the sequence row until the caller's transaction ends, so two
-- people can never be given the same number.
-- -----------------------------------------------------------------------------
DROP PROCEDURE IF EXISTS sp_next_doc_no $$
CREATE PROCEDURE sp_next_doc_no(IN p_prefix VARCHAR(40), OUT p_no VARCHAR(60))
BEGIN
  DECLARE v_year SMALLINT DEFAULT YEAR(CURDATE());
  DECLARE v_val  INT;

  INSERT INTO doc_sequences (seq_key, seq_year, last_no)
  VALUES (p_prefix, v_year, 1)
  ON DUPLICATE KEY UPDATE last_no = last_no + 1;

  SELECT last_no INTO v_val FROM doc_sequences
   WHERE seq_key = p_prefix AND seq_year = v_year;

  SET p_no = CONCAT(p_prefix, '-', v_year, '-', LPAD(v_val, 5, '0'));
END $$

-- -----------------------------------------------------------------------------
-- RBAC
-- -----------------------------------------------------------------------------
DROP FUNCTION IF EXISTS fn_can $$
CREATE FUNCTION fn_can(p_user_id CHAR(36), p_resource VARCHAR(60), p_action VARCHAR(30))
RETURNS TINYINT(1)
READS SQL DATA
BEGIN
  DECLARE v_ok TINYINT(1) DEFAULT 0;
  SELECT EXISTS (
    SELECT 1
      FROM users u
      JOIN user_roles ur       ON ur.user_id = u.id
      JOIN role_permissions rp ON rp.role_id = ur.role_id
      JOIN permissions p       ON p.id = rp.permission_id
     WHERE u.id = p_user_id AND u.is_active = 1
       AND (p.resource = '*' OR p.resource = p_resource)
       AND (p.action   = '*' OR p.action   = p_action)
  ) INTO v_ok;
  RETURN v_ok;
END $$

DROP PROCEDURE IF EXISTS sp_require_permission $$
CREATE PROCEDURE sp_require_permission(IN p_user_id CHAR(36), IN p_resource VARCHAR(60), IN p_action VARCHAR(30))
BEGIN
  IF p_user_id IS NULL OR fn_can(p_user_id, p_resource, p_action) = 0 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Forbidden: you do not have permission for this action';
  END IF;
END $$

DROP PROCEDURE IF EXISTS sp_auth_get_permissions $$
CREATE PROCEDURE sp_auth_get_permissions(IN p_user_id CHAR(36))
BEGIN
  SELECT DISTINCT p.resource, p.action
    FROM user_roles ur
    JOIN role_permissions rp ON rp.role_id = ur.role_id
    JOIN permissions p       ON p.id = rp.permission_id
   WHERE ur.user_id = p_user_id
   ORDER BY p.resource, p.action;
END $$

-- -----------------------------------------------------------------------------
-- Audit
-- -----------------------------------------------------------------------------
DROP PROCEDURE IF EXISTS sp_audit $$
CREATE PROCEDURE sp_audit(IN p_user_id CHAR(36), IN p_event VARCHAR(80), IN p_ip VARCHAR(64), IN p_meta JSON)
BEGIN
  INSERT INTO audit_logs (user_id, event, ip, metadata)
  VALUES (p_user_id, p_event, p_ip, p_meta);
END $$

-- -----------------------------------------------------------------------------
-- AUTH
-- Password hashing stays in PHP (password_hash / password_verify with
-- PASSWORD_ARGON2ID, which reads the existing hashes from the old Node app).
-- MySQL stores the hash, the lock-out counters, the sessions and the audit trail.
--
-- Login flow in PHP:
--   1. CALL sp_auth_get_user_by_email(email)       -> row or empty
--      (if empty, still run password_verify against a dummy hash - no timing leak)
--   2. is_active = 0            -> "Account deactivated"
--      locked_until > NOW()     -> "Account locked"
--   3. password_verify fails    -> CALL sp_auth_login_failed(id, ip)
--   4. password ok              -> token = bin2hex(random_bytes(32));
--                                  CALL sp_auth_login_success(id, hash('sha256',token), ip, ua, 8)
--                                  set cookie hpos_session=token (HttpOnly, Secure, SameSite=Lax)
-- -----------------------------------------------------------------------------
DROP PROCEDURE IF EXISTS sp_auth_get_user_by_email $$
CREATE PROCEDURE sp_auth_get_user_by_email(IN p_email VARCHAR(190))
BEGIN
  SELECT id, email, name, password_hash, is_active, failed_attempts,
         locked_until, (locked_until IS NOT NULL AND locked_until > NOW(3)) AS is_locked
    FROM users WHERE email = p_email;
END $$

DROP PROCEDURE IF EXISTS sp_auth_get_user_by_id $$
CREATE PROCEDURE sp_auth_get_user_by_id(IN p_user_id CHAR(36))
BEGIN
  SELECT id, email, name, password_hash, is_active FROM users WHERE id = p_user_id;
END $$

DROP PROCEDURE IF EXISTS sp_auth_login_failed $$
CREATE PROCEDURE sp_auth_login_failed(IN p_user_id CHAR(36), IN p_ip VARCHAR(64))
BEGIN
  -- locked_until is assigned first so it sees the counter BEFORE the increment
  UPDATE users
     SET locked_until    = IF(failed_attempts + 1 >= 5, NOW(3) + INTERVAL 15 MINUTE, locked_until),
         failed_attempts = failed_attempts + 1
   WHERE id = p_user_id;
  CALL sp_audit(p_user_id, 'LOGIN_FAILED_BAD_PASSWORD', p_ip, NULL);
END $$

DROP PROCEDURE IF EXISTS sp_auth_login_success $$
CREATE PROCEDURE sp_auth_login_success(
  IN p_user_id CHAR(36), IN p_token_hash CHAR(64), IN p_ip VARCHAR(64),
  IN p_user_agent VARCHAR(255), IN p_hours INT)
BEGIN
  DECLARE v_exp DATETIME(3) DEFAULT NOW(3) + INTERVAL IFNULL(p_hours, 8) HOUR;

  START TRANSACTION;
    UPDATE users SET failed_attempts = 0, locked_until = NULL, last_login_at = NOW(3)
     WHERE id = p_user_id;
    INSERT INTO sessions (user_id, token_hash, expires_at, ip, user_agent)
    VALUES (p_user_id, p_token_hash, v_exp, p_ip, p_user_agent);
    CALL sp_audit(p_user_id, 'LOGIN_SUCCESS', p_ip, JSON_OBJECT('userAgent', p_user_agent));
  COMMIT;

  SELECT v_exp AS expires_at;
END $$

-- Returns 0 rows when the session is missing/expired. Otherwise returns the user
-- and the (possibly renewed) expiry; PHP should re-issue the cookie with that expiry.
DROP PROCEDURE IF EXISTS sp_auth_get_session $$
CREATE PROCEDURE sp_auth_get_session(IN p_token_hash CHAR(64), IN p_session_hours INT)
BEGIN
  DECLARE v_hours INT DEFAULT IFNULL(p_session_hours, 8);
  DECLARE v_sid CHAR(36);
  DECLARE v_exp DATETIME(3);

  SELECT s.id, s.expires_at INTO v_sid, v_exp
    FROM sessions s JOIN users u ON u.id = s.user_id
   WHERE s.token_hash = p_token_hash AND u.is_active = 1;

  IF v_sid IS NULL THEN
    DELETE FROM sessions WHERE token_hash = p_token_hash AND expires_at < NOW(3);
  ELSEIF v_exp < NOW(3) THEN
    DELETE FROM sessions WHERE id = v_sid;
    SET v_sid = NULL;
  ELSEIF v_exp < NOW(3) + INTERVAL (v_hours * 30) MINUTE THEN
    -- sliding renewal once less than half of the lifetime is left
    SET v_exp = NOW(3) + INTERVAL v_hours HOUR;
    UPDATE sessions SET expires_at = v_exp WHERE id = v_sid;
  END IF;

  IF v_sid IS NOT NULL THEN
    SELECT u.id, u.email, u.name, u.employee_code, u.phone, u.bio, u.dob,
           u.customer_id, u.assigned_machine_id, u.last_login_at, v_exp AS session_expires_at,
           (SELECT GROUP_CONCAT(r.name ORDER BY r.name SEPARATOR ', ')
              FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = u.id) AS roles
      FROM users u JOIN sessions s ON s.user_id = u.id
     WHERE s.id = v_sid;
  END IF;
END $$

DROP PROCEDURE IF EXISTS sp_auth_logout $$
CREATE PROCEDURE sp_auth_logout(IN p_token_hash CHAR(64))
BEGIN
  DELETE FROM sessions WHERE token_hash = p_token_hash;
END $$

DROP PROCEDURE IF EXISTS sp_auth_purge_sessions $$
CREATE PROCEDURE sp_auth_purge_sessions()
BEGIN
  DELETE FROM sessions WHERE expires_at < NOW(3);
END $$

-- PHP verifies the CURRENT password first (sp_auth_get_user_by_id + password_verify)
DROP PROCEDURE IF EXISTS sp_auth_change_password $$
CREATE PROCEDURE sp_auth_change_password(
  IN p_user_id CHAR(36), IN p_new_hash VARCHAR(255), IN p_keep_token_hash CHAR(64), IN p_ip VARCHAR(64))
BEGIN
  START TRANSACTION;
    UPDATE users SET password_hash = p_new_hash WHERE id = p_user_id;
    -- sign out every other device
    DELETE FROM sessions WHERE user_id = p_user_id
       AND (p_keep_token_hash IS NULL OR token_hash <> p_keep_token_hash);
    CALL sp_audit(p_user_id, 'PASSWORD_CHANGED', p_ip, NULL);
  COMMIT;
END $$

-- -----------------------------------------------------------------------------
-- User administration
-- -----------------------------------------------------------------------------
DROP PROCEDURE IF EXISTS sp_user_list $$
CREATE PROCEDURE sp_user_list(IN p_actor CHAR(36))
BEGIN
  CALL sp_require_permission(p_actor, 'users', 'read');
  SELECT u.id, u.email, u.name, u.employee_code, u.is_active, u.last_login_at, u.created_at,
         (SELECT GROUP_CONCAT(r.name ORDER BY r.name SEPARATOR ', ')
            FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = u.id) AS roles
    FROM users u ORDER BY u.created_at DESC;
END $$

DROP PROCEDURE IF EXISTS sp_role_list $$
CREATE PROCEDURE sp_role_list(IN p_actor CHAR(36))
BEGIN
  CALL sp_require_permission(p_actor, 'users', 'read');
  SELECT id, name FROM roles ORDER BY name;
END $$

-- One user with the ids of their roles (for the edit form)
DROP PROCEDURE IF EXISTS sp_user_get $$
CREATE PROCEDURE sp_user_get(IN p_actor CHAR(36), IN p_id CHAR(36))
BEGIN
  CALL sp_require_permission(p_actor, 'users', 'read');
  SELECT id, email, name, employee_code, is_active, last_login_at, created_at FROM users WHERE id = p_id;
  SELECT role_id FROM user_roles WHERE user_id = p_id;
END $$

-- -----------------------------------------------------------------------------
-- MY PROFILE: the signed-in user's own details. The id always comes from the
-- session, never from the request, so no permission check is needed (the same
-- rule as sp_auth_change_password). Three result sets:
--   1. the user (one row), 2. their roles, 3. their last 8 sign-ins
-- -----------------------------------------------------------------------------
DROP PROCEDURE IF EXISTS sp_profile_get $$
CREATE PROCEDURE sp_profile_get(IN p_user_id CHAR(36))
BEGIN
  SELECT id, email, name, employee_code, phone, last_login_at, created_at
    FROM users WHERE id = p_user_id AND is_active = 1;
  SELECT r.name FROM user_roles ur JOIN roles r ON r.id = ur.role_id
   WHERE ur.user_id = p_user_id ORDER BY r.name;
  SELECT created_at, ip, JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.userAgent')) AS user_agent
    FROM audit_logs
   WHERE user_id = p_user_id AND event = 'LOGIN_SUCCESS'
   ORDER BY created_at DESC LIMIT 8;
END $$

-- Name is required; phone is optional (digits, spaces, + - ( ) only)
DROP PROCEDURE IF EXISTS sp_profile_update $$
CREATE PROCEDURE sp_profile_update(
  IN p_user_id CHAR(36), IN p_name VARCHAR(190), IN p_phone VARCHAR(40), IN p_ip VARCHAR(64))
BEGIN
  DECLARE v_name VARCHAR(190) DEFAULT TRIM(IFNULL(p_name, ''));
  DECLARE v_phone VARCHAR(40) DEFAULT NULLIF(TRIM(IFNULL(p_phone, '')), '');

  IF v_name = '' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Name is required';
  END IF;
  IF v_phone IS NOT NULL AND v_phone NOT REGEXP '^[0-9 +()-]{5,40}$' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Phone may contain only digits, spaces and + - ( )';
  END IF;
  IF NOT EXISTS (SELECT 1 FROM users WHERE id = p_user_id AND is_active = 1) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'User not found';
  END IF;

  UPDATE users SET name = v_name, phone = v_phone WHERE id = p_user_id;
  CALL sp_audit(p_user_id, 'PROFILE_UPDATED', p_ip, NULL);
END $$

-- p_role_ids is a JSON array of role ids: '["id1","id2"]'
DROP PROCEDURE IF EXISTS sp_user_create $$
CREATE PROCEDURE sp_user_create(
  IN p_actor CHAR(36), IN p_email VARCHAR(190), IN p_name VARCHAR(190),
  IN p_password_hash VARCHAR(255), IN p_employee_code VARCHAR(40),
  IN p_role_ids JSON, IN p_ip VARCHAR(64), OUT p_new_id CHAR(36))
BEGIN
  DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;

  CALL sp_require_permission(p_actor, 'users', 'create');

  IF p_role_ids IS NULL OR JSON_LENGTH(p_role_ids) = 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'At least one role is required';
  END IF;
  IF EXISTS (SELECT 1 FROM users WHERE email = p_email) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Email already exists';
  END IF;

  SET p_new_id = UUID();
  START TRANSACTION;
    INSERT INTO users (id, email, name, password_hash, employee_code)
    VALUES (p_new_id, p_email, p_name, p_password_hash, NULLIF(p_employee_code, ''));

    INSERT INTO user_roles (user_id, role_id)
    SELECT p_new_id, jt.role_id
      FROM JSON_TABLE(p_role_ids, '$[*]' COLUMNS (role_id CHAR(36) PATH '$')) jt
      JOIN roles r ON r.id = (jt.role_id COLLATE utf8mb4_unicode_ci);

    CALL sp_audit(p_actor, 'USER_CREATED', p_ip, JSON_OBJECT('targetUserId', p_new_id, 'email', p_email));
  COMMIT;
END $$

-- p_is_active / p_role_ids may be NULL = "leave unchanged"
DROP PROCEDURE IF EXISTS sp_user_update $$
CREATE PROCEDURE sp_user_update(
  IN p_actor CHAR(36), IN p_target CHAR(36), IN p_is_active TINYINT, IN p_role_ids JSON, IN p_ip VARCHAR(64))
BEGIN
  DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;

  CALL sp_require_permission(p_actor, 'users', 'update');

  IF NOT EXISTS (SELECT 1 FROM users WHERE id = p_target) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'User not found';
  END IF;
  IF p_actor = p_target AND p_is_active = 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'You cannot deactivate your own account';
  END IF;

  START TRANSACTION;
    IF p_is_active IS NOT NULL THEN
      UPDATE users SET is_active = p_is_active WHERE id = p_target;
      IF p_is_active = 0 THEN
        DELETE FROM sessions WHERE user_id = p_target;   -- kick them out immediately
      END IF;
    END IF;

    IF p_role_ids IS NOT NULL THEN
      DELETE FROM user_roles WHERE user_id = p_target;
      INSERT INTO user_roles (user_id, role_id)
      SELECT p_target, jt.role_id
        FROM JSON_TABLE(p_role_ids, '$[*]' COLUMNS (role_id CHAR(36) PATH '$')) jt
        JOIN roles r ON r.id = (jt.role_id COLLATE utf8mb4_unicode_ci);
    END IF;

    CALL sp_audit(p_actor, 'USER_UPDATED', p_ip,
      JSON_OBJECT('targetUserId', p_target, 'isActive', p_is_active, 'roleIds', p_role_ids));
  COMMIT;
END $$

-- An administrator sets a new password for someone who forgot theirs. The hash comes from PHP.
-- All of the user's sessions end and a lock-out is cleared, so they can sign in at once.
DROP PROCEDURE IF EXISTS sp_user_set_password $$
CREATE PROCEDURE sp_user_set_password(
  IN p_actor CHAR(36), IN p_target CHAR(36), IN p_new_hash VARCHAR(255), IN p_ip VARCHAR(64))
BEGIN
  DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;

  CALL sp_require_permission(p_actor, 'users', 'update');
  IF NOT EXISTS (SELECT 1 FROM users WHERE id = p_target) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'User not found';
  END IF;
  IF p_new_hash IS NULL OR CHAR_LENGTH(p_new_hash) < 20 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A password hash is required';
  END IF;

  START TRANSACTION;
    UPDATE users SET password_hash = p_new_hash, failed_attempts = 0, locked_until = NULL WHERE id = p_target;
    DELETE FROM sessions WHERE user_id = p_target;
    CALL sp_audit(p_actor, 'PASSWORD_RESET_BY_ADMIN', p_ip, JSON_OBJECT('targetUserId', p_target));
  COMMIT;
END $$

DROP PROCEDURE IF EXISTS sp_user_update_profile $$
CREATE PROCEDURE sp_user_update_profile(
  IN p_user_id CHAR(36), IN p_name VARCHAR(190), IN p_dob DATE, IN p_phone VARCHAR(40), IN p_bio TEXT)
BEGIN
  -- a user may only edit their own profile; name is kept when NULL/empty
  UPDATE users
     SET name  = COALESCE(NULLIF(p_name, ''), name),
         dob   = p_dob,
         phone = p_phone,
         bio   = p_bio
   WHERE id = p_user_id;
END $$

DELIMITER ;
