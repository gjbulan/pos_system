<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../auth/session.php';

require_login();
require_permission($pdo, 'permissions.manage');

$title = 'User Permissions';

$roles = ['Admin', 'Area Manager', 'Manager', 'Cashier'];

$permissions = [
    'dashboard.view'       => 'View dashboard',
    'pos.access'           => 'Use POS checkout',
    'sales.view'           => 'View sales history',
    'products.view'        => 'View products',
    'products.manage'      => 'Manage products',
    'categories.manage'    => 'Manage categories',
    'inventory.view'       => 'View inventory',
    'inventory.manage'     => 'Manage inventory / stock in',
    'customers.view'       => 'View customers',
    'customers.manage'     => 'Manage customers',
    'suppliers.manage'     => 'Manage suppliers',
    'purchases.view'       => 'View purchase orders',
    'purchases.manage'     => 'Create and receive purchase orders',
    'expenses.manage'      => 'Manage expenses',
    'reports.view'         => 'View reports',
    'users.manage'         => 'Manage users',
    'branches.manage'      => 'Manage branches',
    'settings.manage'      => 'Manage settings',
    'backup.manage'        => 'Backup and restore database',
    'audit.view'           => 'View audit logs',
    'cash_drawer.manage'   => 'Manage cash drawer / shifts',
    'permissions.manage'   => 'Manage role permissions'
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($roles as $role) {
        if ($role === 'Admin') {
            continue;
        }

        $deleteStmt = $pdo->prepare("DELETE FROM role_permissions WHERE role_name = ?");
        $deleteStmt->execute([$role]);

        $selectedPermissions = $_POST['permissions'][$role] ?? [];

        foreach ($selectedPermissions as $permissionKey) {
            if (!isset($permissions[$permissionKey])) {
                continue;
            }

            $insertStmt = $pdo->prepare("
                INSERT INTO role_permissions 
                    (role_name, permission_key, is_allowed) 
                VALUES 
                    (?, ?, 1)
            ");

            $insertStmt->execute([
                $role,
                $permissionKey
            ]);
        }
    }

    if (function_exists('log_activity')) {
        log_activity($pdo, 'update', 'permissions', 'Updated role permissions.');
    }

    header('Location: index.php?saved=1');
    exit;
}

$current = [];

$stmt = $pdo->query("
    SELECT role_name, permission_key 
    FROM role_permissions 
    WHERE is_allowed = 1
");

foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $current[$row['role_name']][] = $row['permission_key'];
}

include __DIR__ . '/../includes/header.php';
?>

<div class="container-fluid py-4">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
        <div>
            <h1 class="h3 mb-1">User Permissions</h1>
            <p class="text-muted mb-0">
                Control which pages and actions each role can access.
            </p>
        </div>
    </div>

    <?php if (isset($_GET['saved'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            Permissions saved successfully.
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <form method="post" class="card shadow-sm border-0">
        <div class="card-body">
            <div class="alert alert-info mb-4">
                Admin always has full access. Configure Area Manager, Manager, and Cashier permissions below.
            </div>

            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Permission</th>
                            <th class="text-center">Admin</th>
                            <?php foreach ($roles as $role): ?>
                                <?php if ($role === 'Admin') { continue; } ?>
                                <th class="text-center"><?= htmlspecialchars($role) ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>

                    <tbody>
                        <?php foreach ($permissions as $key => $label): ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($label) ?></strong>
                                    <br>
                                    <small class="text-muted">
                                        <?= htmlspecialchars($key) ?>
                                    </small>
                                </td>

                                <td class="text-center">
                                    <i class="bi bi-check-circle-fill text-success fs-5"></i>
                                </td>

                                <?php foreach ($roles as $role): ?>
                                    <?php if ($role === 'Admin') { continue; } ?>
                                    <td class="text-center">
                                        <input 
                                            class="form-check-input" 
                                            type="checkbox" 
                                            name="permissions[<?= htmlspecialchars($role) ?>][]" 
                                            value="<?= htmlspecialchars($key) ?>"
                                            <?= in_array($key, $current[$role] ?? [], true) ? 'checked' : '' ?>
                                        >
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="d-flex justify-content-end mt-4">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-save me-1"></i>
                    Save Permissions
                </button>
            </div>
        </div>
    </form>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
