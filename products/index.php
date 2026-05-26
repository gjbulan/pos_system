<?php
$pageTitle = 'Products';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/header.php';
$branchId = current_branch_id();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    $data = [$_POST['category_id'] ?: null, $_POST['name'], $_POST['barcode'], $_POST['sku'], $_POST['price'], $_POST['cost'], $_POST['stock_qty'], $_POST['low_stock_threshold'], $branchId];
    if ($id) {
        $stmt = $pdo->prepare('UPDATE products SET category_id=?, name=?, barcode=?, sku=?, price=?, cost=?, stock_qty=?, low_stock_threshold=? WHERE branch_id=? AND id=?');
        $stmt->execute([...$data, $id]);
    } else {
        $stmt = $pdo->prepare('INSERT INTO products(category_id,name,barcode,sku,price,cost,stock_qty,low_stock_threshold,branch_id) VALUES(?,?,?,?,?,?,?,?,?)');
        $stmt->execute($data);
    }
    header('Location: index.php'); exit;
}
if (isset($_GET['delete'])) {
    $stmt = $pdo->prepare('DELETE FROM products WHERE branch_id=? AND id=?');
    $stmt->execute([$branchId, (int)$_GET['delete']]);
    header('Location: index.php'); exit;
}
$categories = $pdo->prepare('SELECT * FROM categories WHERE branch_id=? ORDER BY name');
$categories->execute([$branchId]);
$categories = $categories->fetchAll();
$products = $pdo->prepare('SELECT p.*, c.name category FROM products p LEFT JOIN categories c ON c.id=p.category_id WHERE p.branch_id=? ORDER BY p.name');
$products->execute([$branchId]);
?>
<div class="row g-4">
<div class="col-lg-4"><div class="table-card"><h5>Add Product</h5><form method="post">
<input type="hidden" name="id" id="product_id">
<label class="form-label">Name</label><input class="form-control mb-2" name="name" required>
<label class="form-label">Barcode</label><input class="form-control mb-2" name="barcode" placeholder="Scan or type barcode">
<label class="form-label">SKU</label><input class="form-control mb-2" name="sku">
<label class="form-label">Category</label><select class="form-select mb-2" name="category_id"><option value="">None</option><?php foreach($categories as $c): ?><option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option><?php endforeach; ?></select>
<div class="row"><div class="col"><label>Cost</label><input class="form-control mb-2" type="number" step="0.01" name="cost" value="0"></div><div class="col"><label>Price</label><input class="form-control mb-2" type="number" step="0.01" name="price" value="0"></div></div>
<div class="row"><div class="col"><label>Stock</label><input class="form-control mb-2" type="number" name="stock_qty" value="0"></div><div class="col"><label>Low Stock</label><input class="form-control mb-2" type="number" name="low_stock_threshold" value="5"></div></div>
<button class="btn btn-primary w-100">Save Product</button></form></div></div>
<div class="col-lg-8"><div class="table-card"><h5>Product List</h5><input class="form-control mb-3" id="productFilter" placeholder="Search by name, SKU, or barcode">
<table class="table align-middle"><thead><tr><th>Name</th><th>Barcode</th><th>Category</th><th>Price</th><th>Stock</th><th></th></tr></thead><tbody id="productTable">
<?php foreach($products as $p): ?><tr class="<?= $p['stock_qty'] <= $p['low_stock_threshold'] ? 'table-warning' : '' ?>"><td><?= htmlspecialchars($p['name']) ?></td><td><code><?= htmlspecialchars($p['barcode']) ?></code></td><td><?= htmlspecialchars($p['category'] ?? '') ?></td><td>₱<?= number_format($p['price'],2) ?></td><td><?= (int)$p['stock_qty'] ?></td><td><a class="btn btn-sm btn-outline-danger" href="?delete=<?= $p['id'] ?>" onclick="return confirm('Delete product?')">Delete</a></td></tr><?php endforeach; ?>
</tbody></table></div></div></div>
<script>document.getElementById('productFilter').addEventListener('input', e=>{const q=e.target.value.toLowerCase();document.querySelectorAll('#productTable tr').forEach(r=>r.style.display=r.innerText.toLowerCase().includes(q)?'':'none');});</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
