USE pos_system;

ALTER TABLE users
  MODIFY role ENUM('Admin','Area Manager','Manager','Cashier','Inventory Clerk','Purchasing Staff') NOT NULL DEFAULT 'Cashier';

ALTER TABLE role_permissions
  MODIFY role_name ENUM('Admin','Area Manager','Manager','Cashier','Inventory Clerk','Purchasing Staff') NOT NULL;

INSERT IGNORE INTO role_permissions(role_name, permission_key, is_allowed) VALUES
('Area Manager', 'categories.view', 1),
('Area Manager', 'suppliers.view', 1),
('Manager', 'categories.view', 1),
('Manager', 'suppliers.view', 1),
('Inventory Clerk', 'dashboard.view', 1),
('Inventory Clerk', 'products.view', 1),
('Inventory Clerk', 'categories.view', 1),
('Inventory Clerk', 'inventory.view', 1),
('Inventory Clerk', 'inventory.manage', 1),
('Inventory Clerk', 'reports.view', 1),
('Purchasing Staff', 'dashboard.view', 1),
('Purchasing Staff', 'suppliers.view', 1),
('Purchasing Staff', 'suppliers.manage', 1),
('Purchasing Staff', 'purchases.view', 1),
('Purchasing Staff', 'purchases.manage', 1),
('Purchasing Staff', 'inventory.view', 1),
('Purchasing Staff', 'reports.view', 1);
