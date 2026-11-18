<?php
require_once __DIR__ . '/app/bootstrap.php';
require_login();
$uid = current_user_id();
$role = current_role();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'open') {
    verify_csrf();
    $oid = (int)($_POST['order_id'] ?? 0);
    $reason = trim($_POST['reason'] ?? '');
    $desc = trim($_POST['description'] ?? '');
    $own=$pdo->prepare("SELECT o.*, (SELECT p.id FROM payments p WHERE p.order_id=o.id AND p.status='successful' AND p.escrow_state='held_in_escrow' ORDER BY p.id DESC LIMIT 1) AS held_payment_id FROM orders o WHERE o.id=? AND (o.buyer_id=? OR o.seller_id=?) AND o.escrow_status='funded_held'"); $own->execute([$oid,$uid,$uid]); $ord=$own->fetch();
    $openExisting=$pdo->prepare("SELECT id FROM disputes WHERE order_id=? AND status IN ('open','under_review') LIMIT 1");
    if ($ord && $reason !== '') {
        $openExisting->execute([$oid]);
        if ($openExisting->fetch()) { flash('An open dispute already exists for this order.', 'warning'); header('Location: '.url('disputes.php')); exit; }
        $ref = 'DSP-' . strtoupper(bin2hex(random_bytes(4)));
        $pdo->prepare("INSERT INTO disputes (dispute_reference, order_id, payment_id, opened_by, reason, description) VALUES (?, ?, ?, ?, ?, ?)")->execute([$ref, $oid, $ord['held_payment_id'], $uid, substr($reason,0,190), substr($desc,0,5000)]);
        $pdo->prepare("UPDATE orders SET status='disputed', escrow_status='disputed' WHERE id=?")->execute([$oid]);
        record_order_event($pdo,$oid,'dispute_opened',$uid,'Dispute '.$ref.' opened.');
        email_admins($pdo,$oid,'Order dispute opened','A buyer or seller opened a dispute. Review the evidence before deciding on the payment.');
        flash("Dispute {$ref} opened. Payment remains held pending review.");
    } else flash('Select an order belonging to your account with a payment currently held.', 'error');
    header('Location: ' . url('disputes.php'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'resolve') {
    require_role(['admin']); verify_csrf();
    $did=(int)($_POST['dispute_id']??0); $res=trim($_POST['resolution']??''); $decision=(string)($_POST['decision']??'return_to_workflow');
    $st=$pdo->prepare("SELECT d.*,o.buyer_id,o.seller_id,o.id AS oid,o.product_id,o.quantity,o.seller_confirmed_at,o.stock_released_at FROM disputes d JOIN orders o ON o.id=d.order_id WHERE d.id=? AND d.status IN ('open','under_review')");
    $st->execute([$did]); $d=$st->fetch();
    if ($d && $res!=='') {
        if (!in_array($decision,['return_to_workflow','refund_confirmed','release_confirmed'],true)) $decision='return_to_workflow';
        if ((int)$d['affects_order'] === 0 && $decision !== 'refund_confirmed') { flash('An additional payment is reviewed separately from the order. Verify its external refund before resolving it.', 'error'); header('Location: '.url('disputes.php')); exit; }
        if (($d['reason'] ?? '') === 'Late M-Pesa payment after order closure' && $decision==='return_to_workflow') { flash('A late payment cannot reopen an expired order. Verify an external refund or create a new order.', 'error'); header('Location: '.url('disputes.php')); exit; }
        if ($decision==='refund_confirmed' && empty($_POST['external_action_verified'])) { flash('Confirm only after the payment provider has actually completed the refund.', 'error'); header('Location: '.url('disputes.php')); exit; }
        if ($decision==='release_confirmed' && empty($_POST['external_action_verified'])) { flash('Confirm only after the payment provider has actually released the payment.', 'error'); header('Location: '.url('disputes.php')); exit; }
        $pdo->beginTransaction();
        $pdo->prepare("UPDATE disputes SET status='resolved',resolution=?,resolved_by=? WHERE id=?")->execute([$res,$uid,$did]);
        if ($decision==='refund_confirmed') {
            if ((int)$d['affects_order'] === 1) {
                if (!empty($d['product_id']) && empty($d['seller_confirmed_at']) && empty($d['stock_released_at'])) $pdo->prepare('UPDATE products SET stock_quantity=stock_quantity+? WHERE id=?')->execute([$d['quantity'],$d['product_id']]);
                $pdo->prepare("UPDATE orders SET escrow_status='refunded',status='cancelled',stock_reserved=0,stock_released_at=COALESCE(stock_released_at,NOW()) WHERE id=?")->execute([$d['oid']]);
            }
            $pdo->prepare("UPDATE payments SET escrow_state='refunded_to_buyer',status='refunded' WHERE id=COALESCE(?,id) AND order_id=? AND status='successful'")->execute([$d['payment_id'],$d['oid']]);
        } elseif ($decision==='release_confirmed') {
            if ((int)$d['affects_order'] === 1) $pdo->prepare("UPDATE orders SET escrow_status='released',status='completed',payout_status='paid',payout_confirmed_at=NOW() WHERE id=?")->execute([$d['oid']]);
            $pdo->prepare("UPDATE payments SET escrow_state='released_to_seller' WHERE id=COALESCE(?,id) AND order_id=? AND status='successful'")->execute([$d['payment_id'],$d['oid']]);
        } else {
            if (!empty($d['payment_id'])) $pdo->prepare("UPDATE payments SET escrow_state='held_in_escrow' WHERE id=? AND status='successful'")->execute([$d['payment_id']]);
            if ((int)$d['affects_order'] === 1) $pdo->prepare("UPDATE orders SET escrow_status='funded_held',status=CASE WHEN admin_dispatch_approved_at IS NOT NULL THEN 'shipped' WHEN seller_confirmed_at IS NOT NULL THEN 'confirmed' ELSE 'pending' END WHERE id=?")->execute([$d['oid']]);
        }
        record_order_event($pdo,(int)$d['oid'],'dispute_resolved_'.$decision,$uid,$res);
        $pdo->commit();
        email_order_user($pdo,(int)$d['oid'],(int)$d['buyer_id'],'Dispute resolved',$res);
        email_order_user($pdo,(int)$d['oid'],(int)$d['seller_id'],'Dispute resolved',$res);
        flash('Dispute resolved. Payment records updated according to the verified decision.');
    } else flash('Dispute not found or resolution details missing.', 'error');
    header('Location: ' . url('disputes.php')); exit;
}

$orders = $pdo->prepare("SELECT id, order_reference FROM orders WHERE (buyer_id=? OR seller_id=?) AND escrow_status='funded_held'");
$orders->execute([$uid, $uid]);
$myOrders = $orders->fetchAll();
$ds=($role==='admin') ? $pdo->query("SELECT d.*,o.order_reference,o.buyer_id,o.seller_id FROM disputes d JOIN orders o ON o.id=d.order_id ORDER BY d.created_at DESC") : $pdo->prepare("SELECT d.*,o.order_reference,o.buyer_id,o.seller_id FROM disputes d JOIN orders o ON o.id=d.order_id WHERE d.opened_by=? OR o.buyer_id=? OR o.seller_id=? ORDER BY d.created_at DESC");
if ($role!=='admin') $ds->execute([$uid,$uid,$uid]);
$disputes=$ds->fetchAll();

$pageTitle = 'Dispute Resolution';
require_once __DIR__ . '/app/includes/header.php';
?>
<main class="container section">
 <h1>Order &amp; Payment Dispute Review</h1>
 <?php show_flash(); ?>
 <div class="grid-2">
  <?php if ($role !== 'admin'): ?><form method="post" class="panel">
   <?= csrf_field() ?><input type="hidden" name="action" value="open">
   <label>Order</label><select name="order_id"><?php foreach ($myOrders as $o): ?><option value="<?= (int)$o['id'] ?>"><?= e($o['order_reference']) ?></option><?php endforeach; ?></select>
   <label>Reason</label><input name="reason" required>
   <label>Details</label><textarea name="description"></textarea>
   <button class="btn danger" style="margin-top:14px;">Open Dispute</button>
  </form><?php endif; ?>
  <div>
   <?php foreach ($disputes as $d): ?>
    <div class="card" style="margin-bottom:12px;">
     <div style="display:flex;justify-content:space-between;"><strong><?= e($d['dispute_reference']) ?> (<?= e($d['order_reference']) ?>)</strong><?= status_badge($d['status']) ?></div>
     <p class="small"><strong><?= e($d['reason']) ?></strong>: <?= e($d['description']) ?></p>
     <?php if ($role === 'admin' && $d['status'] !== 'resolved'): ?>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="resolve"><input type="hidden" name="dispute_id" value="<?= (int)$d['id'] ?>"><label>Decision</label><select name="decision"><option value="return_to_workflow">Return to delivery workflow</option><option value="refund_confirmed">Refund completed externally</option><option value="release_confirmed">Payment released externally</option></select><label>Resolution details</label><input name="resolution" placeholder="Explain the evidence and decision" required><label class="small"><input type="checkbox" name="external_action_verified" value="yes"> I verified the external refund/release in the provider records (required for refund/release)</label><button class="btn btn-sm dark" style="margin-top:6px;">Resolve dispute</button></form>
     <?php endif; ?>
    </div>
   <?php endforeach; ?>
  </div>
 </div>
</main>
<?php require_once __DIR__ . '/app/includes/footer.php'; ?>
