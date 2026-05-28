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

function valid_user_roles(): array
{
    return ['Admin', 'Area Manager', 'Manager', 'Cashier'];
}

function has_role(array $roles): bool
{
    return in_array($_SESSION['role'] ?? '', $roles, true);
}

function fetch_all_branches(PDO $pdo): array
{
    $stmt = $pdo->query('SELECT id, name, code FROM branches ORDER BY name, id');
    return $stmt->fetchAll() ?: [];
}

function user_accessible_branches(PDO $pdo, int $userId, string $role, ?int $userBranchId): array
{
    if ($role === 'Admin') {
        return fetch_all_branches($pdo);
    }

    if ($role === 'Area Manager') {
        $stmt = $pdo->prepare('
            SELECT DISTINCT b.id, b.name, b.code
            FROM user_branches ub
            JOIN branches b ON b.id = ub.branch_id
            WHERE ub.user_id = ?
            ORDER BY b.name, b.id
        ');
        $stmt->execute([$userId]);
        return $stmt->fetchAll() ?: [];
    }

    if (in_array($role, ['Manager', 'Cashier'], true) && $userBranchId !== null && $userBranchId > 0) {
        $stmt = $pdo->prepare('SELECT id, name, code FROM branches WHERE id = ? LIMIT 1');
        $stmt->execute([$userBranchId]);
        $branch = $stmt->fetch();
        return $branch ? [$branch] : [];
    }

    return [];
}

function session_accessible_branches(PDO $pdo): array
{
    $userId = (int)($_SESSION['user_id'] ?? 0);
    $role = $_SESSION['role'] ?? '';

    if ($userId <= 0 || !in_array($role, valid_user_roles(), true)) {
        return [];
    }

    $stmt = $pdo->prepare('SELECT branch_id FROM users WHERE id = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    if (!$user) {
        return [];
    }

    if (in_array($role, ['Manager', 'Cashier'], true)) {
        return user_accessible_branches(
            $pdo,
            $userId,
            $role,
            $user['branch_id'] !== null ? (int)$user['branch_id'] : null
        );
    }

    return user_accessible_branches($pdo, $userId, $role, null);
}

function resolve_login_branch_id(PDO $pdo, array $user): int
{
    $userId = (int)$user['id'];
    $role = (string)$user['role'];
    $userBranchId = isset($user['branch_id']) && $user['branch_id'] !== null ? (int)$user['branch_id'] : null;
    $branches = user_accessible_branches($pdo, $userId, $role, $userBranchId);

    if (!$branches) {
        if ($role === 'Admin') {
            throw new RuntimeException('Login denied. No branches are configured.');
        }

        if ($role === 'Area Manager') {
            throw new RuntimeException('Login denied. This Area Manager has no assigned branches.');
        }

        throw new RuntimeException('Login denied. This user has no valid branch assignment.');
    }

    return (int)$branches[0]['id'];
}

function branch_switch_allowed(PDO $pdo): bool
{
    $role = $_SESSION['role'] ?? '';
    if (!in_array($role, ['Admin', 'Area Manager'], true)) {
        return false;
    }

    return count(session_accessible_branches($pdo)) > 1;
}

function can_access_branch(PDO $pdo, int $branchId): bool
{
    foreach (session_accessible_branches($pdo) as $branch) {
        if ((int)$branch['id'] === $branchId) {
            return true;
        }
    }

    return false;
}

function switch_current_branch(PDO $pdo, int $branchId): bool
{
    if (!can_access_branch($pdo, $branchId)) {
        return false;
    }

    $_SESSION['branch_id'] = $branchId;
    return true;
}

function ensure_current_branch_access(PDO $pdo): bool
{
    $branches = session_accessible_branches($pdo);
    if (!$branches) {
        return false;
    }

    $currentBranchId = (int)($_SESSION['branch_id'] ?? 0);
    foreach ($branches as $branch) {
        if ((int)$branch['id'] === $currentBranchId) {
            return true;
        }
    }

    $_SESSION['branch_id'] = (int)$branches[0]['id'];
    return true;
}

function require_valid_branch_access(PDO $pdo): void
{
    if (ensure_current_branch_access($pdo)) {
        return;
    }

    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
    redirect_to('auth/login.php?branch_error=1');
}

function current_branch_label(PDO $pdo): string
{
    $currentBranchId = current_branch_id();
    foreach (session_accessible_branches($pdo) as $branch) {
        if ((int)$branch['id'] === $currentBranchId) {
            return $branch['name'] . ' (' . $branch['code'] . ')';
        }
    }

    return 'Branch ID: ' . $currentBranchId;
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
        'quotations.view',
        'products.view',
        'inventory.view',
        'customers.view'
    ];

    if (in_array($role, ['Area Manager', 'Manager'], true)) {
        return array_merge($permissions, [
            'products.manage',
            'categories.manage',
            'inventory.manage',
            'suppliers.manage',
            'purchases.view',
            'purchases.manage',
            'quotations.manage',
            'expenses.manage',
            'reports.view',
            'settings.manage',
            'audit.view',
            'cash_drawer.manage',
            'closing.view',
            'closing.manage',
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
    require_valid_branch_access($pdo);

    if (!can($pdo, $permission)) {
        http_response_code(403);
        include __DIR__ . '/../includes/header.php';
        echo '<div class="app"><div class="main-content p-4"><div class="alert alert-danger shadow-sm"><h4>Access Denied</h4><p class="mb-0">Your account does not have permission to access this page.</p></div></div></div>';
        include __DIR__ . '/../includes/footer.php';
        exit;
    }
}

function audit_event_details(string $description, array $context = []): string
{
    $parts = [$description];

    if (!empty($context['username'])) {
        $parts[] = 'username=' . $context['username'];
    }

    if (!empty($context['role'])) {
        $parts[] = 'role=' . $context['role'];
    }

    if (array_key_exists('branch_id', $context) && $context['branch_id'] !== null && $context['branch_id'] !== '') {
        $parts[] = 'branch_id=' . (int)$context['branch_id'];
    }

    if (!empty($context['requested_branch_id'])) {
        $parts[] = 'requested_branch_id=' . (int)$context['requested_branch_id'];
    }

    return implode(' | ', $parts);
}

function log_activity(PDO $pdo, string $action, string $module, string $details = '', array $context = []): void
{
    try {
        $branchId = array_key_exists('branch_id', $context) ? $context['branch_id'] : ($_SESSION['branch_id'] ?? null);
        $userId = array_key_exists('user_id', $context) ? $context['user_id'] : ($_SESSION['user_id'] ?? null);

        $stmt = $pdo->prepare("INSERT INTO audit_logs(branch_id, user_id, action, module, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $branchId !== null && $branchId !== '' && (int)$branchId > 0 ? (int)$branchId : null,
            $userId !== null && $userId !== '' && (int)$userId > 0 ? (int)$userId : null,
            $action,
            $module,
            $details,
            $_SERVER['REMOTE_ADDR'] ?? null
        ]);
    } catch (Throwable $e) {
        // Never block the POS workflow if logging fails.
    }
}

function log_auth_event(PDO $pdo, string $action, string $description, array $context = []): void
{
    log_activity($pdo, $action, 'auth', audit_event_details($description, $context), $context);
}
