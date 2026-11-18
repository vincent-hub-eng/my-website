<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_login();
if (!user_can_buy($pdo)) { http_response_code(403); exit('Buyer access required.'); }

$uid = current_user_id();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $qid = (int)($_POST['quotation_id'] ?? 0);
    $stmt = $pdo->prepare("SELECT q.*, r.buyer_id, r.title, r.unit, r.delivery_city, r.trade_type FROM quotations q JOIN rfqs r ON r.id=q.rfq_id WHERE q.id=? AND r.buyer_id=? AND q.status='submitted'");
    $stmt->execute([$qid, $uid]);
    $q = $stmt->fetch();
    if ($q && (int)$q['seller_id'] !== $uid) {
        $sub = (float)$q['unit_price'] * (float)$q['quantity'];
        $ship = (float)$q['shipping_estimate'];
        $tot = $sub + $ship;
        $ref = 'ATO-' . date('Y') . '-' . strtoupper(bin2hex(random_bytes(3)));
        $pdo->prepare("INSERT INTO orders (order_reference, buyer_id, seller_id, quotation_id, title, quantity, unit, trade_type, delivery_address, subtotal, shipping_cost, total_amount, currency) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
            ->execute([$ref, $uid, $q['seller_id'], $qid, $q['title'], $q['quantity'], $q['unit'], $q['trade_type'], $q['delivery_city'], $sub, $ship, $tot, $q['currency']]);
        $oid = (int)$pdo->lastInsertId();
        record_order_event($pdo, $oid, 'order_created_from_quotation', $uid, 'Buyer accepted seller quotation; awaiting payment.');
        $pdo->prepare("UPDATE quotations SET status='accepted' WHERE id=?")->execute([$qid]);
        $pdo->prepare("UPDATE rfqs SET status='awarded' WHERE id=?")->execute([$q['rfq_id']]);
        flash("Quote accepted! Order {$ref} created. complete payment via M-Pesa or submit an external payment reference for admin verification.");
        header('Location: ' . url('payments.php?order_id=' . $oid));
        exit;
    }
}

$quotes = $pdo->prepare("SELECT q.*, r.rfq_reference, r.title, u.full_name AS seller_name FROM quotations q JOIN rfqs r ON r.id=q.rfq_id JOIN users u ON u.id=q.seller_id WHERE r.buyer_id=? ORDER BY q.created_at DESC");
$quotes->execute([$uid]);
$list = $quotes->fetchAll();

$pageTitle = 'Compare Quotations';
require_once __DIR__ . '/../app/includes/header.php';
?>
<main class="container section">
 <h1>Quotations Received</h1>
 <?php show_flash(); ?>
 <div class="grid-2">
  <?php foreach ($list as $q): ?>
   <div class="card">
    <div style="display:flex;justify-content:space-between;"><strong><?= e($q['quote_reference']) ?></strong><?= status_badge($q['status']) ?></div>
    <h3 style="margin:8px 0;"><?= e($q['title']) ?></h3>
    <p class="small">Merchant: <strong><?= e($q['seller_name']) ?></strong></p>
    <div class="price"><?= e(format_money(((float)$q['unit_price'] * (float)$q['quantity']) + (float)$q['shipping_estimate'], $q['currency'])) ?></div>
    <p class="muted small"><?= e($q['terms']) ?></p>
    <?php if ($q['status'] === 'submitted'): ?>
     <form method="post"><?= csrf_field() ?><input type="hidden" name="quotation_id" value="<?= (int)$q['id'] ?>"><button class="btn btn-block" type="submit">Accept Quote & Order</button></form>
    <?php endif; ?>
   </div>
  <?php endforeach; ?>
 </div>
</main>
<?php require_once __DIR__ . '/../app/includes/footer.php'; ?>
