<?php
require_once __DIR__ . '/app/bootstrap.php';
require_login();
$uid = current_user_id();
$role = current_role();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $oid = (int)($_POST['order_id'] ?? 0);
    $type = $_POST['doc_type'] ?? 'commercial_invoice';
    $oStmt = $pdo->prepare("SELECT * FROM orders WHERE id=? AND (buyer_id=? OR seller_id=? OR ?='admin')");
    $oStmt->execute([$oid, $uid, $uid, $role]);
    $ord = $oStmt->fetch();
    if ($ord) {
        $docNum = 'DOC-' . random_int(10000, 99999);
        $title = ucwords(str_replace('_', ' ', $type)) . ' — ' . $ord['order_reference'];
        $body = "DOCUMENT: {$docNum}
ORDER: {$ord['order_reference']}
ITEM: {$ord['title']}
OPTIONS: {$ord['selected_variant']}
QTY: {$ord['quantity']} {$ord['unit']}
TOTAL: " . format_money($ord['total_amount'], $ord['currency']);
        $pdo->prepare("INSERT INTO trade_documents (order_id, uploaded_by, doc_type, document_number, title, generated_content) VALUES (?, ?, ?, ?, ?, ?)")
            ->execute([$oid, $uid, $type, $docNum, $title, $body]);
        flash("Generated {$title}");
    }
    header('Location: ' . url('documents.php'));
    exit;
}

$orders = $pdo->prepare("SELECT id, order_reference, title FROM orders WHERE buyer_id=? OR seller_id=? OR ?='admin'");
$orders->execute([$uid, $uid, $role]);
$userOrders = $orders->fetchAll();

$docs = $pdo->prepare("SELECT d.*, o.order_reference FROM trade_documents d JOIN orders o ON o.id=d.order_id WHERE o.buyer_id=? OR o.seller_id=? OR ?='admin' ORDER BY d.created_at DESC");
$docs->execute([$uid, $uid, $role]);
$docList = $docs->fetchAll();

$pageTitle = 'Invoices & Trade Documents';
require_once __DIR__ . '/app/includes/header.php';
?>
<main class="container section">
 <h1>Invoices & Cross-Border Trade Documents</h1>
 <?php show_flash(); ?>
 <?php if ($userOrders): ?>
  <form method="post" class="panel" style="max-width:540px;margin-bottom:24px;">
   <?= csrf_field() ?>
   <label>Order</label><select name="order_id"><?php foreach ($userOrders as $uo): ?><option value="<?= (int)$uo['id'] ?>"><?= e($uo['order_reference']) ?> — <?= e($uo['title']) ?></option><?php endforeach; ?></select>
   <label>Document Type</label><select name="doc_type"><option value="commercial_invoice">Commercial Invoice</option><option value="packing_list">Packing List</option><option value="certificate_of_origin">AfCFTA Certificate of Origin</option></select>
   <button class="btn" style="margin-top:14px;" type="submit">Generate Document</button>
  </form>
 <?php endif; ?>
 <div class="table-wrap">
  <table>
   <thead><tr><th>Doc No.</th><th>Title</th><th>Order</th><th>Details</th></tr></thead>
   <tbody>
    <?php foreach ($docList as $d): ?>
     <tr><td><strong><?= e($d['document_number']) ?></strong></td><td><?= e($d['title']) ?></td><td><?= e($d['order_reference']) ?></td><td><pre style="margin:0;font-size:12px;"><?= e($d['generated_content']) ?></pre></td></tr>
    <?php endforeach; ?>
   </tbody>
  </table>
 </div>
</main>
<?php require_once __DIR__ . '/app/includes/footer.php'; ?>
