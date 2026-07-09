<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/db.php';

$activePage = $activePage ?? 'dashboard';
$menuItems = [
    'dashboard'   => ['label' => 'Dashboard',         'href' => 'dashboard.php',    'icon' => 'DS'],
    'requests'    => ['label' => 'Requests',           'href' => 'requests.php',     'icon' => 'RQ'],
    'history'     => ['label' => 'History',            'href' => 'history.php',      'icon' => 'HS'],
    'reports'     => ['label' => 'Audit Reports',      'href' => 'audit_report.php', 'icon' => 'RP'],
    'email_logs'  => ['label' => 'Email Logs',         'href' => 'email_logs.php',   'icon' => 'EL'],
    'holidays'    => ['label' => 'Public Holidays',    'href' => 'holidays.php',     'icon' => 'HL'],
    'staff'       => ['label' => 'User Management',    'href' => 'staff.php',        'icon' => 'UM'],
    'departments' => ['label' => 'Department Users',   'href' => 'departments.php',  'icon' => 'DP'],
    'backup'      => ['label' => 'Backup & Export',    'href' => 'backup.php',       'icon' => 'BK'],
];

function sidebarIconSvg(string $icon): string
{
    return match ($icon) {
        'DS' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5.5A1.5 1.5 0 0 1 5.5 4h4A1.5 1.5 0 0 1 11 5.5v4A1.5 1.5 0 0 1 9.5 11h-4A1.5 1.5 0 0 1 4 9.5v-4Zm9 0A1.5 1.5 0 0 1 14.5 4h4A1.5 1.5 0 0 1 20 5.5v4A1.5 1.5 0 0 1 18.5 11h-4A1.5 1.5 0 0 1 13 9.5v-4Zm-9 9A1.5 1.5 0 0 1 5.5 13h4A1.5 1.5 0 0 1 11 14.5v4A1.5 1.5 0 0 1 9.5 20h-4A1.5 1.5 0 0 1 4 18.5v-4Zm9 0A1.5 1.5 0 0 1 14.5 13h4a1.5 1.5 0 0 1 1.5 1.5v4a1.5 1.5 0 0 1-1.5 1.5h-4a1.5 1.5 0 0 1-1.5-1.5v-4Z"/></svg>',
        'RQ' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 4h7l5 5v9.5A1.5 1.5 0 0 1 17.5 20h-10A1.5 1.5 0 0 1 6 18.5v-13A1.5 1.5 0 0 1 7.5 4H7Zm6 1.5V10h4.5"/><path d="M9 13h6M9 16h6M9 10h2"/></svg>',
        'HS' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 8v4l2.5 2.5"/><path d="M12 3.5a8.5 8.5 0 1 0 8.5 8.5"/><path d="M17 4v4h4"/></svg>',
        'UM' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 11a3 3 0 1 0 0-6 3 3 0 0 0 0 6Zm7 2a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5ZM4.5 18.5a4.5 4.5 0 0 1 9 0M13.5 18.5a3.5 3.5 0 0 1 7 0"/></svg>',
        'DP' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 20h16"/><path d="M6 20V6.5A1.5 1.5 0 0 1 7.5 5h9A1.5 1.5 0 0 1 18 6.5V20"/><path d="M9 9h2M13 9h2M9 12h2M13 12h2M9 15h2M13 15h2"/></svg>',
        'RP' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 19V5"/><path d="M5 19h14"/><path d="M8.5 16v-4"/><path d="M12 16V8"/><path d="M15.5 16v-6"/></svg>',
        'HL' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 4v3M17 4v3"/><path d="M5.5 6h13A1.5 1.5 0 0 1 20 7.5v11A1.5 1.5 0 0 1 18.5 20h-13A1.5 1.5 0 0 1 4 18.5v-11A1.5 1.5 0 0 1 5.5 6Z"/><path d="M4 10h16"/><path d="M8 14h3M13 14h3M8 17h2"/></svg>',
        'EL' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16v12a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6Z"/><path d="M4 6l8 7 8-7"/></svg>',
        'BK' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 20h14M12 4v12M8 12l4 4 4-4"/></svg>',
        default => '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="4"/></svg>',
    };
}

