-- =============================================================================
-- HPOS Factory OS  |  11_books_screens.sql  -  what the Books screens call: accounts, manual vouchers,
-- opening balances, credit and debit notes, the day book, ledgers, trial balance, profit and loss,
-- balance sheet, GST registers and party statements.
-- Reading needs Accounts:read; entering needs Accounts:create or update; cancelling needs Accounts:delete.
-- Needs 10_books.sql.
-- =============================================================================
USE hpos;

DELIMITER $$

-- ----------------------------- chart of accounts -----------------------------------
DROP PROCEDURE IF EXISTS sp_gl_account_list $$
CREATE PROCEDURE sp_gl_account_list(IN p_actor CHAR(36), IN p_asof DATE)
BEGIN
  CALL sp_require_permission(p_actor, 'Accounts', 'read');
  SELECT a.id, a.code, a.name, a.acct_type, a.subtype, a.is_system, a.is_active, a.notes,
         IFNULL(t.dr, 0) AS debit_total, IFNULL(t.cr, 0) AS credit_total, IFNULL(t.dr, 0) - IFNULL(t.cr, 0) AS balance
    FROM gl_accounts a
    LEFT JOIN (SELECT e.account_id, SUM(e.debit) AS dr, SUM(e.credit) AS cr
                 FROM gl_entries e JOIN gl_vouchers v ON v.id = e.voucher_id
                WHERE v.voucher_date <= IFNULL(p_asof, CURDATE()) GROUP BY e.account_id) t ON t.account_id = a.id
   ORDER BY a.code;
END $$

-- Active accounts for a dropdown. p_kind: 'ALL' or 'CASHBANK' (cash, bank, cheques, wallet)
DROP PROCEDURE IF EXISTS sp_gl_account_pick $$
CREATE PROCEDURE sp_gl_account_pick(IN p_actor CHAR(36), IN p_kind VARCHAR(10))
BEGIN
  CALL sp_require_permission(p_actor, 'Accounts', 'read');
  SELECT id, code, name, acct_type, subtype FROM gl_accounts
   WHERE is_active = 1 AND (IFNULL(p_kind, 'ALL') = 'ALL' OR subtype IN ('CASH','BANK','CHEQUE','WALLET'))
   ORDER BY code;
END $$

DROP PROCEDURE IF EXISTS sp_gl_account_get $$
CREATE PROCEDURE sp_gl_account_get(IN p_actor CHAR(36), IN p_id CHAR(36))
BEGIN
  CALL sp_require_permission(p_actor, 'Accounts', 'read');
  SELECT a.*, (SELECT COUNT(*) FROM gl_entries e WHERE e.account_id = a.id) AS entry_count FROM gl_accounts a WHERE a.id = p_id;
END $$

DROP PROCEDURE IF EXISTS sp_gl_account_save $$
CREATE PROCEDURE sp_gl_account_save(
  IN p_actor CHAR(36), IN p_id CHAR(36), IN p_code VARCHAR(20), IN p_name VARCHAR(120), IN p_type VARCHAR(12),
  IN p_subtype VARCHAR(20), IN p_notes VARCHAR(255), OUT p_out CHAR(36))
BEGIN
  DECLARE v_old_type VARCHAR(12); DECLARE v_old_sub VARCHAR(20); DECLARE v_system TINYINT; DECLARE v_entries INT DEFAULT 0;
  DECLARE v_ok TINYINT DEFAULT 0;
  DECLARE v_id CHAR(36) DEFAULT NULLIF(p_id, '');
  DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;

  CALL sp_require_permission(p_actor, 'Accounts', IF(v_id IS NULL, 'create', 'update'));
  SET p_name = TRIM(IFNULL(p_name, ''));
  IF p_name = '' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The account needs a name'; END IF;

  IF v_id IS NOT NULL THEN
    SELECT acct_type, subtype, is_system INTO v_old_type, v_old_sub, v_system FROM gl_accounts WHERE id = v_id;
    IF v_old_type IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Account not found'; END IF;
    SELECT COUNT(*) INTO v_entries FROM gl_entries WHERE account_id = v_id;
    IF v_system = 1 THEN
      -- the accounts the documents post to keep their code, type and role; only the name and note can change
      UPDATE gl_accounts SET name = p_name, notes = NULLIF(p_notes, '') WHERE id = v_id;
      SET p_out = v_id;
    ELSE
      IF p_type <> v_old_type AND v_entries > 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'This account already has entries, so its type cannot change';
      END IF;
    END IF;
  END IF;

  IF v_id IS NULL OR v_system = 0 THEN
    IF p_type NOT IN ('ASSET','LIABILITY','EQUITY','INCOME','EXPENSE') THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Choose the account type';
    END IF;
    IF p_code IS NULL OR p_code NOT REGEXP '^[0-9A-Za-z.-]{2,20}$' THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The code is 2 to 20 letters, digits, dots or dashes';
    END IF;
    SET v_ok = CASE p_type
      WHEN 'ASSET'     THEN p_subtype IN ('CASH','BANK','CHEQUE','WALLET','FIXED_ASSET','GENERAL')
      WHEN 'LIABILITY' THEN p_subtype IN ('GENERAL')
      WHEN 'EQUITY'    THEN p_subtype IN ('GENERAL')
      WHEN 'INCOME'    THEN p_subtype IN ('OTHER_INCOME')
      WHEN 'EXPENSE'   THEN p_subtype IN ('DIRECT_EXP','INDIRECT_EXP')
      ELSE 0 END;
    IF v_ok = 0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'That kind of account is not allowed for this type'; END IF;
    IF EXISTS (SELECT 1 FROM gl_accounts WHERE code = p_code AND (v_id IS NULL OR id <> v_id)) THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'That code is already used by another account';
    END IF;
    IF v_id IS NULL THEN
      SET p_out = UUID();
      INSERT INTO gl_accounts (id, code, name, acct_type, subtype, notes) VALUES (p_out, p_code, p_name, p_type, p_subtype, NULLIF(p_notes, ''));
    ELSE
      UPDATE gl_accounts SET code = p_code, name = p_name, acct_type = p_type, subtype = p_subtype, notes = NULLIF(p_notes, '') WHERE id = v_id;
      SET p_out = v_id;
    END IF;
  END IF;
END $$

DROP PROCEDURE IF EXISTS sp_gl_account_set_active $$
CREATE PROCEDURE sp_gl_account_set_active(IN p_actor CHAR(36), IN p_id CHAR(36), IN p_active TINYINT)
BEGIN
  DECLARE v_sys TINYINT; DECLARE v_bal DECIMAL(18,2);
  CALL sp_require_permission(p_actor, 'Accounts', 'update');
  SELECT is_system INTO v_sys FROM gl_accounts WHERE id = p_id;
  IF v_sys IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Account not found'; END IF;
  IF v_sys = 1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A system account cannot be switched off'; END IF;
  SELECT COALESCE(SUM(debit - credit), 0) INTO v_bal FROM gl_entries WHERE account_id = p_id;
  IF p_active = 0 AND v_bal <> 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'This account still has a balance; clear it before switching it off';
  END IF;
  UPDATE gl_accounts SET is_active = IF(p_active = 0, 0, 1) WHERE id = p_id;
END $$

