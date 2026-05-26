<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/app.php';

function require_login(): void
{
    if (empty($_SESSION['user_id'])) {
        redirect_to('auth/login.php');
    }
}

function current_branch_id(): int
{
    return (int)($_SESSION['branch_id'] ?? 1);
}

function has_role(array $roles): bool
{
    return in_array($_SESSION['role'] ?? '', $roles, true);
}

function role_permissions(PDO $pdo, string $role): array
{
    static $cache = [];
    if (isset($cache[$role])) {
        return $cache[$role];
    }

    try {
        $stmt = $pdo->prepare('SELECT permission_key FROM role_permissions WHERE role_name = ? AND is_allowed = 1');
        $stmt->execute([$role]);
        $cache[$role] = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable $e) {
        $cache[$role] = default_permissions($role);
    }

    return $cache[$role];
}

function default_permissions(string $role): array
{
    $permissions = [
        'dashboard.view',
        'pos.access',
        'sales.view',
        'products.view',
        'inventory.view',
        'customers.view'
    ];

    if ($role === 'Manager') {
        return array_merge($permissions, [
            'products.manage',
            'categories.manage',
            'inventory.manage',
            'suppliers.manage',
            'expenses.manage',
            'reports.view',
            'settings.manage',
            'audit.view',
            'cash_drawer.manage',
            'backup.manage'
        ]);
    }

    if ($role === 'Admin') {
        return ['*'];
    }

    return $permissions;
}

function can(PDO $pdo, string $permission): bool
{
    $role = $_SESSION['role'] ?? 'Cashier';
    $permissions = role_permissions($pdo, $role);
    return in_array('*', $permissions, true) || in_array($permission, $permissions, true);
}

function require_permission(PDO $pdo, string $permission): void
{
    if (!can($pdo, $permission)) {
        http_response_code(403);
        include __DIR__ . '/../includes/header.php';
        echo '<div class="app"><div class="main-content p-4"><div class="alert alert-danger shadow-sm"><h4>Access Denied</h4><p class="mb-0">Your account does not have permission to access this page.</p></div></div></div>';
        include __DIR__ . '/../includes/footer.php';
        exit;
    }
}

function log_activity(PDO $pdo, string $action, string $module, string $details = ''): void
{
    try {
        $stmt = $pdo->prepare("INSERT INTO audit_logs(branch_id, user_id, action, module, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $_SESSION['branch_id'] ?? null,
            $_SESSION['user_id'] ?? null,
            $action,
            $module,
            $details,
            $_SERVER['REMOTE_ADDR'] ?? null
        ]);
    } catch (Throwable $e) {
        // Never block the POS workflow if logging fails.
    }
}
