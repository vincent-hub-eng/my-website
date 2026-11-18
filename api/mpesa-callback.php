<?php
require_once __DIR__ . '/../app/bootstrap.php';
header('Content-Type: application/json; charset=UTF-8');

$payload = json_decode(file_get_contents('php://input'), true);
$callback = $payload['Body']['stkCallback'] ?? null;
if (!$callback || empty($callback['CheckoutRequestID'])) {
    http_response_code(400);
    echo json_encode(['ResultCode' => 1, 'ResultDesc' => 'Invalid callback']);
    exit;
}

$checkoutId = (string)$callback['CheckoutRequestID'];
$resultCode = (int)($callback['ResultCode'] ?? 1);

try {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare(
        'SELECT p.*, o.buyer_id, o.seller_id, o.order_reference,
                o.status AS order_status, o.escrow_status AS order_escrow_status
         FROM payments p JOIN orders o ON o.id=p.order_id
         WHERE p.checkout_request_id=? LIMIT 1 FOR UPDATE'
    );
    $stmt->execute([$checkoutId]);
    $payment = $stmt->fetch();

    if (!$payment) {
        $pdo->rollBack();
        echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Unknown checkout ignored']);
        exit;
    }
    if ($payment['status'] === 'successful') {
        $pdo->commit();
        echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Duplicate callback already processed']);
        exit;
    }
    if ($payment['status'] !== 'pending') {
        $pdo->commit();
        echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Payment is no longer pending']);
        exit;
    }

    if ($resultCode !== 0) {
        $pdo->prepare("UPDATE payments SET status='failed' WHERE id=? AND status='pending'")->execute([$payment['id']]);
        $pdo->commit();
        email_order_user($pdo, (int)$payment['order_id'], (int)$payment['buyer_id'], 'Payment was not completed', 'The M-Pesa request was not completed. You can retry payment if the order is still active.');
        echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Payment failure recorded']);
        exit;
    }

    $metadata = [];
    foreach (($callback['CallbackMetadata']['Item'] ?? []) as $item) {
        if (isset($item['Name'])) $metadata[$item['Name']] = $item['Value'] ?? null;
    }
    $receipt = trim((string)($metadata['MpesaReceiptNumber'] ?? ''));
    $paidAmount = isset($metadata['Amount']) ? (float)$metadata['Amount'] : 0.0;

    if ($receipt === '' || $paidAmount <= 0 || abs($paidAmount - (float)$payment['amount']) > 1.00) {
        $pdo->prepare("UPDATE payments SET status='successful', escrow_state='awaiting_payment', mpesa_receipt=?, paid_at=NOW() WHERE id=? AND status='pending'")
            ->execute([$receipt !== '' ? $receipt : null, $payment['id']]);
        $pdo->prepare("UPDATE orders SET escrow_status='disputed', status='disputed', stock_reserved=0 WHERE id=?")
            ->execute([$payment['order_id']]);
        $disputeRef='DSP-'.strtoupper(bin2hex(random_bytes(4)));
        $pdo->prepare("INSERT INTO disputes (dispute_reference,order_id,payment_id,affects_order,opened_by,reason,claim_amount,description) VALUES (?,?,?,1,?,'M-Pesa amount or receipt mismatch',?,'Provider returned success but the receipt or amount requires manual reconciliation.')")
            ->execute([$disputeRef,$payment['order_id'],$payment['id'],$payment['buyer_id'],$paidAmount]);
        record_order_event($pdo, (int)$payment['order_id'], 'mpesa_amount_or_receipt_mismatch', null, 'Provider success requires manual reconciliation; payment is not available for dispatch.');
        $pdo->commit();
        email_order_user($pdo, (int)$payment['order_id'], (int)$payment['buyer_id'], 'Payment received — verification required', 'The provider reported a successful transaction, but the receipt or amount requires review. Your order is blocked while an administrator reconciles it.');
        email_admins($pdo, (int)$payment['order_id'], 'M-Pesa payment requires reconciliation', 'A successful callback contained an invalid or mismatched receipt/amount. Verify the transaction in the Daraja statement before taking further action.');
        echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Payment received; administrator review required']);
        exit;
    }

    $pdo->prepare("UPDATE payments SET status='successful', escrow_state='held_in_escrow', mpesa_receipt=?, paid_at=NOW() WHERE id=? AND status='pending'")
        ->execute([$receipt, $payment['id']]);

    if ($payment['order_status'] === 'cancelled' || $payment['order_escrow_status'] !== 'unfunded') {
        $closedOrder = ($payment['order_status'] === 'cancelled');
        $pdo->prepare("UPDATE payments SET escrow_state='awaiting_payment' WHERE id=?")->execute([$payment['id']]);
        if ($closedOrder) $pdo->prepare("UPDATE orders SET escrow_status='disputed', status='disputed', stock_reserved=0 WHERE id=?")->execute([$payment['order_id']]);
        $disputeRef='DSP-'.strtoupper(bin2hex(random_bytes(4)));
        $reason=$closedOrder ? 'Late M-Pesa payment after order closure' : 'Duplicate M-Pesa payment requires review';
        $affectsOrder=$closedOrder ? 1 : 0;
        $pdo->prepare("INSERT INTO disputes (dispute_reference,order_id,payment_id,affects_order,opened_by,reason,claim_amount,description) VALUES (?,?,?,?,?,?,?,?)")
            ->execute([$disputeRef,$payment['order_id'],$payment['id'],$affectsOrder,$payment['buyer_id'],$reason,$paidAmount,'Successful provider callback arrived after order closure or another payment funding event.']);
        record_order_event($pdo, (int)$payment['order_id'], 'late_or_duplicate_payment', null, 'Successful payment arrived after order closure or another funding event; administrator reconciliation required.');
        $pdo->commit();
        email_order_user($pdo, (int)$payment['order_id'], (int)$payment['buyer_id'], 'Payment received — order review required', 'The payment provider reported an additional or late successful payment. An administrator will review this payment separately from the order workflow.');
        email_admins($pdo, (int)$payment['order_id'], 'Late or duplicate payment requires review', 'A successful M-Pesa callback arrived for a closed order or as an additional payment. Reconcile this payment separately; do not cancel a different valid payment.');
        echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Payment received; payment review required']);
        exit;
    }

    $pdo->prepare("UPDATE orders SET escrow_status='funded_held', status='pending', stock_reserved=0 WHERE id=? AND escrow_status='unfunded'")
        ->execute([$payment['order_id']]);
    record_order_event($pdo, (int)$payment['order_id'], 'payment_confirmed_held', null, 'Daraja callback confirmed payment; awaiting seller stock availability confirmation.');
    $pdo->commit();

    notify_user($pdo, (int)$payment['seller_id'], 'Confirm product availability', "Buyer payment for {$payment['order_reference']} succeeded. Please confirm stock availability (Yes/No) in Orders.", 'order', 'orders.php');
    email_order_user($pdo, (int)$payment['order_id'], (int)$payment['buyer_id'], 'Payment confirmed — escrow secured & awaiting seller check', 'Your M-Pesa payment (' . $receipt . ') was successfully confirmed and secured in the platform escrow account. We have notified the seller to verify product availability. Once confirmed, an administrator will assign our nearest registered delivery partner.');
    email_order_user($pdo, (int)$payment['order_id'], (int)$payment['seller_id'], 'Action Required: Confirm product availability', 'The buyer payment was successfully secured in escrow. Please sign in to Orders and confirm whether the product is available (Yes/No) so we can proceed with delivery assignment.');
    email_admins($pdo, (int)$payment['order_id'], 'Payment received for order — awaiting seller stock confirmation', 'Payment receipt ' . $receipt . ' confirmed. Waiting for seller availability confirmation before assigning a registered delivery company.');
    log_audit($pdo, 'mpesa_callback_success', 'order', (int)$payment['order_id'], 'Daraja receipt ' . $receipt);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('M-Pesa callback processing failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ResultCode' => 1, 'ResultDesc' => 'Processing failed']);
    exit;
}

echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
