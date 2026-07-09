<?php

declare(strict_types=1);

$activePage = $activePage ?? 'dashboard';
$menuItems = [
    'dashboard' => ['label' => 'Dashboard', 'href' => 'dashboard.php', 'icon' => 'DS'],
    'requests' => ['label' => 'Work Queue', 'href' => 'requests.php', 'icon' => 'RQ'],
];

function staffSidebarIconSvg(string $icon): string
{
    return match ($icon) {
        'DS' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5.5A1.5 1.5 0 0 1 5.5 4h4A1.5 1.5 0 0 1 11 5.5v4A1.5 1.5 0 0 1 9.5 11h-4A1.5 1.5 0 0 1 4 9.5v-4Zm9 0A1.5 1.5 0 0 1 14.5 4h4A1.5 1.5 0 0 1 20 5.5v4A1.5 1.5 0 0 1 18.5 11h-4A1.5 1.5 0 0 1 13 9.5v-4Zm-9 9A1.5 1.5 0 0 1 5.5 13h4A1.5 1.5 0 0 1 11 14.5v4A1.5 1.5 0 0 1 9.5 20h-4A1.5 1.5 0 0 1 4 18.5v-4Zm9 0A1.5 1.5 0 0 1 14.5 13h4a1.5 1.5 0 0 1 1.5 1.5v4a1.5 1.5 0 0 1-1.5 1.5h-4a1.5 1.5 0 0 1-1.5-1.5v-4Z"/></svg>',
        'RQ' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 4h7l5 5v9.5A1.5 1.5 0 0 1 17.5 20h-10A1.5 1.5 0 0 1 6 18.5v-13A1.5 1.5 0 0 1 7.5 4H7Zm6 1.5V10h4.5"/><path d="M9 13h6M9 16h6M9 10h2"/></svg>',
        default => '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="4"/></svg>',
    };
}
?>
<style>
    @import url('https://fonts.googleapis.com/css2?family=Nunito+Sans:wght@400;500;600;700;800&display=swap');

    .sidebar {
        position: relative;
        background:
            radial-gradient(circle at top left, rgba(14, 165, 233, 0.12), transparent 34%),
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
    .sidebar > * { position: relative; z-index: 1; }
    .sidebar-brand {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 8px 8px 14px;
        margin-bottom: 10px;
    }
    .sidebar-logo {
        width: 50px;
        height: 50px;
        border-radius: 15px;
        background: linear-gradient(180deg, #38bdf8 0%, #0ea5e9 100%);
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 800;
        font-size: 14px;
        letter-spacing: 0.02em;
        color: #eff6ff;
        box-shadow: 0 18px 30px rgba(14, 165, 233, 0.28);
    }
    .sidebar-title { font-size: 16px; font-weight: 700; line-height: 1.25; color: #f8fbff; }
    .sidebar-subtitle { color: #9fb2ca; font-size: 12px; margin-top: 3px; font-weight: 500; }
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
    .sidebar-nav { display: grid; gap: 6px; margin-top: 6px; }
    .sidebar-link {
        display: grid;
        grid-template-columns: 34px 1fr;
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
        background: linear-gradient(90deg, rgba(14, 165, 233, 0.26), rgba(56, 189, 248, 0.12));
        border-color: rgba(125, 211, 252, 0.26);
        border-left: 4px solid #0ea5e9;
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
        background: rgba(125, 211, 252, 0.14);
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
    .sidebar-tools {
        display: flex;
        width: 100%;
        gap: 10px;
        align-items: center;
        justify-content: space-between;
    }
    .sidebar-footer-spacer {
        margin-top: auto;
        min-height: 18px;
    }
    .sidebar-theme-toggle {
        position: relative;
        display: inline-flex;
        align-items: center;
        justify-content: space-between;
        width: 98px;
        height: 42px;
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
        width: 32px;
        height: 32px;
        border-radius: 999px;
        background: linear-gradient(180deg, #38bdf8 0%, #0ea5e9 100%);
        box-shadow: 0 12px 24px rgba(14, 165, 233, 0.25);
        transition: transform 0.22s ease;
    }
    .sidebar-theme-toggle.is-dark::before {
        transform: translateX(54px);
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
        width: 32px;
        height: 32px;
        color: #94a3b8;
        transition: color 0.18s ease;
    }
    .sidebar-theme-option svg {
        width: 15px;
        height: 15px;
        stroke: currentColor;
        stroke-width: 1.8;
        fill: none;
        stroke-linecap: round;
        stroke-linejoin: round;
    }
    .sidebar-theme-toggle.is-light .sidebar-theme-option-light,
    .sidebar-theme-toggle.is-dark .sidebar-theme-option-dark { color: #ffffff; }
    .sidebar-theme-toggle.is-light .sidebar-theme-option-dark { color: #cbd5e1; }
    .sidebar-theme-toggle.is-dark .sidebar-theme-option-light { color: #8b95a7; }
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
        background: rgba(14, 165, 233, 0.16);
        border-color: rgba(125, 211, 252, 0.22);
    }
    @media (max-width: 1024px) { .sidebar { min-height: auto; } }
</style>
<aside class="sidebar">
    <div class="sidebar-brand">
        <div class="sidebar-logo" style="background:rgba(255,255,255,0.10); padding:4px;">
            <img src="../assets/logo.png" alt="Logo" style="width:38px;height:38px;object-fit:contain;filter:brightness(0) invert(1);">
        </div>
        <div>
            <div class="sidebar-title">ITSR</div>
            <div class="sidebar-subtitle">Staff Work Queue</div>
        </div>
    </div>

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
                <a class="sidebar-logout" href="../login/logout.php?portal=staff">Log out</a>
            </div>
        </div>
    </div>

    <nav class="sidebar-nav">
        <?php foreach ($menuItems as $key => $item): ?>
            <a class="sidebar-link<?= $activePage === $key ? ' is-active' : '' ?>" href="<?= htmlspecialchars($item['href'], ENT_QUOTES, 'UTF-8') ?>">
                <span class="sidebar-link-badge"><?= staffSidebarIconSvg((string) $item['icon']) ?></span>
                <span><?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?></span>
            </a>
        <?php endforeach; ?>
    </nav>

    <div class="sidebar-footer-spacer"></div>
</aside>
<script>
    (function () {
        var body = document.body;
        var toggle = document.getElementById('theme-toggle');
        var storageKey = 'staff-theme';

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
            if (document.body.hasAttribute('inert')) {
                document.body.removeAttribute('inert');
            }
            if (document.documentElement.hasAttribute('inert')) {
                document.documentElement.removeAttribute('inert');
            }

            Array.prototype.forEach.call(document.body.children, function (element) {
                if (element.classList.contains('layout') || element.id === 'confirm-modal' || element.classList.contains('itsr-confirm-modal')) {
                    return;
                }

                var style = window.getComputedStyle(element);
                if (style.position === 'fixed' || style.position === 'absolute') {
                    var rect = element.getBoundingClientRect();
                    var coversScreen = rect.width >= window.innerWidth * 0.85 && rect.height >= window.innerHeight * 0.85;
                    var hasInteractive = element.querySelector('a, button, input, select, textarea, [role="dialog"], [role="menu"]');
                    
                    if (coversScreen && !hasInteractive) {
                        element.style.pointerEvents = 'none';
                        element.style.opacity = '0';
                    }
                }
            });
        }

        document.addEventListener('DOMContentLoaded', runClickSafetyNet);
        window.addEventListener('load', runClickSafetyNet);

        if (typeof MutationObserver !== 'undefined') {
            var safetyNetObserver = new MutationObserver(function () {
                runClickSafetyNet();
            });
            safetyNetObserver.observe(document.body, { childList: true, subtree: true });
        }
    }());
</script>