-- Which cash or bank account a payment goes through (instead of the one its mode implies)
DROP PROCEDURE IF EXISTS sp_payment_set_account $$
CREATE PROCEDURE sp_payment_set_account(IN p_actor CHAR(36), IN p_payment_id CHAR(36), IN p_account_id CHAR(36))
BEGIN
  DECLARE v_status VARCHAR(20);
  CALL sp_require_permission(p_actor, 'Accounts', 'update');
  SELECT status INTO v_status FROM payment_entries WHERE id = p_payment_id;
  IF v_status IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payment not found'; END IF;
  IF v_status <> 'DRAFT' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The account cannot change once the payment is posted'; END IF;
  IF NULLIF(p_account_id, '') IS NOT NULL AND NOT EXISTS (
       SELECT 1 FROM gl_accounts WHERE id = p_account_id AND is_active = 1 AND subtype IN ('CASH','BANK','CHEQUE','WALLET')) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Choose a cash, bank, cheque or wallet account';
  END IF;
  UPDATE payment_entries SET account_id = NULLIF(p_account_id, '') WHERE id = p_payment_id;
END $$

-- ----------------------------- vouchers ---------------------------------------------
-- p_lines is a JSON array: [{"account":"<id>","debit":100,"credit":0,"partyType":"CUSTOMER","partyId":"<id>","note":""}]
-- Shared checks for manual vouchers and opening balances. Raises on the first problem found.
DROP PROCEDURE IF EXISTS sp_voucher_check_lines $$
CREATE PROCEDURE sp_voucher_check_lines(IN p_lines JSON)
BEGIN
  DECLARE v_n INT DEFAULT IFNULL(JSON_LENGTH(p_lines), 0);
  DECLARE v_found INT DEFAULT 0;
  DECLARE v_bad INT DEFAULT 0;

  IF v_n < 1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Add at least one line'; END IF;
  SELECT COUNT(*) INTO v_found
    FROM JSON_TABLE(p_lines, '$[*]' COLUMNS (account CHAR(36) PATH '$.account')) jt
    JOIN gl_accounts a ON a.id = (jt.account COLLATE utf8mb4_unicode_ci) AND a.is_active = 1;
  IF v_found <> v_n THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'One or more accounts were not found, or are switched off'; END IF;

  SELECT COUNT(*) INTO v_bad
    FROM JSON_TABLE(p_lines, '$[*]' COLUMNS (
           debit DECIMAL(16,2) PATH '$.debit' DEFAULT '0' ON EMPTY DEFAULT '0' ON ERROR,
           credit DECIMAL(16,2) PATH '$.credit' DEFAULT '0' ON EMPTY DEFAULT '0' ON ERROR)) jt
   WHERE jt.debit < 0 OR jt.credit < 0 OR (jt.debit > 0 AND jt.credit > 0) OR (jt.debit = 0 AND jt.credit = 0);
  IF v_bad > 0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Each line needs an amount in either Debit or Credit (not both, not negative)'; END IF;

  -- debtors and creditors lines must say which customer or supplier
  SELECT COUNT(*) INTO v_bad
    FROM JSON_TABLE(p_lines, '$[*]' COLUMNS (account CHAR(36) PATH '$.account', partyType VARCHAR(10) PATH '$.partyType', partyId CHAR(36) PATH '$.partyId')) jt
    JOIN gl_accounts a ON a.id = (jt.account COLLATE utf8mb4_unicode_ci)
   WHERE (a.subtype = 'DEBTORS'   AND NOT EXISTS (SELECT 1 FROM customers c WHERE jt.partyType = 'CUSTOMER' AND c.id = (jt.partyId COLLATE utf8mb4_unicode_ci)))
      OR (a.subtype = 'CREDITORS' AND NOT EXISTS (SELECT 1 FROM suppliers s WHERE jt.partyType = 'SUPPLIER' AND s.id = (jt.partyId COLLATE utf8mb4_unicode_ci)));
  IF v_bad > 0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Lines on Sundry Debtors or Sundry Creditors must name the customer or supplier'; END IF;
END $$

DROP PROCEDURE IF EXISTS sp_voucher_create $$
CREATE PROCEDURE sp_voucher_create(
  IN p_actor CHAR(36), IN p_type VARCHAR(20), IN p_date DATE, IN p_narration VARCHAR(500), IN p_reference VARCHAR(80),
  IN p_lines JSON, IN p_ip VARCHAR(64), OUT p_out CHAR(36))
BEGIN
  DECLARE v_n INT DEFAULT IFNULL(JSON_LENGTH(p_lines), 0);
  DECLARE v_dr DECIMAL(18,2); DECLARE v_cr DECIMAL(18,2); DECLARE v_cnt INT DEFAULT 0;
  DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;

  CALL sp_require_permission(p_actor, 'Accounts', 'create');
  IF p_type NOT IN ('JOURNAL','CONTRA','EXPENSE','RECEIPT','PAYMENT') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Choose the voucher type';
  END IF;
  IF p_date IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The date is required'; END IF;
  IF p_date > CURDATE() THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The date cannot be in the future'; END IF;
  IF v_n < 2 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A voucher needs at least two lines'; END IF;
  CALL sp_voucher_check_lines(p_lines);

  SELECT SUM(jt.debit), SUM(jt.credit) INTO v_dr, v_cr
    FROM JSON_TABLE(p_lines, '$[*]' COLUMNS (debit DECIMAL(16,2) PATH '$.debit' DEFAULT '0' ON EMPTY, credit DECIMAL(16,2) PATH '$.credit' DEFAULT '0' ON EMPTY)) jt;
  IF v_dr <> v_cr THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The voucher does not balance: total debits must equal total credits';
  END IF;

  IF p_type = 'CONTRA' THEN
    SELECT COUNT(*) INTO v_cnt FROM JSON_TABLE(p_lines, '$[*]' COLUMNS (account CHAR(36) PATH '$.account')) jt
      JOIN gl_accounts a ON a.id = (jt.account COLLATE utf8mb4_unicode_ci) WHERE a.subtype IN ('CASH','BANK','CHEQUE','WALLET');
    IF v_n <> 2 OR v_cnt <> 2 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A contra moves money between two cash, bank, cheque or wallet accounts'; END IF;
  ELSEIF p_type = 'RECEIPT' THEN
    SELECT COUNT(*) INTO v_cnt FROM JSON_TABLE(p_lines, '$[*]' COLUMNS (account CHAR(36) PATH '$.account', debit DECIMAL(16,2) PATH '$.debit' DEFAULT '0' ON EMPTY)) jt
      JOIN gl_accounts a ON a.id = (jt.account COLLATE utf8mb4_unicode_ci) WHERE a.subtype IN ('CASH','BANK','CHEQUE','WALLET') AND jt.debit > 0;
    IF v_cnt = 0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A receipt must put money into a cash or bank account (debit it)'; END IF;
  ELSEIF p_type = 'PAYMENT' THEN
    SELECT COUNT(*) INTO v_cnt FROM JSON_TABLE(p_lines, '$[*]' COLUMNS (account CHAR(36) PATH '$.account', credit DECIMAL(16,2) PATH '$.credit' DEFAULT '0' ON EMPTY)) jt
      JOIN gl_accounts a ON a.id = (jt.account COLLATE utf8mb4_unicode_ci) WHERE a.subtype IN ('CASH','BANK','CHEQUE','WALLET') AND jt.credit > 0;
    IF v_cnt = 0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A payment must take money out of a cash or bank account (credit it)'; END IF;
  ELSEIF p_type = 'EXPENSE' THEN
    SELECT COUNT(*) INTO v_cnt FROM JSON_TABLE(p_lines, '$[*]' COLUMNS (account CHAR(36) PATH '$.account', debit DECIMAL(16,2) PATH '$.debit' DEFAULT '0' ON EMPTY)) jt
      JOIN gl_accounts a ON a.id = (jt.account COLLATE utf8mb4_unicode_ci) WHERE a.acct_type = 'EXPENSE' AND jt.debit > 0;
    IF v_cnt = 0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'An expense must debit an expense account'; END IF;
  END IF;

  START TRANSACTION;
    CALL sp_gl_begin(p_type, p_date, p_narration, p_reference, 'MANUAL', NULL, p_actor, p_out);
    INSERT INTO gl_entries (voucher_id, line_no, account_id, debit, credit, party_type, party_id, note)
    SELECT p_out, jt.n, a.id, jt.debit, jt.credit,
           IF(a.subtype IN ('DEBTORS','CREDITORS'), jt.partyType, NULL), IF(a.subtype IN ('DEBTORS','CREDITORS'), jt.partyId, NULL), LEFT(jt.note, 255)
      FROM JSON_TABLE(p_lines, '$[*]' COLUMNS (
             n FOR ORDINALITY, account CHAR(36) PATH '$.account',
             debit DECIMAL(16,2) PATH '$.debit' DEFAULT '0' ON EMPTY, credit DECIMAL(16,2) PATH '$.credit' DEFAULT '0' ON EMPTY,
             partyType VARCHAR(10) PATH '$.partyType', partyId CHAR(36) PATH '$.partyId', note VARCHAR(255) PATH '$.note')) jt
      JOIN gl_accounts a ON a.id = (jt.account COLLATE utf8mb4_unicode_ci);
    CALL sp_gl_end(p_out);
    CALL sp_audit(p_actor, 'VOUCHER_POSTED', p_ip, JSON_OBJECT('voucherId', p_out, 'type', p_type));
  COMMIT;
