<?php
$pageTitle = 'Branches';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../auth/session.php';

require_login();
require_permission($pdo, 'branches.manage');

$currentBranchId = current_branch_id();
$search = trim($_GET['q'] ?? '');

$where = [];
$params = [];

if ($search !== '') {
    $where[] = '(name LIKE ? OR code LIKE ? OR address LIKE ?)';
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$sql = 'SELECT id, name, code, address, created_at FROM branches';
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY name ASC LIMIT 300';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$branches = $stmt->fetchAll();

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-3">
    <div>
        <h4 class="mb-0">Branches</h4>
        <small class="text-muted">Manage store branches used for user access and reporting.</small>
    </div>
    <a class="btn btn-primary" href="<?= app_url('branches/add.php') ?>">
        <i class="bi bi-plus-lg me-1"></i>
        Add Branch
    </a>
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
                placeholder="Search by branch name, code, or address"
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
        <h5 class="mb-0">Branch List</h5>
        <span class="badge text-bg-light"><?= count($branches) ?> shown</span>
    </div>

    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Name</th>
                    <th>Code</th>
                    <th>Address</th>
                    <th>Created</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($branches as $branch): ?>
                    <?php $isCurrent = (int)$branch['id'] === $currentBranchId; ?>
                    <tr>
                        <td class="fw-semibold">
                            <?= htmlspecialchars($branch['name']) ?>
                            <?php if ($isCurrent): ?>
                                <span class="badge text-bg-primary ms-2">Current</span>
                            <?php endif; ?>
                        </td>
                        <td><code><?= htmlspecialchars($branch['code']) ?></code></td>
                        <td><?= htmlspecialchars($branch['address'] ?: '-') ?></td>
                        <td><?= htmlspecialchars(date('M d, Y', strtotime($branch['created_at']))) ?></td>
                        <td class="text-end">
                            <div class="btn-group btn-group-sm" role="group" aria-label="Branch actions">
                                <a class="btn btn-outline-primary" href="<?= app_url('branches/edit.php?id=' . (int)$branch['id']) ?>">
                                    <i class="bi bi-pencil-square"></i>
                                </a>
                                <?php if ($isCurrent): ?>
                                    <button class="btn btn-outline-secondary" type="button" disabled title="Current branch cannot be deleted">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                <?php else: ?>
                                    <a class="btn btn-outline-danger" href="<?= app_url('branches/delete.php?id=' . (int)$branch['id']) ?>">
                                        <i class="bi bi-trash"></i>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>

                <?php if (!$branches): ?>
                    <tr>
                        <td colspan="5" class="text-center text-muted py-4">
                            No branches found.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
