<?php
$pageTitle = 'Delete Expense';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../auth/session.php';

require_login();
require_permission($pdo, 'expenses.manage');

$branchId = current_branch_id();
$id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);

if ($id <= 0) {
    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Expense was not found.'];
    redirect_to('expenses/index.php');
}

$stmt = $pdo->prepare(
    'SELECT id, title, amount, expense_date
     FROM expenses
     WHERE id = ? AND branch_id = ?'
);
$stmt->execute([$id, $branchId]);
$expense = $stmt->fetch();

if (!$expense) {
    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Expense was not found in this branch.'];
    redirect_to('expenses/index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $deleteStmt = $pdo->prepare('DELETE FROM expenses WHERE id = ? AND branch_id = ?');
        $deleteStmt->execute([$id, $branchId]);

        log_activity($pdo, 'delete', 'expenses', 'Deleted expense: ' . $expense['title'] . ' amount: ' . number_format((float)$expense['amount'], 2));
        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Expense deleted successfully.'];
        redirect_to('expenses/index.php');
    } catch (Throwable $e) {
        $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Unable to delete expense. Please try again.'];
        redirect_to('expenses/index.php');
    }
}

include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h4 class="mb-0">Delete Expense</h4>
        <small class="text-muted">Confirm removal from the current branch.</small>
    </div>
    <a class="btn btn-outline-secondary" href="<?= app_url('expenses/index.php') ?>">
        <i class="bi bi-arrow-left me-1"></i>
        Back
    </a>
</div>

<div class="table-card">
    <div class="alert alert-warning mb-4">
        <strong>Delete this expense?</strong>
        This action cannot be undone.
    </div>

    <dl class="row mb-4">
        <dt class="col-sm-3">Title</dt>
        <dd class="col-sm-9"><?= htmlspecialchars($expense['title']) ?></dd>

        <dt class="col-sm-3">Amount</dt>
        <dd class="col-sm-9">PHP <?= number_format((float)$expense['amount'], 2) ?></dd>

        <dt class="col-sm-3">Expense Date</dt>
        <dd class="col-sm-9"><?= htmlspecialchars(date('M d, Y', strtotime($expense['expense_date']))) ?></dd>
    </dl>

    <form method="post" class="d-flex justify-content-end gap-2">
        <input type="hidden" name="id" value="<?= (int)$expense['id'] ?>">
        <a class="btn btn-outline-secondary" href="<?= app_url('expenses/index.php') ?>">Cancel</a>
        <button class="btn btn-danger" type="submit">
            <i class="bi bi-trash me-1"></i>
            Delete Expense
        </button>
    </form>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
