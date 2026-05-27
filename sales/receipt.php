<?php
$pageTitle = 'Receipt';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../auth/session.php';

require_login();
require_permission($pdo, 'sales.view');

$branchId = current_branch_id();
$id = (int)($_GET['id'] ?? 0);
$autoPrint = isset($_GET['print']) || isset($_GET['autoprint']);

function receipt_money(float $amount, string $currency): string
{
    $symbol = $currency === '&#8369;' ? $currency : htmlspecialchars($currency, ENT_QUOTES, 'UTF-8');
    return $symbol . number_format($amount, 2);
}

function receipt_logo_src(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    if (preg_match('/^https?:\/\//i', $value)) {
        return $value;
    }

    return app_url(ltrim($value, '/'));
}

function receipt_discount_label(array $sale): string
{
    $type = strtolower((string)($sale['discount_type'] ?? ''));
    $value = (float)($sale['discount_value'] ?? 0);

    if ($type === 'percentage') {
        return 'Discount (' . number_format($value, 2) . '%)';
    }

    if ($type === 'fixed') {
        return 'Discount (Fixed)';
    }

    if ($type === 'senior') {
        return 'Senior Discount (' . number_format($value, 2) . '%)';
    }

    if ($type === 'pwd') {
        return 'PWD Discount (' . number_format($value, 2) . '%)';
    }

    return 'Discount';
}

$settingsStmt = $pdo->query('SELECT setting_key, setting_value FROM settings');
$settings = [];
foreach ($settingsStmt as $row) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

$storeName = trim($settings['store_name'] ?? 'POS STORE');
$storeAddress = trim($settings['store_address'] ?? 'Main Branch');
$storePhone = trim($settings['store_phone'] ?? '');
$storeLogo = receipt_logo_src($settings['store_logo_url'] ?? '');
$currencySetting = trim($settings['currency_symbol'] ?? '');
$currency = ($currencySetting === '' || $currencySetting === 'â‚±') ? '&#8369;' : $currencySetting;
$receiptFooter = trim($settings['receipt_footer'] ?? 'Thank you for shopping!');
$printerWidth = (int)($settings['thermal_printer_width_mm'] ?? 58);
$paperClass = $printerWidth === 80 ? 'thermal-80' : 'thermal-58';