END $$

-- Opening balances at the start of the books: one voucher, balanced against "Opening Balance Difference"
DROP PROCEDURE IF EXISTS sp_opening_balances_save $$
CREATE PROCEDURE sp_opening_balances_save(
  IN p_actor CHAR(36), IN p_date DATE, IN p_lines JSON, IN p_ip VARCHAR(64), OUT p_out CHAR(36))
BEGIN
  DECLARE v_dr DECIMAL(18,2); DECLARE v_cr DECIMAL(18,2); DECLARE v_diff DECIMAL(18,2);
  DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;

  CALL sp_require_permission(p_actor, 'Accounts', 'create');
  IF p_date IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The opening date is required'; END IF;
  IF p_date > CURDATE() THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The date cannot be in the future'; END IF;
  IF EXISTS (SELECT 1 FROM gl_vouchers WHERE voucher_type = 'OPENING' AND status = 'POSTED' AND reverses_id IS NULL) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Opening balances are already entered. Cancel that voucher first to enter them again';
  END IF;
  CALL sp_voucher_check_lines(p_lines);
  SELECT SUM(jt.debit), SUM(jt.credit) INTO v_dr, v_cr
    FROM JSON_TABLE(p_lines, '$[*]' COLUMNS (debit DECIMAL(16,2) PATH '$.debit' DEFAULT '0' ON EMPTY, credit DECIMAL(16,2) PATH '$.credit' DEFAULT '0' ON EMPTY)) jt;
  SET v_diff = v_dr - v_cr;

  START TRANSACTION;
    CALL sp_gl_begin('OPENING', p_date, 'Opening balances', NULL, 'MANUAL', NULL, p_actor, p_out);
    INSERT INTO gl_entries (voucher_id, line_no, account_id, debit, credit, party_type, party_id, note)
    SELECT p_out, jt.n, a.id, jt.debit, jt.credit,
           IF(a.subtype IN ('DEBTORS','CREDITORS'), jt.partyType, NULL), IF(a.subtype IN ('DEBTORS','CREDITORS'), jt.partyId, NULL), 'Opening balance'
      FROM JSON_TABLE(p_lines, '$[*]' COLUMNS (
             n FOR ORDINALITY, account CHAR(36) PATH '$.account',
             debit DECIMAL(16,2) PATH '$.debit' DEFAULT '0' ON EMPTY, credit DECIMAL(16,2) PATH '$.credit' DEFAULT '0' ON EMPTY,
             partyType VARCHAR(10) PATH '$.partyType', partyId CHAR(36) PATH '$.partyId')) jt
      JOIN gl_accounts a ON a.id = (jt.account COLLATE utf8mb4_unicode_ci);
    IF v_diff > 0 THEN CALL sp_gl_line(p_out, fn_gl_id('3900'), 0, v_diff, NULL, NULL, 'Balancing figure'); END IF;
    IF v_diff < 0 THEN CALL sp_gl_line(p_out, fn_gl_id('3900'), -v_diff, 0, NULL, NULL, 'Balancing figure'); END IF;
    CALL sp_gl_end(p_out);
    CALL sp_audit(p_actor, 'OPENING_BALANCES_POSTED', p_ip, JSON_OBJECT('voucherId', p_out));
  COMMIT;
END $$

-- Cancels a voucher entered by hand. Vouchers that come from an invoice, bill, payment or note are cancelled through that document.
DROP PROCEDURE IF EXISTS sp_voucher_cancel $$
CREATE PROCEDURE sp_voucher_cancel(IN p_actor CHAR(36), IN p_id CHAR(36), IN p_reason VARCHAR(200), IN p_ip VARCHAR(64))
BEGIN
  DECLARE v_src VARCHAR(30); DECLARE v_rev CHAR(36);
  DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;
  CALL sp_require_permission(p_actor, 'Accounts', 'delete');
  SELECT source_type INTO v_src FROM gl_vouchers WHERE id = p_id;
  IF v_src IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Voucher not found'; END IF;
  IF v_src <> 'MANUAL' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'This voucher was made by an invoice, bill, payment or note. Cancel that document instead';
  END IF;
  START TRANSACTION;
    CALL sp_gl_reverse(p_id, p_reason, p_actor, v_rev);
    CALL sp_audit(p_actor, 'VOUCHER_CANCELLED', p_ip, JSON_OBJECT('voucherId', p_id));
  COMMIT;
END $$

DROP PROCEDURE IF EXISTS sp_voucher_get $$
CREATE PROCEDURE sp_voucher_get(IN p_actor CHAR(36), IN p_id CHAR(36))
BEGIN
  CALL sp_require_permission(p_actor, 'Accounts', 'read');
  SELECT v.*, u.name AS created_by_name, o.voucher_number AS reverses_number, r.voucher_number AS reversed_by_number
    FROM gl_vouchers v
    LEFT JOIN users u ON u.id = v.created_by_id
    LEFT JOIN gl_vouchers o ON o.id = v.reverses_id
    LEFT JOIN gl_vouchers r ON r.id = v.reversed_by_id
   WHERE v.id = p_id;
  SELECT e.line_no, a.id AS account_id, a.code, a.name AS account_name, e.debit, e.credit, e.party_type, e.party_id, e.note,
         COALESCE(c.name, s.name) AS party_name
    FROM gl_entries e JOIN gl_accounts a ON a.id = e.account_id
    LEFT JOIN customers c ON e.party_type = 'CUSTOMER' AND c.id = e.party_id
    LEFT JOIN suppliers s ON e.party_type = 'SUPPLIER' AND s.id = e.party_id
   WHERE e.voucher_id = p_id ORDER BY e.line_no;
END $$

