<?php
$pageTitle = 'Add Expense';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../auth/session.php';

require_login();
require_permission($pdo, 'expenses.manage');

$branchId = current_branch_id();

function valid_expense_date(string $date): bool
{
    $parsed = DateTime::createFromFormat('Y-m-d', $date);
    return $parsed !== false && $parsed->format('Y-m-d') === $date;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $amountInput = trim($_POST['amount'] ?? '');
    $expenseDate = trim($_POST['expense_date'] ?? '');
    $amount = filter_var($amountInput, FILTER_VALIDATE_FLOAT);

    $_SESSION['expense_old'] = [
        'title' => $title,
        'amount' => $amountInput,
        'expense_date' => $expenseDate,
    ];

    if ($title === '') {
        $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Expense title is required.'];
        redirect_to('expenses/add.php');
    }

    if ($amount === false || $amount <= 0) {
        $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Enter an amount greater than zero.'];
        redirect_to('expenses/add.php');
    }

    if (!valid_expense_date($expenseDate)) {
        $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Enter a valid expense date.'];
        redirect_to('expenses/add.php');
    }

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO expenses (branch_id, title, amount, expense_date)
             VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$branchId, $title, $amount, $expenseDate]);

        unset($_SESSION['expense_old']);
        log_activity($pdo, 'create', 'expenses', 'Created expense: ' . $title . ' amount: ' . number_format((float)$amount, 2));
        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Expense added successfully.'];
        redirect_to('expenses/index.php');
    } catch (Throwable $e) {
        $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Unable to add expense. Please try again.'];
        redirect_to('expenses/add.php');
    }
}

$old = $_SESSION['expense_old'] ?? [
    'title' => '',
    'amount' => '',
    'expense_date' => date('Y-m-d'),
];
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['expense_old'], $_SESSION['flash']);

include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h4 class="mb-0">Add Expense</h4>
        <small class="text-muted">Record a branch expense with date and amount.</small>
    </div>
    <a class="btn btn-outline-secondary" href="<?= app_url('expenses/index.php') ?>">
        <i class="bi bi-arrow-left me-1"></i>
        Back
    </a>
</div>

<?php if ($flash): ?>
    <div class="alert alert-<?= htmlspecialchars($flash['type']) ?> alert-dismissible fade show" role="alert">
        <?= htmlspecialchars($flash['message']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<form method="post" class="table-card">
    <div class="row g-3">
        <div class="col-md-6">
            <label class="form-label">Expense Title <span class="text-danger">*</span></label>
            <input
                type="text"
                name="title"
                class="form-control"
                maxlength="160"
                required
                value="<?= htmlspecialchars($old['title']) ?>"
            >
        </div>
        <div class="col-md-3">
            <label class="form-label">Amount <span class="text-danger">*</span></label>
            <input
                type="number"
                name="amount"
                class="form-control"
                min="0.01"
                step="0.01"
                required
                value="<?= htmlspecialchars($old['amount']) ?>"
            >
        </div>
        <div class="col-md-3">
            <label class="form-label">Expense Date <span class="text-danger">*</span></label>
            <input
                type="date"
                name="expense_date"
                class="form-control"
                required
                value="<?= htmlspecialchars($old['expense_date']) ?>"
            >
        </div>
    </div>

    <div class="d-flex justify-content-end gap-2 mt-4">
        <a class="btn btn-outline-secondary" href="<?= app_url('expenses/index.php') ?>">Cancel</a>
        <button class="btn btn-primary" type="submit">
            <i class="bi bi-save me-1"></i>
            Save Expense
        </button>
    </div>
</form>

<?php include __DIR__ . '/../includes/footer.php'; ?>
