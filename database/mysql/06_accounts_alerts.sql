-- =============================================================================
-- HPOS Factory OS  |  06_accounts_alerts.sql
-- Sales / purchase invoices (GST), payments, Tally log, notifications.
-- =============================================================================
USE hpos;

DELIMITER $$

-- =============================================================================
-- INVOICE MATH (called by triggers and by the payment procedures)
--   line:    amount = qty*rate ; tax = amount*tax_rate/100 ; total = amount + tax   (trigger)
--   header:  subtotal / tax / total are sums of the lines.
--   GST:     buyer in the same state as the factory (system_settings.company_state_code)
--            -> CGST + SGST (half each);   other state -> IGST.
--            Customers without a GSTIN are treated as same-state.
--   balance: outstanding = total - SUBMITTED payments;  status follows the balance
-- =============================================================================
DROP PROCEDURE IF EXISTS sp_recalc_sales_invoice $$
CREATE PROCEDURE sp_recalc_sales_invoice(IN p_id CHAR(36))
BEGIN
  DECLARE v_sub DECIMAL(16,2);
  DECLARE v_tax DECIMAL(16,2);
  DECLARE v_tot DECIMAL(16,2);
  DECLARE v_lines INT;
  DECLARE v_gstin VARCHAR(15);
  DECLARE v_state VARCHAR(4);
  DECLARE v_inter TINYINT DEFAULT 0;
  DECLARE v_paid DECIMAL(16,2);
  DECLARE v_credited DECIMAL(16,2);
  DECLARE v_status VARCHAR(20);

  SELECT COUNT(*), COALESCE(SUM(amount),0), COALESCE(SUM(tax_amount),0), COALESCE(SUM(total_amount),0)
    INTO v_lines, v_sub, v_tax, v_tot
    FROM sales_invoice_items WHERE sales_invoice_id = p_id;

  IF v_lines > 0 THEN
    SELECT c.gstin, si.status INTO v_gstin, v_status
      FROM sales_invoices si JOIN customers c ON c.id = si.customer_id WHERE si.id = p_id;
    SELECT setting_value INTO v_state FROM system_settings WHERE setting_key = 'company_state_code';
    SET v_inter = (v_gstin IS NOT NULL AND v_gstin <> '' AND LEFT(v_gstin, 2) <> IFNULL(v_state, '24'));

    UPDATE sales_invoices
       SET subtotal_amount = v_sub, tax_amount = v_tax, total_amount = v_tot,
           cgst_amount = IF(v_inter = 1, 0, ROUND(v_tax / 2, 2)),
           sgst_amount = IF(v_inter = 1, 0, v_tax - ROUND(v_tax / 2, 2)),
           igst_amount = IF(v_inter = 1, v_tax, 0)
     WHERE id = p_id;
  END IF;

  SELECT COALESCE(SUM(amount),0) INTO v_paid FROM payment_entries
   WHERE sales_invoice_id = p_id AND status = 'SUBMITTED';
  -- posted credit notes against this invoice also reduce what is owed
  SELECT COALESCE(SUM(total_amount),0) INTO v_credited FROM credit_notes WHERE sales_invoice_id = p_id AND status = 'POSTED';
  SET v_paid = v_paid + v_credited;
  UPDATE sales_invoices
     SET outstanding = GREATEST(0, total_amount - v_paid),
         status = CASE WHEN status IN ('UNPAID','PARTIAL','PAID') THEN
                         CASE WHEN total_amount - v_paid <= 0 THEN 'PAID'
                              WHEN v_paid > 0 THEN 'PARTIAL' ELSE 'UNPAID' END
                       ELSE status END
   WHERE id = p_id;
END $$

DROP PROCEDURE IF EXISTS sp_recalc_purchase_invoice $$
CREATE PROCEDURE sp_recalc_purchase_invoice(IN p_id CHAR(36))
BEGIN
  DECLARE v_sub DECIMAL(16,2);
  DECLARE v_tax DECIMAL(16,2);
  DECLARE v_tot DECIMAL(16,2);
  DECLARE v_lines INT;
  DECLARE v_gstin VARCHAR(15);
  DECLARE v_state VARCHAR(4);
  DECLARE v_inter TINYINT DEFAULT 0;
  DECLARE v_paid DECIMAL(16,2);
  DECLARE v_credited DECIMAL(16,2);

  SELECT COUNT(*), COALESCE(SUM(amount),0), COALESCE(SUM(tax_amount),0), COALESCE(SUM(total_amount),0)
    INTO v_lines, v_sub, v_tax, v_tot
    FROM purchase_invoice_items WHERE purchase_invoice_id = p_id;

  IF v_lines > 0 THEN
    SELECT s.gstin INTO v_gstin
      FROM purchase_invoices pi JOIN suppliers s ON s.id = pi.supplier_id WHERE pi.id = p_id;
    SELECT setting_value INTO v_state FROM system_settings WHERE setting_key = 'company_state_code';
    SET v_inter = (v_gstin IS NOT NULL AND v_gstin <> '' AND LEFT(v_gstin, 2) <> IFNULL(v_state, '24'));

    UPDATE purchase_invoices
       SET subtotal_amount = v_sub, tax_amount = v_tax, total_amount = v_tot,
           cgst_amount = IF(v_inter = 1, 0, ROUND(v_tax / 2, 2)),
           sgst_amount = IF(v_inter = 1, 0, v_tax - ROUND(v_tax / 2, 2)),
           igst_amount = IF(v_inter = 1, v_tax, 0)
     WHERE id = p_id;
  END IF;

  SELECT COALESCE(SUM(amount),0) INTO v_paid FROM payment_entries
   WHERE purchase_invoice_id = p_id AND status = 'SUBMITTED';
  -- posted credit notes against this invoice also reduce what is owed
  SELECT COALESCE(SUM(total_amount),0) INTO v_credited FROM credit_notes WHERE purchase_invoice_id = p_id AND status = 'POSTED';
  SET v_paid = v_paid + v_credited;
  UPDATE purchase_invoices
     SET outstanding = GREATEST(0, total_amount - v_paid),
         status = CASE WHEN status IN ('UNPAID','PARTIAL','PAID') THEN
                         CASE WHEN total_amount - v_paid <= 0 THEN 'PAID'
                              WHEN v_paid > 0 THEN 'PARTIAL' ELSE 'UNPAID' END
                       ELSE status END
   WHERE id = p_id;
