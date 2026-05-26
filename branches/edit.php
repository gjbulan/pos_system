<?php
$pageTitle = 'Edit Branch';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../auth/session.php';

require_login();
require_permission($pdo, 'branches.manage');

if (!function_exists('normalize_branch_code')) {
    function normalize_branch_code(string $code): string
    {
        return strtoupper(trim($code));
    }
}

$id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);

if ($id <= 0) {
    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Branch was not found.'];
    redirect_to('branches/index.php');
}

$fetchStmt = $pdo->prepare(
    'SELECT id, name, code, address
     FROM branches
     WHERE id = ?'
);
$fetchStmt->execute([$id]);
$branch = $fetchStmt->fetch();

if (!$branch) {
    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Branch was not found.'];
    redirect_to('branches/index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $code = normalize_branch_code($_POST['code'] ?? '');
    $address = trim($_POST['address'] ?? '');

    $_SESSION['branch_old'] = [
        'name' => $name,
        'code' => $code,
        'address' => $address,
    ];

    if ($name === '') {
        $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Branch name is required.'];
        redirect_to('branches/edit.php?id=' . $id);
    }

    if ($code === '') {
        $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Branch code is required.'];
        redirect_to('branches/edit.php?id=' . $id);
    }

    if (strlen($name) > 120 || strlen($code) > 50 || strlen($address) > 255) {
        $_SESSION['flash'] = ['type' => 'danger', 'message' => 'One or more fields exceed the allowed length.'];
        redirect_to('branches/edit.php?id=' . $id);
    }

    $nameStmt = $pdo->prepare('SELECT COUNT(*) FROM branches WHERE LOWER(name) = LOWER(?) AND id <> ?');
    $nameStmt->execute([$name, $id]);
    if ((int)$nameStmt->fetchColumn() > 0) {
        $_SESSION['flash'] = ['type' => 'danger', 'message' => 'A branch with this name already exists.'];
        redirect_to('branches/edit.php?id=' . $id);
    }

    $codeStmt = $pdo->prepare('SELECT COUNT(*) FROM branches WHERE code = ? AND id <> ?');
    $codeStmt->execute([$code, $id]);
    if ((int)$codeStmt->fetchColumn() > 0) {
        $_SESSION['flash'] = ['type' => 'danger', 'message' => 'A branch with this code already exists.'];
        redirect_to('branches/edit.php?id=' . $id);
    }

    try {
        $stmt = $pdo->prepare(
            'UPDATE branches
             SET name = ?, code = ?, address = ?
             WHERE id = ?'
        );
        $stmt->execute([
            $name,
            $code,
            $address !== '' ? $address : null,
            $id,
        ]);

        unset($_SESSION['branch_old']);
        log_activity($pdo, 'update', 'branches', 'Updated branch: ' . $name . ' (' . $code . ')');
        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Branch updated successfully.'];
        redirect_to('branches/index.php');
    } catch (Throwable $e) {
        $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Unable to update branch. Please try again.'];
        redirect_to('branches/edit.php?id=' . $id);
    }
}

$old = $_SESSION['branch_old'] ?? $branch;
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['branch_old'], $_SESSION['flash']);

include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h4 class="mb-0">Edit Branch</h4>
        <small class="text-muted">Update branch profile details.</small>
    </div>
    <a class="btn btn-outline-secondary" href="<?= app_url('branches/index.php') ?>">
        <i class="bi bi-arrow-left me-1"></i>
        Back
    </a>
</div>

<?php if ($flash): ?>
    <div class="alert alert-<?= htmlspecialchars($flash['type']) ?> alert-dismissible fade show" role="alert">
        <?= htmlspecialchars($flash['message']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<form method="post" class="table-card">
    <input type="hidden" name="id" value="<?= (int)$branch['id'] ?>">

    <div class="row g-3">
        <div class="col-md-6">
            <label class="form-label">Branch Name <span class="text-danger">*</span></label>
            <input
                type="text"
                name="name"
                class="form-control"
                maxlength="120"
                required
                value="<?= htmlspecialchars($old['name']) ?>"
            >
        </div>
        <div class="col-md-6">
            <label class="form-label">Branch Code <span class="text-danger">*</span></label>
            <input
                type="text"
                name="code"
                class="form-control"
                maxlength="50"
                required
                value="<?= htmlspecialchars($old['code']) ?>"
            >
        </div>
        <div class="col-12">
            <label class="form-label">Address</label>
            <input
                type="text"
                name="address"
                class="form-control"
                maxlength="255"
                value="<?= htmlspecialchars($old['address'] ?? '') ?>"
            >
        </div>
    </div>

    <div class="d-flex justify-content-end gap-2 mt-4">
        <a class="btn btn-outline-secondary" href="<?= app_url('branches/index.php') ?>">Cancel</a>
        <button class="btn btn-primary" type="submit">
            <i class="bi bi-save me-1"></i>
            Save Changes
        </button>
    </div>
</form>

<?php include __DIR__ . '/../includes/footer.php'; ?>
