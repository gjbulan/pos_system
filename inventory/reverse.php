<?php
$pageTitle = 'Reverse Inventory Movement';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../auth/session.php';

require_login();
require_permission($pdo, 'inventory.manage');

$branchId = current_branch_id();
$userId = (int)($_SESSION['user_id'] ?? 0);
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$errors = [];
$reversibleTypes = ['stock_in', 'adjustment_in', 'adjustment_out'];

function has_inventory_reversal(PDO $pdo, int $branchId, int $movementId): bool
{
    $stmt = $pdo->prepare('
        SELECT id
        FROM inventory_movements
        WHERE branch_id = ?
          AND type = ?
          AND remarks LIKE ?
        LIMIT 1
    ');
    $stmt->execute([$branchId, 'reversal', 'Reversal of movement #' . $movementId . '%']);

    return (bool)$stmt->fetchColumn();
}

$movementStmt = $pdo->prepare('
    SELECT
        im.*,
        p.name AS product,
        p.sku,
        p.barcode,
        p.stock_qty,
        u.name AS user_name
    FROM inventory_movements im
    JOIN products p ON p.id = im.product_id AND p.branch_id = im.branch_id
    LEFT JOIN users u ON u.id = im.user_id
    WHERE im.branch_id = ? AND im.id = ?
    LIMIT 1
');
$movementStmt->execute([$branchId, $id]);
$movement = $movementStmt->fetch();

if (!$movement) {
    include __DIR__ . '/../includes/header.php';
    ?>
    <div class="alert alert-danger">Inventory movement was not found for this branch.</div>
    <a class="btn btn-outline-secondary" href="<?= app_url('inventory/index.php') ?>">
        <i class="bi bi-arrow-left"></i> Back to Inventory
    </a>
    <?php
    include __DIR__ . '/../includes/footer.php';
    exit;
}

$alreadyReversed = has_inventory_reversal($pdo, $branchId, (int)$movement['id']);
$reverseQty = -(int)$movement['qty'];
$projectedStock = (int)$movement['stock_qty'] + $reverseQty;
$wouldGoNegative = $projectedStock < 0;
$canReverse = in_array($movement['type'], $reversibleTypes, true) && !$alreadyReversed && !$wouldGoNegative;
$reason = trim($_POST['reason'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($alreadyReversed) {
        $errors[] = 'This movement has already been reversed.';
    } elseif (!in_array($movement['type'], $reversibleTypes, true)) {
        $errors[] = 'This movement type cannot be reversed safely from the inventory module.';
    } elseif ($wouldGoNegative) {
        $errors[] = 'Reversal would make stock negative.';
    }

    if ($reason === '') {
        $errors[] = 'Reason is required.';
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();

            $lockedMovementStmt = $pdo->prepare('
                SELECT im.*, p.stock_qty
                FROM inventory_movements im
                JOIN products p ON p.id = im.product_id AND p.branch_id = im.branch_id
                WHERE im.branch_id = ? AND im.id = ?
                FOR UPDATE
            ');
            $lockedMovementStmt->execute([$branchId, $id]);
            $lockedMovement = $lockedMovementStmt->fetch();

            if (!$lockedMovement) {
                throw new RuntimeException('Inventory movement was not found.');
            }

            if (!in_array($lockedMovement['type'], $reversibleTypes, true)) {
                throw new RuntimeException('This movement type is not reversible.');
            }

            if (has_inventory_reversal($pdo, $branchId, (int)$lockedMovement['id'])) {
                throw new RuntimeException('This movement has already been reversed.');
            }

            $reverseQty = -(int)$lockedMovement['qty'];
            $newStock = (int)$lockedMovement['stock_qty'] + $reverseQty;

            if ($newStock < 0) {
                throw new RuntimeException('Reversal would make stock negative.');
            }

            $updateStmt = $pdo->prepare('UPDATE products SET stock_qty = ? WHERE branch_id = ? AND id = ?');
            $updateStmt->execute([$newStock, $branchId, (int)$lockedMovement['product_id']]);

            $remarks = 'Reversal of movement #' . (int)$lockedMovement['id'] . ' (' . $lockedMovement['type'] . '). Reason: ' . $reason;
            $movementInsert = $pdo->prepare('
                INSERT INTO inventory_movements(branch_id, product_id, type, qty, remarks, user_id)
                VALUES(?, ?, ?, ?, ?, ?)
            ');
            $movementInsert->execute([
                $branchId,
                (int)$lockedMovement['product_id'],
                'reversal',
                $reverseQty,
                $remarks,
                $userId,
            ]);

            $pdo->commit();
            redirect_to('inventory/index.php?reversed=1');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = 'Reversal could not be saved. ' . $e->getMessage();
        }
    }
}

include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-3">
    <div>
        <h4 class="mb-0">Reverse Inventory Movement</h4>
        <small class="text-muted">Create an opposite adjustment without editing past history.</small>
    </div>
    <a class="btn btn-outline-secondary" href="<?= app_url('inventory/index.php') ?>">
        <i class="bi bi-arrow-left"></i> Back to Inventory
    </a>
</div>

<?php if ($errors): ?>
    <div class="alert alert-danger">
        <strong>Reversal was not saved.</strong>
        <ul class="mb-0">
            <?php foreach ($errors as $error): ?>
                <li><?= htmlspecialchars($error) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?php if (!$canReverse): ?>
    <div class="alert alert-warning">
        <?php if ($alreadyReversed): ?>
            This movement has already been reversed.
        <?php elseif ($wouldGoNegative): ?>
            This reversal would make stock negative, so it cannot be saved.
        <?php else: ?>
            This movement type cannot be reversed safely from the inventory module.
        <?php endif; ?>
    </div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-5">
        <div class="table-card h-100">
            <h5>Movement Details</h5>
            <dl class="row mb-0">
                <dt class="col-sm-4">Product</dt>
                <dd class="col-sm-8">
                    <?= htmlspecialchars($movement['product']) ?>
                    <div class="small text-muted">
                        <?= htmlspecialchars($movement['sku'] ?: ($movement['barcode'] ?: 'No SKU/barcode')) ?>
                    </div>
                </dd>

                <dt class="col-sm-4">Type</dt>
                <dd class="col-sm-8"><?= htmlspecialchars($movement['type']) ?></dd>

                <dt class="col-sm-4">Qty</dt>
                <dd class="col-sm-8"><?= (int)$movement['qty'] > 0 ? '+' : '' ?><?= (int)$movement['qty'] ?></dd>

                <dt class="col-sm-4">Remarks</dt>
                <dd class="col-sm-8"><?= htmlspecialchars($movement['remarks'] ?? '') ?></dd>

                <dt class="col-sm-4">User</dt>
                <dd class="col-sm-8"><?= htmlspecialchars($movement['user_name'] ?? 'System') ?></dd>

                <dt class="col-sm-4">Date</dt>
                <dd class="col-sm-8"><?= htmlspecialchars(date('M d, Y h:i A', strtotime($movement['created_at']))) ?></dd>
            </dl>
        </div>
    </div>
    <div class="col-lg-7">
        <form method="post" action="<?= app_url('inventory/reverse.php?id=' . (int)$movement['id']) ?>" class="table-card h-100">
            <input type="hidden" name="id" value="<?= (int)$movement['id'] ?>">

            <h5>Reversal Adjustment</h5>
            <div class="row g-3 mb-3">
                <div class="col-md-4">
                    <label class="form-label">Current Stock</label>
                    <input class="form-control" value="<?= (int)$movement['stock_qty'] ?>" disabled>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Reversal Qty</label>
                    <input class="form-control" value="<?= $reverseQty > 0 ? '+' : '' ?><?= $reverseQty ?>" disabled>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Projected Stock</label>
                    <input class="form-control" value="<?= $projectedStock ?>" disabled>
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label">Reason</label>
                <input class="form-control" name="reason" maxlength="160" value="<?= htmlspecialchars($reason) ?>" placeholder="Required reason for reversal" required <?= $canReverse ? '' : 'disabled' ?>>
            </div>

            <button class="btn btn-warning" <?= $canReverse ? '' : 'disabled' ?> onclick="return confirm('Create a reversal adjustment for this movement?');">
                <i class="bi bi-arrow-counterclockwise"></i> Save Reversal
            </button>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
