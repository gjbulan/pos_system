<?php
$pageTitle = 'Edit Product';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../auth/session.php';

require_login();
require_permission($pdo, 'products.manage');

$branchId = current_branch_id();
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$errors = [];

function clean_product_edit_text(string $value): string
{
    return trim($value);
}

function validate_product_edit_category(PDO $pdo, int $branchId, string $categoryId, array &$errors): ?int
{
    if ($categoryId === '') {
        return null;
    }

    if (!ctype_digit($categoryId)) {
        $errors[] = 'Selected category is invalid.';
        return null;
    }

    $stmt = $pdo->prepare('SELECT id FROM categories WHERE branch_id = ? AND id = ? LIMIT 1');
    $stmt->execute([$branchId, (int)$categoryId]);

    if (!$stmt->fetchColumn()) {
        $errors[] = 'Selected category was not found for this branch.';
        return null;
    }

    return (int)$categoryId;
}

function validate_product_edit_decimal(string $value, string $label, array &$errors): float
{
    if ($value === '' || filter_var($value, FILTER_VALIDATE_FLOAT) === false) {
        $errors[] = "{$label} must be a valid number.";
        return 0.0;
    }

    $number = (float)$value;
    if ($number < 0) {
        $errors[] = "{$label} cannot be negative.";
    }

    return $number;
}

function validate_product_edit_integer(string $value, string $label, array &$errors): int
{
    if ($value === '' || filter_var($value, FILTER_VALIDATE_INT) === false) {
        $errors[] = "{$label} must be a whole number.";
        return 0;
    }

    $number = (int)$value;
    if ($number < 0) {
        $errors[] = "{$label} cannot be negative.";
    }

    return $number;
}

$stmt = $pdo->prepare('SELECT * FROM products WHERE branch_id = ? AND id = ? LIMIT 1');
$stmt->execute([$branchId, $id]);
$product = $stmt->fetch();

if (!$product) {
    include __DIR__ . '/../includes/header.php';
    ?>
    <div class="alert alert-danger">
        Product was not found for this branch.
    </div>
    <a class="btn btn-outline-secondary" href="<?= app_url('products/index.php') ?>">
        <i class="bi bi-arrow-left"></i> Back to Products
    </a>
    <?php
    include __DIR__ . '/../includes/footer.php';
    exit;
}