$sidebarProfilePicture = '';
$sidebarProfilePictureTransform = '--picture-offset-x: 0px; --picture-offset-y: 0px;';
$sidebarInitials = strtoupper(substr(currentUsername() !== '' ? currentUsername() : 'AD', 0, 2));
$sidebarUnreadRequests = 0;
$sidebarStats = [
    'unassigned' => 0,
    'pending' => 0,
    'progress' => 0,
    'completed' => 0,
];

try {
    if (currentUserId() > 0) {
        $sidebarStmt = db()->prepare('SELECT profile_picture_path, profile_picture_position_x, profile_picture_position_y FROM users WHERE id = :id LIMIT 1');
        $sidebarStmt->execute([':id' => currentUserId()]);
        $sidebarProfileRow = $sidebarStmt->fetch() ?: [];
        $sidebarProfilePath = trim((string) ($sidebarProfileRow['profile_picture_path'] ?? ''));
        $sidebarProfileAbsolutePath = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $sidebarProfilePath);
        $sidebarPositionX = filter_var($sidebarProfileRow['profile_picture_position_x'] ?? 50, FILTER_VALIDATE_INT);
        $sidebarPositionY = filter_var($sidebarProfileRow['profile_picture_position_y'] ?? 50, FILTER_VALIDATE_INT);
        $sidebarPositionX = $sidebarPositionX === false ? 50 : max(0, min(100, (int) $sidebarPositionX));
        $sidebarPositionY = $sidebarPositionY === false ? 50 : max(0, min(100, (int) $sidebarPositionY));
        $sidebarTranslateX = round(((50 - $sidebarPositionX) / 50) * 7, 2);
        $sidebarTranslateY = round(((50 - $sidebarPositionY) / 50) * 7, 2);
        $sidebarProfilePictureTransform = '--picture-offset-x: ' . $sidebarTranslateX . 'px; --picture-offset-y: ' . $sidebarTranslateY . 'px;';

        if ($sidebarProfilePath !== '' && is_file($sidebarProfileAbsolutePath)) {
            $sidebarProfilePicture = $sidebarProfilePath;
        }
    }

    $sidebarUnreadRequests = (int) db()->query('SELECT COUNT(*) FROM service_requests WHERE is_read = 0')->fetchColumn();
    $sidebarStatsStmt = db()->query("
        SELECT
            SUM(CASE WHEN (assign_to IS NULL OR assign_to = '') AND COALESCE(status, 'pending') NOT IN ('completed', 'closed') THEN 1 ELSE 0 END) AS unassigned_count,
            SUM(CASE WHEN status = 'pending' OR status IS NULL OR status = '' THEN 1 ELSE 0 END) AS pending_count,
            SUM(CASE WHEN status = 'progress' THEN 1 ELSE 0 END) AS progress_count,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed_count
        FROM service_requests
    ");
    $sidebarStatsRow = $sidebarStatsStmt->fetch() ?: [];
    $sidebarStats = [
        'unassigned' => (int) ($sidebarStatsRow['unassigned_count'] ?? 0),
        'pending' => (int) ($sidebarStatsRow['pending_count'] ?? 0),
        'progress' => (int) ($sidebarStatsRow['progress_count'] ?? 0),
        'completed' => (int) ($sidebarStatsRow['completed_count'] ?? 0),
    ];
} catch (Throwable $exception) {
    $sidebarProfilePicture = '';
    $sidebarUnreadRequests = 0;
    $sidebarStats = [
        'unassigned' => 0,
        'pending' => 0,
        'progress' => 0,
        'completed' => 0,
    ];
}
?>
<style>
    @import url('https://fonts.googleapis.com/css2?family=Nunito+Sans:wght@400;500;600;700;800&display=swap');

    .sidebar {
        position: relative;
        background:
            radial-gradient(circle at top left, rgba(59, 130, 246, 0.14), transparent 34%),
            linear-gradient(180deg, #0a1322 0%, #0d172a 46%, #111c32 100%);
        color: #fff;
        padding: 22px 18px 18px;
        display: flex;
        flex-direction: column;
        min-height: 100vh;
        overflow: hidden;
        font-family: "Nunito Sans", Inter, "Segoe UI", Roboto, Arial, sans-serif;
        -webkit-font-smoothing: antialiased;
        text-rendering: optimizeLegibility;
    }

    .sidebar * {
        font-family: inherit;
        letter-spacing: 0;
    }

    .sidebar::before {
        content: '';
        position: absolute;
        inset: 14px;
        border-radius: 22px;
        border: 1px solid rgba(148, 163, 184, 0.08);
        pointer-events: none;
    }

    .sidebar > * {
        position: relative;
        z-index: 1;
    }

    .sidebar-brand {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 8px 8px 14px;
        margin-bottom: 10px;
        text-decoration: none;
        color: inherit;
        border-radius: 16px;
        transition: transform 0.18s ease, background 0.18s ease;
    }

    .sidebar-brand:hover {
        transform: translateX(2px);
        background: rgba(255, 255, 255, 0.04);
    }

    .sidebar-logo {
        width: 46px;
        height: 46px;
        border-radius: 15px;
        background: rgba(255,255,255,0.12);
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 14px;
        font-weight: 800;
        color: #fff;
        letter-spacing: 0.02em;
        flex-shrink: 0;
        box-shadow:
            0 8px 20px rgba(0,0,0,0.28),
            inset 0 1px 0 rgba(255,255,255,0.12);
        overflow: hidden;
        position: relative;
    }

    .sidebar-logo img {
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

    .sidebar-title {
        font-size: 16px;
        font-weight: 700;
        line-height: 1.25;
        color: #f8fbff;
    }

    .sidebar-subtitle {
        color: #9fb2ca;
        font-size: 12px;
        margin-top: 3px;
        font-weight: 500;
    }

    .sidebar-topbar {
        display: flex;
        justify-content: flex-start;
        padding: 0 8px 20px;
        margin-bottom: 14px;
        border-bottom: 1px solid rgba(148, 163, 184, 0.12);
    }

    .sidebar-topbar-card {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        width: 100%;
        padding: 12px;
        border-radius: 18px;
        background: rgba(255, 255, 255, 0.045);
        border: 1px solid rgba(148, 163, 184, 0.1);
        box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.03), 0 8px 20px rgba(7, 12, 23, 0.16);
    }

    .sidebar-nav {
        display: grid;
        gap: 6px;
        margin-top: 6px;
    }

    .sidebar-link {
        display: grid;
        grid-template-columns: 34px 1fr auto;
        align-items: center;
        gap: 11px;
        text-decoration: none;
        border-radius: 15px;
        padding: 11px 12px;
        min-height: 46px;
        font-size: 13.5px;
        font-weight: 600;
        color: #d6e0ec;
        background: transparent;
        border: 1px solid transparent;
        transition: transform 0.18s ease, background 0.18s ease, border-color 0.18s ease, color 0.18s ease;
    }

    .sidebar-link:hover {
        transform: translateX(1px);
        background: rgba(255, 255, 255, 0.045);
        border-color: rgba(148, 163, 184, 0.14);
        color: #fff;
    }

    .sidebar-link.is-active {
        background: linear-gradient(90deg, rgba(37, 99, 235, 0.28), rgba(56, 189, 248, 0.12));
        border-color: rgba(96, 165, 250, 0.28);
        border-left: 4px solid #3b82f6;
        border-top-left-radius: 6px;
        border-bottom-left-radius: 6px;
        color: #ffffff;
        box-shadow: inset 0 0 0 1px rgba(148, 163, 184, 0.05), 0 16px 28px rgba(11, 18, 32, 0.22);
    }

    .sidebar-link-badge {
        width: 34px;
        height: 34px;
        border-radius: 11px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: rgba(148, 163, 184, 0.1);
        color: #e2e8f0;
    }

    .sidebar-link-badge svg {
        width: 17px;
        height: 17px;
        stroke: currentColor;
        stroke-width: 1.9;
        fill: none;
        stroke-linecap: round;
        stroke-linejoin: round;
    }

    .sidebar-link.is-active .sidebar-link-badge {
        background: rgba(96, 165, 250, 0.14);
        color: #bae6fd;
    }

    .sidebar-link-count {
        min-width: 21px;
        height: 21px;
        padding: 0 6px;
        border-radius: 999px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background: linear-gradient(180deg, #fb7185 0%, #ef4444 100%);
        color: #ffffff;
        font-size: 11px;
        font-weight: 700;
        line-height: 1;
        box-shadow: 0 10px 18px rgba(239, 68, 68, 0.28);
    }

    .sidebar-footer {
        margin-top: auto;
        min-height: 12px;
    }

    .sidebar-insights {
        margin-top: 16px;
        padding: 14px 10px 12px;
        border-radius: 18px;
        background: linear-gradient(180deg, rgba(255, 255, 255, 0.045) 0%, rgba(255, 255, 255, 0.028) 100%);
        border: 1px solid rgba(148, 163, 184, 0.12);
        box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.03);
    }

    .sidebar-insights-label {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        font-size: 10.5px;
        font-weight: 700;
        letter-spacing: 0.04em;
        text-transform: uppercase;
        color: #a5ddff;
        margin-bottom: 8px;
    }

    .sidebar-insights-label span:last-child {
        color: #fbbf24;
        font-size: 9.5px;
        letter-spacing: 0.03em;
    }

    .sidebar-insights-grid {
        display: grid;
        gap: 7px;
    }

    .sidebar-insight {
        display: grid;
        grid-template-columns: 1fr auto;
        align-items: center;
        gap: 10px;
        padding: 10px 12px;
        border-radius: 15px;
        background: rgba(255, 255, 255, 0.04);
        border: 1px solid rgba(148, 163, 184, 0.08);
        text-decoration: none;
        transition: transform 0.18s ease, border-color 0.18s ease, background 0.18s ease;
    }

    .sidebar-insight:hover {
        transform: translateX(1px);
        background: rgba(255, 255, 255, 0.07);
        border-color: rgba(125, 211, 252, 0.18);
    }

    .sidebar-insight-main {
        position: relative;
        padding: 13px 13px 14px;
        background:
            radial-gradient(circle at top right, rgba(251, 191, 36, 0.18), transparent 34%),
            linear-gradient(135deg, rgba(251, 191, 36, 0.12), rgba(59, 130, 246, 0.08));
        border-color: rgba(251, 191, 36, 0.22);
        box-shadow: 0 12px 26px rgba(251, 191, 36, 0.06), inset 0 1px 0 rgba(255, 255, 255, 0.05);
    }

    .sidebar-insight-main::before {
        content: "";
        width: 7px;
        height: 7px;
        border-radius: 999px;
        background: #f59e0b;
        box-shadow: 0 0 0 5px rgba(245, 158, 11, 0.12);
        position: absolute;
        top: 14px;
        right: 13px;
    }

    .sidebar-insight small {
        grid-column: 1 / -1;
        color: #9fb2ca;
        font-size: 10.5px;
        line-height: 1.35;
    }

    .sidebar-insight span {
        color: #c6d6e7;
        font-size: 12.5px;
        font-weight: 600;
    }

    .sidebar-insight strong {
        color: #f8fbff;
        font-size: 15px;
        font-weight: 700;
        line-height: 1;
        padding-right: 22px;
        justify-self: end;
    }

    .sidebar-insight.is-unassigned strong { color: #fbbf24; font-size: 18px; }
    .sidebar-insight.is-pending strong { color: #fbbf24; }
    .sidebar-insight.is-progress strong { color: #60a5fa; }
    .sidebar-insight.is-completed strong { color: #4ade80; }

    .sidebar-logout {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-height: 40px;
        padding: 0 15px;
        border-radius: 13px;
        background: rgba(255, 255, 255, 0.055);
        border: 1px solid rgba(148, 163, 184, 0.12);
        color: #dbe7f5;
        text-decoration: none;
        font-size: 13.5px;
        font-weight: 600;
    }

    .sidebar-logout:hover {
        color: #fff;
        background: rgba(59, 130, 246, 0.16);
        border-color: rgba(96, 165, 250, 0.22);
    }

    .sidebar-tools {
        display: flex;
        width: 100%;
        gap: 10px;
        align-items: center;
        flex-wrap: wrap;
        justify-content: space-between;
    }

    .sidebar-theme-toggle {
        position: relative;
        display: inline-flex;
        align-items: center;
        justify-content: space-between;
        width: 96px;
        height: 40px;
        padding: 0 6px;
        border-radius: 999px;
        background: #f8fafc;
        border: 1px solid rgba(148, 163, 184, 0.18);
        cursor: pointer;
        overflow: hidden;
        box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.08);
    }

    .sidebar-theme-toggle::before {
        content: '';
        position: absolute;
        top: 4px;
        left: 5px;
        width: 30px;
        height: 30px;
        border-radius: 999px;
        background: linear-gradient(180deg, #38bdf8 0%, #2563eb 100%);
        box-shadow: 0 12px 24px rgba(37, 99, 235, 0.25);
        transition: transform 0.22s ease;
    }

    .sidebar-theme-toggle.is-dark::before {
        transform: translateX(52px);
        background: linear-gradient(180deg, #4f46e5 0%, #3730a3 100%);
        box-shadow: 0 12px 24px rgba(79, 70, 229, 0.28);
    }

    .sidebar-theme-toggle.is-dark {
        background: #171717;
        border-color: rgba(255, 255, 255, 0.08);
    }

    .sidebar-theme-option {
        position: relative;
        z-index: 1;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 30px;
        height: 30px;
        color: #94a3b8;
        font-size: 14px;
        font-weight: 600;
        transition: color 0.18s ease;
    }

    .sidebar-theme-option svg {
        width: 14px;
        height: 14px;
        stroke: currentColor;
        stroke-width: 1.8;
        fill: none;
        stroke-linecap: round;
        stroke-linejoin: round;
    }

    .sidebar-theme-toggle.is-light .sidebar-theme-option-light,
    .sidebar-theme-toggle.is-dark .sidebar-theme-option-dark {
        color: #ffffff;
    }

    .sidebar-theme-toggle.is-light .sidebar-theme-option-dark {
        color: #cbd5e1;
    }

    .sidebar-theme-toggle.is-dark .sidebar-theme-option-light {
        color: #8b95a7;
    }

    @media (max-width: 1024px) {
        .sidebar {
            min-height: auto;
        }
    }
</style>
<aside class="sidebar">
    <a class="sidebar-brand" href="account.php">
        <div class="sidebar-logo" style="background:rgba(255,255,255,0.10); padding:4px;">
            <?php if ($sidebarProfilePicture !== ''): ?>
                <img src="<?= htmlspecialchars($sidebarProfilePicture, ENT_QUOTES, 'UTF-8') ?>" alt="Admin profile picture" style="<?= htmlspecialchars($sidebarProfilePictureTransform, ENT_QUOTES, 'UTF-8') ?>">
            <?php else: ?>
                <img src="../assets/logo.png" alt="Logo" style="width:38px;height:38px;object-fit:contain;filter:brightness(0) invert(1);">
            <?php endif; ?>
        </div>
        <div>
            <div class="sidebar-title">ITSR</div>
            <div class="sidebar-subtitle">IT Service Request System</div>
        </div>
    </a>

    <div class="sidebar-topbar">
        <div class="sidebar-topbar-card">
            <div class="sidebar-tools">
                <button class="sidebar-theme-toggle" type="button" id="theme-toggle" aria-label="Toggle light or dark mode">
                    <span class="sidebar-theme-option sidebar-theme-option-light" aria-hidden="true">
                        <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="4"/><path d="M12 2.5v2.2M12 19.3v2.2M4.7 4.7l1.6 1.6M17.7 17.7l1.6 1.6M2.5 12h2.2M19.3 12h2.2M4.7 19.3l1.6-1.6M17.7 6.3l1.6-1.6"/></svg>
                    </span>
                    <span class="sidebar-theme-option sidebar-theme-option-dark" aria-hidden="true">
                        <svg viewBox="0 0 24 24"><path d="M20 15.5A7.5 7.5 0 0 1 8.5 4a8.5 8.5 0 1 0 11.5 11.5Z"/></svg>
                    </span>
                </button>
                <a class="sidebar-logout" href="../login/logout.php?portal=admin">Log out</a>
            </div>
        </div>
    </div>

    <nav class="sidebar-nav">
        <?php foreach ($menuItems as $key => $item): ?>
            <a class="sidebar-link<?= $activePage === $key ? ' is-active' : '' ?>" href="<?= htmlspecialchars($item['href'], ENT_QUOTES, 'UTF-8') ?>">
                <span class="sidebar-link-badge"><?= sidebarIconSvg((string) $item['icon']) ?></span>
                <span><?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?></span>
                <?php if ($key === 'requests' && $sidebarUnreadRequests > 0): ?>
                    <span class="sidebar-link-count"><?= htmlspecialchars((string) ($sidebarUnreadRequests > 99 ? '99+' : $sidebarUnreadRequests), ENT_QUOTES, 'UTF-8') ?></span>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>
    </nav>

    <section class="sidebar-insights" aria-label="Request summary">
        <div class="sidebar-insights-label">
            <span>Action Center</span>
            <span>Always check</span>
        </div>
        <div class="sidebar-insights-grid">
            <a class="sidebar-insight sidebar-insight-main is-unassigned" href="requests.php?assignment=unassigned">
                <span>Need Assignment</span>
                <strong><?= (int) $sidebarStats['unassigned'] ?></strong>
                <small>Requests not assigned to staff yet</small>
            </a>
            <a class="sidebar-insight is-pending" href="requests.php?status=pending">
                <span>Pending</span>
                <strong><?= (int) $sidebarStats['pending'] ?></strong>
            </a>
            <a class="sidebar-insight is-progress" href="requests.php?status=progress">
                <span>In Progress</span>
                <strong><?= (int) $sidebarStats['progress'] ?></strong>
            </a>
            <a class="sidebar-insight is-completed" href="requests.php?status=completed">
                <span>Completed</span>
                <strong><?= (int) $sidebarStats['completed'] ?></strong>
            </a>
        </div>
    </section>

    <div class="sidebar-footer">
    </div>
 </aside>
<script>
    (function () {
        var body = document.body;
        var toggle = document.getElementById('theme-toggle');
        var storageKey = 'admin-theme';

        function applyTheme(theme) {
            body.classList.toggle('theme-dark', theme === 'dark');
            body.classList.toggle('theme-light', theme !== 'dark');

            if (toggle) {
                toggle.classList.toggle('is-dark', theme === 'dark');
                toggle.classList.toggle('is-light', theme !== 'dark');
                toggle.setAttribute('aria-pressed', theme === 'dark' ? 'true' : 'false');
            }
        }

        var savedTheme = localStorage.getItem(storageKey);
        applyTheme(savedTheme === 'dark' ? 'dark' : 'light');

        if (toggle) {
            toggle.addEventListener('click', function () {
                var nextTheme = body.classList.contains('theme-dark') ? 'light' : 'dark';
                localStorage.setItem(storageKey, nextTheme);
                applyTheme(nextTheme);
            });
        }

        // Safety Net to resolve unresponsive/frozen UI overlay issues
        function runClickSafetyNet() {
            // Remove any viewport-wide blocking inert attributes
            if (document.body.hasAttribute('inert')) {
                document.body.removeAttribute('inert');
            }
            if (document.documentElement.hasAttribute('inert')) {
                document.documentElement.removeAttribute('inert');
            }

            // Check for blank or third-party overlays (e.g. injected by extensions)
            Array.prototype.forEach.call(document.body.children, function (element) {
                if (element.classList.contains('layout') || element.id === 'confirm-modal' || element.classList.contains('itsr-confirm-modal') || element.classList.contains('itsr-drawer')) {
                    return;
                }

                // If element covers more than 85% of screen and has position fixed/absolute
                // but lacks actual buttons, forms, inputs, links, or dialog roles, disable pointer events
                var style = window.getComputedStyle(element);
                if (style.position === 'fixed' || style.position === 'absolute') {
                    var rect = element.getBoundingClientRect();
                    var coversScreen = rect.width >= window.innerWidth * 0.85 && rect.height >= window.innerHeight * 0.85;
                    var hasInteractive = element.querySelector('a, button, input, select, textarea, [role="dialog"], [role="menu"]');
                    
                    if (coversScreen && !hasInteractive) {
                        element.style.pointerEvents = 'none';
                        element.style.opacity = '0'; // hide it visually if it is empty
                    }
                }
            });
        }

        document.addEventListener('DOMContentLoaded', runClickSafetyNet);
        window.addEventListener('load', runClickSafetyNet);

        // Keep watch for dynamically injected elements
        if (typeof MutationObserver !== 'undefined') {
            var safetyNetObserver = new MutationObserver(function () {
                runClickSafetyNet();
            });
            safetyNetObserver.observe(document.body, { childList: true, subtree: true });
        }
    }());
</script>
