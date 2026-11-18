<?php
// =========================================================================
// REAL SMTP EMAIL DELIVERY & SAFARICOM M-PESA DARAJA STK PUSH CONFIGURATION
// =========================================================================
// To deliver OTPs & Password Recovery codes to ANY real inbox (Gmail, Yahoo,
// Outlook, corporate domains), fill in your SMTP details below (e.g. a Gmail
// address + 16-character Google App Password, or your cPanel/Zoho SMTP).

return [
    'app_name' => 'Africa Trade OS',

    'smtp' => [
        'enabled'    => true,
        'host'       => getenv('SMTP_HOST') ?: 'smtp.gmail.com',
        'port'       => (int)(getenv('SMTP_PORT') ?: 465), // 465 (SSL) or 587 (STARTTLS)
        'encryption' => getenv('SMTP_ENCRYPTION') ?: 'ssl', // 'ssl' or 'tls'
        'username'   => getenv('SMTP_USER') ?: '',
        'password'   => getenv('SMTP_PASS') ?: '',
        'from_email' => getenv('MAIL_FROM') ?: 'noreply@africatradeos.com',
        'from_name'  => getenv('MAIL_FROM_NAME') ?: 'Africa Trade OS',
    ],

    // Safaricom M-Pesa Daraja API (Sandbox or Live)
    'mpesa' => [
        'env'             => getenv('MPESA_ENV') ?: 'sandbox',
        'consumer_key'    => getenv('MPESA_CONSUMER_KEY') ?: '',
        'consumer_secret' => getenv('MPESA_CONSUMER_SECRET') ?: '',
        'shortcode'       => getenv('MPESA_SHORTCODE') ?: '174379',
        'passkey'         => getenv('MPESA_PASSKEY') ?: '',
        'callback_url'    => getenv('MPESA_CALLBACK_URL') ?: '',
    ],
];
