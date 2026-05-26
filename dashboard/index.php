<?php
$pageTitle = 'Dashboard';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/header.php';
$branchId = current_branch_id();
$todaySales = $pdo->prepare('SELECT COALESCE(SUM(total_amount),0) total FROM sales WHERE branch_id=? AND DATE(created_at)=CURDATE() AND status="completed"');
$todaySales->execute([$branchId]);
$salesTotal = $todaySales->fetchColumn();
$orders = $pdo->prepare('SELECT COUNT(*) FROM sales WHERE branch_id=? AND DATE(created_at)=CURDATE()');
$orders->execute([$branchId]);
$orderCount = $orders->fetchColumn();
$low = $pdo->prepare('SELECT COUNT(*) FROM products WHERE branch_id=? AND stock_qty <= low_stock_threshold');
$low->execute([$branchId]);
$lowCount = $low->fetchColumn();
?>
<div class="row g-4 mb-4">
  <div class="col-md-4"><div class="card stat-card"><div class="card-body d-flex justify-content-between"><div><p class="text-muted mb-1">Today's Sales</p><h3>₱<?= number_format($salesTotal,2) ?></h3></div><div class="stat-icon"><i class="bi bi-cash-stack"></i></div></div></div></div>
  <div class="col-md-4"><div class="card stat-card"><div class="card-body d-flex justify-content-between"><div><p class="text-muted mb-1">Orders</p><h3><?= (int)$orderCount ?></h3></div><div class="stat-icon"><i class="bi bi-bag-check"></i></div></div></div></div>
  <div class="col-md-4"><div class="card stat-card"><div class="card-body d-flex justify-content-between"><div><p class="text-muted mb-1">Low Stock</p><h3><?= (int)$lowCount ?></h3></div><div class="stat-icon"><i class="bi bi-exclamation-triangle"></i></div></div></div></div>
</div>
<div class="row g-4">
  <div class="col-lg-8"><div class="card soft-card"><div class="card-body"><h5>Sales Overview</h5><canvas id="salesChart" height="120"></canvas></div></div></div>
  <div class="col-lg-4"><div class="card soft-card"><div class="card-body"><h5>Payment Methods</h5><canvas id="paymentChart"></canvas></div></div></div>
</div>
<script>
document.addEventListener('DOMContentLoaded', () => {
 new Chart(document.getElementById('salesChart'), {type:'line', data:{labels:['Mon','Tue','Wed','Thu','Fri','Sat','Sun'], datasets:[{label:'Sales', data:[1200,1900,900,2400,2100,3200,2800]}]}});
 new Chart(document.getElementById('paymentChart'), {type:'doughnut', data:{labels:['Cash','GCash','Card'], datasets:[{data:[70,20,10]}]}});
});
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
