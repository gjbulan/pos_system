CREATE DATABASE IF NOT EXISTS pos_system CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE pos_system;

DROP TABLE IF EXISTS audit_logs;
DROP TABLE IF EXISTS cash_drawer_transactions;
DROP TABLE IF EXISTS cash_sessions;
DROP TABLE IF EXISTS inventory_movements;
DROP TABLE IF EXISTS purchase_order_items;
DROP TABLE IF EXISTS purchase_orders;
DROP TABLE IF EXISTS sales_return_items;
DROP TABLE IF EXISTS sales_returns;
DROP TABLE IF EXISTS sale_items;
DROP TABLE IF EXISTS sales;
DROP TABLE IF EXISTS expenses;
DROP TABLE IF EXISTS customers;
DROP TABLE IF EXISTS suppliers;
DROP TABLE IF EXISTS products;
DROP TABLE IF EXISTS categories;
DROP TABLE IF EXISTS user_branches;
DROP TABLE IF EXISTS role_permissions;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS branches;
DROP TABLE IF EXISTS settings;

CREATE TABLE branches (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  code VARCHAR(50) NOT NULL UNIQUE,
  address VARCHAR(255),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  branch_id INT NULL,
  name VARCHAR(120) NOT NULL,
  username VARCHAR(80) NOT NULL UNIQUE,
  password VARCHAR(255) NOT NULL,
  role ENUM('Admin','Area Manager','Manager','Cashier') NOT NULL DEFAULT 'Cashier',
  is_active TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL
);

CREATE TABLE role_permissions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  role_name ENUM('Admin','Area Manager','Manager','Cashier') NOT NULL,
  permission_key VARCHAR(100) NOT NULL,
  is_allowed TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_role_permission (role_name, permission_key)
);