$form = [
    'id' => (int)$product['id'],
    'name' => (string)$product['name'],
    'barcode' => (string)($product['barcode'] ?? ''),
    'sku' => (string)($product['sku'] ?? ''),
    'category_id' => (string)($product['category_id'] ?? ''),
    'cost' => (string)$product['cost'],
    'price' => (string)$product['price'],
    'stock_qty' => (string)$product['stock_qty'],
    'low_stock_threshold' => (string)$product['low_stock_threshold'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form = [
        'id' => $id,
        'name' => clean_product_edit_text($_POST['name'] ?? ''),
        'barcode' => clean_product_edit_text($_POST['barcode'] ?? ''),
        'sku' => clean_product_edit_text($_POST['sku'] ?? ''),
        'category_id' => clean_product_edit_text($_POST['category_id'] ?? ''),
        'cost' => clean_product_edit_text($_POST['cost'] ?? '0'),
        'price' => clean_product_edit_text($_POST['price'] ?? '0'),
        'stock_qty' => (string)$product['stock_qty'],
        'low_stock_threshold' => clean_product_edit_text($_POST['low_stock_threshold'] ?? '5'),
    ];

    if ($form['name'] === '') {
        $errors[] = 'Product name is required.';
    } elseif (strlen($form['name']) > 160) {
        $errors[] = 'Product name must not exceed 160 characters.';
    }

    if (strlen($form['barcode']) > 100) {
        $errors[] = 'Barcode must not exceed 100 characters.';
    }

    if (strlen($form['sku']) > 100) {
        $errors[] = 'SKU must not exceed 100 characters.';
    }

    $categoryId = validate_product_edit_category($pdo, $branchId, $form['category_id'], $errors);
    $price = validate_product_edit_decimal($form['price'], 'Price', $errors);
    $cost = validate_product_edit_decimal($form['cost'], 'Cost', $errors);
    $lowStockThreshold = validate_product_edit_integer($form['low_stock_threshold'], 'Low stock threshold', $errors);

    if (!$errors) {
        try {
            $stmt = $pdo->prepare('
                UPDATE products
                SET category_id = ?,
                    name = ?,
                    barcode = ?,
                    sku = ?,
                    price = ?,
                    cost = ?,
                    low_stock_threshold = ?
                WHERE branch_id = ? AND id = ?
            ');
            $stmt->execute([
                $categoryId,
                $form['name'],
                $form['barcode'] !== '' ? $form['barcode'] : null,
                $form['sku'] !== '' ? $form['sku'] : null,
                $price,
                $cost,
                $lowStockThreshold,
                $branchId,
                $id,
            ]);

            redirect_to('products/index.php?updated=1');
        } catch (Throwable $e) {
            $errors[] = 'Product could not be updated. Check for duplicate barcode or invalid values.';
        }
    }
}

$categories = $pdo->prepare('SELECT id, name FROM categories WHERE branch_id = ? ORDER BY name');
$categories->execute([$branchId]);
$categories = $categories->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-3">
    <div>
        <h4 class="mb-0">Edit Product</h4>
        <small class="text-muted">Update product details for this branch.</small>
    </div>
    <a class="btn btn-outline-secondary" href="<?= app_url('products/index.php') ?>">
        <i class="bi bi-arrow-left"></i> Back to Products
    </a>
</div>

<?php if ($errors): ?>
    <div class="alert alert-danger">
        <strong>Product was not updated.</strong>
        <ul class="mb-0">
            <?php foreach ($errors as $error): ?>
                <li><?= htmlspecialchars($error) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<form method="post" action="<?= app_url('products/edit.php?id=' . (int)$form['id']) ?>" class="table-card">
    <input type="hidden" name="id" value="<?= (int)$form['id'] ?>">

    <div class="row g-3">
        <div class="col-md-6">
            <label class="form-label">Name</label>
            <input class="form-control" name="name" value="<?= htmlspecialchars($form['name']) ?>" required maxlength="160">
        </div>

        <div class="col-md-6">
            <label class="form-label">Category</label>
            <select class="form-select" name="category_id">
                <option value="">None</option>
                <?php foreach ($categories as $category): ?>
                    <option value="<?= (int)$category['id'] ?>" <?= (string)$category['id'] === $form['category_id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($category['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="col-md-6">
            <label class="form-label">Barcode</label>
            <input class="form-control" name="barcode" value="<?= htmlspecialchars($form['barcode']) ?>" maxlength="100">
        </div>

        <div class="col-md-6">
            <label class="form-label">SKU</label>
            <input class="form-control" name="sku" value="<?= htmlspecialchars($form['sku']) ?>" maxlength="100">
        </div>

        <div class="col-md-4">
            <label class="form-label">Cost</label>
            <input class="form-control" type="number" step="0.01" min="0" name="cost" value="<?= htmlspecialchars($form['cost']) ?>" required>
        </div>

        <div class="col-md-4">
            <label class="form-label">Price</label>
            <input class="form-control" type="number" step="0.01" min="0" name="price" value="<?= htmlspecialchars($form['price']) ?>" required>
        </div>

        <div class="col-md-4">
            <label class="form-label">Low Stock Threshold</label>
            <input class="form-control" type="number" min="0" name="low_stock_threshold" value="<?= htmlspecialchars($form['low_stock_threshold']) ?>" required>
        </div>

        <div class="col-md-4">
            <label class="form-label">Current Stock</label>
            <input class="form-control" value="<?= (int)$form['stock_qty'] ?>" disabled>
            <div class="form-text">Stock quantity is managed through inventory stock-in and POS sales.</div>
        </div>
    </div>

    <div class="d-flex justify-content-end gap-2 mt-4">
        <a class="btn btn-outline-secondary" href="<?= app_url('products/index.php') ?>">Cancel</a>
        <button class="btn btn-primary">
            <i class="bi bi-save"></i> Update Product
        </button>
    </div>
</form>

<?php include __DIR__ . '/../includes/footer.php'; ?>
