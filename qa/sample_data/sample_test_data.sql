-- Phase 19 optional sample data for local testing only.
-- Import after the main schema.

INSERT INTO branches (id, branch_name, branch_code, address, status)
VALUES
(1, 'Main Branch', 'MAIN', 'Main Store Address', 'Active')
ON DUPLICATE KEY UPDATE branch_name = VALUES(branch_name);

INSERT INTO categories (id, branch_id, category_name, status)
VALUES
(1, 1, 'Beverages', 'Active'),
(2, 1, 'Snacks', 'Active'),
(3, 1, 'Household', 'Active')
ON DUPLICATE KEY UPDATE category_name = VALUES(category_name);

INSERT INTO suppliers (id, branch_id, supplier_name, contact_person, phone, status)
VALUES
(1, 1, 'Default Supplier', 'Supplier Contact', '09000000000', 'Active')
ON DUPLICATE KEY UPDATE supplier_name = VALUES(supplier_name);

INSERT INTO customers (id, branch_id, customer_name, phone, status)
VALUES
(1, 1, 'Walk-in Customer', '', 'Active')
ON DUPLICATE KEY UPDATE customer_name = VALUES(customer_name);

INSERT INTO products (id, branch_id, category_id, product_name, barcode, selling_price, cost_price, stock_qty, low_stock_qty, status)
VALUES
(1, 1, 1, 'Bottled Water', '100000001', 20.00, 12.00, 100, 10, 'Active'),
(2, 1, 1, 'Soft Drink', '100000002', 25.00, 15.00, 80, 10, 'Active'),
(3, 1, 2, 'Chips', '100000003', 18.00, 10.00, 50, 10, 'Active'),
(4, 1, 3, 'Laundry Soap', '100000004', 35.00, 22.00, 30, 5, 'Active')
ON DUPLICATE KEY UPDATE product_name = VALUES(product_name);
