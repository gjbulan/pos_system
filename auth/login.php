<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/session.php';

if (!empty($_SESSION['user_id'])) {
    redirect_to('dashboard/index.php');
}

$error = isset($_GET['branch_error'])
    ? 'Your branch access is no longer valid. Please contact an administrator.'
    : '';
$oldUsername = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $oldUsername = $username;

    $stmt = $pdo->prepare('SELECT id, branch_id, name, username, password, role FROM users WHERE username = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password'])) {
        try {
            $branchId = resolve_login_branch_id($pdo, $user);

            session_regenerate_id(true);
            $_SESSION['user_id'] = (int)$user['id'];
            $_SESSION['name'] = $user['name'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['branch_id'] = $branchId;

            log_activity($pdo, 'login', 'auth', 'User logged in.');
            redirect_to('dashboard/index.php');
        } catch (RuntimeException $e) {
            $error = $e->getMessage();
        }
    } else {
        $error = 'Invalid username or password.';
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login - POS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="<?= app_url('assets/css/app.css') ?>" rel="stylesheet">
</head>
<body class="login-bg">
<div class="container min-vh-100 d-flex align-items-center justify-content-center">
    <div class="card shadow-lg border-0 rounded-4 login-card">
        <div class="card-body p-4">
            <h3 class="fw-bold mb-1">POS Login</h3>
            <p class="text-muted">Access your store dashboard.</p>
            <?php if ($error): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <form method="post">
                <div class="mb-3">
                    <label class="form-label">Username</label>
                    <input class="form-control" name="username" value="<?= htmlspecialchars($oldUsername) ?>" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Password</label>
                    <input class="form-control" name="password" type="password" required>
                </div>
                <button class="btn btn-primary w-100 rounded-3">Login</button>
            </form>
        </div>
    </div>
</div>
</body>
</html>
