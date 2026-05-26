<?php
$pageTitle = ucfirst(basename(__DIR__));
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="table-card">
  <h5><?= htmlspecialchars($pageTitle) ?></h5>
  <p class="text-muted mb-0">This module is included in the project navigation and ready for detailed CRUD expansion.</p>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
