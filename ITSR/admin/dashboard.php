<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/login/auth.php';
requireAdmin();
require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/includes/workflow.php';

function queryValue(PDO $pdo, string $sql): int
{
    $value = $pdo->query($sql)->fetchColumn();
    return (int) $value;
}

function activityTypeMeta(string $type): array
{
    return match ($type) {
        'request_created' => ['request_created', 'activity-request'],
        'request_updated' => ['request_updated', 'activity-update'],
        'request_deleted' => ['request_deleted', 'activity-delete'],
        'staff_created' => ['staff_event', 'activity-staff'],
        'staff_updated' => ['staff_event', 'activity-staff'],
        'staff_deleted' => ['staff_delete', 'activity-delete'],
        'history_cleared' => ['history_cleared', 'activity-delete'],
        default => ['activity_default', 'activity-default'],
    };
}

function activityIconSvg(string $icon): string
{
    return match ($icon) {
        'request_created' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 4h7l5 5v9.5A1.5 1.5 0 0 1 17.5 20h-10A1.5 1.5 0 0 1 6 18.5v-13A1.5 1.5 0 0 1 7.5 4H7Zm6 1.5V10h4.5"/><path d="M12 12.5v5M9.5 15h5"/></svg>',
        'request_updated' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 6v6l4 2"/><path d="M20 12a8 8 0 1 1-2.34-5.66"/><path d="M20 4v5h-5"/></svg>',
        'request_deleted' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16"/><path d="M9 7V4.8A.8.8 0 0 1 9.8 4h4.4a.8.8 0 0 1 .8.8V7"/><path d="M18 7l-.7 10.2a2 2 0 0 1-2 1.8H8.7a2 2 0 0 1-2-1.8L6 7"/><path d="M10 11.2v4.6M14 11.2v4.6"/></svg>',
        'staff_event' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 11a3 3 0 1 0 0-6 3 3 0 0 0 0 6Zm7 2a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5ZM4.5 18.5a4.5 4.5 0 0 1 9 0M13.5 18.5a3.5 3.5 0 0 1 7 0"/></svg>',
        'staff_delete' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M16 7a3 3 0 1 0-3-3 3 3 0 0 0 3 3ZM8 11a3 3 0 1 0 0-6 3 3 0 0 0 0 6ZM3.5 19a4.5 4.5 0 0 1 9 0"/><path d="M15 15h6M18 12v6"/></svg>',
        'history_cleared' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16"/><path d="M9 7V4.8A.8.8 0 0 1 9.8 4h4.4a.8.8 0 0 1 .8.8V7"/><path d="M18 7l-.7 10.2a2 2 0 0 1-2 1.8H8.7a2 2 0 0 1-2-1.8L6 7"/><path d="M12 10v5"/><path d="M10 12.5h4"/></svg>',
        default => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 8v4l2.5 2.5"/><path d="M12 3.5a8.5 8.5 0 1 0 8.5 8.5"/></svg>',
    };
}

function quickActionIconSvg(string $icon): string
{
    return match ($icon) {
        'unread' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7.5A1.5 1.5 0 0 1 5.5 6h13A1.5 1.5 0 0 1 20 7.5v9A1.5 1.5 0 0 1 18.5 18h-13A1.5 1.5 0 0 1 4 16.5v-9Z"/><path d="m5 8 7 5 7-5"/><path d="M17.5 5.5h3M19 4v3"/></svg>',
        'open' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 4h6v6"/><path d="M20 4 11 13"/><path d="M10 6H6.5A1.5 1.5 0 0 0 5 7.5v10A1.5 1.5 0 0 0 6.5 19h10a1.5 1.5 0 0 0 1.5-1.5V14"/></svg>',
        'staff' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 11a3 3 0 1 0 0-6 3 3 0 0 0 0 6Zm7 2a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5ZM4.5 18.5a4.5 4.5 0 0 1 9 0M13.5 18.5a3.5 3.5 0 0 1 7 0"/></svg>',
        'form' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 4h7l5 5v9.5A1.5 1.5 0 0 1 17.5 20h-10A1.5 1.5 0 0 1 6 18.5v-13A1.5 1.5 0 0 1 7.5 4H7Zm6 1.5V10h4.5"/><path d="M9 13h6M9 16h4"/></svg>',
        'returned' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 14 5 10l4-4"/><path d="M5 10h9a5 5 0 0 1 0 10h-2"/></svg>',
        'resubmitted' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 19V5"/><path d="m7 10 5-5 5 5"/><path d="M5 19h14"/></svg>',
        default => '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="4"/></svg>',
    };
}

$isAdmin = currentUserRole() === 'admin';
$requests = [];
$activities = [];
$staffWorkload = [];
$upcomingHoliday = null;
$sortFilter = trim((string) ($_GET['sort'] ?? 'id_desc'));
$allowedSorts = [
    'id_desc' => 'id DESC',
    'id_asc' => 'id ASC',
    'name_asc' => 'requestor_name ASC',
    'name_desc' => 'requestor_name DESC',
    'date_asc' => 'created_at ASC, id ASC',
    'date_desc' => 'created_at DESC, id DESC',
];
if (!array_key_exists($sortFilter, $allowedSorts)) {
    $sortFilter = 'id_desc';
}
$stats = [
    'total' => 0,
    'today' => 0,
    'unread' => 0,
    'read' => 0,
    'active_staff' => 0,
    'returned' => 0,
    'resubmitted' => 0,
    'unassigned' => 0,
    'completed' => 0,
    'overdue' => 0,
    'due_soon' => 0,
    'mh' => 0,
    'nmh' => 0,
    'urgent' => 0,
    'priority' => 0,
    'normal' => 0,
];
$error = '';

