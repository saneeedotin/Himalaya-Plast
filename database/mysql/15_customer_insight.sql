-- =============================================================================
-- HPOS Factory OS  |  15_customer_insight.sql  -  repeat-order tracking and customer leads.
-- From each customer's past orders the database works out their usual gap between orders, how long since the
-- last one, and whether they are due (or gone quiet). "Reorder watch" lists them; a person confirms before a
-- follow-up is created. Customer leads are the people who might become customers.
-- Reading needs Customers:read; follow-ups, leads and converting need Customers:update or create. Needs 01-14.
-- =============================================================================
USE hpos;

INSERT IGNORE INTO system_settings (setting_key, setting_value, description) VALUES
  ('reorder_min_orders', '2',  'Orders a customer needs before HPOS works out their usual reorder gap'),
  ('reorder_soon_days',  '7',  'Days before the usual reorder date when a customer shows as Soon');

CREATE TABLE IF NOT EXISTS customer_leads (
  id                    CHAR(36)     NOT NULL DEFAULT (UUID()),
  company_name          VARCHAR(190) NOT NULL,
  contact_name          VARCHAR(190) NULL,
  phone                 VARCHAR(40)  NULL,
  email                 VARCHAR(190) NULL,
  source                VARCHAR(100) NULL,
  status                VARCHAR(20)  NOT NULL DEFAULT 'NEW',   -- NEW, CONTACTED, QUOTED, WON, LOST
  next_follow_up        DATETIME     NULL,
  notes                 TEXT         NULL,
  owner_id              CHAR(36)     NULL,
  converted_customer_id CHAR(36)     NULL,
  created_at            DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at            DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY ix_cl_status (status),
  CONSTRAINT fk_cl_owner FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_cl_cust  FOREIGN KEY (converted_customer_id) REFERENCES customers(id) ON DELETE SET NULL,
  CONSTRAINT ck_cl_status CHECK (status IN ('NEW','CONTACTED','QUOTED','WON','LOST'))
) ENGINE=InnoDB;

DELIMITER $$

-- A number from system_settings, or the default when the setting is missing or not a number
DROP FUNCTION IF EXISTS fn_setting_num $$
CREATE FUNCTION fn_setting_num(p_key VARCHAR(64), p_default DECIMAL(16,3)) RETURNS DECIMAL(16,3)
READS SQL DATA
BEGIN
  DECLARE v VARCHAR(255);
  SELECT setting_value INTO v FROM system_settings WHERE setting_key = p_key;
  IF v IS NULL OR v NOT REGEXP '^[0-9]+(\\.[0-9]+)?$' THEN RETURN p_default; END IF;
  RETURN CAST(v AS DECIMAL(16,3));
END $$

DELIMITER ;

-- Orders that count: everything except drafts and cancelled ones
CREATE OR REPLACE VIEW v_customer_order_stats AS
SELECT c.id AS customer_id, c.name,
       COUNT(o.id) AS order_count,
       MIN(o.transaction_date) AS first_order,
       MAX(o.transaction_date) AS last_order,
       COALESCE(SUM(o.total_amount), 0) AS lifetime_value,
       IF(COUNT(o.id) >= 2, GREATEST(1, ROUND(DATEDIFF(MAX(o.transaction_date), MIN(o.transaction_date)) / (COUNT(o.id) - 1), 1)), NULL) AS usual_gap_days,
       IF(COUNT(o.id) >= 1, DATEDIFF(CURDATE(), MAX(o.transaction_date)), NULL) AS days_since_last
  FROM customers c
  LEFT JOIN sales_orders o ON o.customer_id = c.id AND o.status NOT IN ('DRAFT', 'CANCELLED')
 GROUP BY c.id, c.name;

-- NONE no orders; NEW too few orders to tell; ON_TRACK; SOON within the soon-days of the usual date;
-- DUE at or past the usual gap; QUIET more than twice the usual gap
CREATE OR REPLACE VIEW v_customer_reorder AS
SELECT s.customer_id, s.name, s.order_count, s.first_order, s.last_order, s.lifetime_value, s.usual_gap_days, s.days_since_last,
       IF(s.usual_gap_days IS NULL, NULL, DATE_ADD(DATE(s.last_order), INTERVAL ROUND(s.usual_gap_days) DAY)) AS expected_next,
       CASE
         WHEN s.order_count = 0 THEN 'NONE'
         WHEN s.order_count < fn_setting_num('reorder_min_orders', 2) OR s.usual_gap_days IS NULL THEN 'NEW'
         WHEN s.days_since_last > 2 * s.usual_gap_days THEN 'QUIET'
         WHEN s.days_since_last >= s.usual_gap_days THEN 'DUE'
         WHEN s.days_since_last >= s.usual_gap_days - fn_setting_num('reorder_soon_days', 7) THEN 'SOON'
         ELSE 'ON_TRACK'
       END AS reorder_status
  FROM v_customer_order_stats s;

DELIMITER $$

