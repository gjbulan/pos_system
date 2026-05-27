<?php
$pageTitle = 'Sales History';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../auth/session.php';

require_login();
require_permission($pdo, 'sales.view');

$branchId = current_branch_id();

$stmt = $pdo->prepare('
    SELECT
        s.*,
        u.name AS cashier,
        COALESCE(sold.sold_qty, 0) AS sold_qty,
        COALESCE(returned.returned_qty, 0) AS returned_qty,
        COALESCE(returned.refund_amount, 0) AS refund_amount
    FROM sales s
    LEFT JOIN users u ON u.id = s.user_id
    LEFT JOIN (
        SELECT sale_id, SUM(qty) AS sold_qty
        FROM sale_items
        GROUP BY sale_id
    ) sold ON sold.sale_id = s.id
    LEFT JOIN (
        SELECT sr.sale_id, SUM(sri.qty) AS returned_qty, SUM(sri.subtotal) AS refund_amount
        FROM sales_returns sr
        JOIN sales_return_items sri ON sri.return_id = sr.id
        WHERE sr.branch_id = ?
        GROUP BY sr.sale_id
    ) returned ON returned.sale_id = s.id
    WHERE s.branch_id = ?
    ORDER BY s.id DESC
');
$stmt->execute([$branchId, $branchId]);
$sales = $stmt->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<?php if (isset($_GET['returned'])): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        Return/refund saved successfully.
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="table-card">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-3">
        <div>
            <h5 class="mb-0">Sales History</h5>
            <small class="text-muted">Completed sales, receipts, returns, and refunds for this branch.</small>
        </div>
        <a class="btn btn-outline-primary btn-sm" href="<?= app_url('sales/returns.php') ?>">
            <i class="bi bi-arrow-counterclockwise"></i> Return History
        </a>
    </div>

    <table class="table align-middle">
        <thead>
            <tr>
                <th>Invoice</th>
                <th>Cashier</th>
                <th class="text-end">Total</th>
                <th class="text-end">Refunded</th>
                <th>Payment</th>
                <th>Status</th>
                <th>Date</th>
                <th class="text-end">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($sales as $sale): ?>
                <?php
                $soldQty = (int)$sale['sold_qty'];
                $returnedQty = (int)$sale['returned_qty'];
                $isFullyReturned = $soldQty > 0 && $returnedQty >= $soldQty;
                $hasReturns = $returnedQty > 0;
                $canReturn = $sale['status'] !== 'voided' && !$isFullyReturned;
                ?>
                <tr>
                    <td><code><?= htmlspecialchars($sale['invoice_no']) ?></code></td>
                    <td><?= htmlspecialchars($sale['cashier'] ?? '') ?></td>
                    <td class="text-end">&#8369;<?= number_format((float)$sale['total_amount'], 2) ?></td>
                    <td class="text-end">&#8369;<?= number_format((float)$sale['refund_amount'], 2) ?></td>
                    <td><?= htmlspecialchars($sale['payment_method']) ?></td>
                    <td>
                        <?php if ($sale['status'] === 'voided'): ?>
                            <span class="badge text-bg-secondary">voided</span>
                        <?php elseif ($isFullyReturned): ?>
                            <span class="badge text-bg-warning">returned</span>
                        <?php elseif ($hasReturns): ?>
                            <span class="badge text-bg-info">partial return</span>
                        <?php else: ?>
                            <span class="badge text-bg-success"><?= htmlspecialchars($sale['status']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars(date('M d, Y h:i A', strtotime($sale['created_at']))) ?></td>
                    <td class="text-end">
                        <div class="btn-group btn-group-sm" role="group" aria-label="Sale actions">
                            <a class="btn btn-outline-primary" href="<?= app_url('sales/receipt.php?id=' . (int)$sale['id']) ?>">Receipt</a>
                            <a class="btn btn-outline-success" href="<?= app_url('sales/receipt.php?id=' . (int)$sale['id'] . '&print=1') ?>">Print</a>
                            <?php if ($canReturn): ?>
                                <a class="btn btn-outline-warning" href="<?= app_url('sales/return.php?id=' . (int)$sale['id']) ?>">Return</a>
                            <?php else: ?>
                                <button class="btn btn-outline-secondary" type="button" disabled>Return</button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>

            <?php if (!$sales): ?>
                <tr>
                    <td colspan="8" class="text-center text-muted py-4">No sales found for this branch.</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
