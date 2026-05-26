<?php
$pageTitle = 'Adjust Inventory';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../auth/session.php';

require_login();
require_permission($pdo, 'inventory.manage');

$branchId = current_branch_id();
$userId = (int)($_SESSION['user_id'] ?? 0);
$errors = [];
$old = [
    'product_id' => '',
    'direction' => 'increase',
    'qty' => '',
    'reference' => '',
    'reason' => '',
];

function build_adjustment_remarks(string $reference, string $reason): string
{
    $parts = [];

    if ($reference !== '') {
        $parts[] = 'Reference: ' . $reference;
    }

    if ($reason !== '') {
        $parts[] = 'Reason: ' . $reason;
    }

    return $parts ? implode(' | ', $parts) : 'Manual stock adjustment';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $old = [
        'product_id' => trim($_POST['product_id'] ?? ''),
        'direction' => trim($_POST['direction'] ?? 'increase'),
        'qty' => trim($_POST['qty'] ?? ''),
        'reference' => trim($_POST['reference'] ?? ''),
        'reason' => trim($_POST['reason'] ?? ''),
    ];

    if ($old['product_id'] === '' || !ctype_digit($old['product_id'])) {
        $errors[] = 'Please select a valid product.';
    }

    if (!in_array($old['direction'], ['increase', 'decrease'], true)) {
        $errors[] = 'Adjustment type is invalid.';
    }

    if ($old['qty'] === '' || filter_var($old['qty'], FILTER_VALIDATE_INT) === false || (int)$old['qty'] <= 0) {
        $errors[] = 'Quantity must be greater than zero.';
    }

    if ($old['reason'] === '') {
        $errors[] = 'Reason is required.';
    }

    if (!$errors) {
        $productId = (int)$old['product_id'];
        $qty = (int)$old['qty'];
        $signedQty = $old['direction'] === 'increase' ? $qty : -$qty;
        $movementType = $old['direction'] === 'increase' ? 'adjustment_in' : 'adjustment_out';
        $remarks = build_adjustment_remarks($old['reference'], $old['reason']);

        try {
            $pdo->beginTransaction();

            $productStmt = $pdo->prepare('SELECT id, stock_qty FROM products WHERE branch_id = ? AND id = ? FOR UPDATE');
            $productStmt->execute([$branchId, $productId]);
            $product = $productStmt->fetch();

            if (!$product) {
                throw new RuntimeException('Selected product was not found for this branch.');
            }

            $currentStock = (int)$product['stock_qty'];
            $newStock = $currentStock + $signedQty;

            if ($newStock < 0) {
                throw new RuntimeException('Adjustment would make stock negative.');
            }

            $updateStmt = $pdo->prepare('UPDATE products SET stock_qty = ? WHERE branch_id = ? AND id = ?');
            $updateStmt->execute([$newStock, $branchId, $productId]);

            $movementStmt = $pdo->prepare('
                INSERT INTO inventory_movements(branch_id, product_id, type, qty, remarks, user_id)
                VALUES(?, ?, ?, ?, ?, ?)
            ');
            $movementStmt->execute([$branchId, $productId, $movementType, $signedQty, $remarks, $userId]);

            $pdo->commit();
            redirect_to('inventory/index.php?adjusted=1');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = 'Adjustment could not be saved. ' . $e->getMessage();
        }
    }
}

$productsStmt = $pdo->prepare('
    SELECT id, name, sku, barcode, stock_qty
    FROM products
    WHERE branch_id = ?
    ORDER BY name
');
$productsStmt->execute([$branchId]);
$products = $productsStmt->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-3">
    <div>
        <h4 class="mb-0">Adjust Inventory</h4>
        <small class="text-muted">Increase or decrease stock with a logged adjustment.</small>
    </div>
    <a class="btn btn-outline-secondary" href="<?= app_url('inventory/index.php') ?>">
        <i class="bi bi-arrow-left"></i> Back to Inventory
    </a>
</div>

<?php if ($errors): ?>
    <div class="alert alert-danger">
        <strong>Adjustment was not saved.</strong>
        <ul class="mb-0">
            <?php foreach ($errors as $error): ?>
                <li><?= htmlspecialchars($error) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="table-card">
    <?php if ($products): ?>
        <form method="post" action="<?= app_url('inventory/adjust.php') ?>">
            <div class="mb-3">
                <label class="form-label">Search Product</label>
                <input class="form-control" id="productSearch" type="search" placeholder="Search by name, SKU, or barcode">
            </div>

            <div class="mb-3">
                <label class="form-label">Product</label>
                <select name="product_id" id="productSelect" class="form-select" required>
                    <option value="">Select product</option>
                    <?php foreach ($products as $product): ?>
                        <?php $searchText = strtolower($product['name'] . ' ' . ($product['sku'] ?? '') . ' ' . ($product['barcode'] ?? '')); ?>
                        <option
                            value="<?= (int)$product['id'] ?>"
                            data-search="<?= htmlspecialchars($searchText) ?>"
                            data-stock="<?= (int)$product['stock_qty'] ?>"
                            <?= $old['product_id'] === (string)$product['id'] ? 'selected' : '' ?>
                        >
                            <?= htmlspecialchars($product['name']) ?>
                            <?= $product['sku'] ? ' · SKU: ' . htmlspecialchars($product['sku']) : '' ?>
                            <?= $product['barcode'] ? ' · Barcode: ' . htmlspecialchars($product['barcode']) : '' ?>
                            · Current: <?= (int)$product['stock_qty'] ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text" id="currentStockText">Select a product to see current stock.</div>
            </div>

            <div class="mb-3">
                <label class="form-label">Adjustment Type</label>
                <select name="direction" class="form-select">
                    <option value="increase" <?= $old['direction'] === 'increase' ? 'selected' : '' ?>>Increase stock</option>
                    <option value="decrease" <?= $old['direction'] === 'decrease' ? 'selected' : '' ?>>Decrease stock</option>
                </select>
            </div>

            <div class="mb-3">
                <label class="form-label">Quantity</label>
                <input class="form-control" type="number" name="qty" min="1" value="<?= htmlspecialchars($old['qty']) ?>" required>
            </div>

            <div class="mb-3">
                <label class="form-label">Reference</label>
                <input class="form-control" name="reference" maxlength="80" value="<?= htmlspecialchars($old['reference']) ?>" placeholder="Count sheet, memo, or reference number">
            </div>

            <div class="mb-3">
                <label class="form-label">Reason</label>
                <input class="form-control" name="reason" maxlength="160" value="<?= htmlspecialchars($old['reason']) ?>" placeholder="Required reason for adjustment" required>
            </div>

            <button class="btn btn-primary">
                <i class="bi bi-save"></i> Save Adjustment
            </button>
        </form>
    <?php else: ?>
        <div class="text-center text-muted py-4">
            No products found for this branch.
        </div>
    <?php endif; ?>
</div>

<script>
const productSearch = document.getElementById('productSearch');
const productSelect = document.getElementById('productSelect');
const currentStockText = document.getElementById('currentStockText');

function updateCurrentStockText() {
    const selected = productSelect?.selectedOptions[0];
    if (!selected || !selected.dataset.stock) {
        currentStockText.textContent = 'Select a product to see current stock.';
        return;
    }
    currentStockText.textContent = 'Current stock: ' + selected.dataset.stock;
}

productSearch?.addEventListener('input', function (event) {
    const query = event.target.value.toLowerCase();
    document.querySelectorAll('#productSelect option[data-search]').forEach(function (option) {
        option.hidden = !option.dataset.search.includes(query);
    });
});

productSelect?.addEventListener('change', updateCurrentStockText);
updateCurrentStockText();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
