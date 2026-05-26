<?php
$pageTitle = 'Delete Branch';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../auth/session.php';

require_login();
require_permission($pdo, 'branches.manage');

$currentBranchId = current_branch_id();
$id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);

if ($id <= 0) {
    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Branch was not found.'];
    redirect_to('branches/index.php');
}

if ($id === $currentBranchId) {
    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'The currently active branch cannot be deleted.'];
    redirect_to('branches/index.php');
}

$stmt = $pdo->prepare(
    'SELECT id, name, code, address
     FROM branches
     WHERE id = ?'
);
$stmt->execute([$id]);
$branch = $stmt->fetch();

if (!$branch) {
    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Branch was not found.'];
    redirect_to('branches/index.php');
}

$branchCount = (int)$pdo->query('SELECT COUNT(*) FROM branches')->fetchColumn();
if ($branchCount <= 1) {
    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'At least one branch must remain in the system.'];
    redirect_to('branches/index.php');
}

$relatedTables = [
    'users' => 'users',
    'categories' => 'categories',
    'products' => 'products',
    'customers' => 'customers',
    'suppliers' => 'suppliers',
    'expenses' => 'expenses',
    'sales' => 'sales',
    'inventory_movements' => 'inventory movements',
    'cash_sessions' => 'cash drawer sessions',
    'cash_drawer_transactions' => 'cash drawer transactions',
    'audit_logs' => 'audit logs',
];
$relatedCounts = [];

foreach ($relatedTables as $table => $label) {
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE branch_id = ?");
    $countStmt->execute([$id]);
    $count = (int)$countStmt->fetchColumn();
    if ($count > 0) {
        $relatedCounts[] = $label . ' (' . $count . ')';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($relatedCounts) {
        $_SESSION['flash'] = [
            'type' => 'danger',
            'message' => 'This branch has related records and cannot be deleted: ' . implode(', ', $relatedCounts) . '.',
        ];
        redirect_to('branches/index.php');
    }

    try {
        $deleteStmt = $pdo->prepare('DELETE FROM branches WHERE id = ?');
        $deleteStmt->execute([$id]);

        log_activity($pdo, 'delete', 'branches', 'Deleted branch: ' . $branch['name'] . ' (' . $branch['code'] . ')');
        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Branch deleted successfully.'];
        redirect_to('branches/index.php');
    } catch (Throwable $e) {
        $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Unable to delete branch. Please check related records and try again.'];
        redirect_to('branches/index.php');
    }
}

include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h4 class="mb-0">Delete Branch</h4>
        <small class="text-muted">Confirm removal from the system.</small>
    </div>
    <a class="btn btn-outline-secondary" href="<?= app_url('branches/index.php') ?>">
        <i class="bi bi-arrow-left me-1"></i>
        Back
    </a>
</div>

<div class="table-card">
    <div class="alert alert-warning mb-4">
        <strong>Delete this branch?</strong>
        This action cannot be undone.
    </div>

    <?php if ($relatedCounts): ?>
        <div class="alert alert-danger">
            This branch has related records and cannot be deleted:
            <?= htmlspecialchars(implode(', ', $relatedCounts)) ?>.
        </div>
    <?php endif; ?>

    <dl class="row mb-4">
        <dt class="col-sm-3">Name</dt>
        <dd class="col-sm-9"><?= htmlspecialchars($branch['name']) ?></dd>

        <dt class="col-sm-3">Code</dt>
        <dd class="col-sm-9"><code><?= htmlspecialchars($branch['code']) ?></code></dd>

        <dt class="col-sm-3">Address</dt>
        <dd class="col-sm-9"><?= htmlspecialchars($branch['address'] ?: '-') ?></dd>
    </dl>

    <form method="post" class="d-flex justify-content-end gap-2">
        <input type="hidden" name="id" value="<?= (int)$branch['id'] ?>">
        <a class="btn btn-outline-secondary" href="<?= app_url('branches/index.php') ?>">Cancel</a>
        <button class="btn btn-danger" type="submit" <?= $relatedCounts ? 'disabled' : '' ?>>
            <i class="bi bi-trash me-1"></i>
            Delete Branch
        </button>
    </form>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
