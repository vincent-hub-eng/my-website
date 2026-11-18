<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_login();

if (!user_can_sell($pdo)) {
    http_response_code(403);
    exit('Only active, verified Seller or Supplier accounts can upload products.');
}
require_verified_partner($pdo);

$uid   = current_user_id();
$error = '';

// Built-in studio product images available when seller lacks gallery photos
$builtInGallery = [
    'uploads/products/laptop_elitebook.svg' => 'Business Laptop',
    'uploads/products/phone_galaxy.svg'     => '5G Smartphone',
    'uploads/products/solar_inverter.svg'   => 'Solar Inverter & Battery',
    'uploads/products/smart_tv_55.svg'      => '4K Smart TV',
    'uploads/products/sneakers_pro.svg'     => 'Running Sneakers',
    'uploads/products/coffee_aa.svg'        => 'Export Coffee Beans',
    'uploads/products/fresh-produce.svg'    => 'Fresh Produce & Fruit',
    'uploads/products/fashion-textiles.svg' => 'Fashion & Textiles',
    'uploads/products/furniture.svg'        => 'Furniture & Home',
    'uploads/products/auto-parts.svg'       => 'Automotive Parts',
    'uploads/products/construction.svg'     => 'Construction Supplies',
    'uploads/products/beauty-care.svg'      => 'Beauty & Personal Care',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $name          = trim($_POST['name'] ?? '');
    $categoryId    = (int)($_POST['category_id'] ?? 0);
    $sku           = strtoupper(trim($_POST['sku'] ?? 'PRD-' . random_int(1000, 9999)));
    $description   = trim($_POST['description'] ?? '');
    $extraSpecs    = trim($_POST['specifications'] ?? '');
    $condition     = trim($_POST['condition_grade'] ?? 'Brand New');
    $warranty      = trim($_POST['warranty_info'] ?? '');
    $shippingScope = $_POST['shipping_scope'] ?? 'both';
    $price         = filter_var($_POST['price'] ?? null, FILTER_VALIDATE_FLOAT);
    $comparePrice  = filter_var($_POST['compare_price'] ?? null, FILTER_VALIDATE_FLOAT);
    $currency      = strtoupper(trim($_POST['currency'] ?? 'KES'));
    $unit          = trim($_POST['unit'] ?? 'piece');
    $minimum       = filter_var($_POST['minimum_order_quantity'] ?? 1, FILTER_VALIDATE_FLOAT);
    $stock         = filter_var($_POST['stock_quantity'] ?? 50, FILTER_VALIDATE_FLOAT);
    $originCountry = (int)($_POST['origin_country_id'] ?? 1);

    // Ticked Colors & Ticked Category Specs
    $colorsArr   = $_POST['colors'] ?? [];
    $customColor = trim($_POST['custom_color'] ?? '');
    if ($customColor !== '') {
        $colorsArr[] = $customColor;
    }
    $colorsStr = $colorsArr ? implode(', ', array_unique(array_map('trim', $colorsArr))) : '';

    $tickedSpecs = $_POST['specs'] ?? [];
    $specsJson   = !empty($tickedSpecs) ? json_encode($tickedSpecs) : null;

    // Product image is optional. A seller may upload, take a camera photo,
    // choose an illustration, or publish without any image.
    $mainImagePath = null;
    $uploadDir = __DIR__ . '/../uploads/products/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
    $cameraDataUri = trim($_POST['camera_ai_image'] ?? '');
    $selectedBuiltIn = trim($_POST['builtin_image'] ?? '');

    if ($cameraDataUri !== '') {
        if (preg_match('#^data:image/(jpeg|png|webp);base64,#', $cameraDataUri, $m)) {
            $parts = explode(',', $cameraDataUri, 2);
            $bin = isset($parts[1]) ? base64_decode($parts[1], true) : false;
            if ($bin === false || strlen($bin) > 5 * 1024 * 1024 || strlen($bin) < 1000) {
                $error = 'Camera image is invalid or exceeds the 5MB limit.';
            } else {
                $tmp = tempnam(sys_get_temp_dir(), 'atoimg');
                file_put_contents($tmp, $bin);
                $info = @getimagesize($tmp);
                $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
                $expected = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
                if (!$info || !isset($expected[$mime]) || $info[0] < 200 || $info[1] < 200) {
                    $error = 'Camera photo must be a valid image at least 200 × 200 pixels.';
                } else {
                    $fileName = 'cam_' . $uid . '_' . bin2hex(random_bytes(8)) . '.' . $expected[$mime];
                    if (file_put_contents($uploadDir . $fileName, $bin) !== false) $mainImagePath = 'uploads/products/' . $fileName;
                    else $error = 'Failed to save camera photo.';
                }
                @unlink($tmp);
            }
        } else {
            $error = 'Camera photo must be JPEG, PNG, or WebP.';
        }
    } elseif (!empty($_FILES['product_image']['name'])) {
        if ($_FILES['product_image']['error'] !== UPLOAD_ERR_OK) {
            $error = 'Product photo upload failed. Try again or continue without a photo.';
        } else {
            $mainImagePath = store_product_image($_FILES['product_image']['tmp_name'], $_FILES['product_image']['name'], $uid);
            if ($mainImagePath === null) $error = 'Choose a valid JPG, PNG, or WebP photo (200 × 200 minimum, 5MB maximum).';
        }
    } elseif ($selectedBuiltIn !== '') {
        if (array_key_exists($selectedBuiltIn, $builtInGallery)) $mainImagePath = $selectedBuiltIn;
        else $error = 'The selected product illustration is not available.';
    }

    // Optional extra angles: max 8 images, 5MB each, verified MIME + dimensions.
    $gallery = [];
    if (!$error && count($_FILES['gallery_images']['name'] ?? []) > 8) $error = 'You can upload a maximum of 8 additional product images.';
    if (!$error && !empty($_FILES['gallery_images']['name'][0])) {
        $total = count($_FILES['gallery_images']['name']);
        for ($idx = 0; $idx < $total; $idx++) {
            if (($_FILES['gallery_images']['error'][$idx] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
            if ($_FILES['gallery_images']['error'][$idx] !== UPLOAD_ERR_OK) { $error = 'One of the gallery images failed to upload.'; break; }
            $saved = store_product_image($_FILES['gallery_images']['tmp_name'][$idx], $_FILES['gallery_images']['name'][$idx], $uid);
            if ($saved === null) { $error = 'Gallery images must be valid JPG, PNG, or WebP files, at least 200 × 200 pixels and no larger than 5MB each.'; break; }
            $gallery[] = $saved;
        }
    }

    if (!$error && ($name === '' || $price === false || $price <= 0 || $minimum === false || $minimum <= 0)) {
        $error = 'Enter a valid product title, selling price, and minimum order quantity.';
    }

    if (!$error) {
        $stmt = $pdo->prepare(
            "INSERT INTO products
            (seller_id, category_id, sku, name, description, specifications, selected_specs,
             available_colors, condition_grade, warranty_info, shipping_scope,
             unit, minimum_order_quantity, price, compare_price, currency, stock_quantity,
             origin_country_id, image_path, gallery_paths, is_featured, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 'active')"
        );
        $stmt->execute([
            $uid, $categoryId ?: null, $sku, $name, $description, $extraSpecs, $specsJson,
            $colorsStr, $condition, $warranty, $shippingScope,
            $unit, $minimum, $price,
            ($comparePrice !== false && $comparePrice > $price) ? $comparePrice : null,
            $currency, $stock !== false ? $stock : 50,
            $originCountry ?: null, $mainImagePath,
            $gallery ? json_encode($gallery) : null
        ]);
        $prodId = (int)$pdo->lastInsertId();

        log_audit($pdo, 'product_uploaded', 'product', $prodId, "Uploaded {$name}");
        flash('Product listing saved. Buyers can now view it and contact you or place an order.');
        header('Location: ' . url('marketplace.php?product=' . $prodId));
        exit;
    }
}

$categories = $pdo->query("SELECT id, name, spec_template FROM categories WHERE is_active=1 ORDER BY id ASC")->fetchAll();
$countries  = $pdo->query("SELECT id, name FROM countries WHERE is_active=1 ORDER BY name")->fetchAll();

$myProducts = $pdo->prepare("SELECT * FROM products WHERE seller_id=? ORDER BY created_at DESC");
$myProducts->execute([$uid]);
$catalog = $myProducts->fetchAll();

$presetColors = ['Black', 'White', 'Silver', 'Space Gray', 'Gold', 'Blue', 'Red', 'Green', 'Brown', 'Yellow'];

$pageTitle = 'Upload Product';
require_once __DIR__ . '/../app/includes/header.php';
?>

<style>
.img-source-tabs { display: flex; gap: 8px; margin-bottom: 12px; flex-wrap: wrap; }
.img-tab-btn { flex: 1; min-width: 120px; padding: 9px 10px; border: 1px solid #cbd5e1; background: #f8fafc; border-radius: 8px; font-weight: 700; font-size: 12.5px; cursor: pointer; text-align: center; color: #0f172a; }
.img-tab-btn.active { background: #0f1f38; color: #fff; border-color: #0f1f38; }
.builtin-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; margin-top: 8px; }
.builtin-item { border: 2px solid #e2e8f0; border-radius: 8px; padding: 6px; text-align: center; cursor: pointer; background: #fff; transition: 0.15s; }
.builtin-item.selected { border-color: #ea580c; background: #fff7ed; }
.builtin-item img { width: 100%; height: 64px; object-fit: cover; border-radius: 5px; display: block; margin-bottom: 4px; }
.builtin-item span { font-size: 11px; font-weight: 700; display: block; color: #1e293b; }
</style>

<main class="container section">
 <div class="page-header">
  <div>
   <h1>Upload Product &amp; Configure Options</h1>
   <p class="muted small" style="margin:0;">Add product photos if available, choose a category illustration, or publish without photos. Up to 8 additional images are supported.</p>
  </div>
  <a class="btn dark" href="<?= e(url('seller/quotations.php')) ?>">View Buyer RFQs →</a>
 </div>

 <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
 <?php show_flash(); ?>

 <div class="grid-2">
  <div class="panel card-featured">
   <h3>1. Product Photos &amp; Basic Info</h3>
   <form method="post" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <input type="hidden" name="builtin_image" id="builtinImageInput" value="">
    <input type="hidden" name="camera_ai_image" id="cameraAiImageInput" value="">

    <label>Choose Main Product Image Source (Optional)</label>
    <div class="img-source-tabs">
     <button type="button" class="img-tab-btn active" onclick="switchImgTab('upload', this)">📁 Upload File</button>
     <button type="button" class="img-tab-btn" onclick="switchImgTab('builtin', this)">🖼️ Built-In Pictures</button>
     <button type="button" class="img-tab-btn" onclick="switchImgTab('camera', this)">📷 Camera Studio</button>
    </div>

    <!-- OPTION A: Standard Gallery File Upload (Not Mandatory) -->
    <div id="tab-upload" class="spec-box" style="margin-top:0;">
     <input type="file" id="productImageInput" name="product_image" accept=".jpg,.jpeg,.png,.webp">
     <p class="muted small" style="margin:4px 0 0;">Optional. You can publish without a photo, choose a built-in illustration, or take a camera photo.</p>
    </div>

    <!-- OPTION B: Built-In Product Picture Selector -->
    <div id="tab-builtin" class="spec-box" style="display:none;margin-top:0;">
     <div style="font-size:12.5px;font-weight:700;color:#0f172a;">Select a Clean Built-In Product Picture:</div>
     <div class="builtin-grid">
      <?php $first = false; foreach ($builtInGallery as $bPath => $bLabel): ?>
       <div class="builtin-item <?= $first ? 'selected' : '' ?>" onclick="selectBuiltIn('<?= e($bPath) ?>', this)">
        <img src="<?= e(url($bPath)) ?>" alt="<?= e($bLabel) ?>">
        <span><?= e($bLabel) ?></span>
       </div>
      <?php $first = false; endforeach; ?>
     </div>
    </div>

    <!-- OPTION C: Live Camera Studio -->
    <div id="tab-camera" class="spec-box" style="display:none;margin-top:0;background:#0f172a;color:#fff;text-align:center;">
     <div style="font-size:12.5px;font-weight:700;color:#38bdf8;margin-bottom:8px;">Camera Studio — capture a clear product photo</div>
     <video id="aiProdVideo" autoplay playsinline muted style="width:100%;max-height:220px;border-radius:8px;background:#000;object-fit:cover;"></video>
     <div style="display:flex;gap:8px;justify-content:center;margin-top:10px;flex-wrap:wrap;">
      <button type="button" class="btn btn-sm outline" style="color:#fff;border-color:#64748b;" onclick="startProductCamera()">Start / Flip Camera</button>
      <button type="button" class="btn btn-sm gold" onclick="captureProductPhoto()">📸 Capture Photo</button>
     </div>
     <div id="aiCleanBadge" style="display:none;margin-top:8px;font-size:12px;color:#34d399;font-weight:700;">✓ Image adjustments applied</div>
    </div>

    <!-- Unified Live Preview Box -->
    <div style="margin-top:10px;text-align:center;">
     <img id="productImagePreview" src="<?= e(url('uploads/products/product-placeholder.svg')) ?>" alt="Optional product image preview" style="max-height:185px;border-radius:10px;border:2px solid #cbd5e1;background:#f8fafc;">
     <div id="previewCaption" class="muted small" style="margin-top:4px;">No image selected — photo is optional</div>
    </div>
    <canvas id="aiProdCanvas" style="display:none;"></canvas>

    <label style="margin-top:12px;">Extra Angle / Detail Photos (Optional — Select Multiple)</label>
    <input type="file" id="galleryImagesInput" name="gallery_images[]" accept=".jpg,.jpeg,.png,.webp" multiple>
    <div id="galleryImagesPreview" style="display:flex;gap:8px;flex-wrap:wrap;margin:8px 0;"></div>
    <p class="muted small">Select up to 8 JPG, PNG or WebP images. Each image must be 5MB or smaller.</p>

    <label>Product Title *</label>
    <input name="name" required placeholder="e.g. HP EliteBook 840 G8 / Samsung Galaxy S24 Ultra / Hass Avocados">

    <div class="form-row">
     <div>
      <label>Category (Auto-loads spec tick-boxes) *</label>
      <select name="category_id" id="productCategorySelect" required>
       <?php foreach ($categories as $c): ?>
        <option value="<?= (int)$c['id'] ?>" data-specs='<?= e($c['spec_template'] ?? '{}') ?>'><?= e($c['name']) ?></option>
       <?php endforeach; ?>
      </select>
     </div>
     <div>
      <label>Delivery Coverage *</label>
      <select name="shipping_scope">
       <option value="both">Both Domestic &amp; Cross-Border Africa</option>
       <option value="domestic">Domestic Only (Within Country)</option>
       <option value="cross_border">Cross-Border Export Only</option>
      </select>
     </div>
    </div>

    <!-- Easy Tick-Box Section for Category Specs -->
    <div class="spec-box">
     <div style="font-size:13px;font-weight:800;color:#0f1f38;margin-bottom:6px;">2. Tick Available Specifications (Easy Selector)</div>
     <div id="dynamicSpecContainer"></div>
    </div>

    <!-- Easy Tick-Box Section for Available Colors -->
    <div class="spec-box">
     <div style="font-size:13px;font-weight:800;color:#0f1f38;margin-bottom:4px;">3. Tick Available Colors</div>
     <div class="chip-group">
      <?php foreach ($presetColors as $clr): ?>
       <label class="chip-check">
        <input type="checkbox" name="colors[]" value="<?= e($clr) ?>">
        <span><?= e($clr) ?></span>
       </label>
      <?php endforeach; ?>
     </div>
     <input name="custom_color" placeholder="Other color (optional, e.g. Rose Gold, Navy)" style="margin-top:8px;">
    </div>

    <div class="form-row">
     <div>
      <label>Selling Price *</label>
      <input type="number" name="price" min="0.01" step="0.01" required placeholder="45000">
     </div>
     <div>
      <label>Original Price (Optional — shows % OFF)</label>
      <input type="number" name="compare_price" min="0.01" step="0.01" placeholder="52000">
     </div>
    </div>

    <div class="form-row">
     <div>
      <label>Currency *</label>
      <select name="currency">
       <?php foreach (array_keys(fx_rates()) as $cur): ?>
        <option value="<?= $cur ?>"><?= $cur ?></option>
       <?php endforeach; ?>
      </select>
     </div>
     <div>
      <label>Unit (piece, kg, bag, carton, ton) *</label>
      <input name="unit" value="piece" required>
     </div>
    </div>

    <div class="form-row">
     <div>
      <label>Minimum Order Quantity (MOQ) *</label>
      <input type="number" name="minimum_order_quantity" value="1" min="1" step="1" required>
     </div>
     <div>
      <label>Available Stock Quantity *</label>
      <input type="number" name="stock_quantity" value="25" min="1" step="1" required>
     </div>
    </div>

    <div class="form-row">
     <div>
      <label>Condition / Grade</label>
      <select name="condition_grade">
       <option value="Brand New">Brand New</option>
       <option value="Certified Refurbished">Certified Refurbished</option>
       <option value="Grade 1 Export">Grade 1 Export Produce</option>
       <option value="Wholesale Bulk">Wholesale Bulk Lot</option>
      </select>
     </div>
     <div>
      <label>Ships From Country *</label>
      <select name="origin_country_id">
       <?php foreach ($countries as $co): ?>
        <option value="<?= (int)$co['id'] ?>"><?= e($co['name']) ?></option>
       <?php endforeach; ?>
      </select>
     </div>
    </div>

    <label>Warranty / Guarantee (Optional)</label>
    <input name="warranty_info" placeholder="e.g. 1 Year Official Warranty / Fresh Harvest Guarantee">

    <label>Product Description *</label>
    <textarea name="description" required placeholder="Highlight key benefits, box contents, or bulk packaging details..."></textarea>

    <label>Additional Technical Notes (Optional)</label>
    <textarea name="specifications" placeholder="Any extra details not covered in the tick-boxes above..."></textarea>

    <button class="btn btn-block" style="margin-top:18px;" type="submit">Publish Product Live</button>
   </form>
  </div>

  <div>
   <h3>Your Active Store Inventory (<?= count($catalog) ?>)</h3>
   <?php if (!$catalog): ?>
    <div class="panel"><p class="muted">No products uploaded yet. Use the form on the left to list your first item!</p></div>
   <?php else: ?>
    <div style="display:flex;flex-direction:column;gap:12px;">
     <?php foreach ($catalog as $item): ?>
      <div class="card" style="display:flex;gap:14px;align-items:center;padding:14px;">
       <img src="<?= e(product_image_url($item['image_path'])) ?>" alt="" style="width:76px;height:76px;object-fit:cover;border-radius:8px;border:1px solid #e2e8f0;">
       <div style="flex:1;">
        <div style="display:flex;justify-content:space-between;align-items:center;">
         <strong><?= e($item['name']) ?></strong>
         <?= status_badge($item['status']) ?>
        </div>
        <div class="price" style="font-size:16px;"><?= e(format_money($item['price'], $item['currency'])) ?> <span class="muted small">/ <?= e($item['unit']) ?></span></div>
        <div class="muted small">Stock: <?= e($item['stock_quantity']) ?> • Colors: <?= e($item['available_colors'] ?: 'Standard') ?></div>
        <a class="small" href="<?= e(url('marketplace.php?product=' . (int)$item['id'])) ?>">View Buyer Page →</a>
       </div>
      </div>
     <?php endforeach; ?>
    </div>
   <?php endif; ?>
  </div>
 </div>
</main>

<script>
let prodFacingMode = 'environment';
const prodVideo   = document.getElementById('aiProdVideo');
const prodCanvas  = document.getElementById('aiProdCanvas');
const previewImg  = document.getElementById('productImagePreview');
const previewCap  = document.getElementById('previewCaption');
const fileInput   = document.getElementById('productImageInput');
const builtInIn   = document.getElementById('builtinImageInput');
const camAiIn     = document.getElementById('cameraAiImageInput');
const aiBadge     = document.getElementById('aiCleanBadge');

function switchImgTab(tab, btn) {
    document.querySelectorAll('.img-tab-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    document.getElementById('tab-upload').style.display  = (tab === 'upload')  ? 'block' : 'none';
    document.getElementById('tab-builtin').style.display = (tab === 'builtin') ? 'block' : 'none';
    document.getElementById('tab-camera').style.display  = (tab === 'camera')  ? 'block' : 'none';

    // Switching source clears the previous source so a hidden old image cannot override the seller's choice.
    if (tab === 'upload') { camAiIn.value = ''; builtInIn.value = ''; previewImg.src = '<?= e(url('uploads/products/product-placeholder.svg')) ?>'; previewCap.textContent = 'No image selected — photo is optional'; }
    if (tab === 'builtin') { camAiIn.value = ''; fileInput.value = ''; builtInIn.value = ''; previewImg.src = '<?= e(url('uploads/products/product-placeholder.svg')) ?>'; previewCap.textContent = 'Choose an illustration or publish without a photo'; }
    if (tab === 'camera') { builtInIn.value = ''; fileInput.value = ''; previewImg.src = '<?= e(url('uploads/products/product-placeholder.svg')) ?>'; previewCap.textContent = 'Take a product photo or publish without one'; startProductCamera(); }
    else if (prodVideo.srcObject) prodVideo.srcObject.getTracks().forEach(t => t.stop());
}

fileInput.addEventListener('change', function() {
    if (this.files && this.files[0]) {
        camAiIn.value = '';
        const reader = new FileReader();
        reader.onload = e => {
            previewImg.src = e.target.result;
            previewCap.textContent = 'Uploaded photo: (' + this.files[0].name + ')';
        };
        reader.readAsDataURL(this.files[0]);
    }
});

function selectBuiltIn(relPath, el) {
    document.querySelectorAll('.builtin-item').forEach(i => i.classList.remove('selected'));
    el.classList.add('selected');
    builtInIn.value = relPath;
    camAiIn.value = '';
    fileInput.value = '';
    previewImg.src = el.querySelector('img').src;
    previewCap.textContent = 'Selected category illustration: ' + el.querySelector('span').textContent;
}

async function startProductCamera() {
    try {
        if (prodVideo.srcObject) {
            prodVideo.srcObject.getTracks().forEach(t => t.stop());
            prodFacingMode = (prodFacingMode === 'environment') ? 'user' : 'environment';
        }
        const stream = await navigator.mediaDevices.getUserMedia({
            video: { width: { ideal: 1280 }, height: { ideal: 1280 }, facingMode: prodFacingMode },
            audio: false
        });
        prodVideo.srcObject = stream;
    } catch (err) {
        alert('Camera unavailable or permission denied. You can choose a Built-In Picture instead.');
    }
}

// Captures a square frame and applies basic lighting, contrast, saturation and crop adjustments
function captureProductPhoto() {
    builtInIn.value = ''; fileInput.value = '';
    if (!prodVideo.videoWidth) {
        alert('Start the camera first before snapping.');
        return;
    }
    const size = 800;
    prodCanvas.width = size;
    prodCanvas.height = size;
    const ctx = prodCanvas.getContext('2d');

    // Clean bright studio background
    ctx.fillStyle = '#f8fafc';
    ctx.fillRect(0, 0, size, size);

    // Center square crop with simple brightness, contrast and saturation adjustments
    const vw = prodVideo.videoWidth;
    const vh = prodVideo.videoHeight;
    const minSide = Math.min(vw, vh);
    const sx = (vw - minSide) / 2;
    const sy = (vh - minSide) / 2;

    const pad = 36;
    ctx.save();
    ctx.filter = 'brightness(1.08) contrast(1.14) saturate(1.15)';
    ctx.beginPath();
    ctx.roundRect(pad, pad, size - pad * 2, size - pad * 2, 24);
    ctx.clip();
    ctx.drawImage(prodVideo, sx, sy, minSide, minSide, pad, pad, size - pad * 2, size - pad * 2);
    ctx.restore();

    // Subtle studio frame border
    ctx.strokeStyle = '#e2e8f0';
    ctx.lineWidth = 6;
    ctx.beginPath();
    ctx.roundRect(pad, pad, size - pad * 2, size - pad * 2, 24);
    ctx.stroke();

    const cameraDataUri = prodCanvas.toDataURL('image/jpeg', 0.92);
    camAiIn.value = cameraDataUri;
    fileInput.value = '';
    previewImg.src = cameraDataUri;
    previewCap.textContent = 'Camera photo captured';
    aiBadge.style.display = 'block';
}
const galleryPicker = document.getElementById('galleryImagesInput');
const galleryPreview = document.getElementById('galleryImagesPreview');
if (galleryPicker && galleryPreview) {
    galleryPicker.addEventListener('change', () => {
        galleryPreview.innerHTML = '';
        const files = Array.from(galleryPicker.files || []);
        if (files.length > 8) { alert('Choose up to 8 additional images.'); galleryPicker.value = ''; return; }
        files.forEach(file => {
            if (!/^image\/(jpeg|png|webp)$/.test(file.type) || file.size > 5 * 1024 * 1024) return;
            const img = document.createElement('img');
            img.src = URL.createObjectURL(file); img.alt = 'Selected gallery photo';
            img.style.cssText = 'width:76px;height:64px;object-fit:cover;border-radius:8px;border:1px solid #cbd5e1;';
            galleryPreview.appendChild(img);
        });
    });
}
</script>

<?php require_once __DIR__ . '/../app/includes/footer.php'; ?>