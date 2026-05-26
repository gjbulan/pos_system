# Phase 20 - Final Release

This is the final release package for the PHP + MySQL + Bootstrap POS system.

## Included Modules

- Authentication and session guard
- Multi-branch dashboard layout
- Dashboard cards and charts
- Products and categories
- Inventory and stock-in
- POS checkout
- Sales history and receipt printing
- Reports and analytics
- Customers with branch-aware search, add, edit, and delete pages
- Suppliers
- Expenses
- Users, roles, branches, and permissions
- Settings and store profile
- Barcode support
- Thermal receipt layout
- Backup and restore
- Audit logs
- Cash drawer and shift management
- Installer and first-admin setup
- System health check
- Mobile/responsive UI polish
- Final QA package

## Recommended Installation

1. Extract this ZIP into your web server folder.
   - XAMPP: `C:\xampp\htdocs\pos-system`
   - Linux/Apache: `/var/www/html/pos-system`

2. Create a MySQL database named `pos_system`.

3. Import:

```text
/database/final_schema.sql
```

4. Update database credentials in:

```text
/config/database.php
```

5. Open the system in your browser:

```text
http://localhost/pos-system
```

## Default Admin

```text
Username: admin
Password: admin123
```

Change this password immediately after first login.

## Release Notes

This package is intended as a complete starter POS system. Before live production use, test all checkout, stock, receipt, permission, backup, restore, and reporting flows using the QA documents in `/qa`.

## Production Checklist

Before deployment:

- Change default admin password
- Use HTTPS
- Disable display errors in production
- Back up the database
- Restrict `/backup`, `/install`, and `/database` access
- Test receipt printer paper width
- Confirm tax/currency/store settings
- Verify branch filtering
- Verify role permissions
