-- Sales discounts and Senior/PWD discount settings.
-- Safe to run on an existing database.

ALTER TABLE sales
  ADD COLUMN IF NOT EXISTS discount_type VARCHAR(20) NULL AFTER customer_id,
  ADD COLUMN IF NOT EXISTS discount_value DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER discount_type,
  ADD COLUMN IF NOT EXISTS discount_amount DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER discount_value;

ALTER TABLE daily_closings
  ADD COLUMN IF NOT EXISTS total_discounts DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER total_sales;

INSERT INTO settings(setting_key, setting_value) VALUES
('enable_senior_discount', '1'),
('enable_pwd_discount', '1')
ON DUPLICATE KEY UPDATE setting_value = setting_value;
