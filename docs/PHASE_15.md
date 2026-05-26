# Phase 15 - User Permissions

This phase adds role-based permission controls to the POS system.

## Added

- `role_permissions` database table
- Permission management screen
- Permission helper functions
- Access denied screen
- Sidebar link for Permissions
- Audit logging when permissions are updated

## Permission Page

Open:

```text
http://localhost/pos_phase_16/permissions/index.php
```

Only Admin users can manage permissions by default.

## Default Behavior

- **Admin** always has full access.
- **Manager** has operational access such as inventory, products, expenses, reports, backup, and cash drawer.
- **Cashier** has limited access for POS, sales view, products view, inventory view, and customers view.

## Helper Functions

Use these functions from `auth/session.php`:

```php
can($pdo, 'products.manage');
require_permission($pdo, 'products.manage');
```

Example page guard:

```php
require_login();
require_permission($pdo, 'reports.view');
```

## Fresh Install

Import:

```text
database/phase_15_schema.sql
```

Default login:

```text
Username: admin
Password: admin123
```

## Next Phase

Phase 16 will focus on deployment hardening and final production cleanup.
