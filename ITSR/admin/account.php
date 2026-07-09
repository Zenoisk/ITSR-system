<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/login/auth.php';
requireAdmin();
require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/login/mailer.php';

$pdo = db();

// SMTP Test Ajax Handler
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'test_smtp') {
    header('Content-Type: application/json');
    if (!csrfValidate()) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Invalid or expired security token. Please refresh the page and try again.']);
        exit;
    }
    $testEmail = trim((string) ($_POST['test_email'] ?? ''));

    if ($testEmail === '' || !filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid recipient email address.']);
        exit;
    }

    require_once dirname(__DIR__) . '/includes/mailer.php';
    $mailError = null;
    $sent = itsrSendSmtpMail($testEmail, 'ITSR Test Recipient', 'Enterprise ITSR Connection Test', "This is a connection test from the Enterprise ITSR Dashboard. If you received this, your SMTP settings are configured correctly!", $mailError);

    if ($sent) {
        echo json_encode([
            'status' => 'success',
            'message' => "SMTP connection test succeeded! Test email was successfully sent to " . htmlspecialchars($testEmail, ENT_QUOTES, 'UTF-8') . "."
        ]);
    } else {
        echo json_encode([
            'status' => 'error',
            'message' => "SMTP connection test failed.\n\nError details: " . ($mailError ?: 'Unknown SMTP protocol error. Check config/mail.php settings.')
        ]);
    }
    exit;
}

$message = '';
$error = '';
$user = [];

function systemLoginLink(): string
{
    return appUrl() . '/login/login.php?portal=admin';
}

function profilePictureRelativePath(?string $storedPath): string
{
    $relativePath = trim((string) $storedPath);

    if ($relativePath === '') {
        return '';
    }

    $absolutePath = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);

    return is_file($absolutePath) ? $relativePath : '';
}

function normalizePicturePosition($value): int
{
    $position = filter_var($value, FILTER_VALIDATE_INT);

    if ($position === false) {
        return 50;
    }

    return max(0, min(100, (int) $position));
}

function pictureTransformStyle(int $positionX, int $positionY, float $travel = 12.0): string
{
    $translateX = round(((50 - $positionX) / 50) * $travel, 2);
    $translateY = round(((50 - $positionY) / 50) * $travel, 2);

    return '--picture-offset-x: ' . $translateX . 'px; --picture-offset-y: ' . $translateY . 'px;';
}

function storeProfilePictureUpload(string $fieldName): ?string
{
    if (!isset($_FILES[$fieldName]) || !is_array($_FILES[$fieldName])) {
        return null;
    }

    $upload = $_FILES[$fieldName];
    $errorCode = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);

    if ($errorCode === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if ($errorCode !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Profile picture upload failed. Please try again.');
    }

    $tmpPath = (string) ($upload['tmp_name'] ?? '');
    if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
        throw new RuntimeException('Invalid profile picture upload.');
    }

    $size = (int) ($upload['size'] ?? 0);
    if ($size <= 0 || $size > 5 * 1024 * 1024) {
        throw new RuntimeException('Profile picture must be 5 MB or smaller.');
    }

    $mimeType = mime_content_type($tmpPath) ?: '';
    $allowedTypes = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    if (!isset($allowedTypes[$mimeType])) {
        throw new RuntimeException('Profile picture must be JPG, PNG, WEBP, or GIF.');
    }

    if (@getimagesize($tmpPath) === false) {
        throw new RuntimeException('The uploaded profile picture is not a valid image.');
    }

    $storageDir = __DIR__ . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'profile_pictures';
    if (!is_dir($storageDir) && !mkdir($storageDir, 0777, true) && !is_dir($storageDir)) {
        throw new RuntimeException('Unable to create the profile picture folder.');
    }

    $fileName = 'profile_' . currentUserId() . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.' . $allowedTypes[$mimeType];
    $targetPath = $storageDir . DIRECTORY_SEPARATOR . $fileName;

    if (!move_uploaded_file($tmpPath, $targetPath)) {
        throw new RuntimeException('Unable to save the profile picture.');
    }

    return 'storage/profile_pictures/' . $fileName;
}

