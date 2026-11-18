<?php
// Run hourly: php scripts/expire-unpaid-orders.php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../app/bootstrap.php';
$st=$pdo->query("SELECT id,buyer_id,product_id,quantity,order_reference FROM orders WHERE status='pending' AND escrow_status='unfunded' AND (stock_reserved=1 OR product_id IS NULL) AND created_at < DATE_SUB(NOW(), INTERVAL 2 HOUR) AND NOT EXISTS (SELECT 1 FROM payments p WHERE p.order_id=orders.id AND p.status='successful') ORDER BY created_at ASC LIMIT 100");
$rows=$st->fetchAll(); $expired=0;
foreach($rows as $o) {
    try {
        $pdo->beginTransaction();
        $lock=$pdo->prepare("SELECT * FROM orders WHERE id=? AND status='pending' AND escrow_status='unfunded' AND (stock_reserved=1 OR product_id IS NULL) FOR UPDATE"); $lock->execute([$o['id']]); $current=$lock->fetch();
        if (!$current) { $pdo->rollBack(); continue; }
        $paid=$pdo->prepare("SELECT id FROM payments WHERE order_id=? AND status='successful' LIMIT 1"); $paid->execute([$o['id']]);
        if ($paid->fetch()) { $pdo->rollBack(); continue; }
        if (!empty($current['product_id']) && (int)$current['stock_reserved']===1) $pdo->prepare('UPDATE products SET stock_quantity=stock_quantity+? WHERE id=?')->execute([$current['quantity'],$current['product_id']]);
        $pdo->prepare("UPDATE orders SET status='cancelled',stock_reserved=0,stock_released_at=NOW() WHERE id=?")->execute([$o['id']]);
        $pdo->prepare("UPDATE payments SET status='failed' WHERE order_id=? AND status='initiated'")->execute([$o['id']]);
        record_order_event($pdo,(int)$o['id'],'unpaid_order_expired',null,'Unpaid order expired after two hours; reserved stock returned to inventory.');
        $pdo->commit();
        notify_user($pdo,(int)$o['buyer_id'],'Unpaid order expired',"Order {$o['order_reference']} expired after two hours. Reserved stock has been returned to inventory.",'order','orders.php');
        email_order_user($pdo,(int)$o['id'],(int)$o['buyer_id'],'Unpaid order expired','Your order was cancelled because payment was not confirmed within two hours. Any stock reservation has been returned where applicable.');
        $expired++;
    } catch(Throwable $e) { if($pdo->inTransaction()) $pdo->rollBack(); error_log('Order expiry failed: '.$e->getMessage()); }
}
fwrite(STDOUT,"Expired {$expired} unpaid order(s).\n");
