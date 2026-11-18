<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_role(['logistics','admin']);
$uid=current_user_id(); $role=current_role();
if ($role==='logistics') require_verified_partner($pdo);

if ($_SERVER['REQUEST_METHOD']==='POST') {
    verify_csrf();
    $action=(string)($_POST['action']??'');
    $oid=(int)($_POST['order_id']??0);
    $sid=(int)($_POST['shipment_id']??0);

    // 1. CLAIM/ACCEPT DELIVERY ASSIGNMENT (Internal Registered Logistics Partners Only)
    if ($action==='claim') {
        require_role(['logistics']); require_verified_partner($pdo);
        $pdo->beginTransaction();
        $st=$pdo->prepare("SELECT o.* FROM orders o LEFT JOIN shipments s ON s.order_id=o.id WHERE o.id=? AND o.escrow_status='funded_held' AND o.seller_confirmed_at IS NOT NULL AND s.id IS NULL FOR UPDATE");
        $st->execute([$oid]); $o=$st->fetch();
        if ($o) {
            $trk='ATO-TRK-'.strtoupper(bin2hex(random_bytes(5)));
            $pdo->prepare("INSERT INTO shipments (order_id, logistics_provider_id, tracking_number, carrier_name, status, current_location) VALUES (?, ?, ?, ?, 'pending', 'Awaiting collection')")
                ->execute([$oid,$uid,$trk,$_SESSION['full_name']??'Registered Delivery Partner']);
            record_order_event($pdo,$oid,'carrier_assigned',$uid,'Registered internal delivery partner accepted the assignment.');
            $pdo->commit();
            email_admins($pdo,$oid,'Delivery partner assigned','A verified registered delivery partner has claimed the shipment.');
            flash("Delivery assigned. Tracking number: {$trk}");
        } else { $pdo->rollBack(); flash('This order is not ready or has already been assigned.', 'error'); }

    // 2. CONFIRM PHYSICAL COLLECTION FROM SELLER -> COMPUTES DISTANCE ETA & SENDS BUYER PIN
    } elseif ($action==='collect') {
        require_role(['logistics']); require_verified_partner($pdo);
        $pdo->beginTransaction();
        $st=$pdo->prepare("SELECT s.*, o.id AS oid, o.buyer_id, o.seller_id, o.order_reference, o.delivery_address, o.trade_type, o.delivery_otp_code FROM shipments s JOIN orders o ON o.id=s.order_id WHERE s.id=? AND s.logistics_provider_id=? AND s.status='pending' AND o.admin_order_approved_at IS NOT NULL AND o.escrow_status='funded_held' FOR UPDATE");
        $st->execute([$sid,$uid]); $sh=$st->fetch();
        if ($sh) {
            $etaInfo = ato_calculate_transit_eta($pdo, $sh);
            $pickupLoc = trim($_POST['current_location']??($etaInfo['origin_hub'].' — Collected from seller'));

            $pdo->prepare("UPDATE shipments SET status='in_transit', current_location=?, distance_km=?, estimated_hours=?, estimated_delivery=?, estimated_delivery_at=?, corridor_note=? WHERE id=? AND status='pending'")
                ->execute([$pickupLoc, $etaInfo['distance_km'], $etaInfo['eta_hours'], $etaInfo['eta_date'], $etaInfo['eta_datetime'], $etaInfo['corridor_note'], $sid]);

            $pdo->prepare("UPDATE orders SET logistics_collected_at=NOW(), admin_dispatch_approved_at=COALESCE(admin_dispatch_approved_at, NOW()), status='shipped' WHERE id=?")->execute([$sh['oid']]);

            record_order_event($pdo,(int)$sh['oid'],'carrier_collected_eta_calculated',$uid,"Carrier confirmed collection ({$pickupLoc}). Distance: {$etaInfo['distance_km']} km. ETA: {$etaInfo['eta_label']}.");
            $pdo->commit();

            $buyerMsg = "Your package has been picked up by {$sh['carrier_name']} (Tracking: {$sh['tracking_number']}).\n\n"
                      . "Route & Distance: {$etaInfo['corridor_note']}\n"
                      . "Estimated Arrival (ETA): {$etaInfo['eta_label']}\n"
                      . "Your Secure Delivery PIN: {$sh['delivery_otp_code']} (Share this PIN with the rider ONLY after inspecting your item upon arrival).";

            notify_user($pdo, (int)$sh['buyer_id'], 'Order Picked Up — ETA: ' . $etaInfo['eta_label'], "Tracking {$sh['tracking_number']} is on the way ({$etaInfo['distance_km']} km). Delivery PIN: {$sh['delivery_otp_code']}.", 'shipment', 'orders.php');
            email_order_user($pdo, (int)$sh['oid'], (int)$sh['buyer_id'], 'Your order is on the way! ETA: ' . $etaInfo['eta_label'], $buyerMsg);
            email_admins($pdo, (int)$sh['oid'], 'Carrier collected order — ETA sent to buyer', "Carrier {$sh['carrier_name']} confirmed pickup. Estimated distance: {$etaInfo['distance_km']} km. Buyer notified with ETA ({$etaInfo['eta_label']}).");

            flash("Collection recorded! Distance ({$etaInfo['distance_km']} km) & ETA ({$etaInfo['eta_label']}) sent to the buyer.");
        } else { $pdo->rollBack(); flash('This shipment cannot be collected by this account.', 'error'); }

    // 3. CONFIRM ARRIVAL AT DESTINATION
    } elseif ($action==='arrived') {
        require_role(['logistics']); require_verified_partner($pdo);
        $st=$pdo->prepare("SELECT s.*,o.id AS oid FROM shipments s JOIN orders o ON o.id=s.order_id WHERE s.id=? AND s.logistics_provider_id=? AND s.status IN ('in_transit','out_for_delivery') AND o.admin_order_approved_at IS NOT NULL");
        $st->execute([$sid,$uid]); $sh=$st->fetch();
        if ($sh) {
            $pdo->prepare("UPDATE shipments SET status='delivered', current_location=? WHERE id=?")->execute([trim($_POST['current_location']??'Destination — arrival confirmed'),$sid]);
            $pdo->prepare("UPDATE orders SET logistics_delivered_at=NOW() WHERE id=?")->execute([$sh['oid']]);
            record_order_event($pdo,$sh['oid'],'carrier_arrival_confirmed',$uid,'Carrier confirmed arrival at destination. Awaiting buyer PIN confirmation.');
            email_admins($pdo,$sh['oid'],'Delivery partner confirmed arrival','The carrier marked the item as arrived at the destination. Awaiting buyer PIN verification to release escrow.');
            flash('Arrival recorded. The buyer has been prompted to enter their Delivery Confirmation PIN.');
        } else flash('This shipment cannot be marked arrived by this account.', 'error');

    // 4. UPDATE LIVE TRACKING LOCATION
    } elseif ($action==='location') {
        require_role(['logistics']); require_verified_partner($pdo);
        $loc=trim($_POST['current_location']??'');
        $st=$pdo->prepare("UPDATE shipments SET current_location=? WHERE id=? AND logistics_provider_id=? AND status IN ('collected','in_transit','at_customs','out_for_delivery')");
        $st->execute([$loc,$sid,$uid]);
        flash($st->rowCount()?'Tracking location updated.':'No eligible shipment was updated.','warning');
    }
    header('Location: '.url('logistics/index.php')); exit;
}

