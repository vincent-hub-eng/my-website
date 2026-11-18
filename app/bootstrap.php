<?php

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Strict');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/mailer.php';
require_once __DIR__ . '/includes/fulfillment.php';

// Auto-ensure schema columns & tables exist on existing MySQL databases

// Auto-add mobile_kyc_token if missing
try {
    $cols = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('mobile_kyc_token', $cols)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN mobile_kyc_token VARCHAR(64) DEFAULT NULL");
    }
} catch (Exception $e) {}

try {
    $cols = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('kyc_auto_score', $cols, true)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN kyc_auto_score INT DEFAULT 0 AFTER id_verification_status");
    }
    if (!in_array('kyc_auto_notes', $cols, true)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN kyc_auto_notes VARCHAR(255) NULL AFTER kyc_auto_score");
    }
    if (!in_array('seller_tier', $cols, true)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN seller_tier ENUM('Standard','Verified Merchant','Gold Power Seller','AfCFTA Export Champion') DEFAULT 'Standard'");
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS wishlists (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT NOT NULL,
        product_id BIGINT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_wish (user_id, product_id)
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS product_reviews (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        product_id BIGINT NOT NULL,
        user_id BIGINT NOT NULL,
        rating TINYINT NOT NULL DEFAULT 5,
        comment TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
} catch (Throwable $e) {
    // Ignore if table does not exist prior to initial SQL import
}

$GLOBALS['ATO_CONFIG'] = require __DIR__ . '/config/integrations.php';

$scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
$baseUrl = preg_replace('#/(admin|api|auth|buyer|logistics|seller|supplier)$#', '', $scriptDir);
if ($baseUrl === '/' || $baseUrl === '.') {
    $baseUrl = '';
}
define('BASE_URL', rtrim($baseUrl, '/'));

function url(string $path = ''): string {
    $path = ltrim($path, '/');
    return BASE_URL . ($path !== '' ? '/' . $path : '/');
}

function absolute_url(string $path = ''): string {
    $configured = rtrim((string)(getenv('APP_URL') ?: ''), '/');
    if ($configured !== '' && filter_var($configured, FILTER_VALIDATE_URL)) return $configured . ($path !== '' ? '/' . ltrim($path, '/') : '');
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = preg_replace('/[^A-Za-z0-9.:-]/', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
    return $scheme . '://' . $host . url($path);
}

function e(mixed $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): void {
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(419);
        exit('Security token expired. Please refresh and try again.');
    }
}

function logged_in(): bool {
    return isset($_SESSION['user_id']);
}

function current_user_id(): int {
    return (int)($_SESSION['user_id'] ?? 0);
}

function current_role(): string {
    return (string)($_SESSION['role'] ?? '');
}

function current_user(PDO $pdo): ?array {
    if (!logged_in()) return null;
    static $cached = null;
    if ($cached !== null) return $cached;
    $stmt = $pdo->prepare(
        "SELECT u.*, bp.business_name, bp.city, bp.tagline, c.name AS country_name, c.currency_code
         FROM users u
         LEFT JOIN business_profiles bp ON bp.user_id = u.id
         LEFT JOIN countries c ON c.id = u.country_id
         WHERE u.id = ?"
    );
    $stmt->execute([current_user_id()]);
    $cached = $stmt->fetch() ?: null;
    return $cached;
}

// Trading permissions are role-exclusive: staff, sellers and delivery partners cannot buy.
function user_can_buy(PDO $pdo): bool {
    $u = current_user($pdo);
    return $u !== null && $u['role'] === 'buyer' && $u['account_status'] === 'active' && !empty($u['can_buy']) && !empty($u['email_verified_at']);
}

function user_can_sell(PDO $pdo): bool {
    $u = current_user($pdo);
    return $u !== null && in_array($u['role'], ['seller', 'supplier'], true)
        && $u['account_status'] === 'active' && !empty($u['can_sell']);
}

function is_fully_verified_partner(PDO $pdo): bool {
    $u = current_user($pdo);
    if (!$u) return false;
    if ($u['role'] === 'buyer' || $u['role'] === 'admin') return true;
    return !empty($u['email_verified_at']) && $u['id_verification_status'] === 'verified';
}

function require_login(): void {
    if (!logged_in()) {
        flash('Please sign in to continue.', 'warning');
        header('Location: ' . url('auth/login.php'));
        exit;
    }
}

function require_role(array $roles): void {
    require_login();
    if (!in_array(current_role(), $roles, true)) {
        http_response_code(403);
        exit('Access Denied: Your role (' . e(current_role()) . ') does not have access to this page.');
    }
}

function require_verified_partner(PDO $pdo): void {
    require_login();
    if (!is_fully_verified_partner($pdo)) {
        flash('Verification Required: Please complete your Email OTP and Front/Back ID + Selfie verification first.', 'warning');
        header('Location: ' . url('verify-account.php'));
        exit;
    }
}

function flash(string $message, string $type = 'success'): void {
    $_SESSION['flash'] = ['message' => $message, 'type' => $type];
}

function show_flash(): void {
    if (!empty($_SESSION['flash'])) {
        $f = $_SESSION['flash'];
        $icon = $f['type'] === 'error' ? '!' : ($f['type'] === 'warning' ? '⚠' : '✓');
        echo '<div class="alert ' . e($f['type']) . '" role="alert">
                <span class="alert-icon">' . $icon . '</span>
                <div>' . e($f['message']) . '</div>
              </div>';
        unset($_SESSION['flash']);
    }
}

require_once __DIR__ . '/includes/mailer.php';

// Real Email Dispatch Helper (Uses authenticated SMTP SSL/TLS + PHP mail fallback)
function send_platform_email(string $toEmail, string $toName, string $subject, string $htmlBody): bool {
    $res = dispatch_real_email($toEmail, $toName, $subject, $htmlBody);
    return !empty($res['sent']);
}

// Normalize Kenyan phone number to 2547XXXXXXXX for Safaricom M-Pesa STK Push
function normalize_mpesa_phone(string $phone): string {
    $digits = preg_replace('/\D+/', '', $phone);
    if (str_starts_with($digits, '0') && strlen($digits) === 10) {
        return '254' . substr($digits, 1);
    }
    if (str_starts_with($digits, '7') || str_starts_with($digits, '1')) {
        return '254' . $digits;
    }
    return $digits;
}

// Safaricom M-Pesa Daraja STK Push (Lipa Na M-Pesa Online)
function trigger_mpesa_stk_push(string $phone, float $amount, string $accountRef, string $desc): array {
    $cfg = $GLOBALS['ATO_CONFIG']['mpesa'];
    $msisdn = normalize_mpesa_phone($phone);
    if (!preg_match('/^254[17]\d{8}$/', $msisdn)) {
        return ['ok' => false, 'message' => 'Enter a valid Safaricom M-Pesa number (e.g., 0712345678 or 254712345678).'];
    }

    $intAmount = max(1, (int)ceil($amount));
    $baseUrl = ($cfg['env'] === 'live') ? 'https://api.safaricom.co.ke' : 'https://sandbox.safaricom.co.ke';

    // If live/sandbox Daraja credentials are configured, execute real cURL STK Push
    if (!empty($cfg['consumer_key']) && !empty($cfg['consumer_secret']) && function_exists('curl_init')) {
        $ch = curl_init($baseUrl . '/oauth/v1/generate?grant_type=client_credentials');
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => ['Authorization: Basic ' . base64_encode($cfg['consumer_key'] . ':' . $cfg['consumer_secret'])],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
        ]);
        $tokenRes = json_decode((string)curl_exec($ch), true);
        curl_close($ch);

        $accessToken = $tokenRes['access_token'] ?? '';
        if ($accessToken === '') {
            return ['ok' => false, 'message' => 'M-Pesa Daraja OAuth failed. Check consumer_key & consumer_secret in app/config/integrations.php.'];
        }

        $timestamp = date('YmdHis');
        $password = base64_encode($cfg['shortcode'] . $cfg['passkey'] . $timestamp);
        $callbackUrl = $cfg['callback_url'] ?: absolute_url('api/mpesa-callback.php');

        $payload = [
            'BusinessShortCode' => $cfg['shortcode'],
            'Password'          => $password,
            'Timestamp'         => $timestamp,
            'TransactionType'   => 'CustomerPayBillOnline',
            'Amount'            => $intAmount,
            'PartyA'            => $msisdn,
            'PartyB'            => $cfg['shortcode'],
            'PhoneNumber'       => $msisdn,
            'CallBackURL'       => $callbackUrl,
            'AccountReference'  => substr($accountRef, 0, 12),
            'TransactionDesc'   => substr($desc, 0, 13),
        ];

        $stkCh = curl_init($baseUrl . '/mpesa/stkpush/v1/processrequest');
        curl_setopt_array($stkCh, [
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json',
            ],
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 25,
        ]);
        $stkRes = json_decode((string)curl_exec($stkCh), true);
        curl_close($stkCh);

        if (($stkRes['ResponseCode'] ?? '') === '0') {
            return [
                'ok' => true,
                'mode' => 'daraja_live',
                'checkout_request_id' => $stkRes['CheckoutRequestID'],
                'customer_message' => $stkRes['CustomerMessage'] ?? 'STK Push sent to your phone. Enter your M-Pesa PIN.',
                'msisdn' => $msisdn,
            ];
        }
        return ['ok' => false, 'message' => $stkRes['errorMessage'] ?? 'M-Pesa STK Push request declined.'];
    }

    // A simulator is available only when explicitly enabled for isolated local testing.
    if (empty($cfg['allow_simulation'])) {
        return ['ok' => false, 'message' => 'M-Pesa Daraja is not configured on this deployment. Please contact platform support.'];
    }
    return [
        'ok' => true,
        'mode' => 'sandbox_simulator',
        'checkout_request_id' => 'ws_CO_' . date('YmdHis') . '_' . random_int(10000, 99999),
        'customer_message' => "LOCAL TEST ONLY: simulated STK Push for +{$msisdn}, KES " . number_format($intAmount) . '. No real money was collected.',
        'msisdn' => $msisdn,
    ];
}

