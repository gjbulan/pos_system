<?php
$pageTitle = 'Products';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../auth/session.php';

require_login();
require_permission($pdo, 'products.view');

$branchId = current_branch_id();
$canManageProducts = can($pdo, 'products.manage');
$errors = [];
$old = [
    'name' => '',
    'barcode' => '',
    'sku' => '',
    'category_id' => '',
    'cost' => '0',
    'price' => '0',
    'stock_qty' => '0',
    'low_stock_threshold' => '5',
];

function clean_product_text(string $value): string
{
    return trim($value);
}

function validate_product_category(PDO $pdo, int $branchId, string $categoryId, array &$errors): ?int
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

function validate_product_decimal(string $value, string $label, array &$errors): float
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

function validate_product_integer(string $value, string $label, array &$errors): int
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_permission($pdo, 'products.manage');

    $old = [
        'name' => clean_product_text($_POST['name'] ?? ''),
        'barcode' => clean_product_text($_POST['barcode'] ?? ''),
        'sku' => clean_product_text($_POST['sku'] ?? ''),
        'category_id' => clean_product_text($_POST['category_id'] ?? ''),
        'cost' => clean_product_text($_POST['cost'] ?? '0'),
        'price' => clean_product_text($_POST['price'] ?? '0'),
        'stock_qty' => clean_product_text($_POST['stock_qty'] ?? '0'),
        'low_stock_threshold' => clean_product_text($_POST['low_stock_threshold'] ?? '5'),
    ];

    if ($old['name'] === '') {
        $errors[] = 'Product name is required.';
    } elseif (strlen($old['name']) > 160) {
        $errors[] = 'Product name must not exceed 160 characters.';
    }

    if (strlen($old['barcode']) > 100) {
        $errors[] = 'Barcode must not exceed 100 characters.';
    }

    if (strlen($old['sku']) > 100) {
        $errors[] = 'SKU must not exceed 100 characters.';
    }

    $categoryId = validate_product_category($pdo, $branchId, $old['category_id'], $errors);
    $price = validate_product_decimal($old['price'], 'Price', $errors);
    $cost = validate_product_decimal($old['cost'], 'Cost', $errors);
    $stockQty = validate_product_integer($old['stock_qty'], 'Initial stock', $errors);
    $lowStockThreshold = validate_product_integer($old['low_stock_threshold'], 'Low stock threshold', $errors);

    if (!$errors) {
        try {
            $stmt = $pdo->prepare('
                INSERT INTO products
                    (category_id, name, barcode, sku, price, cost, stock_qty, low_stock_threshold, branch_id)
                VALUES
                    (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ');
            $stmt->execute([
                $categoryId,
                $old['name'],
                $old['barcode'] !== '' ? $old['barcode'] : null,
                $old['sku'] !== '' ? $old['sku'] : null,
                $price,
                $cost,
                $stockQty,
                $lowStockThreshold,
                $branchId,
            ]);

            redirect_to('products/index.php?added=1');
        } catch (Throwable $e) {
            $errors[] = 'Product could not be saved. Check for duplicate barcode or invalid values.';
        }
    }
}

if (isset($_GET['delete'])) {
    require_permission($pdo, 'products.manage');

    $stmt = $pdo->prepare('DELETE FROM products WHERE branch_id = ? AND id = ?');
    $stmt->execute([$branchId, (int)$_GET['delete']]);
    redirect_to('products/index.php?deleted=1');
}

$categories = $pdo->prepare('SELECT id, name FROM categories WHERE branch_id = ? ORDER BY name');
$categories->execute([$branchId]);
$categories = $categories->fetchAll();

$products = $pdo->prepare('
    SELECT p.*, c.name AS category
    FROM products p
    LEFT JOIN categories c ON c.id = p.category_id
    WHERE p.branch_id = ?
    ORDER BY p.name
');
$products->execute([$branchId]);
$products = $products->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<?php if (isset($_GET['added'])): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        Product added successfully.
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if (isset($_GET['updated'])): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        Product updated successfully.
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if (isset($_GET['deleted'])): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        Product deleted successfully.
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($errors): ?>
    <div class="alert alert-danger">
        <strong>Product was not saved.</strong>
        <ul class="mb-0">
            <?php foreach ($errors as $error): ?>
                <li><?= htmlspecialchars($error) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="row g-4">
    <?php if ($canManageProducts): ?>
        <div class="col-lg-4">
            <div class="table-card">
                <h5>Add Product</h5>
                <form method="post" action="<?= app_url('products/index.php') ?>">
                    <label class="form-label">Name</label>
                    <input class="form-control mb-2" name="name" value="<?= htmlspecialchars($old['name']) ?>" required maxlength="160">

                    <label class="form-label">Barcode</label>
                    <input class="form-control mb-2" name="barcode" value="<?= htmlspecialchars($old['barcode']) ?>" placeholder="Scan or type barcode" maxlength="100">

                    <label class="form-label">SKU</label>
                    <input class="form-control mb-2" name="sku" value="<?= htmlspecialchars($old['sku']) ?>" maxlength="100">

                    <label class="form-label">Category</label>
                    <select class="form-select mb-2" name="category_id">
                        <option value="">None</option>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?= (int)$category['id'] ?>" <?= (string)$category['id'] === $old['category_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($category['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <div class="row">
                        <div class="col">
                            <label class="form-label">Cost</label>
                            <input class="form-control mb-2" type="number" step="0.01" min="0" name="cost" value="<?= htmlspecialchars($old['cost']) ?>" required>
                        </div>
                        <div class="col">
                            <label class="form-label">Price</label>
                            <input class="form-control mb-2" type="number" step="0.01" min="0" name="price" value="<?= htmlspecialchars($old['price']) ?>" required>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col">
                            <label class="form-label">Initial Stock</label>
                            <input class="form-control mb-2" type="number" min="0" name="stock_qty" value="<?= htmlspecialchars($old['stock_qty']) ?>" required>
                        </div>
                        <div class="col">
                            <label class="form-label">Low Stock</label>
                            <input class="form-control mb-2" type="number" min="0" name="low_stock_threshold" value="<?= htmlspecialchars($old['low_stock_threshold']) ?>" required>
                        </div>
                    </div>

                    <button class="btn btn-primary w-100">Save Product</button>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <div class="<?= $canManageProducts ? 'col-lg-8' : 'col-lg-12' ?>">
        <div class="table-card">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="mb-0">Product List</h5>
                <span class="badge text-bg-light"><?= count($products) ?> shown</span>
            </div>
            <input class="form-control mb-3" id="productFilter" placeholder="Search by name, SKU, or barcode">

            <table class="table align-middle">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Barcode</th>
                        <th>SKU</th>
                        <th>Category</th>
                        <th>Price</th>
                        <th>Stock</th>
                        <?php if ($canManageProducts): ?>
                            <th class="text-end">Actions</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody id="productTable">
                    <?php foreach ($products as $product): ?>
                        <tr class="<?= (int)$product['stock_qty'] <= (int)$product['low_stock_threshold'] ? 'table-warning' : '' ?>">
                            <td class="fw-semibold"><?= htmlspecialchars($product['name']) ?></td>
                            <td><code><?= htmlspecialchars($product['barcode'] ?? '') ?></code></td>
                            <td><code><?= htmlspecialchars($product['sku'] ?? '') ?></code></td>
                            <td><?= htmlspecialchars($product['category'] ?? '') ?></td>
                            <td>&#8369;<?= number_format((float)$product['price'], 2) ?></td>
                            <td><?= (int)$product['stock_qty'] ?></td>
                            <?php if ($canManageProducts): ?>
                                <td class="text-end">
                                    <div class="btn-group btn-group-sm" role="group" aria-label="Product actions">
                                        <a class="btn btn-outline-primary" href="<?= app_url('products/edit.php?id=' . (int)$product['id']) ?>">
                                            <i class="bi bi-pencil-square"></i>
                                        </a>
                                        <a class="btn btn-outline-danger" href="<?= app_url('products/index.php?delete=' . (int)$product['id']) ?>" onclick="return confirm('Delete product?')">
                                            <i class="bi bi-trash"></i>
                                        </a>
                                    </div>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>

                    <?php if (!$products): ?>
                        <tr>
                            <td colspan="<?= $canManageProducts ? 7 : 6 ?>" class="text-center text-muted py-4">
                                No products found for this branch.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
document.getElementById('productFilter').addEventListener('input', function (event) {
    const query = event.target.value.toLowerCase();
    document.querySelectorAll('#productTable tr').forEach(function (row) {
        row.style.display = row.innerText.toLowerCase().includes(query) ? '' : 'none';
    });
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
