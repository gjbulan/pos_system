<?php
$pageTitle = 'Add User';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../auth/session.php';

require_login();
require_permission($pdo, 'users.manage');

$roles = ['Admin', 'Manager', 'Cashier'];

$branchStmt = $pdo->prepare('SELECT id, name, code FROM branches ORDER BY name');
$branchStmt->execute();
$branches = $branchStmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $role = trim($_POST['role'] ?? 'Cashier');
    $branchId = trim($_POST['branch_id'] ?? '');
    $isActive = isset($_POST['is_active']) ? 1 : 0;
    $branchIdValue = $branchId !== '' ? (int)$branchId : null;

    $_SESSION['user_old'] = [
        'name' => $name,
        'username' => $username,
        'role' => $role,
        'branch_id' => $branchId,
        'is_active' => $isActive,
    ];

    if ($name === '') {
        $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Full name is required.'];
        redirect_to('users/add.php');
    }

    if ($username === '') {
        $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Username is required.'];
        redirect_to('users/add.php');
    }

    if (strlen($name) > 120 || strlen($username) > 80) {
        $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Name or username exceeds the allowed length.'];
        redirect_to('users/add.php');
    }

    if (strlen($password) < 6) {
        $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Password must be at least 6 characters.'];
        redirect_to('users/add.php');
    }

    if (!in_array($role, $roles, true)) {
        $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Select a valid role.'];
        redirect_to('users/add.php');
    }

    if ($branchId !== '') {
        $branchCheck = $pdo->prepare('SELECT COUNT(*) FROM branches WHERE id = ?');
        $branchCheck->execute([$branchIdValue]);
        if ((int)$branchCheck->fetchColumn() === 0) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Select a valid branch.'];
            redirect_to('users/add.php');
        }
    }

    $usernameCheck = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username = ?');
    $usernameCheck->execute([$username]);
    if ((int)$usernameCheck->fetchColumn() > 0) {
        $_SESSION['flash'] = ['type' => 'danger', 'message' => 'This username is already taken.'];
        redirect_to('users/add.php');
    }

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO users (branch_id, name, username, password, role, is_active)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $branchIdValue,
            $name,
            $username,
            password_hash($password, PASSWORD_DEFAULT),
            $role,
            $isActive,
        ]);

        unset($_SESSION['user_old']);
        log_activity($pdo, 'create', 'users', 'Created user: ' . $username . ' (' . $role . ')');
        $_SESSION['flash'] = ['type' => 'success', 'message' => 'User added successfully.'];
        redirect_to('users/index.php');
    } catch (Throwable $e) {
        $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Unable to add user. Please try again.'];
        redirect_to('users/add.php');
    }
}

$old = $_SESSION['user_old'] ?? [
    'name' => '',
    'username' => '',
    'role' => 'Cashier',
    'branch_id' => '',
    'is_active' => 1,
];
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['user_old'], $_SESSION['flash']);

include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h4 class="mb-0">Add User</h4>
        <small class="text-muted">Create a staff account with a role and optional branch assignment.</small>
    </div>
    <a class="btn btn-outline-secondary" href="<?= app_url('users/index.php') ?>">
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
            <label class="form-label">Full Name <span class="text-danger">*</span></label>
            <input type="text" name="name" class="form-control" maxlength="120" required value="<?= htmlspecialchars($old['name']) ?>">
        </div>
        <div class="col-md-6">
            <label class="form-label">Username <span class="text-danger">*</span></label>
            <input type="text" name="username" class="form-control" maxlength="80" required value="<?= htmlspecialchars($old['username']) ?>">
        </div>
        <div class="col-md-6">
            <label class="form-label">Password <span class="text-danger">*</span></label>
            <input type="password" name="password" class="form-control" minlength="6" required>
        </div>
        <div class="col-md-6">
            <label class="form-label">Role <span class="text-danger">*</span></label>
            <select name="role" class="form-select" required>
                <?php foreach ($roles as $role): ?>
                    <option value="<?= htmlspecialchars($role) ?>" <?= $old['role'] === $role ? 'selected' : '' ?>>
                        <?= htmlspecialchars($role) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-6">
            <label class="form-label">Branch Assignment</label>
            <select name="branch_id" class="form-select">
                <option value="">Unassigned</option>
                <?php foreach ($branches as $branch): ?>
                    <option value="<?= (int)$branch['id'] ?>" <?= (string)$old['branch_id'] === (string)$branch['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($branch['name']) ?> (<?= htmlspecialchars($branch['code']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-6 d-flex align-items-end">
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" role="switch" name="is_active" id="is_active" value="1" <?= (int)$old['is_active'] === 1 ? 'checked' : '' ?>>
                <label class="form-check-label" for="is_active">Active user</label>
            </div>
        </div>
    </div>

    <div class="d-flex justify-content-end gap-2 mt-4">
        <a class="btn btn-outline-secondary" href="<?= app_url('users/index.php') ?>">Cancel</a>
        <button class="btn btn-primary" type="submit">
            <i class="bi bi-save me-1"></i>
            Save User
        </button>
    </div>
</form>

<?php include __DIR__ . '/../includes/footer.php'; ?>
