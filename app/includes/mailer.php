<?php

function ato_smtp_read($socket): string
{
    $data = '';
    while ($str = fgets($socket, 515)) {
        $data .= $str;
        if (substr($str, 3, 1) === ' ') {
            break;
        }
    }
    return $data;
}

function ato_smtp_cmd($socket, string $cmd, array $expectedCodes): bool
{
    fwrite($socket, $cmd . "\r\n");
    $resp = ato_smtp_read($socket);
    $code = (int)substr($resp, 0, 3);
    if (!in_array($code, $expectedCodes, true)) {
        error_log("Africa Trade OS SMTP command failed (expected " . implode(',', $expectedCodes) . "): {$resp}");
        return false;
    }
    return true;
}

/**
 * Dispatches real HTML emails via authenticated SMTP (SSL/TLS) with graceful fallback to PHP mail().
 * Returns ['sent' => bool, 'transport' => 'smtp'|'php_mail'|'unconfigured', 'error' => string]
 */
function dispatch_real_email(string $toEmail, string $toName, string $subject, string $htmlBody): array
{
    $cfg = $GLOBALS['ATO_CONFIG']['smtp'] ?? [];
    $fromEmail = !empty($cfg['from_email']) ? $cfg['from_email'] : ($cfg['username'] ?? '');
    $fromName  = str_replace(["\r","\n",'"','<','>'], '', (string)($cfg['from_name'] ?? 'Africa Trade OS'));
    $toName = str_replace(["\r","\n",'"','<','>'], '', $toName);
    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL) || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
        return ['sent' => false, 'transport' => 'unconfigured', 'error' => 'A valid recipient and sender email are required.'];
    }
    $subject = preg_replace('/[\r\n]+/', ' ', $subject);

    $wrappedHtml = "<div style='font-family:Inter,Arial,sans-serif;max-width:560px;margin:0 auto;padding:28px;border:1px solid #e2e8f0;border-radius:12px;background:#ffffff;'>
        <h2 style='color:#0f1f38;margin-top:0;'>AFRICA <span style='color:#ea580c;'>TRADE OS</span></h2>
        <p style='color:#334155;'>Hello <strong>" . htmlspecialchars($toName, ENT_QUOTES, 'UTF-8') . "</strong>,</p>
        {$htmlBody}
        <hr style='border:none;border-top:1px solid #e2e8f0;margin:24px 0;'>
        <p style='font-size:12px;color:#64748b;'>Africa Trade OS • Pan-African Retail & Wholesale Marketplace</p>
    </div>";

    $_SESSION['last_email_preview'] = [
        'to' => $toEmail,
        'subject' => $subject,
        'body' => trim(strip_tags($htmlBody)),
    ];

    // 1. Authenticated SMTP Socket Delivery (Works on Windows XAMPP, WAMP, Linux, cPanel, VPS)
    if (!empty($cfg['enabled']) && !empty($cfg['host']) && !empty($cfg['username']) && !empty($cfg['password'])) {
        $host = $cfg['host'];
        $port = (int)($cfg['port'] ?? 465);
        $enc  = strtolower((string)($cfg['encryption'] ?? 'ssl'));
        $remote = ($enc === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;

        $ctx = stream_context_create([
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
            ]
        ]);

        $socket = @stream_socket_client($remote, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
        if ($socket) {
            stream_set_timeout($socket, 15);
            $greeting = ato_smtp_read($socket);
            if (str_starts_with($greeting, '220')) {
                $serverName = preg_replace('/[^A-Za-z0-9.:-]/', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
                ato_smtp_cmd($socket, "EHLO {$serverName}", [250]);

                if ($enc === 'tls') {
                    if (!ato_smtp_cmd($socket, "STARTTLS", [220]) || !stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                        fclose($socket);
                        return ['sent' => false, 'transport' => 'smtp', 'error' => 'SMTP TLS negotiation failed.'];
                    }
                    ato_smtp_cmd($socket, "EHLO {$serverName}", [250]);
                }

                if (
                    ato_smtp_cmd($socket, "AUTH LOGIN", [334]) &&
                    ato_smtp_cmd($socket, base64_encode($cfg['username']), [334]) &&
                    ato_smtp_cmd($socket, base64_encode($cfg['password']), [235]) &&
                    ato_smtp_cmd($socket, "MAIL FROM:<{$fromEmail}>", [250]) &&
                    ato_smtp_cmd($socket, "RCPT TO:<{$toEmail}>", [250, 251]) &&
                    ato_smtp_cmd($socket, "DATA", [354])
                ) {
                    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
                    $msg  = 'From: "' . $fromName . '" <' . $fromEmail . ">\r\n";
                    $msg .= 'To: "' . $toName . '" <' . $toEmail . ">\r\n";
                    $msg .= "Subject: {$encodedSubject}\r\n";
                    $msg .= "MIME-Version: 1.0\r\n";
                    $msg .= "Content-Type: text/html; charset=UTF-8\r\n";
                    $msg .= "Date: " . date('r') . "\r\n\r\n";
                    $msg .= $wrappedHtml . "\r\n.";

                    if (ato_smtp_cmd($socket, $msg, [250])) {
                        ato_smtp_cmd($socket, "QUIT", [221]);
                        fclose($socket);
                        $_SESSION['last_email_status'] = 'sent_smtp';
                        return ['sent' => true, 'transport' => 'smtp', 'error' => ''];
                    }
                }
            }
            fclose($socket);
        }
        $_SESSION['last_email_status'] = 'smtp_failed';
        return ['sent' => false, 'transport' => 'smtp', 'error' => "SMTP connection failed ({$errstr}). Check SMTP credentials in app/config/integrations.php."];
    }

    // 2. Native PHP mail() fallback when SMTP credentials are not yet entered
    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-type: text/html; charset=UTF-8\r\n";
    $headers .= "From: {$fromName} <{$fromEmail}>\r\n";
    $ok = @mail($toEmail, $subject, $wrappedHtml, $headers);
    $_SESSION['last_email_status'] = $ok ? 'sent_phpmail' : 'smtp_unconfigured';
    return [
        'sent' => $ok,
        'transport' => $ok ? 'php_mail' : 'unconfigured',
        'error' => $ok ? '' : 'SMTP credentials in app/config/integrations.php are empty.'
    ];
}
