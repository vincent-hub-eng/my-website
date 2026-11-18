<?php
require_once __DIR__ . '/app/bootstrap.php';
require_login();

$uid = current_user_id();
$role = current_role();
$u = current_user($pdo);

function count_for(PDO $pdo, string $sql, array $params = []): int {
    $s = $pdo->prepare($sql);
    $s->execute($params);
    return (int)$s->fetchColumn();
}

$stats = [];
if ($role === 'buyer') {
    $stats['My Orders'] = count_for($pdo, "SELECT COUNT(*) FROM orders WHERE buyer_id=?", [$uid]);
    $stats['Escrow Secured'] = count_for($pdo, "SELECT COUNT(*) FROM orders WHERE buyer_id=? AND escrow_status='funded_held'", [$uid]);
    $stats['Orders Completed'] = count_for($pdo, "SELECT COUNT(*) FROM orders WHERE buyer_id=? AND status='completed'", [$uid]);
    $stats['Open RFQs'] = count_for($pdo, "SELECT COUNT(*) FROM rfqs WHERE buyer_id=? AND status='open'", [$uid]);
} elseif (in_array($role, ['seller', 'supplier'], true)) {
    $stats['Active Products'] = count_for($pdo, "SELECT COUNT(*) FROM products WHERE seller_id=? AND status='active'", [$uid]);
    $stats['Sales Orders'] = count_for($pdo, "SELECT COUNT(*) FROM orders WHERE seller_id=?", [$uid]);
    $stats['Awaiting Availability Check'] = count_for($pdo, "SELECT COUNT(*) FROM orders WHERE seller_id=? AND escrow_status='funded_held' AND seller_confirmed_at IS NULL AND seller_rejected_at IS NULL", [$uid]);
    $stats['Completed Sales'] = count_for($pdo, "SELECT COUNT(*) FROM orders WHERE seller_id=? AND status='completed'", [$uid]);
} elseif ($role === 'logistics') {
    $stats['Assigned Shipments'] = count_for($pdo, "SELECT COUNT(*) FROM shipments WHERE logistics_provider_id=?", [$uid]);
    $stats['Pending Pickup'] = count_for($pdo, "SELECT COUNT(*) FROM shipments s JOIN orders o ON o.id=s.order_id WHERE s.logistics_provider_id=? AND s.status='pending' AND o.admin_order_approved_at IS NOT NULL", [$uid]);
    $stats['In Transit'] = count_for($pdo, "SELECT COUNT(*) FROM shipments WHERE logistics_provider_id=? AND status IN ('in_transit','out_for_delivery')", [$uid]);
    $stats['Delivered Shipments'] = count_for($pdo, "SELECT COUNT(*) FROM shipments WHERE logistics_provider_id=? AND status='delivered'", [$uid]);
} elseif ($role === 'admin') {
    $stats['Total Platform Users'] = count_for($pdo, "SELECT COUNT(*) FROM users");
    $stats['Pending ID+Selfie KYC'] = count_for($pdo, "SELECT COUNT(*) FROM users WHERE id_verification_status='pending'");
    $stats['Awaiting Admin Approval & Courier'] = count_for($pdo, "SELECT COUNT(*) FROM orders WHERE escrow_status='funded_held' AND seller_confirmed_at IS NOT NULL AND admin_order_approved_at IS NULL");
    $stats['Active Marketplace Products'] = count_for($pdo, "SELECT COUNT(*) FROM products WHERE status='active'");
}

$pageTitle = 'Workspace Dashboard';
require_once __DIR__ . '/app/includes/header.php';
?>

<main class="container section">
 <div class="page-header">
  <div>
   <span class="badge badge-info"><?= e(strtoupper($role)) ?> WORKSPACE</span>
   <?php if ($role === 'admin'): ?>
    <span class="badge badge-success">ADMIN • ESCROW &amp; FULFILLMENT GOVERNANCE</span>
   <?php elseif ($role !== 'buyer'): ?>
    <?= is_fully_verified_partner($pdo) ? '<span class="badge badge-success">✓ OTP & ID+Selfie Verified</span>' : '<span class="badge badge-warning">Verification Required</span>' ?>
   <?php endif; ?>
   <h1 style="margin-top:6px;">Welcome, <?= e($u['full_name']) ?></h1>
  </div>

  <div style="display:flex;gap:10px;flex-wrap:wrap;">
   <?php if (user_can_sell($pdo)): ?>
    <a class="btn" href="<?= e(url('seller/product-create.php')) ?>">+ Upload Product</a>
    <a class="btn outline" href="<?= e(url('orders.php')) ?>">Manage Sales &amp; Stock</a>
   <?php endif; ?>
   <?php if (user_can_buy($pdo)): ?>
    <a class="btn dark" href="<?= e(url('marketplace.php')) ?>">Shop Marketplace</a>
    <a class="btn outline" href="<?= e(url('orders.php')) ?>">View Orders &amp; Enter PIN</a>
   <?php endif; ?>
   <?php if ($role === 'logistics'): ?>
    <a class="btn dark" href="<?= e(url('logistics/index.php')) ?>">Delivery &amp; ETA Operations</a>
   <?php endif; ?>
   <?php if ($role === 'admin'): ?>
    <a class="btn gold" href="<?= e(url('admin/index.php')) ?>">★ Admin ID &amp; Courier Control</a>
   <?php endif; ?>
  </div>
 </div>

 <?php show_flash(); ?>

 <?php if (!is_fully_verified_partner($pdo) && $role !== 'buyer'): ?>
  <div class="alert warning">
   <span class="alert-icon">⚠</span>
   <div>
    <strong>Complete Verification:</strong> Verify your Email OTP and upload your Front ID, Back ID & Selfie in <a href="<?= e(url('verify-account.php')) ?>"><strong>Account Verification Center</strong></a> to unlock full partner privileges.
   </div>
  </div>
 <?php endif; ?>

 <div class="grid">
  <?php foreach ($stats as $label => $val): ?>
   <div class="card">
    <div class="stat-label"><?= e($label) ?></div>
    <div class="stat"><?= $val ?></div>
   </div>
  <?php endforeach; ?>
 </div>

 <div class="panel" style="margin-top:30px;">
  <h3>Active Order &amp; Fulfillment Workflow Status</h3>
  <p class="muted small">Africa Trade OS ensures strict escrow protection, seller availability verification, admin proximity courier assignment, distance-based ETAs, and secure buyer PIN release.</p>
  <div style="display:flex;gap:12px;margin-top:16px;flex-wrap:wrap;">
   <a class="btn btn-sm outline" href="<?= e(url('orders.php')) ?>">View All Orders</a>
   <?php if ($role === 'buyer'): ?>
    <a class="btn btn-sm" href="<?= e(url('marketplace.php')) ?>">Browse Marketplace</a>
   <?php elseif (user_can_sell($pdo)): ?>
    <a class="btn btn-sm" href="<?= e(url('seller/quotations.php')) ?>">View Buyer RFQs</a>
   <?php elseif ($role === 'logistics'): ?>
    <a class="btn btn-sm" href="<?= e(url('logistics/index.php')) ?>">View Pending Shipments</a>
   <?php elseif ($role === 'admin'): ?>
    <a class="btn btn-sm" href="<?= e(url('disputes.php')) ?>">Review Escrow Disputes</a>
   <?php endif; ?>
  </div>
 </div>
</main>

<?php require_once __DIR__ . '/app/includes/footer.php'; ?>
