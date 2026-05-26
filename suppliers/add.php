<?php
$pageTitle = 'Add Supplier';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../auth/session.php';

require_login();
require_permission($pdo, 'suppliers.manage');

$branchId = current_branch_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');

    $_SESSION['supplier_old'] = [
        'name' => $name,
        'phone' => $phone,
        'email' => $email,
    ];

    if ($name === '') {
        $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Supplier name is required.'];
        redirect_to('suppliers/add.php');
    }

    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Enter a valid email address.'];
        redirect_to('suppliers/add.php');
    }

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO suppliers (branch_id, name, phone, email)
             VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([
            $branchId,
            $name,
            $phone !== '' ? $phone : null,
            $email !== '' ? $email : null,
        ]);

        unset($_SESSION['supplier_old']);
        log_activity($pdo, 'create', 'suppliers', 'Created supplier: ' . $name);
        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Supplier added successfully.'];
        redirect_to('suppliers/index.php');
    } catch (Throwable $e) {
        $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Unable to add supplier. Please try again.'];
        redirect_to('suppliers/add.php');
    }
}

$old = $_SESSION['supplier_old'] ?? ['name' => '', 'phone' => '', 'email' => ''];
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['supplier_old'], $_SESSION['flash']);

include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h4 class="mb-0">Add Supplier</h4>
        <small class="text-muted">Create a supplier record for the current branch.</small>
    </div>
    <a class="btn btn-outline-secondary" href="<?= app_url('suppliers/index.php') ?>">
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
    <div class="row g-3">
        <div class="col-md-6">
            <label class="form-label">Supplier Name <span class="text-danger">*</span></label>
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
            <label class="form-label">Phone</label>
            <input
                type="text"
                name="phone"
                class="form-control"
                maxlength="50"
                value="<?= htmlspecialchars($old['phone']) ?>"
            >
        </div>
        <div class="col-12">
            <label class="form-label">Email</label>
            <input
                type="email"
                name="email"
                class="form-control"
                maxlength="120"
                value="<?= htmlspecialchars($old['email']) ?>"
            >
        </div>
    </div>

    <div class="d-flex justify-content-end gap-2 mt-4">
        <a class="btn btn-outline-secondary" href="<?= app_url('suppliers/index.php') ?>">Cancel</a>
        <button class="btn btn-primary" type="submit">
            <i class="bi bi-save me-1"></i>
            Save Supplier
        </button>
    </div>
</form>

<?php include __DIR__ . '/../includes/footer.php'; ?>
