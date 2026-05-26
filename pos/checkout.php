<?php
require_once __DIR__ . '/../auth/session.php';
require_once __DIR__ . '/../config/database.php';

require_login();
require_permission($pdo, 'pos.access');

$branchId = current_branch_id();
$userId = (int)$_SESSION['user_id'];
$cart = json_decode($_POST['cart_json'] ?? '[]', true);
$paymentMethod = $_POST['payment_method'] ?? 'Cash';
$amountTendered = (float)($_POST['amount_tendered'] ?? 0);
if (!$cart) { die('Cart is empty.'); }
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
    $total = 0;
    foreach ($cart as $item) { $total += (float)$item['price'] * (int)$item['qty']; }
    if ($amountTendered < $total) { throw new Exception('Insufficient payment.'); }
    $invoice = 'INV-' . date('YmdHis');
    $stmt = $pdo->prepare('INSERT INTO sales(invoice_no,branch_id,user_id,total_amount,amount_tendered,change_amount,payment_method,status) VALUES(?,?,?,?,?,?,?,"completed")');
    $stmt->execute([$invoice,$branchId,$userId,$total,$amountTendered,$amountTendered-$total,$paymentMethod]);
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
    log_activity($pdo, 'complete_sale', 'pos', 'Completed sale ' . $invoice . ' total: ' . number_format($total, 2));
    $pdo->commit();
    header('Location: ' . app_url('sales/receipt.php?id=' . $saleId)); exit;
} catch (Throwable $e) { $pdo->rollBack(); die('Checkout failed: ' . $e->getMessage()); }
