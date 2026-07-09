<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/mailer.php';

/**
 * Returns the active unified SMTP configuration.
 */
function smtpConfig(): array
{
    return itsrMailConfig();
}

/**
 * Sends an email using the unified PHPMailer implementation.
 * Keeps backward compatibility with the legacy signature.
 */
function sendSmtpMail(string $toEmail, string $subject, string $body, ?string &$error = null): bool
{
    return itsrSendSmtpMail($toEmail, '', $subject, $body, $error);
}
