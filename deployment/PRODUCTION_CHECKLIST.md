# Production Checklist

Use this checklist before uploading the POS system to live hosting.

## Server

- PHP 8.1 or newer
- MySQL 8 or MariaDB 10.4+
- Apache mod_rewrite enabled
- HTTPS enabled
- File indexing disabled

## Security

- Change the default admin password immediately.
- Set `APP_DEBUG=false` in production.
- Use a strong database password.
- Do not expose database backups publicly.
- Restrict Backup & Restore to trusted admin users only.
- Remove unused test accounts.

## Database

- Create a fresh production database.
- Import `database/phase_16_schema.sql`.
- Verify login, products, sales, receipts, reports, and backup pages.

## Folder

Recommended local folder:

```text
C:\xampp\htdocs\pos_phase_16
```

Recommended live hosting folder:

```text
public_html/pos
```

If you rename the folder, update:

```php
config/app.php
APP_BASE_PATH
```

Example:

```php
define('APP_BASE_PATH', '/pos');
```