END $$

-- =============================================================================
-- SALES INVOICES
-- =============================================================================
-- Copies the sales-order lines (with the default GST rate). Totals come from the
-- trigger; the old app saved the order total with 0 tax, which is what
-- backfill-taxes.ts was written to repair.
DROP PROCEDURE IF EXISTS sp_sales_invoice_create $$
CREATE PROCEDURE sp_sales_invoice_create(
  IN p_actor CHAR(36), IN p_customer_id CHAR(36), IN p_sales_order_id CHAR(36),
  IN p_delivery_note_id CHAR(36), IN p_invoice_date DATETIME, IN p_due_date DATETIME,
  OUT p_out_id CHAR(36))
BEGIN
  DECLARE v_no VARCHAR(60);
  DECLARE v_customer CHAR(36) DEFAULT NULLIF(p_customer_id, '');
  DECLARE v_so CHAR(36) DEFAULT NULLIF(p_sales_order_id, '');
  DECLARE v_days INT DEFAULT 30;
  DECLARE v_rate DECIMAL(5,2) DEFAULT 18.00;
  DECLARE v_date DATETIME(3) DEFAULT IFNULL(p_invoice_date, NOW(3));
  DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;

  CALL sp_require_permission(p_actor, 'Accounts', 'create');
  IF v_customer IS NULL AND v_so IS NOT NULL THEN
    SELECT customer_id INTO v_customer FROM sales_orders WHERE id = v_so;
  END IF;
  IF v_customer IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A customer is required (link the order to a customer first)';
  END IF;

  SELECT CAST(setting_value AS SIGNED) INTO v_days FROM system_settings WHERE setting_key = 'default_credit_days';
  SELECT CAST(setting_value AS DECIMAL(5,2)) INTO v_rate FROM system_settings WHERE setting_key = 'default_tax_rate';

  START TRANSACTION;
    CALL sp_next_doc_no('INV', v_no);
    SET p_out_id = UUID();
    INSERT INTO sales_invoices (id, invoice_number, sales_order_id, delivery_note_id, customer_id,
                                invoice_date, due_date, status)
    VALUES (p_out_id, v_no, v_so, NULLIF(p_delivery_note_id,''), v_customer, v_date,
            IFNULL(p_due_date, v_date + INTERVAL IFNULL(v_days,30) DAY), 'DRAFT');

    IF v_so IS NOT NULL THEN
      INSERT INTO sales_invoice_items (sales_invoice_id, item_id, qty, rate, tax_rate)
      SELECT p_out_id, item_id, qty, rate, IFNULL(v_rate, 18.00)
        FROM sales_order_items WHERE sales_order_id = v_so;
    END IF;
    CALL sp_recalc_sales_invoice(p_out_id);
  COMMIT;
END $$

-- p_items = '[{"itemId":"...","qty":10,"rate":50,"taxRate":18}, ...]'  (DRAFT invoices only)
DROP PROCEDURE IF EXISTS sp_sales_invoice_set_items $$
CREATE PROCEDURE sp_sales_invoice_set_items(IN p_actor CHAR(36), IN p_id CHAR(36), IN p_items JSON)
BEGIN
  DECLARE v_expected INT DEFAULT IFNULL(JSON_LENGTH(p_items), 0);
  DECLARE v_status VARCHAR(20);
  DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;

  CALL sp_require_permission(p_actor, 'Accounts', 'update');
  START TRANSACTION;
    SELECT status INTO v_status FROM sales_invoices WHERE id = p_id FOR UPDATE;
    IF v_status IS NULL OR v_status <> 'DRAFT' THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Only DRAFT invoices can be edited';
    END IF;
    DELETE FROM sales_invoice_items WHERE sales_invoice_id = p_id;
    IF v_expected > 0 THEN
      INSERT INTO sales_invoice_items (sales_invoice_id, item_id, qty, rate, tax_rate)
      SELECT p_id, i.id, jt.qty, jt.rate, IFNULL(jt.tax_rate, 18.00)
        FROM JSON_TABLE(p_items, '$[*]' COLUMNS (
               item_id  CHAR(36)      PATH '$.itemId',
               qty      DECIMAL(16,3) PATH '$.qty',
               rate     DECIMAL(16,2) PATH '$.rate',
               tax_rate DECIMAL(5,2)  PATH '$.taxRate')) jt
        JOIN items i ON i.id = (jt.item_id COLLATE utf8mb4_unicode_ci);
      IF ROW_COUNT() <> v_expected THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'One or more invoice items were not found';
      END IF;
    END IF;
    CALL sp_recalc_sales_invoice(p_id);
  COMMIT;
END $$

-- The IRN (e-invoice reference) and e-way bill number must come from the GST portal.
-- The old app generated random fake ones; pass the real values here, or NULL to fill in later.
DROP PROCEDURE IF EXISTS sp_sales_invoice_submit $$
CREATE PROCEDURE sp_sales_invoice_submit(
  IN p_actor CHAR(36), IN p_id CHAR(36), IN p_irn VARCHAR(100), IN p_eway VARCHAR(20))
BEGIN
  DECLARE v_status VARCHAR(20);
  DECLARE v_total DECIMAL(16,2);
  DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;

  CALL sp_require_permission(p_actor, 'Accounts', 'update');
  START TRANSACTION;
    SELECT status, total_amount INTO v_status, v_total FROM sales_invoices WHERE id = p_id FOR UPDATE;
    IF v_status IS NULL OR v_status <> 'DRAFT' THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid invoice or already submitted';
    END IF;
    IF v_total <= 0 THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invoice has no value; add line items first';
    END IF;
    UPDATE sales_invoices
       SET status = 'UNPAID', outstanding = total_amount,
           irn = NULLIF(p_irn,''), eway_bill_number = NULLIF(p_eway,'')
     WHERE id = p_id;
    CALL sp_audit(p_actor, 'SALES_INVOICE_SUBMITTED', NULL, JSON_OBJECT('invoiceId', p_id));
  COMMIT;
END $$

