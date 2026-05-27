<?php
$pageTitle = 'Return / Refund';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../auth/session.php';

require_login();
require_permission($pdo, 'sales.view');

$branchId = current_branch_id();
$userId = (int)($_SESSION['user_id'] ?? 0);
$saleId = (int)($_GET['id'] ?? $_POST['sale_id'] ?? 0);
$errors = [];
$reason = trim($_POST['reason'] ?? '');

function get_sale_for_return(PDO $pdo, int $branchId, int $saleId): ?array
{
    $stmt = $pdo->prepare('
        SELECT s.*, u.name AS cashier_name
        FROM sales s
        LEFT JOIN users u ON u.id = s.user_id
        WHERE s.branch_id = ? AND s.id = ?
        LIMIT 1
    ');
    $stmt->execute([$branchId, $saleId]);
    $sale = $stmt->fetch();

    return $sale ?: null;
}

function get_sale_items_for_return(PDO $pdo, int $branchId, int $saleId): array
{
    $stmt = $pdo->prepare('
        SELECT
            si.id AS sale_item_id,
            si.product_id,
            si.qty AS sold_qty,
            si.price,
            si.subtotal,
            p.name,
            p.barcode,
            p.sku,
            p.stock_qty,
            COALESCE(returned.returned_qty, 0) AS returned_qty
        FROM sale_items si
        JOIN products p ON p.id = si.product_id
        LEFT JOIN (
            SELECT sri.sale_item_id, SUM(sri.qty) AS returned_qty
            FROM sales_return_items sri
            JOIN sales_returns sr ON sr.id = sri.return_id
            WHERE sr.branch_id = ? AND sr.sale_id = ?
            GROUP BY sri.sale_item_id
        ) returned ON returned.sale_item_id = si.id
        WHERE si.sale_id = ?
        ORDER BY si.id ASC
    ');
    $stmt->execute([$branchId, $saleId, $saleId]);

    return $stmt->fetchAll();
}

function get_locked_sale_items_for_return(PDO $pdo, int $branchId, int $saleId): array
{
    $stmt = $pdo->prepare('
        SELECT
            si.id AS sale_item_id,
            si.product_id,
            si.qty AS sold_qty,
            si.price,
            p.name,
            p.stock_qty,
            COALESCE(returned.returned_qty, 0) AS returned_qty
        FROM sale_items si
        JOIN products p ON p.id = si.product_id
        LEFT JOIN (
            SELECT sri.sale_item_id, SUM(sri.qty) AS returned_qty
            FROM sales_return_items sri
            JOIN sales_returns sr ON sr.id = sri.return_id
            WHERE sr.branch_id = ? AND sr.sale_id = ?
            GROUP BY sri.sale_item_id
        ) returned ON returned.sale_item_id = si.id
        WHERE si.sale_id = ?
        ORDER BY si.id ASC
        FOR UPDATE
    ');
    $stmt->execute([$branchId, $saleId, $saleId]);
    $items = [];

    foreach ($stmt->fetchAll() as $item) {
        $items[(int)$item['sale_item_id']] = $item;
    }

    return $items;
}

$sale = get_sale_for_return($pdo, $branchId, $saleId);

if (!$sale) {
    include __DIR__ . '/../includes/header.php';
    ?>
    <div class="alert alert-danger">Sale was not found for this branch.</div>
    <a class="btn btn-outline-secondary" href="<?= app_url('sales/index.php') ?>">
        <i class="bi bi-arrow-left"></i> Back to Sales
    </a>
    <?php
    include __DIR__ . '/../includes/footer.php';
    exit;
}

$items = get_sale_items_for_return($pdo, $branchId, $saleId);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($sale['status'] === 'voided') {
        $errors[] = 'Voided sales cannot be returned.';
    }

    if ($reason === '') {
        $errors[] = 'Return reason is required.';
    } elseif (strlen($reason) > 255) {
        $errors[] = 'Return reason must not exceed 255 characters.';
    }

    $requestedReturns = [];
    foreach ($_POST['return_qty'] ?? [] as $saleItemId => $qtyValue) {
        $qtyValue = trim((string)$qtyValue);
        if ($qtyValue === '' || $qtyValue === '0') {
            continue;
        }

        if (!ctype_digit($qtyValue) || (int)$qtyValue <= 0) {
            $errors[] = 'Return quantities must be whole numbers greater than zero.';
            continue;
        }

        $requestedReturns[(int)$saleItemId] = (int)$qtyValue;
    }

    if (!$requestedReturns) {
        $errors[] = 'Enter a return quantity for at least one item.';
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();

            $saleLockStmt = $pdo->prepare('
                SELECT *
                FROM sales
                WHERE branch_id = ? AND id = ?
                FOR UPDATE
            ');
            $saleLockStmt->execute([$branchId, $saleId]);
            $lockedSale = $saleLockStmt->fetch();

            if (!$lockedSale) {
                throw new RuntimeException('Sale was not found for this branch.');
            }

            if ($lockedSale['status'] === 'voided') {
                throw new RuntimeException('Voided sales cannot be returned.');
            }

            $lockedItems = get_locked_sale_items_for_return($pdo, $branchId, $saleId);
            $previousReturnsStmt = $pdo->prepare('
                SELECT id, refund_amount
                FROM sales_returns
                WHERE branch_id = ? AND sale_id = ?
                FOR UPDATE
            ');
            $previousReturnsStmt->execute([$branchId, $saleId]);
            $alreadyRefunded = 0.0;
            foreach ($previousReturnsStmt->fetchAll() as $previousReturn) {
                $alreadyRefunded += (float)$previousReturn['refund_amount'];
            }

            $grossSaleSubtotal = 0.0;
            foreach ($lockedItems as $lockedItem) {
                $grossSaleSubtotal += (float)$lockedItem['price'] * (int)$lockedItem['sold_qty'];
            }

            $saleTotal = max(0, (float)$lockedSale['total_amount']);
            $discountRatio = $grossSaleSubtotal > 0 ? min(1, max(0, $saleTotal / $grossSaleSubtotal)) : 1;
            $remainingRefundable = round(max(0, $saleTotal - $alreadyRefunded), 2);

            if ($remainingRefundable <= 0) {
                throw new RuntimeException('This sale has no remaining refundable balance.');
            }

            $validReturns = [];
            $refundAmount = 0.0;

            foreach ($requestedReturns as $saleItemId => $returnQty) {
                if (!isset($lockedItems[$saleItemId])) {
                    throw new RuntimeException('One or more return items are invalid.');
                }

                $item = $lockedItems[$saleItemId];
                $soldQty = (int)$item['sold_qty'];
                $returnedQty = (int)$item['returned_qty'];
                $availableQty = $soldQty - $returnedQty;

                if ($returnQty > $availableQty) {
                    throw new RuntimeException('Return quantity exceeds available quantity for ' . $item['name'] . '.');
                }

                $netUnitPrice = round((float)$item['price'] * $discountRatio, 2);
                $subtotal = round($returnQty * $netUnitPrice, 2);
                $refundAmount = round($refundAmount + $subtotal, 2);
                $validReturns[] = [
                    'sale_item_id' => $saleItemId,
                    'product_id' => (int)$item['product_id'],
                    'name' => $item['name'],
                    'qty' => $returnQty,
                    'price' => $netUnitPrice,
                    'subtotal' => $subtotal,
                ];
            }

            if ($refundAmount > $remainingRefundable) {
                $overage = round($refundAmount - $remainingRefundable, 2);
                for ($i = count($validReturns) - 1; $i >= 0 && $overage > 0; $i--) {
                    $reduction = min($validReturns[$i]['subtotal'], $overage);
                    $validReturns[$i]['subtotal'] = round($validReturns[$i]['subtotal'] - $reduction, 2);
                    $validReturns[$i]['price'] = $validReturns[$i]['subtotal'] > 0
                        ? round($validReturns[$i]['subtotal'] / $validReturns[$i]['qty'], 2)
                        : 0.0;
                    $overage = round($overage - $reduction, 2);
                }
                $validReturns = array_values(array_filter($validReturns, static fn(array $item): bool => $item['subtotal'] > 0));
                $refundAmount = $remainingRefundable;
            }

            if ($refundAmount <= 0) {
                throw new RuntimeException('Calculated refund amount must be greater than zero.');
            }

            $returnStmt = $pdo->prepare('
                INSERT INTO sales_returns(branch_id, sale_id, user_id, refund_amount, reason)
                VALUES(?, ?, ?, ?, ?)
            ');
            $returnStmt->execute([$branchId, $saleId, $userId ?: null, $refundAmount, $reason]);
            $returnId = (int)$pdo->lastInsertId();

            $returnItemStmt = $pdo->prepare('
                INSERT INTO sales_return_items(return_id, sale_item_id, product_id, qty, price, subtotal)
                VALUES(?, ?, ?, ?, ?, ?)
            ');
            $stockStmt = $pdo->prepare('UPDATE products SET stock_qty = stock_qty + ? WHERE branch_id = ? AND id = ?');
            $movementStmt = $pdo->prepare('
                INSERT INTO inventory_movements(branch_id, product_id, type, qty, remarks, user_id)
                VALUES(?, ?, ?, ?, ?, ?)
            ');

            foreach ($validReturns as $returnItem) {
                $returnItemStmt->execute([
                    $returnId,
                    $returnItem['sale_item_id'],
                    $returnItem['product_id'],
                    $returnItem['qty'],
                    $returnItem['price'],
                    $returnItem['subtotal'],
                ]);

                $stockStmt->execute([$returnItem['qty'], $branchId, $returnItem['product_id']]);

                $remarks = substr(
                    'Returned from invoice ' . $lockedSale['invoice_no'] . ' return #' . $returnId . '. Reason: ' . $reason,
                    0,
                    255
                );
                $movementStmt->execute([
                    $branchId,
                    $returnItem['product_id'],
                    'return',
                    $returnItem['qty'],
                    $remarks,
                    $userId ?: null,
                ]);
            }

            log_activity($pdo, 'return_sale', 'sales', 'Processed return #' . $returnId . ' for invoice ' . $lockedSale['invoice_no'] . ' amount: ' . number_format($refundAmount, 2));

            $pdo->commit();
            redirect_to('sales/index.php?returned=1');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = 'Return could not be saved. ' . $e->getMessage();
        }
    }

    $items = get_sale_items_for_return($pdo, $branchId, $saleId);
}

include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-3">
    <div>
        <h4 class="mb-0">Return / Refund</h4>
        <small class="text-muted">Invoice <?= htmlspecialchars($sale['invoice_no']) ?></small>
    </div>
    <div class="btn-group">
        <a class="btn btn-outline-secondary" href="<?= app_url('sales/index.php') ?>">
            <i class="bi bi-arrow-left"></i> Sales
        </a>
        <a class="btn btn-outline-primary" href="<?= app_url('sales/receipt.php?id=' . (int)$sale['id']) ?>">
            Receipt
        </a>
        <a class="btn btn-outline-success" href="<?= app_url('sales/receipt.php?id=' . (int)$sale['id'] . '&print=1') ?>">
            <i class="bi bi-printer"></i> Print
        </a>
    </div>
</div>

<?php if ($sale['status'] === 'voided'): ?>
    <div class="alert alert-warning">Voided sales cannot be returned.</div>
<?php endif; ?>

<?php if ($errors): ?>
    <div class="alert alert-danger">
        <strong>Return was not saved.</strong>
        <ul class="mb-0">
            <?php foreach ($errors as $error): ?>
                <li><?= htmlspecialchars($error) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-4">
        <div class="table-card h-100">
            <h5>Sale Details</h5>
            <dl class="row mb-0">
                <dt class="col-sm-5">Invoice</dt>
                <dd class="col-sm-7"><?= htmlspecialchars($sale['invoice_no']) ?></dd>

                <dt class="col-sm-5">Cashier</dt>
                <dd class="col-sm-7"><?= htmlspecialchars($sale['cashier_name'] ?? 'N/A') ?></dd>

                <dt class="col-sm-5">Payment</dt>
                <dd class="col-sm-7"><?= htmlspecialchars($sale['payment_method']) ?></dd>

                <dt class="col-sm-5">Total</dt>
                <dd class="col-sm-7">&#8369;<?= number_format((float)$sale['total_amount'], 2) ?></dd>

                <dt class="col-sm-5">Date</dt>
                <dd class="col-sm-7"><?= htmlspecialchars(date('M d, Y h:i A', strtotime($sale['created_at']))) ?></dd>
            </dl>
        </div>
    </div>
    <div class="col-lg-8">
        <form method="post" action="<?= app_url('sales/return.php?id=' . (int)$sale['id']) ?>" class="table-card">
            <input type="hidden" name="sale_id" value="<?= (int)$sale['id'] ?>">

            <div class="mb-3">
                <label class="form-label">Return Reason</label>
                <input
                    class="form-control"
                    name="reason"
                    maxlength="255"
                    value="<?= htmlspecialchars($reason) ?>"
                    placeholder="Required reason for return/refund"
                    required
                    <?= $sale['status'] === 'voided' ? 'disabled' : '' ?>
                >
            </div>

            <table class="table align-middle">
                <thead>
                    <tr>
                        <th>Item</th>
                        <th class="text-end">Sold</th>
                        <th class="text-end">Returned</th>
                        <th class="text-end">Available</th>
                        <th class="text-end">Price</th>
                        <th style="width: 150px;">Return Qty</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $item): ?>
                        <?php
                        $soldQty = (int)$item['sold_qty'];
                        $returnedQty = (int)$item['returned_qty'];
                        $availableQty = max(0, $soldQty - $returnedQty);
                        ?>
                        <tr>
                            <td>
                                <div class="fw-semibold"><?= htmlspecialchars($item['name']) ?></div>
                                <small class="text-muted">
                                    <?= htmlspecialchars($item['sku'] ?: ($item['barcode'] ?: 'No SKU/barcode')) ?>
                                </small>
                            </td>
                            <td class="text-end"><?= $soldQty ?></td>
                            <td class="text-end"><?= $returnedQty ?></td>
                            <td class="text-end"><?= $availableQty ?></td>
                            <td class="text-end">&#8369;<?= number_format((float)$item['price'], 2) ?></td>
                            <td>
                                <input
                                    class="form-control form-control-sm"
                                    type="number"
                                    name="return_qty[<?= (int)$item['sale_item_id'] ?>]"
                                    min="0"
                                    max="<?= $availableQty ?>"
                                    value="<?= htmlspecialchars($_POST['return_qty'][$item['sale_item_id']] ?? '0') ?>"
                                    <?= $availableQty <= 0 || $sale['status'] === 'voided' ? 'disabled' : '' ?>
                                >
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <button class="btn btn-warning" <?= $sale['status'] === 'voided' ? 'disabled' : '' ?> onclick="return confirm('Process this return and restore stock?');">
                <i class="bi bi-arrow-counterclockwise"></i> Process Return
            </button>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
