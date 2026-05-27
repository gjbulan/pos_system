<?php
$pageTitle = 'Customers';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../auth/session.php';

require_login();
require_permission($pdo, 'customers.view');

$branchId = current_branch_id();
$search = trim($_GET['q'] ?? '');
$canManageCustomers = can($pdo, 'customers.manage');

$where = ['branch_id = ?'];
$params = [$branchId];

if ($search !== '') {
    $where[] = '(name LIKE ? OR phone LIKE ? OR email LIKE ?)';
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$stmt = $pdo->prepare(
    'SELECT id, name, phone, email, created_at
     FROM customers
     WHERE ' . implode(' AND ', $where) . '
     ORDER BY name ASC
     LIMIT 300'
);
$stmt->execute($params);
$customers = $stmt->fetchAll();

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-3">
    <div>
        <h4 class="mb-0">Customers</h4>
        <small class="text-muted">Branch customer records and contact details.</small>
    </div>
    <?php if ($canManageCustomers): ?>
        <a class="btn btn-primary" href="<?= app_url('customers/add.php') ?>">
            <i class="bi bi-plus-lg me-1"></i>
            Add Customer
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
    <form class="row g-2 align-items-end" method="get">
        <div class="col-md-9">
            <label class="form-label">Search</label>
            <input
                type="search"
                name="q"
                class="form-control"
                placeholder="Search by name, phone, or email"
                value="<?= htmlspecialchars($search) ?>"
            >
        </div>
        <div class="col-md-3 d-grid">
            <button class="btn btn-outline-primary" type="submit">
                <i class="bi bi-search me-1"></i>
                Search
            </button>
        </div>
    </form>
</div>

<div class="table-card">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="mb-0">Customer List</h5>
        <span class="badge text-bg-light"><?= count($customers) ?> shown</span>
    </div>

    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Name</th>
                    <th>Phone</th>
                    <th>Email</th>
                    <th>Created</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($customers as $customer): ?>
                    <tr>
                        <td class="fw-semibold"><?= htmlspecialchars($customer['name']) ?></td>
                        <td><?= htmlspecialchars($customer['phone'] ?: '-') ?></td>
                        <td><?= htmlspecialchars($customer['email'] ?: '-') ?></td>
                        <td><?= htmlspecialchars(date('M d, Y', strtotime($customer['created_at']))) ?></td>
                        <td class="text-end">
                            <div class="btn-group btn-group-sm" role="group" aria-label="Customer actions">
                                <a class="btn btn-outline-secondary" href="<?= app_url('customers/view.php?id=' . (int)$customer['id']) ?>">
                                    View
                                </a>
                                <?php if ($canManageCustomers): ?>
                                    <a class="btn btn-outline-primary" href="<?= app_url('customers/edit.php?id=' . (int)$customer['id']) ?>">
                                        <i class="bi bi-pencil-square"></i>
                                    </a>
                                    <a class="btn btn-outline-danger" href="<?= app_url('customers/delete.php?id=' . (int)$customer['id']) ?>">
                                        <i class="bi bi-trash"></i>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>

                <?php if (!$customers): ?>
                    <tr>
                        <td colspan="5" class="text-center text-muted py-4">
                            No customers found for this branch.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
