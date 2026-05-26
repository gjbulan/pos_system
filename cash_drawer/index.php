<?php
$pageTitle = 'Cash Drawer & Shift Management';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../auth/session.php';

require_login();
require_permission($pdo, 'cash_drawer.manage');

require_once __DIR__ . '/../includes/header.php';

$branchId = current_branch_id();
$userId = (int)($_SESSION['user_id'] ?? 0);
$message = '';
$error = '';

function get_open_session(PDO $pdo, int $branchId, int $userId): ?array
{
    $stmt = $pdo->prepare("SELECT * FROM cash_sessions WHERE branch_id = ? AND user_id = ? AND status = 'open' ORDER BY id DESC LIMIT 1");
    $stmt->execute([$branchId, $userId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'open_shift') {
            $existing = get_open_session($pdo, $branchId, $userId);
            if ($existing) {
                $error = 'You already have an open shift.';
            } else {
                $opening = (float)($_POST['opening_amount'] ?? 0);
                $notes = trim($_POST['notes'] ?? '');
                $stmt = $pdo->prepare("INSERT INTO cash_sessions(branch_id, user_id, opening_amount, notes) VALUES (?, ?, ?, ?)");
                $stmt->execute([$branchId, $userId, $opening, $notes]);
                log_activity($pdo, 'open_shift', 'cash_drawer', 'Opened shift with opening cash: ' . number_format($opening, 2));
                $message = 'Shift opened successfully.';
            }
        }

        if ($action === 'cash_movement') {
            $session = get_open_session($pdo, $branchId, $userId);
            if (!$session) {
                $error = 'Open a shift first.';
            } else {
                $type = $_POST['type'] ?? 'cash_in';
                $amount = (float)($_POST['amount'] ?? 0);
                $remarks = trim($_POST['remarks'] ?? '');
                if ($amount <= 0) {
                    $error = 'Amount must be greater than zero.';
                } else {
                    $stmt = $pdo->prepare("INSERT INTO cash_drawer_transactions(cash_session_id, branch_id, user_id, type, amount, remarks) VALUES (?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$session['id'], $branchId, $userId, $type, $amount, $remarks]);
                    log_activity($pdo, $type, 'cash_drawer', 'Cash movement amount: ' . number_format($amount, 2) . ' ' . $remarks);
                    $message = 'Cash drawer transaction saved.';
                }
            }
        }

        if ($action === 'close_shift') {
            $session = get_open_session($pdo, $branchId, $userId);
            if (!$session) {
                $error = 'No open shift to close.';
            } else {
                $closing = (float)($_POST['closing_amount'] ?? 0);
                $stmt = $pdo->prepare("SELECT
                    COALESCE(SUM(CASE WHEN type IN ('cash_in','sale_cash','adjustment') THEN amount ELSE 0 END),0) AS cash_add,
                    COALESCE(SUM(CASE WHEN type IN ('cash_out','refund') THEN amount ELSE 0 END),0) AS cash_less
                    FROM cash_drawer_transactions WHERE cash_session_id = ?");
                $stmt->execute([$session['id']]);
                $totals = $stmt->fetch();
                $expected = (float)$session['opening_amount'] + (float)$totals['cash_add'] - (float)$totals['cash_less'];
                $variance = $closing - $expected;
                $notes = trim($_POST['notes'] ?? '');
                $stmt = $pdo->prepare("UPDATE cash_sessions SET closing_amount = ?, expected_amount = ?, variance_amount = ?, status = 'closed', closed_at = NOW(), notes = CONCAT(COALESCE(notes,''), ?) WHERE id = ?");
                $stmt->execute([$closing, $expected, $variance, "\nClose notes: " . $notes, $session['id']]);
                log_activity($pdo, 'close_shift', 'cash_drawer', 'Closed shift. Expected: ' . number_format($expected, 2) . ', Actual: ' . number_format($closing, 2) . ', Variance: ' . number_format($variance, 2));
                $message = 'Shift closed successfully.';
            }
        }
    } catch (Throwable $e) {
        $error = 'Action failed: ' . $e->getMessage();
    }
}

$openSession = get_open_session($pdo, $branchId, $userId);
$sessionTotals = ['cash_add' => 0, 'cash_less' => 0];
$transactions = [];
if ($openSession) {
    $stmt = $pdo->prepare("SELECT
        COALESCE(SUM(CASE WHEN type IN ('cash_in','sale_cash','adjustment') THEN amount ELSE 0 END),0) AS cash_add,
        COALESCE(SUM(CASE WHEN type IN ('cash_out','refund') THEN amount ELSE 0 END),0) AS cash_less
        FROM cash_drawer_transactions WHERE cash_session_id = ?");
    $stmt->execute([$openSession['id']]);
    $sessionTotals = $stmt->fetch();
    $stmt = $pdo->prepare("SELECT * FROM cash_drawer_transactions WHERE cash_session_id = ? ORDER BY created_at DESC LIMIT 100");
    $stmt->execute([$openSession['id']]);
    $transactions = $stmt->fetchAll();
}
$expectedCash = $openSession ? ((float)$openSession['opening_amount'] + (float)$sessionTotals['cash_add'] - (float)$sessionTotals['cash_less']) : 0;

$stmt = $pdo->prepare("SELECT cs.*, u.name AS user_name FROM cash_sessions cs JOIN users u ON u.id = cs.user_id WHERE cs.branch_id = ? ORDER BY cs.opened_at DESC LIMIT 50");
$stmt->execute([$branchId]);
$sessions = $stmt->fetchAll();
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h3 class="mb-0">Cash Drawer & Shift Management</h3>
        <div class="text-muted">Open shifts, record cash in/out, and close drawer counts.</div>
    </div>
    <span class="badge <?= $openSession ? 'text-bg-success' : 'text-bg-secondary' ?> fs-6"><?= $openSession ? 'Shift Open' : 'No Open Shift' ?></span>
</div>
<?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<div class="row g-3 mb-3">
    <div class="col-md-3"><div class="metric-card"><div class="metric-label">Opening Cash</div><div class="metric-value">₱<?= number_format((float)($openSession['opening_amount'] ?? 0), 2) ?></div></div></div>
    <div class="col-md-3"><div class="metric-card"><div class="metric-label">Cash In</div><div class="metric-value">₱<?= number_format((float)$sessionTotals['cash_add'], 2) ?></div></div></div>
    <div class="col-md-3"><div class="metric-card"><div class="metric-label">Cash Out</div><div class="metric-value">₱<?= number_format((float)$sessionTotals['cash_less'], 2) ?></div></div></div>
    <div class="col-md-3"><div class="metric-card"><div class="metric-label">Expected Cash</div><div class="metric-value">₱<?= number_format($expectedCash, 2) ?></div></div></div>
</div>

<div class="row g-3">
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm mb-3"><div class="card-body">
            <h5><?= $openSession ? 'Add Cash Movement' : 'Open Shift' ?></h5>
            <?php if (!$openSession): ?>
            <form method="post">
                <input type="hidden" name="action" value="open_shift">
                <label class="form-label">Opening Amount</label>
                <input type="number" step="0.01" min="0" class="form-control mb-2" name="opening_amount" required>
                <label class="form-label">Notes</label>
                <textarea class="form-control mb-3" name="notes" rows="2"></textarea>
                <button class="btn btn-primary w-100"><i class="bi bi-unlock"></i> Open Shift</button>
            </form>
            <?php else: ?>
            <form method="post">
                <input type="hidden" name="action" value="cash_movement">
                <label class="form-label">Type</label>
                <select class="form-select mb-2" name="type">
                    <option value="cash_in">Cash In</option>
                    <option value="cash_out">Cash Out</option>
                    <option value="adjustment">Adjustment</option>
                    <option value="refund">Refund</option>
                </select>
                <label class="form-label">Amount</label>
                <input type="number" step="0.01" min="0.01" class="form-control mb-2" name="amount" required>
                <label class="form-label">Remarks</label>
                <input class="form-control mb-3" name="remarks" placeholder="Reason or reference">
                <button class="btn btn-primary w-100"><i class="bi bi-cash-stack"></i> Save Movement</button>
            </form>
            <hr>
            <form method="post" onsubmit="return confirm('Close this shift now?');">
                <input type="hidden" name="action" value="close_shift">
                <label class="form-label">Actual Closing Cash</label>
                <input type="number" step="0.01" min="0" class="form-control mb-2" name="closing_amount" required>
                <label class="form-label">Close Notes</label>
                <textarea class="form-control mb-3" name="notes" rows="2"></textarea>
                <button class="btn btn-danger w-100"><i class="bi bi-lock"></i> Close Shift</button>
            </form>
            <?php endif; ?>
        </div></div>
    </div>
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm mb-3"><div class="card-body table-responsive">
            <h5>Current Shift Transactions</h5>
            <table class="table table-hover align-middle">
                <thead><tr><th>Date/Time</th><th>Type</th><th>Amount</th><th>Remarks</th></tr></thead>
                <tbody>
                <?php foreach ($transactions as $t): ?>
                    <tr><td><?= htmlspecialchars($t['created_at']) ?></td><td><?= htmlspecialchars($t['type']) ?></td><td>₱<?= number_format((float)$t['amount'], 2) ?></td><td><?= htmlspecialchars($t['remarks'] ?? '') ?></td></tr>
                <?php endforeach; ?>
                <?php if (!$transactions): ?><tr><td colspan="4" class="text-center text-muted py-4">No transactions for current shift.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div></div>
    </div>
</div>

<div class="card border-0 shadow-sm mt-3"><div class="card-body table-responsive">
    <h5>Recent Shifts</h5>
    <table class="table table-hover align-middle">
        <thead><tr><th>Opened</th><th>Closed</th><th>User</th><th>Opening</th><th>Expected</th><th>Actual</th><th>Variance</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($sessions as $s): ?>
            <tr>
                <td><?= htmlspecialchars($s['opened_at']) ?></td><td><?= htmlspecialchars($s['closed_at'] ?? '-') ?></td><td><?= htmlspecialchars($s['user_name']) ?></td>
                <td>₱<?= number_format((float)$s['opening_amount'], 2) ?></td><td><?= $s['expected_amount'] === null ? '-' : '₱' . number_format((float)$s['expected_amount'], 2) ?></td>
                <td><?= $s['closing_amount'] === null ? '-' : '₱' . number_format((float)$s['closing_amount'], 2) ?></td>
                <td><?= $s['variance_amount'] === null ? '-' : '₱' . number_format((float)$s['variance_amount'], 2) ?></td>
                <td><span class="badge <?= $s['status'] === 'open' ? 'text-bg-success' : 'text-bg-dark' ?>"><?= htmlspecialchars($s['status']) ?></span></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div></div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
