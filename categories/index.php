<?php
$pageTitle = 'Categories';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../auth/session.php';

require_login();
require_permission($pdo, 'categories.manage');

$branchId = current_branch_id();
$errors = [];
$oldName = '';

function category_name_exists(PDO $pdo, int $branchId, string $name, ?int $excludeId = null): bool
{
    $sql = 'SELECT id FROM categories WHERE branch_id = ? AND LOWER(name) = LOWER(?)';
    $params = [$branchId, $name];

    if ($excludeId !== null) {
        $sql .= ' AND id <> ?';
        $params[] = $excludeId;
    }

    $sql .= ' LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return (bool)$stmt->fetchColumn();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $oldName = trim($_POST['name'] ?? '');

    if ($oldName === '') {
        $errors[] = 'Category name is required.';
    } elseif (strlen($oldName) > 120) {
        $errors[] = 'Category name must not exceed 120 characters.';
    } elseif (category_name_exists($pdo, $branchId, $oldName)) {
        $errors[] = 'A category with this name already exists for this branch.';
    }

    if (!$errors) {
        $stmt = $pdo->prepare('INSERT INTO categories(name, branch_id) VALUES(?, ?)');
        $stmt->execute([$oldName, $branchId]);
        redirect_to('categories/index.php?added=1');
    }
}

if (isset($_GET['delete'])) {
    $stmt = $pdo->prepare('DELETE FROM categories WHERE branch_id = ? AND id = ?');
    $stmt->execute([$branchId, (int)$_GET['delete']]);
    redirect_to('categories/index.php?deleted=1');
}

$stmt = $pdo->prepare('SELECT id, name, created_at FROM categories WHERE branch_id = ? ORDER BY name');
$stmt->execute([$branchId]);
$categories = $stmt->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<?php if (isset($_GET['added'])): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        Category added successfully.
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if (isset($_GET['updated'])): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        Category updated successfully.
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if (isset($_GET['deleted'])): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        Category deleted successfully.
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($errors): ?>
    <div class="alert alert-danger">
        <strong>Category was not saved.</strong>
        <ul class="mb-0">
            <?php foreach ($errors as $error): ?>
                <li><?= htmlspecialchars($error) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="table-card">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-3">
        <div>
            <h5 class="mb-0">Categories</h5>
            <small class="text-muted">Manage product categories for the current branch.</small>
        </div>
        <span class="badge text-bg-light"><?= count($categories) ?> shown</span>
    </div>

    <form method="post" action="<?= app_url('categories/index.php') ?>" class="row g-2 align-items-end mb-3">
        <div class="col-md-9">
            <label class="form-label">Category Name</label>
            <input class="form-control" name="name" placeholder="Category name" value="<?= htmlspecialchars($oldName) ?>" required maxlength="120">
        </div>
        <div class="col-md-3 d-grid">
            <button class="btn btn-primary">Add</button>
        </div>
    </form>

    <table class="table align-middle">
        <thead>
            <tr>
                <th>Name</th>
                <th>Created</th>
                <th class="text-end">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($categories as $category): ?>
                <tr>
                    <td class="fw-semibold"><?= htmlspecialchars($category['name']) ?></td>
                    <td><?= htmlspecialchars(date('M d, Y', strtotime($category['created_at']))) ?></td>
                    <td class="text-end">
                        <div class="btn-group btn-group-sm" role="group" aria-label="Category actions">
                            <a class="btn btn-outline-primary" href="<?= app_url('categories/edit.php?id=' . (int)$category['id']) ?>">
                                <i class="bi bi-pencil-square"></i>
                            </a>
                            <a class="btn btn-outline-danger" href="<?= app_url('categories/index.php?delete=' . (int)$category['id']) ?>" onclick="return confirm('Delete category?')">
                                <i class="bi bi-trash"></i>
                            </a>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>

            <?php if (!$categories): ?>
                <tr>
                    <td colspan="3" class="text-center text-muted py-4">
                        No categories found for this branch.
                    </td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
