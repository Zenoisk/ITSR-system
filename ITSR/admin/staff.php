<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/login/auth.php';
requireAdmin();
require_once dirname(__DIR__) . '/db.php';

$pdo = db();
$message = '';
$error = '';

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrfValidateOrDie();
        if (($_POST['add_staff'] ?? '') === '1') {
            $username = trim((string) ($_POST['new_username'] ?? ''));
            $email = trim((string) ($_POST['new_email'] ?? ''));
            $password = (string) ($_POST['new_password'] ?? '');

            if ($username === '' || $email === '' || $password === '') {
                throw new InvalidArgumentException('Username, email, and password are required to add staff.');
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException('Enter a valid staff email address.');
            }
            if (($passwordError = passwordPolicyError($password)) !== null) {
                throw new InvalidArgumentException($passwordError);
            }

            $stmt = $pdo->prepare(
                'INSERT INTO users (username, email, password_hash, role, status) VALUES (:username, :email, :password_hash, :role, :status)'
            );
            $stmt->execute([
                ':username' => $username,
                ':email' => $email,
                ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
                ':role' => 'staff',
                ':status' => 'active',
            ]);
            logSystemActivity('staff_created', 'Staff account added', 'Admin created staff account "' . $username . '".');
            $message = 'Staff account added successfully.';
        }

        if (isset($_POST['user_id'])) {
            $userId = (int) $_POST['user_id'];
            if (($_POST['delete_staff'] ?? '') === '1') {
                if ($userId <= 0) {
                    throw new RuntimeException('Invalid staff delete request.');
                }

                $lookupStmt = $pdo->prepare('SELECT username FROM users WHERE id = :id AND role = :role LIMIT 1');
                $lookupStmt->execute([
                    ':id' => $userId,
                    ':role' => 'staff',
                ]);
                $staffUsername = (string) ($lookupStmt->fetchColumn() ?: '');

                $stmt = $pdo->prepare('DELETE FROM users WHERE id = :id AND role = :role');
                $stmt->execute([
                    ':id' => $userId,
                    ':role' => 'staff',
                ]);

                if ($staffUsername !== '') {
                    logSystemActivity('staff_deleted', 'Staff account deleted', 'Admin deleted staff account "' . $staffUsername . '".');
                }

                $message = 'Staff account deleted successfully.';
            } else {
                $username = trim((string) ($_POST['username'] ?? ''));
                $email = trim((string) ($_POST['email'] ?? ''));
                $status = (string) ($_POST['status'] ?? 'active');
                $password = (string) ($_POST['password'] ?? '');

                if ($userId <= 0 || $username === '' || $email === '') {
                    throw new RuntimeException('Invalid staff update request.');
                }
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new InvalidArgumentException('Enter a valid staff email address.');
                }

                $beforeStmt = $pdo->prepare('SELECT username, status FROM users WHERE id = :id AND role = :role LIMIT 1');
                $beforeStmt->execute([
                    ':id' => $userId,
                    ':role' => 'staff',
                ]);
                $beforeUser = $beforeStmt->fetch() ?: [];
                $normalizedStatus = $status === 'blocked' ? 'blocked' : 'active';

                $stmt = $pdo->prepare('UPDATE users SET username = :username, email = :email, status = :status WHERE id = :id AND role = :role');
                $stmt->execute([
                    ':username' => $username,
                    ':email' => $email,
                    ':status' => $normalizedStatus,
                    ':id' => $userId,
                    ':role' => 'staff',
                ]);

                if ($password !== '') {
                    if (($passwordError = passwordPolicyError($password)) !== null) {
                        throw new InvalidArgumentException($passwordError);
                    }
                    $passStmt = $pdo->prepare('UPDATE users SET password_hash = :password_hash WHERE id = :id AND role = :role');
                    $passStmt->execute([
                        ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
                        ':id' => $userId,
                        ':role' => 'staff',
                    ]);
                }

                $changes = [];
                if (($beforeUser['username'] ?? '') !== $username) {
                    $changes[] = 'renamed to "' . $username . '"';
                }
                if (($beforeUser['status'] ?? '') !== $normalizedStatus) {
                    $changes[] = 'status changed to ' . $normalizedStatus;
                }
                if ($password !== '') {
                    $changes[] = 'password reset';
                }
                logSystemActivity(
                    'staff_updated',
                    'Staff account updated',
                    $changes !== []
                        ? 'Admin updated staff account: ' . implode(', ', $changes) . '.'
                        : 'Admin updated staff account "' . $username . '".'
                );

                $message = 'Staff account updated successfully.';
            }
        }
    }
} catch (Throwable $exception) {
    $error = appErrorMessage($exception, 'User management failed', 'Unable to complete the user management operation.');
}

