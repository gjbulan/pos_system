<?php
$navbarBranches = [];
$navbarCurrentBranch = 'Branch ID: ' . current_branch_id();
$navbarCanSwitchBranch = false;

if (isset($pdo) && $pdo instanceof PDO) {
    $navbarBranches = session_accessible_branches($pdo);
    $navbarCurrentBranch = current_branch_label($pdo);
    $navbarCanSwitchBranch = branch_switch_allowed($pdo);
}
?>
<header class="topbar d-flex justify-content-between align-items-center gap-3">
    <div class="d-flex align-items-center gap-3 min-w-0">
        <button class="btn btn-light border d-lg-none mobile-menu-btn" type="button" id="sidebarToggle" aria-label="Open navigation">
            <i class="bi bi-list"></i>
        </button>
        <div class="min-w-0">
            <h5 class="mb-0 fw-bold text-truncate"><?= htmlspecialchars($pageTitle ?? 'Dashboard') ?></h5>
            <small class="text-muted"><?= htmlspecialchars($navbarCurrentBranch) ?></small>
        </div>
    </div>
    <div class="d-flex align-items-center gap-2 user-actions">
        <?php if ($navbarCanSwitchBranch): ?>
            <form class="d-flex align-items-center gap-2" method="post" action="<?= app_url('auth/switch_branch.php') ?>">
                <select class="form-select form-select-sm" name="branch_id" aria-label="Switch branch" onchange="this.form.submit()">
                    <?php foreach ($navbarBranches as $branch): ?>
                        <option value="<?= (int)$branch['id'] ?>" <?= current_branch_id() === (int)$branch['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($branch['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        <?php endif; ?>
        <span class="text-muted user-label d-none d-sm-inline"><?= htmlspecialchars($_SESSION['name'] ?? 'User') ?> - <?= htmlspecialchars($_SESSION['role'] ?? '') ?></span>
        <a class="btn btn-outline-danger btn-sm" href="<?= app_url('auth/logout.php') ?>">Logout</a>
    </div>
</header>
