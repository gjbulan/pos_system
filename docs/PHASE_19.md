# Phase 19 - Final Testing Package

Phase 19 adds the testing and release-readiness layer for the POS system.

## Added

- Manual QA test plan
- POS checkout test cases
- Inventory test cases
- Reports test cases
- User permission test cases
- Sample SQL test data
- Bug report template
- Release readiness checklist

## How to Use This Phase

1. Import the latest database schema from `database/phase_18_schema.sql`.
2. Optionally import `qa/sample_data/sample_test_data.sql`.
3. Run the app locally in XAMPP.
4. Complete the checklists inside the `qa/` folder.
5. Fix any bugs before proceeding to the final release phase.

## Recommended Test Order

1. Login and session checks
2. Branch access checks
3. Products and categories
4. Inventory stock-in and adjustment
5. POS checkout
6. Receipt printing
7. Sales history
8. Reports
9. Backup and restore
10. Permissions and access control

## Default Test Users

Use your seeded admin account or create the following manually:

- Admin
- Manager
- Cashier

Each role should be tested separately.

## Next Phase

Phase 20 will be the final release ZIP.
