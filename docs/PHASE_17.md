# Phase 17 - Installer, Health Check, and QA

Phase 17 adds project setup tools so the POS is easier to run and validate.

## Added

- Web installer at `install/index.php`
- First admin account setup
- Database schema import using `database/phase_17_schema.sql`
- System health check page at `system/health.php`
- Required table verification
- Key file/folder verification
- QA checklist at `qa/QA_CHECKLIST.md`

## How to run

1. Extract the ZIP into your XAMPP `htdocs` folder.
2. Start Apache and MySQL.
3. Open:

```text
http://localhost/pos_phase_17/install/index.php
```

4. Install the database.
5. Create the first admin account.
6. Login and open:

```text
http://localhost/pos_phase_17/system/health.php
```

## Important production note

After installation, remove or protect the `/install` folder. Leaving an installer open on a live server is unsafe.

## Next phase

Phase 18 should focus on UI polish, mobile responsiveness, and screen refinements for cashier use.