try {
    $pdo = db();
    ensureItsrWorkflowSchema();
    $pdo->exec("UPDATE service_requests SET is_overdue = 1 WHERE due_at IS NOT NULL AND due_at <> '' AND completed_at IS NULL AND status NOT IN ('completed', 'closed') AND due_at < NOW()");
    $pdo->exec("UPDATE request_tasks SET is_overdue = 1 WHERE due_at IS NOT NULL AND due_at <> '' AND completed_at IS NULL AND status NOT IN ('completed', 'closed') AND due_at < NOW()");

    $stats['total'] = queryValue($pdo, 'SELECT COUNT(*) FROM service_requests');
    $stats['today'] = queryValue($pdo, 'SELECT COUNT(*) FROM service_requests WHERE DATE(created_at) = CURDATE()');
    $stats['unread'] = queryValue($pdo, 'SELECT COUNT(*) FROM service_requests WHERE is_read = 0');
    $stats['read'] = queryValue($pdo, 'SELECT COUNT(*) FROM service_requests WHERE is_read = 1');
    $stats['active_staff'] = queryValue($pdo, "SELECT COUNT(*) FROM users WHERE role = 'staff' AND status = 'active'");
    $stats['returned'] = queryValue($pdo, "SELECT COUNT(*) FROM service_requests WHERE status = 'returned'");
    $stats['resubmitted'] = queryValue($pdo, "SELECT COUNT(*) FROM service_requests WHERE status = 'resubmitted'");
    $stats['unassigned'] = queryValue($pdo, "SELECT COUNT(*) FROM service_requests WHERE assign_to IS NULL OR assign_to = ''");
    $stats['completed'] = queryValue($pdo, "SELECT COUNT(*) FROM service_requests WHERE status IN ('completed', 'closed')");
    $stats['pending'] = queryValue($pdo, "SELECT COUNT(*) FROM service_requests WHERE status = 'pending'");
    $stats['progress'] = queryValue($pdo, "SELECT COUNT(*) FROM service_requests WHERE status = 'progress'");
    $stats['overdue'] = queryValue($pdo, "SELECT (
        (SELECT COUNT(*) FROM service_requests WHERE is_overdue = 1 AND status NOT IN ('completed', 'closed')) +
        (SELECT COUNT(*) FROM request_tasks WHERE is_overdue = 1 AND status NOT IN ('completed', 'closed'))
    )");
    $stats['due_soon'] = queryValue($pdo, "SELECT (
        (SELECT COUNT(*) FROM service_requests WHERE due_at IS NOT NULL AND due_at >= NOW() AND due_at <= DATE_ADD(NOW(), INTERVAL 4 HOUR) AND status NOT IN ('completed', 'closed')) +
        (SELECT COUNT(*) FROM request_tasks WHERE due_at IS NOT NULL AND due_at >= NOW() AND due_at <= DATE_ADD(NOW(), INTERVAL 4 HOUR) AND status NOT IN ('completed', 'closed'))
    )");
    $stats['mh'] = queryValue($pdo, "SELECT (
        (SELECT COUNT(*) FROM service_requests WHERE sla_result = 'MH') +
        (SELECT COUNT(*) FROM request_tasks WHERE sla_result = 'MH')
    )");
    $stats['nmh'] = queryValue($pdo, "SELECT (
        (SELECT COUNT(*) FROM service_requests WHERE sla_result = 'NMH') +
        (SELECT COUNT(*) FROM request_tasks WHERE sla_result = 'NMH')
    )");
    $stats['urgent'] = queryValue($pdo, "SELECT (
        (SELECT COUNT(*) FROM service_requests WHERE task_type = 'Urgent') +
        (SELECT COUNT(*) FROM request_tasks WHERE task_type = 'Urgent')
    )");
    $stats['priority'] = queryValue($pdo, "SELECT (
        (SELECT COUNT(*) FROM service_requests WHERE task_type = 'Priority') +
        (SELECT COUNT(*) FROM request_tasks WHERE task_type = 'Priority')
    )");
    $stats['normal'] = queryValue($pdo, "SELECT (
        (SELECT COUNT(*) FROM service_requests WHERE task_type = 'Normal') +
        (SELECT COUNT(*) FROM request_tasks WHERE task_type = 'Normal')
    )");

    $stmt = $pdo->prepare(
        'SELECT
            id,
            requestor_name,
            requestor_email,
            department,
            status,
            is_read,
            assign_to,
            pdf_path,
            created_at
         FROM service_requests
         ORDER BY ' . $allowedSorts[$sortFilter] . '
         LIMIT 8'
    );
    $stmt->execute();
    $requests = $stmt->fetchAll();

    $activityStmt = $pdo->query(
        'SELECT actor_name, actor_role, action_type, title, description, created_at
         FROM activity_logs
         ORDER BY id DESC
         LIMIT 8'
    );
    $activities = $activityStmt->fetchAll();

    $workloadStmt = $pdo->query(
        "SELECT
            u.username,
            COUNT(sr.id) AS total_assigned,
            SUM(CASE WHEN sr.status = 'pending' THEN 1 ELSE 0 END) AS pending_count,
            SUM(CASE WHEN sr.status = 'progress' THEN 1 ELSE 0 END) AS progress_count,
            SUM(CASE WHEN sr.status = 'completed' THEN 1 ELSE 0 END) AS completed_count
         FROM users u
         LEFT JOIN service_requests sr ON sr.assign_to = u.username
         WHERE u.role = 'staff' AND u.status = 'active'
         GROUP BY u.id, u.username
         ORDER BY progress_count DESC, pending_count DESC, total_assigned DESC, u.username ASC
         LIMIT 6"
    );
    $staffWorkload = $workloadStmt->fetchAll();

    $holidayStmt = $pdo->query(
        "SELECT holiday_name, holiday_date
         FROM public_holidays
         WHERE status = 'active' AND holiday_date >= CURDATE()
         ORDER BY holiday_date ASC
         LIMIT 1"
    );
    $upcomingHoliday = $holidayStmt->fetch() ?: null;

    $latestRequestId = (int) $pdo->query('SELECT MAX(id) FROM service_requests')->fetchColumn();
    $latestActivityId = (int) $pdo->query('SELECT MAX(id) FROM activity_logs')->fetchColumn();
    $latestTaskId = 0;
    try {
        $latestTaskId = (int) $pdo->query('SELECT MAX(id) FROM request_tasks')->fetchColumn();
    } catch (Throwable $e) {}
} catch (Throwable $exception) {
    $error = appErrorMessage($exception, 'Admin dashboard failed', 'Unable to load the dashboard.');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - IT Service Request</title>
    <link rel="stylesheet" href="../assets/enterprise-ui.css?v=1.1">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: Inter, "Segoe UI", Roboto, Arial, sans-serif; }
        body {
            background:
                radial-gradient(circle at top left, rgba(56, 189, 248, 0.08), transparent 28%),
                linear-gradient(180deg, var(--ui-bg-top) 0%, var(--ui-bg-bottom) 100%);
            color: var(--ui-text);
            transition: background 0.3s ease, color 0.22s ease;
        }
        .layout { min-height: 100vh; display: grid; grid-template-columns: 300px 1fr; }
        .content { padding: 28px; animation: pageEnter 0.28s ease; }
        .content > * {
            max-width: 1320px;
            margin-left: auto;
            margin-right: auto;
        }
        .hero {
            position: relative;
            background: linear-gradient(135deg, #0f172a 0%, #163b68 52%, #1d4ed8 100%);
            color: #eff6ff;
            border-radius: 28px;
            padding: 30px;
            margin-bottom: 22px;
            overflow: hidden;
            box-shadow: 0 28px 55px rgba(15, 23, 42, 0.18);
        }
        .hero::before {
            content: '';
            position: absolute;
            inset: auto -80px -120px auto;
            width: 320px;
            height: 320px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(125, 211, 252, 0.28), transparent 60%);
        }
        .hero > * { position: relative; z-index: 1; }
        .hero-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 18px;
            flex-wrap: wrap;
        }
        .eyebrow {
            display: inline-block;
            color: #bae6fd;
            font-size: 12px;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            margin-bottom: 12px;
            font-weight: 700;
        }
        .hero h1 { font-size: 36px; margin-bottom: 10px; line-height: 1.05; }
        .hero p { max-width: 720px; line-height: 1.65; color: rgba(226, 232, 240, 0.9); }
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
            transition: transform 0.18s ease, opacity 0.18s ease, background 0.18s ease, box-shadow 0.22s ease, border-color 0.22s ease;
        }
        .btn:hover { transform: translateY(-1px); }
        .btn-primary { background: #fff; color: #12325b; }
        .btn-light { background: rgba(255, 255, 255, 0.08); color: #eff6ff; border-color: rgba(255, 255, 255, 0.14); }
        .stats {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 14px;
            margin-top: 24px;
        }
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 14px;
            margin-bottom: 18px;
        }
        .ops-layout {
            display: grid;
            grid-template-columns: minmax(0, 1.45fr) minmax(340px, 0.95fr);
            gap: 18px;
        }
        .main-stack,
        .side-stack {
            display: grid;
            gap: 18px;
        }
        .quick-action {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 18px;
            border-radius: 22px;
            border: 1px solid rgba(209, 220, 232, 0.92);
            background: rgba(255, 255, 255, 0.88);
            box-shadow: 0 18px 38px rgba(15, 23, 42, 0.08);
            text-decoration: none;
            transition: transform 0.18s ease, box-shadow 0.18s ease;
        }
        .quick-action:hover { transform: translateY(-2px); box-shadow: 0 24px 44px rgba(15, 23, 42, 0.12); }
        .quick-action-badge {
            width: 42px;
            height: 42px;
            border-radius: 14px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            font-weight: 800;
            letter-spacing: 0.08em;
            color: #fff;
            flex-shrink: 0;
        }
        .quick-action-badge svg {
            width: 18px;
            height: 18px;
            stroke: #ffffff;
            stroke-width: 1.9;
            fill: none;
            stroke-linecap: round;
            stroke-linejoin: round;
        }
        .quick-action:nth-child(1) .quick-action-badge { background: linear-gradient(180deg, #3b82f6 0%, #2563eb 100%); }
        .quick-action:nth-child(2) .quick-action-badge { background: linear-gradient(180deg, #22c55e 0%, #16a34a 100%); }
        .quick-action:nth-child(3) .quick-action-badge { background: linear-gradient(180deg, #f97316 0%, #ea580c 100%); }
        .quick-action:nth-child(4) .quick-action-badge { background: linear-gradient(180deg, #a855f7 0%, #9333ea 100%); }
        .quick-action:nth-child(5) .quick-action-badge { background: linear-gradient(180deg, #06b6d4 0%, #0891b2 100%); }
        .quick-action:nth-child(6) .quick-action-badge { background: linear-gradient(180deg, #f59e0b 0%, #d97706 100%); }
        .quick-action strong { display: block; margin-bottom: 4px; color: #102949; font-size: 15px; }
        .quick-action span { color: #72859b; font-size: 13px; line-height: 1.45; }
        .stat {
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.12);
            border-radius: 22px;
            padding: 18px;
            backdrop-filter: blur(8px);
        }
        .stat.is-highlight {
            background: linear-gradient(180deg, rgba(56, 189, 248, 0.18), rgba(255, 255, 255, 0.06));
            border-color: rgba(125, 211, 252, 0.24);
        }
        .stat-label { font-size: 13px; color: rgba(248, 251, 255, 0.84); margin-bottom: 8px; }
        .stat-value { font-size: 34px; font-weight: 800; line-height: 1; color: #f8fbff; }
        .workspace { display: grid; grid-template-columns: 1.55fr 1fr; gap: 18px; }
        .panel {
            background: rgba(255, 255, 255, 0.86);
            border: 1px solid rgba(209, 220, 232, 0.92);
            border-radius: 24px;
            box-shadow: 0 18px 38px rgba(15, 23, 42, 0.08);
            backdrop-filter: blur(10px);
            transition: transform 0.22s ease, box-shadow 0.24s ease, border-color 0.24s ease, background 0.24s ease;
        }
        .panel:hover {
            transform: translateY(-2px);
            box-shadow: 0 24px 44px rgba(15, 23, 42, 0.11);
        }
        .panel-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            padding: 20px 22px;
            border-bottom: 1px solid #e4ebf2;
        }
        .panel-head h2 { font-size: 23px; color: #0f2642; }
        .panel-head p { color: #66788b; font-size: 14px; margin-top: 4px; }
        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th, td {
            padding: 15px 18px;
            border-bottom: 1px solid #e9eef4;
            text-align: left;
            font-size: 14px;
            vertical-align: top;
        }
        th { color: #33506d; font-weight: 700; font-size: 13px; background: rgba(247, 250, 253, 0.95); }
        tbody tr:hover { background: rgba(241, 247, 255, 0.72); }
        .muted { color: #708194; font-size: 13px; margin-top: 4px; }
        .pill {
            display: inline-flex;
            align-items: center;
            padding: 7px 11px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
        }
        .status-pending { background: #fff5dc; color: #9a6a00; }
        .status-returned { background: #fee2e2; color: #b91c1c; }
        .status-resubmitted { background: #f3e8ff; color: #7e22ce; }
        .status-assigned { background: #e0f2fe; color: #0369a1; }
        .status-progress { background: #e9f3ff; color: #1d63b8; }
        .status-completed { background: #eaf8ef; color: #1f7a3f; }
        .status-reopened { background: #f3e8ff; color: #7e22ce; }
        .is-read { background: #eef2f7; color: #64748b; }
        .is-unread { background: #eef4ff; color: #1d63b8; }
        .quick-list { padding: 18px 20px 20px; display: grid; gap: 12px; }
        .quick-item {
            border: 1px solid #e8eef5;
            border-radius: 18px;
            padding: 16px;
            background: linear-gradient(180deg, #fbfdff 0%, #f7fbff 100%);
        }
        .quick-item strong { display: block; font-size: 15px; margin-bottom: 6px; color: #102a45; }
        .quick-item .big { font-size: 30px; font-weight: 800; color: #0f2642; margin-top: 10px; }
        .sub-panel {
            padding: 18px 20px;
            border-top: 1px solid #e4ebf2;
        }
        .sub-panel:first-of-type { border-top: 0; }
        .feed-panel {
            display: flex;
            flex-direction: column;
            min-height: 0;
        }
        .feed-scroll {
            max-height: 720px;
            overflow-y: auto;
            padding-right: 8px;
            scrollbar-width: thin;
            scrollbar-color: rgba(148, 163, 184, 0.68) transparent;
        }
        .feed-scroll::-webkit-scrollbar {
            width: 8px;
        }
        .feed-scroll::-webkit-scrollbar-track {
            background: transparent;
        }
        .feed-scroll::-webkit-scrollbar-thumb {
            background: rgba(148, 163, 184, 0.62);
            border-radius: 999px;
        }
        .feed-scroll::-webkit-scrollbar-thumb:hover {
            background: rgba(100, 116, 139, 0.82);
        }
        .sub-title { font-size: 17px; color: #0f2642; margin-bottom: 6px; }
        .sub-copy { color: #66788b; font-size: 13px; line-height: 1.55; margin-bottom: 14px; }
        .activity-list, .workload-list { display: grid; gap: 12px; }
        .activity-item, .workload-item {
            display: grid;
            grid-template-columns: auto 1fr;
            gap: 12px;
            padding: 14px;
            border-radius: 18px;
            border: 1px solid #e8eef5;
            background: linear-gradient(180deg, #fbfdff 0%, #f7fbff 100%);
        }
        .activity-badge {
            width: 38px;
            height: 38px;
            border-radius: 14px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #fff;
        }
        .activity-badge svg {
            width: 18px;
            height: 18px;
            stroke: currentColor;
            stroke-width: 1.9;
            fill: none;
            stroke-linecap: round;
            stroke-linejoin: round;
        }
        .activity-request { background: linear-gradient(180deg, #2563eb 0%, #1d4ed8 100%); }
        .activity-update { background: linear-gradient(180deg, #0ea5e9 0%, #0284c7 100%); }
        .activity-staff { background: linear-gradient(180deg, #7c3aed 0%, #6d28d9 100%); }
        .activity-delete { background: linear-gradient(180deg, #dc2626 0%, #b91c1c 100%); }
        .activity-default { background: linear-gradient(180deg, #64748b 0%, #475569 100%); }
        .activity-title, .workload-name { display: block; margin-bottom: 4px; color: #102949; font-size: 15px; font-weight: 700; }
        .activity-time { color: #72859b; font-size: 12px; margin-top: 6px; }
        .workload-metrics { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 10px; }
        .metric-chip {
            display: inline-flex;
            align-items: center;
            padding: 6px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
        }
        .metric-chip.is-pending { background: #fff5dc; color: #9a6a00; }
        .metric-chip.is-progress { background: #e9f3ff; color: #1d63b8; }
        .metric-chip.is-completed { background: #eaf8ef; color: #1f7a3f; }
        .pdf-link {
            color: #1f5fbf;
            font-weight: 700;
            text-decoration: none;
        }
        .action-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 34px;
            padding: 0 12px;
            border-radius: 12px;
            border: 1px solid #d7e2ee;
            text-decoration: none;
            font-size: 13px;
            font-weight: 700;
            color: #204a74;
            background: #fff;
            margin-right: 10px;
            margin-bottom: 8px;
        }
        .action-link:last-child { margin-right: 0; }
        .action-link.is-primary {
            color: #fff;
            border-color: transparent;
            background: linear-gradient(180deg, #2563eb 0%, #1d4ed8 100%);
        }
        .error, .empty {
            margin-top: 18px;
            padding: 14px 16px;
            border-radius: 16px;
            font-size: 14px;
        }
        .error { background: #fff1f1; border: 1px solid #ebc7c7; color: #8a1f1f; }
        .empty { background: #f8fbfd; border: 1px solid #dae3ec; color: #617387; }
        .panel-empty {
            margin: 18px;
            max-width: 420px;
            min-height: 108px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            gap: 8px;
            padding: 20px 22px;
        }
        .panel-empty strong {
            color: #102949;
            font-size: 16px;
        }
        .panel-empty span {
            color: #72859b;
            font-size: 14px;
            line-height: 1.55;
        }
        .request-meta {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-top: 10px;
        }
        .priority-high { background: #fee2e2; color: #b91c1c; }
        .priority-medium { background: #fef3c7; color: #a16207; }
        .priority-low { background: #e0f2fe; color: #0369a1; }
        .sla-good { background: #e0f2fe; color: #0369a1; }
        .sla-warning { background: #fef3c7; color: #a16207; }
        .sla-overdue { background: #fee2e2; color: #b91c1c; }
        .sla-closed { background: #dcfce7; color: #15803d; }
        .mini-panel-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px;
        }
        .queue-card {
            border: 1px solid #e8eef5;
            border-radius: 20px;
            padding: 16px;
            background: linear-gradient(180deg, #fbfdff 0%, #f7fbff 100%);
            display: grid;
            gap: 10px;
        }
        .queue-card-head {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 10px;
        }
        .queue-card strong {
            color: #102949;
            font-size: 15px;
        }
        .queue-card-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        .queue-card-actions .action-link {
            margin: 0;
        }
        .compact-table-wrap {
            padding: 0 20px 20px;
        }
        .compact-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0 12px;
        }
        .compact-table thead th {
            background: transparent;
            border: 0;
            padding: 0 16px 4px;
            color: #6f8298;
        }
        .compact-table tbody tr {
            background: linear-gradient(180deg, #fbfdff 0%, #f7fbff 100%);
            box-shadow: inset 0 0 0 1px #e8eef5;
        }
        .compact-table tbody td {
            border-bottom: 0;
            padding: 16px;
        }
        .compact-table tbody td:first-child {
            border-radius: 18px 0 0 18px;
        }
        .compact-table tbody td:last-child {
            border-radius: 0 18px 18px 0;
        }
        .kicker {
            display: inline-flex;
            align-items: center;
            padding: 6px 10px;
            border-radius: 999px;
            background: rgba(37, 99, 235, 0.1);
            color: #1d4ed8;
            font-size: 12px;
            font-weight: 700;
        }
        .workload-summary {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 12px;
        }
        .workload-summary strong {
            color: #102949;
            font-size: 16px;
        }
        .timeline-preview {
            display: grid;
            gap: 12px;
        }
        .timeline-preview-item {
            position: relative;
            padding-left: 22px;
            display: grid;
            gap: 4px;
        }
        .timeline-preview-item::before {
            content: '';
            position: absolute;
            left: 4px;
            top: 4px;
            width: 10px;
            height: 10px;
            border-radius: 999px;
            background: linear-gradient(180deg, #38bdf8 0%, #2563eb 100%);
            box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.12);
        }
        .timeline-preview-item::after {
            content: '';
            position: absolute;
            left: 8px;
            top: 18px;
            bottom: -10px;
            width: 1px;
            background: #d7e2ee;
        }
        .timeline-preview-item:last-child::after {
            display: none;
        }
        .timeline-preview-item strong {
            color: #102949;
            font-size: 14px;
        }
        .timeline-preview-item span,
        .timeline-preview-item small {
            color: #72859b;
            font-size: 13px;
            line-height: 1.5;
        }
        .itsr-drawer[hidden] { display: none; }
        .itsr-drawer {
            position: fixed;
            inset: 0;
            z-index: 1500;
        }
        .itsr-drawer-backdrop {
            position: absolute;
            inset: 0;
            background: rgba(7, 17, 31, 0.56);
            backdrop-filter: blur(4px);
        }
        .itsr-drawer-panel {
            position: absolute;
            top: 0;
            right: 0;
            width: min(860px, 100vw);
            height: 100%;
            background: #f6f9fc;
            box-shadow: -24px 0 48px rgba(15, 23, 42, 0.28);
            display: flex;
            flex-direction: column;
        }
        .itsr-drawer-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            padding: 18px 22px;
            border-bottom: 1px solid #e2e8f0;
            background: rgba(255, 255, 255, 0.9);
        }
        .itsr-drawer-head h3 {
            font-size: 22px;
            color: #102949;
        }
        .itsr-drawer-close {
            min-height: 40px;
            min-width: 40px;
            border-radius: 12px;
            border: 1px solid #d4dde7;
            background: #fff;
            color: #204a74;
            font-size: 18px;
            cursor: pointer;
        }
        .itsr-drawer-frame {
            flex: 1;
            width: 100%;
            border: 0;
            background: #f6f9fc;
        }
        @media (max-width: 1180px) {
            .workspace { grid-template-columns: 1fr; }
            .ops-layout { grid-template-columns: 1fr; }
        }
        @media (max-width: 1024px) {
            .layout { grid-template-columns: 1fr; }
            .content { padding: 18px; }
            .stats { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .quick-actions { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .mini-panel-grid { grid-template-columns: 1fr; }
        }

        body.theme-dark {
            background: linear-gradient(180deg, #07111f 0%, #0b1729 100%);
            color: #e5eef9;
        }
        body.theme-dark .hero {
            background: linear-gradient(135deg, #07111f 0%, #0f2544 52%, #123c8d 100%);
            box-shadow: 0 28px 55px rgba(0, 0, 0, 0.32);
        }
        body.theme-dark .panel {
            background: rgba(12, 21, 36, 0.88);
            border-color: rgba(65, 85, 110, 0.7);
            box-shadow: 0 18px 38px rgba(0, 0, 0, 0.24);
        }
        body.theme-dark .panel-head {
            border-bottom-color: rgba(70, 88, 112, 0.6);
        }
        body.theme-dark .panel-head h2,
        body.theme-dark .quick-item strong,
        body.theme-dark .big {
            color: #f4f8fd;
        }
        body.theme-dark .muted,
        body.theme-dark .panel-head p,
        body.theme-dark .quick-item .muted {
            color: #9eb2c9;
        }
        body.theme-dark .quick-item {
            background: linear-gradient(180deg, #101b2d 0%, #0c1728 100%);
            border-color: rgba(64, 84, 109, 0.65);
        }
        body.theme-dark .quick-action,
        body.theme-dark .activity-item,
        body.theme-dark .workload-item {
            background: linear-gradient(180deg, #101b2d 0%, #0c1728 100%);
            border-color: rgba(64, 84, 109, 0.65);
        }
        body.theme-dark .quick-action strong,
        body.theme-dark .activity-title,
        body.theme-dark .workload-name,
        body.theme-dark .sub-title {
            color: #f4f8fd;
        }
        body.theme-dark .quick-action span,
        body.theme-dark .activity-time,
        body.theme-dark .sub-copy {
            color: #9eb2c9;
        }
        body.theme-dark .sub-panel {
            border-top-color: rgba(70, 88, 112, 0.6);
        }
        body.theme-dark .feed-scroll {
            scrollbar-color: rgba(96, 165, 250, 0.45) transparent;
        }
        body.theme-dark .feed-scroll::-webkit-scrollbar-thumb {
            background: rgba(96, 165, 250, 0.42);
        }
        body.theme-dark .feed-scroll::-webkit-scrollbar-thumb:hover {
            background: rgba(125, 211, 252, 0.62);
        }
        body.theme-dark .metric-chip.is-pending { background: rgba(154, 106, 0, 0.18); color: #fde68a; }
        body.theme-dark .metric-chip.is-progress { background: rgba(29, 99, 184, 0.18); color: #bfdbfe; }
        body.theme-dark .metric-chip.is-completed { background: rgba(31, 122, 63, 0.18); color: #bbf7d0; }
        body.theme-dark .action-link {
            background: rgba(255,255,255,0.04);
            border-color: rgba(148, 163, 184, 0.18);
            color: #eef4fb;
        }
        body.theme-dark .action-link.is-primary {
            background: linear-gradient(180deg, #2563eb 0%, #1d4ed8 100%);
            color: #fff;
        }
        body.theme-dark th {
            background: rgba(20, 31, 49, 0.96);
            color: #cfe0f4;
        }
        body.theme-dark td {
            border-bottom-color: rgba(57, 75, 99, 0.6);
            color: #e8eff8;
        }
        body.theme-dark tbody tr:hover {
            background: rgba(28, 44, 68, 0.64);
        }
        body.theme-dark .btn-primary {
            background: #f8fbff;
            color: #102949;
        }
        body.theme-dark .btn-light {
            background: rgba(255, 255, 255, 0.07);
            color: #eef4fb;
            border-color: rgba(148, 163, 184, 0.18);
        }
        body.theme-dark .empty,
        body.theme-dark .error {
            background: rgba(12, 21, 36, 0.9);
        }
        body.theme-dark .panel-empty strong { color: #f4f8fd; }
        body.theme-dark .panel-empty span { color: #9eb2c9; }
        body.theme-dark .queue-card,
        body.theme-dark .compact-table tbody tr {
            background: linear-gradient(180deg, #101b2d 0%, #0c1728 100%);
            box-shadow: inset 0 0 0 1px rgba(64, 84, 109, 0.65);
        }
        body.theme-dark .queue-card strong,
        body.theme-dark .workload-summary strong,
        body.theme-dark .timeline-preview-item strong,
        body.theme-dark .itsr-drawer-head h3 {
            color: #f4f8fd;
        }
        body.theme-dark .timeline-preview-item::after {
            background: rgba(64, 84, 109, 0.8);
        }
        body.theme-dark .timeline-preview-item span,
        body.theme-dark .timeline-preview-item small {
            color: #9eb2c9;
        }
        body.theme-dark .kicker {
            background: rgba(59, 130, 246, 0.16);
            color: #bfdbfe;
        }
        body.theme-dark .itsr-drawer-panel {
            background: #081220;
        }
        body.theme-dark .itsr-drawer-head {
            background: rgba(12, 21, 36, 0.94);
            border-bottom-color: rgba(65, 85, 110, 0.72);
        }
        body.theme-dark .itsr-drawer-close {
            background: rgba(255, 255, 255, 0.04);
            border-color: rgba(148, 163, 184, 0.18);
            color: #eef4fb;
        }
        .admin-shell {
            display: flex;
            flex-direction: column;
            gap: 24px;
        }
        .admin-header {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 24px;
            align-items: start;
        }
        .admin-title {
            max-width: 760px;
        }
        .admin-title h1 {
            color: #0d2138;
            font-size: clamp(36px, 4.6vw, 60px);
            line-height: 0.98;
            letter-spacing: -0.055em;
            margin-bottom: 14px;
            font-weight: 900;
        }
        .admin-title h1 em {
            color: #2559e8;
            font-style: italic;
        }
        .admin-title p {
            color: #60738c;
            font-size: 17px;
            line-height: 1.7;
            max-width: 720px;
        }
        .top-actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            justify-content: flex-end;
        }
        .modern-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            min-height: 46px;
            padding: 0 18px;
            border-radius: 15px;
            border: 1px solid #cbd9ea;
            background: rgba(255, 255, 255, 0.78);
            color: #17345a;
            text-decoration: none;
            font-weight: 900;
            box-shadow: 0 14px 26px rgba(44, 79, 125, 0.08);
            transition: transform 0.18s ease, box-shadow 0.18s ease, background 0.18s ease;
        }
        .modern-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 18px 32px rgba(44, 79, 125, 0.14);
        }
        .modern-btn.is-primary {
            background: linear-gradient(135deg, #1b65f0, #2855df);
            color: #fff;
            border-color: transparent;
        }
        .bento-grid {
            display: grid;
            grid-template-columns: minmax(320px, 1.15fr) repeat(2, minmax(190px, 0.62fr));
            gap: 18px;
            align-items: stretch;
        }
        .volume-card {
            grid-row: span 2;
            position: relative;
            overflow: hidden;
            border-radius: 30px;
            padding: 30px;
            color: #fff;
            background: linear-gradient(135deg, #0f2744 0%, #194f91 52%, #2958e8 100%);
            box-shadow: 0 28px 55px rgba(31, 74, 155, 0.2);
            min-height: 250px;
        }
        .volume-card::after {
            content: '';
            position: absolute;
            width: 260px;
            height: 260px;
            right: -80px;
            top: -80px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(255,255,255,0.28), transparent 65%);
        }
        .metric-label {
            position: relative;
            z-index: 1;
            color: rgba(255,255,255,0.78);
            font-size: 12px;
            font-weight: 900;
            letter-spacing: 0.09em;
            text-transform: uppercase;
        }
        .volume-value {
            position: relative;
            z-index: 1;
            font-size: clamp(58px, 7vw, 92px);
            line-height: 0.92;
            font-weight: 950;
            letter-spacing: -0.07em;
            margin: 22px 0 20px;
        }
        .volume-meta {
            position: relative;
            z-index: 1;
            display: flex;
            flex-wrap: wrap;
            gap: 9px;
        }
        .volume-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 11px;
            border-radius: 999px;
            background: rgba(255,255,255,0.16);
            color: rgba(255,255,255,0.92);
            font-weight: 900;
            font-size: 12px;
            border: 1px solid rgba(255,255,255,0.18);
        }
        .metric-card {
            position: relative;
            min-height: 116px;
            padding: 20px;
            border-radius: 24px;
            background: rgba(255, 255, 255, 0.82);
            border: 1px solid rgba(197, 213, 232, 0.8);
            box-shadow: 0 18px 34px rgba(39, 68, 105, 0.09);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            overflow: hidden;
        }
        .metric-card::before {
            content: '';
            position: absolute;
            inset: 0;
            background: radial-gradient(circle at top right, rgba(37, 99, 235, 0.1), transparent 45%);
            pointer-events: none;
        }
        .metric-card.is-danger::before {
            background: radial-gradient(circle at top right, rgba(220, 38, 38, 0.11), transparent 45%);
        }
        .metric-card.is-warm::before {
            background: radial-gradient(circle at top right, rgba(245, 158, 11, 0.16), transparent 45%);
        }
        .metric-card small,
        .queue-card p,
        .team-card p {
            color: #61748c;
            line-height: 1.55;
        }
        .metric-card strong {
            position: relative;
            z-index: 1;
            display: block;
            color: #0d2138;
            font-size: 38px;
            line-height: 1;
            font-weight: 950;
            letter-spacing: -0.04em;
        }
        .queue-head {
            display: flex;
            justify-content: space-between;
            align-items: end;
            gap: 18px;
            margin-top: 4px;
        }
        .section-title h2 {
            color: #102746;
            font-size: 26px;
            letter-spacing: -0.035em;
            margin-bottom: 4px;
        }
        .section-title p {
            color: #657991;
            line-height: 1.55;
        }
        .health-card .section-title {
            margin-bottom: 16px;
        }
        .queue-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(220px, 1fr)) minmax(280px, 0.95fr);
            gap: 18px;
        }
        .queue-card {
            display: flex;
            flex-direction: column;
            gap: 16px;
            min-height: 220px;
            padding: 20px;
            border-radius: 28px;
            background: rgba(255,255,255,0.86);
            border: 1px solid rgba(198, 214, 232, 0.9);
            box-shadow: 0 18px 38px rgba(39, 68, 105, 0.09);
            text-decoration: none;
            transition: transform 0.18s ease, box-shadow 0.18s ease, border-color 0.18s ease;
        }
        .queue-card:hover {
            transform: translateY(-4px);
            border-color: rgba(37, 99, 235, 0.4);
            box-shadow: 0 26px 52px rgba(39, 68, 105, 0.14);
        }
        .queue-card-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 14px;
        }
        .queue-icon {
            width: 48px;
            height: 48px;
            flex: 0 0 48px;
            border-radius: 17px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #e6efff;
            color: #245ce8;
        }
        .queue-icon svg {
            width: 24px;
            height: 24px;
            fill: none;
            stroke: currentColor;
            stroke-width: 1.9;
            stroke-linecap: round;
            stroke-linejoin: round;
        }
        .queue-count {
            color: rgba(37, 99, 235, 0.42);
            font-size: 34px;
            line-height: 1;
            font-weight: 950;
        }
        .queue-card strong {
            color: #0f2744;
            display: block;
            font-size: 20px;
            letter-spacing: -0.025em;
        }
        .queue-cta {
            margin-top: auto;
            width: 100%;
            border-radius: 14px;
            padding: 11px 14px;
            background: #edf4ff;
            color: #1f4fd3;
            font-weight: 900;
            text-align: center;
        }
        .queue-card:hover .queue-cta {
            background: #245ce8;
            color: #fff;
        }
        .action-stack {
            display: grid;
            gap: 16px;
            min-width: 0;
        }
        .action-tile {
            display: flex;
            align-items: center;
            gap: 16px;
            padding: 20px;
            border-radius: 24px;
            background: rgba(239, 246, 255, 0.9);
            border: 1px solid rgba(198, 214, 232, 0.85);
            color: #143050;
            text-decoration: none;
            min-height: 112px;
            min-width: 0;
        }
        .action-tile strong {
            display: block;
            color: #0f2744;
            margin-bottom: 4px;
        }
        .dashboard-bottom {
            display: grid;
            grid-template-columns: minmax(0, 1.55fr) minmax(340px, 0.85fr);
            gap: 20px;
            align-items: start;
        }
        .table-card,
        .team-card {
            border-radius: 28px;
            background: rgba(255,255,255,0.9);
            border: 1px solid rgba(198, 214, 232, 0.9);
            box-shadow: 0 18px 38px rgba(39, 68, 105, 0.09);
            overflow: hidden;
        }
        .table-card-head,
        .team-card-head {
            padding: 22px 24px;
            display: flex;
            justify-content: space-between;
            gap: 14px;
            align-items: start;
            border-bottom: 1px solid #e0e9f5;
        }
        .table-card-head h2,
        .team-card-head h2 {
            color: #102746;
            font-size: 24px;
            letter-spacing: -0.035em;
            margin-bottom: 3px;
        }
        .modern-table {
            width: 100%;
            border-collapse: collapse;
        }
        .modern-table th {
            background: #f1f6fd;
            color: #39516f;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.055em;
            padding: 14px 18px;
        }
        .modern-table td {
            padding: 16px 18px;
            border-bottom: 1px solid #e3edf8;
            color: #152b46;
            vertical-align: middle;
        }
        .modern-table tr:hover td {
            background: #f8fbff;
        }
        .team-body {
            padding: 20px;
            display: grid;
            gap: 18px;
        }
        .health-card {
            border-radius: 22px;
            padding: 20px;
            background: #f4f8fd;
            border: 1px solid #dfe9f5;
        }
        .load-row {
            display: grid;
            gap: 10px;
            margin-top: 14px;
        }
        .load-line {
            display: flex;
            justify-content: space-between;
            color: #425a78;
            font-size: 13px;
            font-weight: 800;
        }
        .load-track {
            height: 10px;
            overflow: hidden;
            border-radius: 999px;
            background: #dce8f6;
        }
        .load-track span {
            display: block;
            height: 100%;
            border-radius: inherit;
            background: linear-gradient(90deg, #1d6ff2, #35b7e8);
        }
        .holiday-card {
            display: flex;
            gap: 14px;
            align-items: center;
            border-radius: 22px;
            padding: 16px;
            background: linear-gradient(135deg, #fff7ed, #fff);
            border: 1px solid #fed7aa;
        }
        .holiday-date {
            min-width: 54px;
            padding: 10px 8px;
            text-align: center;
            border-radius: 16px;
            background: #ffedd5;
            color: #c2410c;
            font-weight: 950;
        }
        .holiday-date small {
            display: block;
            font-size: 10px;
            letter-spacing: 0.08em;
        }
        .ops-feed-mini {
            max-height: 300px;
            overflow: auto;
            padding-right: 4px;
            margin-right: 0;
            scrollbar-width: thin;
            scrollbar-color: rgba(37, 99, 235, 0.32) transparent;
            scrollbar-gutter: stable;
        }
        .ops-feed-mini::-webkit-scrollbar {
            width: 8px;
        }
        .ops-feed-mini::-webkit-scrollbar-button {
            display: none;
            width: 0;
            height: 0;
        }
        .ops-feed-mini::-webkit-scrollbar-track {
            background: transparent;
            border-radius: 999px;
        }
        .ops-feed-mini::-webkit-scrollbar-thumb {
            background: linear-gradient(180deg, rgba(37, 99, 235, 0.42), rgba(14, 165, 233, 0.34));
            border: 2px solid transparent;
            background-clip: padding-box;
            border-radius: 999px;
        }
        .ops-feed-mini::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(180deg, rgba(37, 99, 235, 0.62), rgba(14, 165, 233, 0.52));
            border: 2px solid transparent;
            background-clip: padding-box;
        }
        .ops-feed-mini .activity-item,
        .workload-compact .workload-item {
            background: #f8fbff;
            border-color: #e0eaf5;
            gap: 16px;
        }
        .ops-feed-mini .activity-item {
            padding: 16px;
            align-items: start;
            margin-right: 20px;
        }
        .ops-feed-mini .activity-item > div {
            padding-right: 6px;
        }
        .ops-feed-mini .activity-item + .activity-item {
            margin-top: 14px;
        }
        .ops-feed-mini .activity-title {
            display: block;
            margin-bottom: 6px;
            line-height: 1.3;
        }
        .ops-feed-mini .muted {
            display: block;
            margin-bottom: 7px;
            line-height: 1.45;
        }
        .ops-feed-mini .activity-time {
            display: block;
            margin-top: 4px;
            line-height: 1.35;
        }
        .workload-compact {
            display: grid;
            gap: 12px;
            margin-top: 12px;
        }
        .workload-compact .workload-item {
            align-items: center;
            padding: 16px;
            border-radius: 18px;
        }
        .activity-badge {
            flex: 0 0 auto;
        }
        body.theme-dark .admin-title h1,
        body.theme-dark .section-title h2,
        body.theme-dark .table-card-head h2,
        body.theme-dark .team-card-head h2,
        body.theme-dark .queue-card strong,
        body.theme-dark .action-tile strong,
        body.theme-dark .metric-card strong {
            color: #f4f8fd;
        }
        body.theme-dark .admin-title p,
        body.theme-dark .section-title p,
        body.theme-dark .metric-card small,
        body.theme-dark .queue-card p,
        body.theme-dark .team-card p {
            color: #9eb2c9;
        }
        body.theme-dark .metric-card,
        body.theme-dark .queue-card,
        body.theme-dark .table-card,
        body.theme-dark .team-card {
            background: rgba(12, 21, 36, 0.9);
            border-color: rgba(65, 85, 110, 0.72);
            box-shadow: 0 18px 38px rgba(0,0,0,0.24);
        }
        body.theme-dark .action-tile,
        body.theme-dark .health-card,
        body.theme-dark .ops-feed-mini .activity-item,
        body.theme-dark .workload-compact .workload-item {
            background: rgba(16, 27, 45, 0.92);
            border-color: rgba(65, 85, 110, 0.72);
            color: #dce8f6;
        }
        body.theme-dark .modern-btn {
            background: rgba(255,255,255,0.06);
            color: #eaf2fb;
            border-color: rgba(148, 163, 184, 0.2);
        }
        body.theme-dark .modern-table th {
            background: #101b2d;
            color: #c6d7eb;
        }
        body.theme-dark .modern-table td {
            color: #e8eff8;
            border-bottom-color: rgba(65, 85, 110, 0.55);
        }
        body.theme-dark .modern-table tr:hover td {
            background: rgba(20, 34, 56, 0.82);
        }
        @media (max-width: 1180px) {
            .admin-header,
            .dashboard-bottom {
                grid-template-columns: 1fr;
            }
            .top-actions {
                justify-content: flex-start;
            }
            .bento-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
            .volume-card {
                grid-column: 1 / -1;
                grid-row: auto;
            }
            .queue-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }
        @media (max-width: 720px) {
            .bento-grid,
            .queue-grid {
                grid-template-columns: 1fr;
            }
            .modern-table {
                min-width: 760px;
            }
            .admin-title h1 {
                font-size: 36px;
            }
        }
        @keyframes pageEnter {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        @media (prefers-reduced-motion: reduce) {
            .content,
            .btn,
            .quick-action,
            .panel {
                animation: none !important;
                transition: none !important;
            }
        }
        
        /* Visual Analytics Styles */
        .analytics-section {
            margin-top: 22px;
            margin-bottom: 22px;
        }
        .analytics-card {
            background: #ffffff;
            border: 1px solid #e3edf8;
            border-radius: 28px;
            padding: 24px;
            box-shadow: 0 16px 36px rgba(37, 99, 235, 0.04);
        }
        .analytics-card-head {
            margin-bottom: 24px;
        }
        .analytics-card-head h2 {
            font-size: 20px;
            font-weight: 800;
            color: #152b46;
            margin-bottom: 4px;
        }
        .analytics-card-head p {
            font-size: 13.5px;
            color: #5d7891;
        }
        .analytics-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 24px;
        }
        .chart-container {
            background: #f8fbff;
            border: 1px solid #e2effd;
            border-radius: 20px;
            padding: 20px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: flex-start;
            min-height: 280px;
        }
        .chart-container h3 {
            font-size: 14px;
            font-weight: 700;
            color: #304f70;
            margin-bottom: 16px;
            text-align: center;
            width: 100%;
        }
        .chart-container-inner {
            position: relative;
            width: 100%;
            height: 200px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        body.theme-dark .analytics-card {
            background: rgba(12, 21, 36, 0.9);
            border-color: rgba(65, 85, 110, 0.72);
            box-shadow: 0 18px 38px rgba(0,0,0,0.24);
        }
        body.theme-dark .analytics-card-head h2 {
            color: #f4f8fd;
        }
        body.theme-dark .analytics-card-head p {
            color: #9eb2c9;
        }
        body.theme-dark .chart-container {
            background: rgba(16, 27, 45, 0.92);
            border-color: rgba(65, 85, 110, 0.72);
        }
        body.theme-dark .chart-container h3 {
            color: #c6d7eb;
        }
        @media (max-width: 960px) {
            .analytics-grid {
                grid-template-columns: 1fr;
            }
        }

        /* Custom Dropdown Select Component */
        .custom-select { position: relative; z-index: 20; }
        .custom-select-input { display: none; }
        .custom-select-toggle {
            width: 100%;
            min-height: 48px;
            border: 1px solid var(--ui-border-strong, #d4dde7);
            border-radius: 16px;
            padding: 0 44px 0 14px;
            font-size: 14px;
            font-weight: 600;
            text-align: left;
            background: #fff;
            color: #1f3652;
            cursor: pointer;
            position: relative;
            transition: border-color 0.18s ease, box-shadow 0.18s ease;
        }
        .custom-select-toggle:hover {
            border-color: #b7c7d9;
        }
        .custom-select-toggle:focus {
            outline: none;
            border-color: #2c71ba;
            box-shadow: 0 0 0 4px rgba(44, 113, 186, 0.10);
        }
        .custom-select-toggle::after {
            content: '';
            position: absolute;
            top: 50%;
            right: 16px;
            width: 9px;
            height: 9px;
            border-right: 2px solid #6a7c91;
            border-bottom: 2px solid #6a7c91;
            transform: translateY(-65%) rotate(45deg);
            transition: transform 0.18s ease;
        }
        .custom-select.is-open .custom-select-toggle::after {
            transform: translateY(-30%) rotate(-135deg);
        }
        .custom-select-menu {
            position: absolute;
            top: calc(100% + 8px);
            left: 0;
            right: 0;
            z-index: 40;
            padding: 8px;
            border-radius: 18px;
            border: 1px solid var(--ui-border-strong, #d8e3ef);
            background: #ffffff;
            box-shadow: 0 22px 40px rgba(15, 23, 42, 0.14);
            display: none;
            max-height: 190px;
            overflow-y: auto;
            scrollbar-width: thin;
        }
        .custom-select.is-open .custom-select-menu {
            display: block;
        }
        .custom-select-option {
            width: 100%;
            border: 0;
            background: transparent;
            border-radius: 12px;
            padding: 11px 12px;
            font-size: 14px;
            text-align: left;
            color: #1f3652;
            cursor: pointer;
            transition: background 0.18s ease, color 0.18s ease;
        }
        .custom-select-option:hover,
        .custom-select-option.is-selected {
            background: #dbeafe;
            color: #1d4ed8;
        }
        .custom-select-menu::-webkit-scrollbar {
            width: 8px;
        }
        .custom-select-menu::-webkit-scrollbar-track {
            background: transparent;
        }
        .custom-select-menu::-webkit-scrollbar-thumb {
            background: rgba(148, 163, 184, 0.6);
            border-radius: 999px;
        }

        /* Dark theme overrides */
        body.theme-dark .custom-select-toggle {
            background: #0f1a2d;
            border-color: rgba(78, 97, 121, 0.7);
            color: #eef4fb;
        }
        body.theme-dark .custom-select-toggle::after {
            border-color: #9eb2c9;
        }
        body.theme-dark .custom-select-menu {
            background: #0f1a2d;
            border-color: rgba(78, 97, 121, 0.7);
            box-shadow: 0 24px 44px rgba(0, 0, 0, 0.32);
        }
        body.theme-dark .custom-select-option {
            color: #e5eef9;
        }
        body.theme-dark .custom-select-option:hover,
        body.theme-dark .custom-select-option.is-selected {
            background: #14375c;
            color: #8fd4ff;
        }
    </style>
</head>
<body>
    <div class="layout">
        <?php $activePage = 'dashboard'; require __DIR__ . '/sidebar.php'; ?>

        <main class="content">
            <div class="admin-shell">
                <header class="admin-header">
                    <div class="admin-title">
                        <h1>Run the request queue from one <em>calm</em> workspace.</h1>
                        <p>Keep an eye on unread forms, unassigned work, overdue SLA tasks, and the latest customer submissions without losing the bigger operational picture.</p>
                    </div>
                    <div class="top-actions">
                        <a class="modern-btn" href="requests.php">All Requests</a>
                        <?php if ($isAdmin): ?>
                            <a class="modern-btn" href="staff.php">Manage Staff</a>
                        <?php endif; ?>
                        <a class="modern-btn is-primary" href="../form/request_form.php?return=admin">Customer Form</a>
                    </div>
                </header>

                <?php if ($error !== ''): ?>
                    <div class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
                <?php else: ?>
                    <section class="bento-grid" aria-label="Dashboard summary">
                        <div class="volume-card">
                            <div class="metric-label">Total Request Volume</div>
                            <div class="volume-value"><?= $stats['total'] ?></div>
                            <div class="volume-meta">
                                <span class="volume-chip"><?= $stats['today'] ?> submitted today</span>
                                <span class="volume-chip"><?= $stats['unread'] ?> unread</span>
                                <span class="volume-chip"><?= $stats['active_staff'] ?> active staff</span>
                            </div>
                        </div>
                        <div class="metric-card">
                            <small>Submitted Today</small>
                            <strong><?= $stats['today'] ?></strong>
                        </div>
                        <div class="metric-card is-danger">
                            <small>Overdue Tasks</small>
                            <strong><?= $stats['overdue'] ?></strong>
                        </div>
                        <div class="metric-card is-warm">
                            <small>Unassigned</small>
                            <strong><?= $stats['unassigned'] ?></strong>
                        </div>
                        <div class="metric-card">
                            <small>Active Staff</small>
                            <strong><?= $stats['active_staff'] ?></strong>
                        </div>
                    </section>

                    <section>
                        <div class="queue-head">
                            <div class="section-title">
                                <h2>Pending Queues</h2>
                                <p>Prioritize these items first so requests do not quietly sit in the system.</p>
                            </div>
                            <a class="pdf-link" href="requests.php">View All Queues</a>
                        </div>
                    </section>

                    <section class="queue-grid" aria-label="Priority queues">
                        <a class="queue-card" href="requests.php?read=unread">
                            <span class="queue-card-top">
                                <span class="queue-icon"><?= quickActionIconSvg('unread') ?></span>
                                <span class="queue-count"><?= $stats['unread'] ?></span>
                            </span>
                            <span>
                                <strong>Unread Queue</strong>
                                <p>Open forms that still need first review and triage.</p>
                            </span>
                            <span class="queue-cta">Handle Now</span>
                        </a>
                        <a class="queue-card" href="requests.php?assignment=unassigned">
                            <span class="queue-card-top">
                                <span class="queue-icon"><?= quickActionIconSvg('open') ?></span>
                                <span class="queue-count"><?= $stats['unassigned'] ?></span>
                            </span>
                            <span>
                                <strong>Unassigned</strong>
                                <p>Find requests that still need a staff owner.</p>
                            </span>
                            <span class="queue-cta">Assign Team</span>
                        </a>
                        <a class="queue-card" href="requests.php?status=returned">
                            <span class="queue-card-top">
                                <span class="queue-icon"><?= quickActionIconSvg('returned') ?></span>
                                <span class="queue-count"><?= $stats['returned'] ?></span>
                            </span>
                            <span>
                                <strong>Returned</strong>
                                <p>Review forms sent back for missing documents or details.</p>
                            </span>
                            <span class="queue-cta">Review Returns</span>
                        </a>
                        <div class="action-stack">
                            <a class="action-tile" href="staff.php">
                                <span class="queue-icon"><?= quickActionIconSvg('staff') ?></span>
                                <span>
                                    <strong>Manage Staff</strong>
                                    <p>Update team accounts, access, and capacity.</p>
                                </span>
                            </a>
                            <a class="action-tile" href="../form/request_form.php?return=admin">
                                <span class="queue-icon"><?= quickActionIconSvg('form') ?></span>
                                <span>
                                    <strong>Customer Form</strong>
                                    <p>Submit a request on behalf of a superior or requestor.</p>
                                </span>
                            </a>
                        </div>
                    </section>

                    <section class="analytics-section"
                             data-pending="<?= (int)$stats['pending'] ?>"
                             data-progress="<?= (int)$stats['progress'] ?>"
                             data-returned="<?= (int)$stats['returned'] ?>"
                             data-completed="<?= (int)$stats['completed'] ?>"
                             data-urgent="<?= (int)$stats['urgent'] ?>"
                             data-priority="<?= (int)$stats['priority'] ?>"
                             data-normal="<?= (int)$stats['normal'] ?>"
                             data-unread="<?= (int)$stats['unread'] ?>"
                             data-unassigned="<?= (int)$stats['unassigned'] ?>"
                             data-workload='<?= htmlspecialchars(json_encode($staffWorkload, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>'>
                        <div class="analytics-card">
                            <div class="analytics-card-head">
                                <h2>Operations Visual Analytics</h2>
                                <p>Real-time request distribution, task urgency levels, and workload split.</p>
                            </div>
                            <div class="analytics-grid">
                                <div class="chart-container">
                                    <h3>Request Status Breakdown</h3>
                                    <div class="chart-container-inner">
                                        <canvas id="statusChart"></canvas>
                                    </div>
                                </div>
                                <div class="chart-container">
                                    <h3>Task Priority Split</h3>
                                    <div class="chart-container-inner">
                                        <canvas id="priorityChart"></canvas>
                                    </div>
                                </div>
                                <div class="chart-container">
                                    <h3>Staff Workload Split</h3>
                                    <div class="chart-container-inner">
                                        <canvas id="workloadChart"></canvas>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section class="dashboard-bottom">
                        <div class="table-card" style="position: relative; overflow: visible;">
                            <div class="table-card-head" style="align-items: center;">
                                <div>
                                    <h2>Recent Requests</h2>
                                    <p>Latest submissions with quick open and PDF actions.</p>
                                </div>
                                <div style="display: flex; gap: 12px; align-items: center; z-index: 100;">
                                    <div class="custom-select" data-select data-instant-sort style="min-width: 195px;">
                                        <input class="custom-select-input" type="hidden" name="sort" value="<?= htmlspecialchars($sortFilter, ENT_QUOTES, 'UTF-8') ?>">
                                        <button class="custom-select-toggle" type="button" data-select-toggle>
                                            <?php
                                            echo match ($sortFilter) {
                                                'id_asc' => 'ID (Oldest First)',
                                                'name_asc' => 'Name (A-Z)',
                                                'name_desc' => 'Name (Z-A)',
                                                'date_asc' => 'Date (Oldest First)',
                                                'date_desc' => 'Date (Newest First)',
                                                default => 'ID (Newest First)',
                                            };
                                            ?>
                                        </button>
                                        <div class="custom-select-menu">
                                            <button class="custom-select-option<?= $sortFilter === 'id_desc' ? ' is-selected' : '' ?>" type="button" data-value="id_desc">ID (Newest First)</button>
                                            <button class="custom-select-option<?= $sortFilter === 'id_asc' ? ' is-selected' : '' ?>" type="button" data-value="id_asc">ID (Oldest First)</button>
                                            <button class="custom-select-option<?= $sortFilter === 'name_asc' ? ' is-selected' : '' ?>" type="button" data-value="name_asc">Name (A-Z)</button>
                                            <button class="custom-select-option<?= $sortFilter === 'name_desc' ? ' is-selected' : '' ?>" type="button" data-value="name_desc">Name (Z-A)</button>
                                            <button class="custom-select-option<?= $sortFilter === 'date_desc' ? ' is-selected' : '' ?>" type="button" data-value="date_desc">Date (Newest First)</button>
                                            <button class="custom-select-option<?= $sortFilter === 'date_asc' ? ' is-selected' : '' ?>" type="button" data-value="date_asc">Date (Oldest First)</button>
                                        </div>
                                    </div>
                                    <a class="pdf-link" href="requests.php" style="margin-top: 0;">View All</a>
                                </div>
                            </div>
                            <?php if (!$requests): ?>
                                <div class="empty panel-empty">
                                    <strong>No requests found yet</strong>
                                    <span>Once customers start submitting forms, the latest requests will appear here.</span>
                                </div>
                            <?php else: ?>
                                <div class="table-wrap">
                                    <table class="modern-table">
                                        <thead>
                                            <tr>
                                                <th>Request ID</th>
                                                <th>Requestor</th>
                                                <th>Assigned To</th>
                                                <th>Status</th>
                                                <th>Read</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($requests as $request): ?>
                                                <tr>
                                                    <td><strong>#<?= (int) $request['id'] ?></strong></td>
                                                    <td>
                                                        <strong><?= htmlspecialchars((string) $request['requestor_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                                        <div class="muted"><?= htmlspecialchars((string) $request['department'], ENT_QUOTES, 'UTF-8') ?></div>
                                                        <div class="muted"><?= htmlspecialchars((string) $request['requestor_email'], ENT_QUOTES, 'UTF-8') ?></div>
                                                    </td>
                                                    <td><?= htmlspecialchars((string) $request['assign_to'], ENT_QUOTES, 'UTF-8') ?: 'Unassigned' ?></td>
                                                    <td><span class="pill <?= htmlspecialchars(requestStatusClass((string) ($request['status'] ?? 'pending')), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(requestStatusLabel((string) ($request['status'] ?? 'pending')), ENT_QUOTES, 'UTF-8') ?></span></td>
                                                    <td><span class="pill <?= ((int) ($request['is_read'] ?? 0) === 1) ? 'is-read' : 'is-unread' ?>"><?= ((int) ($request['is_read'] ?? 0) === 1) ? 'Read' : 'Unread' ?></span></td>
                                                    <td>
                                                        <a class="action-link is-primary" href="request_edit.php?id=<?= (int) $request['id'] ?>">Open</a>
                                                        <?= workflowGetPdfLinkHtml((string) $request['pdf_path'], 'action-link', 'PDF', '../') ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>

                        <aside class="team-card">
                            <div class="team-card-head">
                                <div>
                                    <h2>Team Health</h2>
                                    <p>Workload, activity, and upcoming availability in one place.</p>
                                </div>
                            </div>
                            <div class="team-body">
                                <div class="health-card">
                                    <div class="load-line">
                                        <span>Staff Online</span>
                                        <strong><?= $stats['active_staff'] ?> active</strong>
                                    </div>
                                    <?php if (!$staffWorkload): ?>
                                        <div class="empty">No active staff accounts found.</div>
                                    <?php else: ?>
                                        <div class="workload-compact">
                                            <?php foreach ($staffWorkload as $staff): ?>
                                                <?php $loadPercent = min(100, ((int) ($staff['total_assigned'] ?? 0)) * 20); ?>
                                                <div class="workload-item">
                                                    <span class="activity-badge activity-staff"><?= activityIconSvg('staff_event') ?></span>
                                                    <div style="width:100%;">
                                                        <span class="workload-name"><?= htmlspecialchars((string) ($staff['username'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                                                        <div class="load-row">
                                                            <div class="load-line">
                                                                <span><?= (int) ($staff['total_assigned'] ?? 0) ?> assigned</span>
                                                                <span><?= $loadPercent ?>% load</span>
                                                            </div>
                                                            <div class="load-track"><span style="width: <?= $loadPercent ?>%;"></span></div>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <div class="holiday-card">
                                    <?php if ($upcomingHoliday): ?>
                                        <?php $holidayTime = strtotime((string) $upcomingHoliday['holiday_date']); ?>
                                        <span class="holiday-date">
                                            <small><?= htmlspecialchars(strtoupper(date('M', $holidayTime)), ENT_QUOTES, 'UTF-8') ?></small>
                                            <?= htmlspecialchars(date('d', $holidayTime), ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                        <span>
                                            <strong><?= htmlspecialchars((string) $upcomingHoliday['holiday_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                            <p>Upcoming public holiday. SLA timer will skip active holidays.</p>
                                        </span>
                                    <?php else: ?>
                                         <span class="holiday-date">
                                             <small>SLA</small>
                                             <span style="display: inline-flex; align-items: center; justify-content: center; gap: 3px; font-size: 14px; margin-top: 2px; line-height: 1;">
                                                 <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink: 0;"><path d="M20 6 9 17 4 12"/></svg>
                                                 <span>OK</span>
                                             </span>
                                         </span>
                                        <span>
                                            <strong>No upcoming holiday set</strong>
                                            <p>Add public holidays so SLA calculations stay accurate.</p>
                                        </span>
                                    <?php endif; ?>
                                </div>

                                <div class="health-card">
                                    <div class="section-title">
                                        <h2 style="font-size:18px;margin-bottom:2px;">Recent Activity</h2>
                                        <p>Latest request and account movement.</p>
                                    </div>
                                    <div class="ops-feed-mini">
                                        <?php if (!$activities): ?>
                                            <div class="empty">No activity logged yet.</div>
                                        <?php else: ?>
                                            <?php foreach ($activities as $activity): ?>
                                                <?php [$activityIcon, $activityClass] = activityTypeMeta((string) ($activity['action_type'] ?? '')); ?>
                                                <div class="activity-item">
                                                    <span class="activity-badge <?= htmlspecialchars($activityClass, ENT_QUOTES, 'UTF-8') ?>"><?= activityIconSvg($activityIcon) ?></span>
                                                    <div>
                                                        <span class="activity-title"><?= htmlspecialchars((string) ($activity['title'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                                                        <div class="muted"><?= htmlspecialchars((string) ($activity['description'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                                                        <div class="activity-time"><?= htmlspecialchars((string) ($activity['created_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </aside>
                    </section>
                <?php endif; ?>
            </div>
        </main>
    </div>
    <script>
        (function () {
            // Inject Toast CSS
            var style = document.createElement('style');
            style.textContent = '\
                .toast-notification {\
                    position: fixed;\
                    bottom: 24px;\
                    right: 24px;\
                    background: linear-gradient(135deg, #1e293b, #0f172a);\
                    color: #fff;\
                    padding: 16px 20px;\
                    border-radius: 18px;\
                    box-shadow: 0 20px 42px rgba(0,0,0,0.32);\
                    display: flex;\
                    align-items: center;\
                    gap: 12px;\
                    z-index: 9999;\
                    transform: translateY(100px);\
                    opacity: 0;\
                    transition: transform 0.4s cubic-bezier(0.16, 1, 0.3, 1), opacity 0.4s ease;\
                    border: 1px solid rgba(255,255,255,0.08);\
                    font-family: Inter, sans-serif;\
                    font-size: 14px;\
                    font-weight: 600;\
                }\
                .toast-notification.is-visible {\
                    transform: translateY(0);\
                    opacity: 1;\
                }\
                .toast-notification .toast-spinner {\
                    width: 18px;\
                    height: 18px;\
                    border: 2px dashed rgba(255,255,255,0.2);\
                    border-top-color: #38bdf8;\
                    border-radius: 50%;\
                    animation: toast-spin 0.8s linear infinite;\
                }\
                @keyframes toast-spin {\
                    to { transform: rotate(360deg); }\
                }\
                .flash-highlight {\
                    animation: flash-animation 1.5s ease-out;\
                }\
                @keyframes flash-animation {\
                    0% { background-color: rgba(56, 189, 248, 0.25); }\
                    100% { background-color: transparent; }\
                }\
            ';
            document.head.appendChild(style);

            // Initial Tracking IDs
            var currentRequestMaxId = <?= (int) ($latestRequestId ?? 0) ?>;
            var currentActivityMaxId = <?= (int) ($latestActivityId ?? 0) ?>;
            var currentTaskMaxId = <?= (int) ($latestTaskId ?? 0) ?>;
            var isUpdating = false;

            // Chart instances tracking
            var charts = {
                status: null,
                priority: null,
                workload: null
            };

            function getAnalyticsData() {
                var el = document.querySelector('.analytics-section');
                if (!el) return null;

                try {
                    return {
                        pending: parseInt(el.getAttribute('data-pending') || 0, 10),
                        progress: parseInt(el.getAttribute('data-progress') || 0, 10),
                        returned: parseInt(el.getAttribute('data-returned') || 0, 10),
                        completed: parseInt(el.getAttribute('data-completed') || 0, 10),
                        urgent: parseInt(el.getAttribute('data-urgent') || 0, 10),
                        priority: parseInt(el.getAttribute('data-priority') || 0, 10),
                        normal: parseInt(el.getAttribute('data-normal') || 0, 10),
                        unread: parseInt(el.getAttribute('data-unread') || 0, 10),
                        unassigned: parseInt(el.getAttribute('data-unassigned') || 0, 10),
                        workload: JSON.parse(el.getAttribute('data-workload') || '[]')
                    };
                } catch (e) {
                    console.error('Failed to parse analytics data:', e);
                    return null;
                }
            }

            function updateTabBadge() {
                var data = getAnalyticsData();
                if (!data) return;

                var pendingCount = data.unread + data.unassigned;
                var baseTitle = "Admin Dashboard - IT Service Request";
                if (pendingCount > 0) {
                    document.title = "(" + pendingCount + ") " + baseTitle;
                } else {
                    document.title = baseTitle;
                }
            }

            function renderCharts() {
                var data = getAnalyticsData();
                if (!data) return;

                // Destroy existing charts to prevent memory leaks
                if (charts.status) charts.status.destroy();
                if (charts.priority) charts.priority.destroy();
                if (charts.workload) charts.workload.destroy();

                var isDark = document.body.classList.contains('theme-dark');
                var textColor = isDark ? '#c6d7eb' : '#425a78';
                var gridColor = isDark ? 'rgba(255,255,255,0.06)' : '#e3edf8';

                // Status Breakdown (Doughnut)
                var ctxStatus = document.getElementById('statusChart');
                if (ctxStatus) {
                    charts.status = new Chart(ctxStatus, {
                        type: 'doughnut',
                        data: {
                            labels: ['Pending', 'In Progress', 'Returned', 'Completed'],
                            datasets: [{
                                data: [data.pending, data.progress, data.returned, data.completed],
                                backgroundColor: ['#38bdf8', '#3b82f6', '#fb923c', '#4ade80'],
                                borderWidth: 0
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    position: 'bottom',
                                    labels: { color: textColor, font: { family: 'Inter', size: 11 } }
                                }
                            },
                            cutout: '65%'
                        }
                    });
                }

                // Task Priority Split (Vertical Bar)
                var ctxPriority = document.getElementById('priorityChart');
                if (ctxPriority) {
                    charts.priority = new Chart(ctxPriority, {
                        type: 'bar',
                        data: {
                            labels: ['Urgent', 'Priority', 'Normal'],
                            datasets: [{
                                data: [data.urgent, data.priority, data.normal],
                                backgroundColor: ['#f43f5e', '#fbbf24', '#3b82f6'],
                                borderRadius: 8
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: { display: false }
                            },
                            scales: {
                                x: {
                                    grid: { display: false },
                                    ticks: { color: textColor, font: { family: 'Inter', size: 11 } }
                                },
                                y: {
                                    grid: { color: gridColor },
                                    ticks: { color: textColor, stepSize: 1, font: { family: 'Inter', size: 11 } }
                                }
                            }
                        }
                    });
                }

                // Staff Workload (Horizontal Stacked Bar)
                var workloadLabels = [];
                var workloadAssigned = [];
                var workloadProgress = [];

                if (Array.isArray(data.workload)) {
                    data.workload.forEach(function (item) {
                        workloadLabels.push(item.username || 'Staff');
                        workloadAssigned.push(parseInt(item.total_assigned || 0, 10));
                        workloadProgress.push(parseInt(item.progress_count || 0, 10));
                    });
                }

                var ctxWorkload = document.getElementById('workloadChart');
                if (ctxWorkload) {
                    charts.workload = new Chart(ctxWorkload, {
                        type: 'bar',
                        data: {
                            labels: workloadLabels,
                            datasets: [
                                {
                                    label: 'In Progress',
                                    data: workloadProgress,
                                    backgroundColor: '#3b82f6',
                                    borderRadius: 6
                                },
                                {
                                    label: 'Total Assigned',
                                    data: workloadAssigned,
                                    backgroundColor: isDark ? 'rgba(255,255,255,0.12)' : '#dce8f6',
                                    borderRadius: 6
                                }
                            ]
                        },
                        options: {
                            indexAxis: 'y',
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    position: 'bottom',
                                    labels: { color: textColor, font: { family: 'Inter', size: 10 } }
                                }
                            },
                            scales: {
                                x: {
                                    grid: { color: gridColor },
                                    ticks: { color: textColor, stepSize: 1, font: { family: 'Inter', size: 10 } }
                                },
                                y: {
                                    grid: { display: false },
                                    ticks: { color: textColor, font: { family: 'Inter', size: 11 } }
                                }
                            }
                        }
                    });
                }
            }

            // Run initial renders
            updateTabBadge();
            renderCharts();

            // Hook chart theme shifts when dark mode is toggled
            var themeToggleBtn = document.getElementById('theme-toggle');
            if (themeToggleBtn) {
                themeToggleBtn.addEventListener('click', function () {
                    // Give classList time to update
                    setTimeout(renderCharts, 250);
                });
            }

            // Toast element
            var toast = document.createElement('div');
            toast.className = 'toast-notification';
            toast.innerHTML = '<div class="toast-spinner"></div><span>Syncing new workspace updates...</span>';
            document.body.appendChild(toast);

            function showToast() {
                toast.classList.add('is-visible');
            }

            function hideToast() {
                toast.classList.remove('is-visible');
            }

            function checkUpdates() {
                if (isUpdating) return;

                fetch('check_updates.php')
                    .then(function (res) { return res.json(); })
                    .then(function (data) {
                        if (data.status === 'success') {
                            var hasUpdates = false;

                            if (data.latest_request_id > currentRequestMaxId) {
                                currentRequestMaxId = data.latest_request_id;
                                hasUpdates = true;
                            }
                            if (data.latest_activity_id > currentActivityMaxId) {
                                currentActivityMaxId = data.latest_activity_id;
                                hasUpdates = true;
                            }
                            if (data.latest_task_id > currentTaskMaxId) {
                                currentTaskMaxId = data.latest_task_id;
                                hasUpdates = true;
                            }

                            if (hasUpdates) {
                                triggerUpdate();
                            }
                        }
                    })
                    .catch(function (err) {
                        console.error('Update check failed:', err);
                    });
            }

            function triggerUpdate() {
                isUpdating = true;
                showToast();

                fetch(window.location.href)
                    .then(function (res) { return res.text(); })
                    .then(function (htmlText) {
                        var parser = new DOMParser();
                        var doc = parser.parseFromString(htmlText, 'text/html');

                        // Helper to swap contents and copy all HTML attributes (like stats variables)
                        function swapContent(selector) {
                            var oldEl = document.querySelector(selector);
                            var newEl = doc.querySelector(selector);
                            if (oldEl && newEl) {
                                oldEl.innerHTML = newEl.innerHTML;
                                Array.prototype.slice.call(newEl.attributes).forEach(function (attr) {
                                    oldEl.setAttribute(attr.name, attr.value);
                                });
                                return oldEl;
                            }
                            return null;
                        }

                        // Swap key sections
                        swapContent('.volume-card');
                        
                        // Swap metric cards
                        var oldMetrics = document.querySelectorAll('.metric-card');
                        var newMetrics = doc.querySelectorAll('.metric-card');
                        for (var i = 0; i < oldMetrics.length && i < newMetrics.length; i++) {
                            oldMetrics[i].innerHTML = newMetrics[i].innerHTML;
                        }

                        // Swap pending queues, analytics data attributes, tables, workload, public holiday, recent activity
                        swapContent('.queue-grid');
                        swapContent('.analytics-section');
                        swapContent('.table-card');
                        swapContent('.workload-compact');
                        swapContent('.holiday-card');
                        swapContent('.ops-feed-mini');
                        swapContent('.sidebar-insights-grid');

                        // Re-render charts and tab counts with fresh stats
                        updateTabBadge();
                        renderCharts();

                        // Add flash highlight to activity feed items and table rows
                        var listItems = document.querySelectorAll('.ops-feed-mini .activity-item, .modern-table tbody tr');
                        listItems.forEach(function (item, idx) {
                            if (idx < 2) { // highlight the top new items
                                item.classList.add('flash-highlight');
                                setTimeout(function () {
                                    item.classList.remove('flash-highlight');
                                }, 1500);
                            }
                        });

                        // Hide notification toast
                        setTimeout(function () {
                            hideToast();
                            isUpdating = false;
                        }, 800);
                    })
                    .catch(function (err) {
                        console.error('Sync failed:', err);
                        hideToast();
                        isUpdating = false;
                    });
            }

            // Custom Dropdown Select Component for Dashboard
            document.querySelectorAll('[data-select]').forEach(function (select) {
                var input = select.querySelector('.custom-select-input');
                var toggle = select.querySelector('[data-select-toggle]');
                var options = Array.from(select.querySelectorAll('.custom-select-option'));

                if (!input || !toggle || options.length === 0) {
                    return;
                }

                toggle.addEventListener('click', function () {
                    document.querySelectorAll('[data-select]').forEach(function (otherSelect) {
                        if (otherSelect !== select) {
                            otherSelect.classList.remove('is-open');
                        }
                    });
                    select.classList.toggle('is-open');
                });

                options.forEach(function (option) {
                    option.addEventListener('click', function () {
                        var nextValue = option.getAttribute('data-value') || '';
                        input.value = nextValue;
                        toggle.textContent = option.textContent || '';
                        options.forEach(function (item) {
                            item.classList.remove('is-selected');
                        });
                        option.classList.add('is-selected');
                        select.classList.remove('is-open');

                        if (select.hasAttribute('data-instant-sort')) {
                            window.location.href = 'dashboard.php?sort=' + encodeURIComponent(nextValue);
                        }
                    });
                });
            });

            document.addEventListener('click', function (event) {
                document.querySelectorAll('[data-select]').forEach(function (select) {
                    if (!select.contains(event.target)) {
                        select.classList.remove('is-open');
                    }
                });
            });

            // Poll every 10 seconds
            setInterval(checkUpdates, 10000);
        }());
    </script>
</body>
</html>
