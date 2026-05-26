<?php
$pageTitle = 'Receipt';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/header.php';

$branchId = current_branch_id();
$id = (int)($_GET['id'] ?? 0);

$saleStmt = $pdo->prepare('
    SELECT s.*, u.name AS cashier_name
    FROM sales s
    LEFT JOIN users u ON u.id = s.user_id
    WHERE s.id = ? AND s.branch_id = ?
');
$saleStmt->execute([$id, $branchId]);
$sale = $saleStmt->fetch();

if (!$sale) {
    die('Sale not found');
}

$itemStmt = $pdo->prepare('
    SELECT si.*, p.name, p.barcode
    FROM sale_items si
    JOIN products p ON p.id = si.product_id
    WHERE si.sale_id = ?
');
$itemStmt->execute([$id]);
$items = $itemStmt->fetchAll();

$settingsStmt = $pdo->query('SELECT setting_key, setting_value FROM settings');
$settings = [];
foreach ($settingsStmt as $row) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

$storeName = $settings['store_name'] ?? 'POS STORE';
$storeAddress = $settings['store_address'] ?? 'Main Branch';
$storePhone = $settings['store_phone'] ?? '';
$currency = $settings['currency_symbol'] ?? '₱';
$receiptFooter = $settings['receipt_footer'] ?? 'Thank you for shopping!';
$printerWidth = (int)($settings['thermal_printer_width_mm'] ?? 58);
$paperClass = $printerWidth === 80 ? 'thermal-80' : 'thermal-58';
?>
<div class="d-print-none d-flex justify-content-between align-items-center mb-3">
    <div>
        <h4 class="mb-0">Receipt</h4>
        <small class="text-muted">Invoice <?= htmlspecialchars($sale['invoice_no']) ?></small>
    </div>
    <div class="btn-group">
        <button class="btn btn-outline-secondary" onclick="window.location.href='<?= app_url('sales/index.php') ?>'">
            <i class="bi bi-arrow-left"></i> Sales
        </button>
        <button class="btn btn-primary" onclick="window.print()">
            <i class="bi bi-printer"></i> Print Receipt
        </button>
    </div>
</div>

<div class="thermal-page <?= $paperClass ?> mx-auto">
    <div class="thermal-receipt">
        <div class="text-center">
            <h6 class="store-name mb-1"><?= htmlspecialchars($storeName) ?></h6>
            <div><?= htmlspecialchars($storeAddress) ?></div>
            <?php if ($storePhone): ?>
                <div>Tel: <?= htmlspecialchars($storePhone) ?></div>
            <?php endif; ?>
            <div class="mt-2">OFFICIAL RECEIPT</div>
        </div>

        <div class="receipt-line"></div>

        <div>Invoice: <?= htmlspecialchars($sale['invoice_no']) ?></div>
        <div>Date: <?= htmlspecialchars($sale['created_at']) ?></div>
        <div>Cashier: <?= htmlspecialchars($sale['cashier_name'] ?? 'N/A') ?></div>
        <div>Payment: <?= htmlspecialchars($sale['payment_method']) ?></div>

        <div class="receipt-line"></div>

        <?php foreach ($items as $item): ?>
            <div class="receipt-item">
                <div class="item-name"><?= htmlspecialchars($item['name']) ?></div>
                <?php if (!empty($item['barcode'])): ?>
                    <div class="item-barcode">Barcode: <?= htmlspecialchars($item['barcode']) ?></div>
                <?php endif; ?>
                <div class="d-flex justify-content-between">
                    <span><?= (int)$item['qty'] ?> x <?= $currency ?><?= number_format((float)$item['price'], 2) ?></span>
                    <span><?= $currency ?><?= number_format((float)$item['subtotal'], 2) ?></span>
                </div>
            </div>
        <?php endforeach; ?>

        <div class="receipt-line"></div>

        <div class="d-flex justify-content-between total-row">
            <span>TOTAL</span>
            <span><?= $currency ?><?= number_format((float)$sale['total_amount'], 2) ?></span>
        </div>
        <div class="d-flex justify-content-between">
            <span>Tendered</span>
            <span><?= $currency ?><?= number_format((float)$sale['amount_tendered'], 2) ?></span>
        </div>
        <div class="d-flex justify-content-between">
            <span>Change</span>
            <span><?= $currency ?><?= number_format((float)$sale['change_amount'], 2) ?></span>
        </div>

        <div class="receipt-line"></div>
        <div class="text-center footer-note"><?= nl2br(htmlspecialchars($receiptFooter)) ?></div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