CREATE TABLE user_branches (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  branch_id INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY unique_user_branch (user_id, branch_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE
);

CREATE TABLE categories (
  id INT AUTO_INCREMENT PRIMARY KEY,
  branch_id INT NOT NULL,
  name VARCHAR(120) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE
);

CREATE TABLE products (
  id INT AUTO_INCREMENT PRIMARY KEY,
  branch_id INT NOT NULL,
  category_id INT NULL,
  name VARCHAR(160) NOT NULL,
  barcode VARCHAR(100) NULL,
  sku VARCHAR(100) NULL,
  cost DECIMAL(12,2) DEFAULT 0,
  price DECIMAL(12,2) NOT NULL DEFAULT 0,
  stock_qty INT NOT NULL DEFAULT 0,
  low_stock_threshold INT NOT NULL DEFAULT 5,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY unique_branch_barcode (branch_id, barcode),
  FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE,
  FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL
);

CREATE TABLE customers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  branch_id INT NOT NULL,
  name VARCHAR(120) NOT NULL,
  phone VARCHAR(50),
  email VARCHAR(120),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE suppliers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  branch_id INT NOT NULL,
  name VARCHAR(120) NOT NULL,
  phone VARCHAR(50),
  email VARCHAR(120),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE purchase_orders (
  id INT AUTO_INCREMENT PRIMARY KEY,
  branch_id INT NOT NULL,
  supplier_id INT NOT NULL,
  po_number VARCHAR(80) NOT NULL,
  po_date DATE NOT NULL,
  status ENUM('pending','partial','received') NOT NULL DEFAULT 'pending',
  notes VARCHAR(255) NULL,
  created_by INT NULL,
  received_by INT NULL,
  received_at TIMESTAMP NULL DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY unique_branch_po_number (branch_id, po_number),
  FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE,
  FOREIGN KEY (supplier_id) REFERENCES suppliers(id),
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (received_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE purchase_order_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  purchase_order_id INT NOT NULL,
  product_id INT NOT NULL,
  qty_ordered INT NOT NULL,
  qty_received INT NOT NULL DEFAULT 0,
  cost DECIMAL(12,2) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(id) ON DELETE CASCADE,
  FOREIGN KEY (product_id) REFERENCES products(id)
);

CREATE TABLE expenses (
  id INT AUTO_INCREMENT PRIMARY KEY,
  branch_id INT NOT NULL,
  title VARCHAR(160) NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  expense_date DATE NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE sales (
  id INT AUTO_INCREMENT PRIMARY KEY,
  invoice_no VARCHAR(80) NOT NULL UNIQUE,
  branch_id INT NOT NULL,
  user_id INT NOT NULL,
  customer_id INT NULL,
  total_amount DECIMAL(12,2) NOT NULL,
  amount_tendered DECIMAL(12,2) NOT NULL,
  change_amount DECIMAL(12,2) NOT NULL,
  payment_method VARCHAR(40) NOT NULL,
  status ENUM('completed','voided') DEFAULT 'completed',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (branch_id) REFERENCES branches(id),
  FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE sale_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  sale_id INT NOT NULL,
  product_id INT NOT NULL,
  qty INT NOT NULL,
  price DECIMAL(12,2) NOT NULL,
  subtotal DECIMAL(12,2) NOT NULL,
  FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE,
  FOREIGN KEY (product_id) REFERENCES products(id)
);

CREATE TABLE sales_returns (
  id INT AUTO_INCREMENT PRIMARY KEY,
  branch_id INT NOT NULL,
  sale_id INT NOT NULL,
  user_id INT NULL,
  refund_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  reason VARCHAR(255) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (branch_id) REFERENCES branches(id),
  FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE sales_return_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  return_id INT NOT NULL,
  sale_item_id INT NOT NULL,
  product_id INT NOT NULL,
  qty INT NOT NULL,
  price DECIMAL(12,2) NOT NULL,
  subtotal DECIMAL(12,2) NOT NULL,
  FOREIGN KEY (return_id) REFERENCES sales_returns(id) ON DELETE CASCADE,
  FOREIGN KEY (sale_item_id) REFERENCES sale_items(id),
  FOREIGN KEY (product_id) REFERENCES products(id)
);

CREATE TABLE inventory_movements (
  id INT AUTO_INCREMENT PRIMARY KEY,
  branch_id INT NOT NULL,
  product_id INT NOT NULL,
  type VARCHAR(40) NOT NULL,
  qty INT NOT NULL,
  remarks VARCHAR(255),
  user_id INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (branch_id) REFERENCES branches(id),
  FOREIGN KEY (product_id) REFERENCES products(id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE settings (
  id INT AUTO_INCREMENT PRIMARY KEY,
  setting_key VARCHAR(100) NOT NULL UNIQUE,
  setting_value TEXT
);

CREATE TABLE audit_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  branch_id INT NULL,
  user_id INT NULL,
  action VARCHAR(120) NOT NULL,
  module VARCHAR(80) NOT NULL,
  details TEXT NULL,
  ip_address VARCHAR(80) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE cash_sessions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  branch_id INT NOT NULL,
  user_id INT NOT NULL,
  opening_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  closing_amount DECIMAL(12,2) NULL,
  expected_amount DECIMAL(12,2) NULL,
  variance_amount DECIMAL(12,2) NULL,
  status ENUM('open','closed') NOT NULL DEFAULT 'open',
  opened_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  closed_at TIMESTAMP NULL DEFAULT NULL,
  notes TEXT NULL,
  FOREIGN KEY (branch_id) REFERENCES branches(id),
  FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE cash_drawer_transactions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  cash_session_id INT NOT NULL,
  branch_id INT NOT NULL,
  user_id INT NOT NULL,
  type ENUM('cash_in','cash_out','sale_cash','refund','adjustment') NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  reference VARCHAR(120) NULL,
  remarks VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (cash_session_id) REFERENCES cash_sessions(id) ON DELETE CASCADE,
  FOREIGN KEY (branch_id) REFERENCES branches(id),
  FOREIGN KEY (user_id) REFERENCES users(id)
);

INSERT INTO branches(name, code, address) VALUES ('Main Branch', 'MAIN', 'Main Store');
INSERT INTO users(branch_id, name, username, password, role) VALUES (1, 'Administrator', 'admin', '$2y$10$dOE5mfF..zkxR9ZzZi30POvTeZlVmMddS6V.tARb7xIYiVy6QXXta', 'Admin');
INSERT INTO categories(branch_id, name) VALUES (1, 'Beverages'), (1, 'Groceries'), (1, 'Snacks');
INSERT INTO products(branch_id, category_id, name, barcode, sku, cost, price, stock_qty, low_stock_threshold) VALUES
(1, 1, 'Bottled Water', '480000000001', 'BW-001', 8, 15, 50, 10),
(1, 1, 'Iced Coffee', '480000000002', 'IC-001', 20, 35, 25, 5),
(1, 2, 'Rice 1kg', '480000000003', 'RC-001', 45, 58, 40, 8),
(1, 3, 'Potato Chips', '480000000004', 'PC-001', 18, 30, 30, 6);
INSERT INTO settings(setting_key, setting_value) VALUES
('store_name','POS STORE'),
('store_address','Main Branch'),
('store_phone',''),
('currency_symbol','₱'),
('receipt_footer','Thank you for shopping!'),
('tax_rate','0'),
('low_stock_threshold','5'),
('thermal_printer_width_mm','58');


INSERT INTO role_permissions(role_name, permission_key, is_allowed) VALUES
('Admin', '*', 1),
('Area Manager', 'dashboard.view', 1),
('Area Manager', 'pos.access', 1),
('Area Manager', 'sales.view', 1),
('Area Manager', 'products.view', 1),
('Area Manager', 'products.manage', 1),
('Area Manager', 'categories.manage', 1),
('Area Manager', 'inventory.view', 1),
('Area Manager', 'inventory.manage', 1),
('Area Manager', 'customers.view', 1),
('Area Manager', 'customers.manage', 1),
('Area Manager', 'suppliers.manage', 1),
('Area Manager', 'purchases.view', 1),
('Area Manager', 'purchases.manage', 1),
('Area Manager', 'expenses.manage', 1),
('Area Manager', 'reports.view', 1),
('Area Manager', 'settings.manage', 1),
('Area Manager', 'audit.view', 1),
('Area Manager', 'backup.manage', 1),
('Area Manager', 'cash_drawer.manage', 1),
('Manager', 'dashboard.view', 1),
('Manager', 'pos.access', 1),
('Manager', 'sales.view', 1),
('Manager', 'products.view', 1),
('Manager', 'products.manage', 1),
('Manager', 'categories.manage', 1),
('Manager', 'inventory.view', 1),
('Manager', 'inventory.manage', 1),
('Manager', 'customers.view', 1),
('Manager', 'customers.manage', 1),
('Manager', 'suppliers.manage', 1),
('Manager', 'purchases.view', 1),
('Manager', 'purchases.manage', 1),
('Manager', 'expenses.manage', 1),
('Manager', 'reports.view', 1),
('Manager', 'settings.manage', 1),
('Manager', 'audit.view', 1),
('Manager', 'backup.manage', 1),
('Manager', 'cash_drawer.manage', 1),
('Cashier', 'dashboard.view', 1),
('Cashier', 'pos.access', 1),
('Cashier', 'sales.view', 1),
('Cashier', 'products.view', 1),
('Cashier', 'inventory.view', 1),
('Cashier', 'customers.view', 1);

INSERT INTO audit_logs(branch_id, user_id, action, module, details, ip_address)
VALUES (1, 1, 'seed', 'system', 'Initial Phase 15 database seed with audit logs, cash drawer support, and role permissions.', '127.0.0.1');

-- Phase 15 includes Audit Logs plus Cash Drawer & Shift Management.
-- Import this file for a fresh install, or manually add the new tables to an existing database.
