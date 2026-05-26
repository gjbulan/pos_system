<?php
$pageTitle = 'Return History';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../auth/session.php';

require_login();
require_permission($pdo, 'sales.view');

$branchId = current_branch_id();
$dateFrom = trim($_GET['date_from'] ?? date('Y-m-01'));
$dateTo = trim($_GET['date_to'] ?? date('Y-m-d'));

function valid_return_date(string $date): bool
{
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    return $parsed instanceof DateTimeImmutable && $parsed->format('Y-m-d') === $date;
}

if (!valid_return_date($dateFrom)) {
    $dateFrom = date('Y-m-01');
}

if (!valid_return_date($dateTo)) {
    $dateTo = date('Y-m-d');
}

if ($dateFrom > $dateTo) {
    [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
}

$returnsStmt = $pdo->prepare('
    SELECT
        sr.*,
        s.invoice_no,
        s.payment_method,
        u.name AS processed_by,
        COALESCE(items.item_count, 0) AS item_count,
        COALESCE(items.total_qty, 0) AS total_qty
    FROM sales_returns sr
    JOIN sales s ON s.id = sr.sale_id
    LEFT JOIN users u ON u.id = sr.user_id
    LEFT JOIN (
        SELECT return_id, COUNT(*) AS item_count, SUM(qty) AS total_qty
        FROM sales_return_items
        GROUP BY return_id
    ) items ON items.return_id = sr.id
    WHERE sr.branch_id = ? AND DATE(sr.created_at) BETWEEN ? AND ?
    ORDER BY sr.id DESC
');
$returnsStmt->execute([$branchId, $dateFrom, $dateTo]);
$returns = $returnsStmt->fetchAll();

$totalRefunded = 0.0;
$totalQty = 0;
foreach ($returns as $return) {
    $totalRefunded += (float)$return['refund_amount'];
    $totalQty += (int)$return['total_qty'];
}

include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-3">
    <div>
        <h4 class="mb-0">Return History</h4>
        <small class="text-muted">Returned items and refund amounts for this branch.</small>
    </div>
    <a class="btn btn-outline-secondary" href="<?= app_url('sales/index.php') ?>">
        <i class="bi bi-arrow-left"></i> Sales History
    </a>
</div>

<div class="table-card mb-3">
    <form method="get" action="<?= app_url('sales/returns.php') ?>" class="row g-2 align-items-end">
        <div class="col-md-4">
            <label class="form-label">From</label>
            <input class="form-control" type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>">
        </div>
        <div class="col-md-4">
            <label class="form-label">To</label>
            <input class="form-control" type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>">
        </div>
        <div class="col-md-4 d-grid">
            <button class="btn btn-outline-primary">
                <i class="bi bi-funnel"></i> Filter
            </button>
        </div>
    </form>
</div>

<div class="row g-3 mb-3">
    <div class="col-md-4">
        <div class="card stat-card">
            <div class="card-body">
                <div class="text-muted small">Refunded Amount</div>
                <div class="h4 mb-0">&#8369;<?= number_format($totalRefunded, 2) ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card stat-card">
            <div class="card-body">
                <div class="text-muted small">Return Records</div>
                <div class="h4 mb-0"><?= count($returns) ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card stat-card">
            <div class="card-body">
                <div class="text-muted small">Units Returned</div>
                <div class="h4 mb-0"><?= $totalQty ?></div>
            </div>
        </div>
    </div>
</div>

<div class="table-card">
    <table class="table align-middle">
        <thead>
            <tr>
                <th>Return #</th>
                <th>Invoice</th>
                <th>Processed By</th>
                <th>Items</th>
                <th class="text-end">Qty</th>
                <th class="text-end">Refund</th>
                <th>Reason</th>
                <th>Date</th>
                <th class="text-end"></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($returns as $return): ?>
                <tr>
                    <td><code>#<?= (int)$return['id'] ?></code></td>
                    <td><code><?= htmlspecialchars($return['invoice_no']) ?></code></td>
                    <td><?= htmlspecialchars($return['processed_by'] ?? 'System') ?></td>
                    <td><?= (int)$return['item_count'] ?></td>
                    <td class="text-end"><?= (int)$return['total_qty'] ?></td>
                    <td class="text-end">&#8369;<?= number_format((float)$return['refund_amount'], 2) ?></td>
                    <td><?= htmlspecialchars($return['reason']) ?></td>
                    <td><?= htmlspecialchars(date('M d, Y h:i A', strtotime($return['created_at']))) ?></td>
                    <td class="text-end">
                        <a class="btn btn-sm btn-outline-primary" href="<?= app_url('sales/receipt.php?id=' . (int)$return['sale_id']) ?>">
                            Receipt
                        </a>
                    </td>
                </tr>
            <?php endforeach; ?>

            <?php if (!$returns): ?>
                <tr>
                    <td colspan="9" class="text-center text-muted py-4">No returns found for this date range.</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
