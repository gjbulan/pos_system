<aside class="sidebar" id="sidebar">
    <div class="brand">
        <i class="bi bi-shop"></i>
        <span>POS System</span>
        <button class="btn btn-sm btn-outline-light ms-auto d-lg-none" type="button" id="sidebarClose" aria-label="Close navigation">
            <i class="bi bi-x-lg"></i>
        </button>
    </div>
    <nav class="nav flex-column">
        <a class="nav-link" href="<?= app_url('dashboard/index.php') ?>"><i class="bi bi-grid"></i> Dashboard</a>
        <a class="nav-link" href="<?= app_url('pos/index.php') ?>"><i class="bi bi-upc-scan"></i> POS Checkout</a>
        <a class="nav-link" href="<?= app_url('products/index.php') ?>"><i class="bi bi-box"></i> Products</a>
        <a class="nav-link" href="<?= app_url('categories/index.php') ?>"><i class="bi bi-tags"></i> Categories</a>
        <a class="nav-link" href="<?= app_url('inventory/index.php') ?>"><i class="bi bi-clipboard-data"></i> Inventory</a>
        <a class="nav-link" href="<?= app_url('sales/index.php') ?>"><i class="bi bi-receipt"></i> Sales</a>
        <a class="nav-link" href="<?= app_url('cash_drawer/index.php') ?>"><i class="bi bi-cash-coin"></i> Cash Drawer</a>
        <a class="nav-link" href="<?= app_url('customers/index.php') ?>"><i class="bi bi-people"></i> Customers</a>
        <a class="nav-link" href="<?= app_url('suppliers/index.php') ?>"><i class="bi bi-truck"></i> Suppliers</a>
        <a class="nav-link" href="<?= app_url('expenses/index.php') ?>"><i class="bi bi-wallet2"></i> Expenses</a>
        <a class="nav-link" href="<?= app_url('reports/index.php') ?>"><i class="bi bi-bar-chart"></i> Reports</a>
        <a class="nav-link" href="<?= app_url('users/index.php') ?>"><i class="bi bi-person-gear"></i> Users</a>
        <a class="nav-link" href="<?= app_url('permissions/index.php') ?>"><i class="bi bi-key"></i> Permissions</a>
        <a class="nav-link" href="<?= app_url('branches/index.php') ?>"><i class="bi bi-building"></i> Branches</a>
        <a class="nav-link" href="<?= app_url('settings/index.php') ?>"><i class="bi bi-gear"></i> Settings</a>
        <a class="nav-link" href="<?= app_url('audit/index.php') ?>"><i class="bi bi-shield-check"></i> Audit Logs</a>
        <a class="nav-link" href="<?= app_url('backup/index.php') ?>"><i class="bi bi-database-down"></i> Backup & Restore</a>
        <a class="nav-link" href="<?= app_url('system/health.php') ?>"><i class="bi bi-heart-pulse"></i> Health Check</a>
    </nav>
</aside>
