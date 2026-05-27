<?php
require_once __DIR__ . '/../auth/session.php';
require_once __DIR__ . '/../config/database.php';

require_login();
require_permission($pdo, 'pos.access');

function calculate_sale_discount(
    string $discountType,
    float $discountValue,
    float $subtotal,
    bool $customerTrackingEnabled,
    ?int $customerId,
    bool $seniorDiscountEnabled,
    bool $pwdDiscountEnabled
): array {
    $discountType = strtolower(trim($discountType));

    if ($discountType === '' || $discountType === 'none' || $subtotal <= 0) {
        return [null, 0.0, 0.0];
    }

    if (!in_array($discountType, ['percentage', 'fixed', 'senior', 'pwd'], true)) {
        throw new RuntimeException('Invalid discount type.');
    }

    if ($discountType === 'senior' || $discountType === 'pwd') {
        if (!$customerTrackingEnabled || $customerId === null) {
            throw new RuntimeException('Senior/PWD discounts require customer tracking and a selected customer.');
        }

        if ($discountType === 'senior' && !$seniorDiscountEnabled) {
            throw new RuntimeException('Senior discount is disabled in Settings.');
        }

        if ($discountType === 'pwd' && !$pwdDiscountEnabled) {
            throw new RuntimeException('PWD discount is disabled in Settings.');
        }

        $discountValue = 20.0;
        $discountAmount = round($subtotal * ($discountValue / 100), 2);
        return [$discountType, $discountValue, min($subtotal, $discountAmount)];
    }

    if ($discountValue <= 0) {
        throw new RuntimeException('Discount value must be greater than zero.');
    }

    if ($discountType === 'percentage') {
        if ($discountValue > 100) {
            throw new RuntimeException('Percentage discount cannot exceed 100%.');
        }

        $discountAmount = round($subtotal * ($discountValue / 100), 2);
        return [$discountType, $discountValue, min($subtotal, $discountAmount)];
    }

    if ($discountValue > $subtotal) {
        throw new RuntimeException('Fixed discount cannot exceed the sale subtotal.');
    }

    return [$discountType, $discountValue, round($discountValue, 2)];
}

$branchId = current_branch_id();
$userId = (int)$_SESSION['user_id'];
$cart = json_decode($_POST['cart_json'] ?? '[]', true);
$paymentMethod = $_POST['payment_method'] ?? 'Cash';
$amountTendered = (float)($_POST['amount_tendered'] ?? 0);
if (!$cart) { die('Cart is empty.'); }

