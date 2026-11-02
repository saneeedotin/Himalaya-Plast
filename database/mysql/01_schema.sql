-- =============================================================================
-- HPOS Factory OS  |  Himalaya Plast  |  MySQL 8.0+ / MariaDB 10.6+
-- 01_schema.sql  -  tables, keys, constraints, indexes
--
-- Run order: 01_schema -> 02_views -> 03_core_procedures -> 04_masters_orders_production
--            -> 05_buying_quality_dispatch -> 06_accounts_alerts -> 07_read_procedures
--            -> 08_triggers -> 09_seed
--
-- Notes
--  * All primary keys are CHAR(36) UUIDs (replaces Prisma cuid()).
--  * Money is DECIMAL(16,2). Quantities are DECIMAL(16,3). No floats anywhere.
--  * Column collation is case-insensitive (utf8mb4_unicode_ci), so permission
--    names such as 'SalesOrders' and 'salesorders' compare equal.
--  * Statuses are VARCHAR (not ENUM) so new states can be added without ALTER.
-- =============================================================================

CREATE DATABASE IF NOT EXISTS hpos
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE hpos;

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- -----------------------------------------------------------------------------
-- 0. SYSTEM
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS system_settings (
  setting_key   VARCHAR(64)  NOT NULL,
  setting_value VARCHAR(255) NOT NULL,
  description   VARCHAR(255) NULL,
  PRIMARY KEY (setting_key)
) ENGINE=InnoDB;

-- Gap-free, race-free document numbers (SO-2026-00001, QC-2026-00001 ...).
-- Replaces the old "count() + 1" pattern which produced duplicates under load.
CREATE TABLE IF NOT EXISTS doc_sequences (
  seq_key    VARCHAR(40) NOT NULL,
  seq_year   SMALLINT    NOT NULL,
  last_no INT         NOT NULL DEFAULT 0,
  PRIMARY KEY (seq_key, seq_year)
) ENGINE=InnoDB;

