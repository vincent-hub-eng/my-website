<?php
require_once __DIR__ . '/app/bootstrap.php';

// Handle mobile handover token login via email link
$mobileToken = trim($_GET['mobile_token'] ?? '');
if ($mobileToken !== '') {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE mobile_kyc_token=? AND mobile_kyc_expires_at > NOW()");
    $stmt->execute([$mobileToken]);
    $matchedUser = $stmt->fetch();
    if ($matchedUser) {
        session_regenerate_id(true);
        $_SESSION['user_id'] = $matchedUser['id'];
        $_SESSION['full_name'] = $matchedUser['full_name'];
        $_SESSION['role'] = $matchedUser['role'];
        $pdo->prepare("UPDATE users SET mobile_kyc_token=NULL, mobile_kyc_expires_at=NULL WHERE id=?")->execute([$matchedUser['id']]);
        flash('Secure mobile verification session active! Use your smartphone camera to capture the ID and selfie images.');
        header('Location: ' . url('verify-account.php'));
        exit;
    } else {
        flash('Mobile verification link is invalid or has already been used.', 'error');
    }
}

require_login();

$uid = current_user_id();
$u = current_user($pdo);
if (!$u) {
    session_destroy();
    header('Location: ' . url('auth/login.php'));
    exit;
}
$error = '';

// Step 1: Verify Email OTP
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'verify_email') {
    verify_csrf();
    $code = trim($_POST['otp_code'] ?? '');
    if ($code !== '' && !empty($u['email_verification_expires_at']) && strtotime($u['email_verification_expires_at']) >= time() && (password_verify($code, (string)$u['email_verification_code']) || hash_equals((string)$u['email_verification_code'], $code))) {
        $pdo->prepare("UPDATE users SET email_verified_at=NOW(), email_verification_code=NULL, email_verification_expires_at=NULL WHERE id=?")->execute([$uid]);
        log_audit($pdo, 'email_verified', 'user', $uid, 'Verified email via OTP');
        flash('Email address verified via OTP!');
        header('Location: ' . ($u['role'] === 'buyer' ? url('marketplace.php') : url('verify-account.php')));
        exit;
    } else {
        $error = 'Incorrect OTP code. Please check your email and try again.';
    }
}

// Resend Email OTP
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'resend_code') {
    verify_csrf();
    $newCode = (string)random_int(100000, 999999);
    $pdo->prepare("UPDATE users SET email_verification_code=?, email_verification_expires_at=DATE_ADD(NOW(), INTERVAL 15 MINUTE) WHERE id=?")->execute([password_hash($newCode, PASSWORD_DEFAULT), $uid]);
    send_platform_email(
        $u['email'],
        $u['full_name'],
        'New Verification OTP — Africa Trade OS',
        "<p>Your new 6-digit verification OTP code is:</p>
         <div style='font-size:28px;font-weight:800;letter-spacing:6px;background:#fff7ed;color:#ea580c;padding:14px 20px;border-radius:10px;text-align:center;'>{$newCode}</div>"
    );
    flash("A new 6-digit OTP has been sent to {$u['email']}.");
    header('Location: ' . url('verify-account.php'));
    exit;
}

// Generate & Send Mobile Handover Link
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_mobile_link') {
    verify_csrf();
    $tok = bin2hex(random_bytes(32));
    $pdo->prepare("UPDATE users SET mobile_kyc_token=?, mobile_kyc_expires_at=DATE_ADD(NOW(), INTERVAL 15 MINUTE) WHERE id=?")->execute([$tok, $uid]);
    
    $link = absolute_url('verify-account.php') . '?mobile_token=' . rawurlencode($tok);

    $htmlBody = "
        <h2>📱 Secure Mobile Verification Link</h2>
        <p>Hello <strong>" . e($u['full_name']) . "</strong>,</p>
        <p>You requested a secure link to capture your identity document and selfie on your smartphone.</p>
        <p style='margin:20px 0;'>
            <a href='{$link}' style='background:#ea580c;color:#fff;padding:14px 24px;border-radius:8px;text-decoration:none;font-weight:bold;display:inline-block;'>Open Secure ID &amp; Selfie Capture →</a>
        </p>
        <p style='color:#666;font-size:12px;'>Or copy and paste this link into your phone's browser:<br>{$link}</p>
    ";

    $sent = send_platform_email($u['email'], $u['full_name'], 'Complete Secure ID & Selfie Capture — Africa Trade OS', $htmlBody);
    
    if ($sent) {
        flash("Secure mobile verification link sent successfully to <strong>{$u['email']}</strong>! Check your phone inbox.");
    } else {
        flash("Mobile link generated! (<a href='{$link}' target='_blank'>Click here to open verification on mobile</a>)", 'warning');
    }
    header('Location: ' . url('verify-account.php'));
    exit;
}

