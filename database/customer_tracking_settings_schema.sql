-- Customer tracking settings and sales customer reference.
-- Safe to run on an existing database.

ALTER TABLE sales
  ADD COLUMN IF NOT EXISTS customer_id INT NULL AFTER user_id;

INSERT INTO settings(setting_key, setting_value) VALUES
('enable_customer_tracking', '1'),
('require_customer_on_sale', '0')
ON DUPLICATE KEY UPDATE setting_value = setting_value;