DROP PROCEDURE IF EXISTS sp_sales_invoice_set_einvoice $$
CREATE PROCEDURE sp_sales_invoice_set_einvoice(
  IN p_actor CHAR(36), IN p_id CHAR(36), IN p_irn VARCHAR(100), IN p_qr TEXT, IN p_eway VARCHAR(20))
BEGIN
  CALL sp_require_permission(p_actor, 'Accounts', 'update');
  UPDATE sales_invoices
     SET irn = IFNULL(NULLIF(p_irn,''), irn), irn_qr_code = IFNULL(p_qr, irn_qr_code),
         eway_bill_number = IFNULL(NULLIF(p_eway,''), eway_bill_number)
   WHERE id = p_id AND status <> 'CANCELLED';
END $$

DROP PROCEDURE IF EXISTS sp_sales_invoice_cancel $$
CREATE PROCEDURE sp_sales_invoice_cancel(IN p_actor CHAR(36), IN p_id CHAR(36))
BEGIN
  CALL sp_require_permission(p_actor, 'Accounts', 'delete');
  IF EXISTS (SELECT 1 FROM payment_entries WHERE sales_invoice_id = p_id AND status = 'SUBMITTED') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invoice has payments; cancel the payments first';
  END IF;
  UPDATE sales_invoices SET status = 'CANCELLED', outstanding = 0 WHERE id = p_id;
  CALL sp_audit(p_actor, 'SALES_INVOICE_CANCELLED', NULL, JSON_OBJECT('invoiceId', p_id));
END $$

-- =============================================================================
-- PURCHASE INVOICES
-- Either pass lines in p_items (GST computed per line) or, for a quick entry,
-- just p_total_amount (treated as the final payable amount, no tax split).
-- =============================================================================
DROP PROCEDURE IF EXISTS sp_purchase_invoice_create $$
CREATE PROCEDURE sp_purchase_invoice_create(
  IN p_actor CHAR(36), IN p_supplier_id CHAR(36), IN p_invoice_date DATETIME, IN p_due_date DATETIME,
  IN p_total_amount DECIMAL(16,2), IN p_items JSON, OUT p_out_id CHAR(36))
BEGIN
  DECLARE v_expected INT DEFAULT IFNULL(JSON_LENGTH(p_items), 0);
  DECLARE v_no VARCHAR(60);
  DECLARE v_days INT DEFAULT 30;
  DECLARE v_date DATETIME(3) DEFAULT IFNULL(p_invoice_date, NOW(3));
  DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;

  CALL sp_require_permission(p_actor, 'Accounts', 'create');
  IF v_expected = 0 AND (p_total_amount IS NULL OR p_total_amount <= 0) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Enter line items or a total amount';
  END IF;
  SELECT CAST(setting_value AS SIGNED) INTO v_days FROM system_settings WHERE setting_key = 'default_credit_days';

  START TRANSACTION;
    CALL sp_next_doc_no('PI', v_no);
    SET p_out_id = UUID();
    INSERT INTO purchase_invoices (id, invoice_number, supplier_id, invoice_date, due_date, status,
                                   subtotal_amount, total_amount, outstanding)
    VALUES (p_out_id, v_no, p_supplier_id, v_date,
            IFNULL(p_due_date, v_date + INTERVAL IFNULL(v_days,30) DAY), 'DRAFT',
            IF(v_expected = 0, p_total_amount, 0), IF(v_expected = 0, p_total_amount, 0),
            IF(v_expected = 0, p_total_amount, 0));

    IF v_expected > 0 THEN
      INSERT INTO purchase_invoice_items (purchase_invoice_id, item_id, qty, rate, tax_rate)
      SELECT p_out_id, i.id, jt.qty, jt.rate, IFNULL(jt.tax_rate, 18.00)
        FROM JSON_TABLE(p_items, '$[*]' COLUMNS (
               item_id  CHAR(36)      PATH '$.itemId',
               qty      DECIMAL(16,3) PATH '$.qty',
               rate     DECIMAL(16,2) PATH '$.rate',
               tax_rate DECIMAL(5,2)  PATH '$.taxRate')) jt
        JOIN items i ON i.id = (jt.item_id COLLATE utf8mb4_unicode_ci);
      IF ROW_COUNT() <> v_expected THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'One or more invoice items were not found';
      END IF;
      CALL sp_recalc_purchase_invoice(p_out_id);
    END IF;
  COMMIT;
END $$

DROP PROCEDURE IF EXISTS sp_purchase_invoice_submit $$
CREATE PROCEDURE sp_purchase_invoice_submit(IN p_actor CHAR(36), IN p_id CHAR(36))
BEGIN
  DECLARE v_status VARCHAR(20);
  DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;
  CALL sp_require_permission(p_actor, 'Accounts', 'update');
  START TRANSACTION;
    SELECT status INTO v_status FROM purchase_invoices WHERE id = p_id FOR UPDATE;
    IF v_status IS NULL OR v_status <> 'DRAFT' THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid invoice or already submitted';
    END IF;
    UPDATE purchase_invoices SET status = 'UNPAID', outstanding = total_amount WHERE id = p_id;
    CALL sp_audit(p_actor, 'PURCHASE_INVOICE_SUBMITTED', NULL, JSON_OBJECT('invoiceId', p_id));
  COMMIT;
END $$

DROP PROCEDURE IF EXISTS sp_purchase_invoice_cancel $$
CREATE PROCEDURE sp_purchase_invoice_cancel(IN p_actor CHAR(36), IN p_id CHAR(36))
BEGIN
  CALL sp_require_permission(p_actor, 'Accounts', 'delete');
  IF EXISTS (SELECT 1 FROM payment_entries WHERE purchase_invoice_id = p_id AND status = 'SUBMITTED') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invoice has payments; cancel the payments first';
  END IF;
  UPDATE purchase_invoices SET status = 'CANCELLED', outstanding = 0 WHERE id = p_id;
END $$

