<?php
$pageTitle = 'Settings';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/header.php';

$allowedKeys = [
    'store_name',
    'store_address',
    'store_phone',
    'currency_symbol',
    'receipt_footer',
    'tax_rate',
    'low_stock_threshold',
    'thermal_printer_width_mm',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($allowedKeys as $key) {
        $value = trim($_POST[$key] ?? '');
        $stmt = $pdo->prepare('
            INSERT INTO settings(setting_key, setting_value)
            VALUES(?, ?)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
        ');
        $stmt->execute([$key, $value]);
    }
    echo '<div class="alert alert-success">Settings saved successfully.</div>';
}

$settings = [];
$stmt = $pdo->query('SELECT setting_key, setting_value FROM settings');
foreach ($stmt as $row) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

function setting_value(array $settings, string $key, string $default = ''): string
{
    return htmlspecialchars($settings[$key] ?? $default, ENT_QUOTES, 'UTF-8');
}
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h4 class="mb-0">Settings</h4>
        <small class="text-muted">Store profile, receipt, and thermal printer settings.</small>
    </div>
</div>

<form method="post" class="table-card">
    <div class="row g-3">
        <div class="col-md-6">
            <label class="form-label">Store Name</label>
            <input type="text" name="store_name" class="form-control" value="<?= setting_value($settings, 'store_name', 'POS STORE') ?>">
        </div>
        <div class="col-md-6">
            <label class="form-label">Store Phone</label>
            <input type="text" name="store_phone" class="form-control" value="<?= setting_value($settings, 'store_phone') ?>">
        </div>
        <div class="col-12">
            <label class="form-label">Store Address</label>
            <input type="text" name="store_address" class="form-control" value="<?= setting_value($settings, 'store_address', 'Main Branch') ?>">
        </div>
        <div class="col-md-3">
            <label class="form-label">Currency Symbol</label>
            <input type="text" name="currency_symbol" class="form-control" value="<?= setting_value($settings, 'currency_symbol', '₱') ?>">
        </div>
        <div class="col-md-3">
            <label class="form-label">Tax Rate (%)</label>
            <input type="number" step="0.01" name="tax_rate" class="form-control" value="<?= setting_value($settings, 'tax_rate', '0') ?>">
        </div>
        <div class="col-md-3">
            <label class="form-label">Default Low Stock Threshold</label>
            <input type="number" name="low_stock_threshold" class="form-control" value="<?= setting_value($settings, 'low_stock_threshold', '5') ?>">
        </div>
        <div class="col-md-3">
            <label class="form-label">Thermal Printer Width</label>
            <select name="thermal_printer_width_mm" class="form-select">
                <?php $width = $settings['thermal_printer_width_mm'] ?? '58'; ?>
                <option value="58" <?= $width === '58' ? 'selected' : '' ?>>58mm</option>
                <option value="80" <?= $width === '80' ? 'selected' : '' ?>>80mm</option>
            </select>
        </div>
        <div class="col-12">
            <label class="form-label">Receipt Footer</label>
            <textarea name="receipt_footer" class="form-control" rows="3"><?= setting_value($settings, 'receipt_footer', 'Thank you for shopping!') ?></textarea>
        </div>
    </div>
    <button class="btn btn-primary mt-4"><i class="bi bi-save"></i> Save Settings</button>
</form>

<div class="table-card mt-3">
    <h6>Thermal Receipt Test</h6>
    <p class="text-muted mb-2">Open any completed sale receipt, then click Print Receipt. Browser print settings should use the matching paper width.</p>
    <a href="/pos_phase_16/sales/index.php" class="btn btn-outline-primary btn-sm">Go to Sales History</a>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
