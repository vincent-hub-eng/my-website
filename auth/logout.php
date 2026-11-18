<?php
require_once __DIR__ . '/../app/bootstrap.php';
if (logged_in()) {
    log_audit($pdo, 'user_logout', 'user', current_user_id(), 'Logged out');
}
$_SESSION = [];
session_destroy();
header('Location: ' . url('index.php'));
exit;
