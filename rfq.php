<?php
require_once __DIR__ . '/app/bootstrap.php';
require_login();
if (!user_can_buy($pdo)) { http_response_code(403); exit('Buyer access required.'); }

$uid = current_user_id();
$productId = (int)($_GET['product_id'] ?? $_POST['product_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $quantity = filter_var($_POST['quantity'] ?? null, FILTER_VALIDATE_FLOAT);
    $unit = trim($_POST['unit'] ?? 'pieces');
    $currency = strtoupper(trim($_POST['currency'] ?? 'KES'));
    $destCountry = (int)($_POST['destination_country_id'] ?? 1);
    $city = trim($_POST['delivery_city'] ?? '');
    $tradeType = ($_POST['trade_type'] ?? 'domestic') === 'cross_border' ? 'cross_border' : 'domestic';

    if ($title !== '' && $quantity !== false && $quantity > 0) {
        $ref = 'RFQ-' . date('Y') . '-' . random_int(1000, 9999);
        $pdo->prepare(
            "INSERT INTO rfqs (rfq_reference, buyer_id, product_id, title, description, quantity, unit, currency, destination_country_id, delivery_city, trade_type, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'open')"
        )->execute([$ref, $uid, $productId ?: null, $title, $description, $quantity, $unit, $currency, $destCountry, $city, $tradeType]);
        flash("RFQ {$ref} published to verified Sellers & Suppliers!");
        header('Location: ' . url('rfq.php'));
        exit;
    }
}

$countries = $pdo->query("SELECT id, name FROM countries WHERE is_active=1 ORDER BY name")->fetchAll();
$myRfqs = $pdo->prepare("SELECT r.*, (SELECT COUNT(*) FROM quotations q WHERE q.rfq_id=r.id) AS q_count FROM rfqs r WHERE r.buyer_id=? ORDER BY r.created_at DESC");
$myRfqs->execute([$uid]);
$rfqs = $myRfqs->fetchAll();

$pageTitle = 'Request Bulk Quotations (RFQ)';
require_once __DIR__ . '/app/includes/header.php';
?>
<main class="container section">
 <div class="page-header">
  <div><h1>Request Bulk Quotations (RFQ)</h1><p class="muted small" style="margin:0;">Source domestic or cross-border wholesale quotes from verified suppliers.</p></div>
  <a class="btn dark" href="<?= e(url('buyer/accept-quotations.php')) ?>">Compare Quotes →</a>
 </div>
 <?php show_flash(); ?>
 <div class="grid-2">
  <div class="panel">
   <h3>Post New Bulk RFQ</h3>
   <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="product_id" value="<?= $productId ?>">
    <label>Item / Commodity Needed *</label>
    <input name="title" required placeholder="e.g. 500 Bags Grade 1 Maize / 30 Office Laptops">
    <div class="form-row">
     <div><label>Quantity *</label><input type="number" name="quantity" min="1" required></div>
     <div><label>Unit *</label><input name="unit" value="pieces" required></div>
    </div>
    <div class="form-row">
     <div><label>Trade Scope</label><select name="trade_type"><option value="domestic">Domestic</option><option value="cross_border">Cross-Border AfCFTA</option></select></div>
     <div><label>Currency</label><select name="currency"><?php foreach (array_keys(fx_rates()) as $c): ?><option value="<?= $c ?>"><?= $c ?></option><?php endforeach; ?></select></div>
    </div>
    <div class="form-row">
     <div><label>Destination Country</label><select name="destination_country_id"><?php foreach ($countries as $co): ?><option value="<?= (int)$co['id'] ?>"><?= e($co['name']) ?></option><?php endforeach; ?></select></div>
     <div><label>Delivery City</label><input name="delivery_city" placeholder="e.g. Nairobi / Kampala"></div>
    </div>
    <label>Specifications</label>
    <textarea name="description"></textarea>
    <button class="btn btn-block" style="margin-top:16px;" type="submit">Publish RFQ</button>
   </form>
  </div>
  <div>
   <h3>Your RFQs</h3>
   <?php foreach ($rfqs as $r): ?>
    <div class="card" style="margin-bottom:12px;">
     <div style="display:flex;justify-content:space-between;"><strong><?= e($r['rfq_reference']) ?></strong><?= status_badge($r['status']) ?></div>
     <h4 style="margin:6px 0;"><?= e($r['title']) ?></h4>
     <p class="muted small">Qty: <?= e($r['quantity']) ?> <?= e($r['unit']) ?> • <?= (int)$r['q_count'] ?> Quotes</p>
     <a class="btn btn-sm dark" href="<?= e(url('buyer/accept-quotations.php')) ?>">View Quotes</a>
    </div>
   <?php endforeach; ?>
  </div>
 </div>
</main>
<?php require_once __DIR__ . '/app/includes/footer.php'; ?>
