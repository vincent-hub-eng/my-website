<?php
// Run once after setting ATO_KYC_ENCRYPTION_KEY: php scripts/encrypt-legacy-kyc.php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../app/bootstrap.php';
if (strlen((string)(getenv('ATO_KYC_ENCRYPTION_KEY') ?: '')) < 32) { fwrite(STDERR,"Set a strong ATO_KYC_ENCRYPTION_KEY first.\n"); exit(1); }
$rows=$pdo->query("SELECT id,id_number FROM users WHERE id_number IS NOT NULL AND id_number<>''")->fetchAll(); $count=0;
foreach($rows as $row) {
    if (str_starts_with((string)$row['id_number'],'enc:v1:')) continue;
    $enc=ato_encrypt_sensitive((string)$row['id_number']);
    if ($enc===false) { fwrite(STDERR,"Encryption failed for user ID {$row['id']}.\n"); continue; }
    $st=$pdo->prepare('UPDATE users SET id_number=? WHERE id=?'); $st->execute([$enc,$row['id']]); $count++;
}
fwrite(STDOUT,"Encrypted {$count} legacy ID number(s). Keep the encryption key backed up securely.\n");