-- ----------------------------- reports ------------------------------------------------
DROP PROCEDURE IF EXISTS sp_gl_day_book $$
CREATE PROCEDURE sp_gl_day_book(IN p_actor CHAR(36), IN p_from DATE, IN p_to DATE, IN p_type VARCHAR(20), IN p_q VARCHAR(100))
BEGIN
  DECLARE v_like VARCHAR(110) DEFAULT CONCAT('%', REPLACE(REPLACE(IFNULL(p_q, ''), '%', ''), '_', ''), '%');
  CALL sp_require_permission(p_actor, 'Accounts', 'read');
  SELECT v.id AS voucher_id, v.voucher_number, v.voucher_date, v.voucher_type, v.narration, v.reference, v.status, v.source_type,
         v.reverses_id, e.line_no, a.code AS account_code, a.name AS account_name, e.debit, e.credit,
         COALESCE(c.name, s.name) AS party_name
    FROM gl_vouchers v
    JOIN gl_entries e ON e.voucher_id = v.id
    JOIN gl_accounts a ON a.id = e.account_id
    LEFT JOIN customers c ON e.party_type = 'CUSTOMER' AND c.id = e.party_id
    LEFT JOIN suppliers s ON e.party_type = 'SUPPLIER' AND s.id = e.party_id
   WHERE v.voucher_date BETWEEN IFNULL(p_from, '1900-01-01') AND IFNULL(p_to, CURDATE())
     AND (NULLIF(p_type, '') IS NULL OR v.voucher_type = p_type)
     AND (IFNULL(p_q, '') = '' OR v.voucher_number LIKE v_like OR v.narration LIKE v_like OR v.reference LIKE v_like)
   ORDER BY v.voucher_date, v.created_at, v.id, e.line_no
   LIMIT 3000;
END $$

-- One account's ledger with a running balance (debit positive). For a party, pass the party to see only theirs.
DROP PROCEDURE IF EXISTS sp_gl_ledger $$
CREATE PROCEDURE sp_gl_ledger(
  IN p_actor CHAR(36), IN p_account CHAR(36), IN p_from DATE, IN p_to DATE, IN p_party_type VARCHAR(10), IN p_party_id CHAR(36))
BEGIN
  DECLARE v_open DECIMAL(18,2) DEFAULT 0;
  DECLARE v_from DATE DEFAULT IFNULL(p_from, '1900-01-01');
  DECLARE v_to DATE DEFAULT IFNULL(p_to, CURDATE());
  DECLARE v_pt VARCHAR(10) DEFAULT NULLIF(p_party_type, '');
  CALL sp_require_permission(p_actor, 'Accounts', 'read');
  IF NOT EXISTS (SELECT 1 FROM gl_accounts WHERE id = p_account) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Account not found';
  END IF;
  SELECT COALESCE(SUM(e.debit - e.credit), 0) INTO v_open
    FROM gl_entries e JOIN gl_vouchers v ON v.id = e.voucher_id
   WHERE e.account_id = p_account AND v.voucher_date < v_from
     AND (v_pt IS NULL OR (e.party_type = v_pt AND e.party_id = p_party_id));
  SELECT a.id, a.code, a.name, a.acct_type, a.subtype, v_open AS opening FROM gl_accounts a WHERE a.id = p_account;
  SELECT v.id AS voucher_id, v.voucher_number, v.voucher_date, v.voucher_type, v.narration, v.reference, v.status, e.debit, e.credit,
         v_open + SUM(e.debit - e.credit) OVER (ORDER BY v.voucher_date, v.created_at, v.id, e.line_no ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW) AS balance,
         (SELECT GROUP_CONCAT(DISTINCT a2.name ORDER BY a2.name SEPARATOR ', ')
            FROM gl_entries e2 JOIN gl_accounts a2 ON a2.id = e2.account_id
           WHERE e2.voucher_id = e.voucher_id AND e2.account_id <> e.account_id) AS against
    FROM gl_entries e JOIN gl_vouchers v ON v.id = e.voucher_id
   WHERE e.account_id = p_account AND v.voucher_date BETWEEN v_from AND v_to
     AND (v_pt IS NULL OR (e.party_type = v_pt AND e.party_id = p_party_id))
   ORDER BY v.voucher_date, v.created_at, v.id, e.line_no;
END $$

DROP PROCEDURE IF EXISTS sp_gl_trial_balance $$
CREATE PROCEDURE sp_gl_trial_balance(IN p_actor CHAR(36), IN p_asof DATE)
BEGIN
  CALL sp_require_permission(p_actor, 'Accounts', 'read');
  SELECT a.id, a.code, a.name, a.acct_type, a.subtype, t.dr AS debit_total, t.cr AS credit_total, t.dr - t.cr AS balance
    FROM gl_accounts a
    JOIN (SELECT e.account_id, SUM(e.debit) AS dr, SUM(e.credit) AS cr
            FROM gl_entries e JOIN gl_vouchers v ON v.id = e.voucher_id
           WHERE v.voucher_date <= IFNULL(p_asof, CURDATE()) GROUP BY e.account_id) t ON t.account_id = a.id
   ORDER BY a.code;
END $$

-- Income and expense accounts for a period, and the stock valuations the cost of goods needs.
-- Opening stock = stock before the period + stock entered as opening stock during it (that is capital, not profit).
DROP PROCEDURE IF EXISTS sp_gl_profit_loss $$
CREATE PROCEDURE sp_gl_profit_loss(IN p_actor CHAR(36), IN p_from DATE, IN p_to DATE)
BEGIN
  DECLARE v_from DATE DEFAULT IFNULL(p_from, DATE_FORMAT(CURDATE(), '%Y-%m-01'));
  DECLARE v_to DATE DEFAULT IFNULL(p_to, CURDATE());
  DECLARE v_start DATETIME(3) DEFAULT CAST(v_from AS DATETIME(3));
  DECLARE v_end DATETIME(3) DEFAULT CAST(CONCAT(v_to, ' 23:59:59.999') AS DATETIME(3));
  CALL sp_require_permission(p_actor, 'Accounts', 'read');
  SELECT a.code, a.name, a.acct_type, a.subtype,
         IF(a.acct_type = 'INCOME', t.cr - t.dr, t.dr - t.cr) AS amount
    FROM gl_accounts a
    JOIN (SELECT e.account_id, SUM(e.debit) AS dr, SUM(e.credit) AS cr
            FROM gl_entries e JOIN gl_vouchers v ON v.id = e.voucher_id
           WHERE v.voucher_date BETWEEN v_from AND v_to GROUP BY e.account_id) t ON t.account_id = a.id
   WHERE a.acct_type IN ('INCOME', 'EXPENSE') AND (t.dr <> 0 OR t.cr <> 0)
   ORDER BY a.acct_type DESC, a.code;
  SELECT fn_stock_value(v_start - INTERVAL 1 MICROSECOND) + fn_stock_introduced(v_start, v_end) AS opening_stock,
         fn_stock_value(v_end) AS closing_stock, v_from AS from_date, v_to AS to_date;
END $$

DROP PROCEDURE IF EXISTS sp_gl_balance_sheet $$
CREATE PROCEDURE sp_gl_balance_sheet(IN p_actor CHAR(36), IN p_asof DATE)
BEGIN
  DECLARE v_asof DATE DEFAULT IFNULL(p_asof, CURDATE());
  DECLARE v_end DATETIME(3) DEFAULT CAST(CONCAT(IFNULL(p_asof, CURDATE()), ' 23:59:59.999') AS DATETIME(3));
  CALL sp_require_permission(p_actor, 'Accounts', 'read');
  SELECT a.id, a.code, a.name, a.acct_type, a.subtype, t.dr - t.cr AS balance
    FROM gl_accounts a
    JOIN (SELECT e.account_id, SUM(e.debit) AS dr, SUM(e.credit) AS cr
            FROM gl_entries e JOIN gl_vouchers v ON v.id = e.voucher_id
           WHERE v.voucher_date <= v_asof GROUP BY e.account_id) t ON t.account_id = a.id
   WHERE a.acct_type IN ('ASSET', 'LIABILITY', 'EQUITY') AND t.dr - t.cr <> 0
   ORDER BY FIELD(a.acct_type, 'ASSET', 'LIABILITY', 'EQUITY'), a.code;
  SELECT fn_stock_value(v_end) AS stock_value,
         fn_stock_introduced(CAST('1900-01-01' AS DATETIME(3)), v_end) AS stock_introduced,
         (SELECT COALESCE(SUM(e.credit - e.debit), 0)
            FROM gl_entries e JOIN gl_vouchers v ON v.id = e.voucher_id JOIN gl_accounts a ON a.id = e.account_id
           WHERE a.acct_type = 'INCOME' AND v.voucher_date <= v_asof)
         - (SELECT COALESCE(SUM(e.debit - e.credit), 0)
              FROM gl_entries e JOIN gl_vouchers v ON v.id = e.voucher_id JOIN gl_accounts a ON a.id = e.account_id
             WHERE a.acct_type = 'EXPENSE' AND v.voucher_date <= v_asof) AS books_profit,
         v_asof AS asof;
