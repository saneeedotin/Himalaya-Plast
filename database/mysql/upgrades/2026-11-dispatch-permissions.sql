-- Upgrade for databases loaded before this change (run once, as the account that loaded 01-09).
-- The Dispatch role no longer holds SalesOrders:update (it let dispatch staff confirm and cancel orders).
-- Re-run 04_masters_orders_production.sql first: sp_dispatch_gate_update now checks Dispatch:create.
USE hpos;
DELETE rp FROM role_permissions rp
  JOIN roles r ON r.id = rp.role_id
  JOIN permissions p ON p.id = rp.permission_id
 WHERE r.name = 'Dispatch' AND p.resource = 'SalesOrders' AND p.action = 'update';
