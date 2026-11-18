<?php
require_once __DIR__ . '/app/bootstrap.php';
$uid = current_user_id();

$q = trim($_GET['q'] ?? '');
$catId = (int)($_GET['category'] ?? 0);
$countryId = (int)($_GET['country'] ?? 0);
$scope = trim($_GET['scope'] ?? '');
$viewProductId = (int)($_GET['product'] ?? 0);

// Instant Order Checkout (verified Buyer accounts only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'instant_order') {
    require_login();
    if (!user_can_buy($pdo)) {
        flash('Only an active, email-verified Buyer account can purchase items.', 'error');
        header('Location: ' . url('marketplace.php?product=' . (int)($_POST['product_id'] ?? 0))); exit;
    }
    verify_csrf();
    $prodId=(int)($_POST['product_id']??0);
    $qty=filter_var($_POST['quantity']??null,FILTER_VALIDATE_FLOAT);
    $deliveryAddr=trim($_POST['delivery_address']??'');
    $tradeType=($_POST['trade_type']??'domestic')==='cross_border'?'cross_border':'domestic';
    $chosenColor=trim($_POST['chosen_color']??''); $chosenSpec=trim($_POST['chosen_spec']??'');
    $variantSummary=trim(implode(' | ',array_filter([$chosenColor?"Color: {$chosenColor}":'',$chosenSpec])));

    if ($qty===false || $qty<=0 || $deliveryAddr==='') {
        flash('Enter a valid quantity and complete delivery address.', 'error');
        header('Location: '.url('marketplace.php?product='.$prodId)); exit;
    }
    try {
        $pdo->beginTransaction();
        $pStmt=$pdo->prepare("SELECT p.*,u.account_status AS seller_status FROM products p JOIN users u ON u.id=p.seller_id WHERE p.id=? AND p.status='active' AND u.account_status='active' AND u.id_verification_status='verified' AND u.email_verified_at IS NOT NULL FOR UPDATE");
        $pStmt->execute([$prodId]); $prod=$pStmt->fetch();
        if (!$prod || $prod['seller_status']!=='active' || (int)$prod['seller_id']===$uid || $qty<(float)$prod['minimum_order_quantity'] || $qty>(float)$prod['stock_quantity']) {
            $pdo->rollBack();
            flash('This item is unavailable, you cannot buy your own listing, or the quantity is outside available stock/MOQ.', 'error');
            header('Location: '.url('marketplace.php?product='.$prodId)); exit;
        }
        $subtotal=(float)$prod['price']*$qty;
        $shipping=$tradeType==='cross_border'?round($subtotal*0.10,2):round($subtotal*0.03,2);
        $total=$subtotal+$shipping;
        $ref='ATO-'.date('Y').'-'.strtoupper(bin2hex(random_bytes(3)));
        $ins=$pdo->prepare("INSERT INTO orders (order_reference,buyer_id,seller_id,product_id,title,selected_variant,quantity,unit,trade_type,delivery_address,subtotal,shipping_cost,total_amount,currency,status,stock_reserved) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,'pending',1)");
        $ins->execute([$ref,$uid,$prod['seller_id'],$prod['id'],$prod['name'],$variantSummary,$qty,$prod['unit'],$tradeType,$deliveryAddr,$subtotal,$shipping,$total,$prod['currency']]);
        $orderId=(int)$pdo->lastInsertId();
        $reserve=$pdo->prepare('UPDATE products SET stock_quantity=stock_quantity-? WHERE id=? AND stock_quantity>=?');
        $reserve->execute([$qty,$prodId,$qty]);
        if ($reserve->rowCount()!==1) { $pdo->rollBack(); flash('Stock changed while you were checking out. Please refresh and try again.', 'error'); header('Location: '.url('marketplace.php?product='.$prodId)); exit; }
        record_order_event($pdo,$orderId,'order_created_stock_reserved',$uid,'Buyer created an order and stock was reserved pending payment.');
        $pdo->commit();
        notify_user($pdo,(int)$prod['seller_id'],"New Order Placed ({$ref})","Order for {$qty} {$prod['unit']} of {$prod['name']}.",'order','orders.php');
        log_audit($pdo,'order_created','order',$orderId,"Order {$ref}; stock reserved.");
        flash("Order {$ref} created and stock reserved. Complete payment to confirm your purchase.");
        header('Location: '.url('payments.php?order_id='.$orderId)); exit;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Marketplace order creation failed: '.$e->getMessage());
        flash('The order could not be created. Please try again.', 'error');
        header('Location: '.url('marketplace.php?product='.$prodId)); exit;
    }
}

