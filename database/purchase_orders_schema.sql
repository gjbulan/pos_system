CREATE TABLE IF NOT EXISTS purchase_orders (
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

CREATE TABLE IF NOT EXISTS purchase_order_items (
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

INSERT INTO role_permissions(role_name, permission_key, is_allowed) VALUES
('Manager', 'purchases.view', 1),
('Manager', 'purchases.manage', 1)
ON DUPLICATE KEY UPDATE is_allowed = VALUES(is_allowed);
