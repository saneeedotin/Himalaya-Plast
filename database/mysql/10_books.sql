-- =============================================================================
-- HPOS Factory OS  |  10_books.sql  -  the books: chart of accounts, double-entry ledger, vouchers,
-- credit and debit notes, and the reports an accountant needs.
--
-- How it works
--   * Every money movement is a VOUCHER with two or more ENTRIES that balance (debits = credits).
--     Vouchers and entries are append-only: a mistake is cancelled by a reversing voucher, never edited.
--   * Posted sales invoices, purchase bills, payments and credit/debit notes write their own vouchers
--     through triggers (the same pattern as the stock ledger), so the books cannot drift from the documents.
--   * Manual vouchers (journal, contra, expense, receipt, payment) and opening balances cover the rest.
--   * Stock is valued at standard cost for the profit and loss and balance sheet (periodic inventory:
--     opening stock + purchases - closing stock = cost of goods).
-- Needs 01-09 loaded first.
-- =============================================================================
USE hpos;

CREATE TABLE IF NOT EXISTS gl_accounts (
  id         CHAR(36)     NOT NULL DEFAULT (UUID()),
  code       VARCHAR(20)  NOT NULL,
  name       VARCHAR(120) NOT NULL,
  acct_type  VARCHAR(12)  NOT NULL,                    -- ASSET, LIABILITY, EQUITY, INCOME, EXPENSE
  subtype    VARCHAR(20)  NOT NULL DEFAULT 'GENERAL',  -- CASH BANK CHEQUE WALLET DEBTORS CREDITORS TAX_INPUT TAX_OUTPUT
                                                       -- SALES SALES_RETURN PURCHASES PURCHASE_RETURN DIRECT_EXP INDIRECT_EXP ...
  is_system  TINYINT(1)   NOT NULL DEFAULT 0,          -- accounts the system posts to: cannot be removed or re-typed
  is_active  TINYINT(1)   NOT NULL DEFAULT 1,
  notes      VARCHAR(255) NULL,
  created_at DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_gl_code (code),
  CONSTRAINT ck_gl_type CHECK (acct_type IN ('ASSET','LIABILITY','EQUITY','INCOME','EXPENSE'))
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS gl_vouchers (
  id             CHAR(36)     NOT NULL DEFAULT (UUID()),
  voucher_number VARCHAR(40)  NOT NULL,                -- VCH-2026-00001
  voucher_type   VARCHAR(20)  NOT NULL,                -- SALES PURCHASE RECEIPT PAYMENT JOURNAL CONTRA EXPENSE CREDIT_NOTE DEBIT_NOTE OPENING
  voucher_date   DATE         NOT NULL,
  narration      VARCHAR(500) NULL,
  reference      VARCHAR(80)  NULL,
  source_type    VARCHAR(30)  NOT NULL DEFAULT 'MANUAL', -- MANUAL, SALES_INVOICE, PURCHASE_INVOICE, PAYMENT, CREDIT_NOTE
  source_id      CHAR(36)     NULL,
  status         VARCHAR(12)  NOT NULL DEFAULT 'POSTED', -- POSTED, or REVERSED once a reversing voucher exists
  reverses_id    CHAR(36)     NULL,                    -- set on a reversing voucher
  reversed_by_id CHAR(36)     NULL,
  created_by_id  CHAR(36)     NULL,
  created_at     DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_gl_voucher_no (voucher_number),
  KEY ix_gl_voucher_date (voucher_date, created_at),
  KEY ix_gl_voucher_src (source_type, source_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS gl_entries (
  id         CHAR(36)      NOT NULL DEFAULT (UUID()),
  voucher_id CHAR(36)      NOT NULL,
  line_no    INT           NOT NULL,
  account_id CHAR(36)      NOT NULL,
  debit      DECIMAL(16,2) NOT NULL DEFAULT 0,
  credit     DECIMAL(16,2) NOT NULL DEFAULT 0,
  party_type VARCHAR(10)   NULL,                       -- CUSTOMER or SUPPLIER, on the debtors and creditors accounts
  party_id   CHAR(36)      NULL,
  note       VARCHAR(255)  NULL,
  PRIMARY KEY (id),
  KEY ix_gl_entry_voucher (voucher_id, line_no),
  KEY ix_gl_entry_account (account_id),
  KEY ix_gl_entry_party (party_type, party_id),
  CONSTRAINT fk_gl_entry_voucher FOREIGN KEY (voucher_id) REFERENCES gl_vouchers(id),
  CONSTRAINT fk_gl_entry_account FOREIGN KEY (account_id) REFERENCES gl_accounts(id),
  CONSTRAINT ck_gl_entry_side CHECK (debit >= 0 AND credit >= 0 AND (debit = 0 OR credit = 0) AND (debit > 0 OR credit > 0))
) ENGINE=InnoDB;

-- Credit notes (to a customer, against a sales invoice) and debit notes (to a supplier, against a bill)
CREATE TABLE IF NOT EXISTS credit_notes (
  id              CHAR(36)      NOT NULL DEFAULT (UUID()),
  note_number     VARCHAR(40)   NOT NULL,              -- CN-2026-00001 / DN-2026-00001
  kind            VARCHAR(10)   NOT NULL,              -- SALES (credit note to a customer) or PURCHASE (debit note to a supplier)
  customer_id     CHAR(36)      NULL,
  supplier_id     CHAR(36)      NULL,
  sales_invoice_id    CHAR(36)  NULL,
  purchase_invoice_id CHAR(36)  NULL,
  note_date       DATE          NOT NULL,
  reason          VARCHAR(255)  NULL,
  status          VARCHAR(12)   NOT NULL DEFAULT 'DRAFT',   -- DRAFT, POSTED, CANCELLED
  subtotal_amount DECIMAL(16,2) NOT NULL DEFAULT 0,
  tax_amount      DECIMAL(16,2) NOT NULL DEFAULT 0,
  cgst_amount     DECIMAL(16,2) NOT NULL DEFAULT 0,
  sgst_amount     DECIMAL(16,2) NOT NULL DEFAULT 0,
  igst_amount     DECIMAL(16,2) NOT NULL DEFAULT 0,
  total_amount    DECIMAL(16,2) NOT NULL DEFAULT 0,
  created_by_id   CHAR(36)      NULL,
  created_at      DATETIME(3)   NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at      DATETIME(3)   NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_credit_note_no (note_number),
  KEY ix_cn_si (sales_invoice_id),
  KEY ix_cn_pi (purchase_invoice_id),
  CONSTRAINT ck_cn_kind CHECK (kind IN ('SALES','PURCHASE')),
  CONSTRAINT ck_cn_status CHECK (status IN ('DRAFT','POSTED','CANCELLED'))
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS credit_note_items (
  id             CHAR(36)      NOT NULL DEFAULT (UUID()),
  credit_note_id CHAR(36)      NOT NULL,
  item_id        CHAR(36)      NULL,
  description    VARCHAR(255)  NOT NULL,
  hsn_code       VARCHAR(20)   NULL,
  qty            DECIMAL(16,3) NOT NULL,
  rate           DECIMAL(16,2) NOT NULL,
  tax_rate       DECIMAL(5,2)  NOT NULL DEFAULT 18.00,
  amount         DECIMAL(16,2) NOT NULL DEFAULT 0,
  tax_amount     DECIMAL(16,2) NOT NULL DEFAULT 0,
  total_amount   DECIMAL(16,2) NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY ix_cni_note (credit_note_id),
  CONSTRAINT fk_cni_note FOREIGN KEY (credit_note_id) REFERENCES credit_notes(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- -----------------------------------------------------------------------------
-- The standard chart of accounts. System accounts are the ones the documents post to.
-- -----------------------------------------------------------------------------
INSERT IGNORE INTO gl_accounts (code, name, acct_type, subtype, is_system) VALUES
 ('1000', 'Cash in Hand',                'ASSET',     'CASH',            1),
 ('1100', 'Bank Account',                'ASSET',     'BANK',            1),
 ('1110', 'Cheques in Hand',             'ASSET',     'CHEQUE',          1),
 ('1120', 'Wallet and UPI',              'ASSET',     'WALLET',          1),
 ('1200', 'Sundry Debtors',              'ASSET',     'DEBTORS',         1),
 ('1300', 'Input CGST',                  'ASSET',     'TAX_INPUT',       1),
 ('1310', 'Input SGST',                  'ASSET',     'TAX_INPUT',       1),
 ('1320', 'Input IGST',                  'ASSET',     'TAX_INPUT',       1),
 ('1400', 'Advances and Deposits',       'ASSET',     'GENERAL',         0),
 ('1500', 'Fixed Assets',                'ASSET',     'FIXED_ASSET',     0),
 ('2000', 'Sundry Creditors',            'LIABILITY', 'CREDITORS',       1),
 ('2100', 'Output CGST',                 'LIABILITY', 'TAX_OUTPUT',      1),
 ('2110', 'Output SGST',                 'LIABILITY', 'TAX_OUTPUT',      1),
 ('2120', 'Output IGST',                 'LIABILITY', 'TAX_OUTPUT',      1),
 ('2200', 'Loans',                       'LIABILITY', 'GENERAL',         0),
 ('2300', 'Other Liabilities',           'LIABILITY', 'GENERAL',         0),
 ('2400', 'TDS and Other Taxes Payable', 'LIABILITY', 'GENERAL',         0),
 ('3000', 'Capital Account',             'EQUITY',    'CAPITAL',         1),
 ('3100', 'Reserves and Surplus',        'EQUITY',    'GENERAL',         0),
 ('3900', 'Opening Balance Difference',  'EQUITY',    'OPENING_OFFSET',  1),
 ('4000', 'Sales',                       'INCOME',    'SALES',           1),
 ('4100', 'Other Income',                'INCOME',    'OTHER_INCOME',    0),
 ('4200', 'Sales Returns',               'INCOME',    'SALES_RETURN',    1),
 ('5000', 'Purchases',                   'EXPENSE',   'PURCHASES',       1),
 ('5100', 'Purchase Returns',            'EXPENSE',   'PURCHASE_RETURN', 1),
 ('5200', 'Direct Expenses',             'EXPENSE',   'DIRECT_EXP',      0),
 ('5210', 'Power and Fuel',              'EXPENSE',   'DIRECT_EXP',      0),
 ('5220', 'Freight Inward',              'EXPENSE',   'DIRECT_EXP',      0),
 ('5300', 'Salaries and Wages',          'EXPENSE',   'INDIRECT_EXP',    0),
 ('5310', 'Rent',                        'EXPENSE',   'INDIRECT_EXP',    0),
 ('5320', 'Electricity',                 'EXPENSE',   'INDIRECT_EXP',    0),
 ('5330', 'Repairs and Maintenance',     'EXPENSE',   'INDIRECT_EXP',    0),
 ('5340', 'Transport and Freight Outward','EXPENSE',  'INDIRECT_EXP',    0),
 ('5350', 'Office and Administration',   'EXPENSE',   'INDIRECT_EXP',    0),
 ('5360', 'Interest and Bank Charges',   'EXPENSE',   'INDIRECT_EXP',    0),
 ('5370', 'Professional Fees',           'EXPENSE',   'INDIRECT_EXP',    0),
 ('5380', 'Depreciation',                'EXPENSE',   'INDIRECT_EXP',    0),
 ('5900', 'Round Off',                   'EXPENSE',   'INDIRECT_EXP',    1);

DELIMITER $$

-- -----------------------------------------------------------------------------
-- Append-only guards
-- -----------------------------------------------------------------------------
DROP TRIGGER IF EXISTS trg_gl_entry_no_update $$
CREATE TRIGGER trg_gl_entry_no_update BEFORE UPDATE ON gl_entries FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ledger entries cannot be changed: cancel the voucher, which posts a reversing entry';
END $$

DROP TRIGGER IF EXISTS trg_gl_entry_no_delete $$
CREATE TRIGGER trg_gl_entry_no_delete BEFORE DELETE ON gl_entries FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ledger entries cannot be deleted: cancel the voucher, which posts a reversing entry';
END $$

DROP TRIGGER IF EXISTS trg_gl_voucher_no_delete $$
CREATE TRIGGER trg_gl_voucher_no_delete BEFORE DELETE ON gl_vouchers FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Vouchers cannot be deleted: cancel the voucher, which posts a reversing entry';
END $$

-- only the status and the link to the reversing voucher may change after posting
DROP TRIGGER IF EXISTS trg_gl_voucher_guard $$
CREATE TRIGGER trg_gl_voucher_guard BEFORE UPDATE ON gl_vouchers FOR EACH ROW
BEGIN
  IF NOT (NEW.voucher_number <=> OLD.voucher_number AND NEW.voucher_type <=> OLD.voucher_type
          AND NEW.voucher_date <=> OLD.voucher_date AND NEW.narration <=> OLD.narration
          AND NEW.source_type <=> OLD.source_type AND NEW.source_id <=> OLD.source_id
          AND (OLD.reverses_id IS NULL OR NEW.reverses_id <=> OLD.reverses_id)) THEN   -- a cancellation voucher is linked to its original once
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A posted voucher cannot be changed: cancel it and enter a new one';
  END IF;
END $$

-- -----------------------------------------------------------------------------
-- Helpers
-- -----------------------------------------------------------------------------
DROP FUNCTION IF EXISTS fn_gl_id $$
CREATE FUNCTION fn_gl_id(p_code VARCHAR(20)) RETURNS CHAR(36)
READS SQL DATA
BEGIN
  DECLARE v_id CHAR(36);
  SELECT id INTO v_id FROM gl_accounts WHERE code = p_code;
  IF v_id IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A system account is missing from the chart of accounts';
  END IF;
  RETURN v_id;
END $$

-- The cash or bank account a payment goes through: the one chosen, else by mode of payment
DROP FUNCTION IF EXISTS fn_gl_pay_account $$
CREATE FUNCTION fn_gl_pay_account(p_account_id CHAR(36), p_mode VARCHAR(20), p_party_type VARCHAR(10)) RETURNS CHAR(36)
READS SQL DATA
BEGIN
  IF p_account_id IS NOT NULL THEN
    RETURN p_account_id;
  END IF;
  RETURN fn_gl_id(CASE
    WHEN p_mode = 'CASH' THEN '1000'
    WHEN p_mode = 'CHEQUE' AND p_party_type = 'CUSTOMER' THEN '1110'
    ELSE '1100' END);
END $$

-- Stock at standard cost as at a moment (stock is valued for the profit and loss and balance sheet)
DROP FUNCTION IF EXISTS fn_stock_value $$
CREATE FUNCTION fn_stock_value(p_asof DATETIME(3)) RETURNS DECIMAL(18,2)
READS SQL DATA
BEGIN
  DECLARE v_val DECIMAL(18,2);
  SELECT COALESCE(SUM(CASE
            WHEN l.movement_type IN ('RECEIPT','PRODUCTION_OUTPUT','PRODUCTION','RECOVERY','ADJUSTMENT_IN') THEN  l.quantity
            WHEN l.movement_type IN ('CONSUMPTION','SCRAP','DISPATCH','ADJUSTMENT_OUT')                      THEN -l.quantity
            ELSE 0 END * IFNULL(i.standard_cost, 0)), 0)
    INTO v_val
    FROM stock_ledger_entries l JOIN items i ON i.id = l.item_id
   WHERE l.created_at <= p_asof;
  RETURN ROUND(v_val, 2);
END $$

-- Stock that was entered as opening stock (not bought, not made) between two moments: capital, not profit
DROP FUNCTION IF EXISTS fn_stock_introduced $$
CREATE FUNCTION fn_stock_introduced(p_from DATETIME(3), p_to DATETIME(3)) RETURNS DECIMAL(18,2)
READS SQL DATA
BEGIN
  DECLARE v_val DECIMAL(18,2);
  SELECT COALESCE(SUM(l.quantity * IFNULL(i.standard_cost, 0)), 0) INTO v_val
    FROM stock_ledger_entries l JOIN items i ON i.id = l.item_id
   WHERE l.is_opening = 1 AND l.movement_type = 'ADJUSTMENT_IN' AND l.created_at >= p_from AND l.created_at <= p_to;
  RETURN ROUND(v_val, 2);
END $$

-- -----------------------------------------------------------------------------
-- Posting. sp_gl_begin opens a voucher, sp_gl_line adds an entry, sp_gl_end checks it balances.
-- These are internal: the callers check permissions (or are triggers on already-authorised documents).
-- -----------------------------------------------------------------------------
DROP PROCEDURE IF EXISTS sp_gl_begin $$
CREATE PROCEDURE sp_gl_begin(
  IN p_type VARCHAR(20), IN p_date DATE, IN p_narration VARCHAR(500), IN p_reference VARCHAR(80),
  IN p_source_type VARCHAR(30), IN p_source_id CHAR(36), IN p_actor CHAR(36), OUT p_voucher CHAR(36))
BEGIN
  DECLARE v_no VARCHAR(60);
  CALL sp_next_doc_no('VCH', v_no);
  SET p_voucher = UUID();
  INSERT INTO gl_vouchers (id, voucher_number, voucher_type, voucher_date, narration, reference, source_type, source_id, created_by_id)
  VALUES (p_voucher, v_no, p_type, p_date, LEFT(p_narration, 500), NULLIF(p_reference, ''), p_source_type, p_source_id, p_actor);
END $$

DROP PROCEDURE IF EXISTS sp_gl_line $$
CREATE PROCEDURE sp_gl_line(
  IN p_voucher CHAR(36), IN p_account CHAR(36), IN p_debit DECIMAL(16,2), IN p_credit DECIMAL(16,2),
  IN p_party_type VARCHAR(10), IN p_party_id CHAR(36), IN p_note VARCHAR(255))
BEGIN
  DECLARE v_n INT;
  -- a nil line (for example a tax head with no tax) is simply not written
  IF IFNULL(p_debit, 0) <> 0 OR IFNULL(p_credit, 0) <> 0 THEN
    SELECT COALESCE(MAX(line_no), 0) + 1 INTO v_n FROM gl_entries WHERE voucher_id = p_voucher;
    INSERT INTO gl_entries (voucher_id, line_no, account_id, debit, credit, party_type, party_id, note)
    VALUES (p_voucher, v_n, p_account, IFNULL(p_debit, 0), IFNULL(p_credit, 0), p_party_type, p_party_id, LEFT(p_note, 255));
  END IF;
END $$

DROP PROCEDURE IF EXISTS sp_gl_end $$
CREATE PROCEDURE sp_gl_end(IN p_voucher CHAR(36))
BEGIN
  DECLARE v_dr DECIMAL(18,2);
  DECLARE v_cr DECIMAL(18,2);
  DECLARE v_n INT;
  SELECT COUNT(*), COALESCE(SUM(debit), 0), COALESCE(SUM(credit), 0) INTO v_n, v_dr, v_cr FROM gl_entries WHERE voucher_id = p_voucher;
  IF v_n < 2 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A voucher needs at least two lines';
  END IF;
  IF v_dr <> v_cr THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The voucher does not balance: total debits must equal total credits';
  END IF;
END $$

-- Cancels a voucher by posting its mirror image; both stay in the books
DROP PROCEDURE IF EXISTS sp_gl_reverse $$
CREATE PROCEDURE sp_gl_reverse(IN p_voucher CHAR(36), IN p_reason VARCHAR(200), IN p_actor CHAR(36), OUT p_reversal CHAR(36))
BEGIN
  DECLARE v_no VARCHAR(40);
  DECLARE v_type VARCHAR(20);
  DECLARE v_src VARCHAR(30);
  DECLARE v_srcid CHAR(36);
  DECLARE v_status VARCHAR(12);
  DECLARE v_reverses CHAR(36);
  DECLARE v_date DATE;

  SELECT voucher_number, voucher_type, source_type, source_id, status, reverses_id, voucher_date
    INTO v_no, v_type, v_src, v_srcid, v_status, v_reverses, v_date
    FROM gl_vouchers WHERE id = p_voucher FOR UPDATE;
  IF v_no IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Voucher not found';
  END IF;
  IF v_status <> 'POSTED' OR v_reverses IS NOT NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'This voucher is already cancelled, or is itself a cancellation';
  END IF;

  CALL sp_gl_begin(v_type, CURDATE(), CONCAT('Cancellation of ', v_no, IF(IFNULL(p_reason, '') = '', '', CONCAT(': ', p_reason))), NULL, v_src, v_srcid, p_actor, p_reversal);
  UPDATE gl_vouchers SET reverses_id = p_voucher WHERE id = p_reversal;
  INSERT INTO gl_entries (voucher_id, line_no, account_id, debit, credit, party_type, party_id, note)
  SELECT p_reversal, line_no, account_id, credit, debit, party_type, party_id, note FROM gl_entries WHERE voucher_id = p_voucher;
  UPDATE gl_vouchers SET status = 'REVERSED', reversed_by_id = p_reversal WHERE id = p_voucher;
  CALL sp_gl_end(p_reversal);
END $$

-- Reverses whatever voucher a document last posted (used when an invoice or payment is cancelled)
DROP PROCEDURE IF EXISTS sp_gl_unpost_source $$
CREATE PROCEDURE sp_gl_unpost_source(IN p_source_type VARCHAR(30), IN p_source_id CHAR(36), IN p_reason VARCHAR(200))
BEGIN
  DECLARE v_voucher CHAR(36);
  DECLARE v_rev CHAR(36);
  SELECT id INTO v_voucher FROM gl_vouchers
   WHERE source_type = p_source_type AND source_id = p_source_id AND status = 'POSTED' AND reverses_id IS NULL
   ORDER BY created_at DESC LIMIT 1;
  IF v_voucher IS NOT NULL THEN
    CALL sp_gl_reverse(v_voucher, p_reason, NULL, v_rev);
  END IF;
END $$

-- ----------------------------- documents -> vouchers ----------------------------
DROP PROCEDURE IF EXISTS sp_gl_post_sales_invoice $$
CREATE PROCEDURE sp_gl_post_sales_invoice(IN p_id CHAR(36))
BEGIN
  DECLARE v_no VARCHAR(60); DECLARE v_cust CHAR(36); DECLARE v_date DATE;
  DECLARE v_sub DECIMAL(16,2); DECLARE v_cgst DECIMAL(16,2); DECLARE v_sgst DECIMAL(16,2); DECLARE v_igst DECIMAL(16,2); DECLARE v_tot DECIMAL(16,2);
  DECLARE v_diff DECIMAL(16,2); DECLARE v_vid CHAR(36); DECLARE v_name VARCHAR(190);

  SELECT si.invoice_number, si.customer_id, DATE(si.invoice_date), si.subtotal_amount, si.cgst_amount, si.sgst_amount, si.igst_amount, si.total_amount, c.name
    INTO v_no, v_cust, v_date, v_sub, v_cgst, v_sgst, v_igst, v_tot, v_name
    FROM sales_invoices si JOIN customers c ON c.id = si.customer_id WHERE si.id = p_id;
  IF v_no IS NULL OR EXISTS (SELECT 1 FROM gl_vouchers WHERE source_type = 'SALES_INVOICE' AND source_id = p_id AND reverses_id IS NULL) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'This sales invoice is already in the books';
  END IF;
  SET v_diff = v_tot - (v_sub + v_cgst + v_sgst + v_igst);   -- normally 0; whatever is left is round-off

  CALL sp_gl_begin('SALES', v_date, CONCAT('Sales invoice ', v_no, ' to ', v_name), v_no, 'SALES_INVOICE', p_id, NULL, v_vid);
  CALL sp_gl_line(v_vid, fn_gl_id('1200'), v_tot, 0, 'CUSTOMER', v_cust, v_no);
  CALL sp_gl_line(v_vid, fn_gl_id('4000'), 0, v_sub, NULL, NULL, v_no);
  CALL sp_gl_line(v_vid, fn_gl_id('2100'), 0, v_cgst, NULL, NULL, v_no);
  CALL sp_gl_line(v_vid, fn_gl_id('2110'), 0, v_sgst, NULL, NULL, v_no);
  CALL sp_gl_line(v_vid, fn_gl_id('2120'), 0, v_igst, NULL, NULL, v_no);
  IF v_diff > 0 THEN CALL sp_gl_line(v_vid, fn_gl_id('5900'), 0, v_diff, NULL, NULL, 'Round off'); END IF;
  IF v_diff < 0 THEN CALL sp_gl_line(v_vid, fn_gl_id('5900'), -v_diff, 0, NULL, NULL, 'Round off'); END IF;
  CALL sp_gl_end(v_vid);
END $$

DROP PROCEDURE IF EXISTS sp_gl_post_purchase_invoice $$
CREATE PROCEDURE sp_gl_post_purchase_invoice(IN p_id CHAR(36))
BEGIN
  DECLARE v_no VARCHAR(60); DECLARE v_sup CHAR(36); DECLARE v_date DATE;
  DECLARE v_sub DECIMAL(16,2); DECLARE v_cgst DECIMAL(16,2); DECLARE v_sgst DECIMAL(16,2); DECLARE v_igst DECIMAL(16,2); DECLARE v_tot DECIMAL(16,2);
  DECLARE v_diff DECIMAL(16,2); DECLARE v_vid CHAR(36); DECLARE v_name VARCHAR(190);

  SELECT pi.invoice_number, pi.supplier_id, DATE(pi.invoice_date), pi.subtotal_amount, pi.cgst_amount, pi.sgst_amount, pi.igst_amount, pi.total_amount, s.name
    INTO v_no, v_sup, v_date, v_sub, v_cgst, v_sgst, v_igst, v_tot, v_name
    FROM purchase_invoices pi JOIN suppliers s ON s.id = pi.supplier_id WHERE pi.id = p_id;
  IF v_no IS NULL OR EXISTS (SELECT 1 FROM gl_vouchers WHERE source_type = 'PURCHASE_INVOICE' AND source_id = p_id AND reverses_id IS NULL) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'This purchase bill is already in the books';
  END IF;
  SET v_diff = v_tot - (v_sub + v_cgst + v_sgst + v_igst);

  CALL sp_gl_begin('PURCHASE', v_date, CONCAT('Purchase bill ', v_no, ' from ', v_name), v_no, 'PURCHASE_INVOICE', p_id, NULL, v_vid);
  CALL sp_gl_line(v_vid, fn_gl_id('5000'), v_sub, 0, NULL, NULL, v_no);
  CALL sp_gl_line(v_vid, fn_gl_id('1300'), v_cgst, 0, NULL, NULL, v_no);
  CALL sp_gl_line(v_vid, fn_gl_id('1310'), v_sgst, 0, NULL, NULL, v_no);
  CALL sp_gl_line(v_vid, fn_gl_id('1320'), v_igst, 0, NULL, NULL, v_no);
  IF v_diff > 0 THEN CALL sp_gl_line(v_vid, fn_gl_id('5900'), v_diff, 0, NULL, NULL, 'Round off'); END IF;
  IF v_diff < 0 THEN CALL sp_gl_line(v_vid, fn_gl_id('5900'), 0, -v_diff, NULL, NULL, 'Round off'); END IF;
  CALL sp_gl_line(v_vid, fn_gl_id('2000'), 0, v_tot, 'SUPPLIER', v_sup, v_no);
  CALL sp_gl_end(v_vid);
END $$

DROP PROCEDURE IF EXISTS sp_gl_post_payment $$
CREATE PROCEDURE sp_gl_post_payment(IN p_id CHAR(36))
BEGIN
  DECLARE v_no VARCHAR(60); DECLARE v_type VARCHAR(10); DECLARE v_party CHAR(36); DECLARE v_date DATE; DECLARE v_amt DECIMAL(16,2);
  DECLARE v_mode VARCHAR(20); DECLARE v_acct CHAR(36); DECLARE v_vid CHAR(36); DECLARE v_name VARCHAR(190); DECLARE v_ref VARCHAR(80);

  SELECT payment_number, party_type, IF(party_type = 'CUSTOMER', customer_id, supplier_id), DATE(payment_date), amount, mode_of_payment, account_id, reference_number
    INTO v_no, v_type, v_party, v_date, v_amt, v_mode, v_acct, v_ref
    FROM payment_entries WHERE id = p_id;
  IF v_no IS NULL OR EXISTS (SELECT 1 FROM gl_vouchers WHERE source_type = 'PAYMENT' AND source_id = p_id AND reverses_id IS NULL) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'This payment is already in the books';
  END IF;
  SET v_acct = fn_gl_pay_account(v_acct, v_mode, v_type);
  IF v_type = 'CUSTOMER' THEN
    SELECT name INTO v_name FROM customers WHERE id = v_party;
    CALL sp_gl_begin('RECEIPT', v_date, CONCAT('Received from ', IFNULL(v_name, 'customer'), ' (', v_no, ')'), IFNULL(v_ref, v_no), 'PAYMENT', p_id, NULL, v_vid);
    CALL sp_gl_line(v_vid, v_acct, v_amt, 0, NULL, NULL, v_no);
    CALL sp_gl_line(v_vid, fn_gl_id('1200'), 0, v_amt, 'CUSTOMER', v_party, v_no);
  ELSE
    SELECT name INTO v_name FROM suppliers WHERE id = v_party;
    CALL sp_gl_begin('PAYMENT', v_date, CONCAT('Paid to ', IFNULL(v_name, 'supplier'), ' (', v_no, ')'), IFNULL(v_ref, v_no), 'PAYMENT', p_id, NULL, v_vid);
    CALL sp_gl_line(v_vid, fn_gl_id('2000'), v_amt, 0, 'SUPPLIER', v_party, v_no);
    CALL sp_gl_line(v_vid, v_acct, 0, v_amt, NULL, NULL, v_no);
  END IF;
  CALL sp_gl_end(v_vid);
END $$

-- Credit note (sales): the customer owes less. Debit note (purchase): we owe the supplier less.
DROP PROCEDURE IF EXISTS sp_gl_post_credit_note $$
CREATE PROCEDURE sp_gl_post_credit_note(IN p_id CHAR(36))
BEGIN
  DECLARE v_no VARCHAR(40); DECLARE v_kind VARCHAR(10); DECLARE v_cust CHAR(36); DECLARE v_sup CHAR(36); DECLARE v_date DATE;
  DECLARE v_sub DECIMAL(16,2); DECLARE v_cgst DECIMAL(16,2); DECLARE v_sgst DECIMAL(16,2); DECLARE v_igst DECIMAL(16,2); DECLARE v_tot DECIMAL(16,2);
  DECLARE v_vid CHAR(36); DECLARE v_name VARCHAR(190);

  SELECT note_number, kind, customer_id, supplier_id, note_date, subtotal_amount, cgst_amount, sgst_amount, igst_amount, total_amount
    INTO v_no, v_kind, v_cust, v_sup, v_date, v_sub, v_cgst, v_sgst, v_igst, v_tot
    FROM credit_notes WHERE id = p_id;
  IF v_no IS NULL OR EXISTS (SELECT 1 FROM gl_vouchers WHERE source_type = 'CREDIT_NOTE' AND source_id = p_id AND reverses_id IS NULL) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'This note is already in the books';
  END IF;
  IF v_kind = 'SALES' THEN
    SELECT name INTO v_name FROM customers WHERE id = v_cust;
    CALL sp_gl_begin('CREDIT_NOTE', v_date, CONCAT('Credit note ', v_no, ' to ', IFNULL(v_name, 'customer')), v_no, 'CREDIT_NOTE', p_id, NULL, v_vid);
    CALL sp_gl_line(v_vid, fn_gl_id('4200'), v_sub, 0, NULL, NULL, v_no);
    CALL sp_gl_line(v_vid, fn_gl_id('2100'), v_cgst, 0, NULL, NULL, v_no);
    CALL sp_gl_line(v_vid, fn_gl_id('2110'), v_sgst, 0, NULL, NULL, v_no);
    CALL sp_gl_line(v_vid, fn_gl_id('2120'), v_igst, 0, NULL, NULL, v_no);
    CALL sp_gl_line(v_vid, fn_gl_id('1200'), 0, v_tot, 'CUSTOMER', v_cust, v_no);
  ELSE
    SELECT name INTO v_name FROM suppliers WHERE id = v_sup;
    CALL sp_gl_begin('DEBIT_NOTE', v_date, CONCAT('Debit note ', v_no, ' to ', IFNULL(v_name, 'supplier')), v_no, 'CREDIT_NOTE', p_id, NULL, v_vid);
    CALL sp_gl_line(v_vid, fn_gl_id('2000'), v_tot, 0, 'SUPPLIER', v_sup, v_no);
    CALL sp_gl_line(v_vid, fn_gl_id('5100'), 0, v_sub, NULL, NULL, v_no);
    CALL sp_gl_line(v_vid, fn_gl_id('1300'), 0, v_cgst, NULL, NULL, v_no);
    CALL sp_gl_line(v_vid, fn_gl_id('1310'), 0, v_sgst, NULL, NULL, v_no);
    CALL sp_gl_line(v_vid, fn_gl_id('1320'), 0, v_igst, NULL, NULL, v_no);
  END IF;
  CALL sp_gl_end(v_vid);
END $$

-- The documents post themselves. Errors are NOT swallowed here: if the books cannot take it, the document is not posted.
DROP TRIGGER IF EXISTS trg_si_gl $$
CREATE TRIGGER trg_si_gl AFTER UPDATE ON sales_invoices FOR EACH ROW
BEGIN
  IF OLD.status = 'DRAFT' AND NEW.status IN ('UNPAID', 'PARTIAL', 'PAID') THEN
    CALL sp_gl_post_sales_invoice(NEW.id);
  ELSEIF OLD.status IN ('UNPAID', 'PARTIAL', 'PAID') AND NEW.status = 'CANCELLED' THEN
    CALL sp_gl_unpost_source('SALES_INVOICE', NEW.id, 'invoice cancelled');
  END IF;
END $$

DROP TRIGGER IF EXISTS trg_pi_gl $$
CREATE TRIGGER trg_pi_gl AFTER UPDATE ON purchase_invoices FOR EACH ROW
BEGIN
  IF OLD.status = 'DRAFT' AND NEW.status IN ('UNPAID', 'PARTIAL', 'PAID') THEN
    CALL sp_gl_post_purchase_invoice(NEW.id);
  ELSEIF OLD.status IN ('UNPAID', 'PARTIAL', 'PAID') AND NEW.status = 'CANCELLED' THEN
    CALL sp_gl_unpost_source('PURCHASE_INVOICE', NEW.id, 'bill cancelled');
  END IF;
END $$

DROP TRIGGER IF EXISTS trg_pay_gl $$
CREATE TRIGGER trg_pay_gl AFTER UPDATE ON payment_entries FOR EACH ROW
BEGIN
  IF OLD.status = 'DRAFT' AND NEW.status = 'SUBMITTED' THEN
    CALL sp_gl_post_payment(NEW.id);
  ELSEIF OLD.status = 'SUBMITTED' AND NEW.status = 'CANCELLED' THEN
    CALL sp_gl_unpost_source('PAYMENT', NEW.id, 'payment cancelled');
  END IF;
END $$

DROP TRIGGER IF EXISTS trg_cn_gl $$
CREATE TRIGGER trg_cn_gl AFTER UPDATE ON credit_notes FOR EACH ROW
BEGIN
  IF OLD.status = 'DRAFT' AND NEW.status = 'POSTED' THEN
    CALL sp_gl_post_credit_note(NEW.id);
  ELSEIF OLD.status = 'POSTED' AND NEW.status = 'CANCELLED' THEN
    CALL sp_gl_unpost_source('CREDIT_NOTE', NEW.id, 'note cancelled');
  END IF;
END $$

-- One-off, safe to repeat: puts documents posted before the books existed into the books
DROP PROCEDURE IF EXISTS sp_gl_backfill $$
CREATE PROCEDURE sp_gl_backfill()
BEGIN
  DECLARE v_id CHAR(36);
  DECLARE v_done TINYINT DEFAULT 0;
  DECLARE c1 CURSOR FOR SELECT id FROM sales_invoices si WHERE status IN ('UNPAID','PARTIAL','PAID')
        AND NOT EXISTS (SELECT 1 FROM gl_vouchers v WHERE v.source_type = 'SALES_INVOICE' AND v.source_id = si.id) ORDER BY invoice_date;
  DECLARE c2 CURSOR FOR SELECT id FROM purchase_invoices pi WHERE status IN ('UNPAID','PARTIAL','PAID')
        AND NOT EXISTS (SELECT 1 FROM gl_vouchers v WHERE v.source_type = 'PURCHASE_INVOICE' AND v.source_id = pi.id) ORDER BY invoice_date;
  DECLARE c3 CURSOR FOR SELECT id FROM payment_entries p WHERE status = 'SUBMITTED'
        AND NOT EXISTS (SELECT 1 FROM gl_vouchers v WHERE v.source_type = 'PAYMENT' AND v.source_id = p.id) ORDER BY payment_date;
  DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_done = 1;

  OPEN c1; l1: LOOP FETCH c1 INTO v_id; IF v_done = 1 THEN LEAVE l1; END IF; CALL sp_gl_post_sales_invoice(v_id); END LOOP; CLOSE c1;
  SET v_done = 0;
  OPEN c2; l2: LOOP FETCH c2 INTO v_id; IF v_done = 1 THEN LEAVE l2; END IF; CALL sp_gl_post_purchase_invoice(v_id); END LOOP; CLOSE c2;
  SET v_done = 0;
  OPEN c3; l3: LOOP FETCH c3 INTO v_id; IF v_done = 1 THEN LEAVE l3; END IF; CALL sp_gl_post_payment(v_id); END LOOP; CLOSE c3;
END $$

DELIMITER ;
