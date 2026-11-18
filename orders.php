<?php
require_once __DIR__ . '/app/bootstrap.php';
require_login();
$uid = current_user_id();
$role = current_role();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');
    $oid = (int)($_POST['order_id'] ?? 0);

    // 1. SELLER CONFIRMS STOCK AVAILABILITY (YES)
    if ($action === 'seller_confirm') {
        require_role(['seller','supplier']);
        if (!user_can_sell($pdo)) { http_response_code(403); exit('Seller permission is not active.'); }
        require_verified_partner($pdo);
        $st = $pdo->prepare("SELECT * FROM orders WHERE id=? AND seller_id=? AND escrow_status='funded_held' AND seller_confirmed_at IS NULL AND seller_rejected_at IS NULL AND status='pending' FOR UPDATE");
        $pdo->beginTransaction();
        $st->execute([$oid, $uid]);
        $o = $st->fetch();
        if ($o) {
            $pdo->prepare("UPDATE orders SET seller_confirmed_at=NOW(), status='confirmed' WHERE id=? AND seller_confirmed_at IS NULL")->execute([$oid]);
            record_order_event($pdo, $oid, 'seller_confirmed_stock_available', $uid, 'Seller confirmed product availability. Awaiting administrator verification & internal carrier assignment.');
            $pdo->commit();
            email_admins($pdo, $oid, 'Seller confirmed product availability', 'The seller confirmed the product is in stock and ready. Sign in to Orders to approve and auto-assign the nearest internal delivery company.');
            notify_admins($pdo, 'Seller confirmed stock — assign delivery', "Order {$o['order_reference']} is ready for admin approval and internal courier assignment.", 'order', 'orders.php');
            notify_user($pdo, (int)$o['buyer_id'], 'Seller confirmed product availability', 'The seller confirmed your item is available. The administrator is assigning a registered delivery partner.', 'order', 'orders.php');
            email_order_user($pdo, $oid, (int)$o['buyer_id'], 'Product availability confirmed', 'Great news! The seller confirmed your item is available and ready. Our administrator is now assigning the nearest verified delivery partner.');
            flash('Availability confirmed! The administrator has been notified to approve and assign the nearest registered delivery company.');
        } else {
            if ($pdo->inTransaction()) $pdo->rollBack();
            flash('This order is not eligible for availability confirmation.', 'error');
        }

    // 2. SELLER REPORTS OUT OF STOCK (NO -> AUTO-CANCEL & REFUND QUEUE)
    } elseif ($action === 'seller_unavailable') {
        require_role(['seller','supplier']);
        if (!user_can_sell($pdo)) { http_response_code(403); exit('Seller permission is not active.'); }
        require_verified_partner($pdo);
        $reason = substr(trim((string)($_POST['rejection_reason'] ?? 'Item currently out of stock')), 0, 255);
        $st = $pdo->prepare("SELECT * FROM orders WHERE id=? AND seller_id=? AND escrow_status='funded_held' AND seller_confirmed_at IS NULL AND seller_rejected_at IS NULL AND status='pending' FOR UPDATE");
        $pdo->beginTransaction();
        $st->execute([$oid, $uid]);
        $o = $st->fetch();
        if ($o) {
            $pdo->prepare("UPDATE orders SET seller_rejected_at=NOW(), seller_rejection_reason=?, status='cancelled', escrow_status='refunded', stock_released_at=NOW() WHERE id=?")->execute([$reason, $oid]);
            $pdo->prepare("UPDATE payments SET escrow_state='refunded_to_buyer', status='refunded' WHERE order_id=? AND status='successful'")->execute([$oid]);
            if (!empty($o['product_id'])) {
                $pdo->prepare("UPDATE products SET stock_quantity=0 WHERE id=?")->execute([$o['product_id']]);
            }
            record_order_event($pdo, $oid, 'seller_unavailable_order_cancelled', $uid, 'Seller reported product unavailable (' . $reason . '). Order cancelled and buyer escrow queued for refund.');
            $pdo->commit();
            email_order_user($pdo, $oid, (int)$o['buyer_id'], 'Order cancelled — item unavailable (Refund initiated)', "The seller reported that the product is currently unavailable ({$reason}). Your held escrow payment has been marked for full refund.");
            notify_user($pdo, (int)$o['buyer_id'], 'Order cancelled — refund queued', "Seller reported item unavailable for {$o['order_reference']}. Full refund initiated.", 'order', 'orders.php');
            email_admins($pdo, $oid, 'Seller declined availability — refund queued', "Seller reported product unavailable ({$reason}). Process buyer refund reconciliation.");
            flash('Order marked unavailable and cancelled. Buyer has been notified for a full refund.', 'warning');
        } else {
            if ($pdo->inTransaction()) $pdo->rollBack();
            flash('This order can no longer be marked unavailable.', 'error');
        }

    // 3. ADMIN CONFIRMS ORDER & AUTO-ASSIGNS NEAREST REGISTERED INTERNAL DELIVERY COMPANY
    } elseif ($action === 'admin_approve_assign') {
        require_role(['admin']);
        $manualCarrierId = (int)($_POST['carrier_id'] ?? 0);
        $st = $pdo->prepare("SELECT o.*, sh.id AS existing_shipment_id FROM orders o LEFT JOIN shipments sh ON sh.order_id=o.id WHERE o.id=? AND o.escrow_status='funded_held' AND o.seller_confirmed_at IS NOT NULL AND o.admin_order_approved_at IS NULL FOR UPDATE");
        $pdo->beginTransaction();
        $st->execute([$oid]);
        $o = $st->fetch();
        if ($o && empty($o['existing_shipment_id'])) {
            $carrier = null;
            if ($manualCarrierId > 0) {
                $cSt = $pdo->prepare("SELECT u.id, u.full_name, u.email, bp.business_name FROM users u LEFT JOIN business_profiles bp ON bp.user_id=u.id WHERE u.id=? AND u.role='logistics' AND u.account_status='active' AND u.id_verification_status='verified' AND u.email_verified_at IS NOT NULL");
                $cSt->execute([$manualCarrierId]);
                $carrier = $cSt->fetch() ?: null;
                if ($carrier) {
                    $carrier['company_display_name'] = !empty($carrier['business_name']) ? $carrier['business_name'] : $carrier['full_name'];
                    $carrier['proximity_km'] = 0;
                }
            } else {
                $carrier = ato_select_nearest_internal_carrier($pdo, $o);
            }

            if (!$carrier) {
                $pdo->rollBack();
                flash('No active, KYC-verified registered delivery company is available inside the system.', 'error');
                header('Location: ' . url('orders.php')); exit;
            }

            $etaInfo = ato_calculate_transit_eta($pdo, $o);
            $trk = 'ATO-TRK-' . strtoupper(bin2hex(random_bytes(5)));
            $deliveryPin = (string)random_int(100000, 999999);

            $pdo->prepare("UPDATE orders SET admin_order_approved_at=NOW(), delivery_otp_code=?, status='processing' WHERE id=?")
                ->execute([$deliveryPin, $oid]);

            $pdo->prepare("INSERT INTO shipments (order_id, logistics_provider_id, tracking_number, carrier_name, origin_hub, destination_hub, current_location, distance_km, estimated_hours, estimated_delivery, estimated_delivery_at, corridor_note, status) VALUES (?, ?, ?, ?, ?, ?, 'Assigned — awaiting pickup from seller', ?, ?, ?, ?, ?, 'pending')")
                ->execute([
                    $oid,
                    $carrier['id'],
                    $trk,
                    $carrier['company_display_name'],
                    $etaInfo['origin_hub'],
                    $etaInfo['dest_hub'],
                    $etaInfo['distance_km'],
                    $etaInfo['eta_hours'],
                    $etaInfo['eta_date'],
                    $etaInfo['eta_datetime'],
                    $etaInfo['corridor_note']
                ]);

            record_order_event($pdo, $oid, 'admin_approved_and_carrier_assigned', $uid, "Administrator approved order and assigned internal registered delivery partner {$carrier['company_display_name']} (Tracking: {$trk}).");
            $pdo->commit();

            notify_user($pdo, (int)$carrier['id'], 'New Delivery Assignment (' . $trk . ')', "You have been assigned order {$o['order_reference']} for pickup from seller.", 'shipment', 'logistics/index.php');
            email_order_user($pdo, $oid, (int)$carrier['id'], 'New Delivery Assigned — Pickup Required', "Administrator assigned your company ({$carrier['company_display_name']}) to collect order {$o['order_reference']}. Tracking: {$trk}. Sign in to Delivery Operations to confirm pickup.");
            email_order_user($pdo, $oid, (int)$o['seller_id'], 'Delivery company assigned for pickup', "Registered carrier {$carrier['company_display_name']} (Tracking: {$trk}) has been assigned to collect this order.");
            notify_user($pdo, (int)$o['buyer_id'], 'Delivery company assigned', "Carrier {$carrier['company_display_name']} has been assigned to collect your order.", 'shipment', 'orders.php');
            email_order_user($pdo, $oid, (int)$o['buyer_id'], 'Delivery partner assigned to your order', "Our administrator approved your order and assigned our verified delivery partner {$carrier['company_display_name']} (Tracking: {$trk}). Once picked up, you will receive your distance-based ETA and Delivery Confirmation PIN.");

            flash("Order approved and internal delivery company ({$carrier['company_display_name']}) assigned! Tracking: {$trk}");
        } else {
            if ($pdo->inTransaction()) $pdo->rollBack();
            flash('Order cannot be approved (already assigned or awaiting seller confirmation).', 'error');
        }

    // 4. BUYER CONFIRMS RECEIPT WITH DELIVERY PIN -> RELEASES ESCROW
    } elseif ($action === 'buyer_confirm_pin') {
        require_role(['buyer']);
        $enteredPin = trim((string)($_POST['delivery_pin'] ?? ''));
        $st = $pdo->prepare("SELECT o.*, s.id AS shipment_id, s.status AS shipment_status FROM orders o JOIN shipments s ON s.order_id=o.id WHERE o.id=? AND o.buyer_id=? AND o.escrow_status='funded_held' AND o.delivery_otp_code IS NOT NULL FOR UPDATE");
        $pdo->beginTransaction();
        $st->execute([$oid, $uid]);
        $o = $st->fetch();
        if ($o && $enteredPin !== '' && hash_equals((string)$o['delivery_otp_code'], $enteredPin)) {
            $pdo->prepare("UPDATE orders SET delivery_otp_verified_at=NOW(), admin_delivery_confirmed_at=NOW(), escrow_released_at=NOW(), escrow_status='released', status='completed', payout_status='awaiting_transfer' WHERE id=?")->execute([$oid]);
            $pdo->prepare("UPDATE shipments SET status='delivered', current_location='Delivered & verified by buyer PIN' WHERE id=?")->execute([$o['shipment_id']]);
            $pdo->prepare("UPDATE payments SET escrow_state='released_to_seller' WHERE order_id=? AND status='successful' AND escrow_state='held_in_escrow'")->execute([$oid]);
            record_order_event($pdo, $oid, 'buyer_pin_confirmed_escrow_released', $uid, 'Buyer confirmed delivery using secure Delivery PIN; escrow funds authorized for seller payout.');
            $pdo->commit();
            email_order_user($pdo, $oid, (int)$o['seller_id'], 'Order delivered — payout authorized', 'The buyer verified delivery with their secure PIN. Your payout is now queued for transfer.');
            email_admins($pdo, $oid, 'Buyer confirmed delivery via PIN — payout queued', 'Buyer verified receipt with their Delivery PIN. Escrow is released and seller payout is awaiting transfer.');
            flash('Delivery confirmed via PIN! Escrow funds have been released to the seller.');
        } else {
            if ($pdo->inTransaction()) $pdo->rollBack();
            flash('Invalid Delivery PIN or order is not ready for final confirmation.', 'error');
        }
    }
    header('Location: ' . url('orders.php')); exit;
}

