<?php
$pageTitle = 'Receive Purchase Order';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../auth/session.php';

require_login();
require_permission($pdo, 'purchases.manage');

$branchId = current_branch_id();
$userId = (int)($_SESSION['user_id'] ?? 0);
$purchaseOrderId = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$errors = [];
$oldReceiveQty = [];

function load_purchase_order(PDO $pdo, int $branchId, int $purchaseOrderId): ?array
{
    $stmt = $pdo->prepare('
        SELECT po.*, s.name AS supplier_name
        FROM purchase_orders po
        JOIN suppliers s ON s.id = po.supplier_id
        WHERE po.id = ? AND po.branch_id = ?
    ');
    $stmt->execute([$purchaseOrderId, $branchId]);
    $order = $stmt->fetch();
    return $order ?: null;
}

function load_purchase_items(PDO $pdo, int $purchaseOrderId): array
{
    $stmt = $pdo->prepare('
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
    $stmt->execute([$purchaseOrderId]);
    return $stmt->fetchAll();
}

$order = load_purchase_order($pdo, $branchId, $purchaseOrderId);
if (!$order) {
    http_response_code(404);
    include __DIR__ . '/../includes/header.php';
    echo '<div class="alert alert-danger">Purchase order not found.</div>';
    include __DIR__ . '/../includes/footer.php';
    exit;
}

$items = load_purchase_items($pdo, $purchaseOrderId);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedQuantities = is_array($_POST['receive_qty'] ?? null) ? $_POST['receive_qty'] : [];
    $itemRemaining = [];

    foreach ($items as $item) {
        $itemId = (int)$item['id'];
        $itemRemaining[$itemId] = max(0, (int)$item['qty_ordered'] - (int)$item['qty_received']);
    }

    $receiveMap = [];
    foreach ($postedQuantities as $itemId => $qtyValue) {
        $itemId = (int)$itemId;
        $qtyValue = trim((string)$qtyValue);
        $oldReceiveQty[$itemId] = $qtyValue;

        if ($qtyValue === '' || $qtyValue === '0') {
            continue;
        }

        if (!isset($itemRemaining[$itemId])) {
            $errors[] = 'One of the submitted items does not belong to this purchase order.';
            continue;
        }

        if (filter_var($qtyValue, FILTER_VALIDATE_INT) === false || (int)$qtyValue <= 0) {
            $errors[] = 'Receive quantities must be whole numbers greater than zero.';
            continue;
        }

        $qty = (int)$qtyValue;
        if ($qty > $itemRemaining[$itemId]) {
            $errors[] = 'Receive quantity cannot exceed the remaining quantity.';
            continue;
        }

        $receiveMap[$itemId] = $qty;
    }

    if (!$receiveMap) {
        $errors[] = 'Enter at least one quantity to receive.';
    }

    if ($order['status'] === 'received') {
        $errors[] = 'This purchase order has already been fully received.';
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();

            $orderLockStmt = $pdo->prepare('
                SELECT po.*, s.name AS supplier_name
                FROM purchase_orders po
                JOIN suppliers s ON s.id = po.supplier_id
                WHERE po.id = ? AND po.branch_id = ?
                FOR UPDATE
            ');
            $orderLockStmt->execute([$purchaseOrderId, $branchId]);
            $lockedOrder = $orderLockStmt->fetch();

            if (!$lockedOrder) {
                throw new RuntimeException('Purchase order was not found for this branch.');
            }

            if ($lockedOrder['status'] === 'received') {
                throw new RuntimeException('This purchase order has already been fully received.');
            }

            $itemLockStmt = $pdo->prepare('
                SELECT
                    poi.*,
                    p.name AS product_name
                FROM purchase_order_items poi
                JOIN products p ON p.id = poi.product_id
                WHERE poi.purchase_order_id = ? AND p.branch_id = ?
                FOR UPDATE
            ');
            $itemLockStmt->execute([$purchaseOrderId, $branchId]);
            $lockedItems = [];
            foreach ($itemLockStmt->fetchAll() as $item) {
                $lockedItems[(int)$item['id']] = $item;
            }

            $updateProductStmt = $pdo->prepare('UPDATE products SET stock_qty = stock_qty + ? WHERE branch_id = ? AND id = ?');
            $updateItemStmt = $pdo->prepare('UPDATE purchase_order_items SET qty_received = qty_received + ? WHERE id = ?');
            $movementStmt = $pdo->prepare('
                INSERT INTO inventory_movements(branch_id, product_id, type, qty, remarks, user_id)
                VALUES(?, ?, "purchase_receive", ?, ?, ?)
            ');

            $totalReceivedNow = 0;
            foreach ($receiveMap as $itemId => $qty) {
                if (!isset($lockedItems[$itemId])) {
                    throw new RuntimeException('A submitted item could not be found on this purchase order.');
                }

                $item = $lockedItems[$itemId];
                $remaining = max(0, (int)$item['qty_ordered'] - (int)$item['qty_received']);
                if ($qty > $remaining) {
                    throw new RuntimeException('Receive quantity cannot exceed the remaining quantity for ' . $item['product_name'] . '.');
                }

                $updateProductStmt->execute([$qty, $branchId, (int)$item['product_id']]);
                $updateItemStmt->execute([$qty, $itemId]);
                $movementStmt->execute([
                    $branchId,
                    (int)$item['product_id'],
                    $qty,
                    'Received from PO ' . $lockedOrder['po_number'],
                    $userId ?: null,
                ]);

                $lockedItems[$itemId]['qty_received'] = (int)$item['qty_received'] + $qty;
                $totalReceivedNow += $qty;
            }

            if ($totalReceivedNow <= 0) {
                throw new RuntimeException('No stock was received.');
            }

            $allReceived = true;
            foreach ($lockedItems as $item) {
                if ((int)$item['qty_received'] < (int)$item['qty_ordered']) {
                    $allReceived = false;
                    break;
                }
            }

            $newStatus = $allReceived ? 'received' : 'partial';
            $updateOrderStmt = $pdo->prepare('
                UPDATE purchase_orders
                SET status = ?, received_by = ?, received_at = CASE WHEN ? = "received" THEN NOW() ELSE received_at END
                WHERE id = ? AND branch_id = ?
            ');
            $updateOrderStmt->execute([$newStatus, $userId ?: null, $newStatus, $purchaseOrderId, $branchId]);

            log_activity($pdo, 'receive_purchase_order', 'purchases', 'Received ' . $totalReceivedNow . ' item(s) from PO ' . $lockedOrder['po_number']);
            $pdo->commit();

            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Purchase order receiving saved successfully.'];
            redirect_to('purchases/view.php?id=' . $purchaseOrderId);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = 'Receiving could not be saved. ' . $e->getMessage();
        }
    }

    $order = load_purchase_order($pdo, $branchId, $purchaseOrderId) ?: $order;
    $items = load_purchase_items($pdo, $purchaseOrderId);
}

include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-3">
    <div>
        <h4 class="mb-0">Receive Purchase Order</h4>
        <small class="text-muted">PO <?= htmlspecialchars($order['po_number']) ?> from <?= htmlspecialchars($order['supplier_name']) ?></small>
    </div>
    <a class="btn btn-outline-secondary" href="<?= app_url('purchases/view.php?id=' . (int)$order['id']) ?>">
        <i class="bi bi-arrow-left"></i> Back to PO
    </a>
</div>

<?php if ($errors): ?>
    <div class="alert alert-danger">
        <strong>Receiving was not saved.</strong>
        <ul class="mb-0">
            <?php foreach ($errors as $error): ?>
                <li><?= htmlspecialchars($error) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?php if ($order['status'] === 'received'): ?>
    <div class="alert alert-info">
        This purchase order has already been fully received.
    </div>
<?php else: ?>
    <form method="post" action="<?= app_url('purchases/receive.php') ?>" class="table-card">
        <input type="hidden" name="id" value="<?= (int)$order['id'] ?>">

        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Product</th>
                        <th>SKU / Barcode</th>
                        <th class="text-end">Ordered</th>
                        <th class="text-end">Received</th>
                        <th class="text-end">Remaining</th>
                        <th style="width: 180px;">Receive Now</th>
                        <th class="text-end">Current Stock</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $item): ?>
                        <?php
                        $itemId = (int)$item['id'];
                        $remaining = max(0, (int)$item['qty_ordered'] - (int)$item['qty_received']);
                        ?>
                        <tr>
                            <td class="fw-semibold"><?= htmlspecialchars($item['product_name']) ?></td>
                            <td>
                                <?= htmlspecialchars($item['sku'] ?: '-') ?>
                                <?php if (!empty($item['barcode'])): ?>
                                    <span class="text-muted">/ <?= htmlspecialchars($item['barcode']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end"><?= (int)$item['qty_ordered'] ?></td>
                            <td class="text-end"><?= (int)$item['qty_received'] ?></td>
                            <td class="text-end"><?= $remaining ?></td>
                            <td>
                                <input
                                    type="number"
                                    class="form-control"
                                    name="receive_qty[<?= $itemId ?>]"
                                    min="0"
                                    max="<?= $remaining ?>"
                                    value="<?= htmlspecialchars($oldReceiveQty[$itemId] ?? '') ?>"
                                    <?= $remaining <= 0 ? 'disabled' : '' ?>
                                >
                            </td>
                            <td class="text-end"><?= (int)$item['stock_qty'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="d-flex justify-content-end gap-2">
            <a class="btn btn-outline-secondary" href="<?= app_url('purchases/view.php?id=' . (int)$order['id']) ?>">Cancel</a>
            <button class="btn btn-success">
                <i class="bi bi-box-arrow-in-down"></i> Save Receiving
            </button>
        </div>
    </form>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
