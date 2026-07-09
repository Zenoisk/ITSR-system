<?php

declare(strict_types=1);

use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;

require_once __DIR__ . '/../vendor/autoload.php';

function itsrMailConfig(): array
{
    $config = require __DIR__ . '/../config/mail.php';
    return is_array($config) ? $config : [];
}

function sendSystemEmail(string $toEmail, string $toName, string $subject, string $body, ?string &$error = null): bool
{
    $config = itsrMailConfig();
    $error = null;

    if (empty($config['enabled'])) {
        $error = 'SMTP notifications are disabled in config/mail.php.';
        return false;
    }

    $toEmail = trim($toEmail);
    if ($toEmail === '' || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        $error = 'Invalid recipient email address.';
        error_log('Mailer Error: invalid recipient email.');
        return false;
    }

    if (!empty($config['log_only'])) {
        try {
            $logFile = (string) ($config['log_file'] ?? (__DIR__ . '/../storage/emails.log'));
            $storageDir = dirname($logFile);
            if (!is_dir($storageDir)) {
                if (!mkdir($storageDir, 0777, true) && !is_dir($storageDir)) {
                    $error = 'Failed to create storage directory for email logs.';
                    error_log('Mailer Error: ' . $error);
                    return false;
                }
            }

            $timestamp = date('Y-m-d H:i:s');
            $logEntry = "========================================================\n"
                      . "TIMESTAMP:   [" . $timestamp . "]\n"
                      . "TO:          " . $toEmail . ($toName !== '' ? " (" . $toName . ")" : '') . "\n"
                      . "FROM:        " . ($config['from_name'] ?? 'Enterprise ITSR System') . " <" . ($config['from_email'] ?? 'no-reply@localhost') . ">\n"
                      . "SUBJECT:     " . $subject . "\n"
                      . "--------------------------------------------------------\n"
                      . "BODY:\n" . $body . "\n"
                      . "========================================================\n\n";

            if (file_put_contents($logFile, $logEntry, FILE_APPEND) === false) {
                $error = 'Failed to write to local email log: ' . $logFile;
                error_log('Mailer Error: ' . $error);
                return false;
            }

            return true;
        } catch (Throwable $exception) {
            $error = 'Log mailer error: ' . $exception->getMessage();
            error_log('Mailer Error: ' . $error);
            return false;
        }
    }

    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host = (string) ($config['host'] ?? '');
        $mail->SMTPAuth = true;
        $mail->Username = (string) ($config['username'] ?? '');
        $mail->Password = (string) ($config['password'] ?? '');
        $mail->SMTPSecure = (string) ($config['encryption'] ?? PHPMailer::ENCRYPTION_STARTTLS);
        $mail->Port = (int) ($config['port'] ?? 587);

        $mail->CharSet = 'UTF-8';
        $mail->setFrom((string) ($config['from_email'] ?? ''), (string) ($config['from_name'] ?? 'Enterprise ITSR System'));
        $mail->addAddress($toEmail, $toName !== '' ? $toName : $toEmail);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $body;
        $mail->AltBody = trim(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $body)));

        return $mail->send();
    } catch (Exception $exception) {
        $error = $mail->ErrorInfo !== '' ? $mail->ErrorInfo : $exception->getMessage();
        error_log('Mailer Error: ' . $error);
        return false;
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
        error_log('Mailer Error: ' . $error);
        return false;
    }
}

function itsrSendSmtpMail(string $toEmail, string $toName, string $subject, string $body, ?string &$error = null): bool
{
    $error = null;
    $result = sendSystemEmail(
        $toEmail,
        $toName,
        $subject,
        nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8')),
        $error
    );

    if (!$result && ($error === null || $error === '')) {
        $error = 'Email could not be sent. Check SMTP settings and PHP error log.';
    }

    return $result;
}