-- =============================================================================
-- PAYMENTS
-- A payment may be tied to one invoice (reduces its outstanding) or be a plain
-- on-account payment. The old app marked payments "COMPLETED" (not a valid status)
-- and never touched the invoice; this one settles the invoice on submit.
-- =============================================================================
DROP PROCEDURE IF EXISTS sp_payment_create $$
CREATE PROCEDURE sp_payment_create(
  IN p_actor CHAR(36), IN p_party_type VARCHAR(20), IN p_party_id CHAR(36), IN p_mode VARCHAR(20),
  IN p_reference VARCHAR(80), IN p_amount DECIMAL(16,2), IN p_sales_invoice_id CHAR(36),
  IN p_purchase_invoice_id CHAR(36), IN p_payment_date DATETIME, OUT p_out_id CHAR(36))
BEGIN
  DECLARE v_no VARCHAR(60);
  DECLARE v_si CHAR(36) DEFAULT NULLIF(p_sales_invoice_id, '');
  DECLARE v_pi CHAR(36) DEFAULT NULLIF(p_purchase_invoice_id, '');
  DECLARE v_party CHAR(36);
  DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;

  CALL sp_require_permission(p_actor, 'Accounts', 'create');
  IF p_party_type NOT IN ('CUSTOMER','SUPPLIER') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Party type must be CUSTOMER or SUPPLIER';
  END IF;
  IF p_amount IS NULL OR p_amount <= 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payment amount must be positive';
  END IF;
  IF p_mode NOT IN ('BANK_TRANSFER','CASH','CHEQUE','UPI') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid mode of payment';
  END IF;

  IF p_party_type = 'CUSTOMER' THEN
    IF v_pi IS NOT NULL THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A customer payment cannot settle a purchase invoice';
    END IF;
    IF v_si IS NOT NULL THEN
      SELECT customer_id INTO v_party FROM sales_invoices WHERE id = v_si;
      IF v_party IS NULL OR v_party <> p_party_id THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invoice does not belong to this customer';
      END IF;
    END IF;
  ELSE
    IF v_si IS NOT NULL THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A supplier payment cannot settle a sales invoice';
    END IF;
    IF v_pi IS NOT NULL THEN
      SELECT supplier_id INTO v_party FROM purchase_invoices WHERE id = v_pi;
      IF v_party IS NULL OR v_party <> p_party_id THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invoice does not belong to this supplier';
      END IF;
    END IF;
  END IF;

  START TRANSACTION;
    CALL sp_next_doc_no('PAY', v_no);
    SET p_out_id = UUID();
    INSERT INTO payment_entries
      (id, payment_number, party_type, customer_id, supplier_id, sales_invoice_id, purchase_invoice_id,
       payment_date, mode_of_payment, reference_number, amount, status)
    VALUES
      (p_out_id, v_no, p_party_type,
       IF(p_party_type = 'CUSTOMER', p_party_id, NULL), IF(p_party_type = 'SUPPLIER', p_party_id, NULL),
       v_si, v_pi, IFNULL(p_payment_date, NOW(3)), p_mode, NULLIF(p_reference,''), p_amount, 'DRAFT');
  COMMIT;
END $$

DROP PROCEDURE IF EXISTS sp_payment_submit $$
CREATE PROCEDURE sp_payment_submit(IN p_actor CHAR(36), IN p_id CHAR(36))
BEGIN
  DECLARE v_status VARCHAR(20);
  DECLARE v_amount DECIMAL(16,2);
  DECLARE v_si CHAR(36);
  DECLARE v_pi CHAR(36);
  DECLARE v_out DECIMAL(16,2);
  DECLARE v_inv_status VARCHAR(20);
  DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;

  CALL sp_require_permission(p_actor, 'Accounts', 'update');
  START TRANSACTION;
    SELECT status, amount, sales_invoice_id, purchase_invoice_id INTO v_status, v_amount, v_si, v_pi
      FROM payment_entries WHERE id = p_id FOR UPDATE;
    IF v_status IS NULL OR v_status <> 'DRAFT' THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payment not found or already submitted';
    END IF;

    IF v_si IS NOT NULL THEN
      SELECT outstanding, status INTO v_out, v_inv_status FROM sales_invoices WHERE id = v_si FOR UPDATE;
      IF v_inv_status NOT IN ('UNPAID','PARTIAL') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invoice is not open for payment';
      END IF;
      IF v_amount > v_out THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payment exceeds the invoice outstanding amount';
      END IF;
    ELSEIF v_pi IS NOT NULL THEN
      SELECT outstanding, status INTO v_out, v_inv_status FROM purchase_invoices WHERE id = v_pi FOR UPDATE;
      IF v_inv_status NOT IN ('UNPAID','PARTIAL') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invoice is not open for payment';
      END IF;
      IF v_amount > v_out THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payment exceeds the invoice outstanding amount';
      END IF;
    END IF;

    UPDATE payment_entries SET status = 'SUBMITTED' WHERE id = p_id;
    IF v_si IS NOT NULL THEN CALL sp_recalc_sales_invoice(v_si); END IF;
    IF v_pi IS NOT NULL THEN CALL sp_recalc_purchase_invoice(v_pi); END IF;
    CALL sp_audit(p_actor, 'PAYMENT_SUBMITTED', NULL, JSON_OBJECT('paymentId', p_id, 'amount', v_amount));
  COMMIT;
END $$

DROP PROCEDURE IF EXISTS sp_payment_cancel $$
CREATE PROCEDURE sp_payment_cancel(IN p_actor CHAR(36), IN p_id CHAR(36))
BEGIN
  DECLARE v_si CHAR(36);
  DECLARE v_pi CHAR(36);
  DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;
  CALL sp_require_permission(p_actor, 'Accounts', 'delete');
  START TRANSACTION;
    SELECT sales_invoice_id, purchase_invoice_id INTO v_si, v_pi FROM payment_entries WHERE id = p_id FOR UPDATE;
    UPDATE payment_entries SET status = 'CANCELLED' WHERE id = p_id AND status <> 'CANCELLED';
    IF v_si IS NOT NULL THEN CALL sp_recalc_sales_invoice(v_si); END IF;
    IF v_pi IS NOT NULL THEN CALL sp_recalc_purchase_invoice(v_pi); END IF;
    CALL sp_audit(p_actor, 'PAYMENT_CANCELLED', NULL, JSON_OBJECT('paymentId', p_id));
  COMMIT;
END $$

