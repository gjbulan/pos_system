<?php
$pageTitle = 'Dashboard';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../auth/session.php';

require_login();

$branchId = current_branch_id();
$today = date('Y-m-d');
$completedStatus = 'completed';

function chart_json(array $value): string
{
    $json = json_encode($value, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
    return $json === false ? '[]' : $json;
}

$todaySales = $pdo->prepare('
    SELECT COALESCE(SUM(total_amount), 0) AS total
    FROM sales
    WHERE branch_id = ? AND DATE(created_at) = ? AND status = ?
');
$todaySales->execute([$branchId, $today, $completedStatus]);
$salesTotal = (float)$todaySales->fetchColumn();

$orders = $pdo->prepare('
    SELECT COUNT(*)
    FROM sales
    WHERE branch_id = ? AND DATE(created_at) = ?
');
$orders->execute([$branchId, $today]);
$orderCount = (int)$orders->fetchColumn();

$low = $pdo->prepare('
    SELECT COUNT(*)
    FROM products
    WHERE branch_id = ? AND stock_qty <= low_stock_threshold
');
$low->execute([$branchId]);
$lowCount = (int)$low->fetchColumn();

$todayExpenses = $pdo->prepare('
    SELECT COALESCE(SUM(amount), 0) AS total
    FROM expenses
    WHERE branch_id = ? AND expense_date = ?
');
$todayExpenses->execute([$branchId, $today]);
$expenseTotal = (float)$todayExpenses->fetchColumn();

$endDate = new DateTimeImmutable('today');
$trendTotalsByDate = [];
$salesTrendLabels = [];

for ($i = 6; $i >= 0; $i--) {
    $date = $endDate->modify("-{$i} days");
    $dateKey = $date->format('Y-m-d');
    $trendTotalsByDate[$dateKey] = 0.0;
    $salesTrendLabels[] = $date->format('M j');
}

$trendDates = array_keys($trendTotalsByDate);
$salesTrend = $pdo->prepare('
    SELECT DATE(created_at) AS sale_date, COALESCE(SUM(total_amount), 0) AS total
    FROM sales
    WHERE branch_id = ? AND status = ? AND DATE(created_at) BETWEEN ? AND ?
    GROUP BY DATE(created_at)
    ORDER BY sale_date
');
$salesTrend->execute([$branchId, $completedStatus, $trendDates[0], $trendDates[count($trendDates) - 1]]);

foreach ($salesTrend->fetchAll() as $row) {
    if (array_key_exists($row['sale_date'], $trendTotalsByDate)) {
        $trendTotalsByDate[$row['sale_date']] = (float)$row['total'];
    }
}

$salesTrendData = array_values($trendTotalsByDate);

$topProductsStmt = $pdo->prepare('
    SELECT p.name, COALESCE(SUM(si.qty), 0) AS total_qty, COALESCE(SUM(si.subtotal), 0) AS total_sales
    FROM sale_items si
    INNER JOIN sales s ON s.id = si.sale_id
    INNER JOIN products p ON p.id = si.product_id
    WHERE s.branch_id = ? AND s.status = ?
    GROUP BY p.id, p.name
    ORDER BY total_qty DESC, total_sales DESC
    LIMIT 5
');
$topProductsStmt->execute([$branchId, $completedStatus]);
$topProducts = $topProductsStmt->fetchAll();

$topProductLabels = [];
$topProductData = [];
foreach ($topProducts as $product) {
    $topProductLabels[] = $product['name'];
    $topProductData[] = (int)$product['total_qty'];
}

if (!$topProductLabels) {
    $topProductLabels = ['No sales yet'];
    $topProductData = [0];
}

$paymentStmt = $pdo->prepare('
    SELECT payment_method, COUNT(*) AS sale_count
    FROM sales
    WHERE branch_id = ? AND status = ?
    GROUP BY payment_method
    ORDER BY sale_count DESC, payment_method ASC
');
$paymentStmt->execute([$branchId, $completedStatus]);
$paymentRows = $paymentStmt->fetchAll();

$paymentLabels = [];
$paymentData = [];
foreach ($paymentRows as $payment) {
    $paymentLabels[] = $payment['payment_method'] ?: 'Unspecified';
    $paymentData[] = (int)$payment['sale_count'];
}

if (!$paymentLabels) {
    $paymentLabels = ['No payments yet'];
    $paymentData = [0];
}

$lowStockStmt = $pdo->prepare('
    SELECT id, name, sku, barcode, stock_qty, low_stock_threshold
    FROM products
    WHERE branch_id = ? AND stock_qty <= low_stock_threshold
    ORDER BY stock_qty ASC, name ASC
    LIMIT 8
');
$lowStockStmt->execute([$branchId]);
$lowStockProducts = $lowStockStmt->fetchAll();

$recentSalesStmt = $pdo->prepare('
    SELECT s.id, s.invoice_no, s.total_amount, s.payment_method, s.status, s.created_at, u.name AS cashier
    FROM sales s
    LEFT JOIN users u ON u.id = s.user_id
    WHERE s.branch_id = ?
    ORDER BY s.created_at DESC, s.id DESC
    LIMIT 8
');
$recentSalesStmt->execute([$branchId]);
$recentSales = $recentSalesStmt->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<div class="row g-4 mb-4">
    <div class="col-md-3">
        <div class="card stat-card">
            <div class="card-body d-flex justify-content-between">
                <div>
                    <p class="text-muted mb-1">Today's Sales</p>
                    <h3>&#8369;<?= number_format($salesTotal, 2) ?></h3>
                </div>
                <div class="stat-icon"><i class="bi bi-cash-stack"></i></div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card">
            <div class="card-body d-flex justify-content-between">
                <div>
                    <p class="text-muted mb-1">Orders</p>
                    <h3><?= $orderCount ?></h3>
                </div>
                <div class="stat-icon"><i class="bi bi-bag-check"></i></div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card">
            <div class="card-body d-flex justify-content-between">
                <div>
                    <p class="text-muted mb-1">Low Stock</p>
                    <h3><?= $lowCount ?></h3>
                </div>
                <div class="stat-icon"><i class="bi bi-exclamation-triangle"></i></div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card">
            <div class="card-body d-flex justify-content-between">
                <div>
                    <p class="text-muted mb-1">Today's Expenses</p>
                    <h3>&#8369;<?= number_format($expenseTotal, 2) ?></h3>
                </div>
                <div class="stat-icon"><i class="bi bi-wallet2"></i></div>
            </div>
        </div>
    </div>
</div>

<div class="row g-4 mb-4">
    <div class="col-lg-8">
        <div class="card soft-card h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="mb-0">Sales Trend</h5>
                    <small class="text-muted">Last 7 days</small>
                </div>
                <canvas id="salesChart" height="120"></canvas>
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
    <div class="col-lg-5">
        <div class="card soft-card h-100">
            <div class="card-body">
                <h5 class="mb-3">Top Selling Products</h5>
                <canvas id="topProductsChart"></canvas>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="table-card h-100">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="mb-0">Low Stock Items</h5>
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
                            <td>
                                <code><?= htmlspecialchars($product['sku'] ?: ($product['barcode'] ?: '-')) ?></code>
                            </td>
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
    </div>
</div>

<div class="table-card">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="mb-0">Recent Transactions</h5>
        <a class="btn btn-sm btn-outline-primary" href="<?= app_url('sales/index.php') ?>">View Sales</a>
    </div>
    <table class="table align-middle mb-0">
        <thead>
            <tr>
                <th>Invoice</th>
                <th>Cashier</th>
                <th>Payment</th>
                <th>Status</th>
                <th>Date</th>
                <th class="text-end">Total</th>
                <th class="text-end"></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($recentSales as $sale): ?>
                <tr>
                    <td><code><?= htmlspecialchars($sale['invoice_no']) ?></code></td>
                    <td><?= htmlspecialchars($sale['cashier'] ?? 'N/A') ?></td>
                    <td><?= htmlspecialchars($sale['payment_method']) ?></td>
                    <td>
                        <span class="badge <?= $sale['status'] === 'completed' ? 'text-bg-success' : 'text-bg-secondary' ?>">
                            <?= htmlspecialchars($sale['status']) ?>
                        </span>
                    </td>
                    <td><?= htmlspecialchars(date('M d, Y h:i A', strtotime($sale['created_at']))) ?></td>
                    <td class="text-end">&#8369;<?= number_format((float)$sale['total_amount'], 2) ?></td>
                    <td class="text-end">
                        <a class="btn btn-sm btn-outline-primary" href="<?= app_url('sales/receipt.php?id=' . (int)$sale['id']) ?>">Receipt</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$recentSales): ?>
                <tr>
                    <td colspan="7" class="text-center text-muted py-4">No transactions found for this branch.</td>
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

    new Chart(document.getElementById('salesChart'), {
        type: 'line',
        data: {
            labels: <?= chart_json($salesTrendLabels) ?>,
            datasets: [{
                label: 'Sales',
                data: <?= chart_json($salesTrendData) ?>,
                borderColor: '#2563eb',
                backgroundColor: 'rgba(37, 99, 235, 0.14)',
                fill: true,
                tension: 0.35,
                pointRadius: 4
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
                data: <?= chart_json($topProductData) ?>,
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
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