$saleStmt = $pdo->prepare('
    SELECT
        s.*,
        u.name AS cashier_name,
        c.name AS customer_name,
        b.name AS branch_name,
        b.code AS branch_code
    FROM sales s
    LEFT JOIN users u ON u.id = s.user_id
    LEFT JOIN customers c ON c.id = s.customer_id AND c.branch_id = s.branch_id
    LEFT JOIN branches b ON b.id = s.branch_id
    WHERE s.id = ? AND s.branch_id = ?
');
$saleStmt->execute([$id, $branchId]);
$sale = $saleStmt->fetch();

if (!$sale) {
    http_response_code(404);
    include __DIR__ . '/../includes/header.php';
    echo '<div class="alert alert-danger">Sale receipt was not found for this branch.</div>';
    echo '<a class="btn btn-outline-secondary" href="' . app_url('sales/index.php') . '"><i class="bi bi-arrow-left"></i> Back to Sales</a>';
    include __DIR__ . '/../includes/footer.php';
    exit;
}

$itemStmt = $pdo->prepare('
    SELECT
        si.*,
        p.name,
        p.barcode,
        p.sku,
        COALESCE(returned.returned_qty, 0) AS returned_qty,
        COALESCE(returned.returned_amount, 0) AS returned_amount
    FROM sale_items si
    JOIN products p ON p.id = si.product_id
    LEFT JOIN (
        SELECT
            sri.sale_item_id,
            SUM(sri.qty) AS returned_qty,
            SUM(sri.subtotal) AS returned_amount
        FROM sales_return_items sri
        JOIN sales_returns sr ON sr.id = sri.return_id
        WHERE sr.sale_id = ? AND sr.branch_id = ?
        GROUP BY sri.sale_item_id
    ) returned ON returned.sale_item_id = si.id
    WHERE si.sale_id = ?
    ORDER BY si.id
');
$itemStmt->execute([$id, $branchId, $id]);
$items = $itemStmt->fetchAll();

$returnStmt = $pdo->prepare('
    SELECT sr.*, u.name AS processed_by
    FROM sales_returns sr
    LEFT JOIN users u ON u.id = sr.user_id
    WHERE sr.sale_id = ? AND sr.branch_id = ?
    ORDER BY sr.created_at
');
$returnStmt->execute([$id, $branchId]);
$returns = $returnStmt->fetchAll();

$itemsSubtotal = 0.0;
$returnedQty = 0;
$refundTotal = 0.0;
foreach ($items as $item) {
    $itemsSubtotal += (float)$item['subtotal'];
    $returnedQty += (int)$item['returned_qty'];
    $refundTotal += (float)$item['returned_amount'];
}

$discount = (float)($sale['discount_amount'] ?? 0);
if ($discount <= 0 && $itemsSubtotal > (float)$sale['total_amount']) {
    $discount = $itemsSubtotal - (float)$sale['total_amount'];
}

$netAfterReturns = max(0, (float)$sale['total_amount'] - $refundTotal);

include __DIR__ . '/../includes/header.php';
?>

<div class="d-print-none d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-3 receipt-actions">
    <div>
        <h4 class="mb-0">Receipt</h4>
        <small class="text-muted">Invoice <?= htmlspecialchars($sale['invoice_no']) ?></small>
    </div>
    <div class="btn-group">
        <a class="btn btn-outline-secondary" href="<?= app_url('sales/index.php') ?>">
            <i class="bi bi-arrow-left"></i> Sales
        </a>
        <a class="btn btn-outline-primary" href="<?= app_url('sales/receipt.php?id=' . (int)$sale['id'] . '&print=1') ?>">
            <i class="bi bi-printer"></i> Auto Print
        </a>
        <button class="btn btn-primary" type="button" onclick="window.print()">
            <i class="bi bi-printer-fill"></i> Print Receipt
        </button>
    </div>
</div>

<div class="thermal-page <?= $paperClass ?> mx-auto">
    <div class="thermal-receipt">
        <div class="receipt-header text-center">
            <?php if ($storeLogo): ?>
                <img class="receipt-logo" src="<?= htmlspecialchars($storeLogo) ?>" alt="<?= htmlspecialchars($storeName) ?>">
            <?php endif; ?>
            <div class="store-name"><?= htmlspecialchars($storeName) ?></div>
            <?php if ($storeAddress): ?>
                <div><?= htmlspecialchars($storeAddress) ?></div>
            <?php endif; ?>
            <?php if ($storePhone): ?>
                <div>Tel: <?= htmlspecialchars($storePhone) ?></div>
            <?php endif; ?>
            <div class="receipt-title">CUSTOMER RECEIPT</div>
        </div>

        <div class="receipt-line"></div>

        <div class="receipt-meta">
            <div class="receipt-row">
                <span>Receipt #</span>
                <strong><?= htmlspecialchars($sale['invoice_no']) ?></strong>
            </div>
            <div class="receipt-row">
                <span>Branch</span>
                <strong><?= htmlspecialchars($sale['branch_name'] ?? ('Branch #' . $branchId)) ?></strong>
            </div>
            <div class="receipt-row">
                <span>Date/Time</span>
                <strong><?= htmlspecialchars(date('M d, Y h:i A', strtotime($sale['created_at']))) ?></strong>
            </div>
            <div class="receipt-row">
                <span>Cashier</span>
                <strong><?= htmlspecialchars($sale['cashier_name'] ?? 'N/A') ?></strong>
            </div>
            <div class="receipt-row">
                <span>Customer</span>
                <strong><?= htmlspecialchars($sale['customer_name'] ?? 'Walk-in Customer') ?></strong>
            </div>
            <div class="receipt-row">
                <span>Payment</span>
                <strong><?= htmlspecialchars($sale['payment_method']) ?></strong>
            </div>
        </div>

        <div class="receipt-line"></div>

        <?php foreach ($items as $item): ?>
            <div class="receipt-item">
                <div class="item-name"><?= htmlspecialchars($item['name']) ?></div>
                <?php if (!empty($item['sku']) || !empty($item['barcode'])): ?>
                    <div class="item-barcode">
                        <?= htmlspecialchars($item['sku'] ?: $item['barcode']) ?>
                    </div>
                <?php endif; ?>
                <div class="receipt-row">
                    <span><?= (int)$item['qty'] ?> x <?= receipt_money((float)$item['price'], $currency) ?></span>
                    <strong><?= receipt_money((float)$item['subtotal'], $currency) ?></strong>
                </div>
                <?php if ((int)$item['returned_qty'] > 0): ?>
                    <div class="receipt-row muted-row">
                        <span>Returned <?= (int)$item['returned_qty'] ?></span>
                        <span>-<?= receipt_money((float)$item['returned_amount'], $currency) ?></span>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>

        <div class="receipt-line"></div>

        <div class="receipt-totals">
            <div class="receipt-row">
                <span>Subtotal</span>
                <strong><?= receipt_money($itemsSubtotal, $currency) ?></strong>
            </div>
            <?php if ($discount > 0): ?>
                <div class="receipt-row">
                    <span><?= htmlspecialchars(receipt_discount_label($sale)) ?></span>
                    <strong>-<?= receipt_money($discount, $currency) ?></strong>
                </div>
            <?php endif; ?>
            <div class="receipt-row total-row">
                <span><?= $discount > 0 ? 'Final Total' : 'Total' ?></span>
                <strong><?= receipt_money((float)$sale['total_amount'], $currency) ?></strong>
            </div>
            <?php if ($refundTotal > 0): ?>
                <div class="receipt-row">
                    <span>Refunded</span>
                    <strong>-<?= receipt_money($refundTotal, $currency) ?></strong>
                </div>
                <div class="receipt-row">
                    <span>Net After Returns</span>
                    <strong><?= receipt_money($netAfterReturns, $currency) ?></strong>
                </div>
            <?php endif; ?>
            <div class="receipt-row">
                <span>Tendered</span>
                <strong><?= receipt_money((float)$sale['amount_tendered'], $currency) ?></strong>
            </div>
            <div class="receipt-row">
                <span>Change</span>
                <strong><?= receipt_money((float)$sale['change_amount'], $currency) ?></strong>
            </div>
        </div>

        <?php if ($returns): ?>
            <div class="receipt-line"></div>
            <div class="receipt-section-title">Returns / Refunds</div>
            <?php foreach ($returns as $return): ?>
                <div class="receipt-row muted-row">
                    <span>#<?= (int)$return['id'] ?> <?= htmlspecialchars(date('M d h:i A', strtotime($return['created_at']))) ?></span>
                    <strong><?= receipt_money((float)$return['refund_amount'], $currency) ?></strong>
                </div>
                <div class="receipt-note"><?= htmlspecialchars($return['reason']) ?></div>
            <?php endforeach; ?>
            <div class="receipt-note">Total returned units: <?= $returnedQty ?></div>
        <?php endif; ?>

        <div class="receipt-line"></div>
        <?php if ($receiptFooter): ?>
            <div class="text-center footer-note"><?= nl2br(htmlspecialchars($receiptFooter)) ?></div>
        <?php endif; ?>
        <div class="text-center receipt-note mt-2">Printed: <?= htmlspecialchars(date('M d, Y h:i A')) ?></div>
    </div>
</div>

<?php if ($autoPrint): ?>
    <script>
    window.addEventListener('load', () => {
        window.setTimeout(() => window.print(), 350);
    });
    </script>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
