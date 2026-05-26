<?php
$pageTitle = 'POS Checkout';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/header.php';
$branchId = current_branch_id();
$products = $pdo->prepare('SELECT id,name,barcode,sku,price,stock_qty FROM products WHERE branch_id=? AND stock_qty > 0 ORDER BY name');
$products->execute([$branchId]);
?>
<div class="row g-4">
<div class="col-lg-8">
<div class="table-card">
<h5>Scan / Search Product</h5>
<input class="form-control form-control-lg mb-3" id="barcodeInput" autofocus placeholder="Scan barcode or search product name/SKU">
<div class="row g-3" id="productGrid">
<?php foreach($products as $p): ?>
<div class="col-md-4 product-card" data-search="<?= htmlspecialchars(strtolower($p['name'].' '.$p['barcode'].' '.$p['sku'])) ?>">
<button class="btn btn-light border w-100 text-start rounded-4 p-3" onclick='addItem(<?= json_encode($p) ?>)'>
<strong><?= htmlspecialchars($p['name']) ?></strong><br><small class="text-muted">Barcode: <?= htmlspecialchars($p['barcode'] ?: 'N/A') ?></small><br><span>₱<?= number_format($p['price'],2) ?></span><br><small>Stock: <?= (int)$p['stock_qty'] ?></small>
</button>
</div>
<?php endforeach; ?>
</div>
</div>
</div>
<div class="col-lg-4"><div class="table-card pos-cart"><h5>Cart</h5><form method="post" action="checkout.php" id="checkoutForm"><div id="cartItems"></div><hr><div class="d-flex justify-content-between"><strong>Total</strong><strong id="cartTotal">₱0.00</strong></div><input type="hidden" name="cart_json" id="cartJson"><label class="form-label mt-3">Payment Method</label><select class="form-select" name="payment_method"><option>Cash</option><option>GCash</option><option>Card</option></select><label class="form-label mt-3">Amount Tendered</label><input class="form-control" type="number" step="0.01" name="amount_tendered" required><button class="btn btn-primary w-100 mt-3">Complete Sale</button></form></div></div>
</div>
<script>
let cart = [];
function addItem(p){ const found=cart.find(i=>i.id==p.id); if(found){ if(found.qty < Number(p.stock_qty)) found.qty++; } else cart.push({id:p.id,name:p.name,price:Number(p.price),qty:1,stock:Number(p.stock_qty)}); renderCart(); }
function removeItem(id){ cart = cart.filter(i=>i.id!==id); renderCart(); }
function changeQty(id,qty){ const item=cart.find(i=>i.id===id); if(!item) return; item.qty=Math.max(1,Math.min(Number(qty),item.stock)); renderCart(); }
function renderCart(){ let total=0; const wrap=document.getElementById('cartItems'); wrap.innerHTML=''; cart.forEach(i=>{ total+=i.price*i.qty; wrap.innerHTML += `<div class="d-flex justify-content-between align-items-center border-bottom py-2"><div><strong>${i.name}</strong><br><small>${money(i.price)} each</small></div><div class="d-flex gap-1 align-items-center"><input class="form-control form-control-sm" style="width:70px" type="number" value="${i.qty}" min="1" max="${i.stock}" onchange="changeQty(${i.id},this.value)"><button type="button" class="btn btn-sm btn-outline-danger" onclick="removeItem(${i.id})">x</button></div></div>`; }); document.getElementById('cartTotal').textContent=money(total); document.getElementById('cartJson').value=JSON.stringify(cart); }
document.getElementById('barcodeInput').addEventListener('input', e=>{ const q=e.target.value.toLowerCase(); document.querySelectorAll('.product-card').forEach(c=>c.style.display=c.dataset.search.includes(q)?'':'none'); const exact=[...document.querySelectorAll('.product-card')].find(c=>c.dataset.search.split(' ').includes(q)); if(exact && q.length>=6){ exact.querySelector('button').click(); e.target.value=''; }});
document.getElementById('checkoutForm').addEventListener('submit', e=>{ if(cart.length===0){ e.preventDefault(); alert('Cart is empty.'); }});
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
