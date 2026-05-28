<?php
require_once __DIR__ . '/../auth/session.php';
require_once __DIR__ . '/../config/database.php';

require_login();
require_permission($pdo, 'pos.access');

function normalize_cart_items(array $cart): array
{
    $items = [];

    foreach ($cart as $item) {
        if (!is_array($item)) {
            throw new RuntimeException('Invalid cart item.');
        }

        $productId = filter_var($item['id'] ?? null, FILTER_VALIDATE_INT);
        $qty = filter_var($item['qty'] ?? null, FILTER_VALIDATE_INT);

        if (!$productId || !$qty || $productId <= 0 || $qty <= 0) {
            throw new RuntimeException('Cart quantities must be whole numbers greater than zero.');
        }

        $items[$productId] = ($items[$productId] ?? 0) + $qty;
    }

    if (!$items) {
        throw new RuntimeException('Cart is empty.');
    }

    return $items;
}

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
    $subtotal = round(max(0, $subtotal), 2);

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
$quotationIdRaw = $_POST['quotation_id'] ?? 0;
$quotationId = filter_var($quotationIdRaw, FILTER_VALIDATE_INT);
if ($quotationId === false || (int)$quotationId < 0) {
    die('Checkout failed: Invalid quotation reference.');
}
$quotationId = (int)$quotationId;
$cartItems = [];

if ($quotationId > 0) {
    if (!can($pdo, 'quotations.view')) {
        die('Checkout failed: You do not have permission to convert quotations.');
    }
} else {
    $decodedCart = json_decode($_POST['cart_json'] ?? '[]', true);

    if (!is_array($decodedCart)) {
        die('Checkout failed: Invalid cart data.');
    }

    try {
        $cartItems = normalize_cart_items($decodedCart);
    } catch (Throwable $e) {
        die('Checkout failed: ' . $e->getMessage());
    }
}

$paymentMethod = trim($_POST['payment_method'] ?? 'Cash');
if ($paymentMethod === '' || strlen($paymentMethod) > 40) {
    die('Checkout failed: Invalid payment method.');
}

$amountTenderedRaw = $_POST['amount_tendered'] ?? null;
if (!is_numeric($amountTenderedRaw)) {
    die('Checkout failed: Amount tendered is required.');
}
$amountTendered = round((float)$amountTenderedRaw, 2);
if ($amountTendered < 0) {
    die('Checkout failed: Amount tendered cannot be negative.');
}

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