-- Tally export audit trail (the PHP layer builds the XML from v_export_tally_sales)
DROP PROCEDURE IF EXISTS sp_tally_sync_log $$
CREATE PROCEDURE sp_tally_sync_log(
  IN p_actor CHAR(36), IN p_document_type VARCHAR(40), IN p_document_id CHAR(36),
  IN p_xml LONGTEXT, IN p_status VARCHAR(20), IN p_error TEXT)
BEGIN
  CALL sp_require_permission(p_actor, 'Accounts', 'create');
  INSERT INTO tally_sync_logs (document_type, document_id, exported_xml, status, error_message)
  VALUES (p_document_type, p_document_id, p_xml, p_status, p_error);
END $$

-- =============================================================================
-- NOTIFICATIONS / ALERTS
-- sp_generate_alerts() is meant to run every few minutes: from a cPanel cron job
--   mysql -u... -e "CALL hpos.sp_generate_alerts()"
-- or, where the host allows it, as a MySQL event (see the end of this file).
-- =============================================================================
DROP FUNCTION IF EXISTS fn_notif_visible $$
-- Who may see a notification: audience NULL means everyone; otherwise the person needs the read right on at
-- least one of the listed resources (or the admin wildcard). Same idea as fn_can, so the page, the bell
-- and Web Push all agree on who is told.
CREATE FUNCTION fn_notif_visible(p_user_id CHAR(36), p_audience VARCHAR(160))
RETURNS TINYINT(1)
READS SQL DATA
BEGIN
  DECLARE v_ok TINYINT(1) DEFAULT 0;
  IF p_audience IS NULL OR p_audience = '' THEN
    RETURN 1;
  END IF;
  SELECT EXISTS (
    SELECT 1
      FROM users u
      JOIN user_roles ur       ON ur.user_id = u.id
      JOIN role_permissions rp ON rp.role_id = ur.role_id
      JOIN permissions p       ON p.id = rp.permission_id
     WHERE u.id = p_user_id AND u.is_active = 1
       AND (p.resource = '*' OR FIND_IN_SET(p.resource, p_audience) > 0)
       AND p.action IN ('*', 'read')
  ) INTO v_ok;
  RETURN v_ok;
END $$

-- A standing alert (stock low, order due...): one open row per source and title; its text is refreshed in place.
DROP PROCEDURE IF EXISTS sp_notify_upsert $$
CREATE PROCEDURE sp_notify_upsert(
  IN p_source_type VARCHAR(20), IN p_source_id VARCHAR(64), IN p_priority VARCHAR(20),
  IN p_title VARCHAR(255), IN p_message TEXT, IN p_action_url VARCHAR(255), IN p_audience VARCHAR(160))
BEGIN
  DECLARE v_id CHAR(36);
  SELECT id INTO v_id FROM notifications
   WHERE source_type = p_source_type AND source_id <=> p_source_id AND title = p_title AND status <> 'RESOLVED'
   LIMIT 1;
  IF v_id IS NULL THEN
    INSERT INTO notifications (title, message, priority, source_type, source_id, action_url, audience, status)
    VALUES (p_title, p_message, p_priority, p_source_type, p_source_id, p_action_url, p_audience, 'UNREAD');
  ELSE
    UPDATE notifications SET message = p_message WHERE id = v_id AND message <> p_message;
  END IF;
END $$

-- Something that just happened (order confirmed, machine stopped...): always a new row.
DROP PROCEDURE IF EXISTS sp_notify_event $$
CREATE PROCEDURE sp_notify_event(
  IN p_source_type VARCHAR(20), IN p_source_id VARCHAR(64), IN p_priority VARCHAR(20),
  IN p_title VARCHAR(255), IN p_message TEXT, IN p_action_url VARCHAR(255), IN p_audience VARCHAR(160))
BEGIN
  INSERT INTO notifications (title, message, priority, source_type, source_id, action_url, audience, status)
  VALUES (p_title, p_message, p_priority, p_source_type, p_source_id, p_action_url, p_audience, 'UNREAD');
END $$

DROP PROCEDURE IF EXISTS sp_notify_resolve $$
CREATE PROCEDURE sp_notify_resolve(IN p_source_type VARCHAR(20), IN p_source_id VARCHAR(64), IN p_title VARCHAR(255))
BEGIN
  UPDATE notifications SET status = 'RESOLVED'
   WHERE source_type = p_source_type AND source_id <=> p_source_id AND title = p_title AND status <> 'RESOLVED';
END $$

-- Raise or clear the "stock low" alert for one item. Called after every stock movement (trigger on the
-- ledger) so the alert appears the moment stock drops, and by the periodic check. Never blocks the movement.
DROP PROCEDURE IF EXISTS sp_check_low_stock $$
CREATE PROCEDURE sp_check_low_stock(IN p_item_id CHAR(36))
BEGIN
  DECLARE v_name  VARCHAR(255);
  DECLARE v_uom   VARCHAR(20);
  DECLARE v_phys  DECIMAL(16,3);
  DECLARE v_min   DECIMAL(16,3);
  DECLARE v_alert TINYINT;
  DECLARE v_found TINYINT DEFAULT 0;
  DECLARE CONTINUE HANDLER FOR SQLEXCEPTION BEGIN END;

  SELECT name, uom, physical_stock, min_stock_level, reorder_alert, 1
    INTO v_name, v_uom, v_phys, v_min, v_alert, v_found
    FROM v_inventory_status
   WHERE item_id = p_item_id AND tier IN ('RAW_MATERIAL','PACKAGING') AND min_stock_level > 0;
  IF v_found = 1 THEN
    IF v_alert = 1 THEN
      CALL sp_notify_upsert('MATERIAL', p_item_id, 'CRITICAL', CONCAT(v_name, ' stock low'),
        CONCAT(v_phys, ' ', v_uom, ' remaining (Minimum: ', v_min, ' ', v_uom, ')'),
        CONCAT('/items/', p_item_id, '/edit'), 'Stock,PurchaseOrders');
    ELSE
      CALL sp_notify_resolve('MATERIAL', p_item_id, CONCAT(v_name, ' stock low'));
    END IF;
  END IF;
END $$

