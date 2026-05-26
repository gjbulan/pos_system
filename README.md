# POS System - Phase 18

PHP + MySQL + Bootstrap POS project snapshot.

## Latest Modules Included

- Dashboard
- Products and Categories
- Inventory / Stock In
- POS Checkout
- Sales History and Receipts
- Reports and Analytics
- Customers, Suppliers, Expenses
- Users, Roles, Branches
- Settings and Store Profile
- Barcode Support
- Thermal Receipt Printing
- Backup and Restore
- Audit Logs
- Cash Drawer and Shift Management
- User Permissions
- Deployment-ready configuration
- Web installer and system health checks
- UI polish and mobile responsiveness

## Requirements

- XAMPP or compatible PHP/MySQL stack
- PHP 8.1+
- MySQL/MariaDB

## Setup

1. Extract this folder to:

```text
C:\xampp\htdocs\pos_phase_18
```

2. Start Apache and MySQL in XAMPP.

3. Create database in phpMyAdmin:

```sql
CREATE DATABASE pos_system;
```

4. Import:

```text
database/phase_18_schema.sql
```

5. Open:

```text
http://localhost/pos_phase_18
```

## Default Login

```text
Username: admin
Password: admin123
```

Change this password before real use.

## Database Config

Edit:

```text
config/database.php
```

Default local values:

```php
$host = 'localhost';
$dbname = 'pos_system';
$username = 'root';
$password = '';
```

## Rename Project Folder

If you rename the folder, update:

```text
config/app.php
```

Example:

```php
define('APP_BASE_PATH', '/pos');
```

## Production Notes

Read:

```text
deployment/PRODUCTION_CHECKLIST.md
```

## Notes

This project is intentionally built with plain PHP, PDO, MySQL, Bootstrap 5, Bootstrap Icons, and Chart.js so it is easy to run on shared hosting or XAMPP.


## Phase 17 Installer and Phase 18 UI Polish

Open the installer after placing the folder in XAMPP `htdocs`:

```text
http://localhost/pos_phase_17/install/index.php
```

After installation, login and run:

```text
http://localhost/pos_phase_17/system/health.php
```

Security reminder: remove or protect `/install` before production use.


## Phase 18 UI Notes

Phase 18 improves the daily usability of the POS on tablets and phones.

Included changes:

- Responsive off-canvas sidebar for smaller screens
- Mobile bottom navigation for quick access to Dashboard, POS, Products, and Sales
- Table wrappers for horizontal scrolling on narrow screens
- Polished cards, rounded controls, and better spacing
- Active navigation highlighting
- Safer asset paths using `APP_BASE_PATH`

Recommended testing sizes:

```text
Desktop: 1366px and above
Tablet: 768px to 1024px
Phone: 360px to 430px
```

## Phase 19 - Final Testing Package

The latest testing documents are in:

```text
qa/
```

Recommended testing flow:

1. Import the latest schema.
2. Optionally import `qa/sample_data/sample_test_data.sql`.
3. Run through `qa/FINAL_TEST_PLAN.md`.
4. Log issues using `qa/BUG_REPORT_TEMPLATE.md`.
5. Complete `qa/release/RELEASE_READINESS_CHECKLIST.md` before final release.