-- -----------------------------------------------------------------------------
-- 1. MASTER DATA (created first because users reference customers)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS customers (
  id         CHAR(36)     NOT NULL DEFAULT (UUID()),
  name       VARCHAR(190) NOT NULL,
  gstin      VARCHAR(15)  NULL,
  email      VARCHAR(190) NULL,
  phone      VARCHAR(40)  NULL,
  created_at DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_customers_gstin (gstin)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS suppliers (
  id         CHAR(36)     NOT NULL DEFAULT (UUID()),
  name       VARCHAR(190) NOT NULL,
  gstin      VARCHAR(15)  NULL,
  email      VARCHAR(190) NULL,
  phone      VARCHAR(40)  NULL,
  status     VARCHAR(20)  NOT NULL DEFAULT 'ACTIVE',
  created_at DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_suppliers_gstin (gstin),
  CONSTRAINT ck_suppliers_status CHECK (status IN ('ACTIVE','INACTIVE'))
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS supplier_leads (
  id             CHAR(36)     NOT NULL DEFAULT (UUID()),
  company_name   VARCHAR(190) NOT NULL,
  contact_name   VARCHAR(190) NULL,
  material       VARCHAR(190) NULL,
  source         VARCHAR(100) NULL,
  status         VARCHAR(20)  NOT NULL DEFAULT 'NEW',
  next_follow_up DATETIME(3)  NULL,
  notes          TEXT         NULL,
  owner_id       CHAR(36)     NULL,
  created_at     DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at     DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  CONSTRAINT ck_supplier_leads_status CHECK (status IN ('NEW','CONTACTED','QUALIFIED','REJECTED'))
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS items (
  id              CHAR(36)      NOT NULL DEFAULT (UUID()),
  code            VARCHAR(60)   NOT NULL,
  name            VARCHAR(255)  NOT NULL,
  category        VARCHAR(30)   NOT NULL,   -- RAW_MATERIAL, FINISHED_GOODS, PACKAGING, SCRAP
  uom             VARCHAR(20)   NOT NULL,   -- Meter, Kg, Nos
  hsn_code        VARCHAR(12)   NULL,
  standard_cost   DECIMAL(16,2) NOT NULL DEFAULT 0,
  min_stock_level DECIMAL(16,3) NOT NULL DEFAULT 0,
  qc_template     JSON          NULL,       -- array of QC parameters
  colour          VARCHAR(40)   NULL,       -- printed on the QC sheet
  lead_time_days  INT           NULL,       -- how long the supplier takes (buying advice)
  reorder_qty     DECIMAL(16,3) NULL,       -- smallest quantity worth ordering
  preferred_supplier_id CHAR(36) NULL,
  created_at      DATETIME(3)   NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at      DATETIME(3)   NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_items_code (code),
  KEY ix_items_category (category),
  CONSTRAINT fk_items_pref_supplier FOREIGN KEY (preferred_supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL,
  CONSTRAINT ck_items_category CHECK (category IN ('RAW_MATERIAL','FINISHED_GOODS','PACKAGING','SCRAP')),
  CONSTRAINT ck_items_nonneg CHECK (standard_cost >= 0 AND min_stock_level >= 0)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS workstations (
  id          CHAR(36)      NOT NULL DEFAULT (UUID()),
  code        VARCHAR(40)   NOT NULL,
  name        VARCHAR(190)  NOT NULL,
  hourly_rate DECIMAL(16,2) NOT NULL DEFAULT 0,
  status      VARCHAR(20)   NOT NULL DEFAULT 'IDLE',   -- RUNNING, IDLE, MAINTENANCE
  created_at  DATETIME(3)   NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at  DATETIME(3)   NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_workstations_code (code),
  CONSTRAINT ck_workstations_status CHECK (status IN ('RUNNING','IDLE','MAINTENANCE'))
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS dies (
  id                          CHAR(36)     NOT NULL DEFAULT (UUID()),
  code                        VARCHAR(40)  NOT NULL,
  name                        VARCHAR(190) NOT NULL,
  total_running_hours         DECIMAL(12,2) NOT NULL DEFAULT 0,
  maintenance_threshold_hours DECIMAL(12,2) NOT NULL DEFAULT 5000,
  status                      VARCHAR(20)  NOT NULL DEFAULT 'ACTIVE',
  created_at                  DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at                  DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_dies_code (code)
) ENGINE=InnoDB;

-- -----------------------------------------------------------------------------
-- 2. AUTH & ROLES
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
  id                  CHAR(36)     NOT NULL DEFAULT (UUID()),
  email               VARCHAR(190) NOT NULL,
  name                VARCHAR(190) NOT NULL,
  password_hash       VARCHAR(255) NOT NULL,       -- argon2id from PHP password_hash()
  is_active           TINYINT(1)   NOT NULL DEFAULT 1,
  failed_attempts     INT          NOT NULL DEFAULT 0,
  locked_until        DATETIME(3)  NULL,
  last_login_at       DATETIME(3)  NULL,
  employee_code       VARCHAR(40)  NULL,
  assigned_machine_id CHAR(36)     NULL,
  dob                 DATE         NULL,
  phone               VARCHAR(40)  NULL,
  bio                 TEXT         NULL,
  customer_id         CHAR(36)     NULL,           -- set for client-portal users
  created_at          DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at          DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email),
  UNIQUE KEY uq_users_employee_code (employee_code),
  CONSTRAINT fk_users_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS roles (
  id   CHAR(36)    NOT NULL DEFAULT (UUID()),
  name VARCHAR(60) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_roles_name (name)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS user_roles (
  user_id CHAR(36) NOT NULL,
  role_id CHAR(36) NOT NULL,
  PRIMARY KEY (user_id, role_id),
  CONSTRAINT fk_user_roles_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_user_roles_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS permissions (
  id       CHAR(36)    NOT NULL DEFAULT (UUID()),
  resource VARCHAR(60) NOT NULL,                  -- '*' = all resources
  action   VARCHAR(30) NOT NULL,                  -- '*' = all actions
  PRIMARY KEY (id),
  UNIQUE KEY uq_permissions (resource, action)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS role_permissions (
  role_id       CHAR(36) NOT NULL,
  permission_id CHAR(36) NOT NULL,
  PRIMARY KEY (role_id, permission_id),
  CONSTRAINT fk_rp_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
  CONSTRAINT fk_rp_perm FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS sessions (
  id         CHAR(36)     NOT NULL DEFAULT (UUID()),
  user_id    CHAR(36)     NOT NULL,
  token_hash CHAR(64)     NOT NULL,               -- SHA-256 hex of the cookie token
  expires_at DATETIME(3)  NOT NULL,
  ip         VARCHAR(64)  NULL,
  user_agent VARCHAR(255) NULL,
  created_at DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_sessions_token (token_hash),
  KEY ix_sessions_user (user_id),
  KEY ix_sessions_expires (expires_at),
  CONSTRAINT fk_sessions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS audit_logs (
  id         CHAR(36)     NOT NULL DEFAULT (UUID()),
  user_id    CHAR(36)     NULL,
  event      VARCHAR(80)  NOT NULL,
  ip         VARCHAR(64)  NULL,
  metadata   JSON         NULL,
  created_at DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY ix_audit_user (user_id),
  KEY ix_audit_event (event, created_at),
  CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- -----------------------------------------------------------------------------
-- 3. BATCHES, BUYING
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS batches (
  id              CHAR(36)      NOT NULL DEFAULT (UUID()),
  batch_number    VARCHAR(80)   NOT NULL,
  item_id         CHAR(36)      NOT NULL,
  quantity        DECIMAL(16,3) NOT NULL,
  uom             VARCHAR(20)   NOT NULL,
  source          VARCHAR(20)   NOT NULL,          -- PURCHASE, EXTRUSION
  parent_batch_id CHAR(36)      NULL,
  created_at      DATETIME(3)   NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_batches_number (batch_number),
  KEY ix_batches_item (item_id),
  CONSTRAINT fk_batches_item   FOREIGN KEY (item_id) REFERENCES items(id),
  CONSTRAINT fk_batches_parent FOREIGN KEY (parent_batch_id) REFERENCES batches(id) ON DELETE SET NULL,
  CONSTRAINT ck_batches_qty CHECK (quantity >= 0)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS purchase_orders (
  id           CHAR(36)      NOT NULL DEFAULT (UUID()),
  po_number    VARCHAR(40)   NOT NULL,             -- BUY-PO-2026-00001
  supplier_id  CHAR(36)      NOT NULL,
  order_date   DATETIME(3)   NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  status       VARCHAR(20)   NOT NULL DEFAULT 'DRAFT',
  total_amount DECIMAL(16,2) NOT NULL DEFAULT 0,   -- maintained by triggers
  notes        TEXT          NULL,
  created_at   DATETIME(3)   NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at   DATETIME(3)   NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_po_number (po_number),
  KEY ix_po_supplier (supplier_id),
  KEY ix_po_status (status),
  CONSTRAINT fk_po_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id),
  CONSTRAINT ck_po_status CHECK (status IN ('DRAFT','SUBMITTED','PARTIAL','RECEIVED','CLOSED'))
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS purchase_order_items (
  id                CHAR(36)      NOT NULL DEFAULT (UUID()),
  purchase_order_id CHAR(36)      NOT NULL,
  item_id           CHAR(36)      NOT NULL,
  qty               DECIMAL(16,3) NOT NULL,
  rate              DECIMAL(16,2) NOT NULL,
  amount            DECIMAL(16,2) NOT NULL DEFAULT 0,   -- = qty * rate (trigger)
  PRIMARY KEY (id),
  KEY ix_poi_po (purchase_order_id),
  KEY ix_poi_item (item_id),
  CONSTRAINT fk_poi_po   FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_poi_item FOREIGN KEY (item_id) REFERENCES items(id),
  CONSTRAINT ck_poi_qty CHECK (qty > 0 AND rate >= 0)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS purchase_receipts (
  id                CHAR(36)      NOT NULL DEFAULT (UUID()),
  receipt_number    VARCHAR(40)   NOT NULL,        -- GRN-2026-00001
  purchase_order_id CHAR(36)      NOT NULL,
  supplier_id       CHAR(36)      NOT NULL,
  receipt_date      DATETIME(3)   NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  status            VARCHAR(20)   NOT NULL DEFAULT 'DRAFT',
  total_amount      DECIMAL(16,2) NOT NULL DEFAULT 0,
  notes             TEXT          NULL,
  created_at        DATETIME(3)   NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at        DATETIME(3)   NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_pr_number (receipt_number),
  KEY ix_pr_po (purchase_order_id),
  CONSTRAINT fk_pr_po       FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(id),
  CONSTRAINT fk_pr_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id),
  CONSTRAINT ck_pr_status CHECK (status IN ('DRAFT','SUBMITTED','CANCELLED'))
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS purchase_receipt_items (
  id                  CHAR(36)      NOT NULL DEFAULT (UUID()),
  purchase_receipt_id CHAR(36)      NOT NULL,
  item_id             CHAR(36)      NOT NULL,
  batch_id            CHAR(36)      NULL,
  qty                 DECIMAL(16,3) NOT NULL,
  rate                DECIMAL(16,2) NOT NULL,
  amount              DECIMAL(16,2) NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY ix_pri_pr (purchase_receipt_id),
  CONSTRAINT fk_pri_pr    FOREIGN KEY (purchase_receipt_id) REFERENCES purchase_receipts(id) ON DELETE CASCADE,
  CONSTRAINT fk_pri_item  FOREIGN KEY (item_id)  REFERENCES items(id),
  CONSTRAINT fk_pri_batch FOREIGN KEY (batch_id) REFERENCES batches(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- -----------------------------------------------------------------------------
-- 4. SELLING, BOM, PRODUCTION
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sales_orders (
  id               CHAR(36)      NOT NULL DEFAULT (UUID()),
  order_number     VARCHAR(40)   NOT NULL,
  customer_name    VARCHAR(190)  NOT NULL,
  customer_id      CHAR(36)      NULL,
  customer_gstin   VARCHAR(15)   NULL,
  transaction_date DATETIME(3)   NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  delivery_date    DATETIME(3)   NOT NULL,
  status           VARCHAR(30)   NOT NULL DEFAULT 'DRAFT',
    -- DRAFT, PROFORMA_SENT, CONFIRMED, APPROVED, IN_PRODUCTION, READY_TO_DISPATCH,
    -- DISPATCH_PREP, DISPATCHED, COMPLETED, CANCELLED
  approval_method  VARCHAR(20)   NOT NULL DEFAULT 'WHATSAPP',  -- WHATSAPP, EMAIL, PHONE, VERBAL
  total_amount     DECIMAL(16,2) NOT NULL DEFAULT 0,           -- maintained by triggers
  notes            TEXT          NULL,
  proforma_ref     VARCHAR(80)   NULL,
  proforma_sent_at DATETIME(3)   NULL,
  confirmed_at     DATETIME(3)   NULL,
  confirmed_by_id  CHAR(36)      NULL,
  created_at       DATETIME(3)   NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at       DATETIME(3)   NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_so_number (order_number),
  KEY ix_so_customer (customer_id),
  KEY ix_so_status (status),
  KEY ix_so_delivery (delivery_date),
  CONSTRAINT fk_so_customer  FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
  CONSTRAINT fk_so_confirmer FOREIGN KEY (confirmed_by_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS sales_order_items (
  id             CHAR(36)      NOT NULL DEFAULT (UUID()),
  sales_order_id CHAR(36)      NOT NULL,
  item_id        CHAR(36)      NOT NULL,
  qty            DECIMAL(16,3) NOT NULL,
  rate           DECIMAL(16,2) NOT NULL,
  amount         DECIMAL(16,2) NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY ix_soi_order (sales_order_id),
  KEY ix_soi_item (item_id),
  CONSTRAINT fk_soi_order FOREIGN KEY (sales_order_id) REFERENCES sales_orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_soi_item  FOREIGN KEY (item_id) REFERENCES items(id),
  CONSTRAINT ck_soi_qty CHECK (qty > 0 AND rate >= 0)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS boms (
  id           CHAR(36)     NOT NULL DEFAULT (UUID()),
  name         VARCHAR(190) NOT NULL,
  fg_item_id   CHAR(36)     NOT NULL,
  output_qty   DECIMAL(16,3) NOT NULL DEFAULT 1,
  scrap_factor DECIMAL(6,4) NOT NULL DEFAULT 0.0250,   -- 2.5 % purge & trim
  created_at   DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at   DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY ix_boms_fg (fg_item_id),
  CONSTRAINT fk_boms_fg FOREIGN KEY (fg_item_id) REFERENCES items(id),
  CONSTRAINT ck_boms_vals CHECK (output_qty > 0 AND scrap_factor >= 0 AND scrap_factor < 1)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS bom_items (
  id           CHAR(36)      NOT NULL DEFAULT (UUID()),
  bom_id       CHAR(36)      NOT NULL,
  rm_item_id   CHAR(36)      NOT NULL,
  qty_per_unit DECIMAL(14,6) NOT NULL,
  PRIMARY KEY (id),
  KEY ix_bomi_bom (bom_id),
  KEY ix_bomi_rm (rm_item_id),
  CONSTRAINT fk_bomi_bom FOREIGN KEY (bom_id) REFERENCES boms(id) ON DELETE CASCADE,
  CONSTRAINT fk_bomi_rm  FOREIGN KEY (rm_item_id) REFERENCES items(id),
  CONSTRAINT ck_bomi_qty CHECK (qty_per_unit > 0)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS work_orders (
  id                CHAR(36)      NOT NULL DEFAULT (UUID()),
  work_order_number VARCHAR(40)   NOT NULL,
  sales_order_id    CHAR(36)      NULL,
  fg_item_id        CHAR(36)      NOT NULL,
  planned_qty       DECIMAL(16,3) NOT NULL,
  produced_qty      DECIMAL(16,3) NOT NULL DEFAULT 0,
  status            VARCHAR(20)   NOT NULL DEFAULT 'PENDING',
  fg_batch_number   VARCHAR(80)   NULL,
  start_date        DATETIME(3)   NULL,
  end_date          DATETIME(3)   NULL,
  created_at        DATETIME(3)   NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at        DATETIME(3)   NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_wo_number (work_order_number),
  KEY ix_wo_so (sales_order_id),
  KEY ix_wo_status (status),
  CONSTRAINT fk_wo_so   FOREIGN KEY (sales_order_id) REFERENCES sales_orders(id) ON DELETE SET NULL,
  CONSTRAINT fk_wo_item FOREIGN KEY (fg_item_id) REFERENCES items(id),
  CONSTRAINT ck_wo_status CHECK (status IN ('PENDING','IN_PROGRESS','COMPLETED','CANCELLED')),
  CONSTRAINT ck_wo_qty CHECK (planned_qty > 0 AND produced_qty >= 0)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS job_cards (
  id               CHAR(36)      NOT NULL DEFAULT (UUID()),
  work_order_id    CHAR(36)      NOT NULL,
  workstation_id   CHAR(36)      NOT NULL,
  assigned_user_id CHAR(36)      NULL,
  die_id           CHAR(36)      NULL,
  planned_start    DATE          NULL,                        -- the planning board's slot on this machine
  planned_end      DATE          NULL,
  status           VARCHAR(20)   NOT NULL DEFAULT 'QUEUED',   -- QUEUED, ACTIVE, COMPLETED
  good_qty         DECIMAL(16,3) NOT NULL DEFAULT 0,
  scrap_qty        DECIMAL(16,3) NOT NULL DEFAULT 0,
  started_at       DATETIME(3)   NULL,
  completed_at     DATETIME(3)   NULL,
  created_at       DATETIME(3)   NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at       DATETIME(3)   NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY ix_jc_wo (work_order_id),
  KEY ix_jc_ws (workstation_id),
  KEY ix_jc_status (status),
  CONSTRAINT fk_jc_wo   FOREIGN KEY (work_order_id)  REFERENCES work_orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_jc_ws   FOREIGN KEY (workstation_id) REFERENCES workstations(id),
  CONSTRAINT fk_jc_user FOREIGN KEY (assigned_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_jc_die  FOREIGN KEY (die_id) REFERENCES dies(id) ON DELETE SET NULL,
  CONSTRAINT ck_jc_status CHECK (status IN ('QUEUED','ACTIVE','COMPLETED')),
  CONSTRAINT ck_jc_plan CHECK ((planned_start IS NULL AND planned_end IS NULL) OR (planned_start IS NOT NULL AND planned_end IS NOT NULL AND planned_end >= planned_start))
) ENGINE=InnoDB;

-- -----------------------------------------------------------------------------
-- 5. INVENTORY (append-only ledger + reservations)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS stock_ledger_entries (
  id            CHAR(36)      NOT NULL DEFAULT (UUID()),
  item_id       CHAR(36)      NOT NULL,
  batch_id      CHAR(36)      NULL,
  warehouse     VARCHAR(20)   NOT NULL DEFAULT 'MAIN',      -- MAIN, QC_HOLD
  quantity      DECIMAL(16,3) NOT NULL,                     -- always positive; sign comes from movement_type
  movement_type VARCHAR(20)   NOT NULL,
  reference_id  VARCHAR(64)   NULL,                         -- PO / WO / receipt id (polymorphic)
  notes         VARCHAR(500)  NULL,
  is_opening    TINYINT(1)    NOT NULL DEFAULT 0,        -- opening stock entered by hand: capital, not profit
  created_at    DATETIME(3)   NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY ix_sle_item (item_id, created_at),
  KEY ix_sle_batch (batch_id),
  KEY ix_sle_ref (reference_id),
  CONSTRAINT fk_sle_item  FOREIGN KEY (item_id) REFERENCES items(id),
  CONSTRAINT fk_sle_batch FOREIGN KEY (batch_id) REFERENCES batches(id) ON DELETE SET NULL,
  CONSTRAINT ck_sle_type CHECK (movement_type IN
    ('RECEIPT','PRODUCTION_OUTPUT','PRODUCTION','RECOVERY','ADJUSTMENT_IN',
     'CONSUMPTION','SCRAP','DISPATCH','ADJUSTMENT_OUT','TRANSFER')),
  CONSTRAINT ck_sle_qty CHECK (quantity >= 0)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS stock_reservations (
  id             CHAR(36)      NOT NULL DEFAULT (UUID()),
  item_id        CHAR(36)      NOT NULL,
  sales_order_id CHAR(36)      NOT NULL,
  quantity       DECIMAL(16,3) NOT NULL,
  status         VARCHAR(20)   NOT NULL DEFAULT 'RESERVED',  -- RESERVED, CONSUMED, CANCELLED
  created_at     DATETIME(3)   NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY ix_sr_item (item_id, status),
  KEY ix_sr_order (sales_order_id),
  CONSTRAINT fk_sr_item  FOREIGN KEY (item_id) REFERENCES items(id),
  CONSTRAINT fk_sr_order FOREIGN KEY (sales_order_id) REFERENCES sales_orders(id) ON DELETE CASCADE,
  CONSTRAINT ck_sr_status CHECK (status IN ('RESERVED','CONSUMED','CANCELLED')),
  CONSTRAINT ck_sr_qty CHECK (quantity >= 0)
) ENGINE=InnoDB;

-- -----------------------------------------------------------------------------
-- 6. QUALITY, PACKING, DISPATCH
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS quality_inspections (
  id             CHAR(36)     NOT NULL DEFAULT (UUID()),
  report_number  VARCHAR(40)  NOT NULL,
  job_card_id    CHAR(36)     NULL,
  batch_number   VARCHAR(80)  NOT NULL,
  status         VARCHAR(20)  NOT NULL DEFAULT 'DRAFT',   -- DRAFT, PENDING, PASS, REJECT, SCRAP, REWORK
  sample_size    INT          NOT NULL DEFAULT 5,
  inspector_name VARCHAR(190) NOT NULL,
  inspector_id   CHAR(36)     NULL,                       -- real user, so the name cannot be forged
  inspected_at   DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  rework_notes   TEXT         NULL,
  qc_data        JSON         NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_qi_report (report_number),
  KEY ix_qi_batch (batch_number),
  KEY ix_qi_status (status),
  CONSTRAINT fk_qi_jc   FOREIGN KEY (job_card_id) REFERENCES job_cards(id) ON DELETE SET NULL,
  CONSTRAINT fk_qi_user FOREIGN KEY (inspector_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT ck_qi_status CHECK (status IN ('DRAFT','PENDING','PASS','REJECT','SCRAP','REWORK'))
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS delivery_notes (
  id               CHAR(36)     NOT NULL DEFAULT (UUID()),
  dn_number        VARCHAR(40)  NOT NULL,
  sales_order_id   CHAR(36)     NOT NULL,
  customer_name    VARCHAR(190) NOT NULL,
  transporter_name VARCHAR(190) NULL,
  vehicle_number   VARCHAR(40)  NULL,
  lr_number        VARCHAR(60)  NULL,
  status           VARCHAR(20)  NOT NULL DEFAULT 'DRAFT',   -- DRAFT, DISPATCHED, DELIVERED
  dispatched_at    DATETIME(3)  NULL,
  created_at       DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at       DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_dn_number (dn_number),
  KEY ix_dn_so (sales_order_id),
  CONSTRAINT fk_dn_so FOREIGN KEY (sales_order_id) REFERENCES sales_orders(id),
  CONSTRAINT ck_dn_status CHECK (status IN ('DRAFT','DISPATCHED','DELIVERED'))
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS carton_labels (
  id                     CHAR(36)      NOT NULL DEFAULT (UUID()),
  carton_code            VARCHAR(80)   NOT NULL,
  delivery_note_id       CHAR(36)      NOT NULL,
  batch_id               CHAR(36)      NOT NULL,
  quantity               DECIMAL(16,3) NOT NULL,
  scanned                TINYINT(1)    NOT NULL DEFAULT 0,
  scanned_by_id          CHAR(36)      NULL,
  scanned_at             DATETIME(3)   NULL,
  dispatch_confirmed     TINYINT(1)    NOT NULL DEFAULT 0,
  dispatch_override      TINYINT(1)    NOT NULL DEFAULT 0,
  dispatch_override_note VARCHAR(500)  NULL,
  created_at             DATETIME(3)   NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_carton_code (carton_code),
  KEY ix_cl_dn (delivery_note_id),
  KEY ix_cl_batch (batch_id),
  CONSTRAINT fk_cl_dn      FOREIGN KEY (delivery_note_id) REFERENCES delivery_notes(id),
  CONSTRAINT fk_cl_batch   FOREIGN KEY (batch_id) REFERENCES batches(id),
  CONSTRAINT fk_cl_scanner FOREIGN KEY (scanned_by_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT ck_cl_qty CHECK (quantity > 0)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS tally_sync_logs (
  id            CHAR(36)    NOT NULL DEFAULT (UUID()),
  document_type VARCHAR(40) NOT NULL,
  document_id   CHAR(36)    NOT NULL,
  exported_xml  LONGTEXT    NOT NULL,
  status        VARCHAR(20) NOT NULL,
  synced_at     DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  error_message TEXT        NULL,
  PRIMARY KEY (id),
  KEY ix_tsl_doc (document_type, document_id)
) ENGINE=InnoDB;

-- -----------------------------------------------------------------------------
-- 7. SHOP FLOOR
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS downtime_logs (
  id             CHAR(36)     NOT NULL DEFAULT (UUID()),
  workstation_id CHAR(36)     NOT NULL,
  job_card_id    CHAR(36)     NULL,
  reason_code    VARCHAR(60)  NOT NULL,
  start_time     DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  end_time       DATETIME(3)  NULL,
  duration_mins  INT          NULL,
  notes          TEXT         NULL,
  logged_by_id   CHAR(36)     NOT NULL,
  PRIMARY KEY (id),
  KEY ix_dl_ws (workstation_id, start_time),
  CONSTRAINT fk_dl_ws   FOREIGN KEY (workstation_id) REFERENCES workstations(id),
  CONSTRAINT fk_dl_jc   FOREIGN KEY (job_card_id) REFERENCES job_cards(id) ON DELETE SET NULL,
  CONSTRAINT fk_dl_user FOREIGN KEY (logged_by_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS shift_handovers (
  id                   CHAR(36)      NOT NULL DEFAULT (UUID()),
  shift_date           DATETIME(3)   NOT NULL,
  shift_type           VARCHAR(20)   NOT NULL,
  workstation_id       CHAR(36)      NOT NULL,
  outgoing_operator_id CHAR(36)      NOT NULL,
  incoming_operator_id CHAR(36)      NULL,
  scrap_generated_qty  DECIMAL(16,3) NOT NULL DEFAULT 0,
  notes                TEXT          NULL,
  status               VARCHAR(20)   NOT NULL DEFAULT 'PENDING',   -- PENDING, ACCEPTED
  created_at           DATETIME(3)   NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  accepted_at          DATETIME(3)   NULL,
  PRIMARY KEY (id),
  KEY ix_sh_ws (workstation_id, shift_date),
  CONSTRAINT fk_sh_ws  FOREIGN KEY (workstation_id) REFERENCES workstations(id),
  CONSTRAINT fk_sh_out FOREIGN KEY (outgoing_operator_id) REFERENCES users(id),
  CONSTRAINT fk_sh_in  FOREIGN KEY (incoming_operator_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS material_scan_logs (
  id                CHAR(36)    NOT NULL DEFAULT (UUID()),
  job_card_id       CHAR(36)    NOT NULL,
  scanned_batch_id  CHAR(36)    NOT NULL,
  expected_item_id  CHAR(36)    NOT NULL,
  status            VARCHAR(20) NOT NULL,                       -- MATCH, MISMATCH
  scanned_by_id     CHAR(36)    NOT NULL,
  scanned_at        DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY ix_msl_jc (job_card_id),
  CONSTRAINT fk_msl_jc    FOREIGN KEY (job_card_id) REFERENCES job_cards(id),
  CONSTRAINT fk_msl_batch FOREIGN KEY (scanned_batch_id) REFERENCES batches(id),
  CONSTRAINT fk_msl_item  FOREIGN KEY (expected_item_id) REFERENCES items(id),
  CONSTRAINT fk_msl_user  FOREIGN KEY (scanned_by_id) REFERENCES users(id)
) ENGINE=InnoDB;

-- -----------------------------------------------------------------------------
-- 8. CRM & NOTIFICATIONS
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS customer_follow_ups (
  id                      CHAR(36)    NOT NULL DEFAULT (UUID()),
  customer_id             CHAR(36)    NOT NULL,
  last_order_date         DATETIME(3) NULL,
  expected_next_order_date DATETIME(3) NULL,
  reminder_date           DATETIME(3) NULL,
  reminder_status         VARCHAR(20) NOT NULL DEFAULT 'PENDING',   -- PENDING, COMPLETED, DISMISSED
  notes                   TEXT        NULL,
  assigned_user_id        CHAR(36)    NULL,
  created_at              DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at              DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY ix_cfu_customer (customer_id),
  KEY ix_cfu_reminder (reminder_status, reminder_date),
  CONSTRAINT fk_cfu_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
  CONSTRAINT fk_cfu_user     FOREIGN KEY (assigned_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS notifications (
  id          CHAR(36)     NOT NULL DEFAULT (UUID()),
  title       VARCHAR(255) NOT NULL,
  message     TEXT         NOT NULL,
  priority    VARCHAR(20)  NOT NULL,       -- CRITICAL, ACTION_REQUIRED, UPDATE, SUCCESS
  status      VARCHAR(20)  NOT NULL DEFAULT 'UNREAD',   -- UNREAD, READ, RESOLVED
  source_type VARCHAR(20)  NOT NULL,       -- ORDER, MATERIAL, MACHINE, QC, SYSTEM, CUSTOMER, FINANCE
  source_id   VARCHAR(64)  NULL,
  action_url  VARCHAR(255) NULL,
  audience    VARCHAR(160) NULL,           -- NULL = everyone; else comma-separated resources, seeing any one of them (read right) is enough
  push_state  TINYINT      NOT NULL DEFAULT 0,   -- 0 = web push still to be sent, 1 = sent or not needed
  created_at  DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at  DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY ix_notif_status (status, created_at),
  KEY ix_notif_src (source_type, source_id),
  KEY ix_notif_push (push_state, created_at)
) ENGINE=InnoDB;

-- A notification is read per person: one row here means that user has read it.
-- status on notifications itself is only UNREAD or RESOLVED (the thing it warned about is dealt with).
CREATE TABLE IF NOT EXISTS notification_reads (
  notification_id CHAR(36)    NOT NULL,
  user_id         CHAR(36)    NOT NULL,
  read_at         DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (notification_id, user_id),
  KEY ix_nr_user (user_id),
  CONSTRAINT fk_nr_notif FOREIGN KEY (notification_id) REFERENCES notifications(id) ON DELETE CASCADE,
  CONSTRAINT fk_nr_user  FOREIGN KEY (user_id)         REFERENCES users(id)         ON DELETE CASCADE
) ENGINE=InnoDB;

-- Browsers that agreed to Web Push (one row per browser; endpoint_hash makes the long endpoint unique).
CREATE TABLE IF NOT EXISTS push_subscriptions (
  id            CHAR(36)     NOT NULL DEFAULT (UUID()),
  user_id       CHAR(36)     NOT NULL,
  endpoint      VARCHAR(600) NOT NULL,
  endpoint_hash CHAR(64)     NOT NULL,
  p256dh        VARCHAR(255) NOT NULL,
  auth          VARCHAR(255) NOT NULL,
  user_agent    VARCHAR(255) NULL,
  created_at    DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  last_ok_at    DATETIME(3)  NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_push_endpoint (endpoint_hash),
  KEY ix_push_user (user_id),
  CONSTRAINT fk_push_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- When a periodic job last ran, so the app can run the alert check itself when cron is not set up.
CREATE TABLE IF NOT EXISTS job_runs (
  job_name VARCHAR(60) NOT NULL,
  last_run DATETIME(3) NOT NULL,
  PRIMARY KEY (job_name)
) ENGINE=InnoDB;

-- -----------------------------------------------------------------------------
-- 9. ACCOUNTS
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sales_invoices (
  id               CHAR(36)      NOT NULL DEFAULT (UUID()),
  invoice_number   VARCHAR(40)   NOT NULL,
  sales_order_id   CHAR(36)      NULL,
  delivery_note_id CHAR(36)      NULL,
  customer_id      CHAR(36)      NOT NULL,
  invoice_date     DATETIME(3)   NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  due_date         DATETIME(3)   NOT NULL,
  status           VARCHAR(20)   NOT NULL DEFAULT 'DRAFT',
  subtotal_amount  DECIMAL(16,2) NOT NULL DEFAULT 0,
  tax_amount       DECIMAL(16,2) NOT NULL DEFAULT 0,
  cgst_amount      DECIMAL(16,2) NOT NULL DEFAULT 0,
  sgst_amount      DECIMAL(16,2) NOT NULL DEFAULT 0,
  igst_amount      DECIMAL(16,2) NOT NULL DEFAULT 0,
  total_amount     DECIMAL(16,2) NOT NULL DEFAULT 0,
  outstanding      DECIMAL(16,2) NOT NULL DEFAULT 0,
  irn              VARCHAR(100)  NULL,       -- must come from the GST e-invoice portal, never generated here
  irn_qr_code      TEXT          NULL,
  eway_bill_number VARCHAR(20)   NULL,
  created_at       DATETIME(3)   NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at       DATETIME(3)   NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_si_number (invoice_number),
  KEY ix_si_customer (customer_id),
  KEY ix_si_status (status, due_date),
  CONSTRAINT fk_si_so       FOREIGN KEY (sales_order_id)   REFERENCES sales_orders(id) ON DELETE SET NULL,
  CONSTRAINT fk_si_dn       FOREIGN KEY (delivery_note_id) REFERENCES delivery_notes(id) ON DELETE SET NULL,
  CONSTRAINT fk_si_customer FOREIGN KEY (customer_id)      REFERENCES customers(id),
  CONSTRAINT ck_si_status CHECK (status IN ('DRAFT','UNPAID','PARTIAL','PAID','CANCELLED'))
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS sales_invoice_items (
  id               CHAR(36)      NOT NULL DEFAULT (UUID()),
  sales_invoice_id CHAR(36)      NOT NULL,
  item_id          CHAR(36)      NOT NULL,
  qty              DECIMAL(16,3) NOT NULL,
  rate             DECIMAL(16,2) NOT NULL,
  amount           DECIMAL(16,2) NOT NULL DEFAULT 0,
  tax_rate         DECIMAL(5,2)  NOT NULL DEFAULT 18.00,
  tax_amount       DECIMAL(16,2) NOT NULL DEFAULT 0,
  total_amount     DECIMAL(16,2) NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY ix_sii_inv (sales_invoice_id),
  CONSTRAINT fk_sii_inv  FOREIGN KEY (sales_invoice_id) REFERENCES sales_invoices(id) ON DELETE CASCADE,
  CONSTRAINT fk_sii_item FOREIGN KEY (item_id) REFERENCES items(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS purchase_invoices (
  id              CHAR(36)      NOT NULL DEFAULT (UUID()),
  invoice_number  VARCHAR(40)   NOT NULL,
  supplier_id     CHAR(36)      NOT NULL,
  invoice_date    DATETIME(3)   NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  due_date        DATETIME(3)   NOT NULL,
  status          VARCHAR(20)   NOT NULL DEFAULT 'DRAFT',
  subtotal_amount DECIMAL(16,2) NOT NULL DEFAULT 0,
  tax_amount      DECIMAL(16,2) NOT NULL DEFAULT 0,
  cgst_amount     DECIMAL(16,2) NOT NULL DEFAULT 0,
  sgst_amount     DECIMAL(16,2) NOT NULL DEFAULT 0,
  igst_amount     DECIMAL(16,2) NOT NULL DEFAULT 0,
  total_amount    DECIMAL(16,2) NOT NULL DEFAULT 0,
  outstanding     DECIMAL(16,2) NOT NULL DEFAULT 0,
  created_at      DATETIME(3)   NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at      DATETIME(3)   NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_pi_number (invoice_number),
  KEY ix_pi_supplier (supplier_id),
  KEY ix_pi_status (status, due_date),
  CONSTRAINT fk_pi_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id),
  CONSTRAINT ck_pi_status CHECK (status IN ('DRAFT','UNPAID','PARTIAL','PAID','CANCELLED'))
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS purchase_invoice_items (
  id                  CHAR(36)      NOT NULL DEFAULT (UUID()),
  purchase_invoice_id CHAR(36)      NOT NULL,
  item_id             CHAR(36)      NOT NULL,
  qty                 DECIMAL(16,3) NOT NULL,
  rate                DECIMAL(16,2) NOT NULL,
  amount              DECIMAL(16,2) NOT NULL DEFAULT 0,
  tax_rate            DECIMAL(5,2)  NOT NULL DEFAULT 18.00,
  tax_amount          DECIMAL(16,2) NOT NULL DEFAULT 0,
  total_amount        DECIMAL(16,2) NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY ix_pii_inv (purchase_invoice_id),
  CONSTRAINT fk_pii_inv  FOREIGN KEY (purchase_invoice_id) REFERENCES purchase_invoices(id) ON DELETE CASCADE,
  CONSTRAINT fk_pii_item FOREIGN KEY (item_id) REFERENCES items(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS payment_entries (
  id                  CHAR(36)      NOT NULL DEFAULT (UUID()),
  payment_number      VARCHAR(40)   NOT NULL,
  party_type          VARCHAR(20)   NOT NULL,           -- CUSTOMER, SUPPLIER
  customer_id         CHAR(36)      NULL,
  supplier_id         CHAR(36)      NULL,
  sales_invoice_id    CHAR(36)      NULL,
  purchase_invoice_id CHAR(36)      NULL,
  payment_date        DATETIME(3)   NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  mode_of_payment     VARCHAR(20)   NOT NULL,           -- BANK_TRANSFER, CASH, CHEQUE, UPI
  reference_number    VARCHAR(80)   NULL,
  amount              DECIMAL(16,2) NOT NULL,
  status              VARCHAR(20)   NOT NULL DEFAULT 'DRAFT',   -- DRAFT, SUBMITTED, CANCELLED
  account_id          CHAR(36)      NULL,                       -- the cash or bank account it went through (NULL = by mode of payment)
  created_at          DATETIME(3)   NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at          DATETIME(3)   NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_pay_number (payment_number),
  KEY ix_pay_si (sales_invoice_id),
  KEY ix_pay_pi (purchase_invoice_id),
  CONSTRAINT fk_pay_customer FOREIGN KEY (customer_id) REFERENCES customers(id),
  CONSTRAINT fk_pay_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id),
  CONSTRAINT fk_pay_si FOREIGN KEY (sales_invoice_id)    REFERENCES sales_invoices(id),
  CONSTRAINT fk_pay_pi FOREIGN KEY (purchase_invoice_id) REFERENCES purchase_invoices(id),
  CONSTRAINT ck_pay_status CHECK (status IN ('DRAFT','SUBMITTED','CANCELLED')),
  CONSTRAINT ck_pay_party CHECK (party_type IN ('CUSTOMER','SUPPLIER')),
  CONSTRAINT ck_pay_amount CHECK (amount > 0)
) ENGINE=InnoDB;

SET FOREIGN_KEY_CHECKS = 1;

-- Defaults used by the invoicing procedures
INSERT INTO system_settings (setting_key, setting_value, description) VALUES
  ('company_name',        'Himalaya Plast',  'Seller name on invoices'),
  ('company_gstin',       '',                'Seller GSTIN - fill in before invoicing'),
  ('company_state_code',  '24',              'GST state code of the factory (24 = Gujarat); decides CGST+SGST vs IGST'),
  ('default_tax_rate',    '18.00',           'Default GST % for new invoice lines'),
  ('default_credit_days', '30',              'Default invoice due days')
ON DUPLICATE KEY UPDATE setting_key = setting_key;
