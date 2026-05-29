<?php
$pageTitle = 'Reports & Analytics';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../auth/session.php';

require_login();
require_permission($pdo, 'reports.view');

$branchId = current_branch_id();
$voidedStatus = 'voided';
$filterError = '';

function valid_report_date(string $date): bool
{
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    return $parsed instanceof DateTimeImmutable && $parsed->format('Y-m-d') === $date;
}

function chart_json(array $value): string
{
    $json = json_encode($value, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
    return $json === false ? '[]' : $json;
}

$defaultFrom = date('Y-m-01');
$defaultTo = date('Y-m-d');
$dateFrom = trim($_GET['date_from'] ?? $defaultFrom);
$dateTo = trim($_GET['date_to'] ?? $defaultTo);

if (!valid_report_date($dateFrom)) {
    $dateFrom = $defaultFrom;
    $filterError = 'Invalid start date was reset to the current month.';
}

if (!valid_report_date($dateTo)) {
    $dateTo = $defaultTo;
    $filterError = 'Invalid end date was reset to today.';
}

if ($dateFrom > $dateTo) {
    [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
    $filterError = 'Date range was reordered so the start date comes before the end date.';
}

$summaryStmt = $pdo->prepare('
    SELECT
        COALESCE(SUM(total_amount), 0) AS total_sales,
        COALESCE(SUM(discount_amount), 0) AS total_discounts,
        COUNT(*) AS sale_count
    FROM sales
    WHERE branch_id = ? AND status <> ? AND DATE(created_at) BETWEEN ? AND ?
');
$summaryStmt->execute([$branchId, $voidedStatus, $dateFrom, $dateTo]);
$summary = $summaryStmt->fetch() ?: ['total_sales' => 0, 'total_discounts' => 0, 'sale_count' => 0];
$totalSales = (float)$summary['total_sales'];
$totalDiscounts = (float)$summary['total_discounts'];
$saleCount = (int)$summary['sale_count'];

$returnsStmt = $pdo->prepare('
    SELECT COALESCE(SUM(sr.refund_amount), 0) AS total_returns, COUNT(*) AS return_count
    FROM sales_returns sr
    JOIN sales s ON s.id = sr.sale_id
    WHERE sr.branch_id = ? AND s.status <> ? AND DATE(sr.created_at) BETWEEN ? AND ?
');
$returnsStmt->execute([$branchId, $voidedStatus, $dateFrom, $dateTo]);
$returnSummary = $returnsStmt->fetch() ?: ['total_returns' => 0, 'return_count' => 0];
$totalReturns = (float)$returnSummary['total_returns'];

$voidsStmt = $pdo->prepare('
    SELECT COALESCE(SUM(total_amount), 0) AS void_total, COUNT(*) AS void_count
    FROM sales
    WHERE branch_id = ? AND status = ? AND voided_at IS NOT NULL AND DATE(voided_at) BETWEEN ? AND ?
');
$voidsStmt->execute([$branchId, $voidedStatus, $dateFrom, $dateTo]);
$voidSummary = $voidsStmt->fetch() ?: ['void_total' => 0, 'void_count' => 0];
$voidTotal = (float)$voidSummary['void_total'];
$voidCount = (int)$voidSummary['void_count'];

$expensesStmt = $pdo->prepare('
    SELECT COALESCE(SUM(amount), 0) AS total_expenses, COUNT(*) AS expense_count
    FROM expenses
    WHERE branch_id = ? AND expense_date BETWEEN ? AND ?
');
$expensesStmt->execute([$branchId, $dateFrom, $dateTo]);
$expenseSummary = $expensesStmt->fetch() ?: ['total_expenses' => 0, 'expense_count' => 0];
$totalExpenses = (float)$expenseSummary['total_expenses'];
$expenseCount = (int)$expenseSummary['expense_count'];

$costStmt = $pdo->prepare('
    SELECT COALESCE(SUM(si.qty * COALESCE(p.cost, 0)), 0) AS estimated_cost
    FROM sale_items si
    INNER JOIN sales s ON s.id = si.sale_id
    INNER JOIN products p ON p.id = si.product_id
    WHERE s.branch_id = ? AND s.status <> ? AND DATE(s.created_at) BETWEEN ? AND ?
');
$costStmt->execute([$branchId, $voidedStatus, $dateFrom, $dateTo]);
$estimatedCost = (float)$costStmt->fetchColumn();

$netRevenue = $totalSales - $totalReturns - $totalExpenses;
$estimatedGrossProfit = $totalSales - $estimatedCost;
$estimatedNetProfit = $estimatedGrossProfit - $totalReturns - $totalExpenses;

$dailyTotalsByDate = [];
$dailyLabels = [];
$cursor = new DateTimeImmutable($dateFrom);
$end = new DateTimeImmutable($dateTo);

while ($cursor <= $end) {
    $dateKey = $cursor->format('Y-m-d');
    $dailyTotalsByDate[$dateKey] = 0.0;
    $dailyLabels[] = $cursor->format('M j');
    $cursor = $cursor->modify('+1 day');
}

$salesByDayStmt = $pdo->prepare('
    SELECT DATE(created_at) AS sale_date, COALESCE(SUM(total_amount), 0) AS total
    FROM sales
    WHERE branch_id = ? AND status <> ? AND DATE(created_at) BETWEEN ? AND ?
    GROUP BY DATE(created_at)
    ORDER BY sale_date
');
$salesByDayStmt->execute([$branchId, $voidedStatus, $dateFrom, $dateTo]);

foreach ($salesByDayStmt->fetchAll() as $row) {
    if (array_key_exists($row['sale_date'], $dailyTotalsByDate)) {
        $dailyTotalsByDate[$row['sale_date']] = (float)$row['total'];
    }
}

$dailySalesData = array_values($dailyTotalsByDate);

$topProductsStmt = $pdo->prepare('
    SELECT
        p.name,
        COALESCE(SUM(si.qty), 0) AS total_qty,
        COALESCE(SUM(si.subtotal), 0) AS total_sales
    FROM sale_items si
    INNER JOIN sales s ON s.id = si.sale_id
    INNER JOIN products p ON p.id = si.product_id
    WHERE s.branch_id = ? AND s.status <> ? AND DATE(s.created_at) BETWEEN ? AND ?
    GROUP BY p.id, p.name
    ORDER BY total_qty DESC, total_sales DESC
    LIMIT 10
');
$topProductsStmt->execute([$branchId, $voidedStatus, $dateFrom, $dateTo]);
$topProducts = $topProductsStmt->fetchAll();

$topProductLabels = [];
$topProductQtyData = [];
foreach ($topProducts as $product) {
    $topProductLabels[] = $product['name'];
    $topProductQtyData[] = (int)$product['total_qty'];
}

if (!$topProductLabels) {
    $topProductLabels = ['No sales'];
    $topProductQtyData = [0];
}

$paymentStmt = $pdo->prepare('
    SELECT
        payment_method,
        COUNT(*) AS sale_count,
        COALESCE(SUM(total_amount), 0) AS total_sales
    FROM sales
    WHERE branch_id = ? AND status <> ? AND DATE(created_at) BETWEEN ? AND ?
    GROUP BY payment_method
    ORDER BY total_sales DESC, payment_method ASC
');
$paymentStmt->execute([$branchId, $voidedStatus, $dateFrom, $dateTo]);
$payments = $paymentStmt->fetchAll();

$paymentLabels = [];
$paymentData = [];
foreach ($payments as $payment) {
    $paymentLabels[] = $payment['payment_method'] ?: 'Unspecified';
    $paymentData[] = (float)$payment['total_sales'];
}

if (!$paymentLabels) {
    $paymentLabels = ['No payments'];
    $paymentData = [0];
}

$cashierStmt = $pdo->prepare('
    SELECT
        COALESCE(u.name, "Unknown") AS cashier,
        COUNT(s.id) AS sale_count,
        COALESCE(SUM(s.total_amount), 0) AS total_sales
    FROM sales s
    LEFT JOIN users u ON u.id = s.user_id
    WHERE s.branch_id = ? AND s.status <> ? AND DATE(s.created_at) BETWEEN ? AND ?
    GROUP BY s.user_id, u.name
    ORDER BY total_sales DESC, sale_count DESC
    LIMIT 10
');
$cashierStmt->execute([$branchId, $voidedStatus, $dateFrom, $dateTo]);
$cashiers = $cashierStmt->fetchAll();

$cashierLabels = [];
$cashierData = [];
foreach ($cashiers as $cashier) {
    $cashierLabels[] = $cashier['cashier'];
    $cashierData[] = (float)$cashier['total_sales'];
}

if (!$cashierLabels) {
    $cashierLabels = ['No sales'];
    $cashierData = [0];
}

$lowStockStmt = $pdo->prepare('
    SELECT id, name, sku, barcode, stock_qty, low_stock_threshold
    FROM products
    WHERE branch_id = ? AND stock_qty <= low_stock_threshold
    ORDER BY stock_qty ASC, name ASC
    LIMIT 25
');
$lowStockStmt->execute([$branchId]);
$lowStockProducts = $lowStockStmt->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<?php if ($filterError): ?>
    <div class="alert alert-warning alert-dismissible fade show" role="alert">
        <?= htmlspecialchars($filterError) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
    <div>
        <h4 class="mb-0">Reports & Analytics</h4>
        <small class="text-muted">
            <?= htmlspecialchars(date('M d, Y', strtotime($dateFrom))) ?>
            to
            <?= htmlspecialchars(date('M d, Y', strtotime($dateTo))) ?>
        </small>
    </div>
    <form method="get" action="<?= app_url('reports/index.php') ?>" class="row g-2 align-items-end">
        <div class="col-auto">
            <label class="form-label">From</label>
            <input class="form-control" type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>">
        </div>
        <div class="col-auto">
            <label class="form-label">To</label>
            <input class="form-control" type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>">
        </div>
        <div class="col-auto">
            <button class="btn btn-primary">
                <i class="bi bi-funnel"></i> Apply
            </button>
        </div>
    </form>
</div>

<div class="row g-4 mb-4">
    <div class="col-md">
        <div class="card stat-card h-100">
            <div class="card-body">
                <p class="text-muted mb-1">Total Sales</p>
                <h3>&#8369;<?= number_format($totalSales, 2) ?></h3>
                <small class="text-muted"><?= $saleCount ?> non-void sale<?= $saleCount === 1 ? '' : 's' ?>; &#8369;<?= number_format($totalReturns, 2) ?> returned</small>
            </div>
        </div>
    </div>
    <div class="col-md">
        <div class="card stat-card h-100">
            <div class="card-body">
                <p class="text-muted mb-1">Discounts</p>
                <h3>&#8369;<?= number_format($totalDiscounts, 2) ?></h3>
                <small class="text-muted">Manual, Senior, and PWD discounts</small>
            </div>
        </div>
    </div>
    <div class="col-md">
        <div class="card stat-card h-100">
            <div class="card-body">
                <p class="text-muted mb-1">Voids</p>
                <h3>&#8369;<?= number_format($voidTotal, 2) ?></h3>
                <small class="text-muted"><?= $voidCount ?> approved void<?= $voidCount === 1 ? '' : 's' ?> excluded from sales</small>
            </div>
        </div>
    </div>
    <div class="col-md">
        <div class="card stat-card h-100">
            <div class="card-body">
                <p class="text-muted mb-1">Total Expenses</p>
                <h3>&#8369;<?= number_format($totalExpenses, 2) ?></h3>
                <small class="text-muted"><?= $expenseCount ?> expense record<?= $expenseCount === 1 ? '' : 's' ?></small>
            </div>
        </div>
    </div>
    <div class="col-md">
        <div class="card stat-card h-100">
            <div class="card-body">
                <p class="text-muted mb-1">Net Revenue</p>
                <h3>&#8369;<?= number_format($netRevenue, 2) ?></h3>
                <small class="text-muted">Sales less returns and expenses</small>
            </div>
        </div>
    </div>
    <div class="col-md">
        <div class="card stat-card h-100">
            <div class="card-body">
                <p class="text-muted mb-1">Estimated Net Profit</p>
                <h3>&#8369;<?= number_format($estimatedNetProfit, 2) ?></h3>
                <small class="text-muted">Uses current product cost</small>
            </div>
        </div>
    </div>
</div>

<div class="row g-4 mb-4">
    <div class="col-lg-8">
        <div class="card soft-card h-100">
            <div class="card-body">
                <h5 class="mb-3">Sales by Day</h5>
                <canvas id="salesByDayChart" height="120"></canvas>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card soft-card h-100">
            <div class="card-body">
                <h5 class="mb-3">Payment Methods</h5>
                <canvas id="paymentChart"></canvas>
            </div>
        </div>
    </div>
</div>

<div class="row g-4 mb-4">
    <div class="col-lg-6">
        <div class="card soft-card h-100">
            <div class="card-body">
                <h5 class="mb-3">Top Selling Products</h5>
                <canvas id="topProductsChart"></canvas>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card soft-card h-100">
            <div class="card-body">
                <h5 class="mb-3">Cashier Performance</h5>
                <canvas id="cashierChart"></canvas>
            </div>
        </div>
    </div>
</div>

<div class="row g-4 mb-4">
    <div class="col-lg-6">
        <div class="table-card h-100">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="mb-0">Top Products</h5>
                <span class="badge text-bg-light"><?= count($topProducts) ?> shown</span>
            </div>
            <table class="table align-middle mb-0">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th class="text-end">Qty Sold</th>
                        <th class="text-end">Sales</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($topProducts as $product): ?>
                        <tr>
                            <td class="fw-semibold"><?= htmlspecialchars($product['name']) ?></td>
                            <td class="text-end"><?= (int)$product['total_qty'] ?></td>
                            <td class="text-end">&#8369;<?= number_format((float)$product['total_sales'], 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$topProducts): ?>
                        <tr>
                            <td colspan="3" class="text-center text-muted py-4">No product sales found for this range.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="table-card h-100">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="mb-0">Cashier Performance</h5>
                <span class="badge text-bg-light"><?= count($cashiers) ?> shown</span>
            </div>
            <table class="table align-middle mb-0">
                <thead>
                    <tr>
                        <th>Cashier</th>
                        <th class="text-end">Sales Count</th>
                        <th class="text-end">Sales</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($cashiers as $cashier): ?>
                        <tr>
                            <td class="fw-semibold"><?= htmlspecialchars($cashier['cashier']) ?></td>
                            <td class="text-end"><?= (int)$cashier['sale_count'] ?></td>
                            <td class="text-end">&#8369;<?= number_format((float)$cashier['total_sales'], 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$cashiers): ?>
                        <tr>
                            <td colspan="3" class="text-center text-muted py-4">No cashier sales found for this range.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="table-card">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-3">
        <div>
            <h5 class="mb-0">Low Stock Report</h5>
            <small class="text-muted">Current branch items at or below their low stock threshold.</small>
        </div>
        <a class="btn btn-sm btn-outline-primary" href="<?= app_url('products/index.php') ?>">View Products</a>
    </div>
    <table class="table align-middle mb-0">
        <thead>
            <tr>
                <th>Product</th>
                <th>SKU / Barcode</th>
                <th class="text-end">Stock</th>
                <th class="text-end">Threshold</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($lowStockProducts as $product): ?>
                <tr>
                    <td class="fw-semibold"><?= htmlspecialchars($product['name']) ?></td>
                    <td><code><?= htmlspecialchars($product['sku'] ?: ($product['barcode'] ?: '-')) ?></code></td>
                    <td class="text-end"><?= (int)$product['stock_qty'] ?></td>
                    <td class="text-end"><?= (int)$product['low_stock_threshold'] ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$lowStockProducts): ?>
                <tr>
                    <td colspan="4" class="text-center text-muted py-4">No low stock items for this branch.</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const currencySymbol = '\u20b1';
    const formatMoney = (value) => currencySymbol + Number(value || 0).toLocaleString(undefined, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });

    new Chart(document.getElementById('salesByDayChart'), {
        type: 'bar',
        data: {
            labels: <?= chart_json($dailyLabels) ?>,
            datasets: [{
                label: 'Sales',
                data: <?= chart_json($dailySalesData) ?>,
                backgroundColor: '#2563eb',
                borderRadius: 8
            }]
        },
        options: {
            responsive: true,
            plugins: {
                tooltip: {
                    callbacks: {
                        label: (context) => 'Sales: ' + formatMoney(context.parsed.y)
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: (value) => formatMoney(value)
                    }
                }
            }
        }
    });

    new Chart(document.getElementById('paymentChart'), {
        type: 'doughnut',
        data: {
            labels: <?= chart_json($paymentLabels) ?>,
            datasets: [{
                data: <?= chart_json($paymentData) ?>,
                backgroundColor: ['#2563eb', '#16a34a', '#f59e0b', '#dc2626', '#7c3aed'],
                borderWidth: 0
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: {
                    position: 'bottom'
                },
                tooltip: {
                    callbacks: {
                        label: (context) => context.label + ': ' + formatMoney(context.parsed)
                    }
                }
            }
        }
    });

    new Chart(document.getElementById('topProductsChart'), {
        type: 'bar',
        data: {
            labels: <?= chart_json($topProductLabels) ?>,
            datasets: [{
                label: 'Units Sold',
                data: <?= chart_json($topProductQtyData) ?>,
                backgroundColor: '#16a34a',
                borderRadius: 8
            }]
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            plugins: {
                legend: {
                    display: false
                }
            },
            scales: {
                x: {
                    beginAtZero: true,
                    ticks: {
                        precision: 0
                    }
                }
            }
        }
    });

    new Chart(document.getElementById('cashierChart'), {
        type: 'bar',
        data: {
            labels: <?= chart_json($cashierLabels) ?>,
            datasets: [{
                label: 'Sales',
                data: <?= chart_json($cashierData) ?>,
                backgroundColor: '#f59e0b',
                borderRadius: 8
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: {
                    display: false
                },
                tooltip: {
                    callbacks: {
                        label: (context) => 'Sales: ' + formatMoney(context.parsed.y)
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: (value) => formatMoney(value)
                    }
                }
            }
        }
    });
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
