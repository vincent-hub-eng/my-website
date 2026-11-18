<?php
require_once __DIR__ . '/../app/bootstrap.php';

$error = '';
$step = $_GET['step'] ?? 'request';
$prefillEmail = strtolower(trim($_GET['email'] ?? ''));

// Step 1: Send Password Recovery OTP/Token via Email
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_reset') {
    verify_csrf();
    $email = strtolower(trim($_POST['email'] ?? ''));
    $stmt = $pdo->prepare("SELECT id, full_name, email FROM users WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    $u = $stmt->fetch();

    if ($u) {
        $token = (string)random_int(100000, 999999);
        $expires = date('Y-m-d H:i:s', time() + 1800); // 30 minutes
        $pdo->prepare("UPDATE users SET password_reset_token=?, password_reset_expires=? WHERE id=?")
            ->execute([$token, $expires, $u['id']]);

        send_platform_email(
            $u['email'],
            $u['full_name'],
            'Password Recovery Code — Africa Trade OS',
            "<p>We received a request to reset your password. Enter this 6-digit recovery code within 30 minutes:</p>
             <div style='font-size:28px;font-weight:800;letter-spacing:6px;background:#eff6ff;color:#2563eb;padding:14px 20px;border-radius:10px;text-align:center;border:1px solid #bfdbfe;'>{$token}</div>"
        );
        log_audit($pdo, 'password_reset_requested', 'user', (int)$u['id'], 'Sent recovery code');
    }

    flash('If that email is registered, a 6-digit password recovery code has been sent to your email.');
    header('Location: ' . url('auth/forgot-password.php?step=reset&email=' . urlencode($email)));
    exit;
}

// Step 2: Verify Code & Set New Password
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'complete_reset') {
    verify_csrf();
    $email = strtolower(trim($_POST['email'] ?? ''));
    $code = trim($_POST['reset_code'] ?? '');
    $newPass = $_POST['new_password'] ?? '';

    $stmt = $pdo->prepare("SELECT id, password_reset_token, password_reset_expires FROM users WHERE email=? LIMIT 1");
    $stmt->execute([$email]);
    $u = $stmt->fetch();

    if (!$u || $code === '' || $u['password_reset_token'] !== $code || strtotime($u['password_reset_expires']) < time()) {
        $error = 'Invalid or expired recovery code. Please request a new code.';
        $step = 'reset';
        $prefillEmail = $email;
    } elseif (strlen($newPass) < 8) {
        $error = 'New password must be at least 8 characters.';
        $step = 'reset';
        $prefillEmail = $email;
    } else {
        $pdo->prepare("UPDATE users SET password_hash=?, password_reset_token=NULL, password_reset_expires=NULL WHERE id=?")
            ->execute([password_hash($newPass, PASSWORD_DEFAULT), $u['id']]);
        log_audit($pdo, 'password_reset_completed', 'user', (int)$u['id'], 'Password recovered via email');
        flash('Password reset successful! Sign in with your new password.');
        header('Location: ' . url('auth/login.php'));
        exit;
    }
}

$pageTitle = 'Recover Password';
require_once __DIR__ . '/../app/includes/header.php';
?>

<div class="container">
 <div class="form-card">
  <h2 style="margin-bottom:6px;">Password Recovery</h2>
  <p class="muted small">Recover access to your account using a 6-digit code sent to your registered email.</p>

  <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
  <?php show_flash(); ?>

  <?php if (!empty($_SESSION['last_email_preview']) && $step === 'reset'): ?>
   <div class="alert warning" style="font-size:12.5px;">
    <strong>Email Sent Preview (Local Server Fallback):</strong> <?= e($_SESSION['last_email_preview']['body']) ?>
   </div>
  <?php endif; ?>

  <?php if ($step === 'reset'): ?>
   <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="complete_reset">
    <label>Registered Email Address *</label>
    <input type="email" name="email" value="<?= e($prefillEmail) ?>" required>

    <label>6-Digit Email Recovery Code *</label>
    <input name="reset_code" maxlength="6" required placeholder="e.g. 482910">

    <label>New Password (min. 8 characters) *</label>
    <input type="password" name="new_password" minlength="8" required placeholder="Enter new password">

    <button class="btn btn-block" style="margin-top:18px;" type="submit">Reset Password</button>
   </form>
  <?php else: ?>
   <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="send_reset">
    <label>Enter Your Registered Email Address *</label>
    <input type="email" name="email" required placeholder="you@example.com">
    <button class="btn btn-block" style="margin-top:18px;" type="submit">Send Recovery Code to Email</button>
   </form>
  <?php endif; ?>

  <p class="small muted" style="margin-top:16px;text-align:center;">
   <a href="<?= e(url('auth/login.php')) ?>">← Back to Sign In</a>
  </p>
 </div>
</div>

<?php require_once __DIR__ . '/../app/includes/footer.php'; ?>
