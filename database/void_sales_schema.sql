USE pos_system;

ALTER TABLE sales
  MODIFY status ENUM('completed','void_requested','voided') NOT NULL DEFAULT 'completed';

ALTER TABLE sales
  ADD COLUMN IF NOT EXISTS void_reason VARCHAR(255) NULL AFTER status,
  ADD COLUMN IF NOT EXISTS voided_by INT NULL AFTER void_reason,
  ADD COLUMN IF NOT EXISTS voided_at TIMESTAMP NULL DEFAULT NULL AFTER voided_by;

ALTER TABLE daily_closings
  ADD COLUMN IF NOT EXISTS void_count INT NOT NULL DEFAULT 0 AFTER return_count,
  ADD COLUMN IF NOT EXISTS void_total DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER void_count;

INSERT IGNORE INTO role_permissions(role_name, permission_key, is_allowed) VALUES
('Area Manager', 'sales.void.request', 1),
('Area Manager', 'sales.void.approve', 1),
('Manager', 'sales.void.request', 1),
('Manager', 'sales.void.approve', 1),
('Cashier', 'sales.void.request', 1);
