-- =============================================================================
-- HPOS Factory OS  |  09_seed.sql  -  roles, permissions, first admin
-- The admin password hash below is a placeholder that can never verify.
-- Set the real one from PHP:   php -r "echo password_hash('YourPassword', PASSWORD_ARGON2ID);"
-- then: UPDATE users SET password_hash='<hash>' WHERE email='admin@himalayaplast.local';
-- =============================================================================
USE hpos;

INSERT IGNORE INTO roles (id, name) VALUES
 (UUID(),'Admin'),(UUID(),'Manager'),(UUID(),'Production'),(UUID(),'Operator'),
 (UUID(),'QC'),(UUID(),'Store'),(UUID(),'Dispatch'),(UUID(),'Accounts'),(UUID(),'Sales');

-- resources x actions
INSERT IGNORE INTO permissions (id, resource, action)
SELECT UUID(), r.resource, a.action
  FROM (SELECT 'Dashboard' resource UNION SELECT 'users' UNION SELECT 'SalesOrders' UNION SELECT 'PurchaseOrders'
        UNION SELECT 'WorkOrders' UNION SELECT 'Production' UNION SELECT 'QC' UNION SELECT 'Stock'
        UNION SELECT 'Dispatch' UNION SELECT 'Customers' UNION SELECT 'Suppliers' UNION SELECT 'Items'
        UNION SELECT 'Workstations' UNION SELECT 'BOMs' UNION SELECT 'Accounts') r
  CROSS JOIN (SELECT 'read' action UNION SELECT 'create' UNION SELECT 'update' UNION SELECT 'delete' UNION SELECT 'scan') a;
INSERT IGNORE INTO permissions (id, resource, action) VALUES (UUID(),'*','*');

-- helper: grant (role, resource, action list)
DROP TEMPORARY TABLE IF EXISTS tmp_grants;
CREATE TEMPORARY TABLE tmp_grants (role_name VARCHAR(60), resource VARCHAR(60), action VARCHAR(30));
INSERT INTO tmp_grants VALUES
 ('Admin','*','*'),
 -- Manager: everything operational, no user admin
 ('Manager','Dashboard','read'),
 ('Manager','SalesOrders','read'),('Manager','SalesOrders','create'),('Manager','SalesOrders','update'),
 ('Manager','PurchaseOrders','read'),('Manager','PurchaseOrders','create'),('Manager','PurchaseOrders','update'),
 ('Manager','WorkOrders','read'),('Manager','WorkOrders','create'),('Manager','WorkOrders','update'),
 ('Manager','Production','read'),('Manager','QC','read'),('Manager','Stock','read'),('Manager','Dispatch','read'),
 ('Manager','Customers','read'),('Manager','Suppliers','read'),('Manager','Items','read'),
 ('Manager','Workstations','read'),('Manager','BOMs','read'),('Manager','Accounts','read'),
 -- Production
 ('Production','Dashboard','read'),('Production','SalesOrders','read'),
 ('Production','WorkOrders','read'),('Production','WorkOrders','create'),('Production','WorkOrders','update'),
 ('Production','Production','read'),('Production','Production','create'),('Production','Production','update'),
 ('Production','Stock','read'),('Production','Items','read'),('Production','BOMs','read'),
 ('Production','Workstations','read'),('Production','Workstations','update'),
 -- Operator
 ('Operator','Production','read'),('Operator','Production','create'),('Operator','WorkOrders','read'),
 ('Operator','Workstations','read'),('Operator','Items','read'),('Operator','Stock','scan'),
 -- QC
 ('QC','QC','read'),('QC','QC','create'),('QC','QC','update'),('QC','Stock','read'),('QC','Items','read'),
 ('QC','Production','read'),
 -- Store
 ('Store','Stock','read'),('Store','Stock','create'),('Store','Stock','scan'),
 ('Store','PurchaseOrders','read'),('Store','PurchaseOrders','update'),
 ('Store','Items','read'),('Store','Suppliers','read'),
 -- Dispatch
 ('Dispatch','Dispatch','read'),('Dispatch','Dispatch','create'),('Dispatch','Dispatch','scan'),
 ('Dispatch','SalesOrders','read'),('Dispatch','Stock','read'),
 -- Accounts
 ('Accounts','Dashboard','read'),('Accounts','Accounts','read'),('Accounts','Accounts','create'),
 ('Accounts','Accounts','update'),('Accounts','Accounts','delete'),
 ('Accounts','SalesOrders','read'),('Accounts','PurchaseOrders','read'),
 ('Accounts','Customers','read'),('Accounts','Suppliers','read'),('Accounts','Items','read'),
 -- Sales
 ('Sales','Dashboard','read'),('Sales','SalesOrders','read'),('Sales','SalesOrders','create'),
 ('Sales','SalesOrders','update'),('Sales','Customers','read'),('Sales','Customers','create'),
 ('Sales','Customers','update'),('Sales','Items','read'),('Sales','Stock','read');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
  FROM tmp_grants g
  JOIN roles r       ON r.name = g.role_name
  JOIN permissions p ON p.resource = g.resource COLLATE utf8mb4_unicode_ci
                    AND p.action   = g.action   COLLATE utf8mb4_unicode_ci;
DROP TEMPORARY TABLE tmp_grants;

-- first admin (placeholder hash '!' never verifies)
INSERT INTO users (id, email, name, password_hash, is_active)
SELECT UUID(), 'admin@himalayaplast.local', 'Administrator', '!', 1
 WHERE NOT EXISTS (SELECT 1 FROM users WHERE email = 'admin@himalayaplast.local');

INSERT IGNORE INTO user_roles (user_id, role_id)
SELECT u.id, r.id FROM users u JOIN roles r ON r.name = 'Admin' WHERE u.email = 'admin@himalayaplast.local';
