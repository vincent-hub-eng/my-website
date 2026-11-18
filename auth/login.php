<?php
require_once __DIR__ . '/../app/bootstrap.php';

if (logged_in()) {
    header('Location: ' . url('dashboard.php'));
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';

    $ipHash = hash('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown') . (getenv('ATO_RATE_LIMIT_SALT') ?: 'change-this-deployment-salt'));
    $lim = $pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE email=? AND ip_hash=? AND attempted_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)');
    $lim->execute([$email, $ipHash]);
    $blocked = (int)$lim->fetchColumn() >= 8;
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$blocked && $user && password_verify($password, $user['password_hash'])) {
        $pdo->prepare('DELETE FROM login_attempts WHERE email=? AND ip_hash=?')->execute([$email, $ipHash]);
        if ($user['account_status'] === 'suspended' || $user['account_status'] === 'rejected') {
            $error = 'Your account is ' . $user['account_status'] . '. Contact Platform Administration.';
        } else {
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int)$user['id'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['role'] = $user['role'];

            log_audit($pdo, 'user_login', 'user', (int)$user['id'], 'Signed in');
            flash('Welcome back, ' . $user['full_name'] . '!');
            header('Location: ' . url('dashboard.php'));
            exit;
        }
    } else {
        $pdo->prepare('INSERT INTO login_attempts (email, ip_hash) VALUES (?, ?)')->execute([$email, $ipHash]);
        $error = $blocked ? 'Too many sign-in attempts. Please wait 15 minutes and try again.' : 'Invalid email or password.';
    }
}

$pageTitle = 'Sign In';
require_once __DIR__ . '/../app/includes/header.php';
?>

<div class="container">
 <div class="form-card">
  <h2 style="margin-bottom:6px;">Sign In to Africa Trade OS</h2>
  <p class="muted small">Access your Buyer, Seller, Supplier, Logistics, or Admin dashboard.</p>

  <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
  <?php show_flash(); ?>

  <form method="post">
   <?= csrf_field() ?>
   <label>Email Address</label>
   <input type="email" name="email" required placeholder="you@example.com">

   <div style="display:flex;justify-content:space-between;align-items:center;margin-top:12px;">
    <label style="margin:0;">Password</label>
    <a class="small" href="<?= e(url('auth/forgot-password.php')) ?>">Forgot password?</a>
   </div>
   <input type="password" name="password" required placeholder="••••••••" style="margin-top:5px;">

   <button class="btn btn-block" style="margin-top:20px;" type="submit">Sign In</button>
  </form>

  <p class="small muted" style="margin-top:18px;text-align:center;">
   Don't have an account? <a href="<?= e(url('auth/register.php')) ?>"><strong>Create Account</strong></a>
  </p>
 </div>
</div>

<?php require_once __DIR__ . '/../app/includes/footer.php'; ?>