END $$

-- ----------------------------- GST -------------------------------------------------------
DROP PROCEDURE IF EXISTS sp_gst_sales_register $$
CREATE PROCEDURE sp_gst_sales_register(IN p_actor CHAR(36), IN p_from DATE, IN p_to DATE)
BEGIN
  CALL sp_require_permission(p_actor, 'Accounts', 'read');
  SELECT 'INVOICE' AS doc_type, si.id, si.invoice_number AS doc_no, DATE(si.invoice_date) AS doc_date, c.name AS party, c.gstin,
         si.subtotal_amount AS taxable, si.cgst_amount AS cgst, si.sgst_amount AS sgst, si.igst_amount AS igst, si.total_amount AS total
    FROM sales_invoices si JOIN customers c ON c.id = si.customer_id
   WHERE si.status IN ('UNPAID','PARTIAL','PAID') AND DATE(si.invoice_date) BETWEEN IFNULL(p_from, '1900-01-01') AND IFNULL(p_to, CURDATE())
  UNION ALL
  SELECT 'CREDIT NOTE', cn.id, cn.note_number, cn.note_date, c.name, c.gstin,
         -cn.subtotal_amount, -cn.cgst_amount, -cn.sgst_amount, -cn.igst_amount, -cn.total_amount
    FROM credit_notes cn JOIN customers c ON c.id = cn.customer_id
   WHERE cn.kind = 'SALES' AND cn.status = 'POSTED' AND cn.note_date BETWEEN IFNULL(p_from, '1900-01-01') AND IFNULL(p_to, CURDATE())
  ORDER BY doc_date, doc_no;
END $$

DROP PROCEDURE IF EXISTS sp_gst_purchase_register $$
CREATE PROCEDURE sp_gst_purchase_register(IN p_actor CHAR(36), IN p_from DATE, IN p_to DATE)
BEGIN
  CALL sp_require_permission(p_actor, 'Accounts', 'read');
  SELECT 'BILL' AS doc_type, pi.id, pi.invoice_number AS doc_no, DATE(pi.invoice_date) AS doc_date, s.name AS party, s.gstin,
         pi.subtotal_amount AS taxable, pi.cgst_amount AS cgst, pi.sgst_amount AS sgst, pi.igst_amount AS igst, pi.total_amount AS total
    FROM purchase_invoices pi JOIN suppliers s ON s.id = pi.supplier_id
   WHERE pi.status IN ('UNPAID','PARTIAL','PAID') AND DATE(pi.invoice_date) BETWEEN IFNULL(p_from, '1900-01-01') AND IFNULL(p_to, CURDATE())
  UNION ALL
  SELECT 'DEBIT NOTE', cn.id, cn.note_number, cn.note_date, s.name, s.gstin,
         -cn.subtotal_amount, -cn.cgst_amount, -cn.sgst_amount, -cn.igst_amount, -cn.total_amount
    FROM credit_notes cn JOIN suppliers s ON s.id = cn.supplier_id
   WHERE cn.kind = 'PURCHASE' AND cn.status = 'POSTED' AND cn.note_date BETWEEN IFNULL(p_from, '1900-01-01') AND IFNULL(p_to, CURDATE())
  ORDER BY doc_date, doc_no;
END $$

-- Output tax (sales less credit notes) against input tax (purchases less debit notes), by head
DROP PROCEDURE IF EXISTS sp_gst_summary $$
CREATE PROCEDURE sp_gst_summary(IN p_actor CHAR(36), IN p_from DATE, IN p_to DATE)
BEGIN
  DECLARE v_from DATE DEFAULT IFNULL(p_from, '1900-01-01');
  DECLARE v_to DATE DEFAULT IFNULL(p_to, CURDATE());
  CALL sp_require_permission(p_actor, 'Accounts', 'read');
  SELECT
    (SELECT COALESCE(SUM(subtotal_amount), 0) FROM sales_invoices WHERE status IN ('UNPAID','PARTIAL','PAID') AND DATE(invoice_date) BETWEEN v_from AND v_to)
      - (SELECT COALESCE(SUM(subtotal_amount), 0) FROM credit_notes WHERE kind = 'SALES' AND status = 'POSTED' AND note_date BETWEEN v_from AND v_to) AS out_taxable,
    (SELECT COALESCE(SUM(cgst_amount), 0) FROM sales_invoices WHERE status IN ('UNPAID','PARTIAL','PAID') AND DATE(invoice_date) BETWEEN v_from AND v_to)
      - (SELECT COALESCE(SUM(cgst_amount), 0) FROM credit_notes WHERE kind = 'SALES' AND status = 'POSTED' AND note_date BETWEEN v_from AND v_to) AS out_cgst,
    (SELECT COALESCE(SUM(sgst_amount), 0) FROM sales_invoices WHERE status IN ('UNPAID','PARTIAL','PAID') AND DATE(invoice_date) BETWEEN v_from AND v_to)
      - (SELECT COALESCE(SUM(sgst_amount), 0) FROM credit_notes WHERE kind = 'SALES' AND status = 'POSTED' AND note_date BETWEEN v_from AND v_to) AS out_sgst,
    (SELECT COALESCE(SUM(igst_amount), 0) FROM sales_invoices WHERE status IN ('UNPAID','PARTIAL','PAID') AND DATE(invoice_date) BETWEEN v_from AND v_to)
      - (SELECT COALESCE(SUM(igst_amount), 0) FROM credit_notes WHERE kind = 'SALES' AND status = 'POSTED' AND note_date BETWEEN v_from AND v_to) AS out_igst,
    (SELECT COALESCE(SUM(subtotal_amount), 0) FROM purchase_invoices WHERE status IN ('UNPAID','PARTIAL','PAID') AND DATE(invoice_date) BETWEEN v_from AND v_to)
      - (SELECT COALESCE(SUM(subtotal_amount), 0) FROM credit_notes WHERE kind = 'PURCHASE' AND status = 'POSTED' AND note_date BETWEEN v_from AND v_to) AS in_taxable,
    (SELECT COALESCE(SUM(cgst_amount), 0) FROM purchase_invoices WHERE status IN ('UNPAID','PARTIAL','PAID') AND DATE(invoice_date) BETWEEN v_from AND v_to)
      - (SELECT COALESCE(SUM(cgst_amount), 0) FROM credit_notes WHERE kind = 'PURCHASE' AND status = 'POSTED' AND note_date BETWEEN v_from AND v_to) AS in_cgst,
    (SELECT COALESCE(SUM(sgst_amount), 0) FROM purchase_invoices WHERE status IN ('UNPAID','PARTIAL','PAID') AND DATE(invoice_date) BETWEEN v_from AND v_to)
      - (SELECT COALESCE(SUM(sgst_amount), 0) FROM credit_notes WHERE kind = 'PURCHASE' AND status = 'POSTED' AND note_date BETWEEN v_from AND v_to) AS in_sgst,
    (SELECT COALESCE(SUM(igst_amount), 0) FROM purchase_invoices WHERE status IN ('UNPAID','PARTIAL','PAID') AND DATE(invoice_date) BETWEEN v_from AND v_to)
      - (SELECT COALESCE(SUM(igst_amount), 0) FROM credit_notes WHERE kind = 'PURCHASE' AND status = 'POSTED' AND note_date BETWEEN v_from AND v_to) AS in_igst;
