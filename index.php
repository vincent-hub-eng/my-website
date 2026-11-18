<?php
require_once __DIR__ . '/app/bootstrap.php';

$pageTitle = 'Shop & Source Across Africa';

$categories = $pdo->query("SELECT * FROM categories WHERE is_active=1 ORDER BY id ASC")->fetchAll();

$products = $pdo->query(
    "SELECT p.*, c.name AS category_name, u.full_name AS seller_name, u.role AS seller_role,
            bp.business_name, co.name AS origin_country
     FROM products p
     LEFT JOIN categories c ON c.id = p.category_id
     JOIN users u ON u.id = p.seller_id
     LEFT JOIN business_profiles bp ON bp.user_id = u.id
     LEFT JOIN countries co ON co.id = p.origin_country_id
     WHERE p.status = 'active' AND u.account_status = 'active'
     ORDER BY p.is_featured DESC, p.created_at DESC
     LIMIT 24"
)->fetchAll();

require_once __DIR__ . '/app/includes/header.php';
?>

<section class="store-hero">
 <div class="container store-hero-grid">
  <aside class="cat-sidebar">
   <div class="cat-sidebar-title">Browse Categories</div>
   <?php foreach ($categories as $cat): ?>
    <a href="<?= e(url('marketplace.php?category=' . (int)$cat['id'])) ?>">
     <span><?= e($cat['name']) ?></span>
     <span>›</span>
    </a>
   <?php endforeach; ?>
  </aside>

  <div class="promo-banner">
   <span class="promo-tag">DOMESTIC & CROSS-BORDER AFRICA MARKETPLACE</span>
   <h1>Buy Direct. Source Wholesale. Track Payments & Delivery Across Africa.</h1>
   <p>Every Seller & Supplier is verified with Front & Back ID + Selfie before listing. Browse product photos and specifications, pay through the configured provider, and follow each seller and delivery confirmation.</p>
   <div style="display:flex;gap:12px;flex-wrap:wrap;">
    <a class="btn gold" href="<?= e(url('marketplace.php')) ?>">Explore All Products</a>
    <?php if (!logged_in()): ?>
     <a class="btn btn-outline-light" href="<?= e(url('auth/register.php')) ?>">Create Free Account</a>
    <?php elseif (user_can_sell($pdo)): ?>
     <a class="btn btn-outline-light" href="<?= e(url('seller/product-create.php')) ?>">+ Upload Product Now</a>
    <?php endif; ?>
   </div>
  </div>

  <div class="side-trust-card">
   <div>
    <span class="badge badge-success">M-PESA + ORDER TRACKING</span>
    <h3 style="margin:10px 0 6px;font-size:17px;">Shop With Confidence</h3>
    <p class="muted small">Follow a controlled order workflow with seller confirmation, carrier collection, administrator dispatch approval and recorded payout reconciliation.</p>
   </div>
   <div style="border-top:1px solid #e2e8f0;padding-top:14px;display:flex;flex-direction:column;gap:8px;">
    <a class="btn btn-sm dark btn-block" href="<?= e(url('auth/register.php?role=buyer')) ?>">Start Buying Today</a>
    <a class="btn btn-sm outline btn-block" href="<?= e(url('auth/register.php?role=seller')) ?>">Open Seller / Supplier Shop</a>
   </div>
  </div>
 </div>

 <div class="container trust-strip">
  <div class="trust-pill">
   <div class="trust-icon">✓</div>
   <div><strong style="font-size:13.5px;">ID & Selfie Verified Sellers</strong><div class="muted small">Front/Back ID checked by Admin</div></div>
  </div>
  <div class="trust-pill">
   <div class="trust-icon">M</div>
   <div><strong style="font-size:13.5px;">M-Pesa STK Push Checkout</strong><div class="muted small">Instant mobile prompt & payment status tracking</div></div>
  </div>
  <div class="trust-pill">
   <div class="trust-icon">⇄</div>
   <div><strong style="font-size:13.5px;">Domestic & Pan-African</strong><div class="muted small">Local delivery & AfCFTA export</div></div>
  </div>
  <div class="trust-pill">
   <div class="trust-icon">★</div>
   <div><strong style="font-size:13.5px;">Retail & Bulk Wholesale</strong><div class="muted small">Buy 1 piece or request RFQ quotes</div></div>
  </div>
 </div>
