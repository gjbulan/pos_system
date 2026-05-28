<?php
$pageTitle = 'View Quotation';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../auth/session.php';
require_once __DIR__ . '/helpers.php';

require_login();
require_permission($pdo, 'quotations.view');

$branchId = current_branch_id();
$quotationId = (int)($_GET['id'] ?? 0);
$canManageQuotations = can($pdo, 'quotations.manage');
$canUsePos = can($pdo, 'pos.access');

$quoteStmt = $pdo->prepare('
    SELECT
        q.*,
        b.name AS branch_name,
        b.code AS branch_code,
        c.name AS customer_name,
        c.phone AS customer_phone,
        c.email AS customer_email,
        u.name AS created_by_name,
        s.invoice_no AS converted_invoice_no
    FROM quotations q
    JOIN branches b ON b.id = q.branch_id
    LEFT JOIN customers c ON c.id = q.customer_id
    LEFT JOIN users u ON u.id = q.user_id
    LEFT JOIN sales s ON s.id = q.converted_sale_id
    WHERE q.id = ? AND q.branch_id = ?
    LIMIT 1
');
$quoteStmt->execute([$quotationId, $branchId]);
$quote = $quoteStmt->fetch();

if (!$quote) {
    http_response_code(404);
    include __DIR__ . '/../includes/header.php';
    echo '<div class="alert alert-danger">Quotation not found.</div>';
    include __DIR__ . '/../includes/footer.php';
    exit;
}

$itemsStmt = $pdo->prepare('
    SELECT
        qi.*,
        p.name AS product_name,
        p.sku,
        p.barcode,
        p.stock_qty
    FROM quotation_items qi
    JOIN products p ON p.id = qi.product_id
    WHERE qi.quotation_id = ?
    ORDER BY qi.id
');
$itemsStmt->execute([$quotationId]);
$items = $itemsStmt->fetchAll();

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$isConvertible = in_array($quote['status'], ['draft', 'issued'], true);

include __DIR__ . '/../includes/header.php';
?>

<style>
@media print {
    .sidebar,
    .navbar,
    .mobile-overlay,
    .no-print {
        display: none !important;
    }

    .app-shell,
    .main-content,
    .page-container {
        display: block !important;
        margin: 0 !important;
        padding: 0 !important;
        width: 100% !important;
    }

    .table-card {
        box-shadow: none !important;
        border: 0 !important;
    }
}
</style>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-3 no-print">
    <div>
        <h4 class="mb-0">Quotation <?= htmlspecialchars($quote['quote_no']) ?></h4>
        <small class="text-muted">Preview, print, or convert this quotation to a POS sale.</small>
    </div>
    <div class="btn-group">
        <a class="btn btn-outline-secondary" href="<?= app_url('quotations/index.php') ?>">
            <i class="bi bi-arrow-left"></i> Quotations
        </a>
        <button class="btn btn-outline-secondary" type="button" onclick="window.print()">
            <i class="bi bi-printer"></i> Print
        </button>
        <?php if ($canManageQuotations && $isConvertible): ?>
            <a class="btn btn-outline-primary" href="<?= app_url('quotations/edit.php?id=' . $quotationId) ?>">
                <i class="bi bi-pencil"></i> Edit
            </a>
        <?php endif; ?>
        <?php if ($canUsePos && $isConvertible): ?>
            <a class="btn btn-success" href="<?= app_url('pos/index.php?quote_id=' . $quotationId) ?>">
                <i class="bi bi-cart-check"></i> Convert to POS Sale
            </a>
        <?php endif; ?>
    </div>
</div>

<?php if ($flash): ?>
    <div class="alert alert-<?= htmlspecialchars($flash['type']) ?> alert-dismissible fade show no-print" role="alert">
        <?= htmlspecialchars($flash['message']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<div class="table-card">
    <div class="d-flex justify-content-between align-items-start gap-3 mb-4">
        <div>
            <h3 class="mb-1">Quotation</h3>
            <div class="text-muted"><?= htmlspecialchars($quote['branch_name'] . ' (' . $quote['branch_code'] . ')') ?></div>
        </div>
        <div class="text-end">
            <h5 class="mb-1"><?= htmlspecialchars($quote['quote_no']) ?></h5>
            <span class="badge text-bg-<?= quotation_status_badge($quote['status']) ?>">
                <?= htmlspecialchars(ucfirst($quote['status'])) ?>
            </span>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <small class="text-muted d-block">Customer</small>
            <strong><?= htmlspecialchars($quote['customer_name'] ?? 'Walk-in Customer') ?></strong>
            <?php if (!empty($quote['customer_phone']) || !empty($quote['customer_email'])): ?>
                <div class="text-muted small">
                    <?= htmlspecialchars($quote['customer_phone'] ?: '') ?>
                    <?php if (!empty($quote['customer_email'])): ?>
                        <?= $quote['customer_phone'] ? ' - ' : '' ?><?= htmlspecialchars($quote['customer_email']) ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
        <div class="col-md-4">
            <small class="text-muted d-block">Created By</small>
            <strong><?= htmlspecialchars($quote['created_by_name'] ?? 'N/A') ?></strong>
        </div>
        <div class="col-md-4">
            <small class="text-muted d-block">Created At</small>
            <strong><?= htmlspecialchars($quote['created_at']) ?></strong>
        </div>
        <div class="col-md-4">
            <small class="text-muted d-block">Valid Until</small>
            <strong><?= htmlspecialchars($quote['valid_until'] ?: '-') ?></strong>
        </div>
        <div class="col-md-4">
            <small class="text-muted d-block">Converted Sale</small>
            <?php if (!empty($quote['converted_sale_id'])): ?>
                <a class="fw-semibold" href="<?= app_url('sales/receipt.php?id=' . (int)$quote['converted_sale_id']) ?>">
                    <?= htmlspecialchars($quote['converted_invoice_no'] ?? ('Sale #' . $quote['converted_sale_id'])) ?>
                </a>
            <?php else: ?>
                <strong>-</strong>
            <?php endif; ?>
        </div>
        <div class="col-md-4">
            <small class="text-muted d-block">Converted At</small>
            <strong><?= htmlspecialchars($quote['converted_at'] ?? '-') ?></strong>
        </div>
    </div>

    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead class="table-light">
                <tr>
                    <th>Product</th>
                    <th>SKU / Barcode</th>
                    <th class="text-end">Qty</th>
                    <th class="text-end">Quoted Price</th>
                    <th class="text-end">Line Total</th>
                    <th class="text-end no-print">Current Stock</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $item): ?>
                    <tr>
                        <td class="fw-semibold"><?= htmlspecialchars($item['product_name']) ?></td>
                        <td>
                            <?= htmlspecialchars($item['sku'] ?: '-') ?>
                            <?php if (!empty($item['barcode'])): ?>
                                <span class="text-muted">/ <?= htmlspecialchars($item['barcode']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end"><?= (int)$item['qty'] ?></td>
                        <td class="text-end"><?= number_format((float)$item['price'], 2) ?></td>
                        <td class="text-end"><?= number_format((float)$item['subtotal'], 2) ?></td>
                        <td class="text-end no-print"><?= (int)$item['stock_qty'] ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$items): ?>
                    <tr>
                        <td colspan="6" class="text-center text-muted py-4">No items found for this quotation.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="row justify-content-end">
        <div class="col-md-5 col-lg-4">
            <div class="d-flex justify-content-between border-bottom py-2">
                <span>Subtotal</span>
                <strong><?= number_format((float)$quote['subtotal_amount'], 2) ?></strong>
            </div>
            <div class="d-flex justify-content-between border-bottom py-2 text-success">
                <span>
                    Discount
                    <?php if (!empty($quote['discount_type'])): ?>
                        <small class="text-muted">(<?= htmlspecialchars(ucfirst($quote['discount_type'])) ?>)</small>
                    <?php endif; ?>
                </span>
                <strong>-<?= number_format((float)$quote['discount_amount'], 2) ?></strong>
            </div>
            <div class="d-flex justify-content-between fs-5 pt-2">
                <strong>Total</strong>
                <strong><?= number_format((float)$quote['total_amount'], 2) ?></strong>
            </div>
        </div>
    </div>

    <?php if (!empty($quote['notes'])): ?>
        <hr>
        <h6>Notes</h6>
        <p class="mb-0"><?= nl2br(htmlspecialchars($quote['notes'])) ?></p>
    <?php endif; ?>
</div>

<?php if ($canManageQuotations && $isConvertible): ?>
    <form method="post" action="<?= app_url('quotations/cancel.php') ?>" class="mt-3 text-end no-print" onsubmit="return confirm('Cancel this quotation?');">
        <input type="hidden" name="id" value="<?= $quotationId ?>">
        <button class="btn btn-outline-danger">
            <i class="bi bi-x-circle"></i> Cancel Quotation
        </button>
    </form>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