// Validate and securely store camera or uploaded identity images.
function store_kyc_image(string $dataUri, string $tag, string $type): array {
    if ($dataUri === '') return ['ok'=>false, 'msg'=>"{$tag}: no camera image supplied."];
    if (!preg_match('#^data:image/(jpeg|png|webp);base64,([A-Za-z0-9+/=]+)$#', $dataUri, $m)) {
        return ['ok'=>false, 'msg'=>"{$tag}: unsupported image format."];
    }
    $bin=base64_decode($m[2], true);
    return store_kyc_image_bytes($bin, $tag, $type);
}
function store_kyc_upload(array $file, string $tag, string $type): array {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return ['ok'=>false, 'msg'=>"{$tag}: no file supplied."];
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK || empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        return ['ok'=>false, 'msg'=>"{$tag}: upload failed. Please choose the file again."];
    }
    if (($file['size'] ?? 0) > 10*1024*1024) return ['ok'=>false, 'msg'=>"{$tag}: maximum file size is 10MB."];
    $bin=file_get_contents($file['tmp_name']);
    return store_kyc_image_bytes($bin === false ? null : $bin, $tag, $type);
}
function store_kyc_image_bytes($bin, string $tag, string $type): array {
    if (!is_string($bin) || strlen($bin)<2000 || strlen($bin)>10*1024*1024) {
        return ['ok'=>false, 'msg'=>"{$tag}: image is empty, too small or larger than 10MB."];
    }
    $info=@getimagesizefromstring($bin);
    if (!$info) return ['ok'=>false, 'msg'=>"{$tag}: file is not a readable image. Upload JPG, PNG or WebP."];
    $allowed=[IMAGETYPE_JPEG=>'jpg', IMAGETYPE_PNG=>'png', IMAGETYPE_WEBP=>'webp'];
    if (!isset($allowed[$info[2]])) return ['ok'=>false, 'msg'=>"{$tag}: only JPG, PNG or WebP images are accepted."];
    // Deliberately lenient: administrator performs the actual identity check.
    $minW = $type==='selfie' ? 180 : 250;
    $minH = $type==='selfie' ? 180 : 150;
    if ($info[0]<$minW || $info[1]<$minH) return ['ok'=>false, 'msg'=>"{$tag}: image is too small. Please use a clearer photo or document scan."];
    $dir=__DIR__.'/uploads/ids/';
    if (!is_dir($dir) && !mkdir($dir,0700,true) && !is_dir($dir)) return ['ok'=>false,'msg'=>'Secure identity storage is unavailable.'];
    $fn='kyc_'.$GLOBALS['uid'].'_'.$tag.'_'.bin2hex(random_bytes(8)).'.'.$allowed[$info[2]];
    $path=$dir.$fn;
    if (file_put_contents($path,$bin,LOCK_EX)===false) return ['ok'=>false,'msg'=>"Failed to securely save {$tag} image."];
    @chmod($path,0600);
    return ['ok'=>true,'path'=>'uploads/ids/'.$fn];
}