$sql = "SELECT o.*, b.full_name AS buyer_name, s.full_name AS seller_name,
        sh.id AS shipment_id, sh.tracking_number, sh.status AS shipment_status, sh.corridor_note, sh.distance_km, sh.estimated_delivery,
        (SELECT id FROM payments WHERE order_id=o.id ORDER BY id DESC LIMIT 1) AS payment_id,
        (SELECT status FROM payments WHERE order_id=o.id ORDER BY id DESC LIMIT 1) AS payment_status,
        (SELECT GROUP_CONCAT(CONCAT(event_type, ' · ', DATE_FORMAT(created_at,'%d %b %H:%i')) ORDER BY created_at DESC SEPARATOR '\n') FROM order_events WHERE order_id=o.id) AS event_history
        FROM orders o JOIN users b ON b.id=o.buyer_id JOIN users s ON s.id=o.seller_id
        LEFT JOIN shipments sh ON sh.order_id=o.id";
$params=[];
if ($role === 'admin') { $sql .= ' ORDER BY o.created_at DESC'; }
elseif (in_array($role, ['seller','supplier'], true)) { $sql .= ' WHERE o.seller_id=? ORDER BY o.created_at DESC'; $params[]=$uid; }
elseif ($role === 'logistics') { $sql .= ' JOIN shipments mine ON mine.order_id=o.id AND mine.logistics_provider_id=? ORDER BY o.created_at DESC'; $params[]=$uid; }
else { $sql .= ' WHERE o.buyer_id=? ORDER BY o.created_at DESC'; $params[]=$uid; }
$st=$pdo->prepare($sql); $st->execute($params); $orders=$st->fetchAll();
$pageTitle='Orders & Delivery Workflow'; require_once __DIR__ . '/app/includes/header.php';
?>
<main class="container section">
 <div class="page-header"><div><h1>Orders &amp; Delivery Workflow</h1><p class="muted small">Secure Escrow, Seller Availability Confirmation (Yes/No), Admin Courier Assignment, Distance ETA &amp; Buyer PIN Verification.</p></div></div>
 <?php show_flash(); ?>
 <?php if (!$orders): ?><div class="panel">No orders are available for this account yet.</div><?php endif; ?>
 <?php foreach ($orders as $o): ?>
  <div class="card" style="margin-bottom:16px;">
   <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;"><strong><?= e($o['order_reference']) ?></strong><span><?= status_badge($o['status']) ?> <?= status_badge($o['escrow_status']) ?></span></div>
   <h3><?= e($o['title'] ?: 'Trade order') ?></h3>
   <p class="small muted">Buyer: <?= e($o['buyer_name']) ?> · Seller: <?= e($o['seller_name']) ?> · Quantity: <?= e($o['quantity']) ?> <?= e($o['unit']) ?></p>
   <p><strong><?= e(format_money($o['total_amount'], $o['currency'])) ?></strong> · Carrier: <?= e($o['tracking_number'] ?: 'Not assigned') ?> · Delivery Status: <?= e($o['shipment_status'] ?: 'Awaiting availability check') ?></p>
   
   <?php if (!empty($o['distance_km'])): ?>
    <p class="small" style="background:#f8fafc;padding:8px;border-radius:6px;border:1px solid #e2e8f0;">📍 <strong>Transit &amp; Distance:</strong> <?= e($o['corridor_note']) ?> · Distance: <strong><?= e($o['distance_km']) ?> km</strong> · ETA: <strong><?= e($o['estimated_delivery']) ?></strong></p>
   <?php endif; ?>

   <?php if (in_array($role,['seller','supplier'],true) && (int)$o['seller_id']===$uid && $o['escrow_status']==='funded_held' && empty($o['seller_confirmed_at']) && empty($o['seller_rejected_at'])): ?>
    <div style="background:#f8fafc;padding:12px;border-radius:8px;margin:10px 0;border:1px solid #e2e8f0;">
     <p style="margin:0 0 8px 0;font-weight:600;color:#0f1f38;">Is this product available in stock for collection?</p>
     <div style="display:flex;gap:10px;flex-wrap:wrap;">
      <form method="post" onsubmit="return confirm('Confirm that this item is available and ready for collection?');" style="margin:0;">
       <?= csrf_field() ?>
       <input type="hidden" name="action" value="seller_confirm">
       <input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>">
       <button class="btn btn-sm success" type="submit">✓ Yes, Item Available</button>
      </form>
      <form method="post" onsubmit="return promptRejection(this);" style="margin:0;display:flex;gap:6px;">
       <?= csrf_field() ?>
       <input type="hidden" name="action" value="seller_unavailable">
       <input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>">
       <input type="hidden" name="rejection_reason" id="rejection_reason_<?= (int)$o['id'] ?>" value="">
       <button class="btn btn-sm danger" type="submit">✕ No, Out of Stock</button>
      </form>
     </div>
    </div>
    <script>
    function promptRejection(form) {
        let reason = prompt("Please provide a brief reason why the item is unavailable (sent to buyer and admin):", "Out of stock / discontinued");
        if (reason === null) return false;
        form.querySelector('[name="rejection_reason"]').value = reason;
        return confirm("Are you sure you want to mark this item unavailable? This will cancel the order and queue a full escrow refund to the buyer.");
    }
    </script>
   <?php endif; ?>

   <?php if ($role==='admin' && $o['escrow_status']==='funded_held' && !empty($o['seller_confirmed_at']) && empty($o['admin_order_approved_at'])): ?>
    <div style="background:#fdf4ed;padding:12px;border-radius:8px;margin:10px 0;border:1px solid #fed7aa;">
     <form method="post" style="display:flex;flex-direction:column;gap:8px;">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="admin_approve_assign">
      <input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>">
      <strong>Admin Action: Approve Order &amp; Assign Nearest Internal Delivery Partner</strong>
      <?php 
         $carriers = $pdo->query("SELECT u.id, u.full_name, bp.business_name FROM users u LEFT JOIN business_profiles bp ON bp.user_id=u.id WHERE u.role='logistics' AND u.account_status='active' AND u.id_verification_status='verified'")->fetchAll();
      ?>
      <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
       <select name="carrier_id" style="flex:1;padding:6px;font-size:13px;">
        <option value="0">⚡ Auto-select nearest registered courier (Smart Proximity)</option>
        <?php foreach ($carriers as $c): ?>
         <option value="<?= (int)$c['id'] ?>"><?= e(!empty($c['business_name']) ? $c['business_name'] : $c['full_name']) ?></option>
        <?php endforeach; ?>
       </select>
       <button class="btn btn-sm success" type="submit">Approve &amp; Assign Courier</button>
      </div>
     </form>
    </div>
   <?php endif; ?>

   <?php if ($role === 'buyer' && in_array($o['shipment_status'], ['in_transit', 'out_for_delivery', 'delivered'], true) && $o['escrow_status'] === 'funded_held' && empty($o['admin_delivery_confirmed_at'])): ?>
    <div style="background:#ecfdf5;padding:12px;border-radius:8px;margin:10px 0;border:1px solid #a7f3d0;">
     <form method="post" style="display:flex;flex-direction:column;gap:8px;" onsubmit="return confirm('Confirm that you have received and inspected your order, and release escrow funds to the seller?');">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="buyer_confirm_pin">
      <input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>">
      <strong style="color:#065f46;">Inspect Item &amp; Enter Secure Delivery PIN</strong>
      <p class="small muted" style="margin:0;">Enter the 6-digit Delivery PIN sent to your email when the courier picked up your item:</p>
      <div style="display:flex;gap:8px;align-items:center;">
       <input type="text" name="delivery_pin" placeholder="Enter 6-digit PIN" required maxlength="6" style="max-width:180px;letter-spacing:3px;font-weight:700;font-size:16px;text-align:center;">
       <button class="btn btn-sm success" type="submit">Confirm Receipt &amp; Release Escrow</button>
      </div>
     </form>
    </div>
   <?php endif; ?>

   <?php if (!empty($o['event_history'])): ?><details style="margin:10px 0;"><summary class="small">Order activity history</summary><pre class="small" style="white-space:pre-wrap;margin:8px 0;"><?= e($o['event_history']) ?></pre></details><?php endif; ?>
   <?php if (!empty($o['payment_id']) && $role !== 'logistics'): ?><a class="btn btn-sm outline" href="<?= e(url('payments.php?receipt_id='.(int)$o['payment_id'])) ?>">View receipt</a><?php endif; ?>
  </div>
 <?php endforeach; ?>
</main>
<?php require_once __DIR__ . '/app/includes/footer.php'; ?>