try {
    $stmt = $pdo->prepare('SELECT id, username, email, role, status, profile_picture_path, profile_picture_position_x, profile_picture_position_y, password_hash, created_at, updated_at FROM users WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => currentUserId()]);
    $user = $stmt->fetch() ?: [];

    if (!$user) {
        throw new RuntimeException('Account not found.');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrfValidateOrDie();
        $username = trim((string) ($_POST['username'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $currentPassword = (string) ($_POST['current_password'] ?? '');
        $newPassword = (string) ($_POST['new_password'] ?? '');
        $confirmPassword = (string) ($_POST['confirm_password'] ?? '');
        $profilePicturePositionX = normalizePicturePosition($_POST['profile_picture_position_x'] ?? 50);
        $profilePicturePositionY = normalizePicturePosition($_POST['profile_picture_position_y'] ?? 50);

        if ($username === '' || $email === '') {
            throw new InvalidArgumentException('Username and email are required.');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Enter a valid email address.');
        }

        if ($currentPassword === '' || !password_verify($currentPassword, (string) $user['password_hash'])) {
            throw new InvalidArgumentException('Current password is incorrect.');
        }

        if ($newPassword !== '' && $newPassword !== $confirmPassword) {
            throw new InvalidArgumentException('New password and confirmation do not match.');
        }

        if ($newPassword !== '' && ($passwordError = passwordPolicyError($newPassword)) !== null) {
            throw new InvalidArgumentException($passwordError);
        }

        $checkStmt = $pdo->prepare('SELECT id FROM users WHERE username = :username AND id <> :id LIMIT 1');
        $checkStmt->execute([
            ':username' => $username,
            ':id' => (int) $user['id'],
        ]);

        if ($checkStmt->fetchColumn()) {
            throw new InvalidArgumentException('That username is already in use.');
        }

        $emailCheckStmt = $pdo->prepare('SELECT id FROM users WHERE email = :email AND id <> :id LIMIT 1');
        $emailCheckStmt->execute([
            ':email' => $email,
            ':id' => (int) $user['id'],
        ]);

        if ($emailCheckStmt->fetchColumn()) {
            throw new InvalidArgumentException('That email is already in use.');
        }

        $profilePicturePath = (string) ($user['profile_picture_path'] ?? '');
        $uploadedProfilePicture = storeProfilePictureUpload('profile_picture');
        if ($uploadedProfilePicture !== null) {
            $profilePicturePath = $uploadedProfilePicture;
        }

        if ($newPassword !== '') {
            $updateStmt = $pdo->prepare('UPDATE users SET username = :username, email = :email, profile_picture_path = :profile_picture_path, profile_picture_position_x = :profile_picture_position_x, profile_picture_position_y = :profile_picture_position_y, password_hash = :password_hash WHERE id = :id');
            $updateStmt->execute([
                ':username' => $username,
                ':email' => $email,
                ':profile_picture_path' => $profilePicturePath !== '' ? $profilePicturePath : null,
                ':profile_picture_position_x' => $profilePicturePositionX,
                ':profile_picture_position_y' => $profilePicturePositionY,
                ':password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
                ':id' => (int) $user['id'],
            ]);
        } else {
            $updateStmt = $pdo->prepare('UPDATE users SET username = :username, email = :email, profile_picture_path = :profile_picture_path, profile_picture_position_x = :profile_picture_position_x, profile_picture_position_y = :profile_picture_position_y WHERE id = :id');
            $updateStmt->execute([
                ':username' => $username,
                ':email' => $email,
                ':profile_picture_path' => $profilePicturePath !== '' ? $profilePicturePath : null,
                ':profile_picture_position_x' => $profilePicturePositionX,
                ':profile_picture_position_y' => $profilePicturePositionY,
                ':id' => (int) $user['id'],
            ]);
        }

        $_SESSION['form_username'] = $username;

        $loginLink = systemLoginLink();
        $mailSubject = 'Enterprise Account Updated';
        $mailBody = "Hello " . $username . ",\n\n"
            . "Your Enterprise account details were updated successfully.\n\n"
            . "You can sign in here:\n" . $loginLink . "\n\n"
            . "If you did not make this change, please contact support immediately.";

        $mailError = null;
        $mailSent = sendSmtpMail($email, $mailSubject, $mailBody, $mailError);

        $stmt->execute([':id' => currentUserId()]);
        $user = $stmt->fetch() ?: [];
        $message = $mailSent
            ? 'Account updated successfully. A system link was sent to your email.'
            : 'Account updated successfully, but the email notification could not be sent.';

        if (!$mailSent && $mailError !== null && $mailError !== '') {
            $error = $mailError;
        }
    }
} catch (Throwable $exception) {
    $error = appErrorMessage($exception, 'Admin account update failed', 'Unable to update the account.');
}

$profilePictureUrl = profilePictureRelativePath((string) ($user['profile_picture_path'] ?? ''));
$profileInitials = strtoupper(substr((string) ($user['username'] ?? 'U'), 0, 2));
$profilePicturePositionX = normalizePicturePosition($user['profile_picture_position_x'] ?? 50);
$profilePicturePositionY = normalizePicturePosition($user['profile_picture_position_y'] ?? 50);
$profilePictureTransform = pictureTransformStyle($profilePicturePositionX, $profilePicturePositionY);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Account</title>
    <link rel="stylesheet" href="../assets/enterprise-ui.css?v=1.1">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: Inter, "Segoe UI", Roboto, Arial, sans-serif; }
        body {
            background:
                radial-gradient(circle at top left, rgba(56, 189, 248, 0.08), transparent 28%),
                linear-gradient(180deg, #eef4fb 0%, #f6f9fc 100%);
            color: #132538;
            transition: background 0.3s ease, color 0.22s ease;
        }
        .layout { min-height: 100vh; display: grid; grid-template-columns: 280px 1fr; }
        .content { padding: 28px; animation: pageEnter 0.28s ease; }
        .page { max-width: 1320px; width: 100%; margin: 0 auto; }
        .hero, .panel {
            background: rgba(255, 255, 255, 0.9);
            border: 1px solid rgba(209, 220, 232, 0.9);
            border-radius: 24px;
            box-shadow: 0 18px 38px rgba(15, 23, 42, 0.08);
            backdrop-filter: blur(10px);
            transition: transform 0.22s ease, box-shadow 0.24s ease, border-color 0.24s ease, background 0.24s ease;
        }
        .hero:hover, .panel:hover {
            transform: translateY(-2px);
            box-shadow: 0 24px 44px rgba(15, 23, 42, 0.11);
        }
        .hero {
            padding: 26px 28px;
            margin-bottom: 18px;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 16px;
            flex-wrap: wrap;
        }
        .eyebrow {
            display: inline-block;
            color: #2563eb;
            font-size: 12px;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            margin-bottom: 12px;
            font-weight: 700;
        }
        .hero h1 { font-size: 34px; margin-bottom: 10px; color: #0f2642; }
        .hero p { max-width: 760px; line-height: 1.65; color: #64748b; }
        .actions { display: flex; gap: 10px; flex-wrap: wrap; }
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            border-radius: 14px;
            padding: 12px 18px;
            font-size: 14px;
            font-weight: 700;
            border: 1px solid transparent;
            cursor: pointer;
            transition: transform 0.18s ease, box-shadow 0.22s ease, border-color 0.22s ease, background 0.22s ease;
        }
        .btn:hover { transform: translateY(-1px); }
        .btn-primary { background: linear-gradient(180deg, #2563eb 0%, #1d4ed8 100%); color: #fff; }
        .btn-light { background: #fff; color: #334155; border-color: #d7e0ea; }
        .workspace { display: grid; grid-template-columns: 0.85fr 1.25fr; gap: 18px; }
        .panel { padding: 22px; }
        .panel h2 { font-size: 23px; color: #0f2642; margin-bottom: 8px; }
        .panel-copy { color: #66788b; font-size: 14px; margin-bottom: 18px; line-height: 1.6; }
        .notice { padding: 12px 14px; border-radius: 16px; margin-bottom: 16px; font-size: 14px; }
        .success { background: #edf8f1; border: 1px solid #c7e8d1; color: #1f7a3f; }
        .error { background: #fff1f1; border: 1px solid #ebc7c7; color: #8a1f1f; }
        .profile-card {
            display: grid;
            gap: 16px;
        }
        .profile-avatar-frame {
            position: relative;
            width: 88px;
            height: 88px;
            border-radius: 26px;
            border: 1px solid rgba(203, 213, 225, 0.68);
            box-shadow: 0 18px 30px rgba(15, 23, 42, 0.12);
            overflow: hidden;
        }
        .profile-avatar-image {
            position: absolute;
            top: 50%;
            left: 50%;
            width: 132%;
            height: 132%;
            max-width: none;
            object-fit: cover;
            display: block;
            transform: translate(calc(-50% + var(--picture-offset-x, 0px)), calc(-50% + var(--picture-offset-y, 0px)));
        }
        .profile-badge {
            width: 68px;
            height: 68px;
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(180deg, #38bdf8 0%, #2563eb 100%);
            color: #eff6ff;
            font-size: 22px;
            font-weight: 800;
            letter-spacing: 0.08em;
            box-shadow: 0 18px 30px rgba(37, 99, 235, 0.24);
        }
        .profile-name { font-size: 28px; font-weight: 800; color: #0f2642; }
        .profile-role { color: #64748b; font-size: 14px; }
        .meta-list { display: grid; gap: 12px; margin-top: 6px; }
        .meta-item {
            border: 1px solid #e6edf4;
            border-radius: 18px;
            padding: 14px 16px;
            background: #fbfdff;
            transition: transform 0.2s ease, border-color 0.22s ease, background 0.22s ease;
        }
        .meta-item:hover { transform: translateY(-1px); }
        .meta-label { color: #708194; font-size: 12px; text-transform: uppercase; letter-spacing: 0.08em; margin-bottom: 6px; }
        .meta-value { color: #0f2642; font-weight: 700; }
        .form-grid { display: grid; gap: 14px; }
        .field-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; }
        label { display: block; margin-bottom: 6px; font-size: 13px; font-weight: 700; color: #36516c; }
        input {
            width: 100%;
            border: 1px solid #d4dde7;
            border-radius: 14px;
            padding: 12px 14px;
            font-size: 14px;
            outline: none;
            background: #fff;
        }
        input[type="file"] {
            padding: 12px;
            background: #f8fbff;
        }
        input[type="range"] {
            padding: 0;
            border: 0;
            background: transparent;
            box-shadow: none;
            accent-color: #2563eb;
        }
        input:focus { border-color: #2c71ba; box-shadow: 0 0 0 4px rgba(44, 113, 186, 0.10); }
        .helper { color: #708194; font-size: 13px; line-height: 1.5; }
        .picture-position-card {
            padding: 16px 18px;
            border-radius: 18px;
            border: 1px solid #d8e3ee;
            background: linear-gradient(180deg, #f8fbff 0%, #f3f8fd 100%);
        }
        .picture-position-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px;
        }
        .range-heading {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            margin-bottom: 8px;
        }
        .range-heading span:last-child {
            color: #2563eb;
            font-weight: 700;
            font-size: 12px;
        }
        @media (max-width: 1024px) {
            .layout { grid-template-columns: 1fr; }
            .content { padding: 18px; }
            .workspace, .field-grid { grid-template-columns: 1fr; }
            .picture-position-grid { grid-template-columns: 1fr; }
        }

        body.theme-dark {
            background: linear-gradient(180deg, #07111f 0%, #0b1729 100%);
            color: #e5eef9;
        }
        body.theme-dark .hero,
        body.theme-dark .panel,
        body.theme-dark .meta-item {
            background: rgba(12, 21, 36, 0.9);
            border-color: rgba(65, 85, 110, 0.72);
            box-shadow: 0 18px 38px rgba(0, 0, 0, 0.24);
        }
        body.theme-dark .hero h1,
        body.theme-dark .panel h2,
        body.theme-dark .profile-name,
        body.theme-dark .meta-value {
            color: #f4f8fd;
        }
        body.theme-dark .hero p,
        body.theme-dark .panel-copy,
        body.theme-dark .profile-role,
        body.theme-dark .helper,
        body.theme-dark .meta-label {
            color: #9eb2c9;
        }
        body.theme-dark input {
            background: #0f1a2d;
            border-color: rgba(78, 97, 121, 0.7);
            color: #eef4fb;
        }
        body.theme-dark input[type="file"] {
            background: rgba(15, 26, 45, 0.94);
        }
        body.theme-dark .picture-position-card {
            background: rgba(15, 26, 45, 0.88);
            border-color: rgba(78, 97, 121, 0.7);
        }
        body.theme-dark .btn-light {
            background: rgba(255, 255, 255, 0.07);
            color: #eef4fb;
            border-color: rgba(148, 163, 184, 0.18);
        }
        body.theme-dark .success,
        body.theme-dark .error {
            background: rgba(12, 21, 36, 0.9);
        }
        @keyframes pageEnter {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        @media (prefers-reduced-motion: reduce) {
            .content,
            .hero,
            .panel,
            .meta-item,
            .btn {
                animation: none !important;
                transition: none !important;
            }
        .password-wrapper { position: relative; display: flex; align-items: center; width: 100%; }
        .password-wrapper input { width: 100%; padding-right: 46px !important; }
        .password-toggle-btn { position: absolute; right: 12px; background: transparent; border: none; color: #64748b; cursor: pointer; display: flex; align-items: center; justify-content: center; padding: 6px; border-radius: 8px; transition: color 0.18s ease, background 0.18s ease; }
        .password-toggle-btn:hover { color: #1e293b; background: rgba(100, 116, 139, 0.08); }
        .password-toggle-btn svg { width: 18px; height: 18px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
        body.theme-dark .password-toggle-btn:hover { color: #f8fbff; background: rgba(148, 163, 184, 0.08); }
    </style>
</head>
<body>
    <div class="layout">
        <?php $activePage = 'account'; require __DIR__ . '/sidebar.php'; ?>

        <main class="content">
            <div class="page">
                <section class="hero">
                    <div>
                        <div class="eyebrow">Profile Settings</div>
                        <h1>Manage Your Account</h1>
                        <p>Update your own username and password here. Changes apply to your current admin session immediately.</p>
                    </div>
                    <div class="actions">
                        <a class="btn btn-light" href="dashboard.php">Back to Dashboard</a>
                    </div>
                </section>

                <?php if ($message !== ''): ?>
                    <div class="notice success"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>
                <?php if ($error !== ''): ?>
                    <div class="notice error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>

                <div class="workspace">
                    <section class="panel">
                        <div class="profile-card">
                            <?php if ($profilePictureUrl !== ''): ?>
                                <div class="profile-avatar-frame">
                                    <img class="profile-avatar-image" id="profile-picture-preview" src="<?= htmlspecialchars($profilePictureUrl, ENT_QUOTES, 'UTF-8') ?>" alt="Admin profile picture" style="<?= htmlspecialchars($profilePictureTransform, ENT_QUOTES, 'UTF-8') ?>">
                                </div>
                            <?php else: ?>
                                <div class="profile-badge"><?= htmlspecialchars($profileInitials, ENT_QUOTES, 'UTF-8') ?></div>
                            <?php endif; ?>
                            <div>
                                <div class="profile-name"><?= htmlspecialchars((string) ($user['username'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                                <div class="profile-role"><?= htmlspecialchars(currentUserLabel(), ENT_QUOTES, 'UTF-8') ?></div>
                            </div>
                            <div class="meta-list">
                                <div class="meta-item">
                                    <div class="meta-label">Account Status</div>
                                    <div class="meta-value"><?= htmlspecialchars((string) ($user['status'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                                </div>
                                <div class="meta-item">
                                    <div class="meta-label">Email</div>
                                    <div class="meta-value"><?= htmlspecialchars((string) ($user['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                                </div>
                                <div class="meta-item">
                                    <div class="meta-label">Created</div>
                                    <div class="meta-value"><?= htmlspecialchars((string) ($user['created_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                                </div>
                                <div class="meta-item">
                                    <div class="meta-label">Last Updated</div>
                                    <div class="meta-value"><?= htmlspecialchars((string) ($user['updated_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section class="panel">
                        <h2>Update Login Details</h2>
                        <div class="panel-copy">Keep the current password field filled to confirm it is really you before any account change is saved. You can also upload a profile photo for the admin sidebar and account card.</div>
                        <form method="post" enctype="multipart/form-data" class="form-grid">
                            <?= csrfField() ?>
                            <div>
                                <label for="username">Username</label>
                                <input id="username" type="text" name="username" value="<?= htmlspecialchars((string) ($user['username'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" required>
                            </div>
                            <div>
                                <label for="email">Email</label>
                                <input id="email" type="email" name="email" value="<?= htmlspecialchars((string) ($user['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" required>
                            </div>
                            <div>
                                <label for="profile_picture">Profile Picture</label>
                                <input id="profile_picture" type="file" name="profile_picture" accept=".jpg,.jpeg,.png,.webp,.gif,image/jpeg,image/png,image/webp,image/gif">
                            </div>
                            <div class="picture-position-card">
                                <div class="helper" style="margin-bottom: 12px;">Adjust how the photo sits inside the square so the important part stays visible in the sidebar and top profile area.</div>
                                <div class="picture-position-grid">
                                    <div>
                                        <div class="range-heading">
                                            <label for="profile_picture_position_x" style="margin-bottom: 0;">Left / Right</label>
                                            <span id="position-x-value"><?= htmlspecialchars((string) $profilePicturePositionX, ENT_QUOTES, 'UTF-8') ?>%</span>
                                        </div>
                                        <input id="profile_picture_position_x" type="range" name="profile_picture_position_x" min="0" max="100" value="<?= htmlspecialchars((string) $profilePicturePositionX, ENT_QUOTES, 'UTF-8') ?>">
                                    </div>
                                    <div>
                                        <div class="range-heading">
                                            <label for="profile_picture_position_y" style="margin-bottom: 0;">Up / Down</label>
                                            <span id="position-y-value"><?= htmlspecialchars((string) $profilePicturePositionY, ENT_QUOTES, 'UTF-8') ?>%</span>
                                        </div>
                                        <input id="profile_picture_position_y" type="range" name="profile_picture_position_y" min="0" max="100" value="<?= htmlspecialchars((string) $profilePicturePositionY, ENT_QUOTES, 'UTF-8') ?>">
                                    </div>
                                </div>
                            </div>
                            <div>
                                <label for="current_password">Current Password</label>
                                <div class="password-wrapper">
                                    <input id="current_password" type="password" name="current_password" required>
                                    <button class="password-toggle-btn" type="button" aria-label="Toggle password visibility">
                                        <svg class="eye-open" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                        <svg class="eye-closed" viewBox="0 0 24 24" style="display:none;"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                                    </button>
                                </div>
                            </div>
                            <div class="field-grid">
                                <div>
                                    <label for="new_password">New Password</label>
                                    <div class="password-wrapper">
                                        <input id="new_password" type="password" name="new_password" placeholder="Leave blank to keep current password">
                                        <button class="password-toggle-btn" type="button" aria-label="Toggle password visibility">
                                            <svg class="eye-open" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                            <svg class="eye-closed" viewBox="0 0 24 24" style="display:none;"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                                        </button>
                                    </div>
                                </div>
                                <div>
                                    <label for="confirm_password">Confirm New Password</label>
                                    <div class="password-wrapper">
                                        <input id="confirm_password" type="password" name="confirm_password" placeholder="Repeat new password">
                                        <button class="password-toggle-btn" type="button" aria-label="Toggle password visibility">
                                            <svg class="eye-open" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                            <svg class="eye-closed" viewBox="0 0 24 24" style="display:none;"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <div class="helper">If you only want to change the username, leave the new password fields empty.</div>
                            <button class="btn btn-primary" type="submit">Save Account Changes</button>
                        </form>
                    </section>

                    <section class="panel" style="grid-column: 1 / -1;">
                        <h2>SMTP Email Connection Test</h2>
                        <div class="panel-copy">Validate the system's email configuration. Enter a recipient email address to send a test message using the settings in <a href="../config/mail.php" style="color: #2563eb; font-weight: 700; text-decoration: underline;">config/mail.php</a>.</div>
                        
                        <div id="smtp-test-result" style="display: none; padding: 16px; border-radius: 18px; margin-bottom: 20px; font-size: 14px; font-family: monospace; white-space: pre-wrap; line-height: 1.6; border: 1px solid transparent;"></div>

                        <form id="smtp-test-form" method="post" style="display: flex; gap: 14px; align-items: flex-end; flex-wrap: wrap;">
                            <?= csrfField() ?>
                            <div style="flex: 1; min-width: 280px;">
                                <label for="test_email">Recipient Email Address</label>
                                <input id="test_email" type="email" name="test_email" placeholder="Enter recipient email address (e.g. test@example.com)" required>
                            </div>
                            <div>
                                <button type="submit" id="btn-smtp-test" class="btn btn-light" style="min-height: 46px; white-space: nowrap;">Send Test Email</button>
                            </div>
                        </form>
                    </section>
                </div>
            </div>
        </main>
    </div>
    <script>
        (function () {
            var preview = document.getElementById('profile-picture-preview');
            var inputX = document.getElementById('profile_picture_position_x');
            var inputY = document.getElementById('profile_picture_position_y');
            var valueX = document.getElementById('position-x-value');
            var valueY = document.getElementById('position-y-value');

            if (!inputX || !inputY || !valueX || !valueY) {
                return;
            }

            function syncPreview() {
                var x = inputX.value || '50';
                var y = inputY.value || '50';

                valueX.textContent = x + '%';
                valueY.textContent = y + '%';

                if (preview) {
                    var translateX = ((50 - parseInt(x, 10)) / 50) * 12;
                    var translateY = ((50 - parseInt(y, 10)) / 50) * 12;
                    preview.style.setProperty('--picture-offset-x', translateX.toFixed(2) + 'px');
                    preview.style.setProperty('--picture-offset-y', translateY.toFixed(2) + 'px');
                }
            }

            inputX.addEventListener('input', syncPreview);
            inputY.addEventListener('input', syncPreview);
            syncPreview();

            document.querySelectorAll('.password-toggle-btn').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var wrapper = btn.closest('.password-wrapper');
                    var input = wrapper.querySelector('input');
                    var eyeOpen = btn.querySelector('.eye-open');
                    var eyeClosed = btn.querySelector('.eye-closed');

                    if (input.type === 'password') {
                        input.type = 'text';
                        eyeOpen.style.display = 'none';
                        eyeClosed.style.display = 'block';
                    } else {
                        input.type = 'password';
                        eyeOpen.style.display = 'block';
                        eyeClosed.style.display = 'none';
                    }
                });
            });

            // SMTP Test Form Handling
            var smtpForm = document.getElementById('smtp-test-form');
            var smtpResult = document.getElementById('smtp-test-result');
            var smtpBtn = document.getElementById('btn-smtp-test');

            if (smtpForm && smtpResult && smtpBtn) {
                smtpForm.addEventListener('submit', function (e) {
                    e.preventDefault();
                    
                    smtpBtn.disabled = true;
                    smtpBtn.textContent = 'Sending...';
                    smtpResult.style.display = 'none';
                    smtpResult.className = '';

                    var testEmail = document.getElementById('test_email').value;
                    var csrfTokenInput = smtpForm.querySelector('input[name="_csrf_token"]');
                    
                    var formData = new FormData();
                    formData.append('action', 'test_smtp');
                    formData.append('test_email', testEmail);
                    if (csrfTokenInput) {
                        formData.append('_csrf_token', csrfTokenInput.value);
                    }

                    fetch('account.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(function (res) { return res.json(); })
                    .then(function (data) {
                        smtpResult.style.display = 'block';
                        smtpResult.textContent = data.message;
                        
                        if (data.status === 'success') {
                            smtpResult.style.backgroundColor = document.body.classList.contains('theme-dark') ? 'rgba(31, 122, 63, 0.15)' : '#edf8f1';
                            smtpResult.style.borderColor = document.body.classList.contains('theme-dark') ? '#1f7a3f' : '#c7e8d1';
                            smtpResult.style.color = '#1f7a3f';
                        } else {
                            smtpResult.style.backgroundColor = document.body.classList.contains('theme-dark') ? 'rgba(138, 31, 31, 0.15)' : '#fff1f1';
                            smtpResult.style.borderColor = document.body.classList.contains('theme-dark') ? '#8a1f1f' : '#ebc7c7';
                            smtpResult.style.color = '#8a1f1f';
                        }
                    })
                    .catch(function (err) {
                        smtpResult.style.display = 'block';
                        smtpResult.textContent = 'An unexpected error occurred during SMTP test connection request: ' + err.message;
                        smtpResult.style.backgroundColor = '#fff1f1';
                        smtpResult.style.borderColor = '#ebc7c7';
                        smtpResult.style.color = '#8a1f1f';
                    })
                    .finally(function () {
                        smtpBtn.disabled = false;
                        smtpBtn.textContent = 'Send Test Email';
                    });
                });
            }
        })();
    </script>
</body>
</html>