// Final submission: accept camera photos or document-file uploads. Incomplete submissions
// are still queued for manual review so a genuine seller is not blocked by camera limitations.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_biometric_kyc') {
    verify_csrf();
    $allowedIdCountries = ['Kenya','Nigeria','South Africa','Ghana','Tanzania','Uganda','Rwanda','Ethiopia','Egypt','Morocco','Other AfCFTA State'];
    $allowedIdTypes = ['National ID','Passport','Alien ID'];
    $idCountry = trim($_POST['id_country'] ?? 'Kenya');
    $idType = trim($_POST['id_type'] ?? 'National ID');
    $idNumber = substr(preg_replace('/[^A-Za-z0-9\-]/', '', trim($_POST['id_number'] ?? '')), 0, 40);
    if (!in_array($idCountry, $allowedIdCountries, true) || !in_array($idType, $allowedIdTypes, true)) {
        $error = 'Select a valid issuing country and identity document type.';
    } elseif ($idNumber !== '' && strlen($idNumber) < 5) {
        $error = 'If you enter an ID number, please enter at least 5 characters. You may leave it blank and let the administrator review the document.';
    } else {
        $saved=[]; $issues=[];
        $specs=[
            'front'=>['front_image','front_file','id'],
            'back'=>['back_image','back_file','id'],
            'face'=>['face_image','selfie_file','selfie']
        ];
        foreach ($specs as $tag=>$spec) {
            [$dataField,$fileField,$kind]=$spec;
            $result=['ok'=>false,'msg'=>"{$tag}: not supplied."];
            if (!empty($_POST[$dataField])) $result=store_kyc_image((string)$_POST[$dataField],$tag,$kind);
            if (!$result['ok'] && isset($_FILES[$fileField]) && ($_FILES[$fileField]['error'] ?? UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE) {
                $result=store_kyc_upload($_FILES[$fileField],$tag,$kind);
            }
            if ($result['ok']) $saved[$tag]=$result['path'];
            else $issues[$tag]=$result['msg'];
        }

        // Reuse previously submitted images when a seller is only replacing a missing/unclear item.
        $frontPath=$saved['front'] ?? ($u['id_front_path'] ?? null);
        $backPath=$saved['back'] ?? ($u['id_back_path'] ?? null);
        $facePath=$saved['face'] ?? ($u['selfie_path'] ?? null);
        if (!empty($frontPath)) unset($issues['front']);
        if (!empty($backPath)) unset($issues['back']);
        if (!empty($facePath)) unset($issues['face']);
        $hasAny = !empty($frontPath) || !empty($backPath) || !empty($facePath);
        if (!$hasAny) {
            $error = 'Please capture or upload at least one image (ID front, ID back or selfie). You can submit the remaining items later.';
        } else {
            $encryptedId = null;
            if ($idNumber !== '' && strlen((string)(getenv('ATO_KYC_ENCRYPTION_KEY') ?: '')) >= 32) {
                $encryptedId=ato_encrypt_sensitive($idNumber);
                if ($encryptedId===false) $encryptedId=null;
            }
            $notes='Submitted for administrator review. '; 
            $notes .= 'Received: '.implode(', ', array_map(fn($k)=>['front'=>'ID front','back'=>'ID back','face'=>'selfie'][$k], array_keys(array_filter(['front'=>$frontPath,'back'=>$backPath,'face'=>$facePath])))).'. ';
            if ($issues) $notes .= 'Still requested: '.implode(', ', array_map(fn($k)=>['front'=>'ID front','back'=>'ID back','face'=>'selfie'][$k], array_keys($issues))).'. ';
            $notes .= 'Images are not automatically authenticated; administrator must inspect them.';

            // Do not erase a previously stored encrypted ID when the optional key is unavailable.
            if ($encryptedId !== null) {
                $pdo->prepare("UPDATE users SET id_number=?, id_front_path=?, id_back_path=?, selfie_path=?, id_verification_status='pending', kyc_auto_score=0, kyc_auto_notes=? WHERE id=?")
                    ->execute([$encryptedId,$frontPath,$backPath,$facePath,$notes,$uid]);
            } else {
                $pdo->prepare("UPDATE users SET id_front_path=?, id_back_path=?, selfie_path=?, id_verification_status='pending', kyc_auto_score=0, kyc_auto_notes=? WHERE id=?")
                    ->execute([$frontPath,$backPath,$facePath,$notes,$uid]);
            }

            $admins=$pdo->query("SELECT id FROM users WHERE role='admin' AND account_status='active'")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($admins as $adminId) {
                notify_user($pdo,(int)$adminId,'Identity documents submitted — Admin Review',"{$u['full_name']} submitted identity material. Please review the available images and request any missing items.",'verification','admin/index.php');
                email_user_notice($pdo,(int)$adminId,'Identity verification requires review',"{$u['full_name']} submitted identity material. {$notes} Please review in the Admin Governance Center.");
            }
            log_audit($pdo,'identity_documents_submitted','user',$uid,$notes);

            if ($issues) {
                $missingNames=implode(', ', array_map(fn($k)=>['front'=>'ID front','back'=>'ID back','face'=>'selfie'][$k], array_keys($issues)));
                $uploadLink=absolute_url('verify-account.php');
                $body='<p>Hello '.e($u['full_name']).',</p><p>We received the identity material you were able to provide and sent it to our verification team for manual review.</p><p>To complete your verification, please provide: <strong>'.e($missingNames).'</strong>.</p><p>You can return securely to your account and either use the camera or upload a clear JPG, PNG or WebP photo/scan of your document:</p><p><a href="'.e($uploadLink).'">Continue identity verification</a></p><p>If the camera is not working, a clear document scan or photo is accepted. Your account will remain pending until an administrator reviews it.</p>';
                send_platform_email($u['email'],$u['full_name'],'Action needed: complete your identity verification — Africa Trade OS',$body);
                flash('We sent the images you provided to our administrator for review. We also emailed you a link to provide the remaining items. You can upload document photos or scans instead of using the camera.');
            } else {
                flash('Your identity images were submitted to the administrator for manual review. Your account remains pending until the review is complete.');
            }
            header('Location: '.url('dashboard.php')); exit;
        }
    }
}

