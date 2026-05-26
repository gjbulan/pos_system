<?php $pageTitle='Categories'; require_once __DIR__.'/../config/database.php'; require_once __DIR__.'/../includes/header.php'; $branchId=current_branch_id();
if($_SERVER['REQUEST_METHOD']==='POST'){ $stmt=$pdo->prepare('INSERT INTO categories(name,branch_id) VALUES(?,?)'); $stmt->execute([$_POST['name'],$branchId]); header('Location:index.php'); exit; }
if(isset($_GET['delete'])){ $stmt=$pdo->prepare('DELETE FROM categories WHERE branch_id=? AND id=?'); $stmt->execute([$branchId,(int)$_GET['delete']]); header('Location:index.php'); exit; }
$stmt=$pdo->prepare('SELECT * FROM categories WHERE branch_id=? ORDER BY name'); $stmt->execute([$branchId]); ?>
<div class="table-card"><h5>Categories</h5><form method="post" class="d-flex gap-2 mb-3"><input class="form-control" name="name" placeholder="Category name" required><button class="btn btn-primary">Add</button></form><table class="table"><tr><th>Name</th><th></th></tr><?php foreach($stmt as $r): ?><tr><td><?= htmlspecialchars($r['name']) ?></td><td><a class="btn btn-sm btn-outline-danger" href="?delete=<?= $r['id'] ?>">Delete</a></td></tr><?php endforeach; ?></table></div>
<?php include __DIR__.'/../includes/footer.php'; ?>