function log_audit(PDO $pdo, string $action, ?string $entityType = null, ?int $entityId = null, ?string $details = null): void {
    try {
        $stmt = $pdo->prepare(
            "INSERT INTO audit_logs (user_id, action, entity_type, entity_id, details, ip_address)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            logged_in() ? current_user_id() : null,
            $action, $entityType, $entityId, $details,
            $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
        ]);
    } catch (Throwable $e) {}
}

function notify_user(PDO $pdo, int $userId, string $title, string $message, string $category = 'trade', ?string $link = null): void {
    try {
        $stmt = $pdo->prepare(
            "INSERT INTO notifications (user_id, category, title, message, link) VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->execute([$userId, $category, $title, $message, $link]);
    } catch (Throwable $e) {}
}

function unread_messages_count(PDO $pdo): int {
    if (!logged_in()) return 0;
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE receiver_id=? AND read_at IS NULL");
        $stmt->execute([current_user_id()]);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function ato_encrypt_sensitive(string $plain): string|false {
    $secret=(string)(getenv('ATO_KYC_ENCRYPTION_KEY') ?: '');
    if (strlen($secret)<32 || !function_exists('openssl_encrypt')) return false;
    $key=hash('sha256',$secret,true); $iv=random_bytes(12); $tag='';
    $cipher=openssl_encrypt($plain,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag,'AfricaTradeOS-KYC',16);
    if ($cipher===false) return false;
    return 'enc:v1:'.base64_encode($iv.$tag.$cipher);
}

function ato_decrypt_sensitive(?string $stored): string {
    if ($stored===null || $stored==='') return '';
    if (!str_starts_with($stored,'enc:v1:')) return $stored; // legacy value; migrate with the CLI utility
    $secret=(string)(getenv('ATO_KYC_ENCRYPTION_KEY') ?: '');
    if (strlen($secret)<32 || !function_exists('openssl_decrypt')) return '[encryption key not configured]';
    $raw=base64_decode(substr($stored,7),true);
    if ($raw===false || strlen($raw)<29) return '[unreadable encrypted value]';
    $key=hash('sha256',$secret,true); $iv=substr($raw,0,12); $tag=substr($raw,12,16); $cipher=substr($raw,28);
    $plain=openssl_decrypt($cipher,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag,'AfricaTradeOS-KYC');
    return $plain===false ? '[decryption failed]' : $plain;
}

function store_product_image(string $tmpPath, string $originalName, int $userId): ?string {
    if (!is_uploaded_file($tmpPath) || !is_file($tmpPath)) return null;
    if (filesize($tmpPath) > 5 * 1024 * 1024) return null;
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowed = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($tmpPath);
    $image = @getimagesize($tmpPath);
    if (!isset($allowed[$ext]) || $mime !== $allowed[$ext] || !$image || $image[0] < 200 || $image[1] < 200) return null;
    $dir = __DIR__ . '/../uploads/products/';
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) return null;
    $name = 'prod_' . $userId . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    return move_uploaded_file($tmpPath, $dir . $name) ? 'uploads/products/' . $name : null;
}

function status_badge(?string $status): string {
    $status = $status ?? 'pending';
    $clean = strtolower(trim($status));
    $map = [
        'active' => 'badge-success',
        'verified' => 'badge-success',
        'completed' => 'badge-success',
        'delivered' => 'badge-success',
        'successful' => 'badge-success',
        'funded_held' => 'badge-info',
        'held_in_escrow' => 'badge-info',
        'released' => 'badge-success',
        'accepted' => 'badge-success',
        'awarded' => 'badge-success',
        'open' => 'badge-info',
        'submitted' => 'badge-info',
        'confirmed' => 'badge-info',
        'processing' => 'badge-warning',
        'in_transit' => 'badge-info',
        'shipped' => 'badge-info',
        'pending' => 'badge-warning',
        'unsubmitted' => 'badge-warning',
        'under_review' => 'badge-warning',
        'unfunded' => 'badge-warning',
        'disputed' => 'badge-danger',
        'suspended' => 'badge-danger',
        'rejected' => 'badge-danger',
        'cancelled' => 'badge-danger',
    ];
    $cls = $map[$clean] ?? 'badge-neutral';
    $label = ucwords(str_replace('_', ' ', $clean));
    return '<span class="badge ' . $cls . '">' . e($label) . '</span>';
}

function format_money(float|string $amount, string $currency = 'KES'): string {
    $sym = match (strtoupper($currency)) {
        'USD' => '$',
        'KES' => 'KES ',
        'NGN' => '₦',
        'ZAR' => 'R',
        'GHS' => '₵',
        'EGP' => 'E£',
        default => strtoupper($currency) . ' ',
    };
    return $sym . number_format((float)$amount, 2);
}

function fx_rates(): array {
    return [
        'KES' => 129.50, 'USD' => 1.00, 'NGN' => 1580.00, 'ZAR' => 18.20,
        'GHS' => 15.60, 'TZS' => 2680.00, 'UGX' => 3710.00, 'RWF' => 1365.00,
        'ETB' => 122.40, 'EGP' => 48.90, 'MAD' => 9.85,
    ];
}

function product_image_url(?string $path): string {
    $clean = ltrim(str_replace('\\', '/', (string)$path), '/');
    if ($clean !== '' && !str_contains($clean, '..') && file_exists(__DIR__ . '/../' . $clean)) {
        return url($clean);
    }
    return url('uploads/products/product-placeholder.svg');
}

function record_order_event(PDO $pdo, int $orderId, string $event, ?int $actorId, ?string $note = null): void {
    try {
        $st = $pdo->prepare('INSERT INTO order_events (order_id, actor_id, event_type, note) VALUES (?, ?, ?, ?)');
        $st->execute([$orderId, $actorId, $event, $note]);
    } catch (Throwable $e) { error_log('Order event logging failed: ' . $e->getMessage()); }
}

function queue_platform_email(PDO $pdo, string $toEmail, string $toName, string $subject, string $htmlBody): bool {
    $id = null;
    try {
        $st = $pdo->prepare("INSERT INTO email_outbox (recipient_email, recipient_name, subject, html_body, status, attempts, created_at) VALUES (?, ?, ?, ?, 'pending', 0, NOW())");
        $st->execute([$toEmail, $toName, $subject, $htmlBody]);
        $id = (int)$pdo->lastInsertId();
    } catch (Throwable $e) { error_log('Email outbox insert failed: ' . $e->getMessage()); }
    $sent = send_platform_email($toEmail, $toName, $subject, $htmlBody);
    if ($id) {
        try {
            $pdo->prepare("UPDATE email_outbox SET status=?, attempts=attempts+1, sent_at=IF(?=1,NOW(),NULL), last_error=? WHERE id=?")
                ->execute([$sent ? 'sent' : 'failed', $sent ? 1 : 0, $sent ? null : 'SMTP delivery failed; retry with scripts/process-email-queue.php', $id]);
        } catch (Throwable $e) { error_log('Email outbox update failed: ' . $e->getMessage()); }
    }
    return $sent;
}

function email_order_user(PDO $pdo, int $orderId, int $userId, string $subject, string $message): bool {
    $st = $pdo->prepare('SELECT u.email, u.full_name, o.order_reference, o.title, o.total_amount, o.currency FROM orders o JOIN users u ON u.id=? WHERE o.id=?');
    $st->execute([$userId, $orderId]);
    $row = $st->fetch();
    if (!$row || !filter_var($row['email'], FILTER_VALIDATE_EMAIL)) return false;
    $body = '<h2>' . e($subject) . '</h2><p>' . nl2br(e($message)) . '</p><p><strong>Order:</strong> ' . e($row['order_reference']) . '</p><p><strong>Item:</strong> ' . e($row['title'] ?? 'Trade order') . '</p><p><strong>Total:</strong> ' . e(format_money($row['total_amount'], $row['currency'])) . '</p><p>Sign in to Africa Trade OS to view the latest order status.</p>';
    return queue_platform_email($pdo, $row['email'], $row['full_name'], $subject . ' — ' . $row['order_reference'], $body);
}

function email_user_notice(PDO $pdo, int $userId, string $subject, string $message): bool {
    $st=$pdo->prepare('SELECT email,full_name FROM users WHERE id=?'); $st->execute([$userId]); $u=$st->fetch();
    if (!$u || !filter_var($u['email'],FILTER_VALIDATE_EMAIL)) return false;
    return queue_platform_email($pdo,$u['email'],$u['full_name'],$subject,'<h2>'.e($subject).'</h2><p>'.nl2br(e($message)).'</p><p>Sign in to Africa Trade OS to review your account.</p>');
}

function notify_admins(PDO $pdo, string $title, string $message, string $type='system', string $link='admin/index.php'): void {
    $admins=$pdo->query("SELECT id FROM users WHERE role='admin' AND account_status='active'")->fetchAll(PDO::FETCH_COLUMN);
    foreach($admins as $adminId) notify_user($pdo,(int)$adminId,$title,$message,$type,$link);
}

function email_admins(PDO $pdo, int $orderId, string $subject, string $message): void {
    $admins = $pdo->query("SELECT id FROM users WHERE role='admin' AND account_status='active'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($admins as $adminId) email_order_user($pdo, $orderId, (int)$adminId, $subject, $message);
}

function email_logistics_partners(PDO $pdo, int $orderId, string $subject, string $message): void {
    $partners = $pdo->query("SELECT id FROM users WHERE role='logistics' AND account_status='active' AND id_verification_status='verified' AND email_verified_at IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($partners as $partnerId) email_order_user($pdo, $orderId, (int)$partnerId, $subject, $message);
}
