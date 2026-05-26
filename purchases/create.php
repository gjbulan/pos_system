<?php
$pageTitle = 'Create Purchase Order';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../auth/session.php';

require_login();
require_permission($pdo, 'purchases.manage');

$branchId = current_branch_id();
$userId = (int)($_SESSION['user_id'] ?? 0);
$errors = [];

$suppliersStmt = $pdo->prepare('SELECT id, name FROM suppliers WHERE branch_id = ? ORDER BY name');
$suppliersStmt->execute([$branchId]);
$suppliers = $suppliersStmt->fetchAll();
$supplierIds = array_map('intval', array_column($suppliers, 'id'));

$productsStmt = $pdo->prepare('
    SELECT id, name, sku, barcode, cost, stock_qty
    FROM products
    WHERE branch_id = ?
    ORDER BY name
');
$productsStmt->execute([$branchId]);
$products = $productsStmt->fetchAll();
$productIds = array_map('intval', array_column($products, 'id'));

$old = [
    'po_number' => 'PO-' . date('Ymd-His'),
    'supplier_id' => '',
    'po_date' => date('Y-m-d'),
    'notes' => '',
    'items' => [
        ['product_id' => '', 'qty' => '', 'cost' => ''],
    ],
];

function valid_purchase_date(string $date): bool
{
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    return $parsed instanceof DateTimeImmutable && $parsed->format('Y-m-d') === $date;
}

function purchase_product_options(array $products, string $selectedId = ''): string
{
    $html = '<option value="">Select product</option>';
    foreach ($products as $product) {
        $label = $product['name'];
        if (!empty($product['sku'])) {
            $label .= ' - SKU: ' . $product['sku'];
        }
        if (!empty($product['barcode'])) {
            $label .= ' - Barcode: ' . $product['barcode'];
        }
        $label .= ' - Stock: ' . (int)$product['stock_qty'];

        $selected = $selectedId === (string)$product['id'] ? ' selected' : '';
        $html .= '<option value="' . (int)$product['id'] . '" data-cost="' . htmlspecialchars((string)$product['cost'], ENT_QUOTES, 'UTF-8') . '"' . $selected . '>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</option>';
    }

    return $html;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedItems = is_array($_POST['items'] ?? null) ? $_POST['items'] : [];
    $old = [
        'po_number' => trim($_POST['po_number'] ?? ''),
        'supplier_id' => trim($_POST['supplier_id'] ?? ''),
        'po_date' => trim($_POST['po_date'] ?? ''),
        'notes' => trim($_POST['notes'] ?? ''),
        'items' => [],
    ];

    foreach ($postedItems as $item) {
        if (!is_array($item)) {
            continue;
        }
        $old['items'][] = [
            'product_id' => trim($item['product_id'] ?? ''),
            'qty' => trim($item['qty'] ?? ''),
            'cost' => trim($item['cost'] ?? ''),
        ];
    }

    if (!$old['items']) {
        $old['items'][] = ['product_id' => '', 'qty' => '', 'cost' => ''];
    }

    if ($old['po_number'] === '') {
        $errors[] = 'PO number is required.';
    } elseif (strlen($old['po_number']) > 80) {
        $errors[] = 'PO number must not exceed 80 characters.';
    } else {
        $checkStmt = $pdo->prepare('SELECT id FROM purchase_orders WHERE branch_id = ? AND po_number = ? LIMIT 1');
        $checkStmt->execute([$branchId, $old['po_number']]);
        if ($checkStmt->fetchColumn()) {
            $errors[] = 'PO number already exists for this branch.';
        }
    }

    if ($old['supplier_id'] === '' || !ctype_digit($old['supplier_id']) || !in_array((int)$old['supplier_id'], $supplierIds, true)) {
        $errors[] = 'Please select a valid supplier for this branch.';
    }

    if ($old['po_date'] === '' || !valid_purchase_date($old['po_date'])) {
        $errors[] = 'Please enter a valid PO date.';
    }

    if (strlen($old['notes']) > 255) {
        $errors[] = 'Notes must not exceed 255 characters.';
    }

    $cleanItems = [];
    foreach ($old['items'] as $index => $item) {
        $hasAnyValue = $item['product_id'] !== '' || $item['qty'] !== '' || $item['cost'] !== '';
        if (!$hasAnyValue) {
            continue;
        }

        $rowNumber = $index + 1;
        if ($item['product_id'] === '' || !ctype_digit($item['product_id']) || !in_array((int)$item['product_id'], $productIds, true)) {
            $errors[] = "Row {$rowNumber}: select a valid branch product.";
            continue;
        }

        if ($item['qty'] === '' || filter_var($item['qty'], FILTER_VALIDATE_INT) === false || (int)$item['qty'] <= 0) {
            $errors[] = "Row {$rowNumber}: quantity must be greater than zero.";
            continue;
        }

        if ($item['cost'] === '' || filter_var($item['cost'], FILTER_VALIDATE_FLOAT) === false || (float)$item['cost'] < 0) {
            $errors[] = "Row {$rowNumber}: cost must be zero or greater.";
            continue;
        }

        $cleanItems[] = [
            'product_id' => (int)$item['product_id'],
            'qty' => (int)$item['qty'],
            'cost' => round((float)$item['cost'], 2),
        ];
    }

    if (!$cleanItems) {
        $errors[] = 'Add at least one purchase order item.';
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();

            $orderStmt = $pdo->prepare('
                INSERT INTO purchase_orders(branch_id, supplier_id, po_number, po_date, notes, created_by)
                VALUES(?, ?, ?, ?, ?, ?)
            ');
            $orderStmt->execute([
                $branchId,
                (int)$old['supplier_id'],
                $old['po_number'],
                $old['po_date'],
                $old['notes'] !== '' ? $old['notes'] : null,
                $userId ?: null,
            ]);
            $purchaseOrderId = (int)$pdo->lastInsertId();

            $itemStmt = $pdo->prepare('
                INSERT INTO purchase_order_items(purchase_order_id, product_id, qty_ordered, cost)
                VALUES(?, ?, ?, ?)
            ');
            foreach ($cleanItems as $item) {
                $itemStmt->execute([$purchaseOrderId, $item['product_id'], $item['qty'], $item['cost']]);
            }

            log_activity($pdo, 'create_purchase_order', 'purchases', 'Created purchase order ' . $old['po_number']);
            $pdo->commit();

            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Purchase order created successfully.'];
            redirect_to('purchases/view.php?id=' . $purchaseOrderId);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = 'Purchase order could not be saved. ' . $e->getMessage();
        }
    }
}

include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-3">
    <div>
        <h4 class="mb-0">Create Purchase Order</h4>
        <small class="text-muted">Prepare supplier item quantities and costs before receiving stock.</small>
    </div>
    <a class="btn btn-outline-secondary" href="<?= app_url('purchases/index.php') ?>">
        <i class="bi bi-arrow-left"></i> Back to Purchase Orders
    </a>
</div>

<?php if ($errors): ?>
    <div class="alert alert-danger">
        <strong>Purchase order was not saved.</strong>
        <ul class="mb-0">
            <?php foreach ($errors as $error): ?>
                <li><?= htmlspecialchars($error) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?php if (!$suppliers || !$products): ?>
    <div class="alert alert-warning">
        Add at least one supplier and one product in this branch before creating purchase orders.
    </div>
<?php endif; ?>

<form method="post" action="<?= app_url('purchases/create.php') ?>" class="table-card">
    <div class="row g-3 mb-3">
        <div class="col-md-4">
            <label class="form-label">PO Number</label>
            <input type="text" name="po_number" class="form-control" maxlength="80" value="<?= htmlspecialchars($old['po_number']) ?>" required>
        </div>
        <div class="col-md-4">
            <label class="form-label">Supplier</label>
            <select name="supplier_id" class="form-select" required>
                <option value="">Select supplier</option>
                <?php foreach ($suppliers as $supplier): ?>
                    <option value="<?= (int)$supplier['id'] ?>" <?= $old['supplier_id'] === (string)$supplier['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($supplier['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-4">
            <label class="form-label">PO Date</label>
            <input type="date" name="po_date" class="form-control" value="<?= htmlspecialchars($old['po_date']) ?>" required>
        </div>
        <div class="col-12">
            <label class="form-label">Notes</label>
            <input type="text" name="notes" class="form-control" maxlength="255" value="<?= htmlspecialchars($old['notes']) ?>" placeholder="Supplier quotation, delivery note, or internal reference">
        </div>
    </div>

    <div class="d-flex justify-content-between align-items-center mb-2">
        <h5 class="mb-0">Items</h5>
        <button class="btn btn-sm btn-outline-primary" type="button" id="addItemRow">
            <i class="bi bi-plus-lg"></i> Add Row
        </button>
    </div>

    <div class="table-responsive">
        <table class="table align-middle">
            <thead class="table-light">
                <tr>
                    <th style="min-width: 320px;">Product</th>
                    <th style="width: 140px;">Quantity</th>
                    <th style="width: 160px;">Cost</th>
                    <th style="width: 160px;" class="text-end">Line Total</th>
                    <th style="width: 80px;"></th>
                </tr>
            </thead>
            <tbody id="itemRows">
                <?php foreach ($old['items'] as $index => $item): ?>
                    <tr class="po-item-row">
                        <td>
                            <select name="items[<?= (int)$index ?>][product_id]" class="form-select product-select">
                                <?= purchase_product_options($products, (string)($item['product_id'] ?? '')) ?>
                            </select>
                        </td>
                        <td>
                            <input type="number" name="items[<?= (int)$index ?>][qty]" class="form-control qty-input" min="1" value="<?= htmlspecialchars($item['qty'] ?? '') ?>">
                        </td>
                        <td>
                            <input type="number" name="items[<?= (int)$index ?>][cost]" class="form-control cost-input" min="0" step="0.01" value="<?= htmlspecialchars($item['cost'] ?? '') ?>">
                        </td>
                        <td class="text-end line-total">0.00</td>
                        <td class="text-end">
                            <button type="button" class="btn btn-sm btn-outline-danger remove-item-row" aria-label="Remove row">
                                <i class="bi bi-x-lg"></i>
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <th colspan="3" class="text-end">Total</th>
                    <th class="text-end" id="orderTotal">0.00</th>
                    <th></th>
                </tr>
            </tfoot>
        </table>
    </div>

    <div class="d-flex justify-content-end gap-2">
        <a class="btn btn-outline-secondary" href="<?= app_url('purchases/index.php') ?>">Cancel</a>
        <button class="btn btn-primary" <?= (!$suppliers || !$products) ? 'disabled' : '' ?>>
            <i class="bi bi-save"></i> Save Purchase Order
        </button>
    </div>
</form>

<template id="itemRowTemplate">
    <tr class="po-item-row">
        <td>
            <select name="items[__INDEX__][product_id]" class="form-select product-select">
                <?= purchase_product_options($products) ?>
            </select>
        </td>
        <td>
            <input type="number" name="items[__INDEX__][qty]" class="form-control qty-input" min="1">
        </td>
        <td>
            <input type="number" name="items[__INDEX__][cost]" class="form-control cost-input" min="0" step="0.01">
        </td>
        <td class="text-end line-total">0.00</td>
        <td class="text-end">
            <button type="button" class="btn btn-sm btn-outline-danger remove-item-row" aria-label="Remove row">
                <i class="bi bi-x-lg"></i>
            </button>
        </td>
    </tr>
</template>

<script>
let nextItemIndex = <?= count($old['items']) ?>;
const itemRows = document.getElementById('itemRows');
const template = document.getElementById('itemRowTemplate');

function updatePurchaseTotals() {
    let total = 0;
    itemRows.querySelectorAll('.po-item-row').forEach(row => {
        const qty = Number(row.querySelector('.qty-input')?.value || 0);
        const cost = Number(row.querySelector('.cost-input')?.value || 0);
        const lineTotal = qty * cost;
        total += lineTotal;
        row.querySelector('.line-total').textContent = lineTotal.toFixed(2);
    });
    document.getElementById('orderTotal').textContent = total.toFixed(2);
}

function addItemRow() {
    const html = template.innerHTML.replaceAll('__INDEX__', String(nextItemIndex++));
    itemRows.insertAdjacentHTML('beforeend', html);
    updatePurchaseTotals();
}

document.getElementById('addItemRow').addEventListener('click', addItemRow);

itemRows.addEventListener('click', event => {
    const button = event.target.closest('.remove-item-row');
    if (!button) {
        return;
    }
    if (itemRows.querySelectorAll('.po-item-row').length > 1) {
        button.closest('.po-item-row').remove();
        updatePurchaseTotals();
    }
});

itemRows.addEventListener('input', updatePurchaseTotals);
itemRows.addEventListener('change', event => {
    if (event.target.classList.contains('product-select')) {
        const option = event.target.selectedOptions[0];
        const costInput = event.target.closest('.po-item-row').querySelector('.cost-input');
        if (option && option.dataset.cost && costInput && costInput.value === '') {
            costInput.value = Number(option.dataset.cost).toFixed(2);
        }
    }
    updatePurchaseTotals();
});

updatePurchaseTotals();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
