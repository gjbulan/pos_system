<?php
$pageTitle = 'Quotations';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../auth/session.php';
require_once __DIR__ . '/helpers.php';

require_login();
require_permission($pdo, 'quotations.view');

$branchId = current_branch_id();
$canManageQuotations = can($pdo, 'quotations.manage');
$canUsePos = can($pdo, 'pos.access');
$statusFilter = trim($_GET['status'] ?? '');
$search = trim($_GET['q'] ?? '');
$validStatuses = ['draft', 'issued', 'converted', 'cancelled'];

$where = ['q.branch_id = ?'];
$params = [$branchId];

if ($statusFilter !== '' && in_array($statusFilter, $validStatuses, true)) {
    $where[] = 'q.status = ?';
    $params[] = $statusFilter;
}

if ($search !== '') {
    $where[] = '(q.quote_no LIKE ? OR c.name LIKE ? OR s.invoice_no LIKE ?)';
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$stmt = $pdo->prepare('
    SELECT
        q.*,
        c.name AS customer_name,
        u.name AS created_by_name,
        s.invoice_no AS converted_invoice_no
    FROM quotations q
    LEFT JOIN customers c ON c.id = q.customer_id
    LEFT JOIN users u ON u.id = q.user_id
    LEFT JOIN sales s ON s.id = q.converted_sale_id
    WHERE ' . implode(' AND ', $where) . '
    ORDER BY q.created_at DESC, q.id DESC
    LIMIT 300
');
$stmt->execute($params);
$quotations = $stmt->fetchAll();

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-3">
    <div>
        <h4 class="mb-0">Quotations</h4>
        <small class="text-muted">Prepare customer quotations and convert them to POS sales without retyping items.</small>
    </div>
    <?php if ($canManageQuotations): ?>
        <a class="btn btn-primary" href="<?= app_url('quotations/create.php') ?>">
            <i class="bi bi-plus-lg me-1"></i>
            New Quotation
        </a>
    <?php endif; ?>
</div>

<?php if ($flash): ?>
    <div class="alert alert-<?= htmlspecialchars($flash['type']) ?> alert-dismissible fade show" role="alert">
        <?= htmlspecialchars($flash['message']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<div class="table-card mb-3">
    <form class="row g-2 align-items-end" method="get" action="<?= app_url('quotations/index.php') ?>">
        <div class="col-lg-6 col-md-5">
            <label class="form-label">Search</label>
            <input
                type="search"
                name="q"
                class="form-control"
                placeholder="Search quote number, customer, or invoice"
                value="<?= htmlspecialchars($search) ?>"
            >
        </div>
        <div class="col-lg-3 col-md-4">
            <label class="form-label">Status</label>
            <select name="status" class="form-select">
                <option value="">All statuses</option>
                <?php foreach ($validStatuses as $status): ?>
                    <option value="<?= htmlspecialchars($status) ?>" <?= $statusFilter === $status ? 'selected' : '' ?>>
                        <?= htmlspecialchars(ucfirst($status)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-lg-3 col-md-3 d-grid">
            <button class="btn btn-outline-primary" type="submit">
                <i class="bi bi-search me-1"></i>
                Filter
            </button>
        </div>
    </form>
</div>

<div class="table-card">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="mb-0">Quotation List</h5>
        <span class="badge text-bg-light"><?= count($quotations) ?> shown</span>
    </div>

    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Quote No.</th>
                    <th>Customer</th>
                    <th>Status</th>
                    <th>Valid Until</th>
                    <th>Created By</th>
                    <th class="text-end">Total</th>
                    <th>Converted Sale</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($quotations as $quote): ?>
                    <?php $isConvertible = in_array($quote['status'], ['draft', 'issued'], true); ?>
                    <tr>
                        <td class="fw-semibold"><?= htmlspecialchars($quote['quote_no']) ?></td>
                        <td><?= htmlspecialchars($quote['customer_name'] ?? 'Walk-in Customer') ?></td>
                        <td>
                            <span class="badge text-bg-<?= quotation_status_badge($quote['status']) ?>">
                                <?= htmlspecialchars(ucfirst($quote['status'])) ?>
                            </span>
                        </td>
                        <td><?= htmlspecialchars($quote['valid_until'] ?: '-') ?></td>
                        <td><?= htmlspecialchars($quote['created_by_name'] ?? 'N/A') ?></td>
                        <td class="text-end"><?= number_format((float)$quote['total_amount'], 2) ?></td>
                        <td>
                            <?php if (!empty($quote['converted_sale_id'])): ?>
                                <a href="<?= app_url('sales/receipt.php?id=' . (int)$quote['converted_sale_id']) ?>">
                                    <?= htmlspecialchars($quote['converted_invoice_no'] ?? ('Sale #' . $quote['converted_sale_id'])) ?>
                                </a>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end">
                            <div class="btn-group btn-group-sm">
                                <a class="btn btn-outline-primary" href="<?= app_url('quotations/view.php?id=' . (int)$quote['id']) ?>">
                                    <i class="bi bi-eye"></i>
                                </a>
                                <?php if ($canManageQuotations && $isConvertible): ?>
                                    <a class="btn btn-outline-secondary" href="<?= app_url('quotations/edit.php?id=' . (int)$quote['id']) ?>">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                <?php endif; ?>
                                <?php if ($canUsePos && $isConvertible): ?>
                                    <a class="btn btn-outline-success" href="<?= app_url('pos/index.php?quote_id=' . (int)$quote['id']) ?>" title="Convert to sale">
                                        <i class="bi bi-cart-check"></i>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$quotations): ?>
                    <tr>
                        <td colspan="8" class="text-center text-muted py-4">No quotations found.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
