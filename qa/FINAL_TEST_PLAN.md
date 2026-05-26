# Final POS Test Plan

Use this checklist before calling the system production-ready.

## Environment

- [ ] Apache starts successfully
- [ ] MySQL starts successfully
- [ ] App loads from localhost
- [ ] Database connection works
- [ ] Installer or SQL import works
- [ ] No PHP fatal errors appear

## Authentication

- [ ] Login accepts valid credentials
- [ ] Login rejects invalid credentials
- [ ] Logout destroys the session
- [ ] Protected pages redirect when not logged in
- [ ] User role is loaded correctly
- [ ] Branch is loaded correctly

## Dashboard

- [ ] KPI cards display values
- [ ] Charts render correctly
- [ ] Low stock panel loads
- [ ] Recent transactions display
- [ ] Mobile layout does not break

## Products and Categories

- [ ] Add category
- [ ] Edit category
- [ ] Delete category only when safe
- [ ] Add product
- [ ] Edit product
- [ ] Delete product only when safe
- [ ] Barcode field saves correctly
- [ ] Low stock threshold works

## Inventory

- [ ] Stock-in increases product stock
- [ ] Inventory movement is recorded
- [ ] Supplier can be linked
- [ ] Branch filtering works
- [ ] Stock cannot go negative without approval

## POS Checkout

- [ ] Product search works
- [ ] Barcode search works
- [ ] Product can be added to cart
- [ ] Quantity can be changed
- [ ] Total updates correctly
- [ ] Payment validation works
- [ ] Sale saves correctly
- [ ] Sale items save correctly
- [ ] Stock is deducted correctly
- [ ] Cash drawer entry is created for cash payments

## Receipts

- [ ] Receipt page opens
- [ ] 58mm layout prints correctly
- [ ] 80mm layout prints correctly
- [ ] Store name/address/footer displays
- [ ] Receipt total matches sale total

## Sales History

- [ ] Sales list loads
- [ ] Date filters work
- [ ] Receipt can be reopened
- [ ] Void sale works only with permission
- [ ] Voiding restores stock
- [ ] Voiding creates audit log

## Reports

- [ ] Daily sales report works
- [ ] Date range report works
- [ ] Payment breakdown works
- [ ] Top products report works
- [ ] Cashier performance report works
- [ ] Print report works

## Customers, Suppliers, Expenses

- [ ] Customer CRUD works
- [ ] Supplier CRUD works
- [ ] Expense CRUD works
- [ ] Expense report totals are correct

## Users, Roles, Permissions

- [ ] Admin can manage users
- [ ] Cashier cannot access restricted modules
- [ ] Role permission matrix saves correctly
- [ ] Access denied page appears when required

## Backup and Restore

- [ ] Backup downloads SQL file
- [ ] Restore accepts SQL file
- [ ] Restore rejects invalid upload
- [ ] Backup/restore is restricted to allowed roles

## Audit Logs

- [ ] Login is logged
- [ ] Logout is logged
- [ ] Sale is logged
- [ ] Void is logged
- [ ] Inventory action is logged
- [ ] Filters work

## Final Acceptance

- [ ] No broken sidebar links
- [ ] No missing includes
- [ ] No SQL errors
- [ ] No visible debug output
- [ ] Mobile UI is usable
- [ ] Project README is accurate
