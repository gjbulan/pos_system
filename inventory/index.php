<?php
$pageTitle = 'Inventory';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../auth/session.php';

require_login();
require_permission($pdo, 'inventory.view');

$branchId = current_branch_id();
$canManageInventory = can($pdo, 'inventory.manage');
$reversibleTypes = ['stock_in', 'adjustment_in', 'adjustment_out'];
$typeFilter = trim($_GET['type'] ?? '');
$search = trim($_GET['q'] ?? '');

$where = ['im.branch_id = ?'];
$params = [$branchId];

if ($typeFilter !== '') {
    $where[] = 'im.type = ?';
    $params[] = $typeFilter;
}

if ($search !== '') {
    $where[] = '(p.name LIKE ? OR p.sku LIKE ? OR p.barcode LIKE ? OR im.remarks LIKE ?)';
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$sql = '
    SELECT
        im.*,
        p.name AS product,
        p.sku,
        p.barcode,
        p.stock_qty,
        u.name AS user_name,
        EXISTS(
            SELECT 1
            FROM inventory_movements r
            WHERE r.branch_id = im.branch_id
              AND r.type = "reversal"
              AND r.remarks LIKE CONCAT("Reversal of movement #", im.id, "%")
        ) AS has_reversal
    FROM inventory_movements im
    JOIN products p ON p.id = im.product_id
    LEFT JOIN users u ON u.id = im.user_id
    WHERE ' . implode(' AND ', $where) . '
    ORDER BY im.id DESC
    LIMIT 300
';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$movements = $stmt->fetchAll();

$typesStmt = $pdo->prepare('
    SELECT DISTINCT type
    FROM inventory_movements
    WHERE branch_id = ?
    ORDER BY type
');
$typesStmt->execute([$branchId]);
$movementTypes = $typesStmt->fetchAll(PDO::FETCH_COLUMN);

include __DIR__ . '/../includes/header.php';
?>

<?php if (isset($_GET['stocked'])): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        Stock-in saved successfully.
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if (isset($_GET['adjusted'])): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        Inventory adjustment saved successfully.
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if (isset($_GET['reversed'])): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        Reversal adjustment saved successfully.
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-3">
    <div>
        <h4 class="mb-0">Inventory</h4>
        <small class="text-muted">Stock movement, adjustment history, and reversal adjustments.</small>
    </div>
    <?php if ($canManageInventory): ?>
        <div class="btn-group">
            <a class="btn btn-primary" href="<?= app_url('inventory/stock_in.php') ?>">
                <i class="bi bi-box-arrow-in-down"></i> Stock In
            </a>
            <a class="btn btn-outline-primary" href="<?= app_url('inventory/adjust.php') ?>">
                <i class="bi bi-plus-slash-minus"></i> Adjust Stock
            </a>
        </div>
    <?php endif; ?>
</div>

<div class="table-card mb-3">
    <form method="get" action="<?= app_url('inventory/index.php') ?>" class="row g-2 align-items-end">
        <div class="col-lg-6 col-md-5">
            <label class="form-label">Search</label>
            <input
                class="form-control"
                type="search"
                name="q"
                value="<?= htmlspecialchars($search) ?>"
                placeholder="Search product, SKU, barcode, or remarks"
            >
        </div>
        <div class="col-lg-3 col-md-4">
            <label class="form-label">Movement Type</label>
            <select class="form-select" name="type">
                <option value="">All types</option>
                <?php foreach ($movementTypes as $movementType): ?>
                    <option value="<?= htmlspecialchars($movementType) ?>" <?= $typeFilter === $movementType ? 'selected' : '' ?>>
                        <?= htmlspecialchars($movementType) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-lg-3 col-md-3 d-grid">
            <button class="btn btn-outline-primary">
                <i class="bi bi-funnel"></i> Filter
            </button>
        </div>
    </form>
</div>

<div class="table-card">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="mb-0">Adjustment History</h5>
        <span class="badge text-bg-light"><?= count($movements) ?> shown</span>
    </div>

    <table class="table align-middle">
        <thead>
            <tr>
                <th>Product</th>
                <th>Type</th>
                <th class="text-end">Qty</th>
                <th>Remarks</th>
                <th>User</th>
                <th>Date</th>
                <?php if ($canManageInventory): ?>
                    <th class="text-end">Action</th>
                <?php endif; ?>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($movements as $movement): ?>
                <?php
                $qty = (int)$movement['qty'];
                $isReversible = in_array($movement['type'], $reversibleTypes, true) && !(bool)$movement['has_reversal'];
                ?>
                <tr>
                    <td>
                        <div class="fw-semibold"><?= htmlspecialchars($movement['product']) ?></div>
                        <small class="text-muted">
                            <?= htmlspecialchars($movement['sku'] ?: ($movement['barcode'] ?: 'No SKU/barcode')) ?>
                            · Current stock: <?= (int)$movement['stock_qty'] ?>
                        </small>
                    </td>
                    <td><span class="badge text-bg-light"><?= htmlspecialchars($movement['type']) ?></span></td>
                    <td class="text-end <?= $qty < 0 ? 'text-danger' : 'text-success' ?>">
                        <?= $qty > 0 ? '+' : '' ?><?= $qty ?>
                    </td>
                    <td><?= htmlspecialchars($movement['remarks'] ?? '') ?></td>
                    <td><?= htmlspecialchars($movement['user_name'] ?? 'System') ?></td>
                    <td><?= htmlspecialchars(date('M d, Y h:i A', strtotime($movement['created_at']))) ?></td>
                    <?php if ($canManageInventory): ?>
                        <td class="text-end">
                            <?php if ($isReversible): ?>
                                <a class="btn btn-sm btn-outline-warning" href="<?= app_url('inventory/reverse.php?id=' . (int)$movement['id']) ?>">
                                    Reverse
                                </a>
                            <?php elseif ((bool)$movement['has_reversal']): ?>
                                <span class="badge text-bg-secondary">Reversed</span>
                            <?php else: ?>
                                <span class="text-muted small">Not reversible</span>
                            <?php endif; ?>
                        </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>

            <?php if (!$movements): ?>
                <tr>
                    <td colspan="<?= $canManageInventory ? 7 : 6 ?>" class="text-center text-muted py-4">
                        No inventory movements found for this branch.
                    </td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
