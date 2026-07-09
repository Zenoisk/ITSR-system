<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__) . '/db.php';
require_once __DIR__ . '/mailer.php';

$message = '';
$error = '';
$debugResetLink = '';
$portal = itsrPortalContext();

function issuePasswordReset(PDO $pdo, array $user): array
{
    $identifier = trim((string) ($user['email'] ?? ''));
    if ($identifier === '') {
        return [false, '', 'This account does not have an email address yet. Please contact an administrator.'];
    }

    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $expiresAt = date('Y-m-d H:i:s', time() + 1800);

    $pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE user_id = :user_id AND used_at IS NULL')->execute([
        ':user_id' => (int) $user['id'],
    ]);

    $stmt = $pdo->prepare('INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (:user_id, :token_hash, :expires_at)');
    $stmt->execute([
        ':user_id' => (int) $user['id'],
        ':token_hash' => $tokenHash,
        ':expires_at' => $expiresAt,
    ]);

    $portal = itsrPortalContext();
    $resetLink = appUrl() . '/login/reset_password.php?token=' . urlencode($token) . '&portal=' . urlencode($portal);

    $subject = 'Enterprise Password Reset';
    $body = "Hello " . ($user['username'] ?? 'user') . ",\n\n" .
        "Use the link below to reset your password:\n" . $resetLink . "\n\n" .
        "This link will expire in 30 minutes.\n\n" .
        "If you did not request this reset, please ignore this email.";

    $mailError = null;
    $sent = sendSmtpMail($identifier, $subject, $body, $mailError);

    return [$sent, $resetLink, $mailError ?? ''];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfValidateOrDie();
    $identifier = trim((string) ($_POST['identifier'] ?? ''));

    if ($identifier === '') {
        $error = 'Please enter your username or email.';
    } else {
        $user = findUserByIdentifier($identifier);

        if ($user) {
            [$sent, $resetLink, $issueError] = issuePasswordReset(db(), $user);

            if ($sent) {
                $message = 'A password reset link has been sent to the email address on the account.';
            } else {
                $message = 'A reset token was created, but SMTP email sending is not configured yet.';

                $isLocalHost = in_array($_SERVER['HTTP_HOST'] ?? '', ['localhost', '127.0.0.1'], true)
                    || str_contains((string) ($_SERVER['HTTP_HOST'] ?? ''), 'localhost');

                if ($isLocalHost) {
                    $debugResetLink = $resetLink;
                }

                if ($issueError !== '') {
                    $error = $issueError;
                }
            }
        } else {
            $message = 'If that account exists, a password reset link has been prepared.';
        }

        if (appIsProduction()) {
            $message = 'If that account exists, password reset instructions have been sent.';
            $error = '';
            $debugResetLink = '';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password</title>
    <link rel="stylesheet" href="../assets/auth-ui.css">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: Arial, Helvetica, sans-serif; }
        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(180deg, rgba(240, 246, 252, 0.72), rgba(229, 238, 248, 0.82)), url('formation-diamond.jpg') center/cover no-repeat;
            padding: 24px;
        }
        .card {
            width: 100%; max-width: 560px; background: rgba(255,255,255,.94); border: 1px solid rgba(180,198,219,.65);
            border-radius: 28px; padding: 34px; box-shadow: 0 28px 70px rgba(23,47,74,.16); backdrop-filter: blur(10px);
        }
        .brand { text-align: center; margin-bottom: 18px; }
        .brand img { width: 220px; max-width: 100%; height: auto; }
        h1 { font-size: 34px; color: #24466d; margin-bottom: 10px; text-align: center; }
        p { color: #66788b; line-height: 1.6; margin-bottom: 22px; text-align: center; }
        .notice, .error { border-radius: 14px; padding: 12px 14px; margin-bottom: 16px; font-size: 14px; }
        .notice { background: #edf8f1; border: 1px solid #c7e8d1; color: #1f7a3f; }
        .error { background: #fff1f1; border: 1px solid #e6c1c1; color: #8a1f1f; }
        label { display: block; font-weight: 700; margin-bottom: 8px; font-size: 15px; color: #33506f; }
        input { width: 100%; border: 1px solid #d8e1ea; border-radius: 16px; padding: 16px 18px; font-size: 15px; outline: none; background: #fff; margin-bottom: 18px; }
        input:focus { border-color: #27a7d9; box-shadow: 0 0 0 4px rgba(39,167,217,.12); }
        .btn { width: 100%; border: none; background: linear-gradient(180deg, #2dc0ea 0%, #1ea5d1 100%); color: #fff; font-size: 16px; font-weight: 700; padding: 16px 20px; cursor: pointer; border-radius: 999px; box-shadow: 0 12px 24px rgba(30,165,209,.24); }
        .footer { margin-top: 18px; text-align: center; }
        .footer a, .debug a { color: #39a9d6; text-decoration: underline; }
        .debug { margin-top: 14px; font-size: 14px; line-height: 1.5; color: #33506f; word-break: break-all; }
    </style>
</head>
<body>
    <div class="card">
        <div class="brand"><img src="../form/assets/logo.png" alt="Logo"></div>
        <h1>Forgot Password</h1>
        <p>Enter your username or email. If the account exists, we will send a reset link to the email address saved for that account.</p>

        <?php if ($message !== ''): ?><div class="notice"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
        <?php if ($error !== ''): ?><div class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

        <form method="post" action="forgot_password.php?portal=<?= htmlspecialchars($portal, ENT_QUOTES, 'UTF-8') ?>">
            <?= csrfField() ?>
            <input type="hidden" name="portal" value="<?= htmlspecialchars($portal, ENT_QUOTES, 'UTF-8') ?>">
            <label for="identifier">Username or Email</label>
            <input id="identifier" type="text" name="identifier" placeholder="Enter username or email" required>
            <button class="btn" type="submit">Send Reset Link</button>
        </form>

        <?php if ($debugResetLink !== ''): ?>
            <div class="debug">Local fallback reset link: <a href="<?= htmlspecialchars($debugResetLink, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($debugResetLink, ENT_QUOTES, 'UTF-8') ?></a></div>
        <?php endif; ?>

        <div class="footer"><a href="login.php?portal=<?= htmlspecialchars($portal, ENT_QUOTES, 'UTF-8') ?>">Back to login</a></div>
    </div>
</body>
</html>
