<?php
$pageTitle = 'POS Checkout';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../auth/session.php';

require_login();
require_permission($pdo, 'pos.access');

$branchId = current_branch_id();

$settingsStmt = $pdo->prepare("
    SELECT setting_key, setting_value
    FROM settings
    WHERE setting_key IN ('enable_customer_tracking', 'require_customer_on_sale')
");
$settingsStmt->execute();
$posSettings = [];
foreach ($settingsStmt as $row) {
    $posSettings[$row['setting_key']] = $row['setting_value'];
}

$customerTrackingEnabled = ($posSettings['enable_customer_tracking'] ?? '1') === '1';
$requireCustomerOnSale = $customerTrackingEnabled && ($posSettings['require_customer_on_sale'] ?? '0') === '1';
$canViewCustomers = can($pdo, 'customers.view');
$canManageCustomers = can($pdo, 'customers.manage');
$selectedCustomerId = (int)($_GET['customer_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'quick_add_customer') {
    if (!$customerTrackingEnabled) {
        $_SESSION['pos_flash'] = ['type' => 'warning', 'message' => 'Customer tracking is disabled in Settings.'];
        header('Location: ' . app_url('pos/index.php'));
        exit;
    }

    if (!$canManageCustomers) {
        $_SESSION['pos_flash'] = ['type' => 'danger', 'message' => 'You do not have permission to add customers.'];
        header('Location: ' . app_url('pos/index.php'));
        exit;
    }

    $name = trim($_POST['customer_name'] ?? '');
    $phone = trim($_POST['customer_phone'] ?? '');
    $email = trim($_POST['customer_email'] ?? '');

    if ($name === '') {
        $_SESSION['pos_flash'] = ['type' => 'danger', 'message' => 'Customer name is required.'];
        header('Location: ' . app_url('pos/index.php'));
        exit;
    }

    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['pos_flash'] = ['type' => 'danger', 'message' => 'Enter a valid customer email address.'];
        header('Location: ' . app_url('pos/index.php'));
        exit;
    }

    try {
        $customerStmt = $pdo->prepare('
            INSERT INTO customers (branch_id, name, phone, email)
            VALUES (?, ?, ?, ?)
        ');
        $customerStmt->execute([
            $branchId,
            $name,
            $phone !== '' ? $phone : null,
            $email !== '' ? $email : null,
        ]);

        $newCustomerId = (int)$pdo->lastInsertId();
        log_activity($pdo, 'create', 'customers', 'Quick-added customer from POS: ' . $name);
        $_SESSION['pos_flash'] = ['type' => 'success', 'message' => 'Customer added and selected.'];
        header('Location: ' . app_url('pos/index.php?customer_id=' . $newCustomerId));
        exit;
    } catch (Throwable $e) {
        $_SESSION['pos_flash'] = ['type' => 'danger', 'message' => 'Unable to add customer. Please try again.'];
        header('Location: ' . app_url('pos/index.php'));
        exit;
    }
}

$customers = [];
if ($customerTrackingEnabled && $canViewCustomers) {
    $customersStmt = $pdo->prepare('
        SELECT id, name, phone, email
        FROM customers
        WHERE branch_id = ?
        ORDER BY name ASC
    ');
    $customersStmt->execute([$branchId]);
    $customers = $customersStmt->fetchAll();
}

$flash = $_SESSION['pos_flash'] ?? null;
unset($_SESSION['pos_flash']);

$products = $pdo->prepare('
    SELECT id, name, barcode, sku, price, stock_qty
    FROM products
    WHERE branch_id = ? AND stock_qty > 0
    ORDER BY name
');
$products->execute([$branchId]);

require_once __DIR__ . '/../includes/header.php';
?>

<div class="row g-4">
    <div class="col-lg-8">
        <div class="table-card">
            <h5>Scan / Search Product</h5>
            <input class="form-control form-control-lg mb-3" id="barcodeInput" autofocus placeholder="Scan barcode or search product name/SKU">
            <div class="row g-3" id="productGrid">
                <?php foreach ($products as $product): ?>
                    <div class="col-md-4 product-card" data-search="<?= htmlspecialchars(strtolower($product['name'] . ' ' . $product['barcode'] . ' ' . $product['sku'])) ?>">
                        <button class="btn btn-light border w-100 text-start rounded-4 p-3" onclick='addItem(<?= json_encode($product) ?>)'>
                            <strong><?= htmlspecialchars($product['name']) ?></strong><br>
                            <small class="text-muted">Barcode: <?= htmlspecialchars($product['barcode'] ?: 'N/A') ?></small><br>
                            <span>&#8369;<?= number_format((float)$product['price'], 2) ?></span><br>
                            <small>Stock: <?= (int)$product['stock_qty'] ?></small>
                        </button>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="table-card pos-cart">
            <h5>Cart</h5>

            <?php if ($flash): ?>
                <div class="alert alert-<?= htmlspecialchars($flash['type']) ?> py-2">
                    <?= htmlspecialchars($flash['message']) ?>
                </div>
            <?php endif; ?>

            <form method="post" action="checkout.php" id="checkoutForm" data-require-customer="<?= $requireCustomerOnSale ? '1' : '0' ?>">
                <?php if ($customerTrackingEnabled && $canViewCustomers): ?>
                    <label class="form-label">Customer</label>
                    <select class="form-select mb-3" name="customer_id" id="customerSelect">
                        <option value="">Walk-in Customer</option>
                        <?php foreach ($customers as $customer): ?>
                            <option value="<?= (int)$customer['id'] ?>" <?= $selectedCustomerId === (int)$customer['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($customer['name'] . (!empty($customer['phone']) ? ' - ' . $customer['phone'] : '')) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($requireCustomerOnSale): ?>
                        <div class="small text-danger mb-3">Customer is required before checkout.</div>
                    <?php endif; ?>
                <?php elseif ($customerTrackingEnabled): ?>
                    <div class="alert alert-warning py-2">Customer tracking is enabled, but this role cannot view customers.</div>
                <?php endif; ?>

                <div id="cartItems"></div>
                <hr>
                <div class="d-flex justify-content-between">
                    <strong>Total</strong>
                    <strong id="cartTotal">&#8369;0.00</strong>
                </div>
                <input type="hidden" name="cart_json" id="cartJson">

                <label class="form-label mt-3">Payment Method</label>
                <select class="form-select" name="payment_method">
                    <option>Cash</option>
                    <option>GCash</option>
                    <option>Card</option>
                </select>

                <label class="form-label mt-3">Amount Tendered</label>
                <input class="form-control" type="number" step="0.01" name="amount_tendered" required>

                <button class="btn btn-primary w-100 mt-3">Complete Sale</button>
            </form>

            <?php if ($customerTrackingEnabled && $canManageCustomers): ?>
                <hr>
                <button class="btn btn-outline-primary btn-sm w-100" type="button" data-bs-toggle="collapse" data-bs-target="#quickAddCustomer">
                    Quick Add Customer
                </button>
                <div class="collapse mt-3" id="quickAddCustomer">
                    <form method="post" class="border rounded p-3">
                        <input type="hidden" name="action" value="quick_add_customer">
                        <label class="form-label">Name <span class="text-danger">*</span></label>
                        <input class="form-control mb-2" type="text" name="customer_name" maxlength="120" required>
                        <label class="form-label">Phone</label>
                        <input class="form-control mb-2" type="text" name="customer_phone" maxlength="50">
                        <label class="form-label">Email</label>
                        <input class="form-control mb-3" type="email" name="customer_email" maxlength="120">
                        <button class="btn btn-primary btn-sm w-100" type="submit">Add Customer</button>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
let cart = [];
function addItem(p){ const found=cart.find(i=>i.id==p.id); if(found){ if(found.qty < Number(p.stock_qty)) found.qty++; } else cart.push({id:p.id,name:p.name,price:Number(p.price),qty:1,stock:Number(p.stock_qty)}); renderCart(); }
function removeItem(id){ cart = cart.filter(i=>i.id!==id); renderCart(); }
function changeQty(id,qty){ const item=cart.find(i=>i.id===id); if(!item) return; item.qty=Math.max(1,Math.min(Number(qty),item.stock)); renderCart(); }
function money(n){ return '\u20B1'+Number(n).toFixed(2); }
function renderCart(){ let total=0; const wrap=document.getElementById('cartItems'); wrap.innerHTML=''; cart.forEach(i=>{ total+=i.price*i.qty; wrap.innerHTML += `<div class="d-flex justify-content-between align-items-center border-bottom py-2"><div><strong>${i.name}</strong><br><small>${money(i.price)} each</small></div><div class="d-flex gap-1 align-items-center"><input class="form-control form-control-sm" style="width:70px" type="number" value="${i.qty}" min="1" max="${i.stock}" onchange="changeQty(${i.id},this.value)"><button type="button" class="btn btn-sm btn-outline-danger" onclick="removeItem(${i.id})">x</button></div></div>`; }); document.getElementById('cartTotal').textContent=money(total); document.getElementById('cartJson').value=JSON.stringify(cart); }
document.getElementById('barcodeInput').addEventListener('input', e=>{ const q=e.target.value.toLowerCase(); document.querySelectorAll('.product-card').forEach(c=>c.style.display=c.dataset.search.includes(q)?'':'none'); const exact=[...document.querySelectorAll('.product-card')].find(c=>c.dataset.search.split(' ').includes(q)); if(exact && q.length>=6){ exact.querySelector('button').click(); e.target.value=''; }});
document.getElementById('checkoutForm').addEventListener('submit', e=>{
    if(cart.length===0){
        e.preventDefault();
        alert('Cart is empty.');
        return;
    }

    const checkoutForm = e.currentTarget;
    const customerSelect = document.getElementById('customerSelect');
    if(checkoutForm.dataset.requireCustomer === '1' && (!customerSelect || customerSelect.value === '')){
        e.preventDefault();
        alert('Please select a customer before checkout.');
    }
});
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
