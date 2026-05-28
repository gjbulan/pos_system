<?php
$pageTitle = 'Edit Quotation';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../auth/session.php';
require_once __DIR__ . '/helpers.php';

require_login();
require_permission($pdo, 'quotations.manage');

$branchId = current_branch_id();
$quotationId = (int)($_GET['id'] ?? 0);
$errors = [];

$quoteStmt = $pdo->prepare('SELECT * FROM quotations WHERE id = ? AND branch_id = ? LIMIT 1');
$quoteStmt->execute([$quotationId, $branchId]);
$quote = $quoteStmt->fetch();

if (!$quote) {
    http_response_code(404);
    include __DIR__ . '/../includes/header.php';
    echo '<div class="alert alert-danger">Quotation not found.</div>';
    include __DIR__ . '/../includes/footer.php';
    exit;
}

if (!in_array($quote['status'], ['draft', 'issued'], true)) {
    include __DIR__ . '/../includes/header.php';
    echo '<div class="alert alert-warning">Only draft or issued quotations can be edited.</div>';
    echo '<a class="btn btn-outline-secondary" href="' . htmlspecialchars(app_url('quotations/view.php?id=' . $quotationId)) . '">Back to quotation</a>';
    include __DIR__ . '/../includes/footer.php';
    exit;
}

