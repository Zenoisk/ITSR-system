<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/login/auth.php';
requireDepartment();
require_once dirname(__DIR__) . '/includes/workflow.php';

$department = trim(currentUserDepartment());
$requests = [];
$stats = [
    'total' => 0,
    'returned' => 0,
    'pending' => 0,
    'progress' => 0,
    'completed' => 0,
];
$error = '';

try {
    $pdo = db();
    ensureItsrWorkflowSchema();

    $where = "WHERE sr.department = :department";
    $params = [':department' => $department];

    $statsStmt = $pdo->prepare(
        "SELECT
            COUNT(*) AS total_count,
            SUM(CASE WHEN sr.status = 'returned' THEN 1 ELSE 0 END) AS returned_count,
            SUM(CASE WHEN sr.status IN ('pending', 'resubmitted') THEN 1 ELSE 0 END) AS pending_count,
            SUM(CASE WHEN sr.status IN ('assigned', 'progress', 'reopened') THEN 1 ELSE 0 END) AS progress_count,
            SUM(CASE WHEN sr.status IN ('completed', 'closed') THEN 1 ELSE 0 END) AS completed_count
         FROM service_requests sr
         $where"
    );
    $statsStmt->execute($params);
    $statsRow = $statsStmt->fetch() ?: [];
    $stats = [
        'total'     => (int) ($statsRow['total_count']     ?? 0),
        'returned'  => (int) ($statsRow['returned_count']  ?? 0),
        'pending'   => (int) ($statsRow['pending_count']   ?? 0),
        'progress'  => (int) ($statsRow['progress_count']  ?? 0),
        'completed' => (int) ($statsRow['completed_count'] ?? 0),
    ];

    $stmt = $pdo->prepare(
        "SELECT
            sr.id,
            sr.company,
            sr.department,
            sr.requestor_name,
            sr.requestor_email,
            sr.requestor_phone,
            sr.status,
            sr.assign_to,
            sr.request_date,
            sr.completed_at,
            sr.total_hour_taken_display,
            sr.sla_result,
            sr.latest_user_update_at,
            sr.created_at,
            sr.problem_description,
            sr.pdf_path,
            rr.return_reason,
            rr.missing_document,
            rr.return_comment,
            rr.returned_at,
            rr.resubmitted_at
         FROM service_requests sr
         LEFT JOIN (
            SELECT r1.*
            FROM request_returns r1
            INNER JOIN (
                SELECT request_id, MAX(id) AS latest_id
                FROM request_returns
                GROUP BY request_id
            ) latest ON latest.latest_id = r1.id
         ) rr ON rr.request_id = sr.id
         $where
         ORDER BY CASE WHEN sr.status = 'returned' THEN 0 ELSE 1 END, COALESCE(sr.completed_at, sr.latest_user_update_at, sr.created_at) DESC, sr.id DESC
         LIMIT 100"
    );
    $stmt->execute($params);
    $requests = $stmt->fetchAll();
} catch (Throwable $exception) {
    $error = appErrorMessage($exception, 'Department dashboard failed', 'Unable to load department requests.');
}