$stmt = $pdo->prepare("SELECT * FROM users WHERE id=?");
$stmt->execute([$uid]);
$u = $stmt->fetch();

$emailDone = !empty($u['email_verified_at']);
$idStatus = $u['id_verification_status'] ?? 'unverified';

$pageTitle = 'Identity Document & Selfie Verification';
require_once __DIR__ . '/app/includes/header.php';
?>

<main class="container section">
 <div class="page-header">
  <div>
   <h1>Identity Document &amp; Selfie Verification</h1>
   <p class="muted small" style="margin:0;">Submit clear photos of the front and back of your identity document and a live selfie. An administrator reviews the images before seller or logistics access is enabled.</p>
  </div>
 </div>

 <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
 <?php show_flash(); ?>

 <div class="grid-2">
  <div class="panel card-featured">
   <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
    <h3 style="margin:0;">Step 1: Email OTP Verification</h3>
    <?= $emailDone ? '<span class="badge badge-success">✓ Verified</span>' : '<span class="badge badge-warning">Pending OTP</span>' ?>
   </div>

   <?php if ($emailDone): ?>
    <p class="small" style="color:#059669;">Your email <strong><?= e($u['email']) ?></strong> is verified.</p>
   <?php else: ?>
    <p class="muted small">Enter the 6-digit OTP sent to <strong><?= e($u['email']) ?></strong>.</p>
    <div class="alert warning" style="font-size:12.5px;">The verification code expires after 15 minutes. If you cannot find the email, use Resend OTP.</div>
    <form method="post">
     <?= csrf_field() ?>
     <input type="hidden" name="action" value="verify_email">
     <label>6-Digit Email OTP *</label>
     <input name="otp_code" maxlength="6" required placeholder="e.g. 592014">
     <button class="btn btn-block" style="margin-top:14px;" type="submit">Confirm Email OTP</button>
    </form>
    <form method="post" style="margin-top:8px;">
     <?= csrf_field() ?>
     <input type="hidden" name="action" value="resend_code">
     <button class="btn btn-sm outline btn-block" type="submit">Resend OTP Email</button>
    </form>
   <?php endif; ?>
  </div>

  <?php if ($u['role'] !== 'buyer' && $u['role'] !== 'admin'): ?>
   <div class="panel card-featured">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
     <h3 style="margin:0;">Step 2: Capture ID &amp; selfie</h3>
     <?= status_badge($idStatus) ?>
    </div>

    <!-- Mobile Handover Option -->
    <div style="background:#0f172a;border:2px solid #38bdf8;border-radius:12px;padding:14px;margin-bottom:14px;color:#fff;text-align:center;">
     <h4 style="margin:0 0 6px 0;color:#38bdf8;font-size:14.5px;">📱 Scanning on PC? Open on Smartphone Instead</h4>
     <p style="font-size:12px;color:#cbd5e1;margin:0 0 10px 0;">Use a well-lit area. Capture the complete ID front and back without glare, then take a live, front-facing selfie with your face clearly visible. A reviewer will compare them manually.</p>
     <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="send_mobile_link">
      <button type="submit" class="btn gold btn-block" style="font-weight:800;padding:8px 14px;">📧 Send Secure Mobile Link to Email</button>
     </form>
    </div>

    <?php if ($idStatus === 'pending'): ?>
     <div class="alert warning">
      <strong>Manual Review Pending:</strong> Identity images submitted (<strong><?= e($u['kyc_auto_notes'] ?? '') ?></strong>).
     </div>
    <?php elseif ($idStatus === 'verified'): ?>
     <p class="small" style="color:#059669;">✓ Fully verified by Platform Administrator!</p>
    <?php endif; ?>

    <?php if ($idStatus !== 'verified'): ?>
     <form method="post" enctype="multipart/form-data" id="biometricKycForm" onsubmit="return validateBiometricForm(event)">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="submit_biometric_kyc">
      <input type="hidden" id="frontImageInput" name="front_image">
      <input type="hidden" id="backImageInput" name="back_image">
      <input type="hidden" id="faceImageInput" name="face_image">
      <div style="margin:12px 0;padding:13px;border:1px solid #dbe3ee;border-radius:12px;background:#f8fafc;color:#0f172a;">
       <strong>Camera not working? Upload photos or scans instead</strong>
       <p class="small" style="margin:5px 0 10px;">You can use your phone camera, gallery, or a scanned document. JPG, PNG and WebP up to 10MB each.</p>
       <label for="frontFileInput">ID front (optional alternative)</label><input type="file" id="frontFileInput" name="front_file" accept="image/jpeg,image/png,image/webp" capture="environment">
       <label for="backFileInput" style="margin-top:8px;">ID back (optional alternative)</label><input type="file" id="backFileInput" name="back_file" accept="image/jpeg,image/png,image/webp" capture="environment">
       <label for="selfieFileInput" style="margin-top:8px;">Selfie (optional alternative)</label><input type="file" id="selfieFileInput" name="selfie_file" accept="image/jpeg,image/png,image/webp" capture="user">
      </div>

      <div class="grid-2" style="gap:10px;">
       <div>
        <label>African Country *</label>
        <select name="id_country" required>
         <option value="Kenya">Kenya</option>
         <option value="Nigeria">Nigeria</option>
         <option value="South Africa">South Africa</option>
         <option value="Ghana">Ghana</option>
         <option value="Tanzania">Tanzania</option>
         <option value="Uganda">Uganda</option>
         <option value="Rwanda">Rwanda</option>
         <option value="Ethiopia">Ethiopia</option>
         <option value="Egypt">Egypt</option>
         <option value="Morocco">Morocco</option>
         <option value="Other AfCFTA State">Other African Nation</option>
        </select>
       </div>
       <div>
        <label>Document *</label>
        <select name="id_type" required>
         <option value="National ID">National ID</option>
         <option value="Passport">Passport</option>
         <option value="Alien ID">Alien ID</option>
        </select>
       </div>
      </div>

      <label style="margin-top:8px;">ID / Passport Number (optional if document image is supplied)</label>
      <input name="id_number" id="idNumberField" placeholder="e.g. 34829102" value="<?= e(ato_decrypt_sensitive($u['id_number'] ?? null)) ?>">

      <!-- Guided camera capture: document presence + face alignment -->
      <style>
        #bioViewfinder{position:relative;width:100%;max-width:440px;margin:0 auto;background:#000;border-radius:14px;overflow:hidden;aspect-ratio:4/3;transition:all .25s ease}
        #bioViewfinder.selfie-view{width:min(100%,340px);aspect-ratio:1/1;border-radius:50%;}
        #bioVideo{width:100%;height:100%;object-fit:cover;display:block}
        #bioOverlay{position:absolute;inset:18% 10%;border:3px dashed #fbbf24;border-radius:12px;pointer-events:none;box-shadow:0 0 0 999px rgba(0,0,0,.28);transition:all .25s ease}
        #bioViewfinder.selfie-view #bioOverlay{inset:8% 17%;border-radius:50%;border-width:4px}
        #bioInstruction{position:absolute;left:8px;right:8px;bottom:8px;background:rgba(0,0,0,.88);color:#fff;padding:9px;border-radius:8px;text-align:center;font-size:13px;font-weight:700;pointer-events:none}
        #bioViewfinder.selfie-view #bioInstruction{left:12%;right:12%;bottom:9%;font-size:12px}
        #bioViewfinder.selfie-view #bioInstruction{background:rgba(0,0,0,.78)}
      </style>
      <div id="guidedKycCamera" style="margin-top:14px;background:#07111f;border:1px solid #294568;border-radius:16px;padding:16px;color:#fff;">
        <div style="display:flex;justify-content:space-between;gap:10px;align-items:center;flex-wrap:wrap;">
          <strong>Guided photo capture</strong>
          <span id="cameraModeBadge" style="background:#164e63;color:#cffafe;padding:6px 10px;border-radius:20px;font-size:12px;font-weight:800;">AUTO CAPTURE</span>
        </div>
        <p class="small" style="color:#cbd5e1;margin:6px 0 12px;">Capture the front and back of your ID, then take a selfie. Manual capture is available as soon as the camera is ready; automatic capture is optional.</p>
        <div style="display:flex;gap:8px;margin-bottom:12px;">
          <button type="button" id="autoModeBtn" class="btn gold" aria-pressed="true" style="flex:1;">Automatic</button>
          <button type="button" id="manualModeBtn" class="btn outline" aria-pressed="false" style="flex:1;">Manual</button>
        </div>
        <div id="bioStatusHeader" role="status" aria-live="polite" style="background:#1d4ed8;color:#fff;padding:10px;border-radius:9px;font-size:13px;font-weight:800;margin-bottom:12px;">Step 1 of 3: Front of ID</div>
        <div id="bioViewfinder">
          <video id="bioVideo" autoplay playsinline muted></video>
          <div id="bioOverlay"></div>
          <div id="bioInstruction">Place the ID horizontally inside the guide. The whole card must be visible.</div>
        </div>
        <div style="display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap;margin:10px 0;">
          <span id="bioQuality" style="font-size:12px;color:#cbd5e1;">Waiting for camera…</span>
          <span id="bioCountdown" style="font-size:12px;color:#67e8f9;font-weight:800;"></span>
        </div>
        <div style="display:flex;gap:8px;">
          <button type="button" id="captureBtn" class="btn gold" disabled style="flex:1;font-weight:800;">Capture photo</button>
          <button type="button" id="retakeBtn" class="btn outline" style="display:none;flex:1;">Retake photo</button>
        </div>
        <p class="small" style="color:#cbd5e1;margin:10px 0 0;">Camera capture is optional. If it fails, upload clear photos or scans below. An administrator reviews all submitted material before approval.</p>
        <div style="display:flex;justify-content:center;gap:14px;margin-top:14px;border-top:1px solid #26384e;padding-top:12px;">
          <div style="text-align:center;"><img id="thumbFront" alt="Front ID preview" style="width:64px;height:46px;object-fit:cover;border-radius:6px;display:none;"><div style="font-size:10px;color:#cbd5e1;">Front ID</div></div>
          <div style="text-align:center;"><img id="thumbBack" alt="Back ID preview" style="width:64px;height:46px;object-fit:cover;border-radius:6px;display:none;"><div style="font-size:10px;color:#cbd5e1;">Back ID</div></div>
          <div style="text-align:center;"><img id="thumbFace" alt="Selfie preview" style="width:64px;height:46px;object-fit:cover;border-radius:50%;display:none;"><div style="font-size:10px;color:#cbd5e1;">Selfie</div></div>
        </div>
      </div>
      <button class="btn dark btn-block" style="margin-top:16px;display:block;font-weight:800;" id="submitBioFinalBtn" type="submit">Submit available documents for Admin Review</button>
      </form>
      <canvas id="bioCanvas" style="display:none;"></canvas>

      <script>
