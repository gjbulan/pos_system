<?php
$pageTitle = 'Users';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../auth/session.php';

require_login();
require_permission($pdo, 'users.manage');

$currentUserId = (int)($_SESSION['user_id'] ?? 0);
$roles = valid_user_roles();
$search = trim($_GET['q'] ?? '');
$roleFilter = trim($_GET['role'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');
$branchFilter = trim($_GET['branch_id'] ?? '');

$branchStmt = $pdo->prepare('SELECT id, name, code FROM branches ORDER BY name');
$branchStmt->execute();
$branches = $branchStmt->fetchAll();

$where = [];
$params = [];

if ($search !== '') {
    $where[] = '(u.name LIKE ? OR u.username LIKE ? OR b.name LIKE ? OR area.area_branches LIKE ?)';
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

if (in_array($roleFilter, $roles, true)) {
    $where[] = 'u.role = ?';
    $params[] = $roleFilter;
}

if ($statusFilter === 'active') {
    $where[] = 'u.is_active = 1';
} elseif ($statusFilter === 'inactive') {
    $where[] = 'u.is_active = 0';
}

if ($branchFilter !== '' && ctype_digit($branchFilter)) {
    $where[] = '(u.role = "Admin" OR u.branch_id = ? OR EXISTS (SELECT 1 FROM user_branches ubf WHERE ubf.user_id = u.id AND ubf.branch_id = ?))';
    $params[] = (int)$branchFilter;
    $params[] = (int)$branchFilter;
}

$sql = '
    SELECT
        u.id,
        u.branch_id,
        u.name,
        u.username,
        u.role,
        u.is_active,
        u.created_at,
        b.name AS branch_name,
        b.code AS branch_code,
        area.area_branches
    FROM users u
    LEFT JOIN branches b ON b.id = u.branch_id
    LEFT JOIN (
        SELECT
            ub.user_id,
            GROUP_CONCAT(CONCAT(ab.name, " (", ab.code, ")") ORDER BY ab.name SEPARATOR ", ") AS area_branches
        FROM user_branches ub
        JOIN branches ab ON ab.id = ub.branch_id
        GROUP BY ub.user_id
    ) area ON area.user_id = u.id
';
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY u.name ASC LIMIT 300';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll();

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-3">
    <div>
        <h4 class="mb-0">Users</h4>
        <small class="text-muted">Manage staff accounts, roles, and branch access.</small>
    </div>
    <a class="btn btn-primary" href="<?= app_url('users/add.php') ?>">
        <i class="bi bi-plus-lg me-1"></i>
        Add User
    </a>
</div>

<?php if ($flash): ?>
    <div class="alert alert-<?= htmlspecialchars($flash['type']) ?> alert-dismissible fade show" role="alert">
        <?= htmlspecialchars($flash['message']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<div class="table-card mb-3">
    <form class="row g-2 align-items-end" method="get">
        <div class="col-lg-4 col-md-6">
            <label class="form-label">Search</label>
            <input
                type="search"
                name="q"
                class="form-control"
                placeholder="Search name, username, or branch"
                value="<?= htmlspecialchars($search) ?>"
            >
        </div>
        <div class="col-lg-2 col-md-6">
            <label class="form-label">Role</label>
            <select name="role" class="form-select">
                <option value="">All roles</option>
                <?php foreach ($roles as $role): ?>
                    <option value="<?= htmlspecialchars($role) ?>" <?= $roleFilter === $role ? 'selected' : '' ?>>
                        <?= htmlspecialchars($role) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-lg-2 col-md-6">
            <label class="form-label">Status</label>
            <select name="status" class="form-select">
                <option value="">All statuses</option>
                <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option>
                <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Inactive</option>
            </select>
        </div>
        <div class="col-lg-2 col-md-6">
            <label class="form-label">Branch</label>
            <select name="branch_id" class="form-select">
                <option value="">All branches</option>
                <?php foreach ($branches as $branch): ?>
                    <option value="<?= (int)$branch['id'] ?>" <?= $branchFilter === (string)$branch['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($branch['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-lg-2 col-md-6 d-grid">
            <button class="btn btn-outline-primary" type="submit">
                <i class="bi bi-funnel me-1"></i>
                Filter
            </button>
        </div>
    </form>
</div>

<div class="table-card">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="mb-0">User List</h5>
        <span class="badge text-bg-light"><?= count($users) ?> shown</span>
    </div>

    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Name</th>
                    <th>Username</th>
                    <th>Role</th>
                    <th>Branch Access</th>
                    <th>Status</th>
                    <th>Created</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $user): ?>
                    <?php
                    $isCurrent = (int)$user['id'] === $currentUserId;
                    $isActive = (int)$user['is_active'] === 1;
                    ?>
                    <tr>
                        <td class="fw-semibold">
                            <?= htmlspecialchars($user['name']) ?>
                            <?php if ($isCurrent): ?>
                                <span class="badge text-bg-primary ms-2">You</span>
                            <?php endif; ?>
                        </td>
                        <td><code><?= htmlspecialchars($user['username']) ?></code></td>
                        <td><?= htmlspecialchars($user['role']) ?></td>
                        <td>
                            <?php if ($user['role'] === 'Admin'): ?>
                                <span class="text-muted">All branches</span>
                            <?php elseif ($user['role'] === 'Area Manager'): ?>
                                <?= htmlspecialchars($user['area_branches'] ?: 'No assigned branches') ?>
                            <?php elseif ($user['branch_name']): ?>
                                <?= htmlspecialchars($user['branch_name']) ?>
                                <small class="text-muted">(<?= htmlspecialchars($user['branch_code']) ?>)</small>
                            <?php else: ?>
                                <span class="text-muted">Unassigned</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge <?= $isActive ? 'text-bg-success' : 'text-bg-secondary' ?>">
                                <?= $isActive ? 'Active' : 'Inactive' ?>
                            </span>
                        </td>
                        <td><?= htmlspecialchars(date('M d, Y', strtotime($user['created_at']))) ?></td>
                        <td class="text-end">
                            <div class="btn-group btn-group-sm" role="group" aria-label="User actions">
                                <a class="btn btn-outline-primary" href="<?= app_url('users/edit.php?id=' . (int)$user['id']) ?>">
                                    <i class="bi bi-pencil-square"></i>
                                </a>
                                <?php if ($isCurrent || !$isActive): ?>
                                    <button
                                        class="btn btn-outline-secondary"
                                        type="button"
                                        disabled
                                        title="<?= $isCurrent ? 'Current user cannot be deactivated' : 'User is already inactive' ?>"
                                    >
                                        <i class="bi bi-person-x"></i>
                                    </button>
                                <?php else: ?>
                                    <a class="btn btn-outline-danger" href="<?= app_url('users/delete.php?id=' . (int)$user['id']) ?>">
                                        <i class="bi bi-person-x"></i>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>

                <?php if (!$users): ?>
                    <tr>
                        <td colspan="7" class="text-center text-muted py-4">
                            No users found.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
