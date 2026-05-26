<?php
$pageTitle = 'Edit Category';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../auth/session.php';

require_login();
require_permission($pdo, 'categories.manage');

$branchId = current_branch_id();
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$errors = [];

function category_edit_name_exists(PDO $pdo, int $branchId, string $name, int $excludeId): bool
{
    $stmt = $pdo->prepare('
        SELECT id
        FROM categories
        WHERE branch_id = ? AND LOWER(name) = LOWER(?) AND id <> ?
        LIMIT 1
    ');
    $stmt->execute([$branchId, $name, $excludeId]);

    return (bool)$stmt->fetchColumn();
}

$stmt = $pdo->prepare('SELECT id, name, created_at FROM categories WHERE branch_id = ? AND id = ? LIMIT 1');
$stmt->execute([$branchId, $id]);
$category = $stmt->fetch();

if (!$category) {
    include __DIR__ . '/../includes/header.php';
    ?>
    <div class="alert alert-danger">
        Category was not found for this branch.
    </div>
    <a class="btn btn-outline-secondary" href="<?= app_url('categories/index.php') ?>">
        <i class="bi bi-arrow-left"></i> Back to Categories
    </a>
    <?php
    include __DIR__ . '/../includes/footer.php';
    exit;
}

$name = (string)$category['name'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');

    if ($name === '') {
        $errors[] = 'Category name is required.';
    } elseif (strlen($name) > 120) {
        $errors[] = 'Category name must not exceed 120 characters.';
    } elseif (category_edit_name_exists($pdo, $branchId, $name, $id)) {
        $errors[] = 'A category with this name already exists for this branch.';
    }

    if (!$errors) {
        $stmt = $pdo->prepare('UPDATE categories SET name = ? WHERE branch_id = ? AND id = ?');
        $stmt->execute([$name, $branchId, $id]);
        redirect_to('categories/index.php?updated=1');
    }
}

include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-3">
    <div>
        <h4 class="mb-0">Edit Category</h4>
        <small class="text-muted">Update this branch category.</small>
    </div>
    <a class="btn btn-outline-secondary" href="<?= app_url('categories/index.php') ?>">
        <i class="bi bi-arrow-left"></i> Back to Categories
    </a>
</div>

<?php if ($errors): ?>
    <div class="alert alert-danger">
        <strong>Category was not updated.</strong>
        <ul class="mb-0">
            <?php foreach ($errors as $error): ?>
                <li><?= htmlspecialchars($error) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<form method="post" action="<?= app_url('categories/edit.php?id=' . (int)$category['id']) ?>" class="table-card">
    <input type="hidden" name="id" value="<?= (int)$category['id'] ?>">

    <div class="row g-3">
        <div class="col-md-8">
            <label class="form-label">Category Name</label>
            <input class="form-control" name="name" value="<?= htmlspecialchars($name) ?>" required maxlength="120">
        </div>
        <div class="col-md-4">
            <label class="form-label">Created</label>
            <input class="form-control" value="<?= htmlspecialchars(date('M d, Y', strtotime($category['created_at']))) ?>" disabled>
        </div>
    </div>

    <div class="d-flex justify-content-end gap-2 mt-4">
        <a class="btn btn-outline-secondary" href="<?= app_url('categories/index.php') ?>">Cancel</a>
        <button class="btn btn-primary">
            <i class="bi bi-save"></i> Update Category
        </button>
    </div>
</form>

<?php include __DIR__ . '/../includes/footer.php'; ?>
