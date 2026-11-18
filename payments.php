<?php
require_once __DIR__ . '/app/bootstrap.php';
require_login();

$uid   = current_user_id();
$role  = current_role();
$u     = current_user($pdo);
$selOrder = (int)($_GET['order_id'] ?? 0);
$error = '';

// 1. AJAX POLLING ENDPOINT: Checks real-time M-Pesa STK callback confirmation
if (isset($_GET['action']) && $_GET['action'] === 'poll') {
    header('Content-Type: application/json');
    $checkoutId = trim($_GET['checkout_id'] ?? '');
    $stmt = $pdo->prepare("SELECT p.id,p.order_id,p.status,p.mpesa_receipt,p.amount,p.currency,o.status AS order_status FROM payments p JOIN orders o ON o.id=p.order_id WHERE p.checkout_request_id=? AND p.payer_id=? LIMIT 1");
    $stmt->execute([$checkoutId, $uid]);
    $pay = $stmt->fetch();

    if (!$pay) {
        echo json_encode(['status' => 'not_found']);
        exit;
    }

    // In Sandbox Simulator mode (no live ngrok webhook), confirm after 4 seconds
    if ($pay['order_status'] === 'cancelled') {
        echo json_encode(['status'=>'expired','payment_id'=>(int)$pay['id'],'receipt'=>$pay['mpesa_receipt']]); exit;
    }
    if ($pay['status'] === 'pending' && str_starts_with($checkoutId, 'ws_CO_') && !empty($GLOBALS['ATO_CONFIG']['mpesa']['allow_simulation'])) {
        // Explicit local-only simulator. Never enable this on a public production site.
        $receipt = 'TEST' . strtoupper(bin2hex(random_bytes(4)));
        $pdo->prepare("UPDATE payments SET status='successful', escrow_state='held_in_escrow', mpesa_receipt=?, paid_at=NOW() WHERE id=? AND status='pending'")->execute([$receipt, $pay['id']]);
        $pdo->prepare("UPDATE orders SET escrow_status='funded_held', status='pending', stock_reserved=0 WHERE id=? AND escrow_status='unfunded'")->execute([$pay['order_id']]);
        $pay['status'] = 'successful'; $pay['mpesa_receipt'] = $receipt;
        $ord=$pdo->prepare('SELECT buyer_id,seller_id FROM orders WHERE id=?'); $ord->execute([$pay['order_id']]); $pair=$ord->fetch();
        if ($pair) { email_order_user($pdo,(int)$pay['order_id'],(int)$pair['buyer_id'],'Test payment successful','Local simulator confirmed a test payment.'); email_order_user($pdo,(int)$pay['order_id'],(int)$pair['seller_id'],'Test payment received','A local test payment was simulated.'); }
    }

    echo json_encode([
        'status'     => $pay['status'],
        'payment_id' => (int)$pay['id'],
        'receipt'    => $pay['mpesa_receipt']
    ]);
    exit;
}