</section>

<section class="section">
 <div class="container">
  <div class="page-header">
   <div>
    <h2>Featured & Latest Products</h2>
    <p class="muted small" style="margin:0;">Real products uploaded by verified merchants—click any item to select specs, color & quantity.</p>
   </div>
   <a class="btn btn-sm outline" href="<?= e(url('marketplace.php')) ?>">View Full Catalog →</a>
  </div>

  <?php if (!$products): ?>
   <div class="panel" style="text-align:center;padding:56px 24px;">
    <h3>Storefront Ready for Your First Product!</h3>
    <p class="muted" style="max-width:540px;margin:0 auto 20px;">Zero fake listings exist in the database. Sign in as the Platform Owner (Admin, Seller & Buyer) or register as a Seller to upload your first product with photos, colors, and specs.</p>
    <div style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap;">
     <a class="btn gold" href="<?= e(url('auth/login.php')) ?>">Sign In & Upload First Product</a>
     <a class="btn dark" href="<?= e(url('auth/register.php?role=seller')) ?>">Register New Seller</a>
    </div>
   </div>
  <?php else: ?>
   <div class="product-grid">
    <?php foreach ($products as $p):
     $hasDiscount = !empty($p['compare_price']) && (float)$p['compare_price'] > (float)$p['price'];
     $pctOff = $hasDiscount ? round((1 - ((float)$p['price'] / (float)$p['compare_price'])) * 100) : 0;
    ?>
     <a class="k-card" href="<?= e(url('marketplace.php?product=' . (int)$p['id'])) ?>">
      <div class="k-img-wrap">
       <img src="<?= e(url($p['image_path'])) ?>" alt="<?= e($p['name']) ?>">
       <?php if ($pctOff > 0): ?>
        <span class="k-discount-badge">-<?= $pctOff ?>%</span>
       <?php endif; ?>
       <span class="k-role-badge"><?= e($p['shipping_scope'] === 'domestic' ? 'DOMESTIC' : ($p['shipping_scope'] === 'cross_border' ? 'CROSS-BORDER' : 'DOMESTIC & AFRICA')) ?></span>
      </div>
      <div class="k-body">
       <div class="k-title"><?= e($p['name']) ?></div>
       <div>
        <span class="k-price"><?= e(format_money($p['price'], $p['currency'])) ?></span>
        <?php if ($hasDiscount): ?>
         <span class="k-compare"><?= e(format_money($p['compare_price'], $p['currency'])) ?></span>
        <?php endif; ?>
       </div>
       <div class="k-meta">MOQ: <?= number_format((float)$p['minimum_order_quantity'], 0) ?> <?= e($p['unit']) ?> • <?= e($p['origin_country'] ?? 'Kenya') ?></div>
       <div class="k-seller">
        <span>✓ <?= e($p['business_name'] ?: $p['seller_name']) ?></span>
        <span style="color:#ea580c;">Buy →</span>
       </div>
      </div>
     </a>
    <?php endforeach; ?>
   </div>
  <?php endif; ?>
 </div>
</section>


