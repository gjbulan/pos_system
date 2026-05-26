<?php
session_start();
require_once __DIR__ . '/../config/database.php';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $branchId = (int)($_POST['branch_id'] ?? 1);

    $stmt = $pdo->prepare('SELECT * FROM users WHERE username = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['name'] = $user['name'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['branch_id'] = $branchId;
        //header('Location: /pos_phase_16/dashboard/index.php');\
		header('Location: /posdemo/dashboard/index.php');
        exit;
    }
    $error = 'Invalid username or password.';
}
$branches = $pdo->query('SELECT id, name FROM branches ORDER BY name')->fetchAll();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login - POS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets/css/app.css" rel="stylesheet">
</head>
<body class="login-bg">
<div class="container min-vh-100 d-flex align-items-center justify-content-center">
    <div class="card shadow-lg border-0 rounded-4 login-card">
        <div class="card-body p-4">
            <h3 class="fw-bold mb-1">POS Login</h3>
            <p class="text-muted">Access your store dashboard.</p>
            <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
            <form method="post">
                <div class="mb-3">
                    <label class="form-label">Username</label>
                    <input class="form-control" name="username" value="admin" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Password</label>
                    <input class="form-control" name="password" type="password" value="admin123" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Branch</label>
                    <select class="form-select" name="branch_id">
                        <?php foreach ($branches as $branch): ?>
                            <option value="<?= $branch['id'] ?>"><?= htmlspecialchars($branch['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button class="btn btn-primary w-100 rounded-3">Login</button>
            </form>
        </div>
    </div>
</div>
</body>
</html>
