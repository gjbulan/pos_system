USE pos_system;

CREATE TABLE IF NOT EXISTS quotations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  branch_id INT NOT NULL,
  customer_id INT NULL,
  user_id INT NULL,
  quote_no VARCHAR(80) NOT NULL,
  status ENUM('draft','issued','converted','cancelled') NOT NULL DEFAULT 'draft',
  valid_until DATE NULL,
  subtotal_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  discount_type VARCHAR(20) NULL,
  discount_value DECIMAL(12,2) NOT NULL DEFAULT 0,
  discount_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  total_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  notes TEXT NULL,
  converted_sale_id INT NULL,
  converted_at TIMESTAMP NULL DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY unique_branch_quote_no (branch_id, quote_no),
  KEY idx_quotations_branch_status (branch_id, status),
  KEY idx_quotations_customer (customer_id),
  FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE,
  FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (converted_sale_id) REFERENCES sales(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS quotation_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  quotation_id INT NOT NULL,
  product_id INT NOT NULL,
  qty INT NOT NULL,
  price DECIMAL(12,2) NOT NULL,
  subtotal DECIMAL(12,2) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (quotation_id) REFERENCES quotations(id) ON DELETE CASCADE,
  FOREIGN KEY (product_id) REFERENCES products(id)
);

INSERT IGNORE INTO role_permissions(role_name, permission_key, is_allowed) VALUES
('Area Manager', 'quotations.view', 1),
('Area Manager', 'quotations.manage', 1),
('Manager', 'quotations.view', 1),
('Manager', 'quotations.manage', 1),
('Cashier', 'quotations.view', 1);
