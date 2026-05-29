<?php
$pageTitle = 'Edit User';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../auth/session.php';

require_login();
require_permission($pdo, 'users.manage');

$roles = valid_user_roles();
$currentUserId = (int)($_SESSION['user_id'] ?? 0);
$id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);

if ($id <= 0) {
    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'User was not found.'];
    redirect_to('users/index.php');
}

$branchStmt = $pdo->prepare('SELECT id, name, code FROM branches ORDER BY name');
$branchStmt->execute();
$branches = $branchStmt->fetchAll();
$validBranchIds = array_map('intval', array_column($branches, 'id'));

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

$assignedStmt = $pdo->prepare('SELECT branch_id FROM user_branches WHERE user_id = ? ORDER BY branch_id');
$assignedStmt->execute([$id]);
$assignedBranchIds = array_map('intval', $assignedStmt->fetchAll(PDO::FETCH_COLUMN) ?: []);

function normalize_edit_branch_ids(array $values): array
{
    $ids = [];
    foreach ($values as $value) {
        if (ctype_digit((string)$value)) {
            $ids[] = (int)$value;
        }
    }

    return array_values(array_unique($ids));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $role = trim($_POST['role'] ?? 'Cashier');
    $branchId = trim($_POST['branch_id'] ?? '');
    $areaBranchInput = $_POST['area_branch_ids'] ?? [];
    $areaBranchIds = normalize_edit_branch_ids(is_array($areaBranchInput) ? $areaBranchInput : [$areaBranchInput]);
    $isActive = isset($_POST['is_active']) ? 1 : 0;
    $branchIdValue = null;

    $_SESSION['user_old'] = [
        'name' => $name,
        'username' => $username,
        'role' => $role,
        'branch_id' => $branchId,
        'area_branch_ids' => $areaBranchIds,
        'is_active' => $isActive,
    ];

    $fail = static function (string $message) use ($id): void {
        $_SESSION['flash'] = ['type' => 'danger', 'message' => $message];
        redirect_to('users/edit.php?id=' . $id);
    };

    if ($name === '') {
        $fail('Full name is required.');
    }

    if ($username === '') {
        $fail('Username is required.');
    }

    if (strlen($name) > 120 || strlen($username) > 80) {
        $fail('Name or username exceeds the allowed length.');
    }

    if ($password !== '' && strlen($password) < 6) {
        $fail('New password must be at least 6 characters.');
    }

    if (!in_array($role, $roles, true)) {
        $fail('Select a valid role.');
    }

    if ($role === 'Area Manager') {
        if (!$areaBranchIds) {
            $fail('Select at least one assigned branch for an Area Manager.');
        }

        if (array_diff($areaBranchIds, $validBranchIds)) {
            $fail('One or more Area Manager branch assignments are invalid.');
        }
    } elseif (in_array($role, single_branch_roles(), true)) {
        if ($branchId === '' || !ctype_digit($branchId)) {
            $fail('Select exactly one branch for this role.');
        }

        $branchIdValue = (int)$branchId;
        if (!in_array($branchIdValue, $validBranchIds, true)) {
            $fail('Select a valid branch.');
        }
    }

    $usernameCheck = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username = ? AND id <> ?');
    $usernameCheck->execute([$username, $id]);
    if ((int)$usernameCheck->fetchColumn() > 0) {
        $fail('This username is already taken.');
    }

    if ($id === $currentUserId && $isActive === 0) {
        $fail('You cannot deactivate your own account.');
    }

    if ((string)$user['role'] === 'Admin' && ((int)$user['is_active'] === 1) && ($role !== 'Admin' || $isActive === 0)) {
        $adminCountStmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role = 'Admin' AND is_active = 1");
        $adminCountStmt->execute();
        if ((int)$adminCountStmt->fetchColumn() <= 1) {
            $fail('At least one active Admin account must remain.');
        }
    }

    try {
        $pdo->beginTransaction();

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

        $deleteAssignments = $pdo->prepare('DELETE FROM user_branches WHERE user_id = ?');
        $deleteAssignments->execute([$id]);

        if ($role === 'Area Manager') {
            $assignStmt = $pdo->prepare('INSERT INTO user_branches(user_id, branch_id) VALUES(?, ?)');
            foreach ($areaBranchIds as $assignedBranchId) {
                $assignStmt->execute([$id, $assignedBranchId]);
            }
        }

        if ($id === $currentUserId) {
            $_SESSION['name'] = $name;
            $_SESSION['role'] = $role;
            $_SESSION['branch_id'] = resolve_login_branch_id($pdo, [
                'id' => $id,
                'role' => $role,
                'branch_id' => $branchIdValue,
            ]);
        }

        log_activity($pdo, 'update', 'users', 'Updated user: ' . $username . ' (' . $role . ')');
        $pdo->commit();

        unset($_SESSION['user_old']);
        $_SESSION['flash'] = ['type' => 'success', 'message' => 'User updated successfully.'];
        redirect_to('users/index.php');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Unable to update user. Please try again.'];
        redirect_to('users/edit.php?id=' . $id);
    }
}