END $$

-- Sales by HSN code (invoice lines, less credit-note lines): quantity, taxable value and tax by head
DROP PROCEDURE IF EXISTS sp_gst_hsn_summary $$
CREATE PROCEDURE sp_gst_hsn_summary(IN p_actor CHAR(36), IN p_from DATE, IN p_to DATE)
BEGIN
  CALL sp_require_permission(p_actor, 'Accounts', 'read');
  SELECT hsn, uom, SUM(qty) AS qty, SUM(taxable) AS taxable, SUM(cgst) AS cgst, SUM(sgst) AS sgst, SUM(igst) AS igst, SUM(cgst + sgst + igst) AS total_tax
    FROM (
      SELECT COALESCE(NULLIF(i.hsn_code, ''), '(none)') AS hsn, i.uom AS uom, sii.qty AS qty, sii.amount AS taxable,
             IF(si.igst_amount > 0, 0, ROUND(sii.tax_amount / 2, 2)) AS cgst,
             IF(si.igst_amount > 0, 0, sii.tax_amount - ROUND(sii.tax_amount / 2, 2)) AS sgst,
             IF(si.igst_amount > 0, sii.tax_amount, 0) AS igst
        FROM sales_invoice_items sii
        JOIN sales_invoices si ON si.id = sii.sales_invoice_id
        JOIN items i ON i.id = sii.item_id
       WHERE si.status IN ('UNPAID','PARTIAL','PAID') AND DATE(si.invoice_date) BETWEEN IFNULL(p_from, '1900-01-01') AND IFNULL(p_to, CURDATE())
      UNION ALL
      SELECT COALESCE(NULLIF(cni.hsn_code, ''), NULLIF(i.hsn_code, ''), '(none)'), IFNULL(i.uom, ''), -cni.qty, -cni.amount,
             IF(cn.igst_amount > 0, 0, -ROUND(cni.tax_amount / 2, 2)),
             IF(cn.igst_amount > 0, 0, -(cni.tax_amount - ROUND(cni.tax_amount / 2, 2))),
             IF(cn.igst_amount > 0, -cni.tax_amount, 0)
        FROM credit_note_items cni
        JOIN credit_notes cn ON cn.id = cni.credit_note_id
        LEFT JOIN items i ON i.id = cni.item_id
       WHERE cn.kind = 'SALES' AND cn.status = 'POSTED' AND cn.note_date BETWEEN IFNULL(p_from, '1900-01-01') AND IFNULL(p_to, CURDATE())
    ) x
   GROUP BY hsn, uom ORDER BY hsn, uom;
END $$

-- A customer's or supplier's account: opening balance, then every entry on their debtors / creditors account
DROP PROCEDURE IF EXISTS sp_gl_party_statement $$
CREATE PROCEDURE sp_gl_party_statement(IN p_actor CHAR(36), IN p_party_type VARCHAR(10), IN p_party_id CHAR(36), IN p_from DATE, IN p_to DATE)
BEGIN
  DECLARE v_open DECIMAL(18,2) DEFAULT 0;
  DECLARE v_from DATE DEFAULT IFNULL(p_from, '1900-01-01');
  DECLARE v_to DATE DEFAULT IFNULL(p_to, CURDATE());
  CALL sp_require_permission(p_actor, 'Accounts', 'read');
  IF p_party_type NOT IN ('CUSTOMER', 'SUPPLIER') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Choose a customer or a supplier'; END IF;
  SELECT COALESCE(SUM(e.debit - e.credit), 0) INTO v_open
    FROM gl_entries e JOIN gl_vouchers v ON v.id = e.voucher_id JOIN gl_accounts a ON a.id = e.account_id
   WHERE e.party_type = p_party_type AND e.party_id = p_party_id AND a.subtype IN ('DEBTORS', 'CREDITORS') AND v.voucher_date < v_from;
  IF p_party_type = 'CUSTOMER' THEN
    SELECT 'CUSTOMER' AS party_type, c.id, c.name, c.gstin, c.email, c.phone, v_open AS opening FROM customers c WHERE c.id = p_party_id;
  ELSE
    SELECT 'SUPPLIER' AS party_type, s.id, s.name, s.gstin, s.email, s.phone, v_open AS opening FROM suppliers s WHERE s.id = p_party_id;
  END IF;
  SELECT v.id AS voucher_id, v.voucher_number, v.voucher_date, v.voucher_type, v.narration, v.reference, e.debit, e.credit,
         v_open + SUM(e.debit - e.credit) OVER (ORDER BY v.voucher_date, v.created_at, v.id, e.line_no ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW) AS balance
    FROM gl_entries e JOIN gl_vouchers v ON v.id = e.voucher_id JOIN gl_accounts a ON a.id = e.account_id
   WHERE e.party_type = p_party_type AND e.party_id = p_party_id AND a.subtype IN ('DEBTORS', 'CREDITORS')
     AND v.voucher_date BETWEEN v_from AND v_to
   ORDER BY v.voucher_date, v.created_at, v.id, e.line_no;
END $$

-- The Books home: balances of cash and bank accounts, what customers owe and we owe, this month, recent vouchers
DROP PROCEDURE IF EXISTS sp_books_dashboard $$
CREATE PROCEDURE sp_books_dashboard(IN p_actor CHAR(36))
BEGIN
  DECLARE v_month DATE DEFAULT DATE_FORMAT(CURDATE(), '%Y-%m-01');
  CALL sp_require_permission(p_actor, 'Accounts', 'read');
  SELECT
    (SELECT COALESCE(SUM(e.debit - e.credit), 0) FROM gl_entries e JOIN gl_accounts a ON a.id = e.account_id WHERE a.subtype = 'DEBTORS')   AS receivables,
    (SELECT COALESCE(SUM(e.credit - e.debit), 0) FROM gl_entries e JOIN gl_accounts a ON a.id = e.account_id WHERE a.subtype = 'CREDITORS') AS payables,
    (SELECT COALESCE(SUM(e.debit - e.credit), 0) FROM gl_entries e JOIN gl_accounts a ON a.id = e.account_id WHERE a.subtype = 'CASH')      AS cash,
    (SELECT COALESCE(SUM(e.debit - e.credit), 0) FROM gl_entries e JOIN gl_accounts a ON a.id = e.account_id WHERE a.subtype = 'BANK')      AS bank,
    (SELECT COALESCE(SUM(e.debit - e.credit), 0) FROM gl_entries e JOIN gl_accounts a ON a.id = e.account_id WHERE a.subtype = 'CHEQUE')    AS cheque,
    (SELECT COALESCE(SUM(e.debit - e.credit), 0) FROM gl_entries e JOIN gl_accounts a ON a.id = e.account_id WHERE a.subtype = 'WALLET')    AS wallet,
    (SELECT COALESCE(SUM(e.credit - e.debit), 0) FROM gl_entries e JOIN gl_vouchers v ON v.id = e.voucher_id JOIN gl_accounts a ON a.id = e.account_id
       WHERE a.subtype IN ('SALES', 'SALES_RETURN') AND v.voucher_date >= v_month)                                                         AS sales_month,
    (SELECT COALESCE(SUM(e.debit - e.credit), 0) FROM gl_entries e JOIN gl_vouchers v ON v.id = e.voucher_id JOIN gl_accounts a ON a.id = e.account_id
       WHERE a.subtype IN ('PURCHASES', 'PURCHASE_RETURN') AND v.voucher_date >= v_month)                                                  AS purchases_month,
    (SELECT COALESCE(SUM(e.debit - e.credit), 0) FROM gl_entries e JOIN gl_vouchers v ON v.id = e.voucher_id JOIN gl_accounts a ON a.id = e.account_id
       WHERE a.acct_type = 'EXPENSE' AND a.subtype IN ('DIRECT_EXP', 'INDIRECT_EXP') AND v.voucher_date >= v_month)                        AS expenses_month;
  SELECT a.id, a.code, a.name, a.subtype, COALESCE(SUM(e.debit - e.credit), 0) AS balance
    FROM gl_accounts a LEFT JOIN gl_entries e ON e.account_id = a.id
   WHERE a.is_active = 1 AND a.subtype IN ('CASH', 'BANK', 'CHEQUE', 'WALLET')
   GROUP BY a.id ORDER BY FIELD(a.subtype, 'CASH', 'BANK', 'CHEQUE', 'WALLET'), a.code;
  SELECT v.id, v.voucher_number, v.voucher_date, v.voucher_type, v.narration, v.status,
         (SELECT COALESCE(SUM(debit), 0) FROM gl_entries WHERE voucher_id = v.id) AS amount
    FROM gl_vouchers v ORDER BY v.created_at DESC LIMIT 8;
