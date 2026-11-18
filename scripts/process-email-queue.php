<?php
// Schedule every 5 minutes: php scripts/process-email-queue.php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../app/bootstrap.php';
$st=$pdo->query("SELECT * FROM email_outbox WHERE status IN ('pending','failed') AND attempts < 6 AND (next_attempt_at IS NULL OR next_attempt_at<=NOW()) ORDER BY created_at ASC LIMIT 30");
$rows=$st->fetchAll(); $sent=0;
foreach($rows as $row) {
    $ok=send_platform_email($row['recipient_email'],$row['recipient_name'],$row['subject'],$row['html_body']);
    $attempt=(int)$row['attempts']+1;
    $delay=min(1440,5*(2 ** min($attempt,8)));
    $up=$pdo->prepare("UPDATE email_outbox SET status=?, attempts=?, sent_at=IF(?=1,NOW(),NULL), next_attempt_at=IF(?=1,NULL,DATE_ADD(NOW(), INTERVAL ? MINUTE)), last_error=? WHERE id=?");
    $up->execute([$ok?'sent':'failed',$attempt,$ok?1:0,$ok?1:0,$delay,$ok?null:'SMTP delivery attempt failed',$row['id']]);
    if($ok) $sent++;
}
fwrite(STDOUT,'Processed '.count($rows).' queued emails; sent '.$sent.".\n");
