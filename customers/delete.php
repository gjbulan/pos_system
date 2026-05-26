<?php
$pageTitle = 'Delete Customer';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../auth/session.php';

require_login();
require_permission($pdo, 'customers.manage');

$branchId = current_branch_id();
$id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);

if ($id <= 0) {
    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Customer was not found.'];
    redirect_to('customers/index.php');
}

$stmt = $pdo->prepare(
    'SELECT id, name, phone, email
     FROM customers
     WHERE id = ? AND branch_id = ?'
);
$stmt->execute([$id, $branchId]);
$customer = $stmt->fetch();

if (!$customer) {
    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Customer was not found in this branch.'];
    redirect_to('customers/index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $deleteStmt = $pdo->prepare('DELETE FROM customers WHERE id = ? AND branch_id = ?');
        $deleteStmt->execute([$id, $branchId]);

        log_activity($pdo, 'delete', 'customers', 'Deleted customer: ' . $customer['name']);
        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Customer deleted successfully.'];
        redirect_to('customers/index.php');
    } catch (Throwable $e) {
        $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Unable to delete customer. Please try again.'];
        redirect_to('customers/index.php');
    }
}

include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h4 class="mb-0">Delete Customer</h4>
        <small class="text-muted">Confirm removal from the current branch.</small>
    </div>
    <a class="btn btn-outline-secondary" href="<?= app_url('customers/index.php') ?>">
        <i class="bi bi-arrow-left me-1"></i>
        Back
    </a>
</div>

<div class="table-card">
    <div class="alert alert-warning mb-4">
        <strong>Delete this customer?</strong>
        This action cannot be undone.
    </div>

    <dl class="row mb-4">
        <dt class="col-sm-3">Name</dt>
        <dd class="col-sm-9"><?= htmlspecialchars($customer['name']) ?></dd>

        <dt class="col-sm-3">Phone</dt>
        <dd class="col-sm-9"><?= htmlspecialchars($customer['phone'] ?: '-') ?></dd>

        <dt class="col-sm-3">Email</dt>
        <dd class="col-sm-9"><?= htmlspecialchars($customer['email'] ?: '-') ?></dd>
    </dl>

    <form method="post" class="d-flex justify-content-end gap-2">
        <input type="hidden" name="id" value="<?= (int)$customer['id'] ?>">
        <a class="btn btn-outline-secondary" href="<?= app_url('customers/index.php') ?>">Cancel</a>
        <button class="btn btn-danger" type="submit">
            <i class="bi bi-trash me-1"></i>
            Delete Customer
        </button>
    </form>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
