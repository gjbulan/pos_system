<?php
$pageTitle = 'Z-Read Closing Report';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../auth/session.php';

require_login();
require_permission($pdo, 'closing.view');

$branchId = current_branch_id();
$closingId = (int)($_GET['id'] ?? 0);
$autoPrint = isset($_GET['print']);

function zread_view_money(float $amount): string
{
    return '&#8369;' . number_format($amount, 2);
}

$stmt = $pdo->prepare('
    SELECT
        dc.*,
        b.name AS branch_name,
        b.code AS branch_code,
        cashier.name AS cashier_name,
        cashier.username AS cashier_username,
        closer.name AS closed_by_name,
        cs.status AS session_status,
        cs.notes AS session_notes
    FROM daily_closings dc
    JOIN branches b ON b.id = dc.branch_id
    JOIN users cashier ON cashier.id = dc.user_id
    LEFT JOIN users closer ON closer.id = dc.closed_by
    JOIN cash_sessions cs ON cs.id = dc.cash_session_id
    WHERE dc.id = ? AND dc.branch_id = ?
    LIMIT 1
');
$stmt->execute([$closingId, $branchId]);
$closing = $stmt->fetch();

if (!$closing) {
    http_response_code(404);
    include __DIR__ . '/../includes/header.php';
    echo '<div class="alert alert-danger">Z-read closing was not found for this branch.</div>';
    echo '<a class="btn btn-outline-secondary" href="' . app_url('closing/index.php') . '"><i class="bi bi-arrow-left"></i> Back to Z-Read</a>';
    include __DIR__ . '/../includes/footer.php';
    exit;
}

$totalDiscounts = (float)($closing['total_discounts'] ?? 0);
$voidTotal = (float)($closing['void_total'] ?? 0);
$grossBeforeDiscount = (float)$closing['total_sales'] + $totalDiscounts;
$netSales = (float)$closing['total_sales'] - (float)$closing['returns_refunds'];

include __DIR__ . '/../includes/header.php';
?>

<style>
@media print {
    .sidebar,
    .topbar,
    .d-print-none {
        display: none !important;
    }

    .main-content {
        margin: 0 !important;
        width: 100% !important;
    }

    .page-container {
        padding: 0 !important;
    }

    .table-card,
    .card {
        border: 1px solid #dee2e6 !important;
        box-shadow: none !important;
    }
}
</style>

<div class="d-print-none d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-3">
    <div>
        <h4 class="mb-0">Z-Read Closing #<?= (int)$closing['id'] ?></h4>
        <small class="text-muted">Print-friendly closing report for reconciliation.</small>
    </div>
    <div class="btn-group">
        <a class="btn btn-outline-secondary" href="<?= app_url('closing/index.php') ?>">
            <i class="bi bi-arrow-left"></i> Back
        </a>
        <button class="btn btn-primary" type="button" onclick="window.print()">
            <i class="bi bi-printer"></i> Print
        </button>
    </div>
</div>

<div class="table-card mb-3">
    <div class="text-center mb-4">
        <h3 class="mb-1">Z-READ / DAILY SALES CLOSING</h3>
        <div class="text-muted"><?= htmlspecialchars($closing['branch_name']) ?> (<?= htmlspecialchars($closing['branch_code']) ?>)</div>
    </div>

    <div class="row g-3">
        <div class="col-md-4">
            <small class="text-muted d-block">Closing Number</small>
            <strong>#<?= (int)$closing['id'] ?></strong>
        </div>
        <div class="col-md-4">
            <small class="text-muted d-block">Closing Date</small>
            <strong><?= htmlspecialchars($closing['closing_date']) ?></strong>
        </div>
        <div class="col-md-4">
            <small class="text-muted d-block">Cash Session</small>
            <strong>#<?= (int)$closing['cash_session_id'] ?> (<?= htmlspecialchars($closing['session_status']) ?>)</strong>
        </div>
        <div class="col-md-4">
            <small class="text-muted d-block">Cashier</small>
            <strong><?= htmlspecialchars($closing['cashier_name']) ?></strong>
            <div class="text-muted"><?= htmlspecialchars($closing['cashier_username']) ?></div>
        </div>
        <div class="col-md-4">
            <small class="text-muted d-block">Opened</small>
            <strong><?= htmlspecialchars($closing['opened_at'] ?? '-') ?></strong>
        </div>
        <div class="col-md-4">
            <small class="text-muted d-block">Closed</small>
            <strong><?= htmlspecialchars($closing['closed_at'] ?? '-') ?></strong>
        </div>
        <div class="col-md-4">
            <small class="text-muted d-block">Finalized By</small>
            <strong><?= htmlspecialchars($closing['closed_by_name'] ?? 'N/A') ?></strong>
        </div>
        <div class="col-md-4">
            <small class="text-muted d-block">Finalized At</small>
            <strong><?= htmlspecialchars($closing['created_at']) ?></strong>
        </div>
        <div class="col-md-4">
            <small class="text-muted d-block">Notes</small>
            <strong><?= htmlspecialchars($closing['notes'] ?: '-') ?></strong>
        </div>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-md">
        <div class="metric-card">
            <div class="metric-label">Gross Before Discount</div>
            <div class="metric-value"><?= zread_view_money($grossBeforeDiscount) ?></div>
        </div>
    </div>
    <div class="col-md">
        <div class="metric-card">
            <div class="metric-label">Discounts</div>
            <div class="metric-value"><?= zread_view_money($totalDiscounts) ?></div>
        </div>
    </div>
    <div class="col-md">
        <div class="metric-card">
            <div class="metric-label">Returns / Refunds</div>
            <div class="metric-value"><?= zread_view_money((float)$closing['returns_refunds']) ?></div>
        </div>
    </div>
    <div class="col-md">
        <div class="metric-card">
            <div class="metric-label">Voids</div>
            <div class="metric-value"><?= zread_view_money($voidTotal) ?></div>
        </div>
    </div>
    <div class="col-md">
        <div class="metric-card">
            <div class="metric-label">Net Sales</div>
            <div class="metric-value"><?= zread_view_money($netSales) ?></div>
        </div>
    </div>
    <div class="col-md">
        <div class="metric-card">
            <div class="metric-label">Variance</div>
            <div class="metric-value"><?= zread_view_money((float)$closing['variance']) ?></div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="table-card h-100">
            <h5 class="mb-3">Sales Summary</h5>
            <table class="table table-sm align-middle">
                <tbody>
                    <tr>
                        <th>Sale Count</th>
                        <td class="text-end"><?= (int)$closing['sale_count'] ?></td>
                    </tr>
                    <tr>
                        <th>Gross Before Discount</th>
                        <td class="text-end"><?= zread_view_money($grossBeforeDiscount) ?></td>
                    </tr>
                    <tr>
                        <th>Discounts</th>
                        <td class="text-end"><?= zread_view_money($totalDiscounts) ?></td>
                    </tr>
                    <tr>
                        <th>Net Sales After Discount</th>
                        <td class="text-end"><?= zread_view_money((float)$closing['total_sales']) ?></td>
                    </tr>
                    <tr>
                        <th>Cash Sales</th>
                        <td class="text-end"><?= zread_view_money((float)$closing['cash_sales']) ?></td>
                    </tr>
                    <tr>
                        <th>Non-Cash Sales</th>
                        <td class="text-end"><?= zread_view_money((float)$closing['non_cash_sales']) ?></td>
                    </tr>
                    <tr>
                        <th>Return Count</th>
                        <td class="text-end"><?= (int)$closing['return_count'] ?></td>
                    </tr>
                    <tr>
                        <th>Returns / Refunds</th>
                        <td class="text-end"><?= zread_view_money((float)$closing['returns_refunds']) ?></td>
                    </tr>
                    <tr>
                        <th>Void Count</th>
                        <td class="text-end"><?= (int)($closing['void_count'] ?? 0) ?></td>
                    </tr>
                    <tr>
                        <th>Voided Sales</th>
                        <td class="text-end"><?= zread_view_money($voidTotal) ?></td>
                    </tr>
                    <tr>
                        <th>Expense Count</th>
                        <td class="text-end"><?= (int)$closing['expense_count'] ?></td>
                    </tr>
                    <tr>
                        <th>Expenses</th>
                        <td class="text-end"><?= zread_view_money((float)$closing['expenses']) ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="table-card h-100">
            <h5 class="mb-3">Cash Reconciliation</h5>
            <table class="table table-sm align-middle">
                <tbody>
                    <tr>
                        <th>Opening Cash</th>
                        <td class="text-end"><?= zread_view_money((float)$closing['opening_cash']) ?></td>
                    </tr>
                    <tr>
                        <th>Cash Sales</th>
                        <td class="text-end"><?= zread_view_money((float)$closing['cash_sales']) ?></td>
                    </tr>
                    <tr>
                        <th>Cash In / Adjustments</th>
                        <td class="text-end"><?= zread_view_money((float)$closing['cash_in']) ?></td>
                    </tr>
                    <tr>
                        <th>Cash Out / Drawer Refunds</th>
                        <td class="text-end"><?= zread_view_money((float)$closing['cash_out']) ?></td>
                    </tr>
                    <tr>
                        <th>Expected Cash</th>
                        <td class="text-end"><?= zread_view_money((float)$closing['expected_cash']) ?></td>
                    </tr>
                    <tr>
                        <th>Actual Counted Cash</th>
                        <td class="text-end"><?= zread_view_money((float)$closing['actual_cash']) ?></td>
                    </tr>
                    <tr>
                        <th>Variance</th>
                        <td class="text-end"><?= zread_view_money((float)$closing['variance']) ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="table-card mt-3">
    <h5 class="mb-3">Sign-Off</h5>
    <div class="row g-4">
        <div class="col-md-6">
            <div class="border-bottom pb-4"></div>
            <small class="text-muted">Cashier Signature</small>
        </div>
        <div class="col-md-6">
            <div class="border-bottom pb-4"></div>
            <small class="text-muted">Manager Signature</small>
        </div>
    </div>
</div>

<?php if ($autoPrint): ?>
    <script>
    window.addEventListener('load', () => {
        window.setTimeout(() => window.print(), 350);
    });
    </script>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