DROP PROCEDURE IF EXISTS sp_reorder_watch $$
CREATE PROCEDURE sp_reorder_watch(IN p_actor CHAR(36))
BEGIN
  CALL sp_require_permission(p_actor, 'Customers', 'read');
  SELECT r.customer_id, r.name, c.phone, c.email, r.order_count, r.last_order, r.usual_gap_days, r.days_since_last, r.expected_next,
         r.lifetime_value, r.reorder_status,
         IF(r.usual_gap_days IS NULL, NULL, r.days_since_last - r.usual_gap_days) AS days_over,
         (SELECT COUNT(*) FROM customer_follow_ups f WHERE f.customer_id = r.customer_id AND f.reminder_status = 'PENDING') AS open_follow_ups
    FROM v_customer_reorder r JOIN customers c ON c.id = r.customer_id
   WHERE r.order_count > 0
   ORDER BY FIELD(r.reorder_status, 'DUE', 'QUIET', 'SOON', 'ON_TRACK', 'NEW'), days_over DESC, r.name;
END $$

-- Creates the follow-up for a customer who is due: a note with the numbers and a reminder tomorrow.
-- Refused when a reorder follow-up is already open, so pressing the button twice does nothing new.
DROP PROCEDURE IF EXISTS sp_reorder_follow_up $$
CREATE PROCEDURE sp_reorder_follow_up(IN p_actor CHAR(36), IN p_customer_id CHAR(36), OUT p_out_id CHAR(36))
BEGIN
  DECLARE v_last DATETIME; DECLARE v_gap DECIMAL(10,1); DECLARE v_since INT; DECLARE v_next DATE; DECLARE v_n INT DEFAULT 0;
  CALL sp_require_permission(p_actor, 'Customers', 'update');
  SELECT order_count, last_order, usual_gap_days, days_since_last, expected_next INTO v_n, v_last, v_gap, v_since, v_next
    FROM v_customer_reorder WHERE customer_id = p_customer_id;
  IF v_n IS NULL OR v_n = 0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'This customer has no orders to work from'; END IF;
  IF EXISTS (SELECT 1 FROM customer_follow_ups WHERE customer_id = p_customer_id AND reminder_status = 'PENDING'
                AND notes LIKE 'Reorder watch:%' AND created_at > NOW() - INTERVAL 7 DAY) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A reorder reminder for this customer is already open';
  END IF;
  CALL sp_customer_follow_up_log(p_actor, p_customer_id,
    CONCAT('Reorder watch: ', IF(v_gap IS NULL, 'only one order so far', CONCAT('usually orders every ', ROUND(v_gap), ' days')),
           '; last order ', DATE(v_last), ' (', v_since, ' days ago). Call to ask about the next order.'),
    GREATEST(IFNULL(v_next, CURDATE()), CURDATE()), NOW() + INTERVAL 1 DAY, p_out_id);
END $$

-- ----------------------------- customer leads ---------------------------------
DROP PROCEDURE IF EXISTS sp_customer_lead_list $$
CREATE PROCEDURE sp_customer_lead_list(IN p_actor CHAR(36))
BEGIN
  CALL sp_require_permission(p_actor, 'Customers', 'read');
  SELECT l.*, u.name AS owner_name FROM customer_leads l LEFT JOIN users u ON u.id = l.owner_id
   ORDER BY FIELD(l.status, 'NEW', 'CONTACTED', 'QUOTED', 'WON', 'LOST'), l.next_follow_up IS NULL, l.next_follow_up, l.created_at DESC;
END $$

DROP PROCEDURE IF EXISTS sp_customer_lead_get $$
CREATE PROCEDURE sp_customer_lead_get(IN p_actor CHAR(36), IN p_id CHAR(36))
BEGIN
  CALL sp_require_permission(p_actor, 'Customers', 'read');
  SELECT * FROM customer_leads WHERE id = p_id;
END $$

DROP PROCEDURE IF EXISTS sp_customer_lead_save $$
CREATE PROCEDURE sp_customer_lead_save(
  IN p_actor CHAR(36), IN p_id CHAR(36), IN p_company VARCHAR(190), IN p_contact VARCHAR(190), IN p_phone VARCHAR(40),
  IN p_email VARCHAR(190), IN p_source VARCHAR(100), IN p_status VARCHAR(20), IN p_next_follow_up DATETIME, IN p_notes TEXT,
  OUT p_out_id CHAR(36))
