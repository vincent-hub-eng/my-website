<?php
require_once __DIR__ . '/../app/bootstrap.php';

if (logged_in()) {
    header('Location: ' . url('dashboard.php'));
    exit;
}

$error = '';
$defaultRole = $_GET['role'] ?? 'buyer';
if (!in_array($defaultRole, ['buyer', 'seller', 'supplier', 'logistics'], true)) {
    $defaultRole = 'buyer';
}
$countries = $pdo->query("SELECT id, name, currency_code FROM countries WHERE is_active=1 ORDER BY name")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $name = trim($_POST['full_name'] ?? '');
    $shopName = trim($_POST['business_name'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $phone = trim($_POST['phone'] ?? '');
    $countryId = (int)($_POST['country_id'] ?? 1);
    $password = $_POST['password'] ?? '';
    $role = $_POST['role'] ?? 'buyer';
    $acceptTerms = isset($_POST['accept_terms']);

    $allowed = ['buyer', 'seller', 'supplier', 'logistics'];

    if (!$acceptTerms) {
        $error = 'You must read and accept the Terms & Conditions to create an account.';
    } elseif ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Enter your full legal name and a valid email address.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters long.';
    } elseif (!in_array($role, $allowed, true)) {
        $error = 'Select a valid account role.';
    } else {
        try {
            $pdo->beginTransaction();

            $isBuyer = ($role === 'buyer');
            $otpCode = (string)random_int(100000, 999999);
            $idStatus = $isBuyer ? 'not_required' : 'unsubmitted';
            $canSell = in_array($role, ['seller', 'supplier'], true) ? 1 : 0;

            $stmt = $pdo->prepare(
                "INSERT INTO users
                (full_name, email, phone, password_hash, role, can_buy, can_sell, country_id,
                 email_verified_at, email_verification_code, email_verification_expires_at, id_verification_status, terms_accepted_at, account_status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, DATE_ADD(NOW(), INTERVAL 15 MINUTE), ?, NOW(), 'active')"
            );
            $stmt->execute([
                $name, $email, $phone,
                password_hash($password, PASSWORD_DEFAULT),
                $role, $isBuyer ? 1 : 0, $canSell, $countryId ?: null,
                password_hash($otpCode, PASSWORD_DEFAULT), $idStatus
            ]);
            $userId = (int)$pdo->lastInsertId();

            if ($shopName !== '') {
                $pdo->prepare("INSERT INTO business_profiles (user_id, business_name, country_id) VALUES (?, ?, ?)")
                    ->execute([$userId, $shopName, $countryId ?: null]);
            }

            // Send OTP Code to User's Email
            send_platform_email(
                $email,
                $name,
                'Your Africa Trade OS Email Verification OTP Code',
                "<p>Thank you for registering on <strong>Africa Trade OS</strong>. Use the 6-digit OTP below to verify your email address:</p>
                 <div style='font-size:28px;font-weight:800;letter-spacing:6px;background:#fff7ed;color:#ea580c;padding:14px 20px;border-radius:10px;text-align:center;border:1px solid #fed7aa;'>{$otpCode}</div>
                 <p>If you registered as a Seller, Supplier, or Logistics Partner, please also upload your Front ID, Back ID, and Selfie for Admin approval.</p>"
            );

            log_audit($pdo, 'user_registered', 'user', $userId, "Registered {$name} as {$role}");
            $pdo->commit();

            session_regenerate_id(true);
            $_SESSION['user_id'] = $userId;
            $_SESSION['full_name'] = $name;
            $_SESSION['role'] = $role;

            flash("Account created! We sent a 6-digit OTP verification code to {$email}.");
            header('Location: ' . url('verify-account.php'));
            exit;
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = $e->getCode() === '23000'
                ? 'That email address is already registered.'
                : 'Registration failed. Please try again.';
        }
    }
}

$pageTitle = 'Create Account';
require_once __DIR__ . '/../app/includes/header.php';
?>

<div class="container">
 <div class="form-card" style="max-width:580px;">
  <h2 style="margin-bottom:6px;">Create Your Africa Trade OS Account</h2>
  <p class="muted small">An OTP verification code will be sent to your email immediately after registration.</p>

  <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>

  <form method="post">
   <?= csrf_field() ?>
   <label>Account Type *</label>
   <select name="role" required>
    <option value="buyer" <?= $defaultRole === 'buyer' ? 'selected' : '' ?>>Buyer (Shop Retail & Bulk Wholesale across Africa)</option>
    <option value="seller" <?= $defaultRole === 'seller' ? 'selected' : '' ?>>Seller (Upload Products & Sell — Requires OTP + Front/Back ID & Selfie)</option>
    <option value="supplier" <?= $defaultRole === 'supplier' ? 'selected' : '' ?>>Wholesale Supplier (Bulk Supply — Requires OTP + Front/Back ID & Selfie)</option>
    <option value="logistics" <?= $defaultRole === 'logistics' ? 'selected' : '' ?>>Logistics & Delivery Partner (Requires OTP + Front/Back ID & Selfie)</option>
   </select>

   <div class="form-row">
    <div>
     <label>Your Full Legal Name *</label>
     <input name="full_name" required maxlength="150" placeholder="As shown on your ID">
    </div>
    <div>
     <label>Store / Business Name (Optional)</label>
     <input name="business_name" maxlength="190" placeholder="e.g. Nairobi Tech Hub">
    </div>
   </div>

   <div class="form-row">
    <div>
     <label>Email Address (Receives OTP) *</label>
     <input type="email" name="email" required placeholder="you@example.com">
    </div>
    <div>
     <label>Phone Number (M-Pesa / Mobile) *</label>
     <input name="phone" required maxlength="30" placeholder="+254 712 345 678">
    </div>
   </div>

   <div class="form-row">
    <div>
     <label>Country *</label>
     <select name="country_id" required>
      <?php foreach ($countries as $c): ?>
       <option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?> (<?= e($c['currency_code']) ?>)</option>
      <?php endforeach; ?>
     </select>
    </div>
    <div>
     <label>Password (min. 8 chars) *</label>
     <input type="password" name="password" minlength="8" required placeholder="Create strong password">
    </div>
   </div>

   <label class="chip-check" style="margin-top:16px;border-radius:8px;padding:10px 12px;width:100%;">
    <input type="checkbox" name="accept_terms" required>
    <span class="small">I have read and agree to the <a href="<?= e(url('terms.php')) ?>" target="_blank"><strong>Terms & Conditions</strong></a> and Payment & Delivery Rules.</span>
   </label>

   <button class="btn btn-block" style="margin-top:18px;" type="submit">Register & Send Email OTP</button>
  </form>

  <p class="small muted" style="margin-top:16px;text-align:center;">
   Already registered? <a href="<?= e(url('auth/login.php')) ?>"><strong>Sign in</strong></a>
  </p>
 </div>
</div>

<?php require_once __DIR__ . '/../app/includes/footer.php'; ?>
