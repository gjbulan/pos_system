<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/session.php';

require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect_to('dashboard/index.php');
}

$branchId = (int)($_POST['branch_id'] ?? 0);

if ($branchId > 0 && switch_current_branch($pdo, $branchId)) {
    log_activity($pdo, 'switch_branch', 'auth', 'Switched active branch to ID ' . $branchId);
    redirect_to('dashboard/index.php');
}

$_SESSION['flash'] = ['type' => 'danger', 'message' => 'You are not allowed to access the selected branch.'];
redirect_to('dashboard/index.php');
