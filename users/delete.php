<?php
$pageTitle = 'Deactivate User';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../auth/session.php';

require_login();
require_permission($pdo, 'users.manage');

$currentUserId = (int)($_SESSION['user_id'] ?? 0);
$id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);

if ($id <= 0) {
    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'User was not found.'];
    redirect_to('users/index.php');
}

if ($id === $currentUserId) {
    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'You cannot deactivate your own account.'];
    redirect_to('users/index.php');
}

$stmt = $pdo->prepare(
    'SELECT u.id, u.name, u.username, u.role, u.is_active, b.name AS branch_name, b.code AS branch_code,
            area.area_branches
     FROM users u
     LEFT JOIN branches b ON b.id = u.branch_id
     LEFT JOIN (
        SELECT
            ub.user_id,
            GROUP_CONCAT(CONCAT(ab.name, " (", ab.code, ")") ORDER BY ab.name SEPARATOR ", ") AS area_branches
        FROM user_branches ub
        JOIN branches ab ON ab.id = ub.branch_id
        GROUP BY ub.user_id
     ) area ON area.user_id = u.id
     WHERE u.id = ?'
);
$stmt->execute([$id]);
$user = $stmt->fetch();

if (!$user) {
    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'User was not found.'];
    redirect_to('users/index.php');
}

if ((int)$user['is_active'] === 0) {
    $_SESSION['flash'] = ['type' => 'info', 'message' => 'This user is already inactive.'];
    redirect_to('users/index.php');
}

if ($user['role'] === 'Admin') {
    $adminCountStmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role = 'Admin' AND is_active = 1");
    $adminCountStmt->execute();
    if ((int)$adminCountStmt->fetchColumn() <= 1) {
        $_SESSION['flash'] = ['type' => 'danger', 'message' => 'At least one active Admin account must remain.'];
        redirect_to('users/index.php');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $deactivateStmt = $pdo->prepare('UPDATE users SET is_active = 0 WHERE id = ?');
        $deactivateStmt->execute([$id]);

        log_activity($pdo, 'deactivate', 'users', 'Deactivated user: ' . $user['username'] . ' (' . $user['role'] . ')');
        $_SESSION['flash'] = ['type' => 'success', 'message' => 'User deactivated successfully.'];
        redirect_to('users/index.php');
    } catch (Throwable $e) {
        $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Unable to deactivate user. Please try again.'];
        redirect_to('users/index.php');
    }
}

include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h4 class="mb-0">Deactivate User</h4>
        <small class="text-muted">Deactivate this account without removing historical records.</small>
    </div>
    <a class="btn btn-outline-secondary" href="<?= app_url('users/index.php') ?>">
        <i class="bi bi-arrow-left me-1"></i>
        Back
    </a>
</div>

<div class="table-card">
    <div class="alert alert-warning mb-4">
        <strong>Deactivate this user?</strong>
        The user will no longer be able to log in, but sales and audit history will remain intact.
    </div>

    <dl class="row mb-4">
        <dt class="col-sm-3">Name</dt>
        <dd class="col-sm-9"><?= htmlspecialchars($user['name']) ?></dd>

        <dt class="col-sm-3">Username</dt>
        <dd class="col-sm-9"><code><?= htmlspecialchars($user['username']) ?></code></dd>

        <dt class="col-sm-3">Role</dt>
        <dd class="col-sm-9"><?= htmlspecialchars($user['role']) ?></dd>

        <dt class="col-sm-3">Branch</dt>
        <dd class="col-sm-9">
            <?php if ($user['role'] === 'Admin'): ?>
                <span class="text-muted">All branches</span>
            <?php elseif ($user['role'] === 'Area Manager'): ?>
                <?= htmlspecialchars($user['area_branches'] ?: 'No assigned branches') ?>
            <?php elseif ($user['branch_name']): ?>
                <?= htmlspecialchars($user['branch_name']) ?> (<?= htmlspecialchars($user['branch_code']) ?>)
            <?php else: ?>
                <span class="text-muted">Unassigned</span>
            <?php endif; ?>
        </dd>
    </dl>

    <form method="post" class="d-flex justify-content-end gap-2">
        <input type="hidden" name="id" value="<?= (int)$user['id'] ?>">
        <a class="btn btn-outline-secondary" href="<?= app_url('users/index.php') ?>">Cancel</a>
        <button class="btn btn-danger" type="submit">
            <i class="bi bi-person-x me-1"></i>
            Deactivate User
        </button>
    </form>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
