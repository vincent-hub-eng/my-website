<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_role(['admin']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    $targetId = (int)($_POST['user_id'] ?? 0);

    if ($action === 'id_decision') {
        $decision = $_POST['id_status'] ?? 'verified';
        if (in_array($decision, ['verified', 'rejected', 'pending'], true)) {
            $target = $pdo->prepare("SELECT full_name,email,email_verified_at,id_front_path,id_back_path,selfie_path FROM users WHERE id=? AND role<>'admin'");
            $target->execute([$targetId]); $targetUser=$target->fetch();
            if (!$targetUser) { flash('User not found.', 'error'); header('Location: '.url('admin/index.php')); exit; }
            if ($decision === 'verified' && (empty($targetUser['email_verified_at']) || empty($targetUser['id_front_path']) || empty($targetUser['id_back_path']) || empty($targetUser['selfie_path']))) {
                flash('Cannot verify: email OTP and all three identity images must be submitted first.', 'error');
                header('Location: '.url('admin/index.php')); exit;
            }
            $pdo->prepare("UPDATE users SET id_verification_status=? WHERE id=? AND role<>'admin'")->execute([$decision, $targetId]);
            notify_user(
                $pdo,
                $targetId,
                'ID & Selfie Verification ' . ucfirst($decision),
                $decision === 'verified'
                    ? 'Platform Administrator approved your Front ID, Back ID, and Selfie. You can now upload products and trade!'
                    : 'Your Front/Back ID or Selfie was ' . $decision . '. Please re-upload clear photos in Verify Account.',
                'verification',
                'verify-account.php'
            );
            email_user_notice($pdo, $targetId, 'Identity verification ' . ucfirst($decision), $decision === 'verified' ? 'Your identity documents were manually reviewed and approved. Seller or logistics features are now available.' : ($decision === 'rejected' ? 'Your identity documents were not approved. Please review the feedback and submit clear replacement images.' : 'Your identity verification is still under review.'));
            log_audit($pdo, 'admin_kyc_' . $decision, 'user', $targetId, "Set KYC status to {$decision}");
            flash("Partner ID & Selfie status updated to " . ucfirst($decision) . ".");
        }
    } elseif ($action === 'account_status') {
        $status = $_POST['status'] ?? 'active';
        if ($targetId === current_user_id()) {
            flash('You cannot suspend your own Admin account.', 'error');
        } elseif (in_array($status, ['active', 'suspended', 'rejected'], true)) {
            $pdo->prepare("UPDATE users SET account_status=? WHERE id=? AND role<>'admin'")->execute([$status, $targetId]);
            log_audit($pdo, 'admin_account_' . $status, 'user', $targetId, "Account status -> {$status}");
            flash('User account status updated.');
        }
    } elseif ($action === 'manual_payment_confirm') {
        $paymentId=(int)($_POST['payment_id']??0);
        $reference=substr(trim($_POST['payout_reference']??''),0,120);
        $st=$pdo->prepare("SELECT p.*,o.id AS oid,o.buyer_id,o.seller_id,o.order_reference,o.escrow_status FROM payments p JOIN orders o ON o.id=p.order_id WHERE p.id=? AND p.status='initiated' AND p.provider_reference IS NOT NULL AND o.escrow_status='unfunded' AND o.status='pending' FOR UPDATE");
        $pdo->beginTransaction(); $st->execute([$paymentId]); $pay=$st->fetch();
        if ($pay && isset($_POST['confirm_received']) && $_POST['confirm_received']==='yes') {
            $pdo->prepare("UPDATE payments SET status='successful',escrow_state='held_in_escrow',paid_at=NOW() WHERE id=? AND status='initiated'")->execute([$paymentId]);
            $pdo->prepare("UPDATE orders SET escrow_status='funded_held',status='pending',stock_reserved=0 WHERE id=? AND escrow_status='unfunded'")->execute([$pay['oid']]);
            log_audit($pdo,'manual_payment_verified','payment',$paymentId,'Admin verified external provider reference '.$pay['provider_reference']);
            record_order_event($pdo,(int)$pay['oid'],'manual_payment_verified',current_user_id(),'Administrator reconciled external payment reference '.$pay['provider_reference'].'.');
            $pdo->commit();
            email_order_user($pdo,(int)$pay['oid'],(int)$pay['buyer_id'],'Payment verified','The administrator verified the external payment reference and marked your order paid.');
            email_order_user($pdo,(int)$pay['oid'],(int)$pay['seller_id'],'Buyer payment verified','The buyer payment has been reconciled. Please confirm stock from Orders.');
            flash('External payment verified and order funded in the platform ledger.');
        } else { if ($pdo->inTransaction()) $pdo->rollBack(); flash('Payment not verified. Confirm only after checking the actual provider/bank statement.', 'error'); }
    } elseif ($action === 'confirm_seller_payout') {
        $orderId=(int)($_POST['order_id']??0); $reference=substr(trim($_POST['payout_reference']??''),0,120);
        if ($reference==='') { flash('Enter the real payout transaction reference.', 'error'); }
        else {
            $pdo->beginTransaction();
            $st=$pdo->prepare("SELECT * FROM orders WHERE id=? AND payout_status='awaiting_transfer' AND escrow_status='released' FOR UPDATE"); $st->execute([$orderId]); $o=$st->fetch();
            if ($o && isset($_POST['confirm_sent']) && $_POST['confirm_sent']==='yes') {
                $pdo->prepare("UPDATE orders SET payout_status='paid',payout_reference=?,payout_confirmed_at=NOW() WHERE id=? AND payout_status='awaiting_transfer'")->execute([$reference,$orderId]);
                record_order_event($pdo,$orderId,'seller_payout_confirmed',current_user_id(),'Administrator confirmed external seller payout reference '.$reference.'.');
                $pdo->commit();
                email_order_user($pdo,$orderId,(int)$o['seller_id'],'Seller payout transfer confirmed','The administrator recorded the payout transaction reference '.$reference.'.');
                flash('Seller payout marked transferred with the supplied reference.');
            } else { if ($pdo->inTransaction()) $pdo->rollBack(); flash('Payout was not confirmed. Verify the transfer first.', 'error'); }
        }
    } elseif ($action === 'remove_product') {
        $prodId = (int)($_POST['product_id'] ?? 0);
        $pdo->prepare("UPDATE products SET status='rejected' WHERE id=?")->execute([$prodId]);
        flash('Product removed from storefront.');
    }

    header('Location: ' . url('admin/index.php'));
    exit;
}

