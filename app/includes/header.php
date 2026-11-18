<?php
$pageTitle = $pageTitle ?? 'Africa Trade OS';
$unreadMsgs = logged_in() ? unread_messages_count($pdo) : 0;
$currentScript = basename($_SERVER['SCRIPT_NAME'] ?? '');
$uInfo = logged_in() ? current_user($pdo) : null;
$searchQuery = trim($_GET['q'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle) ?> | Africa Trade OS</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(url('assets/css/style.css')) ?>">
</head>
<body>

<div class="top-bar">
 <div class="container top-bar-inner">
  <div>Domestic & Cross-Border AfCFTA Marketplace • M-Pesa Payment Tracking & Delivery Review</div>
  <div style="display:flex;gap:16px;align-items:center;">
   <a href="<?= e(url('terms.php')) ?>">Terms & Conditions</a>
   <a href="<?= e(url('trade-tools.php')) ?>">FX & Duty Calculator</a>
   <?php if (!logged_in()): ?>
    <a href="<?= e(url('auth/register.php?role=seller')) ?>">Sell on Africa Trade OS</a>
    <a href="<?= e(url('auth/register.php?role=supplier')) ?>">Bulk Supplier Portal</a>
   <?php elseif ($uInfo && !is_fully_verified_partner($pdo)): ?>
    <a href="<?= e(url('verify-account.php')) ?>" style="color:#fbbf24;font-weight:700;">⚠ Verify Email OTP & Front/Back ID + Selfie</a>
   <?php endif; ?>
  </div>
 </div>
</div>

<header class="main-header">
 <div class="container header-grid">
  <a class="brand" href="<?= e(url('index.php')) ?>">
   <span class="brand-badge">ATO</span>
   <div>
    <div class="brand-title">AFRICA <span>TRADE OS</span></div>
    <div class="brand-sub">Shop • Wholesale • Delivery</div>
   </div>
  </a>

  <form action="<?= e(url('marketplace.php')) ?>" method="get" class="header-search">
   <input type="text" name="q" value="<?= e($searchQuery) ?>" placeholder="Search laptops, phones, fashion, solar, agri-produce, wholesale lots...">
   <button type="submit">Search</button>
  </form>

  <div class="header-actions">
   <?php if (logged_in()): ?>
    <a href="<?= e(url('dashboard.php')) ?>" class="action-link">
     <span class="action-label"><?= e(strtoupper(current_role())) ?></span>
     <strong>Dashboard</strong>
    </a>
    <a href="<?= e(url('orders.php')) ?>" class="action-link">
     <span class="action-label">Payments</span>
     <strong>Orders</strong>
    </a>
    <a href="<?= e(url('messages.php')) ?>" class="action-link">
     <span class="action-label">Inbox <?php if ($unreadMsgs > 0): ?><span class="pill"><?= $unreadMsgs ?></span><?php endif; ?></span>
     <strong>Messages</strong>
    </a>
    <a href="<?= e(url('auth/logout.php')) ?>" class="btn btn-sm btn-outline-light">Logout</a>
   <?php else: ?>
    <a href="<?= e(url('auth/login.php')) ?>" class="action-link">
     <span class="action-label">Account</span>
     <strong>Sign In</strong>
    </a>
    <a href="<?= e(url('auth/register.php')) ?>" class="btn btn-sm gold">Join Free</a>
   <?php endif; ?>
  </div>
 </div>

 <nav class="sub-nav">
  <div class="container sub-nav-inner">
   <a href="<?= e(url('index.php')) ?>" class="<?= $currentScript === 'index.php' ? 'active' : '' ?>">Storefront</a>
   <a href="<?= e(url('marketplace.php')) ?>" class="<?= $currentScript === 'marketplace.php' ? 'active' : '' ?>">All Products</a>
   <?php if (logged_in()): ?>
    <?php if (user_can_buy($pdo)): ?>
     <a href="<?= e(url('rfq.php')) ?>">Post Bulk RFQ</a>
     <a href="<?= e(url('buyer/accept-quotations.php')) ?>">Compare Quotes</a>
    <?php endif; ?>
    <?php if (user_can_sell($pdo)): ?>
     <a href="<?= e(url('seller/product-create.php')) ?>" style="color:#fbbf24;font-weight:700;">+ Upload Product</a>
     <a href="<?= e(url('seller/quotations.php')) ?>">Buyer RFQs</a>
    <?php endif; ?>
    <?php if (current_role() === 'logistics' || current_role() === 'admin'): ?>
     <a href="<?= e(url('logistics/index.php')) ?>">Delivery & Freight</a>
    <?php endif; ?>
    <?php if (current_role() !== 'buyer' && current_role() !== 'admin'): ?>
     <a href="<?= e(url('verify-account.php')) ?>">ID & OTP Verification</a>
    <?php endif; ?>
    <?php if (current_role() === 'admin'): ?>
     <a href="<?= e(url('admin/index.php')) ?>" style="color:#34d399;font-weight:800;">★ Admin Control & ID Approvals</a>
    <?php endif; ?>
    <a href="<?= e(url('payments.php')) ?>">M-Pesa & Payments</a>
    <a href="<?= e(url('documents.php')) ?>">Invoices & Docs</a>
    <a href="<?= e(url('profile.php')) ?>">Profile</a>
   <?php else: ?>
    <a href="<?= e(url('marketplace.php?scope=domestic')) ?>">Domestic Deals</a>
    <a href="<?= e(url('marketplace.php?scope=cross_border')) ?>">Cross-Border AfCFTA</a>
    <a href="<?= e(url('trade-tools.php')) ?>">FX & Tariff Tools</a>
   <?php endif; ?>
  </div>
 </nav>
</header>
