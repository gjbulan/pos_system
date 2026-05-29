<?php
$pageTitle = 'Void Sale';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../auth/session.php';

require_login();
require_permission($pdo, 'sales.view');

$branchId = current_branch_id();
$userId = (int)($_SESSION['user_id'] ?? 0);
$saleId = (int)($_GET['id'] ?? $_POST['sale_id'] ?? 0);
$canRequestVoid = can($pdo, 'sales.void.request');
$canApproveVoid = can($pdo, 'sales.void.approve');
$errors = [];
$reason = trim($_POST['void_reason'] ?? '');

function sale_void_money(float $amount): string
{
    return '&#8369;' . number_format($amount, 2);
}

function fetch_sale_for_void(PDO $pdo, int $branchId, int $saleId): ?array
{
    $stmt = $pdo->prepare('
        SELECT
            s.*,
            u.name AS cashier_name,
            c.name AS customer_name,
            voider.name AS voided_by_name,
            COALESCE(returned.return_count, 0) AS return_count,
            COALESCE(returned.refund_amount, 0) AS refund_amount
        FROM sales s
        LEFT JOIN users u ON u.id = s.user_id
        LEFT JOIN customers c ON c.id = s.customer_id AND c.branch_id = s.branch_id
        LEFT JOIN users voider ON voider.id = s.voided_by
        LEFT JOIN (
            SELECT sale_id, COUNT(*) AS return_count, COALESCE(SUM(refund_amount), 0) AS refund_amount
            FROM sales_returns
            WHERE branch_id = ?
            GROUP BY sale_id
        ) returned ON returned.sale_id = s.id
        WHERE s.id = ? AND s.branch_id = ?
        LIMIT 1
    ');
    $stmt->execute([$branchId, $saleId, $branchId]);
    $sale = $stmt->fetch();

    return $sale ?: null;
}

function fetch_sale_items_for_void(PDO $pdo, int $branchId, int $saleId): array
{
    $stmt = $pdo->prepare('
        SELECT
            si.*,
            p.name,
            p.sku,
            p.barcode,
            p.stock_qty
        FROM sale_items si
        JOIN products p ON p.id = si.product_id
        WHERE si.sale_id = ? AND p.branch_id = ?
        ORDER BY si.id
    ');
    $stmt->execute([$saleId, $branchId]);

    return $stmt->fetchAll();
}

function sale_has_finalized_zread(PDO $pdo, array $sale): bool
{
    $stmt = $pdo->prepare('
        SELECT id
        FROM daily_closings
        WHERE branch_id = ?
          AND user_id = ?
          AND opened_at IS NOT NULL
          AND closed_at IS NOT NULL
          AND ? BETWEEN opened_at AND closed_at
        LIMIT 1
    ');
    $stmt->execute([(int)$sale['branch_id'], (int)$sale['user_id'], $sale['created_at']]);

    return (bool)$stmt->fetchColumn();
}

function sale_return_count(PDO $pdo, int $branchId, int $saleId): int
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM sales_returns WHERE branch_id = ? AND sale_id = ?');
    $stmt->execute([$branchId, $saleId]);

    return (int)$stmt->fetchColumn();
}

$sale = fetch_sale_for_void($pdo, $branchId, $saleId);

if (!$sale) {
    http_response_code(404);
    include __DIR__ . '/../includes/header.php';
    echo '<div class="alert alert-danger">Sale was not found for this branch.</div>';
    echo '<a class="btn btn-outline-secondary" href="' . app_url('sales/index.php') . '"><i class="bi bi-arrow-left"></i> Back to Sales</a>';
    include __DIR__ . '/../includes/footer.php';
    exit;
}

$items = fetch_sale_items_for_void($pdo, $branchId, $saleId);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim($_POST['action'] ?? 'request');

    if ($action === 'request' && !$canRequestVoid) {
        $errors[] = 'You do not have permission to request sale voids.';
    }

    if ($action === 'approve' && !$canApproveVoid) {
        $errors[] = 'You do not have permission to approve sale voids.';
    }

    if (!in_array($action, ['request', 'approve'], true)) {
        $errors[] = 'Invalid void action.';
    }

    if ($reason === '') {
        $errors[] = 'Void reason is required.';
    } elseif (strlen($reason) > 255) {
        $errors[] = 'Void reason must not exceed 255 characters.';
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();

            $saleLockStmt = $pdo->prepare('
                SELECT *
                FROM sales
                WHERE id = ? AND branch_id = ?
                FOR UPDATE
            ');
            $saleLockStmt->execute([$saleId, $branchId]);
            $lockedSale = $saleLockStmt->fetch();

            if (!$lockedSale) {
                throw new RuntimeException('Sale was not found for this branch.');
            }

            if ($lockedSale['status'] === 'voided') {
                throw new RuntimeException('This sale is already voided.');
            }

            if (sale_return_count($pdo, $branchId, $saleId) > 0) {
                throw new RuntimeException('Sales with returns/refunds cannot be voided. Use the existing return/refund records for audit.');
            }

            if (sale_has_finalized_zread($pdo, $lockedSale)) {
                throw new RuntimeException('This sale belongs to a finalized Z-read and cannot be voided.');
            }

            if ($action === 'request') {
                if ($lockedSale['status'] === 'void_requested') {
                    throw new RuntimeException('A void has already been requested for this sale.');
                }

                if ($lockedSale['status'] !== 'completed') {
                    throw new RuntimeException('Only completed sales can be requested for void.');
                }

                $requestStmt = $pdo->prepare('
                    UPDATE sales
                    SET status = "void_requested", void_reason = ?, voided_by = NULL, voided_at = NULL
                    WHERE id = ? AND branch_id = ? AND status = "completed"
                ');
                $requestStmt->execute([$reason, $saleId, $branchId]);

                log_activity($pdo, 'request_sale_void', 'sales', 'Requested void for invoice ' . $lockedSale['invoice_no'] . '. Reason: ' . $reason);
                $pdo->commit();

                $_SESSION['flash'] = ['type' => 'success', 'message' => 'Void request submitted for manager approval.'];
                redirect_to('sales/index.php?void_requested=1');
            }

            if (!in_array($lockedSale['status'], ['completed', 'void_requested'], true)) {
                throw new RuntimeException('Only completed or pending-void sales can be voided.');
            }

            $cashTxn = null;
            $cashTxnStmt = $pdo->prepare('
                SELECT cdt.*, cs.status AS cash_session_status
                FROM cash_drawer_transactions cdt
                JOIN cash_sessions cs ON cs.id = cdt.cash_session_id
                WHERE cdt.branch_id = ?
                  AND cdt.reference = ?
                  AND cdt.type = "sale_cash"
                ORDER BY cdt.id ASC
                LIMIT 1
                FOR UPDATE
            ');
            $cashTxnStmt->execute([$branchId, $lockedSale['invoice_no']]);
            $cashTxn = $cashTxnStmt->fetch() ?: null;

            if ($cashTxn && $cashTxn['cash_session_status'] !== 'open') {
                throw new RuntimeException('The cash drawer session for this cash sale is already closed.');
            }

            $lockedItemsStmt = $pdo->prepare('
                SELECT
                    si.product_id,
                    si.qty,
                    p.name
                FROM sale_items si
                JOIN products p ON p.id = si.product_id
                WHERE si.sale_id = ? AND p.branch_id = ?
                ORDER BY si.id
                FOR UPDATE
            ');
            $lockedItemsStmt->execute([$saleId, $branchId]);
            $lockedItems = $lockedItemsStmt->fetchAll();

            if (!$lockedItems) {
                throw new RuntimeException('Sale has no items to restore.');
            }

            $stockStmt = $pdo->prepare('UPDATE products SET stock_qty = stock_qty + ? WHERE branch_id = ? AND id = ?');
            $movementStmt = $pdo->prepare('
                INSERT INTO inventory_movements(branch_id, product_id, type, qty, remarks, user_id)
                VALUES(?, ?, "sale_void", ?, ?, ?)
            ');

            foreach ($lockedItems as $item) {
                $qty = (int)$item['qty'];
                if ($qty <= 0) {
                    throw new RuntimeException('Sale contains an invalid item quantity.');
                }

                $stockStmt->execute([$qty, $branchId, (int)$item['product_id']]);
                if ($stockStmt->rowCount() !== 1) {
                    throw new RuntimeException('Unable to restore stock for ' . $item['name'] . '.');
                }

                $movementStmt->execute([
                    $branchId,
                    (int)$item['product_id'],
                    $qty,
                    substr('Void restored stock from invoice ' . $lockedSale['invoice_no'] . '. Reason: ' . $reason, 0, 255),
                    $userId ?: null,
                ]);
            }

            if ($cashTxn) {
                $drawerStmt = $pdo->prepare('
                    INSERT INTO cash_drawer_transactions(cash_session_id, branch_id, user_id, type, amount, reference, remarks)
                    VALUES(?, ?, ?, "refund", ?, ?, ?)
                ');
                $drawerStmt->execute([
                    (int)$cashTxn['cash_session_id'],
                    $branchId,
                    $userId,
                    (float)$lockedSale['total_amount'],
                    $lockedSale['invoice_no'],
                    substr('Cash sale void. Reason: ' . $reason, 0, 255),
                ]);
            }

            $voidStmt = $pdo->prepare('
                UPDATE sales
                SET status = "voided", void_reason = ?, voided_by = ?, voided_at = NOW()
                WHERE id = ? AND branch_id = ? AND status IN ("completed", "void_requested")
            ');
            $voidStmt->execute([$reason, $userId ?: null, $saleId, $branchId]);

            if ($voidStmt->rowCount() !== 1) {
                throw new RuntimeException('Sale could not be marked as voided.');
            }

            log_activity($pdo, 'approve_sale_void', 'sales', 'Voided invoice ' . $lockedSale['invoice_no'] . ' amount: ' . number_format((float)$lockedSale['total_amount'], 2) . '. Reason: ' . $reason);
            $pdo->commit();

            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Sale void approved. Stock has been restored.'];
            redirect_to('sales/index.php?voided=1');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = 'Void could not be processed. ' . $e->getMessage();
        }
    }

    $sale = fetch_sale_for_void($pdo, $branchId, $saleId);
    $items = fetch_sale_items_for_void($pdo, $branchId, $saleId);
}

$hasReturns = (int)$sale['return_count'] > 0;
$defaultAction = $canApproveVoid && in_array($sale['status'], ['completed', 'void_requested'], true)
    ? 'approve'
    : 'request';
$formAction = trim($_GET['action'] ?? $defaultAction);
if (!in_array($formAction, ['request', 'approve'], true)) {
    $formAction = $defaultAction;
}

include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-3">
    <div>
        <h4 class="mb-0">Void Sale</h4>
        <small class="text-muted">Invoice <?= htmlspecialchars($sale['invoice_no']) ?></small>
    </div>
    <div class="btn-group">
        <a class="btn btn-outline-secondary" href="<?= app_url('sales/index.php') ?>">
            <i class="bi bi-arrow-left"></i> Sales
        </a>
        <a class="btn btn-outline-primary" href="<?= app_url('sales/voids.php') ?>">
            Void History
        </a>
        <a class="btn btn-outline-success" href="<?= app_url('sales/receipt.php?id=' . (int)$sale['id']) ?>">
            Receipt
        </a>
    </div>
</div>

<?php if ($errors): ?>
    <div class="alert alert-danger">
        <strong>Void was not processed.</strong>
        <ul class="mb-0">
            <?php foreach ($errors as $error): ?>
                <li><?= htmlspecialchars($error) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?php if ($sale['status'] === 'voided'): ?>
    <div class="alert alert-secondary">
        This sale was voided by <?= htmlspecialchars($sale['voided_by_name'] ?? 'Unknown') ?>
        on <?= htmlspecialchars($sale['voided_at'] ?? '-') ?>.
        Reason: <?= htmlspecialchars($sale['void_reason'] ?? '-') ?>
    </div>
<?php elseif ($sale['status'] === 'void_requested'): ?>
    <div class="alert alert-warning">
        Void requested. Reason: <?= htmlspecialchars($sale['void_reason'] ?? '-') ?>
    </div>
<?php endif; ?>

<?php if ($hasReturns): ?>
    <div class="alert alert-warning">
        This sale has return/refund records and cannot be voided.
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

                <dt class="col-sm-5">Customer</dt>
                <dd class="col-sm-7"><?= htmlspecialchars($sale['customer_name'] ?? 'Walk-in Customer') ?></dd>

                <dt class="col-sm-5">Payment</dt>
                <dd class="col-sm-7"><?= htmlspecialchars($sale['payment_method']) ?></dd>

                <dt class="col-sm-5">Total</dt>
                <dd class="col-sm-7"><?= sale_void_money((float)$sale['total_amount']) ?></dd>

                <dt class="col-sm-5">Status</dt>
                <dd class="col-sm-7"><?= htmlspecialchars($sale['status']) ?></dd>

                <dt class="col-sm-5">Date</dt>
                <dd class="col-sm-7"><?= htmlspecialchars(date('M d, Y h:i A', strtotime($sale['created_at']))) ?></dd>
            </dl>
        </div>
    </div>
    <div class="col-lg-8">
        <div class="table-card mb-3">
            <h5 class="mb-3">Items to Restore on Approved Void</h5>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th class="text-end">Qty</th>
                            <th class="text-end">Price</th>
                            <th class="text-end">Subtotal</th>
                            <th class="text-end">Current Stock</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $item): ?>
                            <tr>
                                <td>
                                    <div class="fw-semibold"><?= htmlspecialchars($item['name']) ?></div>
                                    <small class="text-muted"><?= htmlspecialchars($item['sku'] ?: ($item['barcode'] ?: 'No SKU/barcode')) ?></small>
                                </td>
                                <td class="text-end"><?= (int)$item['qty'] ?></td>
                                <td class="text-end"><?= sale_void_money((float)$item['price']) ?></td>
                                <td class="text-end"><?= sale_void_money((float)$item['subtotal']) ?></td>
                                <td class="text-end"><?= (int)$item['stock_qty'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php if (!$hasReturns && $sale['status'] !== 'voided'): ?>
            <?php if (($formAction === 'approve' && $canApproveVoid) || ($formAction === 'request' && $canRequestVoid && $sale['status'] === 'completed')): ?>
                <form method="post" action="<?= app_url('sales/void.php?id=' . (int)$sale['id']) ?>" class="table-card">
                    <input type="hidden" name="sale_id" value="<?= (int)$sale['id'] ?>">
                    <input type="hidden" name="action" value="<?= htmlspecialchars($formAction) ?>">

                    <h5><?= $formAction === 'approve' ? 'Approve Void' : 'Request Void' ?></h5>
                    <p class="text-muted">
                        <?= $formAction === 'approve'
                            ? 'Approving a void restores all sold stock and marks this sale as voided.'
                            : 'Request a manager/admin approval for this void. Stock is not restored until approval.' ?>
                    </p>

                    <label class="form-label">Void Reason</label>
                    <input
                        class="form-control"
                        name="void_reason"
                        maxlength="255"
                        value="<?= htmlspecialchars($reason !== '' ? $reason : (string)($sale['void_reason'] ?? '')) ?>"
                        required
                    >

                    <div class="d-flex justify-content-end gap-2 mt-3">
                        <?php if ($canApproveVoid && $formAction !== 'approve'): ?>
                            <a class="btn btn-outline-danger" href="<?= app_url('sales/void.php?id=' . (int)$sale['id'] . '&action=approve') ?>">
                                Approve Instead
                            </a>
                        <?php endif; ?>
                        <button class="btn <?= $formAction === 'approve' ? 'btn-danger' : 'btn-warning' ?>" onclick="return confirm('<?= $formAction === 'approve' ? 'Approve this sale void and restore stock?' : 'Submit this void request?' ?>');">
                            <i class="bi bi-x-circle"></i>
                            <?= $formAction === 'approve' ? 'Approve Void' : 'Request Void' ?>
                        </button>
                    </div>
                </form>
            <?php elseif ($sale['status'] === 'void_requested' && !$canApproveVoid): ?>
                <div class="alert alert-info">This void request is waiting for manager/admin approval.</div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
