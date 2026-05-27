<?php
$pageTitle = 'Customer History';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../auth/session.php';

require_login();
require_permission($pdo, 'customers.view');

$branchId = current_branch_id();
$id = (int)($_GET['id'] ?? 0);
$canManageCustomers = can($pdo, 'customers.manage');

if ($id <= 0) {
    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Customer was not found.'];
    redirect_to('customers/index.php');
}

$customerStmt = $pdo->prepare('
    SELECT id, name, phone, email, created_at
    FROM customers
    WHERE id = ? AND branch_id = ?
');
$customerStmt->execute([$id, $branchId]);
$customer = $customerStmt->fetch();

if (!$customer) {
    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Customer was not found in this branch.'];
    redirect_to('customers/index.php');
}

$salesStmt = $pdo->prepare('
    SELECT
        s.id,
        s.invoice_no,
        s.total_amount,
        s.payment_method,
        s.status,
        s.created_at,
        u.name AS cashier,
        COALESCE(returned.refund_amount, 0) AS refund_amount
    FROM sales s
    LEFT JOIN users u ON u.id = s.user_id
    LEFT JOIN (
        SELECT sale_id, SUM(refund_amount) AS refund_amount
        FROM sales_returns
        WHERE branch_id = ?
        GROUP BY sale_id
    ) returned ON returned.sale_id = s.id
    WHERE s.branch_id = ? AND s.customer_id = ?
    ORDER BY s.created_at DESC, s.id DESC
');
$salesStmt->execute([$branchId, $branchId, $id]);
$sales = $salesStmt->fetchAll();

$grossSales = 0.0;
$refunds = 0.0;
foreach ($sales as $sale) {
    $grossSales += (float)$sale['total_amount'];
    $refunds += (float)$sale['refund_amount'];
}
$netSales = max(0, $grossSales - $refunds);

include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-3">
    <div>
        <h4 class="mb-0"><?= htmlspecialchars($customer['name']) ?></h4>
        <small class="text-muted">Customer purchase history for this branch.</small>
    </div>
    <div class="btn-group">
        <a class="btn btn-outline-secondary" href="<?= app_url('customers/index.php') ?>">
            <i class="bi bi-arrow-left me-1"></i>
            Customers
        </a>
        <?php if ($canManageCustomers): ?>
            <a class="btn btn-primary" href="<?= app_url('customers/edit.php?id=' . (int)$customer['id']) ?>">
                <i class="bi bi-pencil-square me-1"></i>
                Edit
            </a>
        <?php endif; ?>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-lg-4">
        <div class="table-card h-100">
            <h5 class="mb-3">Customer Details</h5>
            <dl class="row mb-0">
                <dt class="col-sm-4">Phone</dt>
                <dd class="col-sm-8"><?= htmlspecialchars($customer['phone'] ?: '-') ?></dd>
                <dt class="col-sm-4">Email</dt>
                <dd class="col-sm-8"><?= htmlspecialchars($customer['email'] ?: '-') ?></dd>
                <dt class="col-sm-4">Created</dt>
                <dd class="col-sm-8"><?= htmlspecialchars(date('M d, Y', strtotime($customer['created_at']))) ?></dd>
            </dl>
        </div>
    </div>
    <div class="col-lg-8">
        <div class="row g-3">
            <div class="col-md-4">
                <div class="table-card h-100">
                    <small class="text-muted">Sales Count</small>
                    <h4 class="mb-0"><?= count($sales) ?></h4>
                </div>
            </div>
            <div class="col-md-4">
                <div class="table-card h-100">
                    <small class="text-muted">Gross Sales</small>
                    <h4 class="mb-0">&#8369;<?= number_format($grossSales, 2) ?></h4>
                </div>
            </div>
            <div class="col-md-4">
                <div class="table-card h-100">
                    <small class="text-muted">Net After Refunds</small>
                    <h4 class="mb-0">&#8369;<?= number_format($netSales, 2) ?></h4>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="table-card">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="mb-0">Purchase History</h5>
        <span class="badge text-bg-light">&#8369;<?= number_format($refunds, 2) ?> refunded</span>
    </div>

    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Invoice</th>
                    <th>Cashier</th>
                    <th>Payment</th>
                    <th>Status</th>
                    <th>Date</th>
                    <th class="text-end">Total</th>
                    <th class="text-end">Refunded</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($sales as $sale): ?>
                    <tr>
                        <td><code><?= htmlspecialchars($sale['invoice_no']) ?></code></td>
                        <td><?= htmlspecialchars($sale['cashier'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($sale['payment_method']) ?></td>
                        <td>
                            <span class="badge text-bg-<?= $sale['status'] === 'voided' ? 'secondary' : 'success' ?>">
                                <?= htmlspecialchars($sale['status']) ?>
                            </span>
                        </td>
                        <td><?= htmlspecialchars(date('M d, Y h:i A', strtotime($sale['created_at']))) ?></td>
                        <td class="text-end">&#8369;<?= number_format((float)$sale['total_amount'], 2) ?></td>
                        <td class="text-end">&#8369;<?= number_format((float)$sale['refund_amount'], 2) ?></td>
                        <td class="text-end">
                            <div class="btn-group btn-group-sm" role="group" aria-label="Sale actions">
                                <a class="btn btn-outline-primary" href="<?= app_url('sales/receipt.php?id=' . (int)$sale['id']) ?>">Receipt</a>
                                <a class="btn btn-outline-success" href="<?= app_url('sales/receipt.php?id=' . (int)$sale['id'] . '&print=1') ?>">Print</a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>

                <?php if (!$sales): ?>
                    <tr>
                        <td colspan="8" class="text-center text-muted py-4">
                            No purchases found for this customer.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