// 2. DOWNLOADABLE HTML/PRINT RECEIPT FILE ENDPOINT
if (isset($_GET['download_receipt'])) {
    $pid = (int)$_GET['download_receipt'];
    $rStmt = $pdo->prepare(
        "SELECT p.*, o.order_reference, o.title AS order_title, o.quantity, o.unit, o.status AS order_status, o.escrow_status, o.payout_status, o.payout_reference,
                b.full_name AS buyer_name, b.email AS buyer_email, s.full_name AS seller_name
         FROM payments p
         JOIN orders o ON o.id = p.order_id
         JOIN users b ON b.id = o.buyer_id
         JOIN users s ON s.id = o.seller_id
         WHERE p.id=? AND (o.buyer_id=? OR o.seller_id=? OR ?='admin') LIMIT 1"
    );
    $rStmt->execute([$pid, $uid, $uid, $role]);
    $rec = $rStmt->fetch();
    if (!$rec) {
        exit('Receipt not found.');
    }

    $isFinalized = (($rec['payout_status'] ?? '') === 'paid');
    $isRefunded  = ($rec['escrow_state'] === 'refunded_to_buyer' || $rec['status'] === 'refunded');

    if ($isRefunded) {
        $badgeText  = 'REFUND CONFIRMED';
        $badgeColor = '#dc2626';
        $policyText = 'The platform records this transaction as refunded after administrator verification of the external provider refund.';
    } elseif ($isFinalized) {
        $badgeText  = 'SELLER PAYOUT TRANSFER CONFIRMED';
        $badgeColor = '#059669';
        $policyText = 'The administrator recorded that the seller payout transfer was completed. Payout reference: ' . ($rec['payout_reference'] ?? 'not supplied') . '.';
    } elseif (($rec['payout_status'] ?? '') === 'awaiting_transfer') {
        $badgeText='DELIVERY CONFIRMED — SELLER PAYOUT AWAITING TRANSFER'; $badgeColor='#d97706';
        $policyText='Delivery has been confirmed in the platform. The seller payout is authorized but an external transfer reference has not yet been recorded.';
    } elseif ($rec['escrow_state'] === 'awaiting_payment' && $rec['status'] === 'successful') {
        $badgeText='PAYMENT REQUIRES RECONCILIATION'; $badgeColor='#dc2626';
        $policyText='The provider reported a successful payment, but the platform has not accepted it into the order workflow. Administrator reconciliation is required.';
    } elseif ($rec['order_status'] === 'disputed') {
        $badgeText='DISPUTE REVIEW — PAYMENT RESTRICTED'; $badgeColor='#dc2626';
        $policyText='The order is under dispute review. No refund or seller payout is recorded as complete.';
    } elseif ($rec['status'] !== 'successful') {
        $badgeText = 'AWAITING PAYMENT CONFIRMATION'; $badgeColor = '#d97706';
        $policyText = 'This payment has not yet been confirmed by the payment provider or administrator. Do not treat this document as proof of funds received.';
    } elseif ($rec['escrow_state'] === 'held_in_escrow') {
        $badgeText  = 'PAYMENT RECORDED — DELIVERY REVIEW PENDING';
        $badgeColor = '#d97706';
        $policyText = 'The platform records this payment as held pending delivery review. Actual custody and refund execution depend on the payment or regulated escrow provider.';
    } else {
        $badgeText = strtoupper(str_replace('_',' ',$rec['escrow_state'])); $badgeColor = '#64748b';
        $policyText = 'This receipt records the payment and platform order status. It is not proof of a third-party escrow balance or bank transfer.';
    }

    $fileName = 'ATO-Receipt-' . ($rec['mpesa_receipt'] ?: $rec['payment_reference']) . '.html';
    header('Content-Type: text/html; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    echo "<!DOCTYPE html><html><head><meta charset='utf-8'><title>Receipt {$fileName}</title>
    <style>
        body{font-family:Arial,sans-serif;background:#f8fafc;padding:30px;color:#0f172a;}
        .box{max-width:720px;margin:auto;background:#fff;border:1px solid #cbd5e1;border-top:6px solid {$badgeColor};border-radius:10px;padding:32px;}
        .row{display:flex;justify-content:space-between;margin-bottom:16px;}
        .badge{display:inline-block;background:{$badgeColor};color:#fff;padding:6px 12px;border-radius:6px;font-weight:bold;font-size:12px;}
        table{width:100%;border-collapse:collapse;margin:20px 0;}
        th,td{border:1px solid #e2e8f0;padding:12px;text-align:left;}
        th{background:#f1f5f9;}
        .policy{background:#fffbeb;border:1px solid #fcd34d;padding:14px;border-radius:8px;font-size:13px;line-height:1.5;}
    </style></head><body><div class='box'>
    <div class='row'><div><h2 style='margin:0;'>AFRICA TRADE OS</h2><small>Payment Receipt & Order Status Record</small></div>
    <div style='text-align:right;'><span class='badge'>{$badgeText}</span><br><small>Date: " . e($rec['paid_at'] ?: $rec['created_at']) . "</small></div></div><hr>
    <div class='row'>
        <div><strong>Buyer (Payer):</strong><br>" . e($rec['buyer_name']) . "<br>" . e($rec['buyer_email']) . "<br>Phone: " . e($rec['mpesa_phone'] ?: 'N/A') . "</div>
        <div style='text-align:right;'><strong>Transaction Details:</strong><br>Receipt #: <strong>" . e($rec['mpesa_receipt'] ?: $rec['payment_reference']) . "</strong><br>Order Ref: " . e($rec['order_reference']) . "<br>Seller: " . e($rec['seller_name']) . "</div>
    </div>
    <table><thead><tr><th>Item Description</th><th>Gateway</th><th>Amount</th></tr></thead><tbody>
    <tr><td>" . e($rec['order_title']) . " (Qty: " . e($rec['quantity']) . " " . e($rec['unit']) . ")</td><td>" . e($rec['provider']) . "</td><td><strong>" . e(format_money($rec['amount'], $rec['currency'])) . "</strong></td></tr>
    </tbody></table>
    <div class='policy'><strong>Payment Status Terms:</strong><br>{$policyText}</div>
    </div></body></html>";
    exit;
}

// 3. BUYER REQUESTS A REFUND REVIEW. This does not claim that a provider refund has been sent.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'request_refund') {
    if (!user_can_buy($pdo)) { http_response_code(403); exit('Buyer access required.'); }
    verify_csrf();
    $pid=(int)($_POST['payment_id']??0);
    $st=$pdo->prepare("SELECT p.*,o.id AS order_id,o.buyer_id,o.seller_id,o.order_reference,o.status AS order_status FROM payments p JOIN orders o ON o.id=p.order_id WHERE p.id=? AND o.buyer_id=? AND p.status='successful' AND p.escrow_state='held_in_escrow'");
    $st->execute([$pid,$uid]); $pay=$st->fetch();
    if ($pay && !in_array($pay['order_status'],['completed','cancelled'],true)) {
        $exists=$pdo->prepare("SELECT id FROM disputes WHERE order_id=? AND opened_by=? AND status IN ('open','under_review') LIMIT 1");
        $exists->execute([$pay['order_id'],$uid]);
        if (!$exists->fetch()) {
            $ref='DSP-'.strtoupper(bin2hex(random_bytes(4)));
            $pdo->prepare("INSERT INTO disputes (dispute_reference,order_id,payment_id,opened_by,reason,claim_amount,description) VALUES (?,?,?,?,'Buyer refund review request',?,'Buyer requested a refund review from the payment receipt.')")
                ->execute([$ref,$pay['order_id'],$pid,$uid,$pay['amount']]);
            $pdo->prepare("UPDATE orders SET escrow_status='disputed',status='disputed' WHERE id=?")->execute([$pay['order_id']]);
            record_order_event($pdo,(int)$pay['order_id'],'refund_review_requested',$uid,'Buyer requested a refund review. No external refund has been processed.');
            notify_user($pdo,(int)$pay['seller_id'],'Buyer requested a refund review',"Order {$pay['order_reference']} is under administrator review.",'dispute','disputes.php');
            email_admins($pdo,(int)$pay['order_id'],'Refund review requested','The buyer requested a refund. Review the dispute and verify the actual payment provider balance before approving any refund.');
            email_order_user($pdo,(int)$pay['order_id'],$uid,'Refund request received','Your request has been sent for administrator review. This message is not confirmation that money has been refunded.');
            flash("Refund review {$ref} opened. The payment remains recorded as held until the provider refund is actually verified.", 'warning');
        } else flash('A refund or dispute review is already open for this order.', 'warning');
    } else $error='Refund review is unavailable for this payment or order.';
    header('Location: '.url('payments.php?receipt_id='.$pid)); exit;
}

// 4. TRIGGER REAL SAFARICOM M-PESA STK PUSH
$pendingCheckoutId = '';
$pendingPhone = '';
$pendingAmountKes = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mpesa_stk') {
    if (!user_can_buy($pdo)) { http_response_code(403); exit('Only verified Buyer accounts can pay for orders.'); }
    verify_csrf();
    $oid = (int)($_POST['order_id'] ?? 0);
    $mpesaPhone = trim($_POST['mpesa_phone'] ?? '');

    $oStmt = $pdo->prepare("SELECT * FROM orders WHERE id=? AND buyer_id=? AND escrow_status='unfunded' AND status='pending'");
    $oStmt->execute([$oid, $uid]);
    $ord = $oStmt->fetch();
    $activePay=$pdo->prepare("SELECT id FROM payments WHERE order_id=? AND status IN ('pending','initiated','successful') LIMIT 1");
    $activePay->execute([$oid]); $hasActivePayment=(bool)$activePay->fetch();

    if (!$ord) {
        $error = 'Select a valid unfunded order.';
    } elseif ($hasActivePayment) {
        $error = 'A payment attempt is already active for this order. Check your payment ledger before retrying.';
    } else {
        $rates = fx_rates();
        $fromRate = $rates[$ord['currency']] ?? 1;
        $kesRate = $rates['KES'] ?? 129.50;
        $amountKes = ($ord['currency'] === 'KES') ? (float)$ord['total_amount'] : (((float)$ord['total_amount'] / $fromRate) * $kesRate);

        $stk = trigger_mpesa_stk_push($mpesaPhone, $amountKes, $ord['order_reference'], 'ATO Order Payment');
        if (!$stk['ok']) {
            $error = $stk['message'];
        } else {
            $payRef = 'MPE-' . strtoupper(bin2hex(random_bytes(4)));
            $pdo->prepare(
                "INSERT INTO payments
                (order_id, payer_id, payment_reference, checkout_request_id, mpesa_phone,
                 provider, payment_method, amount, currency, escrow_state, status)
                VALUES (?, ?, ?, ?, ?, 'Safaricom M-Pesa STK Push', 'M-Pesa Express', ?, 'KES', 'awaiting_payment', 'pending')"
            )->execute([
                $oid, $uid, $payRef, $stk['checkout_request_id'], $stk['msisdn'], round($amountKes, 2)
            ]);

            $pendingCheckoutId = $stk['checkout_request_id'];
            $pendingPhone      = $stk['msisdn'];
            $pendingAmountKes  = round($amountKes, 2);
        }
    }
}

// 5. ALTERNATIVE PAN-AFRICAN / CARD ESCROW SETTLEMENT
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'other_rail') {
    if (!user_can_buy($pdo)) { http_response_code(403); exit('Only verified Buyer accounts can initiate payment.'); }
    verify_csrf();
    $oid = (int)($_POST['order_id'] ?? 0);
    $provider = trim($_POST['provider'] ?? 'Manual bank transfer');
    $providerReference = substr(trim($_POST['provider_reference'] ?? ''), 0, 120);

    $oStmt = $pdo->prepare("SELECT * FROM orders WHERE id=? AND buyer_id=? AND escrow_status='unfunded' AND status='pending'");
    $oStmt->execute([$oid, $uid]);
    $ord = $oStmt->fetch();
    $activePay=$pdo->prepare("SELECT id FROM payments WHERE order_id=? AND status IN ('pending','initiated','successful') LIMIT 1");
    $activePay->execute([$oid]); $hasActivePayment=(bool)$activePay->fetch();

    if ($ord && !$hasActivePayment && $providerReference !== '' && strlen($providerReference) >= 5) {
        $payRef = 'MAN-' . strtoupper(bin2hex(random_bytes(4)));
        $pdo->prepare(
            "INSERT INTO payments (order_id, payer_id, payment_reference, provider_reference, provider, payment_method, amount, currency, escrow_state, status, paid_at)
             VALUES (?, ?, ?, ?, ?, 'Manual external transfer', ?, ?, 'awaiting_payment', 'initiated', NULL)"
        )->execute([$oid, $uid, $payRef, $providerReference, $provider, $ord['total_amount'], $ord['currency']]);
        $newPayId = (int)$pdo->lastInsertId();

        record_order_event($pdo, $oid, 'manual_payment_reference_submitted', $uid, 'External payment reference submitted; awaiting administrator reconciliation.');
        email_admins($pdo, $oid, 'Manual payment requires reconciliation', 'A buyer submitted an external payment reference. Verify actual incoming funds with the provider or bank before approving.');
        flash("Payment reference recorded ({$payRef}). The order remains unpaid until an administrator verifies the actual incoming funds.", 'warning');
        header('Location: ' . url('payments.php?receipt_id=' . $newPayId));
        exit;
    } else {
        $error = $hasActivePayment ? 'A payment attempt is already active for this order. Check the payment ledger before submitting another reference.' : 'Enter a valid external transaction reference (at least 5 characters) for administrator reconciliation.';
    }
}

// Fetch Active Receipt if requested
$activeReceipt = null;
if (isset($_GET['receipt_id'])) {
    $rStmt = $pdo->prepare(
        "SELECT p.*, o.order_reference, o.title AS order_title, o.quantity, o.unit, o.status AS order_status, o.escrow_status, o.payout_status, o.payout_reference,
                b.full_name AS buyer_name, b.email AS buyer_email, s.full_name AS seller_name
         FROM payments p
         JOIN orders o ON o.id = p.order_id
         JOIN users b ON b.id = o.buyer_id
         JOIN users s ON s.id = o.seller_id
         WHERE p.id=? AND (o.buyer_id=? OR o.seller_id=? OR ?='admin') LIMIT 1"
    );
    $rStmt->execute([(int)$_GET['receipt_id'], $uid, $uid, $role]);
    $activeReceipt = $rStmt->fetch();
}

$unfunded = $pdo->prepare("SELECT o.* FROM orders o WHERE o.buyer_id=? AND o.escrow_status='unfunded' AND o.status='pending' AND NOT EXISTS (SELECT 1 FROM payments p WHERE p.order_id=o.id AND p.status IN ('pending','initiated','successful')) ORDER BY o.created_at DESC");
$unfunded->execute([$uid]);
$unfundedOrders = $unfunded->fetchAll();

$payments = $pdo->prepare(
    "SELECT p.*, o.order_reference, o.title, o.status AS order_status, o.escrow_status, o.payout_status
     FROM payments p
     JOIN orders o ON o.id = p.order_id
     WHERE o.buyer_id=? OR o.seller_id=? OR ?='admin'
     ORDER BY p.created_at DESC"
);
$payments->execute([$uid, $uid, $role]);
$list = $payments->fetchAll();

$pageTitle = 'Payments & Order Receipts';
require_once __DIR__ . '/app/includes/header.php';
?>

<style>
@media print {
    body * { visibility: hidden; }
    #official-receipt, #official-receipt * { visibility: visible; }
    #official-receipt { position: fixed; left: 0; top: 0; width: 100%; border: 1px solid #000 !important; box-shadow: none !important; }
    .no-print { display: none !important; }
}
</style>

<main class="container section">
 <div class="page-header">
  <div>
   <h1>Payments &amp; Order Receipts</h1>
   <p class="muted small" style="margin:0;">Payment status is recorded in the platform. Actual fund custody, refund execution and seller payout depend on the contracted payment or regulated escrow provider.</p>
  </div>
 </div>

 <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
 <?php show_flash(); ?>

 <!-- REAL-TIME M-PESA STK PIN POLLING OVERLAY -->
 <?php if ($pendingCheckoutId !== ''): ?>
  <div class="panel" style="border:2px solid #16a34a;background:#f0fdf4;margin-bottom:24px;text-align:center;">
   <h3 style="color:#15803d;margin-bottom:6px;">📲 M-Pesa PIN Prompt Sent to +<?= e($pendingPhone) ?></h3>
   <p style="margin-bottom:10px;">Enter your M-Pesa PIN on your phone to authorize <strong>KES <?= number_format($pendingAmountKes, 2) ?></strong>. Waiting for Safaricom confirmation...</p>
   <div id="stkPollProgress" style="font-weight:700;color:#166534;">⏳ Listening for Safaricom M-Pesa confirmation...</div>
  </div>
  <script>
  (function(){
      const cid = <?= json_encode($pendingCheckoutId) ?>;
      const timer = setInterval(async () => {
          try {
              const res = await fetch('<?= e(url('payments.php')) ?>?action=poll&checkout_id=' + encodeURIComponent(cid));
              const data = await res.json();
              if (data.status === 'successful') {
                  clearInterval(timer);
                  window.location.href = '<?= e(url('payments.php')) ?>?receipt_id=' + data.payment_id;
              } else if (data.status === 'failed' || data.status === 'expired') {
                  clearInterval(timer);
                  document.getElementById('stkPollProgress').textContent = data.status === 'expired' ? 'This order expired. If you completed payment, contact support and provide your M-Pesa receipt.' : '❌ M-Pesa prompt was cancelled or timed out.';
              }
          } catch (e) {}
      }, 2000);
  })();
  </script>
 <?php endif; ?>

 <!-- OFFICIAL DOWNLOADABLE RECEIPT CARD -->
 <?php if ($activeReceipt): 
    $isFinalized = (($activeReceipt['payout_status'] ?? '') === 'paid');
    $isRefunded  = ($activeReceipt['status'] === 'refunded');
    $borderCol   = $isRefunded ? '#dc2626' : ($isFinalized ? '#059669' : (($activeReceipt['order_status'] === 'disputed') ? '#dc2626' : '#d97706'));
 ?>
  <div id="official-receipt" class="panel" style="border-top:6px solid <?= $borderCol ?>;margin-bottom:28px;background:#fff;">
   <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px;border-bottom:1px solid #e2e8f0;padding-bottom:16px;margin-bottom:16px;">
    <div>
     <h2 style="margin:0;">AFRICA TRADE OS — PAYMENT RECORD</h2>
     <div class="muted small">Payment provider confirmation &amp; order workflow record</div>
    </div>
    <div style="text-align:right;">
     <?php if ($isRefunded): ?>
      <span class="badge badge-danger" style="font-size:13px;padding:6px 12px;">REFUND CONFIRMED</span>
     <?php elseif ($isFinalized): ?>
      <span class="badge badge-success" style="font-size:13px;padding:6px 12px;">SELLER PAYOUT TRANSFER CONFIRMED</span>
     <?php elseif (($activeReceipt['payout_status'] ?? '') === 'awaiting_transfer'): ?>
      <span class="badge badge-warning" style="font-size:13px;padding:6px 12px;">DELIVERY CONFIRMED — PAYOUT AWAITING TRANSFER</span>
     <?php elseif ($activeReceipt['escrow_state']==='awaiting_payment' && $activeReceipt['status']==='successful'): ?>
      <span class="badge badge-danger" style="font-size:13px;padding:6px 12px;">PAYMENT REQUIRES RECONCILIATION</span>
     <?php elseif ($activeReceipt['order_status'] === 'disputed'): ?>
      <span class="badge badge-danger" style="font-size:13px;padding:6px 12px;">DISPUTE REVIEW</span>
     <?php elseif ($activeReceipt['status'] !== 'successful'): ?>
      <span class="badge badge-warning" style="font-size:13px;padding:6px 12px;">AWAITING PAYMENT CONFIRMATION</span>
     <?php else: ?>
      <span class="badge badge-warning" style="font-size:13px;padding:6px 12px;">PAYMENT RECORDED — DELIVERY REVIEW PENDING</span>
     <?php endif; ?>
     <div class="small muted" style="margin-top:6px;">Receipt No: <strong><?= e($activeReceipt['mpesa_receipt'] ?: $activeReceipt['payment_reference']) ?></strong></div>
     <div class="small muted"><?= $activeReceipt['status']==='successful' ? 'Paid At:' : 'Created At:' ?> <?= e($activeReceipt['paid_at'] ?: $activeReceipt['created_at']) ?></div>
    </div>
   </div>

   <div class="grid-2" style="margin-bottom:16px;">
    <div>
     <div class="small muted">PAYER (BUYER)</div>
     <strong><?= e($activeReceipt['buyer_name']) ?></strong><br>
     <span class="small"><?= e($activeReceipt['buyer_email']) ?></span><br>
     <?php if ($activeReceipt['mpesa_phone']): ?><span class="small">M-Pesa Number: +<?= e($activeReceipt['mpesa_phone']) ?></span><?php endif; ?>
    </div>
    <div style="text-align:right;">
     <div class="small muted">ORDER &amp; PAYMENT REFERENCE</div>
     <strong><?= e($activeReceipt['order_reference']) ?></strong><br>
     <span class="small">Seller: <?= e($activeReceipt['seller_name']) ?></span><br>
     <span class="small">Gateway: <?= e($activeReceipt['provider']) ?></span>
    </div>
   </div>

   <div class="table-wrap" style="margin-bottom:16px;">
    <table>
     <thead><tr><th>Item Description</th><th>Quantity</th><th>Amount</th></tr></thead>
     <tbody>
      <tr>
       <td><strong><?= e($activeReceipt['order_title']) ?></strong></td>
       <td><?= e($activeReceipt['quantity']) ?> <?= e($activeReceipt['unit']) ?></td>
       <td><strong style="font-size:16px;"><?= e(format_money($activeReceipt['amount'], $activeReceipt['currency'])) ?></strong></td>
      </tr>
     </tbody>
    </table>
   </div>

   <!-- Payment status and custody notice -->
   <?php if ($isRefunded): ?>
    <div class="alert error"><strong>Refund status:</strong> A refund is recorded as complete after administrator verification of the external provider transaction.</div>
   <?php elseif ($isFinalized): ?>
    <div class="alert"><strong>Payout status:</strong> The administrator recorded the seller payout transfer as completed. Reference: <?= e($activeReceipt['payout_reference'] ?? '') ?></div>
   <?php elseif (($activeReceipt['payout_status'] ?? '') === 'awaiting_transfer'): ?>
    <div class="alert warning"><strong>Delivery confirmed:</strong> Seller payout has been authorized and is awaiting external transfer. This receipt does not claim the bank/provider transfer has completed.</div>
   <?php elseif ($activeReceipt['order_status'] === 'disputed'): ?>
    <div class="alert error"><strong>Dispute review:</strong> This order is restricted pending administrator review. No refund or payout is recorded as complete.</div>
   <?php elseif ($activeReceipt['status'] !== 'successful'): ?>
    <div class="alert warning"><strong>Payment pending:</strong> This payment has not yet been confirmed by the provider or administrator.</div>
   <?php else: ?>
    <div class="alert warning"><strong>Payment confirmed in the platform:</strong> The platform records this payment as held while the order moves through seller and delivery confirmation. This database status is not proof of funds held in a regulated third-party escrow account. Actual custody, refund and payout depend on the contracted payment provider or escrow custodian.</div>
   <?php endif; ?>

   <div class="no-print" style="display:flex;gap:10px;flex-wrap:wrap;justify-content:space-between;align-items:center;margin-top:16px;">
    <div style="display:flex;gap:10px;flex-wrap:wrap;">
     <a href="<?= e(url('payments.php?download_receipt=' . (int)$activeReceipt['id'])) ?>" class="btn dark">⬇ Download Receipt File</a>
     <button type="button" onclick="window.print()" class="btn outline">🖨️ Print / Save as PDF</button>
     <a href="<?= e(url('orders.php')) ?>" class="btn success">Go to Orders (Accept Delivery) →</a>
    </div>

    <?php if (!$isFinalized && !$isRefunded && $role === 'buyer' && $activeReceipt['status']==='successful' && $activeReceipt['escrow_state']==='held_in_escrow' && $activeReceipt['order_status']!=='disputed'): ?>
     <form method="post" onsubmit="return confirm('Open a refund review for administrator assessment? No refund is processed until the payment provider confirms it.');">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="request_refund">
      <input type="hidden" name="payment_id" value="<?= (int)$activeReceipt['id'] ?>">
      <button type="submit" class="btn btn-sm" style="background:#dc2626;color:#fff;">Request refund review</button>
     </form>
    <?php endif; ?>
   </div>
  </div>
 <?php endif; ?>

 <?php if ($unfundedOrders): ?>
  <div class="grid-2" style="margin-bottom:28px;">
   <div class="panel" style="border-top:4px solid #16a34a;">
    <span class="badge badge-success">SAFARICOM M-PESA STK PUSH</span>
    <h3 style="margin-top:8px;">Pay via Safaricom M-Pesa STK Push</h3>
    <p class="muted small">Enter your Safaricom phone number. A provider callback must confirm the transaction before the payment is marked successful.</p>
    <form method="post">
     <?= csrf_field() ?>
     <input type="hidden" name="action" value="mpesa_stk">
     <label>Select Order to Pay *</label>
     <select name="order_id" required>
      <?php foreach ($unfundedOrders as $uo): ?>
       <option value="<?= (int)$uo['id'] ?>" <?= $selOrder === (int)$uo['id'] ? 'selected' : '' ?>>
        <?= e($uo['order_reference']) ?> — <?= e($uo['title']) ?> (<?= e(format_money($uo['total_amount'], $uo['currency'])) ?>)
       </option>
      <?php endforeach; ?>
     </select>
     <label>Safaricom M-Pesa Phone Number *</label>
     <input name="mpesa_phone" value="<?= e($u['phone'] ?? '') ?>" required placeholder="0712345678 or 254712345678">
     <button class="btn mpesa btn-block" style="margin-top:16px;" type="submit">Send M-Pesa STK Push Prompt</button>
    </form>
   </div>

   <div class="panel card-featured">
    <span class="badge badge-info">MANUAL EXTERNAL TRANSFER</span>
    <h3 style="margin-top:8px;">Manual External Payment Reference</h3>
    <p class="muted small">Use this only after making a transfer through a payment method approved by the platform. Submit the external transaction reference; an administrator must reconcile the incoming funds before the order is treated as paid.</p>
    <form method="post">
     <?= csrf_field() ?>
     <input type="hidden" name="action" value="other_rail">
     <label>Select Order *</label>
     <select name="order_id" required>
      <?php foreach ($unfundedOrders as $uo): ?>
       <option value="<?= (int)$uo['id'] ?>" <?= $selOrder === (int)$uo['id'] ? 'selected' : '' ?>>
        <?= e($uo['order_reference']) ?> — <?= e(format_money($uo['total_amount'], $uo['currency'])) ?>
       </option>
      <?php endforeach; ?>
     </select>
     <label>Payment Provider / Rail *</label>
     <select name="provider">
      <option value="Manual Bank Transfer — Admin Verification">Bank / PAPSS transfer — manual verification</option>
      <option value="External Card Provider — Manual Verification">External Card Provider — Manual Verification</option>
      <option value="Other Provider Transfer — Manual Verification">Other provider transfer — manual verification</option>
     </select>
     <label>External Transaction Reference *</label><input name="provider_reference" required minlength="5" maxlength="120" placeholder="Bank / provider receipt or reference">
     <button class="btn dark btn-block" style="margin-top:16px;" type="submit">Submit for Payment Verification</button>
    </form>
   </div>
  </div>
 <?php endif; ?>

 <h3>Payment Ledger &amp; Downloadable Records</h3>
 <div class="table-wrap">
  <table>
   <thead><tr><th>Receipt / Ref</th><th>Order</th><th>Gateway</th><th>Amount</th><th>Payment / Payout Status</th><th>Receipt Action</th></tr></thead>
   <tbody>
    <?php if (!$list): ?>
     <tr><td colspan="6" class="muted" style="text-align:center;padding:24px;">No payment records yet.</td></tr>
    <?php else: ?>
     <?php foreach ($list as $p): 
        $isFin = (($p['payout_status'] ?? '') === 'paid');
        $isRef = ($p['status'] === 'refunded');
     ?>
      <tr>
       <td><strong><?= e($p['mpesa_receipt'] ?: $p['payment_reference']) ?></strong></td>
       <td><?= e($p['order_reference']) ?> — <?= e($p['title']) ?></td>
       <td><?= e($p['provider']) ?></td>
       <td><strong><?= e(format_money($p['amount'], $p['currency'])) ?></strong></td>
       <td>
        <?php if ($isRef): ?>
         <span class="badge badge-danger">Refund confirmed</span>
        <?php elseif ($isFin): ?>
         <span class="badge badge-success">Payout transferred</span>
        <?php else: ?>
         <span class="badge badge-warning"><?= e($p['status']==='successful' ? (($p['payout_status']??'')==='awaiting_transfer'?'Payout awaiting transfer':($p['order_status']==='disputed'?'Dispute review':'Payment recorded as held')) : 'Awaiting payment confirmation') ?></span>
        <?php endif; ?>
       </td>
       <td style="display:flex;gap:6px;flex-wrap:wrap;">
        <?php if ($p['status'] === 'successful' || $isRef): ?>
         <a href="<?= e(url('payments.php?receipt_id=' . (int)$p['id'])) ?>" class="btn btn-sm dark">View Receipt</a>
         <a href="<?= e(url('payments.php?download_receipt=' . (int)$p['id'])) ?>" class="btn btn-sm outline">⬇ Download</a>
        <?php else: ?>
         <span class="muted small">Awaiting provider confirmation</span>
        <?php endif; ?>
       </td>
      </tr>
     <?php endforeach; ?>
    <?php endif; ?>
   </tbody>
  </table>
 </div>
</main>

<?php require_once __DIR__ . '/app/includes/footer.php'; ?>