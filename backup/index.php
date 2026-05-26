<?php
$pageTitle = 'Backup & Restore';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../auth/session.php';

require_login();
require_permission($pdo, 'backup.manage');

function sql_quote(PDO $pdo, mixed $value): string
{
    if ($value === null) {
        return 'NULL';
    }
    return $pdo->quote((string)$value);
}

function split_sql_statements(string $sql): array
{
    $statements = [];
    $buffer = '';
    $length = strlen($sql);
    $inSingle = false;
    $inDouble = false;
    $escape = false;

    for ($i = 0; $i < $length; $i++) {
        $char = $sql[$i];
        $buffer .= $char;

        if ($escape) {
            $escape = false;
            continue;
        }

        if ($char === '\\') {
            $escape = true;
            continue;
        }

        if ($char === "'" && !$inDouble) {
            $inSingle = !$inSingle;
            continue;
        }

        if ($char === '"' && !$inSingle) {
            $inDouble = !$inDouble;
            continue;
        }

        if ($char === ';' && !$inSingle && !$inDouble) {
            $statement = trim($buffer);
            if ($statement !== ';' && $statement !== '') {
                $statements[] = $statement;
            }
            $buffer = '';
        }
    }

    $tail = trim($buffer);
    if ($tail !== '') {
        $statements[] = $tail;
    }

    return $statements;
}

$notice = '';
$error = '';

if (isset($_GET['download']) && $_GET['download'] === 'sql') {
    $tables = [];
    foreach ($pdo->query('SHOW TABLES') as $row) {
        $tables[] = array_values($row)[0];
    }

    $filename = 'pos_backup_' . date('Ymd_His') . '.sql';
    header('Content-Type: application/sql');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    echo "-- POS System Backup\n";
    echo "-- Generated: " . date('Y-m-d H:i:s') . "\n";
    echo "SET FOREIGN_KEY_CHECKS=0;\n\n";

    foreach ($tables as $table) {
        $create = $pdo->query('SHOW CREATE TABLE `' . str_replace('`', '``', $table) . '`')->fetch();
        echo "DROP TABLE IF EXISTS `" . str_replace('`', '``', $table) . "`;\n";
        echo $create['Create Table'] . ";\n\n";

        $rows = $pdo->query('SELECT * FROM `' . str_replace('`', '``', $table) . '`')->fetchAll();
        if ($rows) {
            $columns = array_keys($rows[0]);
            $columnSql = '`' . implode('`, `', array_map(fn($col) => str_replace('`', '``', $col), $columns)) . '`';
            foreach ($rows as $row) {
                $values = array_map(fn($col) => sql_quote($pdo, $row[$col]), $columns);
                echo "INSERT INTO `" . str_replace('`', '``', $table) . "` ({$columnSql}) VALUES (" . implode(', ', $values) . ");\n";
            }
            echo "\n";
        }
    }

    echo "SET FOREIGN_KEY_CHECKS=1;\n";
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['restore_backup'])) {
    if (!isset($_FILES['backup_file']) || $_FILES['backup_file']['error'] !== UPLOAD_ERR_OK) {
        $error = 'Please upload a valid .sql backup file.';
    } else {
        $fileName = $_FILES['backup_file']['name'];
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        if ($extension !== 'sql') {
            $error = 'Only .sql files are allowed.';
        } else {
            $sql = file_get_contents($_FILES['backup_file']['tmp_name']);
            if ($sql === false || trim($sql) === '') {
                $error = 'The uploaded backup file is empty or unreadable.';
            } else {
                try {
                    $pdo->beginTransaction();
                    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
                    foreach (split_sql_statements($sql) as $statement) {
                        $trimmed = trim($statement);
                        if ($trimmed === '' || str_starts_with($trimmed, '--')) {
                            continue;
                        }
                        $pdo->exec($trimmed);
                    }
                    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
                    $pdo->commit();
                    $notice = 'Backup restored successfully. Log out and log back in if user, branch, or role data changed.';
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    try { $pdo->exec('SET FOREIGN_KEY_CHECKS=1'); } catch (Throwable $ignored) {}
                    $error = 'Restore failed: ' . $e->getMessage();
                }
            }
        }
    }
}

include __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h4 class="mb-0">Backup & Restore</h4>
        <small class="text-muted">Export the full database or restore from a trusted SQL backup.</small>
    </div>
    <a href="<?= app_url('backup/index.php?download=sql') ?>" class="btn btn-primary">
        <i class="bi bi-download"></i> Download SQL Backup
    </a>
</div>

<?php if ($notice): ?>
    <div class="alert alert-success"><?= htmlspecialchars($notice) ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="table-card h-100">
            <h5><i class="bi bi-cloud-download"></i> Create Backup</h5>
            <p class="text-muted">Download a complete SQL file containing schema and data for this POS database.</p>
            <ul class="small text-muted">
                <li>Back up before importing changes or deploying updates.</li>
                <li>Store backups outside the web server folder.</li>
                <li>Use date-based filenames for easier tracking.</li>
            </ul>
            <a href="<?= app_url('backup/index.php?download=sql') ?>" class="btn btn-outline-primary">
                <i class="bi bi-database-down"></i> Export Database
            </a>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="table-card h-100">
            <h5><i class="bi bi-cloud-upload"></i> Restore Backup</h5>
            <p class="text-muted">Restore from a trusted SQL backup. This may replace current data.</p>
            <form method="post" enctype="multipart/form-data" onsubmit="return confirm('Restoring may overwrite current records. Continue?');">
                <input type="hidden" name="restore_backup" value="1">
                <div class="mb-3">
                    <label class="form-label">SQL Backup File</label>
                    <input type="file" name="backup_file" class="form-control" accept=".sql" required>
                </div>
                <button class="btn btn-danger">
                    <i class="bi bi-arrow-clockwise"></i> Restore Backup
                </button>
            </form>
        </div>
    </div>
</div>

<div class="table-card mt-3">
    <h5>Recommended Backup Routine</h5>
    <div class="row g-3">
        <div class="col-md-4">
            <div class="p-3 rounded border bg-light h-100">
                <strong>Daily</strong>
                <p class="small text-muted mb-0">Download a backup after closing sales for the day.</p>
            </div>
        </div>
        <div class="col-md-4">
            <div class="p-3 rounded border bg-light h-100">
                <strong>Before Updates</strong>
                <p class="small text-muted mb-0">Back up before importing new schema files or deploying a new phase.</p>
            </div>
        </div>
        <div class="col-md-4">
            <div class="p-3 rounded border bg-light h-100">
                <strong>Offsite Copy</strong>
                <p class="small text-muted mb-0">Keep a copy in Google Drive, external drive, or secure storage.</p>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
