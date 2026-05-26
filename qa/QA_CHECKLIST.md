# Phase 17 QA Checklist

Use this checklist before moving to UI polish and final release.

## Installation

- [ ] Project folder is inside `htdocs`.
- [ ] Apache and MySQL are running.
- [ ] `/install/index.php` loads correctly.
- [ ] Database installer completes without SQL errors.
- [ ] First admin account can be created.
- [ ] Login works with the created admin account.

## Health Check

- [ ] `/system/health.php` loads after login.
- [ ] Database connection check passes.
- [ ] Required table checks pass.
- [ ] `database/phase_17_schema.sql` exists.
- [ ] `config/database.php` exists.

## Security

- [ ] Admin password is not the default password.
- [ ] `/install` folder is removed or protected after setup.
- [ ] `.env.example` is copied/translated to real server config if needed.
- [ ] `APP_DEBUG` is disabled in production.

## POS Flow

- [ ] Add category.
- [ ] Add product with barcode.
- [ ] Add stock-in inventory.
- [ ] Perform POS checkout.
- [ ] Print receipt.
- [ ] Confirm stock deduction.
- [ ] Review sales history.
- [ ] Run reports.

## Admin Flow

- [ ] Add branch.
- [ ] Add user.
- [ ] Assign role.
- [ ] Review permissions.
- [ ] Confirm audit logs.
- [ ] Open and close cash drawer shift.
- [ ] Create database backup.