DROP PROCEDURE IF EXISTS sp_generate_alerts $$
CREATE PROCEDURE sp_generate_alerts()
BEGIN
  DECLARE v_pending INT DEFAULT 0;

  -- 1. low raw-material and packaging stock (the real ledger balance)
  BEGIN
    DECLARE v_item CHAR(36);
    DECLARE v_done TINYINT DEFAULT 0;
    DECLARE cur CURSOR FOR
      SELECT item_id FROM v_inventory_status WHERE tier IN ('RAW_MATERIAL','PACKAGING') AND min_stock_level > 0;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_done = 1;

    OPEN cur;
    low_loop: LOOP
      FETCH cur INTO v_item;
      IF v_done = 1 THEN LEAVE low_loop; END IF;
      CALL sp_check_low_stock(v_item);
    END LOOP;
    CLOSE cur;
  END;

  -- 2. orders due today or overdue
  BEGIN
    DECLARE v_id CHAR(36);
    DECLARE v_no VARCHAR(40);
    DECLARE v_done TINYINT DEFAULT 0;
    DECLARE cur CURSOR FOR
      SELECT id, order_number FROM sales_orders
       WHERE status IN ('APPROVED','CONFIRMED','IN_PRODUCTION') AND DATE(delivery_date) <= CURDATE();
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_done = 1;

    OPEN cur;
    due_loop: LOOP
      FETCH cur INTO v_id, v_no;
      IF v_done = 1 THEN LEAVE due_loop; END IF;
      CALL sp_notify_upsert('ORDER', v_id, 'CRITICAL', CONCAT('Order ', v_no, ' due today'),
        'Production needs to be accelerated for delivery.', CONCAT('/orders/', v_id), 'SalesOrders,WorkOrders');
    END LOOP;
    CLOSE cur;

    -- close alerts for orders that are no longer open / due
    UPDATE notifications n
       SET n.status = 'RESOLVED'
     WHERE n.source_type = 'ORDER' AND n.status <> 'RESOLVED' AND n.title LIKE '% due today'
       AND NOT EXISTS (SELECT 1 FROM sales_orders so
                        WHERE so.id = n.source_id COLLATE utf8mb4_unicode_ci
                          AND so.status IN ('APPROVED','CONFIRMED','IN_PRODUCTION')
                          AND DATE(so.delivery_date) <= CURDATE());
  END;

  -- 3. QC inspections waiting
  SELECT COUNT(*) INTO v_pending FROM quality_inspections WHERE status = 'PENDING';
  UPDATE notifications SET status = 'RESOLVED'
   WHERE source_type = 'QC' AND source_id = 'global' AND status <> 'RESOLVED'
     AND title <> CONCAT(v_pending, ' QC inspections pending');
  IF v_pending > 0 THEN
    CALL sp_notify_upsert('QC', 'global', 'ACTION_REQUIRED', CONCAT(v_pending, ' QC inspections pending'),
      'Quality checks are waiting to be processed.', '/quality?status=PENDING', 'QC');
  END IF;

  -- 4. overdue receivables
  BEGIN
    DECLARE v_cnt INT DEFAULT 0;
    DECLARE v_amt DECIMAL(16,2) DEFAULT 0;
    SELECT COUNT(*), COALESCE(SUM(outstanding),0) INTO v_cnt, v_amt
      FROM sales_invoices WHERE status IN ('UNPAID','PARTIAL') AND due_date < NOW(3);
    UPDATE notifications SET status = 'RESOLVED'
     WHERE source_type = 'FINANCE' AND source_id = 'overdue' AND status <> 'RESOLVED'
       AND title <> CONCAT(v_cnt, ' overdue customer invoices');
    IF v_cnt > 0 THEN
      CALL sp_notify_upsert('FINANCE', 'overdue', 'ACTION_REQUIRED', CONCAT(v_cnt, ' overdue customer invoices'),
        CONCAT('Total overdue: ', v_amt), '/receivables', 'Accounts');
    END IF;
  END;

  -- 5. the suggestions from the smart modules (customers, buying, deadlines, machines). Each lives in its own file;
  --    one that has not been loaded yet (error 1305, procedure does not exist) is skipped.
  BEGIN
    DECLARE CONTINUE HANDLER FOR 1305 BEGIN END;
    CALL sp_alerts_customers();
    CALL sp_alerts_buying();
    CALL sp_alerts_deadlines();
    CALL sp_alerts_machines();
    CALL sp_alerts_qc();
  END;
END $$

-- Runs the alert check when it has not run for p_minutes, so alerts work even before cron is set up.
-- The first caller claims the run, so two open tabs cannot run it twice.
DROP PROCEDURE IF EXISTS sp_alerts_run_if_due $$
CREATE PROCEDURE sp_alerts_run_if_due(IN p_minutes INT)
BEGIN
  DECLARE v_last DATETIME(3);
  DECLARE v_ran TINYINT DEFAULT 0;
  SELECT last_run INTO v_last FROM job_runs WHERE job_name = 'alerts';
  IF v_last IS NULL OR v_last < NOW(3) - INTERVAL IFNULL(p_minutes, 10) MINUTE THEN
    INSERT INTO job_runs (job_name, last_run) VALUES ('alerts', NOW(3))
    ON DUPLICATE KEY UPDATE last_run = NOW(3);
    CALL sp_generate_alerts();
    SET v_ran = 1;
  END IF;
  SELECT v_ran AS ran;
END $$

DROP PROCEDURE IF EXISTS sp_notification_list $$
CREATE PROCEDURE sp_notification_list(IN p_actor CHAR(36), IN p_limit INT)
BEGIN
  IF p_actor IS NULL OR NOT EXISTS (SELECT 1 FROM users WHERE id = p_actor AND is_active = 1) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Forbidden: please sign in';
  END IF;
  SET p_limit = IFNULL(p_limit, 50);   -- LIMIT accepts a variable, not an expression
  SELECT n.id, n.title, n.message, n.priority, IF(r.user_id IS NULL, 'UNREAD', 'READ') AS status,
         n.source_type, n.source_id, n.action_url, n.created_at
    FROM notifications n
    LEFT JOIN notification_reads r ON r.notification_id = n.id AND r.user_id = p_actor
   WHERE n.status <> 'RESOLVED' AND fn_notif_visible(p_actor, n.audience) = 1
   ORDER BY FIELD(n.priority,'CRITICAL','ACTION_REQUIRED','UPDATE','SUCCESS'), n.created_at DESC
   LIMIT p_limit;
