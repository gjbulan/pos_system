# Final Installation Guide

## Local Installation with XAMPP

1. Install XAMPP.
2. Start Apache and MySQL.
3. Extract the project to:

```text
C:\xampp\htdocs\pos-system
```

4. Open phpMyAdmin:

```text
http://localhost/phpmyadmin
```

5. Create database:

```sql
CREATE DATABASE pos_system;
```

6. Import:

```text
/database/final_schema.sql
```

7. Open:

```text
http://localhost/pos-system
```

## Login

```text
Username: admin
Password: admin123
```

## Troubleshooting

### Blank page
Enable PHP errors locally or check Apache error logs.

### Database error
Check `/config/database.php` credentials.

### CSS not loading
Make sure the extracted folder name matches the browser URL.

### Receipt print size wrong
Adjust thermal printer size in Settings.
