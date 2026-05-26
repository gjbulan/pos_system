<?php
$pageTitle = 'Purchase Orders';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../auth/session.php';

require_login();
require_permission($pdo, 'purchases.view');

$branchId = current_branch_id();
$canManagePurchases = can($pdo, 'purchases.manage');
$statusFilter = trim($_GET['status'] ?? '');
$search = trim($_GET['q'] ?? '');
$validStatuses = ['pending', 'partial', 'received'];

$where = ['po.branch_id = ?'];
$params = [$branchId];

if ($statusFilter !== '' && in_array($statusFilter, $validStatuses, true)) {
    $where[] = 'po.status = ?';
    $params[] = $statusFilter;
}

if ($search !== '') {
    $where[] = '(po.po_number LIKE ? OR s.name LIKE ?)';
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
}

$sql = '
    SELECT
        po.*,
        s.name AS supplier_name,
        COALESCE(SUM(poi.qty_ordered), 0) AS total_qty,
        COALESCE(SUM(poi.qty_received), 0) AS received_qty,
        COALESCE(SUM(poi.qty_ordered * poi.cost), 0) AS total_cost
    FROM purchase_orders po
    JOIN suppliers s ON s.id = po.supplier_id
    LEFT JOIN purchase_order_items poi ON poi.purchase_order_id = po.id
    WHERE ' . implode(' AND ', $where) . '
    GROUP BY po.id
    ORDER BY po.po_date DESC, po.id DESC
    LIMIT 300
';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$purchaseOrders = $stmt->fetchAll();

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

function purchase_status_badge(string $status): string
{
    return match ($status) {
        'received' => 'success',
        'partial' => 'warning',
        default => 'secondary',
    };
}

include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-3">
    <div>
        <h4 class="mb-0">Purchase Orders</h4>
        <small class="text-muted">Supplier orders and stock receiving for the current branch.</small>
    </div>
    <?php if ($canManagePurchases): ?>
        <a class="btn btn-primary" href="<?= app_url('purchases/create.php') ?>">
            <i class="bi bi-plus-lg me-1"></i>
            New Purchase Order
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
    <form class="row g-2 align-items-end" method="get" action="<?= app_url('purchases/index.php') ?>">
        <div class="col-lg-6 col-md-5">
            <label class="form-label">Search</label>
            <input
                type="search"
                name="q"
                class="form-control"
                placeholder="Search PO number or supplier"
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
        <h5 class="mb-0">Purchase Order List</h5>
        <span class="badge text-bg-light"><?= count($purchaseOrders) ?> shown</span>
    </div>

    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>PO Number</th>
                    <th>Supplier</th>
                    <th>PO Date</th>
                    <th>Status</th>
                    <th class="text-end">Ordered</th>
                    <th class="text-end">Received</th>
                    <th class="text-end">Total Cost</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($purchaseOrders as $order): ?>
                    <tr>
                        <td class="fw-semibold"><?= htmlspecialchars($order['po_number']) ?></td>
                        <td><?= htmlspecialchars($order['supplier_name']) ?></td>
                        <td><?= htmlspecialchars($order['po_date']) ?></td>
                        <td>
                            <span class="badge text-bg-<?= purchase_status_badge($order['status']) ?>">
                                <?= htmlspecialchars(ucfirst($order['status'])) ?>
                            </span>
                        </td>
                        <td class="text-end"><?= (int)$order['total_qty'] ?></td>
                        <td class="text-end"><?= (int)$order['received_qty'] ?></td>
                        <td class="text-end"><?= number_format((float)$order['total_cost'], 2) ?></td>
                        <td class="text-end">
                            <div class="btn-group btn-group-sm">
                                <a class="btn btn-outline-primary" href="<?= app_url('purchases/view.php?id=' . (int)$order['id']) ?>">
                                    <i class="bi bi-eye"></i>
                                </a>
                                <?php if ($canManagePurchases && $order['status'] !== 'received'): ?>
                                    <a class="btn btn-outline-success" href="<?= app_url('purchases/receive.php?id=' . (int)$order['id']) ?>">
                                        <i class="bi bi-box-arrow-in-down"></i>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$purchaseOrders): ?>
                    <tr>
                        <td colspan="8" class="text-center text-muted py-4">No purchase orders found.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