END $$

-- ----------------------------- credit and debit notes ---------------------------------
-- p_lines: [{"itemId":"<id or empty>","description":"...","hsn":"","qty":1,"rate":100,"taxRate":18}]
DROP PROCEDURE IF EXISTS sp_credit_note_save $$
CREATE PROCEDURE sp_credit_note_save(
  IN p_actor CHAR(36), IN p_id CHAR(36), IN p_kind VARCHAR(10), IN p_party_id CHAR(36), IN p_invoice_id CHAR(36),
  IN p_date DATE, IN p_reason VARCHAR(255), IN p_lines JSON, OUT p_out CHAR(36))
BEGIN
  DECLARE v_id CHAR(36) DEFAULT NULLIF(p_id, '');
  DECLARE v_inv CHAR(36) DEFAULT NULLIF(p_invoice_id, '');
  DECLARE v_n INT DEFAULT IFNULL(JSON_LENGTH(p_lines), 0);
  DECLARE v_status VARCHAR(12); DECLARE v_no VARCHAR(40); DECLARE v_gstin VARCHAR(15); DECLARE v_state VARCHAR(4);
  DECLARE v_inter TINYINT DEFAULT 0; DECLARE v_bad INT DEFAULT 0; DECLARE v_party CHAR(36);
  DECLARE v_sub DECIMAL(16,2); DECLARE v_tax DECIMAL(16,2); DECLARE v_tot DECIMAL(16,2);
  DECLARE v_inv_total DECIMAL(16,2); DECLARE v_inv_status VARCHAR(20); DECLARE v_other DECIMAL(16,2) DEFAULT 0;
  DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;

  CALL sp_require_permission(p_actor, 'Accounts', IF(v_id IS NULL, 'create', 'update'));
  IF p_kind NOT IN ('SALES', 'PURCHASE') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Choose a credit note (to a customer) or a debit note (to a supplier)'; END IF;
  IF p_date IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The date is required'; END IF;
  IF v_n < 1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Add at least one line'; END IF;

  IF p_kind = 'SALES' THEN
    SELECT id, gstin INTO v_party, v_gstin FROM customers WHERE id = p_party_id;
  ELSE
    SELECT id, gstin INTO v_party, v_gstin FROM suppliers WHERE id = p_party_id;
  END IF;
  IF v_party IS NULL AND p_kind = 'SALES' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Choose the customer'; END IF;
  IF v_party IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Choose the supplier'; END IF;

  IF v_id IS NOT NULL THEN
    SELECT status, note_number INTO v_status, v_no FROM credit_notes WHERE id = v_id FOR UPDATE;
    IF v_status IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Note not found'; END IF;
    IF v_status <> 'DRAFT' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A posted note cannot be edited: cancel it and enter a new one'; END IF;
  END IF;

  SELECT COUNT(*) INTO v_bad
    FROM JSON_TABLE(p_lines, '$[*]' COLUMNS (
           description VARCHAR(255) PATH '$.description', qty DECIMAL(16,3) PATH '$.qty' DEFAULT '0' ON EMPTY,
           rate DECIMAL(16,2) PATH '$.rate' DEFAULT '0' ON EMPTY, taxRate DECIMAL(5,2) PATH '$.taxRate' DEFAULT '0' ON EMPTY)) jt
   WHERE IFNULL(TRIM(jt.description), '') = '' OR jt.qty <= 0 OR jt.rate < 0 OR jt.taxRate < 0 OR jt.taxRate > 100;
  IF v_bad > 0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Every line needs a description, a quantity above zero, a rate and a tax rate from 0 to 100'; END IF;

  IF v_inv IS NOT NULL THEN
    IF p_kind = 'SALES' THEN
      SELECT total_amount, status INTO v_inv_total, v_inv_status FROM sales_invoices WHERE id = v_inv AND customer_id = v_party;
      SELECT COALESCE(SUM(total_amount), 0) INTO v_other FROM credit_notes WHERE sales_invoice_id = v_inv AND status = 'POSTED' AND (v_id IS NULL OR id <> v_id);
    ELSE
      SELECT total_amount, status INTO v_inv_total, v_inv_status FROM purchase_invoices WHERE id = v_inv AND supplier_id = v_party;
      SELECT COALESCE(SUM(total_amount), 0) INTO v_other FROM credit_notes WHERE purchase_invoice_id = v_inv AND status = 'POSTED' AND (v_id IS NULL OR id <> v_id);
    END IF;
    IF v_inv_total IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'That invoice does not belong to this party'; END IF;
    IF v_inv_status NOT IN ('UNPAID', 'PARTIAL', 'PAID') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Only a posted invoice can be credited'; END IF;
  END IF;

  SELECT setting_value INTO v_state FROM system_settings WHERE setting_key = 'company_state_code';
  SET v_inter = (v_gstin IS NOT NULL AND v_gstin <> '' AND LEFT(v_gstin, 2) <> IFNULL(v_state, '24'));

  START TRANSACTION;
    IF v_id IS NULL THEN
      CALL sp_next_doc_no(IF(p_kind = 'SALES', 'CN', 'DN'), v_no);
      SET v_id = UUID();
      INSERT INTO credit_notes (id, note_number, kind, customer_id, supplier_id, sales_invoice_id, purchase_invoice_id, note_date, reason, status, created_by_id)
      VALUES (v_id, v_no, p_kind, IF(p_kind = 'SALES', v_party, NULL), IF(p_kind = 'PURCHASE', v_party, NULL),
              IF(p_kind = 'SALES', v_inv, NULL), IF(p_kind = 'PURCHASE', v_inv, NULL), p_date, NULLIF(p_reason, ''), 'DRAFT', p_actor);
    ELSE
      DELETE FROM credit_note_items WHERE credit_note_id = v_id;
      UPDATE credit_notes SET customer_id = IF(p_kind = 'SALES', v_party, NULL), supplier_id = IF(p_kind = 'PURCHASE', v_party, NULL),
             sales_invoice_id = IF(p_kind = 'SALES', v_inv, NULL), purchase_invoice_id = IF(p_kind = 'PURCHASE', v_inv, NULL),
             note_date = p_date, reason = NULLIF(p_reason, ''), kind = p_kind WHERE id = v_id;
    END IF;
    INSERT INTO credit_note_items (credit_note_id, item_id, description, hsn_code, qty, rate, tax_rate, amount, tax_amount, total_amount)
    SELECT v_id, i.id, LEFT(TRIM(jt.description), 255), NULLIF(jt.hsn, ''), jt.qty, jt.rate, jt.taxRate,
           ROUND(jt.qty * jt.rate, 2), ROUND(ROUND(jt.qty * jt.rate, 2) * jt.taxRate / 100, 2),
           ROUND(jt.qty * jt.rate, 2) + ROUND(ROUND(jt.qty * jt.rate, 2) * jt.taxRate / 100, 2)
      FROM JSON_TABLE(p_lines, '$[*]' COLUMNS (
             itemId CHAR(36) PATH '$.itemId', description VARCHAR(255) PATH '$.description', hsn VARCHAR(20) PATH '$.hsn',
             qty DECIMAL(16,3) PATH '$.qty', rate DECIMAL(16,2) PATH '$.rate', taxRate DECIMAL(5,2) PATH '$.taxRate' DEFAULT '0' ON EMPTY)) jt
      LEFT JOIN items i ON i.id = (NULLIF(jt.itemId, '') COLLATE utf8mb4_unicode_ci);
    SELECT COALESCE(SUM(amount), 0), COALESCE(SUM(tax_amount), 0), COALESCE(SUM(total_amount), 0) INTO v_sub, v_tax, v_tot
      FROM credit_note_items WHERE credit_note_id = v_id;
    IF v_inv IS NOT NULL AND v_tot + v_other > v_inv_total THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The notes against this invoice would total more than the invoice itself';
    END IF;
    UPDATE credit_notes SET subtotal_amount = v_sub, tax_amount = v_tax, total_amount = v_tot,
           cgst_amount = IF(v_inter = 1, 0, ROUND(v_tax / 2, 2)), sgst_amount = IF(v_inter = 1, 0, v_tax - ROUND(v_tax / 2, 2)),
           igst_amount = IF(v_inter = 1, v_tax, 0)
     WHERE id = v_id;
    SET p_out = v_id;
  COMMIT;
