<?php
require_once __DIR__ . '/app/bootstrap.php';
require_login();
$uid = current_user_id();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_profile') {
    verify_csrf();
    $fullName = trim($_POST['full_name'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $phone = trim($_POST['phone'] ?? '');
    $bizName = trim($_POST['business_name'] ?? '');
    $city = trim($_POST['city'] ?? '');

    if ($fullName === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Enter a valid name and email address.';
    } else {
        $pdo->prepare("UPDATE users SET full_name=?, email=?, phone=? WHERE id=?")->execute([$fullName, $email, $phone, $uid]);
        $_SESSION['full_name'] = $fullName;
        if ($bizName !== '') {
            $chk = $pdo->prepare("SELECT id FROM business_profiles WHERE user_id=?");
            $chk->execute([$uid]);
            if ($chk->fetch()) {
                $pdo->prepare("UPDATE business_profiles SET business_name=?, city=? WHERE user_id=?")->execute([$bizName, $city, $uid]);
            } else {
                $pdo->prepare("INSERT INTO business_profiles (user_id, business_name, city) VALUES (?, ?, ?)")->execute([$uid, $bizName, $city]);
            }
        }
        flash('Profile updated!');
        header('Location: ' . url('profile.php'));
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change_password') {
    verify_csrf();
    $newPass = $_POST['new_password'] ?? '';
    if (strlen($newPass) < 8) {
        $error = 'Password must be at least 8 characters.';
    } else {
        $pdo->prepare("UPDATE users SET password_hash=? WHERE id=?")->execute([password_hash($newPass, PASSWORD_DEFAULT), $uid]);
        flash('Password changed!');
        header('Location: ' . url('profile.php'));
        exit;
    }
}

$u = current_user($pdo);
$pageTitle = 'Account Profile';
require_once __DIR__ . '/app/includes/header.php';
?>
<main class="container section">
 <h1>Account Profile & Security</h1>
 <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
 <?php show_flash(); ?>
 <div class="grid-2">
  <form method="post" class="panel">
   <h3>Personal & Store Details</h3>
   <?= csrf_field() ?><input type="hidden" name="action" value="update_profile">
   <label>Full Name *</label><input name="full_name" required value="<?= e($u['full_name']) ?>">
   <label>Email *</label><input type="email" name="email" required value="<?= e($u['email']) ?>">
   <label>Phone (M-Pesa / Mobile)</label><input name="phone" value="<?= e($u['phone']) ?>">
   <label>Store / Business Name</label><input name="business_name" value="<?= e($u['business_name'] ?? '') ?>">
   <label>City</label><input name="city" value="<?= e($u['city'] ?? '') ?>">
   <button class="btn" style="margin-top:16px;" type="submit">Save Profile</button>
  </form>
  <form method="post" class="panel">
   <h3>Change Password</h3>
   <?= csrf_field() ?><input type="hidden" name="action" value="change_password">
   <label>New Password (min. 8 chars)</label><input type="password" name="new_password" minlength="8" required>
   <button class="btn dark" style="margin-top:16px;" type="submit">Update Password</button>
  </form>
 </div>
</main>
<?php require_once __DIR__ . '/app/includes/footer.php'; ?>
