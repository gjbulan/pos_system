<?php
$pageTitle = 'Delete Supplier';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../auth/session.php';

require_login();
require_permission($pdo, 'suppliers.manage');

$branchId = current_branch_id();
$id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);

if ($id <= 0) {
    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Supplier was not found.'];
    redirect_to('suppliers/index.php');
}

$stmt = $pdo->prepare(
    'SELECT id, name, phone, email
     FROM suppliers
     WHERE id = ? AND branch_id = ?'
);
$stmt->execute([$id, $branchId]);
$supplier = $stmt->fetch();

if (!$supplier) {
    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Supplier was not found in this branch.'];
    redirect_to('suppliers/index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $deleteStmt = $pdo->prepare('DELETE FROM suppliers WHERE id = ? AND branch_id = ?');
        $deleteStmt->execute([$id, $branchId]);

        log_activity($pdo, 'delete', 'suppliers', 'Deleted supplier: ' . $supplier['name']);
        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Supplier deleted successfully.'];
        redirect_to('suppliers/index.php');
    } catch (Throwable $e) {
        $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Unable to delete supplier. Please try again.'];
        redirect_to('suppliers/index.php');
    }
}

include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h4 class="mb-0">Delete Supplier</h4>
        <small class="text-muted">Confirm removal from the current branch.</small>
    </div>
    <a class="btn btn-outline-secondary" href="<?= app_url('suppliers/index.php') ?>">
        <i class="bi bi-arrow-left me-1"></i>
        Back
    </a>
</div>

<div class="table-card">
    <div class="alert alert-warning mb-4">
        <strong>Delete this supplier?</strong>
        This action cannot be undone.
    </div>

    <dl class="row mb-4">
        <dt class="col-sm-3">Name</dt>
        <dd class="col-sm-9"><?= htmlspecialchars($supplier['name']) ?></dd>

        <dt class="col-sm-3">Phone</dt>
        <dd class="col-sm-9"><?= htmlspecialchars($supplier['phone'] ?: '-') ?></dd>

        <dt class="col-sm-3">Email</dt>
        <dd class="col-sm-9"><?= htmlspecialchars($supplier['email'] ?: '-') ?></dd>
    </dl>

    <form method="post" class="d-flex justify-content-end gap-2">
        <input type="hidden" name="id" value="<?= (int)$supplier['id'] ?>">
        <a class="btn btn-outline-secondary" href="<?= app_url('suppliers/index.php') ?>">Cancel</a>
        <button class="btn btn-danger" type="submit">
            <i class="bi bi-trash me-1"></i>
            Delete Supplier
        </button>
    </form>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