$staffUsers = $pdo->query("SELECT id, username, email, role, status, created_at, updated_at FROM users WHERE role = 'staff' ORDER BY id DESC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Staff</title>
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
        .workspace { display: grid; grid-template-columns: 0.95fr 1.35fr; gap: 18px; }
        .panel { padding: 22px; }
        .panel h2 { font-size: 23px; color: #0f2642; margin-bottom: 8px; }
        .panel-copy { color: #66788b; font-size: 14px; margin-bottom: 18px; line-height: 1.6; }
        .notice { padding: 12px 14px; border-radius: 16px; margin-bottom: 16px; font-size: 14px; }
        .success { background: #edf8f1; border: 1px solid #c7e8d1; color: #1f7a3f; }
        .error { background: #fff1f1; border: 1px solid #ebc7c7; color: #8a1f1f; }
        .toast { position: fixed; top: 24px; right: 24px; z-index: 1200; min-width: 280px; max-width: 380px; box-shadow: 0 20px 40px rgba(15, 23, 42, 0.16); animation: toastIn 0.28s ease; }
        .itsr-confirm-modal[hidden] { display: none; }
        .itsr-confirm-modal { position: fixed; inset: 0; z-index: 1300; display: flex; align-items: center; justify-content: center; }
        .itsr-confirm-backdrop { position: absolute; inset: 0; background: rgba(7, 17, 31, 0.56); backdrop-filter: blur(3px); }
        .itsr-confirm-dialog { position: relative; width: min(420px, calc(100vw - 32px)); padding: 22px; border-radius: 22px; background: rgba(255, 255, 255, 0.96); border: 1px solid rgba(209, 220, 232, 0.92); box-shadow: 0 26px 60px rgba(15, 23, 42, 0.18); }
        .itsr-confirm-dialog h3 { font-size: 24px; color: #0f2642; margin-bottom: 8px; }
        .itsr-confirm-dialog p { color: #64748b; line-height: 1.6; margin-bottom: 18px; }
        .form-grid { display: grid; gap: 14px; }
        .compact-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 14px; }
        label { display: block; margin-bottom: 6px; font-size: 13px; font-weight: 700; color: #36516c; }
        input, select {
            width: 100%;
            border: 1px solid #d4dde7;
            border-radius: 14px;
            padding: 12px 14px;
            font-size: 14px;
            outline: none;
            background: #fff;
        }
        input:focus, select:focus { border-color: #2c71ba; box-shadow: 0 0 0 4px rgba(44, 113, 186, 0.10); }
        .staff-list { display: grid; gap: 16px; }
        .staff-card {
            border: 1px solid #e6edf4;
            border-radius: 22px;
            background: #fff;
            overflow: hidden;
            transition: transform 0.2s ease, box-shadow 0.22s ease, border-color 0.22s ease, background 0.22s ease;
        }
        .staff-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 22px 36px rgba(15, 23, 42, 0.09);
        }
        .staff-card-top {
            display: grid;
            grid-template-columns: minmax(0, 1.1fr) minmax(0, 1fr) auto auto;
            gap: 18px;
            padding: 18px 20px;
            border-bottom: 1px solid #e7edf3;
            align-items: start;
        }
        .staff-card-body {
            padding: 20px;
        }
        .meta { font-size: 12px; color: #6c7b8a; margin-top: 6px; }
        .pill { display: inline-flex; padding: 7px 12px; border-radius: 999px; font-size: 12px; font-weight: 700; }
        .active { background: #eaf8ef; color: #1f7a3f; }
        .blocked { background: #fff1f1; color: #8a1f1f; }
        .row-form { display: grid; gap: 14px; }
        .row-fields { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; }
        .row-actions { display: flex; gap: 10px; align-items: center; }
        .row-form .field-block { min-width: 120px; }
        .row-form .field-block select,
        .row-form .field-block input { min-width: 0; }
        .staff-label { display: block; color: #708194; font-size: 12px; letter-spacing: 0.08em; text-transform: uppercase; margin-bottom: 6px; }
        .staff-value { color: #0f2642; font-weight: 700; line-height: 1.45; word-break: break-word; }
        .btn-danger { background: linear-gradient(180deg, #d84c4c 0%, #b42318 100%); color: #fff; }
        .modern-select {
            position: relative;
        }
        .status-trigger {
            width: 100%;
            min-height: 48px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            border: 1px solid #d4dde7;
            border-radius: 16px;
            padding: 0 14px;
            background: linear-gradient(180deg, #ffffff, #f8fbff);
            color: #0f2642;
            font-size: 14px;
            font-weight: 800;
            cursor: pointer;
            transition: border-color 0.18s ease, box-shadow 0.18s ease, transform 0.18s ease;
        }
        .status-trigger:hover,
        .modern-select.is-open .status-trigger {
            border-color: #38a7ff;
            box-shadow: 0 0 0 4px rgba(56, 167, 255, 0.14);
        }
        .status-trigger::after {
            content: "";
            width: 10px;
            height: 10px;
            border-right: 2px solid #55708d;
            border-bottom: 2px solid #55708d;
            transform: rotate(45deg) translateY(-2px);
            transition: transform 0.18s ease;
        }
        .modern-select.is-open .status-trigger::after {
            transform: rotate(225deg) translateY(-2px);
        }
        .status-options {
            position: absolute;
            z-index: 30;
            top: calc(100% + 8px);
            left: 0;
            right: 0;
            display: none;
            padding: 8px;
            border: 1px solid #d8e5f2;
            border-radius: 18px;
            background: rgba(255, 255, 255, 0.98);
            box-shadow: 0 18px 40px rgba(15, 23, 42, 0.16);
        }
        .modern-select.is-open .status-options {
            display: grid;
            gap: 6px;
        }
        .status-option {
            width: 100%;
            min-height: 42px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border: 0;
            border-radius: 12px;
            padding: 0 12px;
            background: transparent;
            color: #0f2642;
            font-size: 14px;
            font-weight: 800;
            cursor: pointer;
            text-align: left;
        }
        .status-option:hover,
        .status-option.is-selected {
            background: #e8f3ff;
            color: #0b66c3;
        }
        .status-option.is-selected::after {
            content: "✓";
            color: #0b66c3;
            font-weight: 900;
        }
        @media (max-width: 1180px) {
            .workspace { grid-template-columns: 1fr; }
        }
        @media (max-width: 1024px) {
            .layout { grid-template-columns: 1fr; }
            .content { padding: 18px; }
            .compact-grid { grid-template-columns: 1fr; }
            .staff-card-top,
            .row-fields { grid-template-columns: 1fr; }
        }

        body.theme-dark {
            background: linear-gradient(180deg, #07111f 0%, #0b1729 100%);
            color: #e5eef9;
        }
        body.theme-dark .hero,
        body.theme-dark .panel,
        body.theme-dark .staff-card {
            background: rgba(12, 21, 36, 0.9);
            border-color: rgba(65, 85, 110, 0.72);
            box-shadow: 0 18px 38px rgba(0, 0, 0, 0.24);
        }
        body.theme-dark .hero h1,
        body.theme-dark .panel h2 {
            color: #f4f8fd;
        }
        body.theme-dark .hero p,
        body.theme-dark .panel-copy,
        body.theme-dark .meta {
            color: #9eb2c9;
        }
        body.theme-dark input,
        body.theme-dark select {
            background: #0f1a2d;
            border-color: rgba(78, 97, 121, 0.7);
            color: #eef4fb;
        }
        body.theme-dark .staff-card-top { border-bottom-color: rgba(57, 75, 99, 0.6); }
        body.theme-dark .staff-label { color: #9eb2c9; }
        body.theme-dark .staff-value { color: #e8eff8; }
        body.theme-dark .status-trigger {
            background: linear-gradient(180deg, #101b2d, #0d1727);
            border-color: rgba(78, 97, 121, 0.7);
            color: #eef4fb;
        }
        body.theme-dark .status-trigger::after { border-color: #9eb2c9; }
        body.theme-dark .status-options {
            background: #0d1727;
            border-color: #334761;
            box-shadow: 0 18px 40px rgba(0, 0, 0, 0.36);
        }
        body.theme-dark .status-option { color: #e5eef9; }
        body.theme-dark .status-option:hover,
        body.theme-dark .status-option.is-selected { background: #14375c; color: #8fd4ff; }
        body.theme-dark .status-option.is-selected::after { color: #8fd4ff; }
        body.theme-dark .btn-light {
            background: rgba(255, 255, 255, 0.07);
            color: #eef4fb;
            border-color: rgba(148, 163, 184, 0.18);
        }
        body.theme-dark .itsr-confirm-dialog { background: rgba(12, 21, 36, 0.96); border-color: rgba(65, 85, 110, 0.72); }
        body.theme-dark .itsr-confirm-dialog h3 { color: #f4f8fd; }
        body.theme-dark .itsr-confirm-dialog p { color: #9eb2c9; }
        body.theme-dark .success,
        body.theme-dark .error {
            background: rgba(12, 21, 36, 0.9);
        }
        @keyframes pageEnter {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        @keyframes toastIn { from { opacity: 0; transform: translateY(-8px); } to { opacity: 1; transform: translateY(0); } }
        @media (prefers-reduced-motion: reduce) {
            .content,
            .hero,
            .panel,
            .staff-card,
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
        <?php $activePage = 'staff'; require __DIR__ . '/sidebar.php'; ?>

        <main class="content">
            <div class="page">
                <section class="hero">
                    <div>
                        <div class="eyebrow">Administrator Staff</div>
                        <h1>Manage Staff Accounts</h1>
                        <p>Add new staff, update usernames, reset passwords, and block or reopen access from one clean workspace.</p>
                    </div>
                    <div class="actions">
                        <a class="btn btn-light" href="dashboard.php">Back to Dashboard</a>
                    </div>
                </section>

                <?php if ($message !== ''): ?><div class="notice success toast" data-toast><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
                <?php if ($error !== ''): ?><div class="notice error toast" data-toast><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

                <div class="workspace">
                    <section class="panel">
                        <h2>Add Staff</h2>
                        <div class="panel-copy">Create a new staff account for internal assignment, updates, and request handling.</div>
                        <form method="post" class="form-grid">
                            <?= csrfField() ?>
                            <div>
                                <label for="new_username">New Staff Username</label>
                                <input id="new_username" type="text" name="new_username" required>
                            </div>
                            <div>
                                <label for="new_email">Staff Email</label>
                                <input id="new_email" type="email" name="new_email" required>
                            </div>
                            <div>
                                <label for="new_password">New Staff Password</label>
                                <div class="password-wrapper">
                                    <input id="new_password" type="password" name="new_password" placeholder="Create staff password" autocomplete="new-password" required>
                                    <button class="password-toggle-btn" type="button" aria-label="Toggle password visibility">
                                        <svg class="eye-open" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                        <svg class="eye-closed" viewBox="0 0 24 24" style="display:none;"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                                    </button>
                                </div>
                            </div>
                            <button class="btn btn-primary" type="submit" name="add_staff" value="1">Add Staff</button>
                        </form>
                    </section>

                    <section class="panel">
                        <h2>Existing Staff</h2>
                        <div class="panel-copy">Update credentials and availability for current team members.</div>
                        <div class="staff-list">
                            <?php foreach ($staffUsers as $user): ?>
                                <article class="staff-card">
                                    <div class="staff-card-top">
                                        <div>
                                            <span class="staff-label">Username</span>
                                            <div class="staff-value"><?= htmlspecialchars((string) $user['username'], ENT_QUOTES, 'UTF-8') ?></div>
                                            <div class="meta">Updated <?= htmlspecialchars((string) $user['updated_at'], ENT_QUOTES, 'UTF-8') ?></div>
                                        </div>
                                        <div>
                                            <span class="staff-label">Email</span>
                                            <div class="staff-value"><?= htmlspecialchars((string) $user['email'], ENT_QUOTES, 'UTF-8') ?></div>
                                        </div>
                                        <div>
                                            <span class="staff-label">Status</span>
                                            <span class="pill <?= $user['status'] === 'blocked' ? 'blocked' : 'active' ?>"><?= htmlspecialchars((string) $user['status'], ENT_QUOTES, 'UTF-8') ?></span>
                                        </div>
                                        <div>
                                            <span class="staff-label">Created</span>
                                            <div class="staff-value"><?= htmlspecialchars((string) $user['created_at'], ENT_QUOTES, 'UTF-8') ?></div>
                                        </div>
                                    </div>
                                    <div class="staff-card-body">
                                        <form method="post" class="row-form">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>">
                                            <input type="hidden" name="delete_staff" value="" class="delete-staff-flag">
                                            <div class="row-fields">
                                                <div class="field-block">
                                                    <label>Username</label>
                                                    <input type="text" name="username" value="<?= htmlspecialchars((string) $user['username'], ENT_QUOTES, 'UTF-8') ?>" required>
                                                </div>
                                                <div class="field-block">
                                                    <label>Email</label>
                                                    <input type="email" name="email" value="<?= htmlspecialchars((string) $user['email'], ENT_QUOTES, 'UTF-8') ?>" required>
                                                </div>
                                                <div class="field-block">
                                                    <label>New Password</label>
                                                    <div class="password-wrapper">
                                                        <input type="password" name="password" placeholder="Leave blank to keep" autocomplete="new-password">
                                                        <button class="password-toggle-btn" type="button" aria-label="Toggle password visibility">
                                                            <svg class="eye-open" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                                            <svg class="eye-closed" viewBox="0 0 24 24" style="display:none;"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                                                        </button>
                                                    </div>
                                                </div>
                                                <div class="field-block">
                                                    <label>Status</label>
                                                    <?php $selectedStatus = $user['status'] === 'blocked' ? 'blocked' : 'active'; ?>
                                                    <div class="modern-select" data-modern-select>
                                                        <input type="hidden" name="status" value="<?= htmlspecialchars($selectedStatus, ENT_QUOTES, 'UTF-8') ?>" data-select-value>
                                                        <button class="status-trigger" type="button" data-select-trigger><?= $selectedStatus === 'blocked' ? 'Blocked' : 'Active' ?></button>
                                                        <div class="status-options" role="listbox">
                                                            <button class="status-option<?= $selectedStatus === 'active' ? ' is-selected' : '' ?>" type="button" data-value="active">Active</button>
                                                            <button class="status-option<?= $selectedStatus === 'blocked' ? ' is-selected' : '' ?>" type="button" data-value="blocked">Blocked</button>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="row-actions">
                                                <button class="btn btn-primary" type="submit" name="save_staff" value="1">Save</button>
                                                <button class="btn btn-danger" type="button" data-confirm-delete="staff">Delete</button>
                                            </div>
                                        </form>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    </section>
                </div>
            </div>
        </main>
    </div>
    <div class="itsr-confirm-modal" id="confirm-modal" hidden>
        <div class="itsr-confirm-backdrop" data-confirm-close></div>
        <div class="itsr-confirm-dialog">
            <h3>Delete Staff Account?</h3>
            <p>This will permanently remove the selected staff account.</p>
            <div class="row-actions">
                <button class="btn btn-light" type="button" data-confirm-close>Cancel</button>
                <button class="btn btn-danger" type="button" id="confirm-delete-action">Delete</button>
            </div>
        </div>
    </div>
    <script>
        (function () {
            document.querySelectorAll('[data-toast]').forEach(function (toast) {
                window.setTimeout(function () {
                    toast.style.opacity = '0';
                    toast.style.transform = 'translateY(-8px)';
                    window.setTimeout(function () { toast.remove(); }, 220);
                }, 2800);
            });
            var modal = document.getElementById('confirm-modal');
            var confirmButton = document.getElementById('confirm-delete-action');
            var activeForm = null;

            document.querySelectorAll('[data-modern-select]').forEach(function (select) {
                var trigger = select.querySelector('[data-select-trigger]');
                var valueInput = select.querySelector('[data-select-value]');
                var options = Array.from(select.querySelectorAll('[data-value]'));

                if (!trigger || !valueInput || options.length === 0) {
                    return;
                }

                trigger.addEventListener('click', function () {
                    document.querySelectorAll('[data-modern-select].is-open').forEach(function (openSelect) {
                        if (openSelect !== select) {
                            openSelect.classList.remove('is-open');
                        }
                    });
                    select.classList.toggle('is-open');
                });

                options.forEach(function (option) {
                    option.addEventListener('click', function () {
                        valueInput.value = option.getAttribute('data-value') || '';
                        trigger.textContent = option.textContent || '';
                        options.forEach(function (item) {
                            item.classList.toggle('is-selected', item === option);
                        });
                        select.classList.remove('is-open');
                    });
                });
            });

            document.addEventListener('click', function (event) {
                if (!event.target.closest('[data-modern-select]')) {
                    document.querySelectorAll('[data-modern-select].is-open').forEach(function (select) {
                        select.classList.remove('is-open');
                    });
                }
            });

            if (!modal || !confirmButton) { return; }
            function closeModal() { modal.hidden = true; activeForm = null; }
            document.querySelectorAll('[data-confirm-delete="staff"]').forEach(function (button) {
                button.addEventListener('click', function () {
                    activeForm = button.closest('form');
                    modal.hidden = false;
                });
            });
            modal.querySelectorAll('[data-confirm-close]').forEach(function (control) { control.addEventListener('click', closeModal); });
            confirmButton.addEventListener('click', function () {
                if (!activeForm) { closeModal(); return; }
                var flag = activeForm.querySelector('.delete-staff-flag');
                if (flag) { flag.value = '1'; }
                activeForm.submit();
            });

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
        })();
    </script>
</body>
</html>