END $$

-- How many this person has not read (the bell)
DROP PROCEDURE IF EXISTS sp_notification_unread_count $$
CREATE PROCEDURE sp_notification_unread_count(IN p_actor CHAR(36))
BEGIN
  IF p_actor IS NULL OR NOT EXISTS (SELECT 1 FROM users WHERE id = p_actor AND is_active = 1) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Forbidden: please sign in';
  END IF;
  SELECT COUNT(*) AS unread
    FROM notifications n
    LEFT JOIN notification_reads r ON r.notification_id = n.id AND r.user_id = p_actor
   WHERE n.status <> 'RESOLVED' AND r.user_id IS NULL AND fn_notif_visible(p_actor, n.audience) = 1;
END $$

-- The page polls this every few seconds: the unread count, the server time, and the unread ones raised after
-- p_after (at most 10, oldest first) for pop-ups. With p_after NULL only the count and the time come back, so a
-- freshly opened page does not pop up the old backlog.
DROP PROCEDURE IF EXISTS sp_notification_poll $$
CREATE PROCEDURE sp_notification_poll(IN p_actor CHAR(36), IN p_after DATETIME(3))
BEGIN
  IF p_actor IS NULL OR NOT EXISTS (SELECT 1 FROM users WHERE id = p_actor AND is_active = 1) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Forbidden: please sign in';
  END IF;
  SELECT (SELECT COUNT(*) FROM notifications n
            LEFT JOIN notification_reads r ON r.notification_id = n.id AND r.user_id = p_actor
           WHERE n.status <> 'RESOLVED' AND r.user_id IS NULL AND fn_notif_visible(p_actor, n.audience) = 1) AS unread,
         DATE_FORMAT(NOW(3), '%Y-%m-%d %H:%i:%s.%f') AS server_time;
  SELECT n.id, n.title, n.message, n.priority, n.action_url, n.created_at
    FROM notifications n
    LEFT JOIN notification_reads r ON r.notification_id = n.id AND r.user_id = p_actor
   WHERE p_after IS NOT NULL AND n.created_at > p_after AND n.status <> 'RESOLVED'
     AND r.user_id IS NULL AND fn_notif_visible(p_actor, n.audience) = 1
   ORDER BY n.created_at ASC
   LIMIT 10;
END $$

-- READ and UNREAD are per person; RESOLVED (dismiss) closes it for everyone. p_id NULL with READ marks all read.
DROP PROCEDURE IF EXISTS sp_notification_set_status $$
CREATE PROCEDURE sp_notification_set_status(IN p_actor CHAR(36), IN p_id CHAR(36), IN p_status VARCHAR(20))
BEGIN
  IF p_actor IS NULL OR NOT EXISTS (SELECT 1 FROM users WHERE id = p_actor AND is_active = 1) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Forbidden: please sign in';
  END IF;
  IF p_status NOT IN ('UNREAD','READ','RESOLVED') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid notification status';
  END IF;
  IF p_id IS NULL THEN
    IF p_status = 'READ' THEN
      INSERT IGNORE INTO notification_reads (notification_id, user_id)
      SELECT n.id, p_actor FROM notifications n
       WHERE n.status <> 'RESOLVED' AND fn_notif_visible(p_actor, n.audience) = 1;
    END IF;
  ELSE
    IF NOT EXISTS (SELECT 1 FROM notifications n WHERE n.id = p_id AND fn_notif_visible(p_actor, n.audience) = 1) THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Notification not found';
    END IF;
    IF p_status = 'READ' THEN
      INSERT IGNORE INTO notification_reads (notification_id, user_id) VALUES (p_id, p_actor);
    ELSEIF p_status = 'UNREAD' THEN
      DELETE FROM notification_reads WHERE notification_id = p_id AND user_id = p_actor;
    ELSE
      UPDATE notifications SET status = 'RESOLVED' WHERE id = p_id;
    END IF;
  END IF;
END $$

-- ----------------------------- Web Push ---------------------------------------
-- A browser that agreed to push registers here; the PHP sender reads what is waiting and who should get it.
DROP PROCEDURE IF EXISTS sp_push_subscribe $$
CREATE PROCEDURE sp_push_subscribe(
  IN p_actor CHAR(36), IN p_endpoint VARCHAR(600), IN p_p256dh VARCHAR(255), IN p_auth VARCHAR(255), IN p_ua VARCHAR(255))
BEGIN
  IF p_actor IS NULL OR NOT EXISTS (SELECT 1 FROM users WHERE id = p_actor AND is_active = 1) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Forbidden: please sign in';
  END IF;
  IF p_endpoint IS NULL OR p_endpoint NOT LIKE 'https://%' OR IFNULL(p_p256dh, '') = '' OR IFNULL(p_auth, '') = '' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'That is not a valid push subscription';
  END IF;
  INSERT INTO push_subscriptions (user_id, endpoint, endpoint_hash, p256dh, auth, user_agent)
  VALUES (p_actor, p_endpoint, SHA2(p_endpoint, 256), p_p256dh, p_auth, LEFT(p_ua, 255))
  ON DUPLICATE KEY UPDATE user_id = p_actor, p256dh = p_p256dh, auth = p_auth, user_agent = LEFT(p_ua, 255);
END $$

DROP PROCEDURE IF EXISTS sp_push_unsubscribe $$
CREATE PROCEDURE sp_push_unsubscribe(IN p_actor CHAR(36), IN p_endpoint VARCHAR(600))
BEGIN
  IF p_actor IS NULL OR NOT EXISTS (SELECT 1 FROM users WHERE id = p_actor AND is_active = 1) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Forbidden: please sign in';
  END IF;
  DELETE FROM push_subscriptions WHERE endpoint_hash = SHA2(p_endpoint, 256) AND user_id = p_actor;
END $$

