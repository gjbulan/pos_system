<?php
$step = $_GET['step'] ?? 'welcome';
$basePath = dirname(__DIR__);
require_once $basePath . '/config/app.php';

$configFile = $basePath . '/config/database.php';
$schemaFile = $basePath . '/database/final_schema.sql';

function clean($value) {
    return htmlspecialchars(trim((string)$value), ENT_QUOTES, 'UTF-8');
}

function run_sql_file(PDO $pdo, string $file): array {
    $sql = file_get_contents($file);
    $statements = array_filter(array_map('trim', preg_split('/;\s*\n/', $sql)));
    $executed = 0;
    foreach ($statements as $statement) {
        if ($statement === '') continue;
        $pdo->exec($statement);
        $executed++;
    }
    return ['executed' => $executed];
}

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'install') {
    $host = trim($_POST['db_host'] ?? 'localhost');
    $db = trim($_POST['db_name'] ?? 'pos_system');
    $user = trim($_POST['db_user'] ?? 'root');
    $pass = $_POST['db_pass'] ?? '';
    try {
        $pdo = new PDO("mysql:host={$host};charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $result = run_sql_file($pdo, $schemaFile);
        $message = "Database installed successfully. SQL statements executed: " . $result['executed'];
        $step = 'admin';
    } catch (Throwable $e) {
        $error = $e->getMessage();
        $step = 'database';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'admin') {
    $host = trim($_POST['db_host'] ?? 'localhost');
    $db = trim($_POST['db_name'] ?? 'pos_system');
    $user = trim($_POST['db_user'] ?? 'root');
    $pass = $_POST['db_pass'] ?? '';
    $name = trim($_POST['admin_name'] ?? 'Administrator');
    $username = trim($_POST['admin_username'] ?? 'admin');
    $password = $_POST['admin_password'] ?? '';
    if (strlen($password) < 8) {
        $error = 'Admin password must be at least 8 characters.';
        $step = 'admin';
    } else {
        try {
            $pdo = new PDO("mysql:host={$host};dbname={$db};charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $branchId = $pdo->query("SELECT id FROM branches ORDER BY id LIMIT 1")->fetchColumn();
            if (!$branchId) {
                $pdo->exec("INSERT INTO branches (name, code, address) VALUES ('Main Branch', 'MAIN', 'Default branch')");
                $branchId = $pdo->lastInsertId();
            }
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO users (branch_id, name, username, password, role, is_active) VALUES (?, ?, ?, ?, 'Admin', 1) ON DUPLICATE KEY UPDATE name = VALUES(name), password = VALUES(password), role = 'Admin', is_active = 1");
            $stmt->execute([$branchId, $name, $username, $hash]);
            $message = 'Admin account created. Remove or protect the /install folder before going live.';
            $step = 'done';
        } catch (Throwable $e) {
            $error = $e->getMessage();
            $step = 'admin';
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>POS Installer</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>body{background:#f5f7fb}.installer-card{max-width:760px;margin:50px auto}.step-pill{font-size:.85rem}</style>
</head>
<body>
<div class="container">
    <div class="card shadow-sm installer-card border-0 rounded-4">
        <div class="card-body p-4 p-md-5">
            <div class="d-flex align-items-center gap-3 mb-4">
                <div class="bg-primary text-white rounded-3 p-3"><i class="bi bi-shop fs-3"></i></div>
                <div>
                    <h1 class="h3 mb-1">POS System Installer</h1>
                    <div class="text-muted">Phase 17 setup assistant</div>
                </div>
            </div>
            <?php if ($message): ?><div class="alert alert-success"><?= clean($message) ?></div><?php endif; ?>
            <?php if ($error): ?><div class="alert alert-danger"><?= clean($error) ?></div><?php endif; ?>

            <?php if ($step === 'welcome'): ?>
                <span class="badge bg-primary step-pill mb-3">Step 1</span>
                <h2 class="h5">Before you start</h2>
                <p>This installer can import the database schema and create the first administrator account.</p>
                <ul>
                    <li>Start Apache and MySQL in XAMPP.</li>
                    <li>Place this folder inside <code>htdocs</code>.</li>
                    <li>Use a strong admin password before production.</li>
                </ul>
                <a class="btn btn-primary" href="?step=database">Start installation</a>
            <?php elseif ($step === 'database'): ?>
                <span class="badge bg-primary step-pill mb-3">Step 2</span>
                <h2 class="h5">Database setup</h2>
                <form method="post" class="row g-3">
                    <input type="hidden" name="action" value="install">
                    <div class="col-md-6"><label class="form-label">Host</label><input class="form-control" name="db_host" value="localhost" required></div>
                    <div class="col-md-6"><label class="form-label">Database</label><input class="form-control" name="db_name" value="pos_system" required></div>
                    <div class="col-md-6"><label class="form-label">Username</label><input class="form-control" name="db_user" value="root" required></div>
                    <div class="col-md-6"><label class="form-label">Password</label><input class="form-control" type="password" name="db_pass"></div>
                    <div class="col-12"><button class="btn btn-primary">Install database</button></div>
                </form>
            <?php elseif ($step === 'admin'): ?>
                <span class="badge bg-primary step-pill mb-3">Step 3</span>
                <h2 class="h5">Create first administrator</h2>
                <form method="post" class="row g-3">
                    <input type="hidden" name="action" value="admin">
                    <input type="hidden" name="db_host" value="<?= clean($_POST['db_host'] ?? 'localhost') ?>">
                    <input type="hidden" name="db_name" value="<?= clean($_POST['db_name'] ?? 'pos_system') ?>">
                    <input type="hidden" name="db_user" value="<?= clean($_POST['db_user'] ?? 'root') ?>">
                    <input type="hidden" name="db_pass" value="<?= clean($_POST['db_pass'] ?? '') ?>">
                    <div class="col-md-6"><label class="form-label">Name</label><input class="form-control" name="admin_name" value="Administrator" required></div>
                    <div class="col-md-6"><label class="form-label">Username</label><input class="form-control" name="admin_username" value="admin" required></div>
                    <div class="col-12"><label class="form-label">Password</label><input class="form-control" type="password" name="admin_password" minlength="8" required></div>
                    <div class="col-12"><button class="btn btn-success">Create admin</button></div>
                </form>
            <?php elseif ($step === 'done'): ?>
                <span class="badge bg-success step-pill mb-3">Complete</span>
                <h2 class="h5">Installation complete</h2>
                <p>You can now open the POS login page.</p>
                <a class="btn btn-primary" href="<?= app_url('auth/login.php') ?>">Go to login</a>
                <a class="btn btn-outline-secondary" href="<?= app_url('system/health.php') ?>">Run health check</a>
            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>
