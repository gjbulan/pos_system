<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/session.php';

if (!empty($_SESSION['user_id'])) {
    log_auth_event($pdo, 'logout', 'User logged out.', [
        'user_id' => (int)$_SESSION['user_id'],
        'username' => $_SESSION['username'] ?? null,
        'role' => $_SESSION['role'] ?? null,
        'branch_id' => $_SESSION['branch_id'] ?? null,
    ]);
}

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
}
session_destroy();
redirect_to('auth/login.php');
