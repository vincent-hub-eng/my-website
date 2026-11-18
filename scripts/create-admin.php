<?php
// Run only from CLI after importing the schema: php scripts/create-admin.php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../app/config/database.php';
$prompt = static function(string $label): string { fwrite(STDOUT, $label); return trim((string)fgets(STDIN)); };
$name = getenv('ATO_ADMIN_NAME') ?: $prompt('Administrator full name: ');
$email = strtolower(getenv('ATO_ADMIN_EMAIL') ?: $prompt('Administrator email: '));
$password = getenv('ATO_ADMIN_PASSWORD') ?: $prompt('Strong password (minimum 14 characters; input is visible): ');
if ($name==='' || !filter_var($email,FILTER_VALIDATE_EMAIL) || strlen($password)<14) { fwrite(STDERR,"Name, valid email and a password of at least 14 characters are required.\n"); exit(1); }
$check=$pdo->prepare('SELECT id FROM users WHERE email=?'); $check->execute([$email]);
if ($check->fetch()) { fwrite(STDERR,"That email already exists. Use the account recovery process instead.\n"); exit(1); }
$hash=password_hash($password,PASSWORD_DEFAULT);
$st=$pdo->prepare("INSERT INTO users (full_name,email,password_hash,role,can_buy,can_sell,email_verified_at,id_verification_status,account_status,terms_accepted_at) VALUES (?,?,?,'admin',0,0,NOW(),'verified','active',NOW())");
$st->execute([$name,$email,$hash]);
fwrite(STDOUT,"Administrator created. Store the password in a password manager and remove it from your shell history/environment.\n");
