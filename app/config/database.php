<?php

$host = getenv('DB_HOST') ?: 'localhost';
$dbname = getenv('DB_NAME') ?: 'africa_trade_os';
$appEnv = strtolower(getenv('APP_ENV') ?: 'development');
$username = getenv('DB_USER') ?: ($appEnv === 'production' ? '' : 'root');
$password = getenv('DB_PASS') ?: '';
if ($appEnv === 'production' && ($username === '' || $password === '')) { http_response_code(500); exit('Production database credentials are not configured.'); }

try {
    $pdo = new PDO(
        "mysql:host={$host};dbname={$dbname};charset=utf8mb4",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (PDOException $e) {
    error_log('Africa Trade OS DB Error: ' . $e->getMessage());
    http_response_code(500);
    die('<div style="font-family:Inter,sans-serif;max-width:540px;margin:80px auto;padding:32px;background:#0f172a;color:#f8fafc;border-radius:16px;">
        <h2 style="margin-top:0;color:#f59e0b;">Database Connection Needed</h2>
        <p style="color:#cbd5e1;">Import <code>database/africa_trade_os.sql</code> into MySQL and verify <code>app/config/database.php</code>.</p>
    </div>');
}
