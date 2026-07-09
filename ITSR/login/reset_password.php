<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__) . '/db.php';

$message = '';
$error = '';
$portal = itsrPortalContext();
$token = trim((string) ($_GET['token'] ?? $_POST['token'] ?? ''));
$resetRow = null;

if ($token !== '') {
    $tokenHash = hash('sha256', $token);
    $stmt = db()->prepare(
        'SELECT pr.id, pr.user_id, pr.expires_at, pr.used_at, u.username
         FROM password_resets pr
         INNER JOIN users u ON u.id = pr.user_id
         WHERE pr.token_hash = :token_hash
         LIMIT 1'
    );
    $stmt->execute([':token_hash' => $tokenHash]);
    $resetRow = $stmt->fetch() ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfValidateOrDie();
    $password = (string) ($_POST['password'] ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

    if ($token === '' || !$resetRow) {
        $error = 'This password reset link is invalid.';
    } elseif (!empty($resetRow['used_at'])) {
        $error = 'This password reset link has already been used.';
    } elseif (strtotime((string) $resetRow['expires_at']) < time()) {
        $error = 'This password reset link has expired.';
    } elseif ($password === '' || $confirmPassword === '') {
        $error = 'Please enter and confirm your new password.';
    } elseif ($password !== $confirmPassword) {
        $error = 'Passwords do not match.';
    } elseif (($passwordError = passwordPolicyError($password)) !== null) {
        $error = $passwordError;
    } else {
        $pdo = db();
        $pdo->prepare('UPDATE users SET password_hash = :password_hash WHERE id = :id')->execute([
            ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
            ':id' => (int) $resetRow['user_id'],
        ]);
        $pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE id = :id')->execute([
            ':id' => (int) $resetRow['id'],
        ]);
        $message = 'Your password has been reset successfully. You can sign in now.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password</title>
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
        .footer a { color: #39a9d6; text-decoration: underline; }
    </style>
</head>
<body>
    <div class="card">
        <div class="brand"><img src="../form/assets/logo.png" alt="Logo"></div>
        <h1>Reset Password</h1>

        <?php if ($message !== ''): ?>
            <div class="notice"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div>
            <div class="footer"><a href="login.php?portal=<?= htmlspecialchars($portal, ENT_QUOTES, 'UTF-8') ?>">Back to login</a></div>
        <?php else: ?>
            <p>Create a new password for your account<?php if ($resetRow && !empty($resetRow['username'])): ?>, <?= htmlspecialchars((string) $resetRow['username'], ENT_QUOTES, 'UTF-8') ?><?php endif; ?>.</p>
            <?php if ($error !== ''): ?><div class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="portal" value="<?= htmlspecialchars($portal, ENT_QUOTES, 'UTF-8') ?>">
                <label for="password">New Password</label>
                <input id="password" type="password" name="password" required>
                <label for="confirm_password">Confirm New Password</label>
                <input id="confirm_password" type="password" name="confirm_password" required>
                <button class="btn" type="submit">Reset Password</button>
            </form>
            <div class="footer"><a href="login.php?portal=<?= htmlspecialchars($portal, ENT_QUOTES, 'UTF-8') ?>">Back to login</a></div>
        <?php endif; ?>
    </div>
</body>
</html>
