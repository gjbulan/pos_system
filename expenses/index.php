<?php
$pageTitle = 'Expenses';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../auth/session.php';

require_login();
require_permission($pdo, 'expenses.manage');

$branchId = current_branch_id();
$search = trim($_GET['q'] ?? '');
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo = trim($_GET['date_to'] ?? '');

$where = ['branch_id = ?'];
$params = [$branchId];

if ($search !== '') {
    $where[] = 'title LIKE ?';
    $params[] = '%' . $search . '%';
}

if ($dateFrom !== '') {
    $where[] = 'expense_date >= ?';
    $params[] = $dateFrom;
}

if ($dateTo !== '') {
    $where[] = 'expense_date <= ?';
    $params[] = $dateTo;
}

$stmt = $pdo->prepare(
    'SELECT id, title, amount, expense_date, created_at
     FROM expenses
     WHERE ' . implode(' AND ', $where) . '
     ORDER BY expense_date DESC, id DESC
     LIMIT 300'
);
$stmt->execute($params);
$expenses = $stmt->fetchAll();

$totalAmount = 0;
foreach ($expenses as $expense) {
    $totalAmount += (float)$expense['amount'];
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-3">
    <div>
        <h4 class="mb-0">Expenses</h4>
        <small class="text-muted">Track branch expenses by date and amount.</small>
    </div>
    <a class="btn btn-primary" href="<?= app_url('expenses/add.php') ?>">
        <i class="bi bi-plus-lg me-1"></i>
        Add Expense
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
                placeholder="Search expense title"
                value="<?= htmlspecialchars($search) ?>"
            >
        </div>
        <div class="col-lg-3 col-md-6">
            <label class="form-label">From</label>
            <input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($dateFrom) ?>">
        </div>
        <div class="col-lg-3 col-md-6">
            <label class="form-label">To</label>
            <input type="date" name="date_to" class="form-control" value="<?= htmlspecialchars($dateTo) ?>">
        </div>
        <div class="col-lg-2 col-md-6 d-grid">
            <button class="btn btn-outline-primary" type="submit">
                <i class="bi bi-funnel me-1"></i>
                Filter
            </button>
        </div>
    </form>
</div>

<div class="row g-3 mb-3">
    <div class="col-md-4">
        <div class="card stat-card">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="stat-icon"><i class="bi bi-wallet2"></i></div>
                <div>
                    <div class="text-muted small">Shown Total</div>
                    <div class="h5 mb-0">PHP <?= number_format($totalAmount, 2) ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card stat-card">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="stat-icon"><i class="bi bi-list-check"></i></div>
                <div>
                    <div class="text-muted small">Records Shown</div>
                    <div class="h5 mb-0"><?= count($expenses) ?></div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="table-card">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="mb-0">Expense List</h5>
        <span class="badge text-bg-light"><?= count($expenses) ?> shown</span>
    </div>

    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Date</th>
                    <th>Title</th>
                    <th class="text-end">Amount</th>
                    <th>Created</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($expenses as $expense): ?>
                    <tr>
                        <td><?= htmlspecialchars(date('M d, Y', strtotime($expense['expense_date']))) ?></td>
                        <td class="fw-semibold"><?= htmlspecialchars($expense['title']) ?></td>
                        <td class="text-end">PHP <?= number_format((float)$expense['amount'], 2) ?></td>
                        <td><?= htmlspecialchars(date('M d, Y', strtotime($expense['created_at']))) ?></td>
                        <td class="text-end">
                            <div class="btn-group btn-group-sm" role="group" aria-label="Expense actions">
                                <a class="btn btn-outline-primary" href="<?= app_url('expenses/edit.php?id=' . (int)$expense['id']) ?>">
                                    <i class="bi bi-pencil-square"></i>
                                </a>
                                <a class="btn btn-outline-danger" href="<?= app_url('expenses/delete.php?id=' . (int)$expense['id']) ?>">
                                    <i class="bi bi-trash"></i>
                                </a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>

                <?php if (!$expenses): ?>
                    <tr>
                        <td colspan="5" class="text-center text-muted py-4">
                            No expenses found for this branch.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
