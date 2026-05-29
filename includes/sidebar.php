<?php
$sidebarLinks = [
    ['label' => 'Dashboard', 'path' => 'dashboard/index.php', 'icon' => 'bi-grid', 'permission' => 'dashboard.view'],
    ['label' => 'POS Checkout', 'path' => 'pos/index.php', 'icon' => 'bi-upc-scan', 'permission' => 'pos.access'],
    ['label' => 'Quotations', 'path' => 'quotations/index.php', 'icon' => 'bi-file-earmark-text', 'permission' => 'quotations.view'],
    ['label' => 'Products', 'path' => 'products/index.php', 'icon' => 'bi-box', 'permission' => 'products.view'],
    ['label' => 'Categories', 'path' => 'categories/index.php', 'icon' => 'bi-tags', 'permission' => 'categories.view'],
    ['label' => 'Inventory', 'path' => 'inventory/index.php', 'icon' => 'bi-clipboard-data', 'permission' => 'inventory.view'],
    ['label' => 'Sales', 'path' => 'sales/index.php', 'icon' => 'bi-receipt', 'permission' => 'sales.view'],
    ['label' => 'Cash Drawer', 'path' => 'cash_drawer/index.php', 'icon' => 'bi-cash-coin', 'permission' => 'cash_drawer.manage'],
    ['label' => 'Z-Read Closing', 'path' => 'closing/index.php', 'icon' => 'bi-clipboard-check', 'permission' => 'closing.view'],
    ['label' => 'Customers', 'path' => 'customers/index.php', 'icon' => 'bi-people', 'permission' => 'customers.view'],
    ['label' => 'Suppliers', 'path' => 'suppliers/index.php', 'icon' => 'bi-truck', 'permission' => 'suppliers.view'],
    ['label' => 'Purchases', 'path' => 'purchases/index.php', 'icon' => 'bi-bag-plus', 'permission' => 'purchases.view'],
    ['label' => 'Expenses', 'path' => 'expenses/index.php', 'icon' => 'bi-wallet2', 'permission' => 'expenses.manage'],
    ['label' => 'Reports', 'path' => 'reports/index.php', 'icon' => 'bi-bar-chart', 'permission' => 'reports.view'],
    ['label' => 'Users', 'path' => 'users/index.php', 'icon' => 'bi-person-gear', 'permission' => 'users.manage'],
    ['label' => 'Permissions', 'path' => 'permissions/index.php', 'icon' => 'bi-key', 'permission' => 'permissions.manage'],
    ['label' => 'Branches', 'path' => 'branches/index.php', 'icon' => 'bi-building', 'permission' => 'branches.manage'],
    ['label' => 'Settings', 'path' => 'settings/index.php', 'icon' => 'bi-gear', 'permission' => 'settings.manage'],
    ['label' => 'Audit Logs', 'path' => 'audit/index.php', 'icon' => 'bi-shield-check', 'permission' => 'audit.view'],
    ['label' => 'Backup & Restore', 'path' => 'backup/index.php', 'icon' => 'bi-database-down', 'permission' => 'backup.manage'],
    ['label' => 'Health Check', 'path' => 'system/health.php', 'icon' => 'bi-heart-pulse', 'permission' => 'settings.manage'],
];
?>
<aside class="sidebar" id="sidebar">
    <div class="brand">
        <i class="bi bi-shop"></i>
        <span>POS System</span>
        <button class="btn btn-sm btn-outline-light ms-auto d-lg-none" type="button" id="sidebarClose" aria-label="Close navigation">
            <i class="bi bi-x-lg"></i>
        </button>
    </div>
    <nav class="nav flex-column">
        <?php foreach ($sidebarLinks as $link): ?>
            <?php
            $canViewLink = true;
            if (isset($link['permission'])) {
                if (isset($pdo) && $pdo instanceof PDO) {
                    $canViewLink = can($pdo, $link['permission']);
                } else {
                    $fallbackPermissions = default_permissions($_SESSION['role'] ?? 'Cashier');
                    $canViewLink = in_array('*', $fallbackPermissions, true) || in_array($link['permission'], $fallbackPermissions, true);
                }
            }
            if (!$canViewLink) {
                continue;
            }
            ?>
            <a class="nav-link" href="<?= app_url($link['path']) ?>"><i class="bi <?= htmlspecialchars($link['icon']) ?>"></i> <?= htmlspecialchars($link['label']) ?></a>
        <?php endforeach; ?>
    </nav>
</aside>
