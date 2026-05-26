<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/session.php';

require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect_to('dashboard/index.php');
}

$branchId = (int)($_POST['branch_id'] ?? 0);
$oldBranchId = current_branch_id();
$auditContext = [
    'user_id' => (int)($_SESSION['user_id'] ?? 0),
    'username' => $_SESSION['username'] ?? null,
    'role' => $_SESSION['role'] ?? null,
    'branch_id' => $oldBranchId,
    'requested_branch_id' => $branchId,
];

if ($branchId > 0 && switch_current_branch($pdo, $branchId)) {
    log_auth_event($pdo, 'branch_switch', 'Switched active branch from ID ' . $oldBranchId . ' to ID ' . $branchId . '.', [
        'user_id' => (int)($_SESSION['user_id'] ?? 0),
        'username' => $_SESSION['username'] ?? null,
        'role' => $_SESSION['role'] ?? null,
        'branch_id' => $branchId,
        'requested_branch_id' => $branchId,
    ]);
    redirect_to('dashboard/index.php');
}

log_auth_event($pdo, 'branch_switch_denied', 'Denied branch switch from ID ' . $oldBranchId . ' to requested ID ' . $branchId . '.', $auditContext);
$_SESSION['flash'] = ['type' => 'danger', 'message' => 'You are not allowed to access the selected branch.'];
redirect_to('dashboard/index.php');