$stats = [
    'Total Users'        => (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn(),
    'Pending ID+Selfie'  => (int)$pdo->query("SELECT COUNT(*) FROM users WHERE id_verification_status='pending'")->fetchColumn(),
    'Live Products'      => (int)$pdo->query("SELECT COUNT(*) FROM products WHERE status='active'")->fetchColumn(),
    'Total Orders'       => (int)$pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn(),
];

$users = $pdo->query(
    "SELECT u.*, bp.business_name, c.name AS country_name
     FROM users u
     LEFT JOIN business_profiles bp ON bp.user_id = u.id
     LEFT JOIN countries c ON c.id = u.country_id
     ORDER BY FIELD(u.id_verification_status, 'pending', 'unsubmitted', 'rejected', 'verified', 'not_required'), u.created_at DESC"
)->fetchAll();

$manualPayments = $pdo->query("SELECT p.id,p.provider,p.provider_reference,p.amount,p.currency,p.created_at,o.order_reference,o.id AS order_id,b.full_name AS buyer_name FROM payments p JOIN orders o ON o.id=p.order_id JOIN users b ON b.id=o.buyer_id WHERE p.status='initiated' AND p.provider_reference IS NOT NULL AND o.escrow_status='unfunded' AND o.status='pending' ORDER BY p.created_at ASC")->fetchAll();
$pendingPayouts = $pdo->query("SELECT o.id,o.order_reference,o.total_amount,o.currency,o.payout_status,s.full_name AS seller_name FROM orders o JOIN users s ON s.id=o.seller_id WHERE o.payout_status='awaiting_transfer' ORDER BY o.created_at ASC")->fetchAll();

$products = $pdo->query(
    "SELECT p.*, u.full_name AS seller_name FROM products p JOIN users u ON u.id=p.seller_id WHERE p.status='active' ORDER BY p.created_at DESC LIMIT 40"
)->fetchAll();

$pageTitle = 'Admin Governance Control';
require_once __DIR__ . '/../app/includes/header.php';
?>

<main class="container section">
 <div class="page-header">
  <div>
   <h1>Platform Administrator Governance Center</h1>
   <p class="muted small" style="margin:0;">Review identity documents, moderate products, and govern orders, delivery and platform security.</p>
  </div>
  <div style="display:flex;gap:10px;">
   
   <a class="btn dark" href="<?= e(url('disputes.php')) ?>">Dispute Center</a>
  </div>
 </div>

 <?php show_flash(); ?>

 <?php if ($manualPayments): ?><div class="panel" style="margin:18px 0;"><h3>External payments awaiting reconciliation</h3><p class="small muted">Check the actual bank/provider statement before confirming any reference.</p><?php foreach($manualPayments as $mp): ?><form method="post" style="display:flex;gap:10px;align-items:center;justify-content:space-between;flex-wrap:wrap;border-top:1px solid #e2e8f0;padding:10px 0;"><?=csrf_field()?><input type="hidden" name="action" value="manual_payment_confirm"><input type="hidden" name="payment_id" value="<?= (int)$mp['id'] ?>"><span><strong><?=e($mp['order_reference'])?></strong> · <?=e($mp['buyer_name'])?> · <?=e($mp['provider'])?> · Ref <?=e($mp['provider_reference'])?> · <?=e(format_money($mp['amount'],$mp['currency']))?></span><label class="small"><input type="checkbox" name="confirm_received" value="yes" required> I verified the incoming funds</label><button class="btn btn-sm success">Confirm received</button></form><?php endforeach; ?></div><?php endif; ?>
 <?php if ($pendingPayouts): ?><div class="panel" style="margin:18px 0;"><h3>Seller payouts awaiting external transfer</h3><p class="small muted">Make the transfer through the contracted payout provider/bank, then record its real transaction reference.</p><?php foreach($pendingPayouts as $po): ?><form method="post" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;border-top:1px solid #e2e8f0;padding:10px 0;"><?=csrf_field()?><input type="hidden" name="action" value="confirm_seller_payout"><input type="hidden" name="order_id" value="<?= (int)$po['id'] ?>"><span><strong><?=e($po['order_reference'])?></strong> · <?=e($po['seller_name'])?> · <?=e(format_money($po['total_amount'],$po['currency']))?></span><input name="payout_reference" placeholder="Actual transfer reference" required maxlength="120"><label class="small"><input type="checkbox" name="confirm_sent" value="yes" required> Transfer completed</label><button class="btn btn-sm success">Record payout</button></form><?php endforeach; ?></div><?php endif; ?>

 <div class="grid">
  <?php foreach ($stats as $label => $val): ?>
   <div class="card">
    <div class="stat-label"><?= e($label) ?></div>
    <div class="stat"><?= $val ?></div>
   </div>
  <?php endforeach; ?>
 </div>

 <h2 style="margin-top:28px;">Partner ID (Front & Back) + Selfie Verification Queue</h2>
 <div class="table-wrap">
  <table>
   <thead>
    <tr>
     <th>User & Store</th>
     <th>Role</th>
     <th>Email OTP</th>
     <th>Front ID, Back ID & Selfie</th>
     <th>Status</th>
     <th>Admin Decision</th>
    </tr>
   </thead>
   <tbody>
    <?php foreach ($users as $u): ?>
     <tr>
      <td>
       <strong><?= e($u['full_name']) ?></strong> <?= $u['business_name'] ? '(' . e($u['business_name']) . ')' : '' ?><br>
       <span class="muted small"><?= e($u['email']) ?> • <?= e($u['phone']) ?></span>
      </td>
      <td><span class="badge badge-neutral"><?= e(strtoupper($u['role'])) ?></span></td>
      <td><?= $u['email_verified_at'] ? '<span class="badge badge-success">Verified</span>' : '<span class="badge badge-warning">Pending OTP</span>' ?></td>
      <td>
       <?= status_badge($u['id_verification_status']) ?>
       <?php if (!empty($u['id_number'])): ?>
        <div class="small" style="margin-top:4px;">ID #: <strong><?= e(ato_decrypt_sensitive($u['id_number'])) ?></strong></div>
       <?php endif; ?>
       <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:4px;">
        <?php if (!empty($u['id_front_path'])): ?><a class="small" href="<?= e(url('admin/kyc-image.php?user_id='.(int)$u['id'].'&kind=front')) ?>" target="_blank">Front ID ↗</a><?php endif; ?>
        <?php if (!empty($u['id_back_path'])): ?><a class="small" href="<?= e(url('admin/kyc-image.php?user_id='.(int)$u['id'].'&kind=back')) ?>" target="_blank">Back ID ↗</a><?php endif; ?>
        <?php if (!empty($u['selfie_path'])): ?><a class="small" href="<?= e(url('admin/kyc-image.php?user_id='.(int)$u['id'].'&kind=selfie')) ?>" target="_blank">Selfie ↗</a><?php endif; ?>
       </div>
      </td>
      <td><?= status_badge($u['account_status']) ?></td>
      <td>
       <?php if ($u['role'] !== 'admin'): ?>
        <div style="display:flex;flex-direction:column;gap:6px;">
         <?php if ($u['role'] !== 'buyer'): ?>
          <form method="post" style="display:flex;gap:4px;">
           <?= csrf_field() ?>
           <input type="hidden" name="action" value="id_decision">
           <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
           <select name="id_status" style="width:auto;padding:4px 8px;font-size:12px;">
            <option value="verified">Approve KYC</option>
            <option value="rejected">Reject KYC</option>
           </select>
           <button class="btn btn-sm success" type="submit">Apply</button>
          </form>
         <?php endif; ?>
         <form method="post" style="display:flex;gap:4px;">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="account_status">
          <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
          <select name="status" style="width:auto;padding:4px 8px;font-size:12px;">
           <option value="active">Active</option>
           <option value="suspended">Suspend</option>
          </select>
          <button class="btn btn-sm dark" type="submit">Save</button>
         </form>
        </div>
       <?php else: ?>
        <span class="badge badge-info">Owner (Admin + Seller + Buyer)</span>
       <?php endif; ?>
      </td>
     </tr>
    <?php endforeach; ?>
   </tbody>
  </table>
 </div>

 <h3 style="margin-top:28px;">Active Storefront Products</h3>
 <div class="table-wrap">
  <table>
   <thead><tr><th>Image</th><th>Product</th><th>Merchant</th><th>Price</th><th>Action</th></tr></thead>
   <tbody>
    <?php if (!$products): ?>
     <tr><td colspan="5" class="muted" style="text-align:center;padding:20px;">No products uploaded yet.</td></tr>
    <?php else: ?>
     <?php foreach ($products as $p): ?>
      <tr>
       <td><img src="<?= e(url($p['image_path'])) ?>" style="width:48px;height:48px;object-fit:cover;border-radius:6px;"></td>
       <td><strong><?= e($p['name']) ?></strong></td>
       <td><?= e($p['seller_name']) ?></td>
       <td><?= e(format_money($p['price'], $p['currency'])) ?></td>
       <td>
        <form method="post" onsubmit="return confirm('Remove this product?');">
         <?= csrf_field() ?>
         <input type="hidden" name="action" value="remove_product">
         <input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>">
         <button class="btn btn-sm danger" type="submit">Remove</button>
        </form>
       </td>
      </tr>
     <?php endforeach; ?>
    <?php endif; ?>
   </tbody>
  </table>
 </div>
</main>

<?php require_once __DIR__ . '/../app/includes/footer.php'; ?>