if ($quotationId <= 0 && $customerTrackingEnabled) {
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

try {
    $pdo->beginTransaction();

    $cashSessionId = null;
    if (strtolower($paymentMethod) === 'cash') {
        $shiftStmt = $pdo->prepare("SELECT id FROM cash_sessions WHERE branch_id = ? AND user_id = ? AND status = 'open' ORDER BY id DESC LIMIT 1 FOR UPDATE");
        $shiftStmt->execute([$branchId, $userId]);
        $cashSessionId = $shiftStmt->fetchColumn();
        if (!$cashSessionId) {
            throw new RuntimeException('Open a cash drawer shift before accepting cash payments.');
        }
    }

    $subtotal = 0.0;
    $validatedItems = [];
    $quotation = null;
    $productStmt = $pdo->prepare('
        SELECT id, name, price, stock_qty
        FROM products
        WHERE id = ? AND branch_id = ?
        FOR UPDATE
    ');

    if ($quotationId > 0) {
        $quotationStmt = $pdo->prepare('
            SELECT *
            FROM quotations
            WHERE id = ? AND branch_id = ?
            FOR UPDATE
        ');
        $quotationStmt->execute([$quotationId, $branchId]);
        $quotation = $quotationStmt->fetch();

        if (!$quotation) {
            throw new RuntimeException('Quotation was not found for this branch.');
        }

        if (!in_array($quotation['status'], ['draft', 'issued'], true)) {
            throw new RuntimeException('Only draft or issued quotations can be converted.');
        }

        $customerId = $quotation['customer_id'] !== null ? (int)$quotation['customer_id'] : null;
        if ($customerId !== null) {
            $customerStmt = $pdo->prepare('SELECT id FROM customers WHERE id = ? AND branch_id = ? LIMIT 1');
            $customerStmt->execute([$customerId, $branchId]);
            if (!$customerStmt->fetchColumn()) {
                throw new RuntimeException('Quotation customer was not found for this branch.');
            }
        }

        if ($requireCustomerOnSale && $customerId === null) {
            throw new RuntimeException('Customer is required for this sale.');
        }

        $quoteItemsStmt = $pdo->prepare('
            SELECT product_id, qty, price
            FROM quotation_items
            WHERE quotation_id = ?
            ORDER BY id
        ');
        $quoteItemsStmt->execute([$quotationId]);
        $quoteItems = $quoteItemsStmt->fetchAll();
        if (!$quoteItems) {
            throw new RuntimeException('Quotation has no items to convert.');
        }

        foreach ($quoteItems as $quoteItem) {
            $qty = (int)$quoteItem['qty'];
            if ($qty <= 0) {
                throw new RuntimeException('Quotation contains an invalid item quantity.');
            }

            $productStmt->execute([(int)$quoteItem['product_id'], $branchId]);
            $product = $productStmt->fetch();

            if (!$product) {
                throw new RuntimeException('One or more quoted products were not found for this branch.');
            }

            $stock = (int)$product['stock_qty'];
            if ($stock < $qty) {
                throw new RuntimeException('Insufficient stock for ' . $product['name'] . '.');
            }

            $price = round((float)$quoteItem['price'], 2);
            if ($price < 0) {
                throw new RuntimeException('Quotation contains an invalid item price.');
            }

            $lineSubtotal = round($price * $qty, 2);
            $subtotal = round($subtotal + $lineSubtotal, 2);
            $validatedItems[] = [
                'product_id' => (int)$product['id'],
                'name' => $product['name'],
                'qty' => $qty,
                'price' => $price,
                'subtotal' => $lineSubtotal,
            ];
        }

        [$discountType, $discountValue, $discountAmount] = calculate_sale_discount(
            $quotation['discount_type'] ?? '',
            (float)($quotation['discount_value'] ?? 0),
            $subtotal,
            $customerTrackingEnabled,
            $customerId,
            $seniorDiscountEnabled,
            $pwdDiscountEnabled
        );
    } else {
        foreach ($cartItems as $productId => $qty) {
            $productStmt->execute([$productId, $branchId]);
            $product = $productStmt->fetch();

            if (!$product) {
                throw new RuntimeException('One or more cart products were not found for this branch.');
            }

            $stock = (int)$product['stock_qty'];
            if ($stock < $qty) {
                throw new RuntimeException('Insufficient stock for ' . $product['name'] . '.');
            }

            $price = round((float)$product['price'], 2);
            $lineSubtotal = round($price * $qty, 2);
            $subtotal = round($subtotal + $lineSubtotal, 2);
            $validatedItems[] = [
                'product_id' => (int)$product['id'],
                'name' => $product['name'],
                'qty' => $qty,
                'price' => $price,
                'subtotal' => $lineSubtotal,
            ];
        }

        [$discountType, $discountValue, $discountAmount] = calculate_sale_discount(
            $_POST['discount_type'] ?? '',
            (float)($_POST['discount_value'] ?? 0),
            $subtotal,
            $customerTrackingEnabled,
            $customerId,
            $seniorDiscountEnabled,
            $pwdDiscountEnabled
        );
    }

    if ($discountAmount > $subtotal) {
        throw new RuntimeException('Discount cannot exceed the sale subtotal.');
    }

    $total = round($subtotal - $discountAmount, 2);
    if ($total < 0) {
        throw new RuntimeException('Sale total cannot be negative.');
    }

    if ($amountTendered < $total) {
        throw new RuntimeException('Payment amount must cover the final total.');
    }

    $invoice = 'INV-' . date('YmdHis');
    $stmt = $pdo->prepare('
        INSERT INTO sales(
            invoice_no, branch_id, user_id, customer_id,
            discount_type, discount_value, discount_amount,
            total_amount, amount_tendered, change_amount, payment_method, status
        )
        VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "completed")
    ');
    $stmt->execute([
        $invoice,
        $branchId,
        $userId,
        $customerId,
        $discountType,
        $discountValue,
        $discountAmount,
        $total,
        $amountTendered,
        round($amountTendered - $total, 2),
        $paymentMethod,
    ]);
    $saleId = (int)$pdo->lastInsertId();

    $saleItemStmt = $pdo->prepare('
        INSERT INTO sale_items(sale_id, product_id, qty, price, subtotal)
        VALUES(?, ?, ?, ?, ?)
    ');
    $stockUpdateStmt = $pdo->prepare('
        UPDATE products
        SET stock_qty = stock_qty - ?
        WHERE id = ? AND branch_id = ? AND stock_qty >= ?
    ');
    $movementStmt = $pdo->prepare('
        INSERT INTO inventory_movements(branch_id, product_id, type, qty, remarks, user_id)
        VALUES(?, ?, "sale", ?, ?, ?)
    ');

    foreach ($validatedItems as $item) {
        $saleItemStmt->execute([
            $saleId,
            $item['product_id'],
            $item['qty'],
            $item['price'],
            $item['subtotal'],
        ]);

        $stockUpdateStmt->execute([$item['qty'], $item['product_id'], $branchId, $item['qty']]);
        if ($stockUpdateStmt->rowCount() !== 1) {
            throw new RuntimeException('Stock could not be deducted for ' . $item['name'] . '.');
        }

        $movementStmt->execute([
            $branchId,
            $item['product_id'],
            -$item['qty'],
            'Sold via ' . $invoice . ($quotation ? ' from quotation ' . $quotation['quote_no'] : ''),
            $userId,
        ]);
    }

    if ($cashSessionId) {
        $pdo->prepare('
            INSERT INTO cash_drawer_transactions(cash_session_id, branch_id, user_id, type, amount, reference, remarks)
            VALUES(?, ?, ?, "sale_cash", ?, ?, ?)
        ')->execute([$cashSessionId, $branchId, $userId, $total, $invoice, 'Cash sale']);
    }

    if ($quotation) {
        $convertedStmt = $pdo->prepare('
            UPDATE quotations
            SET status = "converted", converted_sale_id = ?, converted_at = NOW()
            WHERE id = ? AND branch_id = ? AND status IN ("draft", "issued")
        ');
        $convertedStmt->execute([$saleId, $quotationId, $branchId]);
        if ($convertedStmt->rowCount() !== 1) {
            throw new RuntimeException('Quotation could not be marked as converted.');
        }
    }

    log_activity($pdo, 'complete_sale', 'pos', 'Completed sale ' . $invoice . ' total: ' . number_format($total, 2) . ' discount: ' . number_format($discountAmount, 2) . ($quotation ? ' from quotation ' . $quotation['quote_no'] : ''));
    $pdo->commit();
    header('Location: ' . app_url('sales/receipt.php?id=' . $saleId . '&print=1'));
    exit;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    die('Checkout failed: ' . $e->getMessage());
}
