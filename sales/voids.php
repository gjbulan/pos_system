<?php
$pageTitle = 'Sale Voids';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../auth/session.php';

require_login();
require_permission($pdo, 'sales.view');

$branchId = current_branch_id();
$canApproveVoid = can($pdo, 'sales.void.approve');
$statusFilter = trim($_GET['status'] ?? '');
$dateFrom = trim($_GET['date_from'] ?? date('Y-m-01'));
$dateTo = trim($_GET['date_to'] ?? date('Y-m-d'));
$validStatuses = ['void_requested', 'voided'];

function valid_void_date(string $date): bool
{
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    return $parsed instanceof DateTimeImmutable && $parsed->format('Y-m-d') === $date;
}

function void_status_badge(string $status): string
{
    return match ($status) {
        'voided' => 'secondary',
        'void_requested' => 'warning',
        default => 'light',
    };
}

if (!valid_void_date($dateFrom)) {
    $dateFrom = date('Y-m-01');
}

if (!valid_void_date($dateTo)) {
    $dateTo = date('Y-m-d');
}

if ($dateFrom > $dateTo) {
    [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
}

$where = ['s.branch_id = ?', 's.status IN ("void_requested", "voided")'];
$params = [$branchId];

if ($statusFilter !== '' && in_array($statusFilter, $validStatuses, true)) {
    $where[] = 's.status = ?';
    $params[] = $statusFilter;
}

$where[] = 'DATE(COALESCE(s.voided_at, s.created_at)) BETWEEN ? AND ?';
$params[] = $dateFrom;
$params[] = $dateTo;

$stmt = $pdo->prepare('
    SELECT
        s.*,
        cashier.name AS cashier_name,
        c.name AS customer_name,
        voider.name AS voided_by_name,
        COALESCE(items.item_count, 0) AS item_count,
        COALESCE(items.total_qty, 0) AS total_qty
    FROM sales s
    LEFT JOIN users cashier ON cashier.id = s.user_id
    LEFT JOIN customers c ON c.id = s.customer_id AND c.branch_id = s.branch_id
    LEFT JOIN users voider ON voider.id = s.voided_by
    LEFT JOIN (
        SELECT sale_id, COUNT(*) AS item_count, SUM(qty) AS total_qty
        FROM sale_items
        GROUP BY sale_id
    ) items ON items.sale_id = s.id
    WHERE ' . implode(' AND ', $where) . '
    ORDER BY COALESCE(s.voided_at, s.created_at) DESC, s.id DESC
    LIMIT 300
');
$stmt->execute($params);
$voids = $stmt->fetchAll();

$pendingCount = 0;
$voidedCount = 0;
$voidedTotal = 0.0;
foreach ($voids as $void) {
    if ($void['status'] === 'void_requested') {
        $pendingCount++;
    } elseif ($void['status'] === 'voided') {
        $voidedCount++;
        $voidedTotal += (float)$void['total_amount'];
    }
}

include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-3">
    <div>
        <h4 class="mb-0">Sale Voids</h4>
        <small class="text-muted">Pending void requests and approved void history for this branch.</small>
    </div>
    <a class="btn btn-outline-secondary" href="<?= app_url('sales/index.php') ?>">
        <i class="bi bi-arrow-left"></i> Sales History
    </a>
</div>

<div class="row g-3 mb-3">
    <div class="col-md-4">
        <div class="card stat-card">
            <div class="card-body">
                <div class="text-muted small">Pending Requests</div>
                <div class="h4 mb-0"><?= $pendingCount ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card stat-card">
            <div class="card-body">
                <div class="text-muted small">Approved Voids</div>
                <div class="h4 mb-0"><?= $voidedCount ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card stat-card">
            <div class="card-body">
                <div class="text-muted small">Voided Total</div>
                <div class="h4 mb-0">&#8369;<?= number_format($voidedTotal, 2) ?></div>
            </div>
        </div>
    </div>
</div>

<div class="table-card mb-3">
    <form class="row g-2 align-items-end" method="get" action="<?= app_url('sales/voids.php') ?>">
        <div class="col-md-3">
            <label class="form-label">From</label>
            <input class="form-control" type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>">
        </div>
        <div class="col-md-3">
            <label class="form-label">To</label>
            <input class="form-control" type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>">
        </div>
        <div class="col-md-3">
            <label class="form-label">Status</label>
            <select class="form-select" name="status">
                <option value="">All void statuses</option>
                <option value="void_requested" <?= $statusFilter === 'void_requested' ? 'selected' : '' ?>>Pending request</option>
                <option value="voided" <?= $statusFilter === 'voided' ? 'selected' : '' ?>>Voided</option>
            </select>
        </div>
        <div class="col-md-3 d-grid">
            <button class="btn btn-outline-primary">
                <i class="bi bi-funnel"></i> Filter
            </button>
        </div>
    </form>
</div>

<div class="table-card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Invoice</th>
                    <th>Status</th>
                    <th>Cashier</th>
                    <th>Customer</th>
                    <th class="text-end">Items</th>
                    <th class="text-end">Qty</th>
                    <th class="text-end">Amount</th>
                    <th>Reason</th>
                    <th>Voided By</th>
                    <th>Date</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($voids as $void): ?>
                    <tr>
                        <td><code><?= htmlspecialchars($void['invoice_no']) ?></code></td>
                        <td>
                            <span class="badge text-bg-<?= void_status_badge($void['status']) ?>">
                                <?= $void['status'] === 'void_requested' ? 'void requested' : htmlspecialchars($void['status']) ?>
                            </span>
                        </td>
                        <td><?= htmlspecialchars($void['cashier_name'] ?? 'N/A') ?></td>
                        <td><?= htmlspecialchars($void['customer_name'] ?? 'Walk-in Customer') ?></td>
                        <td class="text-end"><?= (int)$void['item_count'] ?></td>
                        <td class="text-end"><?= (int)$void['total_qty'] ?></td>
                        <td class="text-end">&#8369;<?= number_format((float)$void['total_amount'], 2) ?></td>
                        <td><?= htmlspecialchars($void['void_reason'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($void['voided_by_name'] ?? '-') ?></td>
                        <td><?= htmlspecialchars(date('M d, Y h:i A', strtotime($void['voided_at'] ?: $void['created_at']))) ?></td>
                        <td class="text-end">
                            <div class="btn-group btn-group-sm">
                                <a class="btn btn-outline-primary" href="<?= app_url('sales/void.php?id=' . (int)$void['id']) ?>">
                                    View
                                </a>
                                <?php if ($canApproveVoid && $void['status'] === 'void_requested'): ?>
                                    <a class="btn btn-outline-danger" href="<?= app_url('sales/void.php?id=' . (int)$void['id'] . '&action=approve') ?>">
                                        Approve
                                    </a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>

                <?php if (!$voids): ?>
                    <tr>
                        <td colspan="11" class="text-center text-muted py-4">No void requests or approved voids found.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
