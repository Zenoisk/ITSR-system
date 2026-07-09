<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

$error = '';
$portal = itsrPortalContext();
$requiredRole = match ($portal) {
    'staff' => 'staff',
    'department' => null,
    default => 'admin',
};
$portalTitle = match ($portal) {
    'staff' => 'Staff Login',
    'department' => 'Department Login',
    default => 'Admin Login',
};
$portalSubtitle = match ($portal) {
    'staff' => 'staff work queue',
    'department' => 'department request dashboard',
    default => 'admin management portal',
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfValidateOrDie();
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    [$success, $loginError] = $portal === 'department'
        ? attemptDepartmentLogin($username, $password)
        : attemptLogin($username, $password, $requiredRole);

    if ($success) {
        $destination = match (currentUserRole()) {
            'admin' => '../admin/dashboard.php',
            'department_user' => '../department/dashboard.php',
            default => '../staff/dashboard.php',
        };
        header('Location: ' . $destination);
        exit;
    }

    $error = (string) $loginError;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - ITSR Portal</title>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/auth-ui.css">
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: "Manrope", "Segoe UI", "Trebuchet MS", sans-serif;
        }
        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background:
                radial-gradient(circle at 12% 12%, rgba(255, 255, 255, 0.62), transparent 24%),
                radial-gradient(circle at 84% 18%, rgba(95, 178, 255, 0.18), transparent 22%),
                linear-gradient(180deg, rgba(236, 244, 252, 0.62), rgba(220, 232, 246, 0.84)),
                url('cloud.jpg') center center / cover no-repeat;
            color: #111827;
            padding: 30px 22px;
            overflow-x: hidden;
            overflow-y: auto;
            position: relative;
        }

        body::before,
        body::after {
            content: "";
            position: fixed;
            inset: 0;
            pointer-events: none;
            z-index: 0;
        }

        body::before {
            background:
                radial-gradient(circle at 14% 86%, rgba(255, 255, 255, 0.28), transparent 20%),
                radial-gradient(circle at 88% 78%, rgba(255, 255, 255, 0.16), transparent 22%),
                radial-gradient(circle at 50% 110%, rgba(173, 216, 255, 0.20), transparent 26%);
            filter: blur(18px);
            opacity: 0.9;
        }

        body::after {
            background:
                radial-gradient(circle at center, transparent 42%, rgba(8, 20, 36, 0.18) 100%);
        }

        .shell {
            width: 100%;
            max-width: 1560px;
            min-height: 740px;
            display: grid;
            grid-template-columns: minmax(0, 1.75fr) minmax(480px, 0.95fr);
            background: rgba(247, 251, 255, 0.64);
            border: 1px solid rgba(210, 226, 241, 0.72);
            border-radius: 38px;
            overflow: hidden;
            box-shadow: 0 34px 100px rgba(21, 44, 71, 0.20);
            backdrop-filter: blur(14px);
            animation: shellEnter 0.6s ease;
            position: relative;
            z-index: 1;
        }

        .shell::before {
            content: "";
            position: absolute;
            inset: 0;
            background:
                linear-gradient(135deg, rgba(255, 255, 255, 0.10), transparent 38%),
                radial-gradient(circle at top right, rgba(45, 192, 234, 0.12), transparent 20%),
                linear-gradient(180deg, rgba(255, 255, 255, 0.05), transparent 50%, rgba(9, 24, 46, 0.02) 100%);
            pointer-events: none;
            z-index: 0;
        }

        .hero {
            position: relative;
            min-height: 740px;
            overflow: hidden;
            background: #09182d;
            isolation: isolate;
        }

        .hero-slides {
            position: absolute;
            inset: 0;
        }

        .hero-slide {
            position: absolute;
            inset: 0;
            opacity: 0;
            transition: opacity 1.2s ease;
            will-change: opacity;
            backface-visibility: hidden;
        }

        .hero-slide.is-active {
            opacity: 1;
        }

        .hero-video {
            position: absolute;
            inset: 0;
            width: 100%;
            height: 100%;
            object-fit: cover;
            object-position: center center;
            transform: translateZ(0);
            will-change: transform, opacity;
        }
        .hero::before {
            content: "";
            position: absolute;
            inset: 0;
            background:
                radial-gradient(circle at 18% 18%, rgba(255, 255, 255, 0.16), transparent 24%),
                linear-gradient(180deg, rgba(255, 255, 255, 0.04), rgba(255, 255, 255, 0));
            animation: heroDrift 18s ease-in-out infinite alternate;
        }

        .hero::after {
            content: "";
            position: absolute;
            inset: 0;
            background:
                radial-gradient(circle at 72% 18%, rgba(78, 197, 255, 0.22), transparent 18%),
                radial-gradient(circle at 18% 80%, rgba(255, 255, 255, 0.10), transparent 24%),
                linear-gradient(180deg, rgba(7, 25, 50, 0.08), rgba(9, 24, 46, 0.22) 55%, rgba(7, 22, 41, 0.66) 100%);
        }

        .hero-copy {
            position: absolute;
            left: 52px;
            bottom: 54px;
            z-index: 2;
            max-width: 460px;
            color: rgba(255, 255, 255, 0.94);
            text-shadow: 0 10px 22px rgba(0, 0, 0, 0.38);
            animation: copyRise 0.8s ease 0.15s both;
        }

        .hero-chip {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 10px 14px;
            border-radius: 999px;
            margin-bottom: 20px;
            background: rgba(255, 255, 255, 0.12);
            border: 1px solid rgba(255, 255, 255, 0.20);
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            backdrop-filter: blur(10px);
            box-shadow: 0 14px 28px rgba(0, 0, 0, 0.16);
        }

        .hero-chip::before {
            content: "";
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #38bdf8;
            box-shadow: 0 0 0 6px rgba(56, 189, 248, 0.18);
        }

        .hero-copy .quote {
            font-size: 44px;
            font-weight: 600;
            font-style: italic;
            line-height: 1.28;
            letter-spacing: 0.01em;
            margin-bottom: 16px;
        }

        .hero-copy .tagline {
            font-size: 14px;
            letter-spacing: 0.18em;
            text-transform: uppercase;
            opacity: 0.86;
        }

        .hero-copy .tagline::before {
            content: "";
            display: block;
            width: 56px;
            height: 2px;
            background: rgba(255, 255, 255, 0.72);
            margin-bottom: 14px;
        }

        .panel {
            position: relative;
            padding: 54px 52px 44px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            background:
                linear-gradient(180deg, rgba(255, 255, 255, 0.94), rgba(247, 250, 255, 0.92)),
                radial-gradient(circle at bottom right, rgba(67, 134, 219, 0.08), transparent 28%);
            border-left: 1px solid rgba(205, 220, 236, 0.78);
            box-shadow: inset 1px 0 0 rgba(255, 255, 255, 0.78);
            animation: panelSlide 0.65s ease 0.08s both;
            z-index: 1;
        }

        .panel::before {
            content: "";
            position: absolute;
            inset: 22px 22px auto 22px;
            height: 1px;
            background: linear-gradient(90deg, transparent, rgba(148, 163, 184, 0.30), transparent);
        }

        .panel::after {
            content: "";
            position: absolute;
            inset: 0;
            background:
                radial-gradient(circle at 12% 18%, rgba(45, 192, 234, 0.08), transparent 18%),
                radial-gradient(circle at 88% 84%, rgba(37, 99, 235, 0.06), transparent 22%);
            pointer-events: none;
        }

        .panel > * {
            position: relative;
            z-index: 1;
            width: 100%;
            max-width: 468px;
        }

        .brand {
            text-align: center;
            margin-bottom: 26px;
        }

        .brand img {
            width: 192px;
            max-width: 100%;
            height: auto;
            filter: drop-shadow(0 10px 20px rgba(28, 85, 132, 0.12));
        }

        .title {
            text-align: center;
            font-family: "Plus Jakarta Sans", "Manrope", sans-serif;
            font-size: clamp(31px, 3vw, 38px);
            font-weight: 800;
            color: #173a62;
            margin-bottom: 9px;
            letter-spacing: -0.055em;
            line-height: 1.08;
        }

        .subtitle {
            text-align: center;
            color: #61758d;
            font-size: 15.5px;
            margin: 0 auto 28px;
            line-height: 1.58;
            max-width: 360px;
        }

        .portal-switch {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 8px;
            margin: 0 0 20px;
            padding: 7px;
            border-radius: 20px;
            background: rgba(235, 244, 253, 0.92);
            border: 1px solid rgba(206, 222, 238, 0.95);
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.86);
        }

        .portal-switch a {
            min-height: 42px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 14px;
            color: #496984;
            text-decoration: none;
            font-size: 14px;
            font-weight: 800;
            letter-spacing: -0.01em;
            transition: background 0.18s ease, color 0.18s ease, box-shadow 0.18s ease, transform 0.18s ease;
        }

        .portal-switch a:hover {
            transform: translateY(-1px);
            color: #1f5fbf;
        }

        .portal-switch a.is-active {
            background: #fff;
            color: #1f5fbf;
            box-shadow: 0 12px 24px rgba(31, 95, 191, 0.14);
        }

        .public-form-link {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            padding: 17px 18px;
            margin: 0 0 24px;
            border-radius: 22px;
            background: linear-gradient(135deg, rgba(233, 247, 255, 0.94) 0%, rgba(250, 253, 255, 0.98) 100%);
            border: 1px solid #c6e0f4;
            color: #17486f;
            text-decoration: none;
            box-shadow: 0 14px 30px rgba(31, 95, 191, 0.08);
            transition: transform 0.18s ease, box-shadow 0.18s ease, border-color 0.18s ease;
        }

        .public-form-link:hover {
            transform: translateY(-2px);
            border-color: #86c8f1;
            box-shadow: 0 18px 34px rgba(31, 95, 191, 0.14);
        }

        .public-form-link strong {
            display: block;
            font-size: 15px;
            margin-bottom: 4px;
            color: #0d3558;
            letter-spacing: -0.015em;
        }

        .public-form-link span {
            color: #5d7891;
            font-size: 12.5px;
            line-height: 1.5;
        }

        .public-form-link .form-arrow {
            width: 42px;
            height: 42px;
            flex: 0 0 42px;
            border-radius: 15px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #1f95d1;
            color: #fff;
            box-shadow: 0 10px 22px rgba(31, 149, 209, 0.28);
        }

        .public-form-link svg {
            width: 18px;
            height: 18px;
            fill: none;
            stroke: currentColor;
            stroke-width: 2.2;
            stroke-linecap: round;
            stroke-linejoin: round;
        }

        .error {
            background: #fff1f1;
            border: 1px solid #e6c1c1;
            border-radius: 14px;
            color: #8a1f1f;
            padding: 12px 14px;
            margin-bottom: 16px;
            font-size: 14px;
        }

        .field {
            margin-bottom: 18px;
        }

        .field-wrap {
            position: relative;
        }

        label {
            display: block;
            font-weight: 800;
            margin-bottom: 9px;
            font-size: 13px;
            letter-spacing: 0.03em;
            color: #304f70;
            text-transform: uppercase;
        }

        .icon {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            width: 20px;
            height: 20px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #6c86a1;
        }

        .icon svg {
            width: 18px;
            height: 18px;
            stroke: currentColor;
            stroke-width: 1.9;
            fill: none;
            stroke-linecap: round;
            stroke-linejoin: round;
        }

        input {
            width: 100%;
            border: 1px solid #d3e0ec;
            border-radius: 20px;
            padding: 18px 18px 18px 50px;
            font-size: 15px;
            outline: none;
            background: linear-gradient(180deg, rgba(255, 255, 255, 0.99), rgba(249, 252, 255, 0.97));
            box-shadow: inset 0 1px 2px rgba(0, 0, 0, 0.025), 0 10px 22px rgba(36, 70, 109, 0.045);
            transition: border-color 0.18s ease, box-shadow 0.18s ease, transform 0.18s ease;
        }

        input:focus {
            border-color: #27a7d9;
            box-shadow: 0 0 0 4px rgba(39, 167, 217, 0.12), 0 14px 26px rgba(39, 167, 217, 0.10);
            transform: translateY(-1px);
        }

        .toggle-password {
            position: absolute;
            right: 16px;
            top: 50%;
            transform: translateY(-50%);
            border: none;
            background: none;
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #6c86a1;
            cursor: pointer;
            transition: color 0.18s ease, background-color 0.18s ease;
            padding: 0;
            z-index: 10;
        }

        .toggle-password:hover {
            color: #1d74e8;
            background-color: rgba(29, 116, 232, 0.08);
        }

        .toggle-password svg {
            width: 18px;
            height: 18px;
            stroke: currentColor;
            stroke-width: 2;
            fill: none;
            stroke-linecap: round;
            stroke-linejoin: round;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            border: none;
            background: linear-gradient(135deg, #1d74e8 0%, #21b7d7 100%);
            color: #fff;
            font-size: 16px;
            font-weight: 800;
            padding: 18px 20px;
            cursor: pointer;
            border-radius: 18px;
            box-shadow: 0 18px 32px rgba(30, 119, 224, 0.24);
            margin-top: 14px;
            letter-spacing: -0.01em;
            transition: transform 0.18s ease, box-shadow 0.18s ease, filter 0.18s ease;
            position: relative;
            overflow: hidden;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 20px 34px rgba(30, 165, 209, 0.28);
            filter: saturate(1.02);
        }

        .btn::after {
            content: "";
            position: absolute;
            inset: 0;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.24), transparent);
            transform: translateX(-120%);
            transition: transform 0.55s ease;
        }

        .btn:hover::after {
            transform: translateX(120%);
        }

        .helper {
            margin-top: 24px;
            display: flex;
            justify-content: space-between;
            gap: 16px;
            font-size: 13px;
            align-items: center;
        }

        .helper-note {
            color: #7a8ea3;
        }

        .helper a {
            color: #1d74c9;
            text-decoration: underline;
            text-underline-offset: 3px;
            font-weight: 700;
        }

        .panel-footer {
            margin-top: 28px;
            padding-top: 20px;
            border-top: 1px solid rgba(213, 222, 233, 0.86);
            color: #8093a8;
            font-size: 12px;
            text-align: center;
            line-height: 1.6;
        }

        @keyframes shellEnter {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @keyframes panelSlide {
            from { opacity: 0; transform: translateX(20px); }
            to { opacity: 1; transform: translateX(0); }
        }

        @keyframes copyRise {
            from { opacity: 0; transform: translateY(22px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @keyframes heroDrift {
            from { transform: scale(1) translate3d(0, 0, 0); }
            to { transform: scale(1.03) translate3d(-10px, -6px, 0); }
        }
        @media (max-width: 960px) {
            body {
                align-items: flex-start;
            }

            .shell {
                grid-template-columns: 1fr;
                min-height: auto;
                max-width: 680px;
            }

            .hero {
                min-height: 320px;
            }

            .panel {
                padding: 32px 22px 28px;
            }

            .title {
                font-size: 28px;
            }

            .hero-copy {
                left: 26px;
                right: 26px;
                bottom: 28px;
            }

            .hero-copy .quote {
                font-size: 30px;
            }
        }

        @media (prefers-reduced-motion: reduce) {
            .shell,
            .panel,
            .hero::before,
            .hero-copy,
            .btn,
            input {
                animation: none !important;
                transition: none !important;
            }
        }
    </style>
</head>
<body>
    <div class="shell">
        <div class="hero">
            <div class="hero-slides" id="hero-slides">
                <video id="hero-video-player" class="hero-video" autoplay muted loop playsinline preload="auto" poster="formation-diamond.jpg" style="transition: opacity 0.8s ease; will-change: opacity; opacity: 1;">
                    <source id="hero-video-source" src="hero-video.mp4" type="video/mp4">
                </video>
            </div>
            <div class="hero-copy">
                <div class="hero-chip">ITSR &mdash; Staff, Admin &amp; Department</div>
                <div class="quote">"You must find<br>your own sky"</div>
                <div class="tagline">IT Service Request System</div>
            </div>
        </div>

        <form class="panel" method="post" action="login.php?portal=<?= htmlspecialchars($portal, ENT_QUOTES, 'UTF-8') ?>">
            <div class="brand">
                <img src="../form/assets/logo.png" alt="Logo">
            </div>

            <div class="title"><?= htmlspecialchars($portalTitle, ENT_QUOTES, 'UTF-8') ?></div>
            <div class="subtitle">Sign in to the <?= htmlspecialchars($portalSubtitle, ENT_QUOTES, 'UTF-8') ?>.</div>

            <div class="portal-switch" aria-label="Choose login portal">
                <a class="<?= $portal === 'admin' ? 'is-active' : '' ?>" href="login.php?portal=admin">Admin</a>
                <a class="<?= $portal === 'staff' ? 'is-active' : '' ?>" href="login.php?portal=staff">Staff</a>
                <a class="<?= $portal === 'department' ? 'is-active' : '' ?>" href="login.php?portal=department">Department</a>
            </div>

            <a class="public-form-link" href="../form/request_form.php">
                <span>
                    <strong>Fill Customer Request Form</strong>
                    <span>No login needed for users submitting a new ITSR form.</span>
                </span>
                <span class="form-arrow" aria-hidden="true">
                    <svg viewBox="0 0 24 24"><path d="M5 12h14"/><path d="m13 6 6 6-6 6"/></svg>
                </span>
            </a>

            <?php if ($error !== ''): ?>
                <div class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>

            <?php if (isset($_GET['logout_reason']) && $_GET['logout_reason'] === 'inactivity'): ?>
                <div class="error" style="background: #fffbeb; border-color: #fef08a; color: #854d0e; text-shadow: none;">Your session has expired due to inactivity. Please log in again.</div>
            <?php endif; ?>

            <?= csrfField() ?>
            <input type="hidden" name="portal" value="<?= htmlspecialchars($portal, ENT_QUOTES, 'UTF-8') ?>">

            <div class="field">
                <label for="username"><?= $portal === 'department' ? 'Department' : 'Username' ?></label>
                <div class="field-wrap">
                    <span class="icon" aria-hidden="true">
                        <?php if ($portal === 'department'): ?>
                            <svg viewBox="0 0 24 24"><path d="M4 20V6.5A1.5 1.5 0 0 1 5.5 5h8A1.5 1.5 0 0 1 15 6.5V20"/><path d="M15 10h3.5A1.5 1.5 0 0 1 20 11.5V20"/><path d="M7 9h5M7 12h5M7 15h5M3 20h18"/></svg>
                        <?php else: ?>
                            <svg viewBox="0 0 24 24"><path d="M12 12a4 4 0 1 0-4-4 4 4 0 0 0 4 4Z"/><path d="M5 19a7 7 0 0 1 14 0"/></svg>
                        <?php endif; ?>
                    </span>
                    <input id="username" type="text" name="username" placeholder="<?= $portal === 'department' ? 'Enter department name' : 'Enter your username' ?>" required>
                </div>
            </div>

            <div class="field">
                <label for="password">Password</label>
                <div class="field-wrap">
                    <span class="icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24"><path d="M7 11V8a5 5 0 0 1 10 0v3"/><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M12 15v2"/></svg>
                    </span>
                    <input id="password" type="password" name="password" placeholder="Enter your password" required style="padding-right: 50px;">
                    <button type="button" id="toggle-password-btn" class="toggle-password" aria-label="Toggle password visibility">
                        <svg class="eye-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                    </button>
                </div>
            </div>

            <button class="btn" type="submit">Authenticate</button>

            <div class="helper">
                <span class="helper-note">Authorized users only</span>
                <?php if ($portal !== 'department'): ?>
                    <a href="forgot_password.php?portal=<?= htmlspecialchars($portal, ENT_QUOTES, 'UTF-8') ?>">Forgot password?</a>
                <?php else: ?>
                    <span class="helper-note">Ask admin to reset department password</span>
                <?php endif; ?>
            </div>

            <div class="panel-footer">
                Internal access for Enterprise request handling, review, and operations.
            </div>
        </form>
    </div>
    <script>
        (function () {
            var videoPlayer = document.getElementById('hero-video-player');
            var videoSource = document.getElementById('hero-video-source');
            var videoSources = [
                'hero-video.mp4',
                'hero-video-2.mp4',
                'hero-video-3.mp4',
                'hero-video-4.mp4',
                'hero-video-5.mp4',
                'hero-video-6.mp4'
            ];
            var currentIndex = 0;

            function tryPlay(media) {
                if (!media) {
                    return;
                }
                var playPromise = media.play();
                if (playPromise && typeof playPromise.catch === 'function') {
                    playPromise.catch(function () {});
                }
            }

            if (!videoPlayer || !videoSource || videoSources.length < 2) {
                return;
            }

            window.setInterval(function () {
                var nextIndex = (currentIndex + 1) % videoSources.length;
                videoPlayer.style.opacity = '0';
                
                window.setTimeout(function () {
                    videoSource.setAttribute('src', videoSources[nextIndex]);
                    videoPlayer.load();
                    
                    videoPlayer.onloadeddata = function () {
                        videoPlayer.style.opacity = '1';
                    };
                    
                    tryPlay(videoPlayer);
                    currentIndex = nextIndex;
                }, 800); // Wait for the 0.8s CSS fade-out to complete
            }, 8000);
        }());

        (function () {
            var togglePasswordBtn = document.getElementById('toggle-password-btn');
            var passwordInput = document.getElementById('password');
            if (togglePasswordBtn && passwordInput) {
                togglePasswordBtn.addEventListener('click', function () {
                    var isPassword = passwordInput.getAttribute('type') === 'password';
                    passwordInput.setAttribute('type', isPassword ? 'text' : 'password');
                    if (isPassword) {
                        togglePasswordBtn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>';
                    } else {
                        togglePasswordBtn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>';
                    }
                });
            }
        }());
    </script>
</body>
</html>
