<?php
$pageTitle = 'View Purchase Order';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../auth/session.php';

require_login();
require_permission($pdo, 'purchases.view');

$branchId = current_branch_id();
$canManagePurchases = can($pdo, 'purchases.manage');
$purchaseOrderId = (int)($_GET['id'] ?? 0);

$orderStmt = $pdo->prepare('
    SELECT
        po.*,
        s.name AS supplier_name,
        s.phone AS supplier_phone,
        s.email AS supplier_email,
        creator.name AS created_by_name,
        receiver.name AS received_by_name
    FROM purchase_orders po
    JOIN suppliers s ON s.id = po.supplier_id
    LEFT JOIN users creator ON creator.id = po.created_by
    LEFT JOIN users receiver ON receiver.id = po.received_by
    WHERE po.id = ? AND po.branch_id = ?
');
$orderStmt->execute([$purchaseOrderId, $branchId]);
$order = $orderStmt->fetch();

if (!$order) {
    http_response_code(404);
    include __DIR__ . '/../includes/header.php';
    echo '<div class="alert alert-danger">Purchase order not found.</div>';
    include __DIR__ . '/../includes/footer.php';
    exit;
}

$itemsStmt = $pdo->prepare('
    SELECT
        poi.*,
        p.name AS product_name,
        p.sku,
        p.barcode,
        p.stock_qty
    FROM purchase_order_items poi
    JOIN products p ON p.id = poi.product_id
    WHERE poi.purchase_order_id = ?
    ORDER BY poi.id
');
$itemsStmt->execute([$purchaseOrderId]);
$items = $itemsStmt->fetchAll();

$totalQty = 0;
$receivedQty = 0;
$totalCost = 0.0;
foreach ($items as $item) {
    $totalQty += (int)$item['qty_ordered'];
    $receivedQty += (int)$item['qty_received'];
    $totalCost += (int)$item['qty_ordered'] * (float)$item['cost'];
}
$remainingQty = max(0, $totalQty - $receivedQty);

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

function purchase_view_status_badge(string $status): string
{
    return match ($status) {
        'received' => 'success',
        'partial' => 'warning',
        default => 'secondary',
    };
}

include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-3">
    <div>
        <h4 class="mb-0">Purchase Order <?= htmlspecialchars($order['po_number']) ?></h4>
        <small class="text-muted">Supplier order details and receiving progress.</small>
    </div>
    <div class="btn-group">
        <a class="btn btn-outline-secondary" href="<?= app_url('purchases/index.php') ?>">
            <i class="bi bi-arrow-left"></i> Purchase Orders
        </a>
        <?php if ($canManagePurchases && $remainingQty > 0 && $order['status'] !== 'received'): ?>
            <a class="btn btn-success" href="<?= app_url('purchases/receive.php?id=' . (int)$order['id']) ?>">
                <i class="bi bi-box-arrow-in-down"></i> Receive Stock
            </a>
        <?php endif; ?>
    </div>
</div>

<?php if ($flash): ?>
    <div class="alert alert-<?= htmlspecialchars($flash['type']) ?> alert-dismissible fade show" role="alert">
        <?= htmlspecialchars($flash['message']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<div class="row g-3 mb-3">
    <div class="col-lg-8">
        <div class="table-card h-100">
            <div class="d-flex justify-content-between align-items-start gap-3">
                <div>
                    <h5 class="mb-1"><?= htmlspecialchars($order['supplier_name']) ?></h5>
                    <div class="text-muted">
                        <?= htmlspecialchars($order['supplier_phone'] ?: 'No phone') ?>
                        <?php if (!empty($order['supplier_email'])): ?>
                            - <?= htmlspecialchars($order['supplier_email']) ?>
                        <?php endif; ?>
                    </div>
                </div>
                <span class="badge text-bg-<?= purchase_view_status_badge($order['status']) ?>">
                    <?= htmlspecialchars(ucfirst($order['status'])) ?>
                </span>
            </div>
            <hr>
            <div class="row g-3">
                <div class="col-md-4">
                    <small class="text-muted d-block">PO Date</small>
                    <strong><?= htmlspecialchars($order['po_date']) ?></strong>
                </div>
                <div class="col-md-4">
                    <small class="text-muted d-block">Created By</small>
                    <strong><?= htmlspecialchars($order['created_by_name'] ?? 'N/A') ?></strong>
                </div>
                <div class="col-md-4">
                    <small class="text-muted d-block">Created At</small>
                    <strong><?= htmlspecialchars($order['created_at']) ?></strong>
                </div>
                <div class="col-md-4">
                    <small class="text-muted d-block">Received By</small>
                    <strong><?= htmlspecialchars($order['received_by_name'] ?? 'N/A') ?></strong>
                </div>
                <div class="col-md-4">
                    <small class="text-muted d-block">Received At</small>
                    <strong><?= htmlspecialchars($order['received_at'] ?? 'N/A') ?></strong>
                </div>
                <div class="col-md-4">
                    <small class="text-muted d-block">Notes</small>
                    <strong><?= htmlspecialchars($order['notes'] ?: '-') ?></strong>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="table-card h-100">
            <h5 class="mb-3">Summary</h5>
            <div class="d-flex justify-content-between border-bottom py-2">
                <span>Ordered Qty</span>
                <strong><?= $totalQty ?></strong>
            </div>
            <div class="d-flex justify-content-between border-bottom py-2">
                <span>Received Qty</span>
                <strong><?= $receivedQty ?></strong>
            </div>
            <div class="d-flex justify-content-between border-bottom py-2">
                <span>Remaining Qty</span>
                <strong><?= $remainingQty ?></strong>
            </div>
            <div class="d-flex justify-content-between pt-2">
                <span>Total Cost</span>
                <strong><?= number_format($totalCost, 2) ?></strong>
            </div>
        </div>
    </div>
</div>

<div class="table-card">
    <h5 class="mb-3">Items</h5>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Product</th>
                    <th>SKU / Barcode</th>
                    <th class="text-end">Ordered</th>
                    <th class="text-end">Received</th>
                    <th class="text-end">Remaining</th>
                    <th class="text-end">Cost</th>
                    <th class="text-end">Line Total</th>
                    <th class="text-end">Current Stock</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $item): ?>
                    <?php
                    $ordered = (int)$item['qty_ordered'];
                    $received = (int)$item['qty_received'];
                    $remaining = max(0, $ordered - $received);
                    ?>
                    <tr>
                        <td class="fw-semibold"><?= htmlspecialchars($item['product_name']) ?></td>
                        <td>
                            <?= htmlspecialchars($item['sku'] ?: '-') ?>
                            <?php if (!empty($item['barcode'])): ?>
                                <span class="text-muted">/ <?= htmlspecialchars($item['barcode']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end"><?= $ordered ?></td>
                        <td class="text-end"><?= $received ?></td>
                        <td class="text-end"><?= $remaining ?></td>
                        <td class="text-end"><?= number_format((float)$item['cost'], 2) ?></td>
                        <td class="text-end"><?= number_format($ordered * (float)$item['cost'], 2) ?></td>
                        <td class="text-end"><?= (int)$item['stock_qty'] ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$items): ?>
                    <tr>
                        <td colspan="8" class="text-center text-muted py-4">No items found for this purchase order.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