$old = $_SESSION['user_old'] ?? [
    'name' => $user['name'],
    'username' => $user['username'],
    'role' => $user['role'],
    'branch_id' => $user['branch_id'] ?? '',
    'area_branch_ids' => $assignedBranchIds,
    'is_active' => $user['is_active'],
];
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['user_old'], $_SESSION['flash']);

include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h4 class="mb-0">Edit User</h4>
        <small class="text-muted">Update account details, role, branch access, or password.</small>
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
            <select name="role" id="roleSelect" class="form-select" required>
                <?php foreach ($roles as $role): ?>
                    <option value="<?= htmlspecialchars($role) ?>" <?= $old['role'] === $role ? 'selected' : '' ?>>
                        <?= htmlspecialchars($role) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="col-md-6" data-branch-panel="single">
            <label class="form-label">Branch Assignment</label>
            <select name="branch_id" class="form-select">
                <option value="">Select branch</option>
                <?php foreach ($branches as $branch): ?>
                    <option value="<?= (int)$branch['id'] ?>" <?= (string)($old['branch_id'] ?? '') === (string)$branch['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($branch['name']) ?> (<?= htmlspecialchars($branch['code']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="col-12" data-branch-panel="multiple">
            <label class="form-label">Area Manager Branches</label>
            <div class="row g-2">
                <?php foreach ($branches as $branch): ?>
                    <div class="col-md-4">
                        <label class="form-check border rounded p-2 h-100">
                            <input
                                class="form-check-input me-2"
                                type="checkbox"
                                name="area_branch_ids[]"
                                value="<?= (int)$branch['id'] ?>"
                                <?= in_array((int)$branch['id'], $old['area_branch_ids'] ?? [], true) ? 'checked' : '' ?>
                            >
                            <span class="form-check-label"><?= htmlspecialchars($branch['name']) ?> (<?= htmlspecialchars($branch['code']) ?>)</span>
                        </label>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="col-12" data-branch-panel="admin">
            <div class="alert alert-info mb-0">Admin users can access all branches and do not need a branch assignment.</div>
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

<script>
const roleSelect = document.getElementById('roleSelect');
const branchPanels = document.querySelectorAll('[data-branch-panel]');

function updateBranchPanels() {
    const role = roleSelect.value;
    branchPanels.forEach(panel => {
        const mode = panel.dataset.branchPanel;
        const show =
            (role === 'Admin' && mode === 'admin') ||
            (role === 'Area Manager' && mode === 'multiple') ||
            (['Manager', 'Cashier', 'Inventory Clerk', 'Purchasing Staff'].includes(role) && mode === 'single');
        panel.classList.toggle('d-none', !show);
    });
}

roleSelect.addEventListener('change', updateBranchPanels);
updateBranchPanels();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
