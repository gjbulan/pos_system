<?php
$pageTitle = 'Daily Sales Closing / Z-Read';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../auth/session.php';

require_login();
require_permission($pdo, 'closing.view');

$branchId = current_branch_id();
$currentUserId = (int)($_SESSION['user_id'] ?? 0);
$canManageClosing = can($pdo, 'closing.manage');
$errors = [];
$dateFrom = trim($_GET['date_from'] ?? date('Y-m-01'));
$dateTo = trim($_GET['date_to'] ?? date('Y-m-d'));
$userFilter = trim($_GET['user_id'] ?? '');

function zread_valid_date(string $date): bool
{
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    return $parsed instanceof DateTimeImmutable && $parsed->format('Y-m-d') === $date;
}

function zread_money(float $amount): string
{
    return '&#8369;' . number_format($amount, 2);
}

function zread_build_summary(PDO $pdo, array $session): array
{
    $branchId = (int)$session['branch_id'];
    $userId = (int)$session['user_id'];
    $openedAt = (string)$session['opened_at'];
    $closedAt = (string)$session['closed_at'];
    $closingDate = substr($closedAt, 0, 10);

    $salesStmt = $pdo->prepare('
        SELECT
            COUNT(*) AS sale_count,
            COALESCE(SUM(total_amount), 0) AS total_sales,
            COALESCE(SUM(discount_amount), 0) AS total_discounts,
            COALESCE(SUM(CASE WHEN LOWER(payment_method) = "cash" THEN total_amount ELSE 0 END), 0) AS cash_sales
        FROM sales
        WHERE branch_id = ?
          AND user_id = ?
          AND status = "completed"
          AND created_at BETWEEN ? AND ?
    ');
    $salesStmt->execute([$branchId, $userId, $openedAt, $closedAt]);
    $sales = $salesStmt->fetch() ?: ['sale_count' => 0, 'total_sales' => 0, 'total_discounts' => 0, 'cash_sales' => 0];

    $returnsStmt = $pdo->prepare('
        SELECT COUNT(*) AS return_count, COALESCE(SUM(refund_amount), 0) AS returns_refunds
        FROM sales_returns
        WHERE branch_id = ?
          AND user_id = ?
          AND created_at BETWEEN ? AND ?
    ');
    $returnsStmt->execute([$branchId, $userId, $openedAt, $closedAt]);
    $returns = $returnsStmt->fetch() ?: ['return_count' => 0, 'returns_refunds' => 0];

    $expensesStmt = $pdo->prepare('
        SELECT COUNT(*) AS expense_count, COALESCE(SUM(amount), 0) AS expenses
        FROM expenses
        WHERE branch_id = ?
          AND expense_date = ?
    ');
    $expensesStmt->execute([$branchId, $closingDate]);
    $expenses = $expensesStmt->fetch() ?: ['expense_count' => 0, 'expenses' => 0];

    $cashStmt = $pdo->prepare('
        SELECT
            COALESCE(SUM(CASE WHEN type = "sale_cash" THEN amount ELSE 0 END), 0) AS drawer_cash_sales,
            COALESCE(SUM(CASE WHEN type IN ("cash_in", "adjustment") THEN amount ELSE 0 END), 0) AS cash_in,
            COALESCE(SUM(CASE WHEN type IN ("cash_out", "refund") THEN amount ELSE 0 END), 0) AS cash_out
        FROM cash_drawer_transactions
        WHERE cash_session_id = ?
    ');
    $cashStmt->execute([(int)$session['id']]);
    $cash = $cashStmt->fetch() ?: ['drawer_cash_sales' => 0, 'cash_in' => 0, 'cash_out' => 0];

    $totalSales = (float)$sales['total_sales'];
    $cashSales = (float)$sales['cash_sales'];
    $cashIn = (float)$cash['cash_in'];
    $cashOut = (float)$cash['cash_out'];
    $expectedCash = $session['expected_amount'] !== null
        ? (float)$session['expected_amount']
        : ((float)$session['opening_amount'] + (float)$cash['drawer_cash_sales'] + $cashIn - $cashOut);
    $actualCash = $session['closing_amount'] !== null ? (float)$session['closing_amount'] : 0.0;

    return [
        'closing_date' => $closingDate,
        'opened_at' => $openedAt,
        'closed_at' => $closedAt,
        'opening_cash' => (float)$session['opening_amount'],
        'total_sales' => $totalSales,
        'total_discounts' => (float)$sales['total_discounts'],
        'cash_sales' => $cashSales,
        'non_cash_sales' => max(0, $totalSales - $cashSales),
        'returns_refunds' => (float)$returns['returns_refunds'],
        'expenses' => (float)$expenses['expenses'],
        'cash_in' => $cashIn,
        'cash_out' => $cashOut,
        'expected_cash' => $expectedCash,
        'actual_cash' => $actualCash,
        'variance' => $session['variance_amount'] !== null ? (float)$session['variance_amount'] : ($actualCash - $expectedCash),
        'sale_count' => (int)$sales['sale_count'],
        'return_count' => (int)$returns['return_count'],
        'expense_count' => (int)$expenses['expense_count'],
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_permission($pdo, 'closing.manage');

    $sessionId = (int)($_POST['cash_session_id'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');

    try {
        $pdo->beginTransaction();

        $sessionStmt = $pdo->prepare('
            SELECT cs.*, u.name AS cashier_name
            FROM cash_sessions cs
            JOIN users u ON u.id = cs.user_id
            WHERE cs.id = ? AND cs.branch_id = ?
            FOR UPDATE
        ');
        $sessionStmt->execute([$sessionId, $branchId]);
        $session = $sessionStmt->fetch();

        if (!$session) {
            throw new RuntimeException('Cash session was not found for this branch.');
        }

        if ($session['status'] !== 'closed' || $session['closed_at'] === null) {
            throw new RuntimeException('Only closed cash sessions can be finalized for Z-read.');
        }

        $duplicateStmt = $pdo->prepare('SELECT id FROM daily_closings WHERE cash_session_id = ? LIMIT 1 FOR UPDATE');
        $duplicateStmt->execute([$sessionId]);
        if ($duplicateStmt->fetchColumn()) {
            throw new RuntimeException('This cash session already has a Z-read closing.');
        }

        $summary = zread_build_summary($pdo, $session);

        $insertStmt = $pdo->prepare('
            INSERT INTO daily_closings(
                branch_id, user_id, cash_session_id, closing_date, opened_at, closed_at,
                opening_cash, total_sales, total_discounts, cash_sales, non_cash_sales, returns_refunds, expenses,
                cash_in, cash_out, expected_cash, actual_cash, variance,
                sale_count, return_count, expense_count, notes, closed_by
            )
            VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $insertStmt->execute([
            $branchId,
            (int)$session['user_id'],
            $sessionId,
            $summary['closing_date'],
            $summary['opened_at'],
            $summary['closed_at'],
            $summary['opening_cash'],
            $summary['total_sales'],
            $summary['total_discounts'],
            $summary['cash_sales'],
            $summary['non_cash_sales'],
            $summary['returns_refunds'],
            $summary['expenses'],
            $summary['cash_in'],
            $summary['cash_out'],
            $summary['expected_cash'],
            $summary['actual_cash'],
            $summary['variance'],
            $summary['sale_count'],
            $summary['return_count'],
            $summary['expense_count'],
            $notes !== '' ? $notes : null,
            $currentUserId ?: null,
        ]);

        $closingId = (int)$pdo->lastInsertId();
        log_activity($pdo, 'create_z_read', 'closing', 'Created Z-read closing #' . $closingId . ' for cash session #' . $sessionId);
        $pdo->commit();

        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Z-read closing finalized successfully.'];
        redirect_to('closing/view.php?id=' . $closingId);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $errors[] = $e->getMessage();
    }
}

if (!zread_valid_date($dateFrom)) {
    $dateFrom = date('Y-m-01');
}
if (!zread_valid_date($dateTo)) {
    $dateTo = date('Y-m-d');
}

$usersStmt = $pdo->prepare('
    SELECT DISTINCT u.id, u.name
    FROM cash_sessions cs
    JOIN users u ON u.id = cs.user_id
    WHERE cs.branch_id = ?
    ORDER BY u.name
');
$usersStmt->execute([$branchId]);
$cashiers = $usersStmt->fetchAll();

$availableStmt = $pdo->prepare('
    SELECT cs.*, u.name AS cashier_name
    FROM cash_sessions cs
    JOIN users u ON u.id = cs.user_id
    LEFT JOIN daily_closings dc ON dc.cash_session_id = cs.id
    WHERE cs.branch_id = ?
      AND cs.status = "closed"
      AND dc.id IS NULL
    ORDER BY cs.closed_at DESC, cs.id DESC
    LIMIT 50
');
$availableStmt->execute([$branchId]);
$availableSessions = $availableStmt->fetchAll();

$closingWhere = ['dc.branch_id = ?', 'dc.closing_date BETWEEN ? AND ?'];
$closingParams = [$branchId, $dateFrom, $dateTo];
if ($userFilter !== '' && ctype_digit($userFilter)) {
    $closingWhere[] = 'dc.user_id = ?';
    $closingParams[] = (int)$userFilter;
}

$closingsStmt = $pdo->prepare('
    SELECT dc.*, u.name AS cashier_name, closer.name AS closed_by_name
    FROM daily_closings dc
    JOIN users u ON u.id = dc.user_id
    LEFT JOIN users closer ON closer.id = dc.closed_by
    WHERE ' . implode(' AND ', $closingWhere) . '
    ORDER BY dc.closed_at DESC, dc.id DESC
    LIMIT 300
');
$closingsStmt->execute($closingParams);
$closings = $closingsStmt->fetchAll();

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-3">
    <div>
        <h4 class="mb-0">Daily Sales Closing / Z-Read</h4>
        <small class="text-muted">Finalize closed cash drawer shifts and print reconciliation reports.</small>
    </div>
</div>

<?php if ($flash): ?>
    <div class="alert alert-<?= htmlspecialchars($flash['type']) ?> alert-dismissible fade show" role="alert">
        <?= htmlspecialchars($flash['message']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($errors): ?>
    <div class="alert alert-danger">
        <strong>Z-read was not finalized.</strong>
        <ul class="mb-0">
            <?php foreach ($errors as $error): ?>
                <li><?= htmlspecialchars($error) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?php if ($canManageClosing): ?>
    <div class="table-card mb-3">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <h5 class="mb-0">Closed Shifts Ready for Z-Read</h5>
                <small class="text-muted">A cash session can be finalized once. Close the drawer first if the shift is still open.</small>
            </div>
            <span class="badge text-bg-light"><?= count($availableSessions) ?> ready</span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Session</th>
                        <th>Cashier</th>
                        <th>Opened</th>
                        <th>Closed</th>
                        <th class="text-end">Opening</th>
                        <th class="text-end">Expected</th>
                        <th class="text-end">Actual</th>
                        <th class="text-end">Variance</th>
                        <th style="min-width: 300px;" class="text-end">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($availableSessions as $session): ?>
                        <tr>
                            <td>#<?= (int)$session['id'] ?></td>
                            <td><?= htmlspecialchars($session['cashier_name']) ?></td>
                            <td><?= htmlspecialchars($session['opened_at']) ?></td>
                            <td><?= htmlspecialchars($session['closed_at'] ?? '-') ?></td>
                            <td class="text-end"><?= zread_money((float)$session['opening_amount']) ?></td>
                            <td class="text-end"><?= zread_money((float)($session['expected_amount'] ?? 0)) ?></td>
                            <td class="text-end"><?= zread_money((float)($session['closing_amount'] ?? 0)) ?></td>
                            <td class="text-end"><?= zread_money((float)($session['variance_amount'] ?? 0)) ?></td>
                            <td class="text-end">
                                <form method="post" action="<?= app_url('closing/index.php') ?>" class="d-flex gap-2 justify-content-end">
                                    <input type="hidden" name="cash_session_id" value="<?= (int)$session['id'] ?>">
                                    <input class="form-control form-control-sm" name="notes" maxlength="255" placeholder="Optional notes">
                                    <button class="btn btn-sm btn-primary" onclick="return confirm('Finalize this Z-read closing?');">
                                        <i class="bi bi-check2-circle"></i> Finalize
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$availableSessions): ?>
                        <tr>
                            <td colspan="9" class="text-center text-muted py-4">No closed cash sessions are waiting for Z-read.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<div class="table-card mb-3">
    <form class="row g-2 align-items-end" method="get" action="<?= app_url('closing/index.php') ?>">
        <div class="col-md-3">
            <label class="form-label">Date From</label>
            <input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($dateFrom) ?>">
        </div>
        <div class="col-md-3">
            <label class="form-label">Date To</label>
            <input type="date" name="date_to" class="form-control" value="<?= htmlspecialchars($dateTo) ?>">
        </div>
        <div class="col-md-4">
            <label class="form-label">Cashier</label>
            <select name="user_id" class="form-select">
                <option value="">All cashiers</option>
                <?php foreach ($cashiers as $cashier): ?>
                    <option value="<?= (int)$cashier['id'] ?>" <?= $userFilter === (string)$cashier['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($cashier['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2 d-grid">
            <button class="btn btn-outline-primary">
                <i class="bi bi-funnel"></i> Filter
            </button>
        </div>
    </form>
</div>

<div class="table-card">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="mb-0">Z-Read Closing History</h5>
        <span class="badge text-bg-light"><?= count($closings) ?> shown</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Z-Read</th>
                    <th>Date</th>
                    <th>Cashier</th>
                    <th class="text-end">Total Sales</th>
                    <th class="text-end">Discounts</th>
                    <th class="text-end">Returns</th>
                    <th class="text-end">Expected Cash</th>
                    <th class="text-end">Actual Cash</th>
                    <th class="text-end">Variance</th>
                    <th>Finalized</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($closings as $closing): ?>
                    <tr>
                        <td>#<?= (int)$closing['id'] ?></td>
                        <td><?= htmlspecialchars($closing['closing_date']) ?></td>
                        <td><?= htmlspecialchars($closing['cashier_name']) ?></td>
                        <td class="text-end"><?= zread_money((float)$closing['total_sales']) ?></td>
                        <td class="text-end"><?= zread_money((float)$closing['total_discounts']) ?></td>
                        <td class="text-end"><?= zread_money((float)$closing['returns_refunds']) ?></td>
                        <td class="text-end"><?= zread_money((float)$closing['expected_cash']) ?></td>
                        <td class="text-end"><?= zread_money((float)$closing['actual_cash']) ?></td>
                        <td class="text-end"><?= zread_money((float)$closing['variance']) ?></td>
                        <td>
                            <?= htmlspecialchars($closing['created_at']) ?>
                            <br><small class="text-muted"><?= htmlspecialchars($closing['closed_by_name'] ?? 'N/A') ?></small>
                        </td>
                        <td class="text-end">
                            <div class="btn-group btn-group-sm">
                                <a class="btn btn-outline-primary" href="<?= app_url('closing/view.php?id=' . (int)$closing['id']) ?>">
                                    View
                                </a>
                                <a class="btn btn-outline-success" href="<?= app_url('closing/view.php?id=' . (int)$closing['id'] . '&print=1') ?>">
                                    <i class="bi bi-printer"></i> Print
                                </a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$closings): ?>
                    <tr>
                        <td colspan="11" class="text-center text-muted py-4">No Z-read closings found.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