<!-- INTERACTIVE HD EXPLAINER VIDEO SECTION (100% SELF-CONTAINED, WORKS OFFLINE & ONLINE) -->
<section class="section" style="padding-top:20px;padding-bottom:30px;">
 <div class="container">
  <div style="background:linear-gradient(135deg,#081220 0%,#0f1f38 100%);border:2px solid #1e3a8a;border-radius:18px;padding:28px;color:#fff;box-shadow:0 20px 45px rgba(8,18,32,0.25);">
   <div style="display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:14px;margin-bottom:18px;">
    <div>
     <span class="badge" style="background:#ea580c;color:#fff;font-weight:800;">🎥 PLATFORM EXPLAINER VIDEO</span>
     <h2 style="margin:6px 0 4px 0;color:#fff;font-size:24px;">How Africa Trade OS Works — Pan-African B2B &amp; B2C Trade</h2>
     <p style="margin:0;color:#cbd5e1;font-size:13.5px;">Watch the interactive visual walkthrough: Manual ID and selfie review, M-Pesa payment confirmation and AfCFTA cross-border trade.</p>
    </div>
    <div style="display:flex;gap:8px;">
     <button type="button" id="vidPlayPauseBtn" class="btn gold" style="font-weight:800;padding:10px 20px;">▶ Play Explainer Video</button>
     <button type="button" id="vidRestartBtn" class="btn outline" style="color:#fff;border-color:#475569;">↺ Restart</button>
    </div>
   </div>

   <div style="position:relative;width:100%;max-width:920px;margin:0 auto;aspect-ratio:16/9;background:#030712;border-radius:14px;overflow:hidden;border:2px solid #334155;box-shadow:0 12px 30px rgba(0,0,0,0.6);">
    <canvas id="africaTradeExplainerCanvas" width="960" height="540" style="width:100%;height:100%;display:block;"></canvas>
    <!-- Video Scrub & Chapter Bar -->
    <div style="position:absolute;bottom:0;left:0;right:0;background:linear-gradient(transparent,rgba(0,0,0,0.92));padding:14px 18px;display:flex;flex-direction:column;gap:8px;">
     <div style="width:100%;height:6px;background:#1e293b;border-radius:99px;overflow:hidden;cursor:pointer;" id="vidProgressTrack">
      <div id="vidProgressFill" style="width:0%;height:100%;background:linear-gradient(90deg,#f59e0b,#ea580c);transition:width 0.1s linear;"></div>
     </div>
     <div style="display:flex;justify-content:space-between;align-items:center;font-size:12px;color:#e2e8f0;">
      <span id="vidChapterTitle" style="font-weight:700;color:#fbbf24;">Chapter 1 / 4: Welcome to Africa Trade OS</span>
      <span id="vidTimeDisplay" style="font-family:monospace;">00:00 / 00:24</span>
     </div>
    </div>
   </div>
  </div>
 </div>
