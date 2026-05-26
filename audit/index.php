<?php
$pageTitle = 'Audit Logs';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../auth/session.php';

require_login();
require_permission($pdo, 'audit.view');

$branchId = current_branch_id();
$module = trim($_GET['module'] ?? '');
$action = trim($_GET['action'] ?? '');
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo = trim($_GET['date_to'] ?? '');

$where = ['(a.branch_id = ? OR a.branch_id IS NULL)'];
$params = [$branchId];
if ($module !== '') { $where[] = 'a.module LIKE ?'; $params[] = "%{$module}%"; }
if ($action !== '') { $where[] = 'a.action LIKE ?'; $params[] = "%{$action}%"; }
if ($dateFrom !== '') { $where[] = 'DATE(a.created_at) >= ?'; $params[] = $dateFrom; }
if ($dateTo !== '') { $where[] = 'DATE(a.created_at) <= ?'; $params[] = $dateTo; }

$sql = "SELECT a.*, u.name AS user_name, b.name AS branch_name
        FROM audit_logs a
        LEFT JOIN users u ON u.id = a.user_id
        LEFT JOIN branches b ON b.id = a.branch_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY a.created_at DESC
        LIMIT 300";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h3 class="mb-0">Audit Logs</h3>
        <div class="text-muted">Track important actions in this branch.</div>
    </div>
</div>
<div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
        <form class="row g-2">
            <div class="col-md-3"><input class="form-control" name="module" placeholder="Module" value="<?= htmlspecialchars($module) ?>"></div>
            <div class="col-md-3"><input class="form-control" name="action" placeholder="Action" value="<?= htmlspecialchars($action) ?>"></div>
            <div class="col-md-2"><input type="date" class="form-control" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>"></div>
            <div class="col-md-2"><input type="date" class="form-control" name="date_to" value="<?= htmlspecialchars($dateTo) ?>"></div>
            <div class="col-md-2 d-grid"><button class="btn btn-primary"><i class="bi bi-search"></i> Filter</button></div>
        </form>
    </div>
</div>
<div class="card border-0 shadow-sm">
    <div class="card-body table-responsive">
        <table class="table table-hover align-middle">
            <thead><tr><th>Date/Time</th><th>User</th><th>Branch</th><th>Module</th><th>Action</th><th>Details</th><th>IP</th></tr></thead>
            <tbody>
            <?php foreach ($logs as $log): ?>
                <tr>
                    <td><?= htmlspecialchars($log['created_at']) ?></td>
                    <td><?= htmlspecialchars($log['user_name'] ?? 'System') ?></td>
                    <td><?= htmlspecialchars($log['branch_name'] ?? '-') ?></td>
                    <td><span class="badge text-bg-light"><?= htmlspecialchars($log['module']) ?></span></td>
                    <td><?= htmlspecialchars($log['action']) ?></td>
                    <td><?= htmlspecialchars($log['details'] ?? '') ?></td>
                    <td><?= htmlspecialchars($log['ip_address'] ?? '') ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$logs): ?><tr><td colspan="7" class="text-center text-muted py-4">No logs found.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
