<?php
require_once __DIR__ . '/app/bootstrap.php';
require_login();
$uid = current_user_id();
$pdo->prepare("UPDATE messages SET read_at=NOW() WHERE receiver_id=? AND read_at IS NULL")->execute([$uid]);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $to = (int)($_POST['receiver_id'] ?? 0);
    $msg = trim($_POST['message'] ?? '');
    if ($to > 0 && $to !== $uid && $msg !== '') {
        $pdo->prepare("INSERT INTO messages (sender_id, receiver_id, message) VALUES (?, ?, ?)")->execute([$uid, $to, $msg]);
        flash('Message sent.');
    }
    header('Location: ' . url('messages.php'));
    exit;
}

$contacts = $pdo->prepare("SELECT id, full_name, role FROM users WHERE id<>? AND account_status='active' ORDER BY full_name");
$contacts->execute([$uid]);
$users = $contacts->fetchAll();

$msgs = $pdo->prepare("SELECT m.*, a.full_name AS sender, b.full_name AS receiver FROM messages m JOIN users a ON a.id=m.sender_id JOIN users b ON b.id=m.receiver_id WHERE m.sender_id=? OR m.receiver_id=? ORDER BY m.created_at DESC LIMIT 60");
$msgs->execute([$uid, $uid]);
$thread = $msgs->fetchAll();

$pageTitle = 'Messages';
require_once __DIR__ . '/app/includes/header.php';
?>
<main class="container section">
 <h1>Direct Trade Messages</h1>
 <?php show_flash(); ?>
 <div class="grid-2">
  <form method="post" class="panel">
   <?= csrf_field() ?>
   <label>Recipient</label>
   <select name="receiver_id" required><option value="">Select user...</option><?php foreach ($users as $u): ?><option value="<?= (int)$u['id'] ?>"><?= e($u['full_name']) ?> (<?= e(ucfirst($u['role'])) ?>)</option><?php endforeach; ?></select>
   <label>Message</label><textarea name="message" required></textarea>
   <button class="btn" style="margin-top:14px;" type="submit">Send Message</button>
  </form>
  <div>
   <?php foreach ($thread as $m): ?>
    <div class="card" style="margin-bottom:10px;">
     <strong><?= e($m['sender']) ?></strong> → <strong><?= e($m['receiver']) ?></strong>
     <p class="small" style="margin:6px 0;"><?= nl2br(e($m['message'])) ?></p>
     <span class="muted small"><?= e($m['created_at']) ?></span>
    </div>
   <?php endforeach; ?>
  </div>
 </div>
</main>
<?php require_once __DIR__ . '/app/includes/footer.php'; ?>