END $$

DROP PROCEDURE IF EXISTS sp_credit_note_post $$
CREATE PROCEDURE sp_credit_note_post(IN p_actor CHAR(36), IN p_id CHAR(36))
BEGIN
  DECLARE v_status VARCHAR(12); DECLARE v_total DECIMAL(16,2); DECLARE v_si CHAR(36); DECLARE v_pi CHAR(36);
  DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;
  CALL sp_require_permission(p_actor, 'Accounts', 'update');
  START TRANSACTION;
    SELECT status, total_amount, sales_invoice_id, purchase_invoice_id INTO v_status, v_total, v_si, v_pi FROM credit_notes WHERE id = p_id FOR UPDATE;
    IF v_status IS NULL OR v_status <> 'DRAFT' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Note not found or already posted'; END IF;
    IF v_total <= 0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The note has no value'; END IF;
    UPDATE credit_notes SET status = 'POSTED' WHERE id = p_id;
    IF v_si IS NOT NULL THEN CALL sp_recalc_sales_invoice(v_si); END IF;
    IF v_pi IS NOT NULL THEN CALL sp_recalc_purchase_invoice(v_pi); END IF;
    CALL sp_audit(p_actor, 'CREDIT_NOTE_POSTED', NULL, JSON_OBJECT('noteId', p_id));
  COMMIT;
END $$

DROP PROCEDURE IF EXISTS sp_credit_note_cancel $$
CREATE PROCEDURE sp_credit_note_cancel(IN p_actor CHAR(36), IN p_id CHAR(36))
BEGIN
  DECLARE v_status VARCHAR(12); DECLARE v_si CHAR(36); DECLARE v_pi CHAR(36);
  DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;
  CALL sp_require_permission(p_actor, 'Accounts', 'delete');
  START TRANSACTION;
    SELECT status, sales_invoice_id, purchase_invoice_id INTO v_status, v_si, v_pi FROM credit_notes WHERE id = p_id FOR UPDATE;
    IF v_status IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Note not found'; END IF;
    IF v_status <> 'POSTED' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Only a posted note can be cancelled (delete a draft instead)'; END IF;
    UPDATE credit_notes SET status = 'CANCELLED' WHERE id = p_id;
    IF v_si IS NOT NULL THEN CALL sp_recalc_sales_invoice(v_si); END IF;
    IF v_pi IS NOT NULL THEN CALL sp_recalc_purchase_invoice(v_pi); END IF;
    CALL sp_audit(p_actor, 'CREDIT_NOTE_CANCELLED', NULL, JSON_OBJECT('noteId', p_id));
  COMMIT;
END $$

DROP PROCEDURE IF EXISTS sp_credit_note_delete $$
CREATE PROCEDURE sp_credit_note_delete(IN p_actor CHAR(36), IN p_id CHAR(36))
BEGIN
  DECLARE v_status VARCHAR(12);
  CALL sp_require_permission(p_actor, 'Accounts', 'delete');
  SELECT status INTO v_status FROM credit_notes WHERE id = p_id;
  IF v_status IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Note not found'; END IF;
  IF v_status <> 'DRAFT' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Only a draft can be deleted'; END IF;
  DELETE FROM credit_notes WHERE id = p_id;
END $$

DROP PROCEDURE IF EXISTS sp_credit_note_list $$
CREATE PROCEDURE sp_credit_note_list(IN p_actor CHAR(36), IN p_kind VARCHAR(10))
BEGIN
  CALL sp_require_permission(p_actor, 'Accounts', 'read');
  SELECT cn.id, cn.note_number, cn.kind, cn.note_date, cn.status, cn.total_amount, cn.reason,
         COALESCE(c.name, s.name) AS party_name, COALESCE(si.invoice_number, pi.invoice_number) AS invoice_number
    FROM credit_notes cn
    LEFT JOIN customers c ON c.id = cn.customer_id
    LEFT JOIN suppliers s ON s.id = cn.supplier_id
    LEFT JOIN sales_invoices si ON si.id = cn.sales_invoice_id
    LEFT JOIN purchase_invoices pi ON pi.id = cn.purchase_invoice_id
   WHERE NULLIF(p_kind, '') IS NULL OR cn.kind = p_kind
   ORDER BY cn.created_at DESC;
END $$

DROP PROCEDURE IF EXISTS sp_credit_note_get $$
CREATE PROCEDURE sp_credit_note_get(IN p_actor CHAR(36), IN p_id CHAR(36))
BEGIN
  CALL sp_require_permission(p_actor, 'Accounts', 'read');
  SELECT cn.*, COALESCE(c.name, s.name) AS party_name, COALESCE(c.gstin, s.gstin) AS party_gstin,
         COALESCE(si.invoice_number, pi.invoice_number) AS invoice_number
    FROM credit_notes cn
    LEFT JOIN customers c ON c.id = cn.customer_id
    LEFT JOIN suppliers s ON s.id = cn.supplier_id
    LEFT JOIN sales_invoices si ON si.id = cn.sales_invoice_id
    LEFT JOIN purchase_invoices pi ON pi.id = cn.purchase_invoice_id
   WHERE cn.id = p_id;
  SELECT cni.*, i.code AS item_code FROM credit_note_items cni LEFT JOIN items i ON i.id = cni.item_id
   WHERE cni.credit_note_id = p_id ORDER BY cni.id;
END $$

DELIMITER ;