$sql = "SELECT p.*, c.name AS category_name, u.full_name AS seller_name, u.role AS seller_role,
               bp.business_name, co.name AS origin_country
        FROM products p
        LEFT JOIN categories c ON c.id = p.category_id
        JOIN users u ON u.id = p.seller_id
        LEFT JOIN business_profiles bp ON bp.user_id = u.id
        LEFT JOIN countries co ON co.id = p.origin_country_id
        WHERE p.status = 'active' AND p.stock_quantity > 0 AND u.account_status = 'active' AND u.id_verification_status = 'verified' AND u.email_verified_at IS NOT NULL";
$params = [];

if ($q !== '') {
    $sql .= " AND (p.name LIKE ? OR p.description LIKE ? OR bp.business_name LIKE ?)";
    $params = array_merge($params, ["%$q%", "%$q%", "%$q%"]);
}
if ($catId > 0) {
    $sql .= " AND p.category_id = ?";
    $params[] = $catId;
}
if ($countryId > 0) {
    $sql .= " AND p.origin_country_id = ?";
    $params[] = $countryId;
}
if (in_array($scope, ['domestic', 'cross_border'], true)) {
    $sql .= " AND (p.shipping_scope = ? OR p.shipping_scope = 'both')";
    $params[] = $scope;
}

$sql .= " ORDER BY p.is_featured DESC, p.created_at DESC LIMIT 120";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll();

$categories = $pdo->query("SELECT id, name FROM categories WHERE is_active=1 ORDER BY name")->fetchAll();
$countries = $pdo->query("SELECT id, name FROM countries WHERE is_active=1 ORDER BY name")->fetchAll();

$selectedProduct = null;
if ($viewProductId > 0) {
    $sp = $pdo->prepare(
        "SELECT p.*, c.name AS category_name, u.full_name AS seller_name, u.role AS seller_role,
                bp.business_name, bp.city, co.name AS origin_country
         FROM products p
         LEFT JOIN categories c ON c.id = p.category_id
         JOIN users u ON u.id = p.seller_id
         LEFT JOIN business_profiles bp ON bp.user_id = u.id
         LEFT JOIN countries co ON co.id = p.origin_country_id
         WHERE p.id = ? AND p.status = 'active' AND p.stock_quantity > 0 AND u.account_status = 'active' AND u.id_verification_status = 'verified' AND u.email_verified_at IS NOT NULL"
    );
    $sp->execute([$viewProductId]);
    $selectedProduct = $sp->fetch();
}

$pageTitle = 'Marketplace Catalog';
require_once __DIR__ . '/app/includes/header.php';
?>