$settingsStmt = $pdo->prepare("
    SELECT setting_key, setting_value
    FROM settings
    WHERE setting_key IN (
        'enable_customer_tracking',
        'require_customer_on_sale',
        'enable_senior_discount',
        'enable_pwd_discount'
    )
");
$settingsStmt->execute();
$posSettings = [];
foreach ($settingsStmt as $row) {
    $posSettings[$row['setting_key']] = $row['setting_value'];
}

$customerTrackingEnabled = ($posSettings['enable_customer_tracking'] ?? '1') === '1';
$requireCustomerOnSale = $customerTrackingEnabled && ($posSettings['require_customer_on_sale'] ?? '0') === '1';
$seniorDiscountEnabled = ($posSettings['enable_senior_discount'] ?? '1') === '1';
$pwdDiscountEnabled = ($posSettings['enable_pwd_discount'] ?? '1') === '1';
$customerId = null;

if ($customerTrackingEnabled) {
    $postedCustomerId = (int)($_POST['customer_id'] ?? 0);

    if ($postedCustomerId > 0) {
        if (!can($pdo, 'customers.view')) {
            die('Checkout failed: You do not have permission to select customers.');
        }

        $customerStmt = $pdo->prepare('SELECT id FROM customers WHERE id = ? AND branch_id = ?');
        $customerStmt->execute([$postedCustomerId, $branchId]);
        $customerId = $customerStmt->fetchColumn() ? $postedCustomerId : null;

        if ($customerId === null) {
            die('Checkout failed: Selected customer was not found for this branch.');
        }
    }

    if ($requireCustomerOnSale && $customerId === null) {
        die('Checkout failed: Customer is required for this sale.');
    }
}

$subtotal = 0.0;
foreach ($cart as $item) {
    $subtotal += (float)$item['price'] * (int)$item['qty'];
}

try {
    [$discountType, $discountValue, $discountAmount] = calculate_sale_discount(
        $_POST['discount_type'] ?? '',
        (float)($_POST['discount_value'] ?? 0),
        $subtotal,
        $customerTrackingEnabled,
        $customerId,
        $seniorDiscountEnabled,
        $pwdDiscountEnabled
    );
} catch (Throwable $e) {
    die('Checkout failed: ' . $e->getMessage());
}

$total = max(0, round($subtotal - $discountAmount, 2));

try {
    $pdo->beginTransaction();
    $cashSessionId = null;
    if (strtolower($paymentMethod) === 'cash') {
        $shiftStmt = $pdo->prepare("SELECT id FROM cash_sessions WHERE branch_id = ? AND user_id = ? AND status = 'open' ORDER BY id DESC LIMIT 1 FOR UPDATE");
        $shiftStmt->execute([$branchId, $userId]);
        $cashSessionId = $shiftStmt->fetchColumn();
        if (!$cashSessionId) {
            throw new Exception('Open a cash drawer shift before accepting cash payments.');
        }
    }
    if ($amountTendered < $total) { throw new Exception('Insufficient payment.'); }
    $invoice = 'INV-' . date('YmdHis');
    $stmt = $pdo->prepare('INSERT INTO sales(invoice_no,branch_id,user_id,customer_id,discount_type,discount_value,discount_amount,total_amount,amount_tendered,change_amount,payment_method,status) VALUES(?,?,?,?,?,?,?,?,?,?,?,"completed")');
    $stmt->execute([$invoice,$branchId,$userId,$customerId,$discountType,$discountValue,$discountAmount,$total,$amountTendered,$amountTendered-$total,$paymentMethod]);
    $saleId = (int)$pdo->lastInsertId();
    foreach ($cart as $item) {
        $productId = (int)$item['id']; $qty=(int)$item['qty']; $price=(float)$item['price'];
        $check = $pdo->prepare('SELECT stock_qty FROM products WHERE id=? AND branch_id=? FOR UPDATE');
        $check->execute([$productId,$branchId]);
        $stock = (int)$check->fetchColumn();
        if ($stock < $qty) { throw new Exception('Insufficient stock for product ID '.$productId); }
        $pdo->prepare('INSERT INTO sale_items(sale_id,product_id,qty,price,subtotal) VALUES(?,?,?,?,?)')->execute([$saleId,$productId,$qty,$price,$qty*$price]);
        $pdo->prepare('UPDATE products SET stock_qty = stock_qty - ? WHERE id=? AND branch_id=?')->execute([$qty,$productId,$branchId]);
        $pdo->prepare('INSERT INTO inventory_movements(branch_id,product_id,type,qty,remarks,user_id) VALUES(?, ?, "sale", ?, ?, ?)')->execute([$branchId,$productId,-$qty,'Sold via '.$invoice,$userId]);
    }
    if ($cashSessionId) {
        $pdo->prepare('INSERT INTO cash_drawer_transactions(cash_session_id,branch_id,user_id,type,amount,reference,remarks) VALUES(?, ?, ?, "sale_cash", ?, ?, ?)')->execute([$cashSessionId,$branchId,$userId,$total,$invoice,'Cash sale']);
    }
    log_activity($pdo, 'complete_sale', 'pos', 'Completed sale ' . $invoice . ' total: ' . number_format($total, 2) . ' discount: ' . number_format($discountAmount, 2));
    $pdo->commit();
    header('Location: ' . app_url('sales/receipt.php?id=' . $saleId . '&print=1')); exit;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    die('Checkout failed: ' . $e->getMessage());
}