$deptLabel = htmlspecialchars($department !== '' ? $department : 'Department', ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $deptLabel ?> Dashboard — ITSR | Enterprise</title>
    <link rel="stylesheet" href="../assets/enterprise-ui.css?v=1.2">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: "Nunito Sans", Inter, "Segoe UI", Roboto, Arial, sans-serif; }

        body {
            background:
                radial-gradient(circle at top left,  rgba(56,  189, 248, 0.08), transparent 28%),
                radial-gradient(circle at bottom right, rgba(14, 165, 233, 0.08), transparent 22%),
                linear-gradient(180deg, #eef5fb 0%, #f7fafd 100%);
            color: #1e293b;
            min-height: 100vh;
        }

        /* ─── TOPNAV ────────────────────────────────────────────────────────── */
        .topnav {
            position: sticky;
            top: 0;
            z-index: 50;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            padding: 0 32px;
            height: 62px;
            background: rgba(255, 255, 255, 0.94);
            border-bottom: 1px solid rgba(207, 221, 235, 0.9);
            backdrop-filter: blur(14px);
            box-shadow: 0 2px 14px rgba(15, 23, 42, 0.05);
        }

        .topnav-brand {
            display: flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
        }

        .brand-logo {
            height: 32px;
            width: auto;
            object-fit: contain;
            flex-shrink: 0;
            filter: drop-shadow(0 2px 6px rgba(37, 99, 235, 0.18));
        }

        .brand-name {
            font-size: 15px;
            font-weight: 800;
            letter-spacing: -0.02em;
            color: #0f172a;
        }

        .brand-sep { color: #cbd5e1; margin: 0 6px; font-weight: 300; }

        .brand-ctx {
            font-size: 14px;
            font-weight: 700;
            color: #2563eb;
        }

        .topnav-actions { display: flex; align-items: center; gap: 10px; }

        .nav-btn {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 9px 17px;
            border-radius: 12px;
            font-size: 13.5px;
            font-weight: 700;
            text-decoration: none;
            border: 1px solid transparent;
            transition: transform 0.16s ease, box-shadow 0.18s ease, background 0.18s ease;
        }
        .nav-btn:hover { transform: translateY(-1px); }
        .nav-btn svg { width: 15px; height: 15px; stroke: currentColor; stroke-width: 2; fill: none; stroke-linecap: round; stroke-linejoin: round; flex-shrink: 0; }

        .nav-btn-ghost { color: #475569; background: transparent; border-color: #e2e8f0; }
        .nav-btn-ghost:hover { background: #f1f5f9; color: #1e293b; }

        .nav-btn-primary {
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            color: #fff;
            box-shadow: 0 8px 20px rgba(37, 99, 235, 0.24);
        }
        .nav-btn-primary:hover { box-shadow: 0 12px 26px rgba(37, 99, 235, 0.34); }

        /* ─── PAGE WRAPPER ──────────────────────────────────────────────────── */
        .page-wrap {
            max-width: 1400px;
            margin: 0 auto;
            padding: 32px 32px 72px;
            display: grid;
            gap: 22px;
            animation: pageEnter 0.3s ease;
        }

        @keyframes pageEnter {
            from { opacity: 0; transform: translateY(12px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        /* ─── HERO ──────────────────────────────────────────────────────────── */
        .hero {
            position: relative;
            background: linear-gradient(135deg, #0f172a 0%, #163b68 50%, #2563eb 100%);
            border-radius: 28px;
            padding: 34px 40px;
            overflow: hidden;
            box-shadow: 0 28px 56px rgba(15, 23, 42, 0.20);
            border: 1px solid rgba(255, 255, 255, 0.06);
        }

        .hero::before {
            content: '';
            position: absolute;
            right: -80px; bottom: -110px;
            width: 360px; height: 360px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(125, 211, 252, 0.24), transparent 60%);
            pointer-events: none;
        }

        .hero::after {
            content: '';
            position: absolute;
            inset: 0;
            background:
                linear-gradient(120deg, rgba(255,255,255,0.07), transparent 36%, transparent 66%, rgba(255,255,255,0.04)),
                radial-gradient(circle at 85% 18%, rgba(125, 211, 252, 0.20), transparent 24%);
            pointer-events: none;
        }

        .hero > * { position: relative; z-index: 1; }

        .hero-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 24px;
            flex-wrap: wrap;
        }

        .hero-eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 14px;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            color: #bae6fd;
        }

        .hero-eyebrow::before {
            content: '';
            width: 8px; height: 8px;
            border-radius: 50%;
            background: #38bdf8;
            box-shadow: 0 0 0 4px rgba(56, 189, 248, 0.24);
            animation: pulse 2.4s ease infinite;
        }

        @keyframes pulse {
            0%, 100% { box-shadow: 0 0 0 4px rgba(56, 189, 248, 0.24); }
            50%       { box-shadow: 0 0 0 8px rgba(56, 189, 248, 0.08); }
        }

        .hero h1 {
            font-size: clamp(26px, 3.2vw, 40px);
            font-weight: 800;
            color: #f8fbff;
            letter-spacing: -0.04em;
            line-height: 1.06;
            margin-bottom: 10px;
        }

        .hero p {
            max-width: 540px;
            font-size: 14.5px;
            line-height: 1.68;
            color: rgba(226, 232, 240, 0.88);
        }

        .hero-btns {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 4px;
        }

        .hero-btn {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 11px 20px;
            border-radius: 14px;
            font-size: 13.5px;
            font-weight: 700;
            text-decoration: none;
            border: 1px solid transparent;
            transition: transform 0.16s ease, opacity 0.16s ease;
            white-space: nowrap;
        }
        .hero-btn:hover { transform: translateY(-1px); opacity: 0.9; }
        .hero-btn svg { width: 14px; height: 14px; stroke: currentColor; stroke-width: 2.2; fill: none; stroke-linecap: round; stroke-linejoin: round; }

        .hero-btn-white { background: #fff; color: #12325b; box-shadow: 0 8px 20px rgba(0,0,0,0.14); }
        .hero-btn-ghost { background: rgba(255,255,255,0.10); border-color: rgba(255,255,255,0.20); color: #dbeafe; }

        /* ─── STAT CARDS ────────────────────────────────────────────────────── */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(5, minmax(0, 1fr));
            gap: 16px;
        }

        .stat-card {
            background: rgba(255, 255, 255, 0.92);
            border: 1px solid rgba(207, 221, 235, 0.92);
            border-radius: 22px;
            padding: 22px 24px;
            box-shadow: 0 18px 38px rgba(15, 23, 42, 0.06);
            backdrop-filter: blur(10px);
            transition: transform 0.18s ease, box-shadow 0.20s ease;
        }
        .stat-card:hover { transform: translateY(-3px); box-shadow: 0 26px 48px rgba(15, 23, 42, 0.10); }

        .stat-card.is-alert {
            background: linear-gradient(155deg, #fff1f2 0%, #ffe4e6 100%);
            border-color: #fecdd3;
        }
        .stat-card.is-alert .stat-value { color: #be123c; }
        .stat-card.is-alert .stat-label { color: #e11d48; }

        .stat-card.is-success {
            background: linear-gradient(155deg, #f0fdf4 0%, #dcfce7 100%);
            border-color: #bbf7d0;
        }
        .stat-card.is-success .stat-value { color: #15803d; }

        .stat-icon {
            width: 40px; height: 40px;
            border-radius: 13px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 16px;
        }
        .stat-icon svg { width: 18px; height: 18px; stroke: currentColor; stroke-width: 2; fill: none; stroke-linecap: round; stroke-linejoin: round; }
        .si-blue   { background: #dbeafe; color: #1d4ed8; }
        .si-amber  { background: #fef3c7; color: #d97706; }
        .si-indigo { background: #e0e7ff; color: #4338ca; }
        .si-red    { background: #fee2e2; color: #b91c1c; }
        .si-green  { background: #dcfce7; color: #15803d; }

        .stat-label {
            font-size: 10.5px;
            font-weight: 800;
            letter-spacing: 0.10em;
            text-transform: uppercase;
            color: #64748b;
            margin-bottom: 7px;
        }

        .stat-value {
            font-size: 38px;
            font-weight: 800;
            color: #0f172a;
            line-height: 1;
            letter-spacing: -0.04em;
        }

        /* ─── TABLE CARD ────────────────────────────────────────────────────── */
        .table-card {
            background: rgba(255, 255, 255, 0.93);
            border: 1px solid rgba(207, 221, 235, 0.92);
            border-radius: 26px;
            box-shadow: 0 20px 42px rgba(15, 23, 42, 0.07);
            backdrop-filter: blur(10px);
            overflow: hidden;
        }

        .table-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 14px;
            padding: 24px 28px;
            border-bottom: 1px solid #e7eef5;
        }

        .table-head h2 {
            font-size: 19px;
            font-weight: 800;
            color: #0f172a;
            letter-spacing: -0.025em;
        }

        .table-head p { font-size: 13px; color: #64748b; margin-top: 3px; }

        .count-badge {
            display: inline-flex;
            align-items: center;
            padding: 5px 14px;
            border-radius: 999px;
            background: #dbeafe;
            color: #1d4ed8;
            font-size: 12.5px;
            font-weight: 800;
        }

        /* Table */
        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }

        th {
            padding: 13px 22px;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.09em;
            text-transform: uppercase;
            color: #53708f;
            background: rgba(247, 250, 253, 0.97);
            border-bottom: 1px solid #e7eef5;
            white-space: nowrap;
        }

        td {
            padding: 16px 22px;
            border-bottom: 1px solid #edf2f7;
            vertical-align: middle;
            font-size: 13.5px;
            color: #1e293b;
        }

        tbody tr:last-child td { border-bottom: none; }
        tbody tr:hover td { background: rgba(241, 247, 255, 0.72); }
        tbody tr { transition: background 0.14s ease; }

        /* Ticket ID chip */
        .ticket-id {
            font-family: "JetBrains Mono", "SF Mono", "Fira Code", "Courier New", monospace;
            font-size: 11.5px;
            font-weight: 700;
            color: #1d4ed8;
            background: #eff6ff;
            padding: 5px 10px;
            border-radius: 8px;
            border: 1px solid #bfdbfe;
            white-space: nowrap;
            letter-spacing: 0.02em;
        }

        /* Requestor */
        .req-name { font-weight: 700; color: #0f172a; font-size: 13.5px; }
        .req-email { font-size: 11.5px; color: #64748b; margin-top: 2px; }

        /* Summary */
        .summary-desc {
            font-size: 12.5px;
            color: #475569;
            max-width: 260px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .returned-notice {
            margin-top: 8px;
            padding: 8px 11px;
            border-radius: 10px;
            background: #fff7ed;
            border: 1px solid #fed7aa;
            font-size: 11.5px;
            color: #9a3412;
            line-height: 1.5;
        }
        .returned-notice strong { color: #c2410c; }

        /* Status Pills */
        .pill {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 5px 12px;
            border-radius: 999px;
            font-size: 11.5px;
            font-weight: 700;
            white-space: nowrap;
        }
        .pill svg { width: 11px; height: 11px; stroke: currentColor; stroke-width: 2.2; fill: none; stroke-linecap: round; stroke-linejoin: round; flex-shrink: 0; }

        .status-pending     { background: #fef9c3; color: #854d0e;  border: 1px solid #fde047; }
        .status-returned    { background: #fee2e2; color: #b91c1c;  border: 1px solid #fca5a5; }
        .status-progress    { background: #dbeafe; color: #1d4ed8;  border: 1px solid #93c5fd; }
        .status-assigned    { background: #e0e7ff; color: #4338ca;  border: 1px solid #a5b4fc; }
        .status-completed   { background: #dcfce7; color: #15803d;  border: 1px solid #86efac; }
        .status-reopened    { background: #f3e8ff; color: #6d28d9;  border: 1px solid #c4b5fd; }
        .status-resubmitted { background: #fdf4ff; color: #86198f;  border: 1px solid #e879f9; }

        /* Assignee */
        .assignee-chip { display: inline-flex; align-items: center; gap: 8px; }
        .assignee-avatar {
            width: 30px; height: 30px;
            border-radius: 50%;
            background: linear-gradient(135deg, #2563eb, #0ea5e9);
            color: #fff;
            font-size: 10.5px;
            font-weight: 800;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            box-shadow: 0 4px 10px rgba(37, 99, 235, 0.24);
        }
        .assignee-name { font-size: 13px; font-weight: 600; color: #1e293b; }
        .unassigned { color: #94a3b8; font-style: italic; font-size: 13px; }

        /* Date */
        .date-text { font-size: 12.5px; color: #64748b; white-space: nowrap; }

        /* Actions */
        .action-links { display: flex; align-items: center; gap: 8px; justify-content: flex-end; flex-wrap: wrap; }

        .act-btn {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 7px 14px;
            border-radius: 10px;
            font-size: 12.5px;
            font-weight: 700;
            text-decoration: none;
            border: 1px solid transparent;
            transition: transform 0.14s ease, box-shadow 0.16s ease, background 0.16s ease;
            white-space: nowrap;
        }
        .act-btn:hover { transform: translateY(-1px); }
        .act-btn svg { width: 13px; height: 13px; stroke: currentColor; stroke-width: 2.2; fill: none; stroke-linecap: round; stroke-linejoin: round; }

        .act-btn-view     { background: #eff6ff; color: #1d4ed8; border-color: #bfdbfe; }
        .act-btn-view:hover { background: #dbeafe; box-shadow: 0 4px 12px rgba(37, 99, 235, 0.14); }

        .act-btn-pdf      { background: #f0fdf4; color: #15803d; border-color: #bbf7d0; }
        .act-btn-pdf:hover { background: #dcfce7; box-shadow: 0 4px 12px rgba(21, 128, 61, 0.12); }

        .act-btn-resubmit { background: #fff7ed; color: #c2410c; border-color: #fed7aa; }
        .act-btn-resubmit:hover { background: #ffedd5; box-shadow: 0 4px 12px rgba(194, 65, 12, 0.12); }

        /* Empty */
        .empty-state { padding: 64px 20px; text-align: center; }
        .empty-icon {
            width: 58px; height: 58px;
            border-radius: 18px;
            background: #f1f5f9;
            border: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 18px;
        }
        .empty-icon svg { width: 24px; height: 24px; stroke: #94a3b8; stroke-width: 1.8; fill: none; stroke-linecap: round; stroke-linejoin: round; }
        .empty-title { font-size: 16.5px; font-weight: 700; color: #334155; margin-bottom: 6px; }
        .empty-sub { font-size: 13.5px; color: #94a3b8; max-width: 380px; margin: 0 auto 20px; line-height: 1.6; }

        /* Error */
        .error-banner {
            background: #fff1f2;
            border: 1px solid #fecdd3;
            border-radius: 16px;
            padding: 16px 22px;
            color: #9f1239;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .error-banner svg { width: 18px; height: 18px; stroke: #e11d48; stroke-width: 2; fill: none; flex-shrink: 0; }

        /* Table foot */
        .table-foot {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 14px 28px;
            border-top: 1px solid #e7eef5;
            font-size: 13px;
            color: #64748b;
            background: rgba(248, 250, 253, 0.82);
        }

        /* Responsive */
        @media (max-width: 1120px) { .stats-grid { grid-template-columns: repeat(3, minmax(0,1fr)); } }
        @media (max-width: 780px) {
            .stats-grid { grid-template-columns: repeat(2, minmax(0,1fr)); }
            .page-wrap { padding: 16px 14px 50px; }
            .topnav { padding: 0 16px; }
            .hero { padding: 24px 22px; }
            .hero-top { flex-direction: column; }
        }
        @media (max-width: 480px) { .stats-grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>

<!-- ▸ TOP NAVIGATION -->
<nav class="topnav">
    <a href="dashboard.php" class="topnav-brand">
        <img src="../assets/logo.png" alt="Logo" class="brand-logo">
        <span class="brand-name">ITSR</span>
        <span class="brand-sep">/</span>
        <span class="brand-ctx"><?= $deptLabel ?></span>
    </a>
    <div class="topnav-actions">
        <a href="../login/logout.php?portal=department" class="nav-btn nav-btn-ghost">
            <svg viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
            Log Out
        </a>
        <a href="../form/request_form.php?return=department" class="nav-btn nav-btn-primary">
            <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            New Request
        </a>
    </div>
</nav>

<div class="page-wrap">

    <!-- ▸ HERO BANNER -->
    <div class="hero">
        <div class="hero-top">
            <div>
                <div class="hero-eyebrow">Department Portal</div>
                <h1><?= $deptLabel ?> Request Tracking</h1>
                <p>Monitor and manage your ITSR submissions. Track live status updates, view assigned staff, and resubmit returned forms all in one place.</p>
            </div>
            <div class="hero-btns">
                <a href="../form/request_form.php?return=department" class="hero-btn hero-btn-white">
                    <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    Submit New Request
                </a>
                <?php if ($stats['returned'] > 0): ?>
                <a href="#requests-table" class="hero-btn hero-btn-ghost">
                    <svg viewBox="0 0 24 24"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                    <?= $stats['returned'] ?> Needs Action
                </a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ▸ STAT CARDS -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon si-blue">
                <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            </div>
            <div class="stat-label">Total Requests</div>
            <div class="stat-value"><?= $stats['total'] ?></div>
        </div>

        <div class="stat-card">
            <div class="stat-icon si-amber">
                <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            </div>
            <div class="stat-label">Pending Review</div>
            <div class="stat-value"><?= $stats['pending'] ?></div>
        </div>

        <div class="stat-card">
            <div class="stat-icon si-indigo">
                <svg viewBox="0 0 24 24"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
            </div>
            <div class="stat-label">In Progress</div>
            <div class="stat-value"><?= $stats['progress'] ?></div>
        </div>

        <div class="stat-card <?= $stats['returned'] > 0 ? 'is-alert' : '' ?>">
            <div class="stat-icon si-red">
                <svg viewBox="0 0 24 24"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
            </div>
            <div class="stat-label">Needs Action</div>
            <div class="stat-value"><?= $stats['returned'] ?></div>
        </div>

        <div class="stat-card <?= $stats['completed'] > 0 ? 'is-success' : '' ?>">
            <div class="stat-icon si-green">
                <svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
            </div>
            <div class="stat-label">Completed</div>
            <div class="stat-value"><?= $stats['completed'] ?></div>
        </div>
    </div>

    <?php if ($error !== ''): ?>
    <div class="error-banner">
        <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
    </div>
    <?php endif; ?>

    <!-- ▸ REQUESTS TABLE -->
    <div class="table-card" id="requests-table">
        <div class="table-head">
            <div>
                <h2>Active Service Requests</h2>
                <p>All submissions for your department — click <strong>Open</strong> to view full details or <strong>Resubmit</strong> for returned requests.</p>
            </div>
            <div class="count-badge"><?= count($requests) ?> Record<?= count($requests) !== 1 ? 's' : '' ?></div>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Ticket #</th>
                        <th>Requestor</th>
                        <th>Summary / Reason</th>
                        <th>Status</th>
                        <th>Assigned To</th>
                        <th>Submitted</th>
                        <th style="text-align:right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($requests as $req): ?>
                    <?php
                        $st = (string)($req['status'] ?? 'pending');
                        $stLabel = [
                            'pending'     => 'Pending',
                            'returned'    => 'Returned',
                            'resubmitted' => 'Resubmitted',
                            'assigned'    => 'Assigned',
                            'progress'    => 'In Progress',
                            'reopened'    => 'Reopened',
                            'completed'   => 'Completed',
                            'closed'      => 'Closed',
                        ][$st] ?? ucfirst($st);

                        $stIcon = [
                            'pending'     => '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>',
                            'returned'    => '<svg viewBox="0 0 24 24"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-3.84"/></svg>',
                            'resubmitted' => '<svg viewBox="0 0 24 24"><path d="M12 19V5"/><path d="m7 10 5-5 5 5"/><path d="M5 19h14"/></svg>',
                            'assigned'    => '<svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>',
                            'progress'    => '<svg viewBox="0 0 24 24"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>',
                            'reopened'    => '<svg viewBox="0 0 24 24"><path d="M21 2v6h-6"/><path d="M3 12a9 9 0 0 1 15-6.7L21 8"/><path d="M3 22v-6h6"/><path d="M21 12a9 9 0 0 1-15 6.7L3 16"/></svg>',
                            'completed'   => '<svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>',
                            'closed'      => '<svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>',
                        ][$st] ?? '';

                        $stCss = [
                            'pending'     => 'status-pending',
                            'returned'    => 'status-returned',
                            'resubmitted' => 'status-resubmitted',
                            'assigned'    => 'status-assigned',
                            'progress'    => 'status-progress',
                            'reopened'    => 'status-reopened',
                            'completed'   => 'status-completed',
                            'closed'      => 'status-completed',
                        ][$st] ?? '';

                        $assignee = trim((string)($req['assign_to'] ?? ''));
                        $initials = '';
                        foreach (explode(' ', $assignee) as $w) { $initials .= mb_strtoupper(mb_substr($w, 0, 1)); }
                        $initials = mb_substr($initials, 0, 2);

                        $pdfPath = trim((string)($req['pdf_path'] ?? ''));
                        $pdfFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'form' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $pdfPath);
                        $hasPdf  = $pdfPath !== '' && is_file($pdfFile);

                        $createdAt = (string)($req['created_at'] ?? '');
                        $ticketYear = $createdAt !== '' ? date('Y', strtotime($createdAt)) : date('Y');
                        $ticketNum  = sprintf('%04d', (int)$req['id']);
                    ?>
                    <tr>
                        <td><span class="ticket-id">ITSR-<?= $ticketYear ?>-<?= $ticketNum ?></span></td>
                        <td>
                            <div class="req-name"><?= htmlspecialchars((string)($req['requestor_name'] ?: '—'), ENT_QUOTES, 'UTF-8') ?></div>
                            <div class="req-email"><?= htmlspecialchars((string)($req['requestor_email'] ?: ''), ENT_QUOTES, 'UTF-8') ?></div>
                        </td>
                        <td>
                            <div class="summary-desc" title="<?= htmlspecialchars((string)($req['problem_description'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                <?= htmlspecialchars((string)($req['company'] ?: '—'), ENT_QUOTES, 'UTF-8') ?>
                                <?php if (trim((string)($req['problem_description'] ?? '')) !== ''): ?>
                                    &mdash; <?= htmlspecialchars(mb_substr(trim((string)$req['problem_description']), 0, 55), ENT_QUOTES, 'UTF-8') ?>…
                                <?php endif; ?>
                            </div>
                            <?php if ($st === 'returned' && trim((string)($req['return_reason'] ?? '')) !== ''): ?>
                                <div class="returned-notice">
                                    <strong>Return Reason:</strong> <?= htmlspecialchars((string)$req['return_reason'], ENT_QUOTES, 'UTF-8') ?>
                                    <?php if (trim((string)($req['return_comment'] ?? '')) !== ''): ?>
                                        <div style="margin-top:3px; color:#9a3412; opacity:0.9;"><?= htmlspecialchars((string)$req['return_comment'], ENT_QUOTES, 'UTF-8') ?></div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td><span class="pill <?= $stCss ?>"><?= $stIcon ?><?= htmlspecialchars($stLabel, ENT_QUOTES, 'UTF-8') ?></span></td>
                        <td>
                            <?php if ($assignee !== ''): ?>
                                <div class="assignee-chip">
                                    <div class="assignee-avatar"><?= htmlspecialchars($initials, ENT_QUOTES, 'UTF-8') ?></div>
                                    <span class="assignee-name"><?= htmlspecialchars($assignee, ENT_QUOTES, 'UTF-8') ?></span>
                                </div>
                            <?php else: ?>
                                <span class="unassigned">Unassigned</span>
                            <?php endif; ?>
                        </td>
                        <td><span class="date-text"><?= $createdAt !== '' ? date('d M Y', strtotime($createdAt)) : '—' ?></span></td>
                        <td>
                            <div class="action-links">
                                <a href="request.php?id=<?= (int)$req['id'] ?>" class="act-btn act-btn-view">
                                    <svg viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                    Open
                                </a>
                                <?php if ($hasPdf): ?>
                                    <a href="../form/download_pdf.php?portal=department&amp;file=<?= rawurlencode(basename($pdfPath)) ?>" target="_blank" rel="noopener" class="act-btn act-btn-pdf">
                                        <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                                        PDF
                                    </a>
                                <?php endif; ?>
                                <?php if ($st === 'returned'): ?>
                                    <a href="../form/request_form.php?id=<?= (int)$req['id'] ?>&return=department" class="act-btn act-btn-resubmit">
                                        <svg viewBox="0 0 24 24"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-3.84"/></svg>
                                        Resubmit
                                    </a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>

                    <?php if ($requests === []): ?>
                    <tr>
                        <td colspan="7">
                            <div class="empty-state">
                                <div class="empty-icon">
                                    <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                                </div>
                                <div class="empty-title">No requests found</div>
                                <div class="empty-sub">Your department has not submitted any IT service requests yet. Get started by clicking the button below.</div>
                                <a href="../form/request_form.php?return=department" class="act-btn act-btn-view" style="display:inline-flex; margin-top:4px;">
                                    <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                                    Submit New Request
                                </a>
                            </div>
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="table-foot">
            <span>Showing <strong><?= count($requests) ?></strong> record<?= count($requests) !== 1 ? 's' : '' ?> for <strong><?= $deptLabel ?></strong></span>
            <span style="font-size:12px; color:#b0bec5;">Enterprise IT Service Request System &bull; <?= date('Y') ?></span>
        </div>
    </div>

</div>

<script>
    // Micro-click feedback
    document.querySelectorAll('.act-btn, .nav-btn, .hero-btn').forEach(function(el) {
        el.addEventListener('mousedown', function() { this.style.transform = 'scale(0.96)'; });
        el.addEventListener('mouseup',   function() { this.style.transform = ''; });
        el.addEventListener('mouseleave',function() { this.style.transform = ''; });
    });
</script>
</body>
</html>
