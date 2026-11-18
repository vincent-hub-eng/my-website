<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_login();
if (!user_can_sell($pdo)) { http_response_code(403); exit('Seller access required.'); }
require_verified_partner($pdo);

$uid = current_user_id();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $rfqId = (int)($_POST['rfq_id'] ?? 0);
    $price = filter_var($_POST['unit_price'] ?? null, FILTER_VALIDATE_FLOAT);
    $quantity = filter_var($_POST['quantity'] ?? null, FILTER_VALIDATE_FLOAT);
    $shippingEst = filter_var($_POST['shipping_estimate'] ?? 0, FILTER_VALIDATE_FLOAT);
    $currency = strtoupper(trim($_POST['currency'] ?? 'KES'));
    $days = (int)($_POST['delivery_days'] ?? 3);
    $terms = trim($_POST['terms'] ?? '');

    $check = $pdo->prepare("SELECT id, buyer_id, rfq_reference FROM rfqs WHERE id=? AND status='open'");
    $check->execute([$rfqId]);
    $rfqRow = $check->fetch();
    if ($rfqRow && $price !== false && $price > 0 && $quantity !== false && $quantity > 0) {
        $quoteRef = 'QT-' . date('Y') . '-' . random_int(1000, 9999);
        $pdo->prepare("INSERT INTO quotations (quote_reference, rfq_id, seller_id, unit_price, quantity, shipping_estimate, currency, delivery_days, terms, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'submitted')")
            ->execute([$quoteRef, $rfqId, $uid, $price, $quantity, $shippingEst !== false ? $shippingEst : 0, $currency, $days, $terms]);
        notify_user($pdo, (int)$rfqRow['buyer_id'], "New Quote ({$quoteRef})", "A verified merchant quoted your RFQ {$rfqRow['rfq_reference']}.", 'quotation', 'buyer/accept-quotations.php');
        flash("Quotation {$quoteRef} sent to buyer!");
    }
    header('Location: ' . url('seller/quotations.php'));
    exit;
}

$rfqs = $pdo->query("SELECT r.*, u.full_name AS buyer_name, co.name AS dest_country FROM rfqs r JOIN users u ON u.id=r.buyer_id LEFT JOIN countries co ON co.id=r.destination_country_id WHERE r.status='open' ORDER BY r.created_at DESC")->fetchAll();

$pageTitle = 'Buyer RFQs';
require_once __DIR__ . '/../app/includes/header.php';
?>
<main class="container section">
 <h1>Open Buyer RFQs</h1>
 <?php show_flash(); ?>
 <div class="grid-2">
  <?php foreach ($rfqs as $r): ?>
   <div class="card card-featured">
    <span class="badge badge-info"><?= e($r['rfq_reference']) ?></span>
    <h3 style="margin:8px 0 4px;"><?= e($r['title']) ?></h3>
    <p class="small"><?= nl2br(e($r['description'])) ?></p>
    <p class="muted small">Buyer: <strong><?= e($r['buyer_name']) ?></strong> • Qty: <strong><?= e($r['quantity']) ?> <?= e($r['unit']) ?></strong></p>
    <form method="post" style="border-top:1px solid #e2e8f0;padding-top:12px;">
     <?= csrf_field() ?><input type="hidden" name="rfq_id" value="<?= (int)$r['id'] ?>">
     <div class="form-row">
      <div><label>Unit Price *</label><input type="number" name="unit_price" step="0.01" required></div>
      <div><label>Quantity *</label><input type="number" name="quantity" value="<?= e($r['quantity']) ?>" required></div>
     </div>
     <div class="form-row">
      <div><label>Shipping Cost</label><input type="number" name="shipping_estimate" value="0"></div>
      <div><label>Currency</label><input name="currency" value="<?= e($r['currency'] ?: 'KES') ?>" maxlength="3"></div>
     </div>
     <label>Delivery Terms</label><input name="terms" placeholder="e.g. Dispatched within 24 hours">
     <button class="btn btn-block" style="margin-top:14px;" type="submit">Submit Quote</button>
    </form>
   </div>
  <?php endforeach; ?>
 </div>
</main>
<?php require_once __DIR__ . '/../app/includes/footer.php'; ?>