BEGIN
  DECLARE v_old VARCHAR(20); DECLARE v_conv CHAR(36);
  CALL sp_require_permission(p_actor, 'Customers', IF(NULLIF(p_id, '') IS NULL, 'create', 'update'));
  IF IFNULL(TRIM(p_company), '') = '' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The company name is required'; END IF;
  IF p_status NOT IN ('NEW', 'CONTACTED', 'QUOTED', 'WON', 'LOST') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Choose a status'; END IF;
  IF NULLIF(p_id, '') IS NULL THEN
    IF p_status = 'WON' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Use Convert to customer to mark a lead as won'; END IF;
    SET p_out_id = UUID();
    INSERT INTO customer_leads (id, company_name, contact_name, phone, email, source, status, next_follow_up, notes, owner_id)
    VALUES (p_out_id, TRIM(p_company), NULLIF(p_contact, ''), NULLIF(p_phone, ''), NULLIF(p_email, ''), NULLIF(p_source, ''), p_status, p_next_follow_up, NULLIF(p_notes, ''), p_actor);
  ELSE
    SELECT status, converted_customer_id INTO v_old, v_conv FROM customer_leads WHERE id = p_id;
    IF v_old IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Lead not found'; END IF;
    IF v_conv IS NOT NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'This lead is already a customer'; END IF;
    IF p_status = 'WON' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Use Convert to customer to mark a lead as won'; END IF;
    UPDATE customer_leads SET company_name = TRIM(p_company), contact_name = NULLIF(p_contact, ''), phone = NULLIF(p_phone, ''),
           email = NULLIF(p_email, ''), source = NULLIF(p_source, ''), status = p_status, next_follow_up = p_next_follow_up, notes = NULLIF(p_notes, '')
     WHERE id = p_id;
    SET p_out_id = p_id;
  END IF;
END $$

-- Turns a lead into a customer (company, email and phone carry over) and marks the lead Won
DROP PROCEDURE IF EXISTS sp_customer_lead_convert $$
CREATE PROCEDURE sp_customer_lead_convert(IN p_actor CHAR(36), IN p_id CHAR(36), OUT p_customer_id CHAR(36))
BEGIN
  DECLARE v_company VARCHAR(190); DECLARE v_email VARCHAR(190); DECLARE v_phone VARCHAR(40); DECLARE v_conv CHAR(36); DECLARE v_status VARCHAR(20);
  DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;
  CALL sp_require_permission(p_actor, 'Customers', 'create');
  SELECT company_name, email, phone, converted_customer_id, status INTO v_company, v_email, v_phone, v_conv, v_status FROM customer_leads WHERE id = p_id;
  IF v_company IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Lead not found'; END IF;
  IF v_conv IS NOT NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'This lead is already a customer'; END IF;
  IF v_status = 'LOST' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A lost lead cannot be converted. Reopen it first'; END IF;
  START TRANSACTION;
    CALL sp_customer_save(p_actor, NULL, v_company, NULL, v_email, v_phone, p_customer_id);
    UPDATE customer_leads SET status = 'WON', converted_customer_id = p_customer_id WHERE id = p_id;
  COMMIT;
END $$

-- ----------------------------- alerts (called by sp_generate_alerts) ------------------------
DROP PROCEDURE IF EXISTS sp_alerts_customers $$
CREATE PROCEDURE sp_alerts_customers()
BEGIN
  DECLARE v_n INT DEFAULT 0;

  -- customers due to reorder, or gone quiet
  SELECT COUNT(*) INTO v_n FROM v_customer_reorder WHERE reorder_status IN ('DUE', 'QUIET');
  UPDATE notifications SET status = 'RESOLVED'
   WHERE source_type = 'CUSTOMER' AND source_id = 'reorder' AND status <> 'RESOLVED' AND title <> CONCAT(v_n, ' customers due to reorder');
  IF v_n > 0 THEN
    CALL sp_notify_upsert('CUSTOMER', 'reorder', 'ACTION_REQUIRED', CONCAT(v_n, ' customers due to reorder'),
      'These customers have passed their usual gap between orders. Open Reorder watch to follow up.', '/reorders', 'Customers,SalesOrders');
  END IF;

  -- customer leads whose follow-up date has come
  SELECT COUNT(*) INTO v_n FROM customer_leads WHERE status IN ('NEW', 'CONTACTED', 'QUOTED') AND next_follow_up IS NOT NULL AND next_follow_up <= NOW();
  UPDATE notifications SET status = 'RESOLVED'
   WHERE source_type = 'CUSTOMER' AND source_id = 'leads' AND status <> 'RESOLVED' AND title <> CONCAT(v_n, ' lead follow-ups due');
  IF v_n > 0 THEN
    CALL sp_notify_upsert('CUSTOMER', 'leads', 'ACTION_REQUIRED', CONCAT(v_n, ' lead follow-ups due'),
      'Some customer leads are waiting for a call or message.', '/customer-leads', 'Customers');
  END IF;

  -- customer reminders that were set and have come due (they were never acted on before)
  SELECT COUNT(*) INTO v_n FROM customer_follow_ups WHERE reminder_status = 'PENDING' AND reminder_date IS NOT NULL AND reminder_date <= NOW();
  UPDATE notifications SET status = 'RESOLVED'
   WHERE source_type = 'CUSTOMER' AND source_id = 'reminders' AND status <> 'RESOLVED' AND title <> CONCAT(v_n, ' customer reminders due');
  IF v_n > 0 THEN
    CALL sp_notify_upsert('CUSTOMER', 'reminders', 'ACTION_REQUIRED', CONCAT(v_n, ' customer reminders due'),
      'Follow-ups you set a reminder for are due. Open the customer to mark them done.', '/reorders', 'Customers');
  END IF;
END $$

DELIMITER ;
