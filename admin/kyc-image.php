<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_role(['admin']);
$userId=(int)($_GET['user_id']??0); $kind=(string)($_GET['kind']??'');
$columns=['front'=>'id_front_path','back'=>'id_back_path','selfie'=>'selfie_path'];
if ($userId<1 || !isset($columns[$kind])) { http_response_code(400); exit('Invalid request.'); }
$st=$pdo->prepare('SELECT '.$columns[$kind].' AS image_path FROM users WHERE id=?'); $st->execute([$userId]); $path=$st->fetchColumn();
if (!$path || !str_starts_with($path,'uploads/ids/')) { http_response_code(404); exit('Image not found.'); }
$base=realpath(__DIR__.'/../uploads/ids'); $file=realpath(__DIR__.'/../'.$path);
if (!$base || !$file || !str_starts_with($file,$base.DIRECTORY_SEPARATOR) || !is_file($file)) { http_response_code(404); exit('Image not found.'); }
$mime=(new finfo(FILEINFO_MIME_TYPE))->file($file); $allowed=['image/jpeg','image/png','image/webp'];
if (!in_array($mime,$allowed,true)) { http_response_code(415); exit('Unsupported image.'); }
header('Content-Type: '.$mime); header('Content-Length: '.filesize($file)); header('Cache-Control: private, no-store, max-age=0'); header('X-Content-Type-Options: nosniff');
readfile($file);
