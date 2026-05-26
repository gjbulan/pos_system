<?php
$pageTitle = 'Edit User';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../auth/session.php';

require_login();
require_permission($pdo, 'users.manage');

$roles = ['Admin', 'Manager', 'Cashier'];
$currentUserId = (int)($_SESSION['user_id'] ?? 0);
$id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);

if ($id <= 0) {
    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'User was not found.'];
    redirect_to('users/index.php');
}

$branchStmt = $pdo->prepare('SELECT id, name, code FROM branches ORDER BY name');
$branchStmt->execute();
$branches = $branchStmt->fetchAll();

$fetchStmt = $pdo->prepare(
    'SELECT id, branch_id, name, username, role, is_active
     FROM users
     WHERE id = ?'
);
$fetchStmt->execute([$id]);
$user = $fetchStmt->fetch();

if (!$user) {
    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'User was not found.'];
    redirect_to('users/index.php');
}

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
        redirect_to('users/edit.php?id=' . $id);
    }

    if ($username === '') {
        $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Username is required.'];
        redirect_to('users/edit.php?id=' . $id);
    }

    if (strlen($name) > 120 || strlen($username) > 80) {
        $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Name or username exceeds the allowed length.'];
        redirect_to('users/edit.php?id=' . $id);
    }

    if ($password !== '' && strlen($password) < 6) {
        $_SESSION['flash'] = ['type' => 'danger', 'message' => 'New password must be at least 6 characters.'];
        redirect_to('users/edit.php?id=' . $id);
    }

    if (!in_array($role, $roles, true)) {
        $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Select a valid role.'];
        redirect_to('users/edit.php?id=' . $id);
    }

    if ($branchId !== '') {
        $branchCheck = $pdo->prepare('SELECT COUNT(*) FROM branches WHERE id = ?');
        $branchCheck->execute([$branchIdValue]);
        if ((int)$branchCheck->fetchColumn() === 0) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Select a valid branch.'];
            redirect_to('users/edit.php?id=' . $id);
        }
    }

    $usernameCheck = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username = ? AND id <> ?');
    $usernameCheck->execute([$username, $id]);
    if ((int)$usernameCheck->fetchColumn() > 0) {
        $_SESSION['flash'] = ['type' => 'danger', 'message' => 'This username is already taken.'];
        redirect_to('users/edit.php?id=' . $id);
    }

    if ($id === $currentUserId && $isActive === 0) {
        $_SESSION['flash'] = ['type' => 'danger', 'message' => 'You cannot deactivate your own account.'];
        redirect_to('users/edit.php?id=' . $id);
    }

    if ((string)$user['role'] === 'Admin' && ((int)$user['is_active'] === 1) && ($role !== 'Admin' || $isActive === 0)) {
        $adminCountStmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role = 'Admin' AND is_active = 1");
        $adminCountStmt->execute();
        if ((int)$adminCountStmt->fetchColumn() <= 1) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'At least one active Admin account must remain.'];
            redirect_to('users/edit.php?id=' . $id);
        }
    }

    try {
        if ($password !== '') {
            $stmt = $pdo->prepare(
                'UPDATE users
                 SET branch_id = ?, name = ?, username = ?, password = ?, role = ?, is_active = ?
                 WHERE id = ?'
            );
            $stmt->execute([
                $branchIdValue,
                $name,
                $username,
                password_hash($password, PASSWORD_DEFAULT),
                $role,
                $isActive,
                $id,
            ]);
        } else {
            $stmt = $pdo->prepare(
                'UPDATE users
                 SET branch_id = ?, name = ?, username = ?, role = ?, is_active = ?
                 WHERE id = ?'
            );
            $stmt->execute([
                $branchIdValue,
                $name,
                $username,
                $role,
                $isActive,
                $id,
            ]);
        }

        if ($id === $currentUserId) {
            $_SESSION['name'] = $name;
            $_SESSION['role'] = $role;
        }

        unset($_SESSION['user_old']);
        log_activity($pdo, 'update', 'users', 'Updated user: ' . $username . ' (' . $role . ')');
        $_SESSION['flash'] = ['type' => 'success', 'message' => 'User updated successfully.'];
        redirect_to('users/index.php');
    } catch (Throwable $e) {
        $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Unable to update user. Please try again.'];
        redirect_to('users/edit.php?id=' . $id);
    }
}

$old = $_SESSION['user_old'] ?? [
    'name' => $user['name'],
    'username' => $user['username'],
    'role' => $user['role'],
    'branch_id' => $user['branch_id'] ?? '',
    'is_active' => $user['is_active'],
];
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['user_old'], $_SESSION['flash']);

include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h4 class="mb-0">Edit User</h4>
        <small class="text-muted">Update account details, role, branch assignment, or password.</small>
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
    <input type="hidden" name="id" value="<?= (int)$user['id'] ?>">

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
            <label class="form-label">New Password</label>
            <input type="password" name="password" class="form-control" minlength="6" placeholder="Leave blank to keep current password">
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
                    <option value="<?= (int)$branch['id'] ?>" <?= (string)($old['branch_id'] ?? '') === (string)$branch['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($branch['name']) ?> (<?= htmlspecialchars($branch['code']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-6 d-flex align-items-end">
            <div class="form-check form-switch">
                <input
                    class="form-check-input"
                    type="checkbox"
                    role="switch"
                    name="is_active"
                    id="is_active"
                    value="1"
                    <?= (int)$old['is_active'] === 1 ? 'checked' : '' ?>
                    <?= (int)$user['id'] === $currentUserId ? 'disabled' : '' ?>
                >
                <?php if ((int)$user['id'] === $currentUserId): ?>
                    <input type="hidden" name="is_active" value="1">
                <?php endif; ?>
                <label class="form-check-label" for="is_active">Active user</label>
            </div>
        </div>
    </div>

    <div class="d-flex justify-content-end gap-2 mt-4">
        <a class="btn btn-outline-secondary" href="<?= app_url('users/index.php') ?>">Cancel</a>
        <button class="btn btn-primary" type="submit">
            <i class="bi bi-save me-1"></i>
            Save Changes
        </button>
    </div>
</form>

<?php include __DIR__ . '/../includes/footer.php'; ?>
