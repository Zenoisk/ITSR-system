<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/login/auth.php';
requireStaff();
require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/includes/workflow.php';

function queryValue(PDO $pdo, string $sql): int
{
    $value = $pdo->query($sql)->fetchColumn();
    return (int) $value;
}

function requestStatus(array $request): array
{
    $status = (string) ($request['status'] ?? '');

    return [requestStatusLabel($status), requestStatusClass($status)];
}

$requests = [];
$tasks = [];
$staffUsername = currentUsername();
$stats = [
    'assigned' => 0,
    'progress' => 0,
    'completed' => 0,
    'pending' => 0,
    'overdue' => 0,
    'mh' => 0,
    'nmh' => 0,
];
$error = '';

try {
    $pdo = db();
    ensureItsrWorkflowSchema();
    $pdo->prepare("UPDATE service_requests SET is_overdue = 1 WHERE assign_to = :assign_to AND due_at IS NOT NULL AND due_at <> '' AND completed_at IS NULL AND status NOT IN ('completed', 'closed') AND due_at < NOW()")->execute([':assign_to' => $staffUsername]);
    $pdo->prepare("UPDATE request_tasks SET is_overdue = 1 WHERE assigned_to = :assigned_to AND due_at IS NOT NULL AND due_at <> '' AND completed_at IS NULL AND status NOT IN ('completed', 'closed') AND due_at < NOW()")->execute([':assigned_to' => $staffUsername]);
    $staffParams = [':assign_to' => $staffUsername];
    $assignedWhere = ' WHERE assign_to = :assign_to';

    $assignedStmt = $pdo->prepare("SELECT COUNT(*) FROM service_requests$assignedWhere");
    $assignedStmt->execute($staffParams);
    $stats['assigned'] = (int) $assignedStmt->fetchColumn();

    $progressStmt = $pdo->prepare("SELECT COUNT(*) FROM service_requests$assignedWhere AND status = 'progress'");
    $progressStmt->execute($staffParams);
    $stats['progress'] = (int) $progressStmt->fetchColumn();

    $completedStmt = $pdo->prepare("SELECT COUNT(*) FROM service_requests$assignedWhere AND status = 'completed'");
    $completedStmt->execute($staffParams);
    $stats['completed'] = (int) $completedStmt->fetchColumn();

    $pendingStmt = $pdo->prepare("SELECT COUNT(*) FROM service_requests$assignedWhere AND status = 'pending'");
    $pendingStmt->execute($staffParams);
    $stats['pending'] = (int) $pendingStmt->fetchColumn();

    $overdueStmt = $pdo->prepare("SELECT (
        (SELECT COUNT(*) FROM service_requests WHERE assign_to = :request_staff AND is_overdue = 1 AND status NOT IN ('completed', 'closed')) +
        (SELECT COUNT(*) FROM request_tasks WHERE assigned_to = :task_staff AND is_overdue = 1 AND status NOT IN ('completed', 'closed'))
    )");
    $overdueStmt->execute([':request_staff' => $staffUsername, ':task_staff' => $staffUsername]);
    $stats['overdue'] = (int) $overdueStmt->fetchColumn();

    $mhStmt = $pdo->prepare("SELECT (
        (SELECT COUNT(*) FROM service_requests WHERE assign_to = :request_staff AND sla_result = 'MH') +
        (SELECT COUNT(*) FROM request_tasks WHERE assigned_to = :task_staff AND sla_result = 'MH')
    )");
    $mhStmt->execute([':request_staff' => $staffUsername, ':task_staff' => $staffUsername]);
    $stats['mh'] = (int) $mhStmt->fetchColumn();

    $nmhStmt = $pdo->prepare("SELECT (
        (SELECT COUNT(*) FROM service_requests WHERE assign_to = :request_staff AND sla_result = 'NMH') +
        (SELECT COUNT(*) FROM request_tasks WHERE assigned_to = :task_staff AND sla_result = 'NMH')
    )");
    $nmhStmt->execute([':request_staff' => $staffUsername, ':task_staff' => $staffUsername]);
    $stats['nmh'] = (int) $nmhStmt->fetchColumn();

    $stmt = $pdo->prepare(
        'SELECT id, requestor_name, requestor_email, department, status, assign_to, created_at, pdf_path, task_type, due_at, sla_result, is_overdue
         FROM service_requests
         WHERE assign_to = :assign_to
         ORDER BY id DESC
         LIMIT 8'
    );
    $stmt->execute($staffParams);
    $requests = $stmt->fetchAll();

    $taskStmt = $pdo->prepare(
        'SELECT rt.*, sr.requestor_name, sr.department, sr.pdf_path
         FROM request_tasks rt
         INNER JOIN service_requests sr ON sr.id = rt.parent_request_id
         WHERE rt.assigned_to = :assigned_to
         ORDER BY rt.id DESC
         LIMIT 8'
    );
    $taskStmt->execute([':assigned_to' => $staffUsername]);
    $tasks = $taskStmt->fetchAll();
} catch (Throwable $exception) {
    $error = appErrorMessage($exception, 'Staff dashboard failed', 'Unable to load the dashboard.');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Staff Dashboard</title>
<link rel="stylesheet" href="../assets/enterprise-ui.css">
<style>
*{box-sizing:border-box;margin:0;padding:0;font-family:Inter,"Segoe UI",Roboto,Arial,sans-serif}
body{background:radial-gradient(circle at top left,rgba(56,189,248,.08),transparent 28%),linear-gradient(180deg,#eef4fb 0%,#f6f9fc 100%);color:#132538;transition:background .28s ease,color .22s ease;opacity:1!important;filter:none!important}
.layout{min-height:100vh;display:grid;grid-template-columns:300px 1fr}
.layout,.sidebar,.content,.page,.hero,.panel,.aside-card,a,button{pointer-events:auto}
.content{padding:28px;animation:pageEnter .28s ease}
.page{max-width:1320px;margin:0 auto}
.hero{position:relative;background:linear-gradient(135deg,#0b1729 0%,#154b6a 50%,#14b8ff 100%);color:#eff6ff;border-radius:32px;padding:34px;overflow:hidden;box-shadow:0 28px 55px rgba(15,23,42,.18);margin-bottom:22px}
.hero::before{content:"";position:absolute;inset:auto -70px -130px auto;width:320px;height:320px;border-radius:50%;background:radial-gradient(circle,rgba(125,211,252,.28),transparent 60%)}
.hero::after{content:"";position:absolute;inset:0;background:linear-gradient(125deg,rgba(255,255,255,.08),transparent 32%,transparent 68%,rgba(255,255,255,.04));pointer-events:none}
.hero>*{position:relative;z-index:1}
.hero-top{display:flex;justify-content:space-between;align-items:flex-start;gap:22px;flex-wrap:wrap}
.eyebrow{display:inline-block;color:#d1f1ff;font-size:12px;letter-spacing:.14em;text-transform:uppercase;margin-bottom:12px;font-weight:700}
.hero h1{font-size:42px;margin-bottom:12px;line-height:1.04;max-width:780px}
.hero p{max-width:720px;line-height:1.72;font-size:15px;color:rgba(226,232,240,.92)}
.actions{display:flex;gap:12px;flex-wrap:wrap}
.btn{display:inline-flex;align-items:center;justify-content:center;text-decoration:none;border-radius:16px;padding:13px 20px;font-size:14px;font-weight:700;border:1px solid transparent;transition:transform .18s ease,box-shadow .22s ease,background .22s ease,border-color .22s ease}
.btn:hover{transform:translateY(-1px)}
.btn-primary{background:#fff;color:#12325b;box-shadow:0 14px 30px rgba(15,23,42,.12)}
.btn-light{background:rgba(255,255,255,.1);color:#eff6ff;border-color:rgba(255,255,255,.16)}
.empty-card .btn-light{background:#fff;color:#12325b;border-color:#d7e2ee;box-shadow:0 12px 26px rgba(37,99,235,.12)}
.empty-card .btn-primary{background:linear-gradient(180deg,#2563eb 0%,#1d4ed8 100%);color:#fff;border-color:transparent}
.stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-top:26px}
.stat{background:rgba(255,255,255,.09);border:1px solid rgba(255,255,255,.13);border-radius:24px;padding:18px 18px 16px;backdrop-filter:blur(10px);min-height:96px}
.stat-label{font-size:13px;color:rgba(248,251,255,.84);margin-bottom:10px}
.stat-value{font-size:34px;font-weight:800;line-height:1;color:#f8fbff}
.workspace{display:grid;grid-template-columns:minmax(0,1.5fr) minmax(280px,.78fr);gap:18px}
.panel,.aside-card{background:rgba(255,255,255,.88);border:1px solid rgba(209,220,232,.92);border-radius:24px;box-shadow:0 18px 38px rgba(15,23,42,.08);backdrop-filter:blur(10px);transition:transform .2s ease,box-shadow .22s ease}
.panel:hover,.aside-card:hover{transform:translateY(-1px);box-shadow:0 24px 44px rgba(15,23,42,.11)}
.panel-head{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;padding:22px 22px 18px;border-bottom:1px solid #e4ebf2}
.panel-head h2{font-size:23px;color:#0f2642}
.panel-head p{color:#66788b;font-size:14px;margin-top:6px;line-height:1.6}
.panel-link{display:inline-flex;align-items:center;gap:8px;color:#2563eb;font-weight:700;text-decoration:none;padding-top:4px}
.panel-link:hover{text-decoration:underline}
.table-wrap{padding:8px 14px 16px;overflow-x:auto}
table{width:100%;border-collapse:separate;border-spacing:0 10px}
th{padding:0 14px 8px;text-align:left;font-size:12px;letter-spacing:.04em;text-transform:uppercase;color:#68809c;font-weight:800}
td{padding:18px 14px;vertical-align:top;font-size:14px;background:#fbfdff;border-top:1px solid #e8eef5;border-bottom:1px solid #e8eef5}
tbody td:first-child{border-left:1px solid #e8eef5;border-radius:18px 0 0 18px}
tbody td:last-child{border-right:1px solid #e8eef5;border-radius:0 18px 18px 0}
tbody tr:hover td{background:#f3f8fe}
.requestor{font-size:18px;font-weight:800;color:#102949;margin-bottom:4px}
.request-meta{display:grid;gap:4px}
.muted{color:#708194;font-size:13px;line-height:1.45}
.request-id{display:inline-flex;align-items:center;justify-content:center;min-width:52px;padding:8px 12px;border-radius:999px;background:#eef4ff;color:#2156a5;font-weight:800}
.pill{display:inline-flex;align-items:center;padding:7px 11px;border-radius:999px;font-size:12px;font-weight:700}
.status-pending{background:#fff5dc;color:#9a6a00}
.status-returned{background:#fee2e2;color:#b91c1c}
.status-resubmitted{background:#f3e8ff;color:#7e22ce}
.status-assigned{background:#e0f2fe;color:#0369a1}
.status-progress{background:#e9f3ff;color:#1d63b8}
.status-completed{background:#eaf8ef;color:#1f7a3f}
.status-reopened{background:#f3e8ff;color:#7e22ce}
.action-stack{display:flex;flex-wrap:wrap;gap:10px}
.action-link{display:inline-flex;align-items:center;justify-content:center;min-height:38px;padding:0 14px;border-radius:13px;border:1px solid #d7e2ee;text-decoration:none;font-size:13px;font-weight:700;color:#204a74;background:#fff}
.action-link.is-primary{color:#fff;border-color:transparent;background:linear-gradient(180deg,#0ea5e9 0%,#0284c7 100%)}
.empty-state{padding:22px}
.empty-card{max-width:520px;padding:24px;border-radius:22px;background:linear-gradient(180deg,#f9fbfd 0%,#f3f7fb 100%);border:1px solid #dfe8f1;box-shadow:inset 0 1px 0 rgba(255,255,255,.7)}
.empty-icon{width:56px;height:56px;border-radius:18px;display:flex;align-items:center;justify-content:center;background:linear-gradient(180deg,#e9f6ff 0%,#d8eeff 100%);color:#1993db;margin-bottom:16px}
.empty-icon svg{width:26px;height:26px;stroke:currentColor;stroke-width:1.9;fill:none;stroke-linecap:round;stroke-linejoin:round}
.empty-card h3{font-size:22px;color:#102949;margin-bottom:8px}
.empty-card p{color:#66788b;line-height:1.7;margin-bottom:16px}
.empty-actions{display:flex;gap:10px;flex-wrap:wrap}
.aside-card{padding:22px}
.aside-card h3{font-size:20px;color:#102949;margin-bottom:8px}
.aside-card p{color:#66788b;line-height:1.6;font-size:14px}
.aside-list{display:grid;gap:12px;margin-top:18px}
.aside-item{padding:14px 16px;border-radius:18px;background:#f8fbfd;border:1px solid #e2e9f1}
.aside-item strong{display:block;color:#12325b;font-size:14px;margin-bottom:4px}
.aside-item span{color:#6e8197;font-size:13px;line-height:1.5}
.error{padding:14px 16px;border-radius:16px;font-size:14px;background:#fff1f1;border:1px solid #ebc7c7;color:#8a1f1f}
@keyframes pageEnter{from{opacity:0}to{opacity:1}}
@media (max-width:1180px){.workspace{grid-template-columns:1fr}.aside-card{order:-1}}
@media (max-width:1024px){.layout{grid-template-columns:1fr}.content{padding:18px}.stats{grid-template-columns:repeat(2,minmax(0,1fr))}.hero h1{font-size:34px}}
@media (max-width:640px){.stats{grid-template-columns:1fr}.hero{padding:26px 22px}.panel-head{padding:18px}.table-wrap{padding:8px 10px 14px}}
body.theme-dark{background:linear-gradient(180deg,#07111f 0%,#0b1729 100%);color:#e5eef9}
body.theme-dark .hero{background:linear-gradient(135deg,#07111f 0%,#0d3245 52%,#0369a1 100%);box-shadow:0 28px 55px rgba(0,0,0,.32)}
body.theme-dark .panel,body.theme-dark .aside-card{background:rgba(12,21,36,.9);border-color:rgba(65,85,110,.72);box-shadow:0 18px 38px rgba(0,0,0,.24)}
body.theme-dark .panel-head{border-bottom-color:rgba(70,88,112,.6)}
body.theme-dark .panel-head h2,body.theme-dark .aside-card h3,body.theme-dark .requestor{color:#f4f8fd}
body.theme-dark .panel-head p,body.theme-dark .muted,body.theme-dark .aside-card p,body.theme-dark .aside-item span{color:#9eb2c9}
body.theme-dark th{color:#9eb2c9}
body.theme-dark td{background:rgba(18,30,48,.92);border-top-color:rgba(57,75,99,.65);border-bottom-color:rgba(57,75,99,.65);color:#e8eff8}
body.theme-dark tbody td:first-child{border-left-color:rgba(57,75,99,.65)}
body.theme-dark tbody td:last-child{border-right-color:rgba(57,75,99,.65)}
body.theme-dark tbody tr:hover td{background:rgba(25,40,62,.95)}
body.theme-dark .request-id{background:rgba(37,99,235,.18);color:#d8e7ff}
body.theme-dark .btn-primary{background:#f8fbff;color:#102949}
body.theme-dark .btn-light{background:rgba(255,255,255,.07);color:#eef4fb;border-color:rgba(148,163,184,.18)}
body.theme-dark .empty-card .btn-light{background:rgba(255,255,255,.08);color:#eef4fb;border-color:rgba(148,163,184,.2)}
body.theme-dark .empty-card .btn-primary{background:linear-gradient(180deg,#2563eb 0%,#1d4ed8 100%);color:#fff}
body.theme-dark .action-link{background:rgba(255,255,255,.04);border-color:rgba(148,163,184,.18);color:#eef4fb}
body.theme-dark .action-link.is-primary{color:#fff}
body.theme-dark .empty-card,body.theme-dark .aside-item{background:rgba(16,27,43,.86);border-color:rgba(67,86,109,.62)}
body.theme-dark .empty-card h3{color:#f4f8fd}
body.theme-dark .empty-card p{color:#9eb2c9}
body.theme-dark .empty-icon{background:linear-gradient(180deg,rgba(14,165,233,.24) 0%,rgba(59,130,246,.2) 100%);color:#7dd3fc}
body.theme-dark .aside-item strong{color:#e8eff8}
body.theme-dark .error{background:rgba(64,19,19,.88);border-color:rgba(180,67,67,.45);color:#ffd1d1}
</style>
</head>
<body>
<div class="layout">
<?php $activePage='dashboard'; require __DIR__ . '/sidebar.php'; ?>
<main class="content">
<div class="page">
<section class="hero">
<div class="hero-top">
<div>
<div class="eyebrow">Staff Dashboard</div>
<h1>Stay focused on the work that belongs to you.</h1>
<p>This view is tuned for <?= htmlspecialchars($staffUsername, ENT_QUOTES, 'UTF-8') ?>, so the team member on shift can see assigned requests, check PDFs quickly, and keep updates moving without extra clutter.</p>
</div>
<div class="actions">
<a class="btn btn-light" href="requests.php">Open Work Queue</a>
<a class="btn btn-primary" href="../form/request_form.php?return=staff">Customer Form</a>
</div>
</div>
<div class="stats">
<div class="stat"><div class="stat-label">Assigned To You</div><div class="stat-value"><?= $stats['assigned'] ?></div></div>
<div class="stat"><div class="stat-label">Pending</div><div class="stat-value"><?= $stats['pending'] ?></div></div>
<div class="stat"><div class="stat-label">In Progress</div><div class="stat-value"><?= $stats['progress'] ?></div></div>
<div class="stat"><div class="stat-label">Completed</div><div class="stat-value"><?= $stats['completed'] ?></div></div>
<div class="stat"><div class="stat-label">Overdue</div><div class="stat-value"><?= $stats['overdue'] ?></div></div>
<div class="stat"><div class="stat-label">MH</div><div class="stat-value"><?= $stats['mh'] ?></div></div>
<div class="stat"><div class="stat-label">NMH</div><div class="stat-value"><?= $stats['nmh'] ?></div></div>
</div>
</section>
<?php if ($error !== ''): ?>
<div class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
<?php else: ?>
<div class="workspace">
<section class="panel">
<div class="panel-head">
<div>
<h2>My Recent Requests</h2>
<p>Latest assigned work, ready to open, review, and update.</p>
</div>
<a class="panel-link" href="requests.php">View All</a>
</div>
<?php if (!$requests): ?>
<div class="empty-state">
<div class="empty-card">
<div class="empty-icon">
<svg viewBox="0 0 24 24"><path d="M7 4h7l5 5v9.5A1.5 1.5 0 0 1 17.5 20h-10A1.5 1.5 0 0 1 6 18.5v-13A1.5 1.5 0 0 1 7.5 4H7Z"/><path d="M14 4v5h5"/><path d="M9 13h6"/><path d="M9 16h4"/></svg>
</div>
<h3>No assigned requests yet</h3>
<p>Once admin assigns work to you, the latest request cards will appear here instead of this empty bar.</p>
<div class="empty-actions">
<a class="btn btn-light" href="requests.php">Open Work Queue</a>
<a class="btn btn-primary" href="../form/request_form.php?return=staff">Customer Form</a>
</div>
</div>
</div>
<?php else: ?>
<div class="table-wrap">
<table>
<thead>
<tr><th>Request</th><th>Requestor</th><th>Status</th><th>SLA</th><th>Created</th><th>Actions</th></tr>
</thead>
<tbody>
<?php foreach ($requests as $request): ?>
<?php [$statusText,$statusClass]=requestStatus($request); ?>
<tr>
<td>
<span class="request-id">#<?= (int) $request['id'] ?></span>
</td>
<td>
<div class="requestor"><?= htmlspecialchars((string) $request['requestor_name'], ENT_QUOTES, 'UTF-8') ?></div>
<div class="request-meta">
<div class="muted"><?= htmlspecialchars((string) $request['requestor_email'], ENT_QUOTES, 'UTF-8') ?></div>
<div class="muted"><?= htmlspecialchars((string) $request['department'], ENT_QUOTES, 'UTF-8') ?></div>
<div class="muted">Assigned to <?= htmlspecialchars((string) $request['assign_to'], ENT_QUOTES, 'UTF-8') ?: 'Unassigned' ?></div>
</div>
</td>
<td><span class="pill <?= $statusClass ?>"><?= $statusText ?></span></td>
<td>
<div class="muted"><?= htmlspecialchars((string) ($request['task_type'] ?? 'Normal'), ENT_QUOTES, 'UTF-8') ?></div>
<div class="muted"><?= trim((string) ($request['due_at'] ?? '')) !== '' ? 'Due ' . htmlspecialchars((string) $request['due_at'], ENT_QUOTES, 'UTF-8') : 'Not started' ?></div>
<div class="muted"><?= htmlspecialchars((string) ($request['sla_result'] ?? 'Pending'), ENT_QUOTES, 'UTF-8') ?><?= (int) ($request['is_overdue'] ?? 0) === 1 ? ' / Overdue' : '' ?></div>
</td>
<td><div class="muted"><?= htmlspecialchars((string) $request['created_at'], ENT_QUOTES, 'UTF-8') ?></div></td>
<td>
<div class="action-stack">
<a class="action-link is-primary" href="request_edit.php?id=<?= (int) $request['id'] ?>">Open</a>
<?= workflowGetPdfLinkHtml((string) $request['pdf_path'], 'action-link', 'PDF', '../') ?>
</div>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php endif; ?>
<div class="panel-head" style="border-top:1px solid #e4ebf2;">
<div>
<h2>My Task Cards</h2>
<p>Split work assigned to you from a parent request.</p>
</div>
</div>
<?php if (!$tasks): ?>
<div class="empty-state"><div class="empty-card"><h3>No child tasks assigned</h3><p>Task cards created by admin will appear here when they are assigned to you.</p></div></div>
<?php else: ?>
<div class="table-wrap">
<table>
<thead><tr><th>Task</th><th>Parent</th><th>Status</th><th>Priority</th><th>Action</th></tr></thead>
<tbody>
<?php foreach ($tasks as $task): ?>
<tr>
<td><strong><?= htmlspecialchars((string) $task['task_title'], ENT_QUOTES, 'UTF-8') ?></strong><div class="muted"><?= htmlspecialchars((string) $task['task_description'], ENT_QUOTES, 'UTF-8') ?></div></td>
<td>#<?= (int) $task['parent_request_id'] ?><div class="muted"><?= htmlspecialchars((string) $task['requestor_name'], ENT_QUOTES, 'UTF-8') ?></div></td>
<td><span class="pill <?= htmlspecialchars(requestStatusClass((string) $task['status']), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(requestStatusLabel((string) $task['status']), ENT_QUOTES, 'UTF-8') ?></span></td>
<td><span class="pill status-assigned"><?= htmlspecialchars(ucfirst((string) $task['priority']), ENT_QUOTES, 'UTF-8') ?></span></td>
<td><div class="action-stack"><a class="action-link is-primary" href="task_edit.php?id=<?= (int) $task['id'] ?>">Open Task</a></div></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php endif; ?>
</section>
<aside class="aside-card">
<h3>Quick Workflow</h3>
<p>A simple rhythm for staff keeps the queue cleaner and avoids missed updates.</p>
<div class="aside-list">
<div class="aside-item"><strong>1. Open assigned request</strong><span>Use the queue to open the newest work already assigned to you.</span></div>
<div class="aside-item"><strong>2. Update progress</strong><span>Set the correct status so admin can see whether work is pending, in progress, or completed.</span></div>
<div class="aside-item"><strong>3. Add solution notes</strong><span>Record corrective action clearly so the request history stays useful later.</span></div>
</div>
</aside>
</div>
<?php endif; ?>
</div>
</main></div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    document.body.removeAttribute('inert');
    document.documentElement.removeAttribute('inert');

    document.querySelectorAll('.drawer-backdrop, .modal-backdrop, .confirm-modal__backdrop, .page-overlay, .screen-overlay').forEach(function (overlay) {
        overlay.style.pointerEvents = 'none';
    });

    // Safety net: if a blank full-screen backdrop is accidentally left in the DOM,
    // keep it from stealing clicks on the staff dashboard.
    Array.prototype.forEach.call(document.body.children, function (element) {
        if (element.classList.contains('layout')) {
            return;
        }

        var style = window.getComputedStyle(element);
        var rect = element.getBoundingClientRect();
        var coversScreen = rect.width >= window.innerWidth * 0.85 && rect.height >= window.innerHeight * 0.85;
        var isLayer = style.position === 'fixed' || style.position === 'absolute';
        var hasControls = element.querySelector('a, button, input, select, textarea, [role="dialog"]');

        if (isLayer && coversScreen && !hasControls) {
            element.style.pointerEvents = 'none';
        }
    });
});
</script>
</body></html>
