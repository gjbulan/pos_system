# POS Checkout Test Cases

## TC-POS-001: Add Item to Cart

**Steps**
1. Login as cashier.
2. Open POS screen.
3. Search for an active product.
4. Add product to cart.

**Expected Result**
- Product appears in cart.
- Quantity defaults to 1.
- Subtotal is correct.

## TC-POS-002: Barcode Scan

**Steps**
1. Focus barcode input.
2. Enter a valid barcode.
3. Press Enter.

**Expected Result**
- Matching product is added to cart.

## TC-POS-003: Prevent Overselling

**Steps**
1. Add a product with limited stock.
2. Enter quantity greater than available stock.
3. Try checkout.

**Expected Result**
- Checkout is blocked.
- User sees a clear stock warning.

## TC-POS-004: Complete Cash Sale

**Steps**
1. Add product to cart.
2. Select cash payment.
3. Enter cash received.
4. Complete checkout.

**Expected Result**
- Sale is saved.
- Stock is deducted.
- Change is calculated.
- Receipt can be printed.

## TC-POS-005: Void Sale

**Steps**
1. Open Sales History.
2. Choose a completed sale.
3. Void the sale.

**Expected Result**
- Sale status becomes voided.
- Stock is restored.
- Audit log is created.