if ($role==='admin') {
    $shipments=$pdo->query("SELECT sh.*,o.order_reference,o.title,o.escrow_status FROM shipments sh JOIN orders o ON o.id=sh.order_id ORDER BY sh.created_at DESC")->fetchAll();
    $openOrders=$pdo->query("SELECT o.* FROM orders o LEFT JOIN shipments sh ON sh.order_id=o.id WHERE sh.id IS NULL AND o.escrow_status='funded_held' AND o.seller_confirmed_at IS NOT NULL ORDER BY o.created_at ASC")->fetchAll();
} else {
    $st=$pdo->prepare("SELECT sh.*,o.order_reference,o.title,o.escrow_status FROM shipments sh JOIN orders o ON o.id=sh.order_id WHERE sh.logistics_provider_id=? ORDER BY sh.created_at DESC"); $st->execute([$uid]); $shipments=$st->fetchAll();
    $openOrders=$pdo->query("SELECT o.* FROM orders o LEFT JOIN shipments sh ON sh.order_id=o.id WHERE sh.id IS NULL AND o.escrow_status='funded_held' AND o.seller_confirmed_at IS NOT NULL ORDER BY o.created_at ASC")->fetchAll();
}

$pageTitle='Delivery Partner Operations';
require_once __DIR__.'/../app/includes/header.php';
?>
<main class="container section">
 <h1>Delivery Partner Operations</h1>
 <p class="muted small">Internal registered logistics partners only. Claim orders, confirm physical collection (which calculates distance-based ETA), and record arrival at destination.</p>
 <?php show_flash(); ?>

 <?php if ($role==='logistics' && $openOrders): ?>
  <div class="panel">
   <h3>Paid orders ready for delivery assignment</h3>
   <?php foreach($openOrders as $o): ?>
    <form method="post" style="display:flex;gap:12px;justify-content:space-between;align-items:center;padding:10px 0;border-bottom:1px solid #e2e8f0;">
     <?=csrf_field()?>
     <input type="hidden" name="action" value="claim">
     <input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>">
     <span><strong><?=e($o['order_reference'])?></strong> — <?=e($o['title'])?></span>
     <button class="btn btn-sm" type="submit">Accept delivery</button>
    </form>
   <?php endforeach; ?>
  </div>
 <?php endif; ?>

 <?php foreach($shipments as $sh): ?>
  <div class="card" style="margin:14px 0;">
   <div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap">
    <strong><?=e($sh['tracking_number'])?> · <?=e($sh['order_reference'])?></strong>
    <?=status_badge($sh['status'])?>
   </div>
   <h3><?=e($sh['title']?:'Trade order')?></h3>
   <p class="small">Current location: <strong><?=e($sh['current_location'])?></strong></p>
   <?php if (!empty($sh['distance_km'])): ?>
    <p class="small muted">Route: <?=e($sh['corridor_note'])?> · Distance: <strong><?=e($sh['distance_km'])?> km</strong> · ETA: <strong><?=e($sh['estimated_delivery'])?></strong></p>
   <?php endif; ?>

   <?php if ($role==='logistics' && $sh['status']==='pending'): ?>
    <form method="post">
     <?=csrf_field()?>
     <input type="hidden" name="action" value="collect">
     <input type="hidden" name="shipment_id" value="<?= (int)$sh['id'] ?>">
     <input name="current_location" placeholder="Seller pickup hub / city" required style="margin-bottom:8px;">
     <button class="btn btn-sm" type="submit">Confirm physical collection &amp; send buyer ETA</button>
    </form>
   <?php endif; ?>

   <?php if ($role==='logistics' && in_array($sh['status'],['in_transit','out_for_delivery'],true)): ?>
    <form method="post">
     <?=csrf_field()?>
     <input type="hidden" name="action" value="arrived">
     <input type="hidden" name="shipment_id" value="<?= (int)$sh['id'] ?>">
     <input name="current_location" placeholder="Destination / arrival location" required style="margin-bottom:8px;">
     <button class="btn btn-sm success" type="submit">Confirm arrival at destination</button>
    </form>
   <?php endif; ?>

   <?php if ($role==='logistics' && in_array($sh['status'],['collected','in_transit','at_customs','out_for_delivery'],true)): ?>
    <form method="post" style="margin-top:8px;display:flex;gap:8px;">
     <?=csrf_field()?>
     <input type="hidden" name="action" value="location">
     <input type="hidden" name="shipment_id" value="<?= (int)$sh['id'] ?>">
     <input name="current_location" value="<?=e($sh['current_location'])?>" required>
     <button class="btn btn-sm outline">Update location</button>
    </form>
   <?php endif; ?>
  </div>
 <?php endforeach; ?>
</main>
<?php require_once __DIR__.'/../app/includes/footer.php'; ?>
