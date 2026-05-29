<?php
$pageTitle = 'Add User';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../auth/session.php';

require_login();
require_permission($pdo, 'users.manage');

$roles = valid_user_roles();

$branchStmt = $pdo->prepare('SELECT id, name, code FROM branches ORDER BY name');
$branchStmt->execute();
$branches = $branchStmt->fetchAll();
$validBranchIds = array_map('intval', array_column($branches, 'id'));

function normalize_branch_ids(array $values): array
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
    $areaBranchIds = normalize_branch_ids(is_array($areaBranchInput) ? $areaBranchInput : [$areaBranchInput]);
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

    $fail = static function (string $message): void {
        $_SESSION['flash'] = ['type' => 'danger', 'message' => $message];
        redirect_to('users/add.php');
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

    if (strlen($password) < 6) {
        $fail('Password must be at least 6 characters.');
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

    $usernameCheck = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username = ?');
    $usernameCheck->execute([$username]);
    if ((int)$usernameCheck->fetchColumn() > 0) {
        $fail('This username is already taken.');
    }

    try {
        $pdo->beginTransaction();

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

        $userId = (int)$pdo->lastInsertId();
        if ($role === 'Area Manager') {
            $assignStmt = $pdo->prepare('INSERT INTO user_branches(user_id, branch_id) VALUES(?, ?)');
            foreach ($areaBranchIds as $assignedBranchId) {
                $assignStmt->execute([$userId, $assignedBranchId]);
            }
        }

        log_activity($pdo, 'create', 'users', 'Created user: ' . $username . ' (' . $role . ')');
        $pdo->commit();

        unset($_SESSION['user_old']);
        $_SESSION['flash'] = ['type' => 'success', 'message' => 'User added successfully.'];
        redirect_to('users/index.php');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Unable to add user. Please try again.'];
        redirect_to('users/add.php');
    }
}

$old = $_SESSION['user_old'] ?? [
    'name' => '',
    'username' => '',
    'role' => 'Cashier',
    'branch_id' => '',
    'area_branch_ids' => [],
    'is_active' => 1,
];
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['user_old'], $_SESSION['flash']);

include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h4 class="mb-0">Add User</h4>
        <small class="text-muted">Create a staff account with role-based branch access.</small>
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
                    <option value="<?= (int)$branch['id'] ?>" <?= (string)$old['branch_id'] === (string)$branch['id'] ? 'selected' : '' ?>>
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
