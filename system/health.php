<?php
require_once __DIR__ . '/../auth/session.php';
require_once __DIR__ . '/../config/database.php';

require_login();
require_permission($pdo, 'settings.manage');

$title = 'System Health Check';

$requiredTables = [
    'branches',
    'users',
    'role_permissions',
    'categories',
    'products',
    'customers',
    'suppliers',
    'expenses',
    'sales',
    'sale_items',
    'inventory_movements',
    'cash_sessions',
    'cash_drawer_transactions',
    'audit_logs',
    'settings'
];

$checks = [];

function add_check(array &$checks, string $name, bool $ok, string $detail = ''): void
{
    $checks[] = [
        'name' => $name,
        'ok' => $ok,
        'detail' => $detail
    ];
}

try {
    $pdo->query('SELECT 1');
    add_check($checks, 'Database connection', true, 'Connected successfully.');
} catch (Throwable $e) {
    add_check($checks, 'Database connection', false, $e->getMessage());
}

foreach ($requiredTables as $table) {
    try {
        $safeTable = preg_replace('/[^a-zA-Z0-9_]/', '', $table);

        $stmt = $pdo->query("SHOW TABLES LIKE '{$safeTable}'");
        $exists = $stmt && $stmt->rowCount() > 0;

        add_check(
            $checks,
            'Table: ' . $table,
            $exists,
            $exists ? 'Found.' : 'Missing table.'
        );
    } catch (Throwable $e) {
        add_check($checks, 'Table: ' . $table, false, $e->getMessage());
    }
}

$paths = [
    'config/database.php' => __DIR__ . '/../config/database.php',
    'database folder' => __DIR__ . '/../database',
    'backup folder' => __DIR__ . '/../backup',
    'install folder' => __DIR__ . '/../install',
    'assets folder' => __DIR__ . '/../assets',
    'includes folder' => __DIR__ . '/../includes'
];

foreach ($paths as $label => $path) {
    $exists = file_exists($path);

    add_check(
        $checks,
        'Path: ' . $label,
        $exists,
        $exists ? 'Found.' : 'Missing.'
    );
}

$backupPath = __DIR__ . '/../backup';

if (file_exists($backupPath)) {
    add_check(
        $checks,
        'Backup folder writable',
        is_writable($backupPath),
        is_writable($backupPath) ? 'Writable.' : 'Not writable.'
    );
}

$total = count($checks);

$passed = count(array_filter($checks, static function ($check) {
    return $check['ok'];
}));

include __DIR__ . '/../includes/header.php';
?>

<div class="container-fluid py-4">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
        <div>
            <h1 class="h3 mb-1">System Health Check</h1>
            <p class="text-muted mb-0">
                Verify database tables, key files, and installation readiness.
            </p>
        </div>

        <span class="badge <?= $passed === $total ? 'bg-success' : 'bg-warning text-dark' ?> fs-6 px-3 py-2">
            <?= $passed ?> / <?= $total ?> passed
        </span>
    </div>

    <div class="card border-0 shadow-sm rounded-4">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th style="width: 120px;">Status</th>
                            <th>Check</th>
                            <th>Details</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php foreach ($checks as $check): ?>
                            <tr>
                                <td>
                                    <span class="badge <?= $check['ok'] ? 'bg-success' : 'bg-danger' ?>">
                                        <?= $check['ok'] ? 'OK' : 'FAILED' ?>
                                    </span>
                                </td>

                                <td>
                                    <?= htmlspecialchars($check['name']) ?>
                                </td>

                                <td class="text-muted">
                                    <?= htmlspecialchars($check['detail']) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="alert alert-info mt-4 mb-0">
                For production, remove or password-protect the <code>/install</code> folder after setup.
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