(() => {
  'use strict';
  const $ = (id) => document.getElementById(id);
  const video = $('bioVideo'), canvas = $('bioCanvas');
  const ctx = canvas && canvas.getContext('2d');
  const header = $('bioStatusHeader'), instruction = $('bioInstruction');
  const qualityText = $('bioQuality'), countdown = $('bioCountdown');
  const captureBtn = $('captureBtn'), retakeBtn = $('retakeBtn');
  const finalBtn = $('submitBioFinalBtn'), overlay = $('bioOverlay');
  const fileInputs = ['frontFileInput','backFileInput','selfieFileInput'].map($);
  const viewfinder = $('bioViewfinder'), modeBadge = $('cameraModeBadge');
  const autoBtn = $('autoModeBtn'), manualBtn = $('manualModeBtn');
  const inputs = ['frontImageInput','backImageInput','faceImageInput'].map($);
  const thumbs = ['thumbFront','thumbBack','thumbFace'].map($);
  const steps = [
    {name:'Front of ID', facing:'environment', face:false},
    {name:'Back of ID', facing:'environment', face:false},
    {name:'Selfie', facing:'user', face:true}
  ];
  if (!video || !canvas || !ctx || !captureBtn || inputs.some(x => !x)) return;

  let stage=0, lastCapturedStage=-1, stream=null, mode='manual';
  let captured=false, raf=0, goodSince=0, starting=false, cameraGeneration=0;

  function say(message, good=false) {
    if (instruction) instruction.textContent=message;
    if (qualityText) { qualityText.textContent=(good?'✓ ':'')+message; qualityText.style.color=good?'#86efac':'#fde68a'; }
  }
  function stopCamera() {
    if (raf) cancelAnimationFrame(raf);
    raf=0;
    if (stream) stream.getTracks().forEach(track=>track.stop());
    stream=null;
    try { video.pause(); } catch (_) {}
    video.srcObject=null;
  }
  function updateStage() {
    const selfie=steps[stage].face;
    viewfinder.classList.toggle('selfie-view',selfie);
    if (overlay) { overlay.style.borderRadius=selfie?'50%':'12px'; overlay.style.inset=selfie?'8% 17%':'18% 10%'; }
    header.textContent=`Step ${stage+1} of 3: ${steps[stage].name}`;
    header.style.background=selfie?'#6d28d9':'#1d4ed8';
    say(selfie?'Use the front camera. Keep your whole face visible and look at the lens.':'Place the complete ID horizontally in the guide. Keep all four edges visible.');
  }
  function setMode(next) {
    mode=next;
    autoBtn.setAttribute('aria-pressed',String(next==='auto'));
    manualBtn.setAttribute('aria-pressed',String(next==='manual'));
    autoBtn.style.opacity=next==='auto'?'1':'.65';
    manualBtn.style.opacity=next==='manual'?'1':'.65';
    modeBadge.textContent=next==='auto'?'AUTO CAPTURE':'MANUAL CAPTURE';
    goodSince=0;
    countdown.textContent='';
  }
  function basicFrameCheck() {
    if (video.readyState < HTMLMediaElement.HAVE_CURRENT_DATA || !video.videoWidth) return {ok:false,msg:'Waiting for camera image…'};
    // Only reject clearly unusable frames. This is not ID or face authentication.
    const w=160, h=Math.max(90,Math.round(w*video.videoHeight/video.videoWidth));
    const probe=document.createElement('canvas'); probe.width=w; probe.height=h;
    const pctx=probe.getContext('2d',{willReadFrequently:true});
    pctx.drawImage(video,0,0,w,h);
    const pixels=pctx.getImageData(0,0,w,h).data;
    let total=0, bright=0;
    for(let i=0;i<pixels.length;i+=16){ const lum=.2126*pixels[i]+.7152*pixels[i+1]+.0722*pixels[i+2]; total+=lum; if(lum>248) bright++; }
    const avg=total/Math.max(1,Math.ceil(pixels.length/16));
    const glare=bright/Math.max(1,Math.ceil(pixels.length/16));
    if(avg<28) return {ok:false,msg:'Too dark. Move to a brighter place.'};
    if(avg>248 || glare>.55) return {ok:false,msg:'Too much glare. Adjust the light or tilt the ID slightly.'};
    return {ok:true,msg:steps[stage].face?'Camera ready. Keep your face centred.':'Camera ready. Check that the full ID is visible.'};
  }
  async function startCamera(facing) {
    const myGeneration=++cameraGeneration;
    starting=true; captured=false; goodSince=0;
    stopCamera();
    captureBtn.disabled=true; captureBtn.style.display='inline-block';
    retakeBtn.style.display='none'; finalBtn.style.display='block';
    updateStage(); say('Requesting camera permission…');
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      starting=false; say('Camera access needs HTTPS (or localhost) and a supported browser.'); return;
    }
    try {
      let newStream;
      try {
        newStream=await navigator.mediaDevices.getUserMedia({audio:false,video:{facingMode:{ideal:facing},width:{ideal:1280},height:{ideal:720}}});
      } catch (firstError) {
        // Some laptops/browsers do not support facingMode; retry with a generic camera.
        newStream=await navigator.mediaDevices.getUserMedia({audio:false,video:true});
      }
      if(myGeneration!==cameraGeneration){newStream.getTracks().forEach(t=>t.stop());return;}
      stream=newStream; video.srcObject=stream;
      await new Promise((resolve,reject)=>{
        if(video.readyState>=HTMLMediaElement.HAVE_CURRENT_DATA && video.videoWidth) return resolve();
        const timer=setTimeout(()=>reject(new Error('Camera opened but no video frame arrived. Check that another app is not using the camera.')),10000);
        video.onloadedmetadata=()=>{video.play().then(()=>{clearTimeout(timer);resolve();}).catch(err=>{clearTimeout(timer);reject(err);});};
        video.onerror=()=>{clearTimeout(timer);reject(new Error('The browser could not read the camera stream.'));};
      });
      await video.play();
      if(myGeneration!==cameraGeneration)return;
      starting=false;
      captureBtn.disabled=false; // Manual capture is available as soon as a real frame is present.
      say(steps[stage].face?'Camera ready. Centre your face and tap Capture photo.':'Camera ready. Fit the complete ID in the guide, then tap Capture photo.',true);
      countdown.textContent=mode==='manual'?'Ready — tap Capture photo':'Hold steady for automatic capture';
      scan(myGeneration);
    } catch(err) {
      starting=false; captureBtn.disabled=true;
      let message=err && err.name==='NotAllowedError'?'Camera permission was denied. Allow camera access in your browser settings, then reload.':
        err && err.name==='NotFoundError'?'No camera was found on this device. Connect a camera or use the mobile link.':
        err && err.name==='NotReadableError'?'The camera is busy in another app. Close other camera apps and try again.':
        (err.message||'Could not start the camera. Check browser permissions and HTTPS.');
      say(message);
    }
  }
  function scan(generation) {
    if(generation!==cameraGeneration || !stream || captured) return;
    const check=basicFrameCheck();
    if(check.ok) {
      captureBtn.disabled=false;
      if(overlay) overlay.style.borderColor='#22c55e';
      if(mode==='auto') {
        if(!goodSince) goodSince=Date.now();
        const seconds=Math.max(0,2.5-(Date.now()-goodSince)/1000);
        countdown.textContent=seconds>0?`Capturing in ${seconds.toFixed(1)}s`:'Capturing…';
        if(seconds<=0){capture();return;}
      } else countdown.textContent='Ready — tap Capture photo';
      say(check.msg,true);
    } else {
      goodSince=0;
      if(overlay) overlay.style.borderColor='#f59e0b';
      // Keep manual capture available unless the video itself has not started.
      captureBtn.disabled=!(video.videoWidth && video.readyState>=HTMLMediaElement.HAVE_CURRENT_DATA);
      say(check.msg);
      countdown.textContent='';
    }
    raf=requestAnimationFrame(()=>scan(generation));
  }
  function capture() {
    if(captured || starting || !stream || !video.videoWidth || video.readyState<HTMLMediaElement.HAVE_CURRENT_DATA) {
      say('Camera is not ready yet. Wait for the live picture.'); return;
    }
    try {
      captured=true;
      canvas.width=video.videoWidth; canvas.height=video.videoHeight;
      ctx.drawImage(video,0,0,canvas.width,canvas.height);
      const data=canvas.toDataURL('image/jpeg',.90);
      if(!data || data.length<16000) throw new Error('The captured image is too small. Move closer and try again.');
      inputs[stage].value=data;
      thumbs[stage].src=data; thumbs[stage].style.display='block';
      lastCapturedStage=stage; captureBtn.disabled=true; retakeBtn.style.display='inline-block';
      retakeBtn.textContent='Retake '+steps[stage].name;
      if(raf) cancelAnimationFrame(raf); raf=0;
      stopCamera();
      if(stage<2) {
        stage++; startCamera(steps[stage].facing);
      } else {
        header.textContent='All three photos captured — review before submitting';
        header.style.background='#047857';
        say('All photos captured. Review the previews, then submit for administrator review.',true);
        captureBtn.style.display='none'; retakeBtn.style.display='inline-block'; retakeBtn.textContent='Retake selfie';
        finalBtn.style.display='block'; countdown.textContent='';
      }
    } catch(err) {
      captured=false; captureBtn.disabled=false; say(err.message||'Could not capture the photo. Try again.');
    }
  }
  captureBtn.addEventListener('click',()=>capture());
  autoBtn.addEventListener('click',()=>setMode('auto'));
  manualBtn.addEventListener('click',()=>setMode('manual'));
  retakeBtn.addEventListener('click',()=>{
    if(lastCapturedStage<0)return;
    stage=lastCapturedStage;
    for(let i=stage;i<3;i++){inputs[i].value='';thumbs[i].removeAttribute('src');thumbs[i].style.display='none';}
    finalBtn.style.display='block'; captureBtn.style.display='inline-block';
    startCamera(steps[stage].facing);
  });
  window.validateBiometricForm=function(event){
    const available=inputs.some(input=>input && input.value) || fileInputs.some(input=>input && input.files && input.files.length>0);
    if(!available) { event.preventDefault(); alert('Please capture or upload at least one image. You can submit the remaining items later.'); return false; }
    stopCamera(); return true;
  };
  setMode('manual');
  startCamera('environment');
  window.addEventListener('pagehide',stopCamera,{once:true});
})();
      </script>
    <?php endif; ?>
   </div>
  <?php endif; ?>
 </div>
</main>

<?php require_once __DIR__ . '/app/includes/footer.php'; ?>