-- How many browsers this person has registered, and whether this one (by endpoint) is among them
DROP PROCEDURE IF EXISTS sp_push_status $$
CREATE PROCEDURE sp_push_status(IN p_actor CHAR(36), IN p_endpoint VARCHAR(600))
BEGIN
  IF p_actor IS NULL OR NOT EXISTS (SELECT 1 FROM users WHERE id = p_actor AND is_active = 1) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Forbidden: please sign in';
  END IF;
  SELECT COUNT(*) AS browsers,
         IFNULL(SUM(endpoint_hash = SHA2(IFNULL(p_endpoint, ''), 256)), 0) AS this_browser
    FROM push_subscriptions WHERE user_id = p_actor;
END $$

-- This person's registered browsers (for the "send me a test" button)
DROP PROCEDURE IF EXISTS sp_push_mine $$
CREATE PROCEDURE sp_push_mine(IN p_actor CHAR(36))
BEGIN
  IF p_actor IS NULL OR NOT EXISTS (SELECT 1 FROM users WHERE id = p_actor AND is_active = 1) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Forbidden: please sign in';
  END IF;
  SELECT id, endpoint, p256dh, auth FROM push_subscriptions WHERE user_id = p_actor ORDER BY created_at;
END $$

-- Notifications whose push has not gone out yet (anything older than a day is dropped, not sent late)
DROP PROCEDURE IF EXISTS sp_push_pending $$
CREATE PROCEDURE sp_push_pending(IN p_limit INT)
BEGIN
  UPDATE notifications SET push_state = 1 WHERE push_state = 0 AND (status = 'RESOLVED' OR created_at < NOW(3) - INTERVAL 1 DAY);
  SET p_limit = IFNULL(p_limit, 20);
  SELECT id, title, message, priority, action_url FROM notifications
   WHERE push_state = 0 ORDER BY created_at ASC LIMIT p_limit;
END $$

-- The browsers that should get one notification: people who may see it and have not read it
DROP PROCEDURE IF EXISTS sp_push_targets $$
CREATE PROCEDURE sp_push_targets(IN p_notification_id CHAR(36))
BEGIN
  SELECT s.id, s.endpoint, s.p256dh, s.auth
    FROM notifications n
    JOIN push_subscriptions s ON 1 = 1
    JOIN users u ON u.id = s.user_id AND u.is_active = 1
   WHERE n.id = p_notification_id AND fn_notif_visible(s.user_id, n.audience) = 1
     AND NOT EXISTS (SELECT 1 FROM notification_reads r WHERE r.notification_id = n.id AND r.user_id = s.user_id);
END $$

DROP PROCEDURE IF EXISTS sp_push_done $$
CREATE PROCEDURE sp_push_done(IN p_notification_id CHAR(36))
BEGIN
  UPDATE notifications SET push_state = 1 WHERE id = p_notification_id;
END $$

DROP PROCEDURE IF EXISTS sp_push_ok $$
CREATE PROCEDURE sp_push_ok(IN p_subscription_id CHAR(36))
BEGIN
  UPDATE push_subscriptions SET last_ok_at = NOW(3) WHERE id = p_subscription_id;
END $$

-- The push service said the browser is gone (404 / 410)
DROP PROCEDURE IF EXISTS sp_push_drop $$
CREATE PROCEDURE sp_push_drop(IN p_subscription_id CHAR(36))
BEGIN
  DELETE FROM push_subscriptions WHERE id = p_subscription_id;
END $$

-- =============================================================================
-- SETTINGS (company name and GSTIN for invoice prints, state code for GST split, defaults)
-- Readable by Accounts and by user admins; only user admins can change them.
-- =============================================================================
DROP PROCEDURE IF EXISTS sp_settings_list $$
CREATE PROCEDURE sp_settings_list(IN p_actor CHAR(36))
BEGIN
  IF p_actor IS NULL OR (fn_can(p_actor, 'Accounts', 'read') = 0 AND fn_can(p_actor, 'users', 'read') = 0) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Forbidden: you do not have permission for this action';
  END IF;
  SELECT setting_key, setting_value, description FROM system_settings ORDER BY setting_key;
END $$

DROP PROCEDURE IF EXISTS sp_settings_set $$
CREATE PROCEDURE sp_settings_set(IN p_actor CHAR(36), IN p_key VARCHAR(64), IN p_value VARCHAR(255))
BEGIN
  CALL sp_require_permission(p_actor, 'users', 'update');
  IF NOT EXISTS (SELECT 1 FROM system_settings WHERE setting_key = p_key) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Unknown setting';
  END IF;
  IF p_key = 'company_gstin' AND p_value <> '' AND p_value NOT REGEXP '^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][A-Z0-9]Z[A-Z0-9]$' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The GSTIN is not in the 15-character GST format';
  END IF;
  IF p_key = 'company_state_code' AND p_value NOT REGEXP '^[0-9]{2}$' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The state code is two digits, for example 24';
  END IF;
  IF p_key = 'default_tax_rate' AND (p_value NOT REGEXP '^[0-9]{1,2}(\\.[0-9]{1,2})?$') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The default tax rate is a percentage such as 18 or 12.5';
  END IF;
  IF p_key = 'default_credit_days' AND p_value NOT REGEXP '^[0-9]{1,3}$' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Default credit days is a whole number';
  END IF;
  IF p_key IN ('reorder_min_orders', 'reorder_soon_days', 'due_warning_days', 'buffer_days', 'issue_lot_sacks',
               'default_metres_per_day', 'cover_days', 'machine_low_hours', 'qc_reading_minutes') AND p_value NOT REGEXP '^[0-9]{1,6}$' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'This setting is a whole number';
  END IF;
  UPDATE system_settings SET setting_value = p_value WHERE setting_key = p_key;
  CALL sp_audit(p_actor, 'SETTING_CHANGED', NULL, JSON_OBJECT('key', p_key));
END $$

DELIMITER ;

-- Optional: run the alert check inside MySQL itself every 5 minutes.
-- Needs event_scheduler = ON on the server (often disabled on shared hosting; use cron there).
-- CREATE EVENT IF NOT EXISTS ev_hpos_alerts ON SCHEDULE EVERY 5 MINUTE DO CALL sp_generate_alerts();
-- CREATE EVENT IF NOT EXISTS ev_hpos_purge_sessions ON SCHEDULE EVERY 1 HOUR DO CALL sp_auth_purge_sessions();
