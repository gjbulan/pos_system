CREATE TABLE IF NOT EXISTS daily_closings (
  id INT AUTO_INCREMENT PRIMARY KEY,
  branch_id INT NOT NULL,
  user_id INT NOT NULL,
  cash_session_id INT NOT NULL,
  closing_date DATE NOT NULL,
  opened_at TIMESTAMP NULL DEFAULT NULL,
  closed_at TIMESTAMP NULL DEFAULT NULL,
  opening_cash DECIMAL(12,2) NOT NULL DEFAULT 0,
  total_sales DECIMAL(12,2) NOT NULL DEFAULT 0,
  cash_sales DECIMAL(12,2) NOT NULL DEFAULT 0,
  non_cash_sales DECIMAL(12,2) NOT NULL DEFAULT 0,
  returns_refunds DECIMAL(12,2) NOT NULL DEFAULT 0,
  expenses DECIMAL(12,2) NOT NULL DEFAULT 0,
  cash_in DECIMAL(12,2) NOT NULL DEFAULT 0,
  cash_out DECIMAL(12,2) NOT NULL DEFAULT 0,
  expected_cash DECIMAL(12,2) NOT NULL DEFAULT 0,
  actual_cash DECIMAL(12,2) NOT NULL DEFAULT 0,
  variance DECIMAL(12,2) NOT NULL DEFAULT 0,
  sale_count INT NOT NULL DEFAULT 0,
  return_count INT NOT NULL DEFAULT 0,
  expense_count INT NOT NULL DEFAULT 0,
  notes TEXT NULL,
  closed_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY unique_daily_closing_session (cash_session_id),
  UNIQUE KEY unique_daily_closing_branch_date_session (branch_id, closing_date, cash_session_id),
  FOREIGN KEY (branch_id) REFERENCES branches(id),
  FOREIGN KEY (user_id) REFERENCES users(id),
  FOREIGN KEY (cash_session_id) REFERENCES cash_sessions(id),
  FOREIGN KEY (closed_by) REFERENCES users(id) ON DELETE SET NULL
);

INSERT INTO role_permissions(role_name, permission_key, is_allowed) VALUES
('Area Manager', 'closing.view', 1),
('Area Manager', 'closing.manage', 1),
('Manager', 'closing.view', 1),
('Manager', 'closing.manage', 1)
ON DUPLICATE KEY UPDATE is_allowed = VALUES(is_allowed);