<main class="container section">
 <?php show_flash(); ?>

 <?php if ($selectedProduct):
  $gallery = json_decode($selectedProduct['gallery_paths'] ?? '[]', true) ?: [];
  $selectedSpecs = json_decode($selectedProduct['selected_specs'] ?? '[]', true) ?: [];
  $colorsList = array_filter(array_map('trim', explode(',', (string)$selectedProduct['available_colors'])));
 ?>
  <div class="panel card-featured" style="margin-bottom:28px;">
   <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
     <span class="badge badge-info"><?= e($selectedProduct['category_name'] ?? 'Product') ?></span>
     <span class="badge badge-success"><?= e($selectedProduct['condition_grade']) ?></span>
     <span class="badge badge-neutral"><?= e($selectedProduct['shipping_scope'] === 'both' ? 'Domestic & Cross-Border Africa' : ucwords(str_replace('_', ' ', $selectedProduct['shipping_scope']))) ?></span>
    </div>
    <a class="btn btn-sm outline" href="<?= e(url('marketplace.php')) ?>">✕ Close</a>
   </div>

   <div class="grid-2">
    <div>
     <div style="border-radius:12px;overflow:hidden;border:1px solid #e2e8f0;background:#f8fafc;aspect-ratio:4/3;">
      <img id="mainProductImg" src="<?= e(product_image_url($selectedProduct['image_path'])) ?>" alt="<?= e($selectedProduct['name']) ?>" style="width:100%;height:100%;object-fit:cover;">
     </div>
     <?php if ($gallery): ?>
      <div style="display:flex;gap:10px;margin-top:10px;flex-wrap:wrap;">
       <img src="<?= e(product_image_url($selectedProduct['image_path'])) ?>" onclick="document.getElementById('mainProductImg').src=this.src" style="width:70px;height:70px;object-fit:cover;border-radius:8px;cursor:pointer;border:2px solid #ea580c;">
       <?php foreach ($gallery as $gImg): ?>
        <img src="<?= e(url($gImg)) ?>" onclick="document.getElementById('mainProductImg').src=this.src" style="width:70px;height:70px;object-fit:cover;border-radius:8px;cursor:pointer;border:1px solid #cbd5e1;">
       <?php endforeach; ?>
      </div>
     <?php endif; ?>
    </div>

    <div>
     <div style="color:#059669;font-weight:700;font-size:13px;margin-bottom:6px;">
      ✓ Verified <?= e(ucfirst($selectedProduct['seller_role'])) ?>: <?= e($selectedProduct['business_name'] ?: $selectedProduct['seller_name']) ?>
     </div>
     <h2 style="font-size:26px;margin-bottom:10px;"><?= e($selectedProduct['name']) ?></h2>

     <div style="background:#fff7ed;padding:16px;border-radius:10px;border:1px solid #fed7aa;margin-bottom:16px;">
      <span class="price" style="font-size:30px;"><?= e(format_money($selectedProduct['price'], $selectedProduct['currency'])) ?></span>
      <span class="muted small"> / <?= e($selectedProduct['unit']) ?></span>
      <?php if (!empty($selectedProduct['compare_price']) && $selectedProduct['compare_price'] > $selectedProduct['price']): ?>
       <span class="k-compare" style="font-size:15px;"><?= e(format_money($selectedProduct['compare_price'], $selectedProduct['currency'])) ?></span>
      <?php endif; ?>
      <div class="small muted" style="margin-top:6px;">
       MOQ: <strong><?= number_format((float)$selectedProduct['minimum_order_quantity'], 0) ?> <?= e($selectedProduct['unit']) ?></strong> •
       In Stock: <strong><?= number_format((float)$selectedProduct['stock_quantity'], 0) ?></strong> •
       Origin: <strong><?= e($selectedProduct['origin_country'] ?? 'Kenya') ?></strong>
       <?php if (!empty($selectedProduct['warranty_info'])): ?> • <strong><?= e($selectedProduct['warranty_info']) ?></strong><?php endif; ?>
      </div>
     </div>

     <?php if ($selectedSpecs): ?>
      <div style="background:#f8fafc;padding:12px;border-radius:8px;border:1px solid #e2e8f0;margin-bottom:12px;">
       <strong class="small">Available Specifications:</strong>
       <div style="display:flex;flex-wrap:wrap;gap:6px;margin-top:6px;">
        <?php foreach ($selectedSpecs as $grp => $vals): ?>
         <span class="badge badge-neutral"><?= e($grp) ?>: <?= e(implode(' / ', (array)$vals)) ?></span>
        <?php endforeach; ?>
       </div>
      </div>
     <?php endif; ?>

     <p class="small" style="color:#334155;"><?= nl2br(e($selectedProduct['description'])) ?></p>

     <?php if (logged_in() && user_can_buy($pdo)): ?>
      <form method="post" style="border-top:1px solid #e2e8f0;padding-top:14px;">
       <?= csrf_field() ?>
       <input type="hidden" name="action" value="instant_order">
       <input type="hidden" name="product_id" value="<?= (int)$selectedProduct['id'] ?>">

       <div class="form-row">
        <?php if ($colorsList): ?>
         <div>
          <label>Select Color</label>
          <select name="chosen_color">
           <?php foreach ($colorsList as $cl): ?>
            <option value="<?= e($cl) ?>"><?= e($cl) ?></option>
           <?php endforeach; ?>
          </select>
         </div>
        <?php endif; ?>
        <div>
         <label>Delivery Mode</label>
         <select name="trade_type">
          <option value="domestic">Domestic Delivery (3% Est. Shipping)</option>
          <option value="cross_border">Cross-Border Africa Freight (10% Est. Shipping)</option>
         </select>
        </div>
       </div>

       <div class="form-row">
        <div>
         <label>Quantity (<?= e($selectedProduct['unit']) ?>) *</label>
         <input type="number" name="quantity" min="<?= e($selectedProduct['minimum_order_quantity']) ?>" max="<?= e($selectedProduct['stock_quantity']) ?>" step="1" value="<?= e($selectedProduct['minimum_order_quantity']) ?>" required>
        </div>
        <div>
         <label>Delivery City & Address *</label>
         <input name="delivery_address" required placeholder="e.g. Nairobi Westlands / Lagos / Kigali">
        </div>
       </div>

       <label>Preferred Spec / Order Note (Optional)</label>
       <input name="chosen_spec" placeholder="e.g. 16GB RAM / 512GB SSD">

       <div style="display:flex;gap:10px;margin-top:16px;flex-wrap:wrap;">
        <button class="btn mpesa" type="submit">Buy Now & Pay via M-Pesa</button>
        <a class="btn dark" href="<?= e(url('rfq.php?product_id=' . (int)$selectedProduct['id'])) ?>">Request Bulk Quote (RFQ)</a>
       </div>
      </form>
     <?php elseif (!logged_in()): ?>
      <a class="btn btn-block" href="<?= e(url('auth/login.php')) ?>">Sign in to Buy or Request Bulk Quote</a>
     <?php endif; ?>
    </div>
   </div>
  </div>
 <?php endif; ?>

 <form method="get" class="panel" style="margin-bottom:24px;">
  <div style="display:grid;grid-template-columns:2fr 1fr 1fr 1fr auto;gap:12px;align-items:end;">
   <div>
    <label style="margin-top:0;">Search Catalog</label>
    <input name="q" value="<?= e($q) ?>" placeholder="Search product, brand, or merchant...">
   </div>
   <div>
    <label style="margin-top:0;">Category</label>
    <select name="category">
     <option value="0">All Categories</option>
     <?php foreach ($categories as $c): ?>
      <option value="<?= (int)$c['id'] ?>" <?= $catId === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
     <?php endforeach; ?>
    </select>
   </div>
   <div>
    <label style="margin-top:0;">Trade Scope</label>
    <select name="scope">
     <option value="">Domestic & Cross-Border</option>
     <option value="domestic" <?= $scope === 'domestic' ? 'selected' : '' ?>>Domestic Delivery</option>
     <option value="cross_border" <?= $scope === 'cross_border' ? 'selected' : '' ?>>Cross-Border AfCFTA</option>
    </select>
   </div>
   <div>
    <label style="margin-top:0;">Country</label>
    <select name="country">
     <option value="0">All Countries</option>
     <?php foreach ($countries as $co): ?>
      <option value="<?= (int)$co['id'] ?>" <?= $countryId === (int)$co['id'] ? 'selected' : '' ?>><?= e($co['name']) ?></option>
     <?php endforeach; ?>
    </select>
   </div>
   <div style="display:flex;gap:8px;">
    <button class="btn" type="submit">Filter</button>
    <?php if ($q || $catId || $countryId || $scope): ?>
     <a class="btn outline" href="<?= e(url('marketplace.php')) ?>">Reset</a>
    <?php endif; ?>
   </div>
  </div>
 </form>

 <?php if (!$products): ?>
  <div class="panel" style="text-align:center;padding:48px;">
   <h3>No Products Listed Yet</h3>
   <p class="muted">Upload your first product or adjust your filters.</p>
   <?php if (logged_in() && user_can_sell($pdo)): ?>
    <a class="btn" href="<?= e(url('seller/product-create.php')) ?>">+ Upload First Product</a>
   <?php endif; ?>
  </div>
 <?php else: ?>
  <div class="product-grid">
   <?php foreach ($products as $p):
    $hasDiscount = !empty($p['compare_price']) && (float)$p['compare_price'] > (float)$p['price'];
    $pctOff = $hasDiscount ? round((1 - ((float)$p['price'] / (float)$p['compare_price'])) * 100) : 0;
   ?>
    <a class="k-card" href="<?= e(url('marketplace.php?product=' . (int)$p['id'])) ?>">
     <div class="k-img-wrap">
      <img src="<?= e(product_image_url($p['image_path'])) ?>" alt="<?= e($p['name']) ?>">
      <?php if ($pctOff > 0): ?>
       <span class="k-discount-badge">-<?= $pctOff ?>%</span>
      <?php endif; ?>
      <span class="k-role-badge"><?= e(strtoupper($p['seller_role'])) ?></span>
     </div>
     <div class="k-body">
      <div class="k-title"><?= e($p['name']) ?></div>
      <div>
       <span class="k-price"><?= e(format_money($p['price'], $p['currency'])) ?></span>
       <?php if ($hasDiscount): ?>
        <span class="k-compare"><?= e(format_money($p['compare_price'], $p['currency'])) ?></span>
       <?php endif; ?>
      </div>
      <div class="k-meta">MOQ: <?= number_format((float)$p['minimum_order_quantity'], 0) ?> <?= e($p['unit']) ?> • <?= e($p['origin_country'] ?? 'Kenya') ?></div>
      <div class="k-seller">
       <span>✓ <?= e($p['business_name'] ?: $p['seller_name']) ?></span>
       <span style="color:#ea580c;">Buy →</span>
      </div>
     </div>
    </a>
   <?php endforeach; ?>
  </div>
 <?php endif; ?>
</main>

<?php require_once __DIR__ . '/app/includes/footer.php'; ?>