$settingsStmt = $pdo->prepare("
    SELECT setting_key, setting_value
    FROM settings
    WHERE setting_key IN ('enable_senior_discount', 'enable_pwd_discount')
");
$settingsStmt->execute();
$settings = [];
foreach ($settingsStmt as $row) {
    $settings[$row['setting_key']] = $row['setting_value'];
}
$seniorDiscountEnabled = ($settings['enable_senior_discount'] ?? '1') === '1';
$pwdDiscountEnabled = ($settings['enable_pwd_discount'] ?? '1') === '1';

$canViewCustomers = can($pdo, 'customers.view');
$customers = [];
if ($canViewCustomers) {
    $customersStmt = $pdo->prepare('
        SELECT id, name, phone, email
        FROM customers
        WHERE branch_id = ?
        ORDER BY name
    ');
    $customersStmt->execute([$branchId]);
    $customers = $customersStmt->fetchAll();
}

$productsStmt = $pdo->prepare('
    SELECT id, name, sku, barcode, price, stock_qty
    FROM products
    WHERE branch_id = ?
    ORDER BY name
');
$productsStmt->execute([$branchId]);
$products = $productsStmt->fetchAll();
$productMap = quotation_product_map($products);

$itemsStmt = $pdo->prepare('
    SELECT product_id, qty
    FROM quotation_items
    WHERE quotation_id = ?
    ORDER BY id
');
$itemsStmt->execute([$quotationId]);
$existingItems = $itemsStmt->fetchAll();

$old = [
    'quote_no' => $quote['quote_no'],
    'customer_id' => $quote['customer_id'] !== null ? (string)$quote['customer_id'] : '',
    'valid_until' => $quote['valid_until'] ?? '',
    'status' => $quote['status'],
    'discount_type' => $quote['discount_type'] ?? '',
    'discount_value' => (string)(float)$quote['discount_value'],
    'notes' => $quote['notes'] ?? '',
    'items' => array_map(static fn(array $item): array => [
        'product_id' => (string)$item['product_id'],
        'qty' => (string)$item['qty'],
    ], $existingItems),
];

if (!$old['items']) {
    $old['items'][] = ['product_id' => '', 'qty' => '1'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedItems = is_array($_POST['items'] ?? null) ? $_POST['items'] : [];
    $old = [
        'quote_no' => trim($_POST['quote_no'] ?? ''),
        'customer_id' => trim($_POST['customer_id'] ?? ''),
        'valid_until' => trim($_POST['valid_until'] ?? ''),
        'status' => trim($_POST['status'] ?? 'issued'),
        'discount_type' => trim($_POST['discount_type'] ?? ''),
        'discount_value' => trim($_POST['discount_value'] ?? '0'),
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
        ];
    }

    if (!$old['items']) {
        $old['items'][] = ['product_id' => '', 'qty' => '1'];
    }

    if ($old['quote_no'] === '') {
        $errors[] = 'Quotation number is required.';
    } elseif (strlen($old['quote_no']) > 80) {
        $errors[] = 'Quotation number must not exceed 80 characters.';
    } else {
        $checkStmt = $pdo->prepare('SELECT id FROM quotations WHERE branch_id = ? AND quote_no = ? AND id <> ? LIMIT 1');
        $checkStmt->execute([$branchId, $old['quote_no'], $quotationId]);
        if ($checkStmt->fetchColumn()) {
            $errors[] = 'Quotation number already exists for this branch.';
        }
    }

    if (!in_array($old['status'], ['draft', 'issued'], true)) {
        $errors[] = 'Please select a valid quotation status.';
    }

    if ($old['valid_until'] !== '' && !quotation_valid_date($old['valid_until'])) {
        $errors[] = 'Please enter a valid expiration date.';
    }

    if (strlen($old['notes']) > 2000) {
        $errors[] = 'Notes must not exceed 2000 characters.';
    }

    $customerId = null;
    if ($old['customer_id'] !== '') {
        if (!$canViewCustomers || !ctype_digit($old['customer_id'])) {
            $errors[] = 'Please select a valid customer for this branch.';
        } else {
            $customerStmt = $pdo->prepare('SELECT id FROM customers WHERE id = ? AND branch_id = ? LIMIT 1');
            $customerStmt->execute([(int)$old['customer_id'], $branchId]);
            $customerId = $customerStmt->fetchColumn() ? (int)$old['customer_id'] : null;
            if ($customerId === null) {
                $errors[] = 'Selected customer was not found for this branch.';
            }
        }
    }

    $cleanItems = [];
    $subtotal = 0.0;
    foreach ($old['items'] as $index => $item) {
        $hasAnyValue = $item['product_id'] !== '' || $item['qty'] !== '';
        if (!$hasAnyValue) {
            continue;
        }

        $rowNumber = $index + 1;
        if ($item['product_id'] === '' || !ctype_digit($item['product_id']) || !isset($productMap[(int)$item['product_id']])) {
            $errors[] = "Row {$rowNumber}: select a valid branch product.";
            continue;
        }

        if ($item['qty'] === '' || filter_var($item['qty'], FILTER_VALIDATE_INT) === false || (int)$item['qty'] <= 0) {
            $errors[] = "Row {$rowNumber}: quantity must be a whole number greater than zero.";
            continue;
        }

        $product = $productMap[(int)$item['product_id']];
        $qty = (int)$item['qty'];
        $price = round((float)$product['price'], 2);
        $lineSubtotal = round($price * $qty, 2);
        $subtotal = round($subtotal + $lineSubtotal, 2);
        $cleanItems[] = [
            'product_id' => (int)$product['id'],
            'qty' => $qty,
            'price' => $price,
            'subtotal' => $lineSubtotal,
        ];
    }

    if (!$cleanItems) {
        $errors[] = 'Add at least one quotation item.';
    }

    $discountType = null;
    $discountValue = 0.0;
    $discountAmount = 0.0;
    if (!$errors) {
        try {
            [$discountType, $discountValue, $discountAmount] = quotation_calculate_discount(
                $old['discount_type'],
                is_numeric($old['discount_value']) ? (float)$old['discount_value'] : -1,
                $subtotal,
                $customerId,
                $seniorDiscountEnabled,
                $pwdDiscountEnabled
            );
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }

    $total = round(max(0, $subtotal - $discountAmount), 2);

    if (!$errors) {
        try {
            $pdo->beginTransaction();

            $lockStmt = $pdo->prepare('SELECT status FROM quotations WHERE id = ? AND branch_id = ? FOR UPDATE');
            $lockStmt->execute([$quotationId, $branchId]);
            $lockedStatus = $lockStmt->fetchColumn();
            if (!in_array($lockedStatus, ['draft', 'issued'], true)) {
                throw new RuntimeException('Quotation is no longer editable.');
            }

            $updateStmt = $pdo->prepare('
                UPDATE quotations
                SET customer_id = ?, quote_no = ?, status = ?, valid_until = ?,
                    subtotal_amount = ?, discount_type = ?, discount_value = ?,
                    discount_amount = ?, total_amount = ?, notes = ?
                WHERE id = ? AND branch_id = ? AND status IN ("draft", "issued")
            ');
            $updateStmt->execute([
                $customerId,
                $old['quote_no'],
                $old['status'],
                $old['valid_until'] !== '' ? $old['valid_until'] : null,
                $subtotal,
                $discountType,
                $discountValue,
                $discountAmount,
                $total,
                $old['notes'] !== '' ? $old['notes'] : null,
                $quotationId,
                $branchId,
            ]);

            $deleteStmt = $pdo->prepare('DELETE FROM quotation_items WHERE quotation_id = ?');
            $deleteStmt->execute([$quotationId]);

            $itemStmt = $pdo->prepare('
                INSERT INTO quotation_items(quotation_id, product_id, qty, price, subtotal)
                VALUES(?, ?, ?, ?, ?)
            ');
            foreach ($cleanItems as $item) {
                $itemStmt->execute([
                    $quotationId,
                    $item['product_id'],
                    $item['qty'],
                    $item['price'],
                    $item['subtotal'],
                ]);
            }

            log_activity($pdo, 'update_quotation', 'quotations', 'Updated quotation ' . $old['quote_no']);
            $pdo->commit();

            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Quotation updated successfully.'];
            redirect_to('quotations/view.php?id=' . $quotationId);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = 'Quotation could not be updated. ' . $e->getMessage();
        }
    }
}

include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-3">
    <div>
        <h4 class="mb-0">Edit Quotation</h4>
        <small class="text-muted"><?= htmlspecialchars($quote['quote_no']) ?></small>
    </div>
    <a class="btn btn-outline-secondary" href="<?= app_url('quotations/view.php?id=' . $quotationId) ?>">
        <i class="bi bi-arrow-left"></i> Back to Quotation
    </a>
</div>

<?php if ($errors): ?>
    <div class="alert alert-danger">
        <strong>Quotation was not updated.</strong>
        <ul class="mb-0">
            <?php foreach ($errors as $error): ?>
                <li><?= htmlspecialchars($error) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<form method="post" action="<?= app_url('quotations/edit.php?id=' . $quotationId) ?>" class="table-card">
    <div class="row g-3 mb-3">
        <div class="col-md-4">
            <label class="form-label">Quotation No.</label>
            <input type="text" name="quote_no" class="form-control" maxlength="80" value="<?= htmlspecialchars($old['quote_no']) ?>" required>
        </div>
        <div class="col-md-4">
            <label class="form-label">Customer</label>
            <select name="customer_id" class="form-select" <?= $canViewCustomers ? '' : 'disabled' ?>>
                <option value="">Walk-in Customer</option>
                <?php foreach ($customers as $customer): ?>
                    <option value="<?= (int)$customer['id'] ?>" <?= $old['customer_id'] === (string)$customer['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($customer['name'] . (!empty($customer['phone']) ? ' - ' . $customer['phone'] : '')) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label">Valid Until</label>
            <input type="date" name="valid_until" class="form-control" value="<?= htmlspecialchars($old['valid_until']) ?>">
        </div>
        <div class="col-md-2">
            <label class="form-label">Status</label>
            <select name="status" class="form-select">
                <option value="draft" <?= $old['status'] === 'draft' ? 'selected' : '' ?>>Draft</option>
                <option value="issued" <?= $old['status'] === 'issued' ? 'selected' : '' ?>>Issued</option>
            </select>
        </div>
        <div class="col-12">
            <label class="form-label">Notes</label>
            <textarea name="notes" class="form-control" rows="2" maxlength="2000"><?= htmlspecialchars($old['notes']) ?></textarea>
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
                    <th style="width: 160px;" class="text-end">Price</th>
                    <th style="width: 160px;" class="text-end">Line Total</th>
                    <th style="width: 80px;"></th>
                </tr>
            </thead>
            <tbody id="itemRows">
                <?php foreach ($old['items'] as $index => $item): ?>
                    <tr class="quote-item-row">
                        <td>
                            <select name="items[<?= (int)$index ?>][product_id]" class="form-select product-select">
                                <?= quotation_product_options($products, (string)($item['product_id'] ?? '')) ?>
                            </select>
                        </td>
                        <td>
                            <input type="number" name="items[<?= (int)$index ?>][qty]" class="form-control qty-input" min="1" value="<?= htmlspecialchars($item['qty'] ?? '') ?>">
                        </td>
                        <td class="text-end product-price">0.00</td>
                        <td class="text-end line-total">0.00</td>
                        <td class="text-end">
                            <button type="button" class="btn btn-sm btn-outline-danger remove-item-row" aria-label="Remove row">
                                <i class="bi bi-x-lg"></i>
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="row g-3 justify-content-end">
        <div class="col-lg-5">
            <div class="border rounded p-3">
                <label class="form-label">Discount</label>
                <select class="form-select" name="discount_type" id="discountType">
                    <option value="" <?= $old['discount_type'] === '' ? 'selected' : '' ?>>No discount</option>
                    <option value="percentage" <?= $old['discount_type'] === 'percentage' ? 'selected' : '' ?>>Percentage</option>
                    <option value="fixed" <?= $old['discount_type'] === 'fixed' ? 'selected' : '' ?>>Fixed Amount</option>
                    <option value="senior" <?= $old['discount_type'] === 'senior' ? 'selected' : '' ?> <?= $seniorDiscountEnabled ? '' : 'disabled' ?>>Senior (20%)</option>
                    <option value="pwd" <?= $old['discount_type'] === 'pwd' ? 'selected' : '' ?> <?= $pwdDiscountEnabled ? '' : 'disabled' ?>>PWD (20%)</option>
                </select>
                <label class="form-label mt-2 mb-1">Discount Value</label>
                <input class="form-control" type="number" step="0.01" min="0" name="discount_value" id="discountValue" value="<?= htmlspecialchars($old['discount_value']) ?>">
                <small class="text-muted d-block mt-2" id="discountHelp">Discounts are saved on the quotation and carried into POS conversion.</small>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="border rounded p-3 h-100">
                <div class="d-flex justify-content-between border-bottom py-2">
                    <span>Subtotal</span>
                    <strong id="quoteSubtotal">0.00</strong>
                </div>
                <div class="d-flex justify-content-between border-bottom py-2 text-success">
                    <span>Discount</span>
                    <strong id="quoteDiscount">-0.00</strong>
                </div>
                <div class="d-flex justify-content-between fs-5 pt-2">
                    <strong>Total</strong>
                    <strong id="quoteTotal">0.00</strong>
                </div>
            </div>
        </div>
    </div>

    <div class="d-flex justify-content-end gap-2 mt-4">
        <a class="btn btn-outline-secondary" href="<?= app_url('quotations/view.php?id=' . $quotationId) ?>">Cancel</a>
        <button class="btn btn-primary">
            <i class="bi bi-save"></i> Update Quotation
        </button>
    </div>
</form>

<template id="itemRowTemplate">
    <tr class="quote-item-row">
        <td>
            <select name="items[__INDEX__][product_id]" class="form-select product-select">
                <?= quotation_product_options($products) ?>
            </select>
        </td>
        <td>
            <input type="number" name="items[__INDEX__][qty]" class="form-control qty-input" min="1" value="1">
        </td>
        <td class="text-end product-price">0.00</td>
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
const seniorPwdRate = 20;

function calculateQuoteDiscount(subtotal) {
    const discountType = document.getElementById('discountType').value;
    const discountValue = Number(document.getElementById('discountValue').value || 0);

    if (subtotal <= 0 || discountType === '') {
        return 0;
    }

    if (discountType === 'percentage') {
        return Math.min(subtotal, subtotal * (Math.max(0, Math.min(discountValue, 100)) / 100));
    }

    if (discountType === 'fixed') {
        return Math.min(subtotal, Math.max(0, discountValue));
    }

    if (discountType === 'senior' || discountType === 'pwd') {
        return Math.min(subtotal, subtotal * (seniorPwdRate / 100));
    }

    return 0;
}

function syncDiscountInput() {
    const discountType = document.getElementById('discountType').value;
    const discountValue = document.getElementById('discountValue');
    const discountHelp = document.getElementById('discountHelp');

    discountValue.disabled = discountType === '' || discountType === 'senior' || discountType === 'pwd';
    discountValue.readOnly = discountType === 'senior' || discountType === 'pwd';

    if (discountType === 'senior' || discountType === 'pwd') {
        discountValue.value = seniorPwdRate;
        discountHelp.textContent = 'Senior/PWD discounts require a selected customer.';
    } else if (discountType === 'percentage') {
        discountHelp.textContent = 'Enter a percentage from 0 to 100.';
    } else if (discountType === 'fixed') {
        discountHelp.textContent = 'Enter a fixed peso amount.';
    } else {
        discountValue.value = 0;
        discountHelp.textContent = 'Discounts are saved on the quotation and carried into POS conversion.';
    }
}

function updateQuotationTotals() {
    let subtotal = 0;
    itemRows.querySelectorAll('.quote-item-row').forEach(row => {
        const option = row.querySelector('.product-select')?.selectedOptions[0];
        const price = Number(option?.dataset.price || 0);
        const qty = Number(row.querySelector('.qty-input')?.value || 0);
        const lineTotal = price * qty;
        subtotal += lineTotal;
        row.querySelector('.product-price').textContent = price.toFixed(2);
        row.querySelector('.line-total').textContent = lineTotal.toFixed(2);
    });

    const discount = calculateQuoteDiscount(subtotal);
    const total = Math.max(0, subtotal - discount);
    document.getElementById('quoteSubtotal').textContent = subtotal.toFixed(2);
    document.getElementById('quoteDiscount').textContent = '-' + discount.toFixed(2);
    document.getElementById('quoteTotal').textContent = total.toFixed(2);
}

function addItemRow() {
    const html = template.innerHTML.replaceAll('__INDEX__', String(nextItemIndex++));
    itemRows.insertAdjacentHTML('beforeend', html);
    updateQuotationTotals();
}

document.getElementById('addItemRow').addEventListener('click', addItemRow);

itemRows.addEventListener('click', event => {
    const button = event.target.closest('.remove-item-row');
    if (!button) {
        return;
    }
    if (itemRows.querySelectorAll('.quote-item-row').length > 1) {
        button.closest('.quote-item-row').remove();
        updateQuotationTotals();
    }
});

itemRows.addEventListener('input', updateQuotationTotals);
itemRows.addEventListener('change', updateQuotationTotals);
document.getElementById('discountType').addEventListener('change', () => {
    syncDiscountInput();
    updateQuotationTotals();
});
document.getElementById('discountValue').addEventListener('input', updateQuotationTotals);

syncDiscountInput();
updateQuotationTotals();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
