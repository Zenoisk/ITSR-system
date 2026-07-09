<?php

declare(strict_types=1);

require_once __DIR__ . '/environment.php';

/*
 * SMTP settings for Enterprise ITSR email notifications.
 *
 * For Gmail, use a Gmail App Password. Do not use your normal Gmail password.
 * Replace the placeholder values below with your real SMTP details when testing.
 */
return [
    'enabled' => appEnvBool('MAIL_ENABLED', !appIsProduction()),
    'log_only' => appEnvBool('MAIL_LOG_ONLY', true),
    'log_file' => __DIR__ . '/../storage/emails.log',

    'host' => appEnv('MAIL_HOST', 'smtp.gmail.com'),
    'port' => (int) (appEnv('MAIL_PORT', '587') ?? '587'),
    'encryption' => appEnv('MAIL_ENCRYPTION', 'tls'),

    'username' => appEnv('MAIL_USERNAME', ''),
    'password' => appEnvRaw('MAIL_PASSWORD', ''),

    'from_email' => appEnv('MAIL_FROM_ADDRESS', 'no-reply@localhost'),
    'from_name' => appEnv('MAIL_FROM_NAME', 'Enterprise ITSR System'),

    'app_url' => appUrl(),
];
