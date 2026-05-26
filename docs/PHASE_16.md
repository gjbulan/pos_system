# Phase 16 - Deployment Ready Package

Phase 16 prepares the POS system for cleaner local setup and safer deployment.

## Added

- `config/app.php`
- `app_url()` helper
- `redirect_to()` helper
- Environment-ready database config
- `.env.example`
- `.htaccess` hardening
- Production checklist
- Updated setup documentation
- `database/phase_16_schema.sql`

## Important

The project still uses plain PHP, MySQL, Bootstrap 5, Bootstrap Icons, Chart.js, and PDO.

## Changing Project Folder Name

If you rename the folder from:

```text
pos_phase_16
```

to:

```text
pos
```

update this line in `config/app.php`:

```php
define('APP_BASE_PATH', '/pos');
```

## Local URL

```text
http://localhost/pos_phase_16
```

## Default Login

```text
Username: admin
Password: admin123
```

Change the default password before real use.
