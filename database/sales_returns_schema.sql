CREATE TABLE IF NOT EXISTS sales_returns (
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

CREATE TABLE IF NOT EXISTS sales_return_items (
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
