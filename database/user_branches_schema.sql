ALTER TABLE users
  MODIFY role ENUM('Admin','Area Manager','Manager','Cashier') NOT NULL DEFAULT 'Cashier';

ALTER TABLE role_permissions
  MODIFY role_name ENUM('Admin','Area Manager','Manager','Cashier') NOT NULL;

CREATE TABLE IF NOT EXISTS user_branches (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  branch_id INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY unique_user_branch (user_id, branch_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE
);

INSERT INTO role_permissions(role_name, permission_key, is_allowed)
SELECT 'Area Manager', permission_key, is_allowed
FROM role_permissions
WHERE role_name = 'Manager'
ON DUPLICATE KEY UPDATE is_allowed = VALUES(is_allowed);