</section>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const canvas = document.getElementById('africaTradeExplainerCanvas');
    if (!canvas) return;
    const ctx = canvas.getContext('2d');
    const playBtn = document.getElementById('vidPlayPauseBtn');
    const restartBtn = document.getElementById('vidRestartBtn');
    const progFill = document.getElementById('vidProgressFill');
    const progTrack = document.getElementById('vidProgressTrack');
    const chapTitle = document.getElementById('vidChapterTitle');
    const timeDisp = document.getElementById('vidTimeDisplay');

    const TOTAL_DURATION = 24.0; // 24 seconds interactive HD presentation
    let elapsed = 0;
    let playing = true;
    let lastTs = performance.now();

    const scenes = [
        {
            start: 0, end: 6,
            title: "Chapter 1 / 4: Pan-African B2B & B2C Trade OS",
            badge: "54 AFRICAN COUNTRIES • AFCFTA READY",
            heading: "Buy & Sell Across Africa With Zero Fraud",
            sub: "Connecting verified Kenyan, Nigerian, South African, Ghanaian & East African merchants.",
            accent: "#f59e0b",
            icon: "🌍"
        },
        {
            start: 6, end: 12,
            title: "Chapter 2 / 4: ID & Selfie Verification Review",
            badge: "MANUAL IDENTITY REVIEW",
            heading: "Clear ID Front, ID Back & Selfie",
            sub: "Checks file type and image resolution, then sends the documents to an administrator for manual review.",
            accent: "#10b981",
            icon: "🛡️"
        },
        {
            start: 12, end: 18,
            title: "Chapter 3 / 4: M-Pesa STK Push & Payment Tracking",
            badge: "ORDER STATUS TRACKING",
            heading: "Payment & Delivery Workflow",
            sub: "Pay using the configured M-Pesa flow or submit an external payment reference for admin verification. Seller payout is reconciled after delivery confirmation.",
            accent: "#ea580c",
            icon: "📲"
        },
        {
            start: 18, end: 24,
            title: "Chapter 4 / 4: Bulk RFQs, Duty Calculator & PAN AFR AI",
            badge: "BUILT FOR SCALE",
            heading: "Source Bulk Orders & Calculate 0% AfCFTA Duty",
            sub: "Post wholesale RFQs, compare verified supplier quotes & get 24/7 guidance from PAN AFR AI.",
            accent: "#38bdf8",
            icon: "🚀"
        }
    ];

    function renderFrame(t) {
        const w = canvas.width;
        const h = canvas.height;
        ctx.clearRect(0, 0, w, h);

        // Background gradient
        const bg = ctx.createLinearGradient(0, 0, w, h);
        bg.addColorStop(0, '#060d1a');
        bg.addColorStop(1, '#0f2342');
        ctx.fillStyle = bg;
        ctx.fillRect(0, 0, w, h);

        // Subtle animated grid lines
        ctx.strokeStyle = 'rgba(56, 189, 248, 0.08)';
        ctx.lineWidth = 1;
        const offset = (t * 25) % 40;
        for (let x = offset; x < w; x += 40) {
            ctx.beginPath(); ctx.moveTo(x, 0); ctx.lineTo(x, h); ctx.stroke();
        }
        for (let y = offset; y < h; y += 40) {
            ctx.beginPath(); ctx.moveTo(0, y); ctx.lineTo(w, y); ctx.stroke();
        }

        const scene = scenes.find(s => t >= s.start && t < s.end) || scenes[scenes.length - 1];
        const localT = t - scene.start;
        const fade = Math.min(1, localT * 2.5);

        ctx.save();
        ctx.globalAlpha = fade;

        // Glowing orb behind icon
        const grad = ctx.createRadialGradient(w / 2, 145, 10, w / 2, 145, 120);
        grad.addColorStop(0, scene.accent + '44');
        grad.addColorStop(1, 'transparent');
        ctx.fillStyle = grad;
        ctx.beginPath();
        ctx.arc(w / 2, 145, 120, 0, Math.PI * 2);
        ctx.fill();

        // Emoji / Icon
        ctx.font = '68px sans-serif';
        ctx.textAlign = 'center';
        ctx.fillText(scene.icon, w / 2, 165 + Math.sin(t * 3) * 5);

        // Pill badge
        ctx.fillStyle = scene.accent + '26';
        ctx.strokeStyle = scene.accent;
        ctx.lineWidth = 1.5;
        const badgeW = 360;
        ctx.beginPath();
        ctx.roundRect(w / 2 - badgeW / 2, 205, badgeW, 30, 15);
        ctx.fill();
        ctx.stroke();

        ctx.fillStyle = scene.accent;
        ctx.font = 'bold 13px sans-serif';
        ctx.fillText(scene.badge, w / 2, 225);

        // Main Heading
        ctx.fillStyle = '#ffffff';
        ctx.font = 'bold 34px sans-serif';
        ctx.fillText(scene.heading, w / 2, 285);

        // Subtitle
        ctx.fillStyle = '#cbd5e1';
        ctx.font = '18px sans-serif';
        ctx.fillText(scene.sub, w / 2, 330);

        // Bottom step indicators
        scenes.forEach((sc, idx) => {
            const pillX = w / 2 - 140 + idx * 75;
            ctx.fillStyle = (sc === scene) ? scene.accent : '#334155';
            ctx.beginPath();
            ctx.roundRect(pillX, 385, 60, 6, 3);
            ctx.fill();
        });

        ctx.restore();

        chapTitle.textContent = scene.title;
        const pct = Math.min(100, (t / TOTAL_DURATION) * 100);
        progFill.style.width = pct + '%';
        const secStr = String(Math.floor(t)).padStart(2, '0');
        timeDisp.textContent = `00:${secStr} / 00:24`;
    }

    function tick(now) {
        const dt = (now - lastTs) / 1000;
        lastTs = now;
        if (playing) {
            elapsed += dt;
            if (elapsed >= TOTAL_DURATION) {
                elapsed = 0; // loop smoothly
            }
            renderFrame(elapsed);
        }
        requestAnimationFrame(tick);
    }

    playBtn.addEventListener('click', () => {
        playing = !playing;
        playBtn.textContent = playing ? '⏸ Pause Video' : '▶ Play Explainer Video';
    });

    restartBtn.addEventListener('click', () => {
        elapsed = 0;
        playing = true;
        playBtn.textContent = '⏸ Pause Video';
        renderFrame(0);
    });

    progTrack.addEventListener('click', (e) => {
        const rect = progTrack.getBoundingClientRect();
        const ratio = Math.max(0, Math.min(1, (e.clientX - rect.left) / rect.width));
        elapsed = ratio * TOTAL_DURATION;
        renderFrame(elapsed);
    });

    playBtn.textContent = '⏸ Pause Video';
    requestAnimationFrame(tick);
});
</script>

<?php require_once __DIR__ . '/app/includes/footer.php'; ?>
