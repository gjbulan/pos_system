<?php
$pageTitle = 'Audit Logs';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../auth/session.php';

require_login();
require_permission($pdo, 'audit.view');

$accessibleBranches = session_accessible_branches($pdo);
$accessibleBranchIds = array_map('intval', array_column($accessibleBranches, 'id'));
$module = trim($_GET['module'] ?? '');
$action = trim($_GET['action'] ?? '');
$userFilter = trim($_GET['user_id'] ?? '');
$branchFilter = trim($_GET['branch_id'] ?? '');
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo = trim($_GET['date_to'] ?? '');

$where = [];
$params = [];

if ($accessibleBranchIds) {
    $placeholders = implode(',', array_fill(0, count($accessibleBranchIds), '?'));
    $where[] = '(a.branch_id IS NULL OR a.branch_id IN (' . $placeholders . '))';
    array_push($params, ...$accessibleBranchIds);
} else {
    $where[] = 'a.branch_id IS NULL';
}

if ($branchFilter !== '' && ctype_digit($branchFilter) && in_array((int)$branchFilter, $accessibleBranchIds, true)) {
    $where[] = 'a.branch_id = ?';
    $params[] = (int)$branchFilter;
}

if ($userFilter !== '' && ctype_digit($userFilter)) {
    $where[] = 'a.user_id = ?';
    $params[] = (int)$userFilter;
}

if ($module !== '') {
    $where[] = 'a.module LIKE ?';
    $params[] = '%' . $module . '%';
}

if ($action !== '') {
    $where[] = 'a.action = ?';
    $params[] = $action;
}

if ($dateFrom !== '') {
    $where[] = 'DATE(a.created_at) >= ?';
    $params[] = $dateFrom;
}

if ($dateTo !== '') {
    $where[] = 'DATE(a.created_at) <= ?';
    $params[] = $dateTo;
}

$accessWhere = [];
$accessParams = [];
if ($accessibleBranchIds) {
    $accessPlaceholders = implode(',', array_fill(0, count($accessibleBranchIds), '?'));
    $accessWhere[] = '(a.branch_id IS NULL OR a.branch_id IN (' . $accessPlaceholders . '))';
    array_push($accessParams, ...$accessibleBranchIds);
} else {
    $accessWhere[] = 'a.branch_id IS NULL';
}

$actionStmt = $pdo->prepare('
    SELECT DISTINCT a.action
    FROM audit_logs a
    WHERE ' . implode(' AND ', $accessWhere) . '
    ORDER BY a.action
');
$actionStmt->execute($accessParams);
$actionOptions = $actionStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

$userStmt = $pdo->prepare('
    SELECT DISTINCT u.id, u.name, u.username, u.role
    FROM audit_logs a
    JOIN users u ON u.id = a.user_id
    WHERE ' . implode(' AND ', $accessWhere) . '
    ORDER BY u.name, u.username
');
$userStmt->execute($accessParams);
$userOptions = $userStmt->fetchAll();

$sql = '
    SELECT
        a.*,
        u.name AS user_name,
        u.username,
        u.role,
        b.name AS branch_name,
        b.code AS branch_code
    FROM audit_logs a
    LEFT JOIN users u ON u.id = a.user_id
    LEFT JOIN branches b ON b.id = a.branch_id
    WHERE ' . implode(' AND ', $where) . '
    ORDER BY a.created_at DESC
    LIMIT 300
';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h3 class="mb-0">Audit Logs</h3>
        <div class="text-muted">Track important user, branch, and system actions.</div>
    </div>
</div>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
        <form class="row g-2 align-items-end" method="get" action="<?= app_url('audit/index.php') ?>">
            <div class="col-lg-2 col-md-4">
                <label class="form-label">Date From</label>
                <input type="date" class="form-control" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>">
            </div>
            <div class="col-lg-2 col-md-4">
                <label class="form-label">Date To</label>
                <input type="date" class="form-control" name="date_to" value="<?= htmlspecialchars($dateTo) ?>">
            </div>
            <div class="col-lg-2 col-md-4">
                <label class="form-label">Branch</label>
                <select name="branch_id" class="form-select">
                    <option value="">All accessible</option>
                    <?php foreach ($accessibleBranches as $branch): ?>
                        <option value="<?= (int)$branch['id'] ?>" <?= $branchFilter === (string)$branch['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($branch['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-2 col-md-4">
                <label class="form-label">User</label>
                <select name="user_id" class="form-select">
                    <option value="">All users</option>
                    <?php foreach ($userOptions as $user): ?>
                        <option value="<?= (int)$user['id'] ?>" <?= $userFilter === (string)$user['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($user['name']) ?> (<?= htmlspecialchars($user['username']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-2 col-md-4">
                <label class="form-label">Action</label>
                <select name="action" class="form-select">
                    <option value="">All actions</option>
                    <?php foreach ($actionOptions as $actionOption): ?>
                        <option value="<?= htmlspecialchars($actionOption) ?>" <?= $action === $actionOption ? 'selected' : '' ?>>
                            <?= htmlspecialchars($actionOption) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-2 col-md-4">
                <label class="form-label">Module</label>
                <input class="form-control" name="module" placeholder="Module" value="<?= htmlspecialchars($module) ?>">
            </div>
            <div class="col-12 d-flex justify-content-end gap-2">
                <a class="btn btn-outline-secondary" href="<?= app_url('audit/index.php') ?>">Reset</a>
                <button class="btn btn-primary">
                    <i class="bi bi-search"></i> Filter
                </button>
            </div>
        </form>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body table-responsive">
        <table class="table table-hover align-middle">
            <thead>
                <tr>
                    <th>Date/Time</th>
                    <th>User</th>
                    <th>Branch</th>
                    <th>Module</th>
                    <th>Action</th>
                    <th>Details</th>
                    <th>IP</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($logs as $log): ?>
                    <tr>
                        <td><?= htmlspecialchars($log['created_at']) ?></td>
                        <td>
                            <?php if ($log['user_name']): ?>
                                <?= htmlspecialchars($log['user_name']) ?>
                                <?php if (!empty($log['username'])): ?>
                                    <br><small class="text-muted"><?= htmlspecialchars($log['username']) ?> - <?= htmlspecialchars($log['role'] ?? '') ?></small>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-muted">Unknown</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($log['branch_name']): ?>
                                <?= htmlspecialchars($log['branch_name']) ?>
                                <br><small class="text-muted"><?= htmlspecialchars($log['branch_code'] ?? '') ?></small>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td><span class="badge text-bg-light"><?= htmlspecialchars($log['module']) ?></span></td>
                        <td><?= htmlspecialchars($log['action']) ?></td>
                        <td><?= htmlspecialchars($log['details'] ?? '') ?></td>
                        <td><?= htmlspecialchars($log['ip_address'] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$logs): ?>
                    <tr>
                        <td colspan="7" class="text-center text-muted py-4">No logs found.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
