<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/login/auth.php';
requireStaff();
require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/includes/workflow.php';

function staffRequestStatus(array $request): array
{
    $status = (string) ($request['status'] ?? 'pending');

    return [requestStatusLabel($status), requestStatusClass($status)];
}

$search = trim((string) ($_GET['search'] ?? ''));
$statusFilter = trim((string) ($_GET['status'] ?? ''));
$staffUsername = currentUsername();
$allowedStatuses = array_keys(requestStatusOptions());
if (!in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = 'all';
}

$requests = [];
$tasks = [];
$error = '';

try {
    $pdo = db();
    ensureItsrWorkflowSchema();

    $conditions = ['assign_to = :assign_to'];
    $params = [':assign_to' => $staffUsername];

    if ($search !== '') {
        $conditions[] = '(requestor_name LIKE :search OR requestor_email LIKE :search OR company LIKE :search OR department LIKE :search OR location LIKE :search OR assign_to LIKE :search)';
        $params[':search'] = '%' . $search . '%';
    }

    if ($statusFilter !== 'all') {
        $conditions[] = 'status = :status';
        $params[':status'] = $statusFilter;
    }

    $sql = 'SELECT id, company, department, requestor_name, requestor_email, requestor_phone, request_date, status, location, assign_to, date_receive, pdf_path, created_at, task_type, due_at, sla_result, is_overdue
            FROM service_requests
            WHERE ' . implode(' AND ', $conditions) . '
            ORDER BY id DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $requests = $stmt->fetchAll();

    $taskConditions = ['rt.assigned_to = :assigned_to'];
    $taskParams = [':assigned_to' => $staffUsername];
    if ($search !== '') {
        $taskConditions[] = '(rt.task_title LIKE :task_search OR rt.task_description LIKE :task_search OR sr.requestor_name LIKE :task_search OR sr.department LIKE :task_search)';
        $taskParams[':task_search'] = '%' . $search . '%';
    }
    if ($statusFilter !== 'all') {
        $taskConditions[] = 'rt.status = :task_status';
        $taskParams[':task_status'] = $statusFilter;
    }

    $taskSql = 'SELECT rt.*, sr.requestor_name, sr.department, sr.pdf_path
                FROM request_tasks rt
                INNER JOIN service_requests sr ON sr.id = rt.parent_request_id
                WHERE ' . implode(' AND ', $taskConditions) . '
                ORDER BY rt.id DESC';
    $taskStmt = $pdo->prepare($taskSql);
    $taskStmt->execute($taskParams);
    $tasks = $taskStmt->fetchAll();
} catch (Throwable $exception) {
    $error = appErrorMessage($exception, 'Staff request list failed', 'Unable to load requests.');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Staff Work Queue</title>
<link rel="stylesheet" href="../assets/enterprise-ui.css">
<style>
*{box-sizing:border-box;margin:0;padding:0;font-family:Inter,"Segoe UI",Roboto,Arial,sans-serif}
body{background:radial-gradient(circle at top left,rgba(56,189,248,.08),transparent 28%),linear-gradient(180deg,#eef4fb 0%,#f6f9fc 100%);color:#132538}
.layout{min-height:100vh;display:grid;grid-template-columns:280px 1fr}
.content{padding:28px;animation:pageEnter .28s ease}
.content>*{max-width:1320px;margin-left:auto;margin-right:auto}
.hero,.table-card{background:rgba(255,255,255,.9);border:1px solid rgba(209,220,232,.9);border-radius:24px;box-shadow:0 18px 38px rgba(15,23,42,.08);backdrop-filter:blur(10px)}
.hero{position:relative;z-index:20;padding:26px 28px;margin-bottom:18px;overflow:visible}
.hero-top{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap}
.eyebrow{display:inline-block;color:#2563eb;font-size:12px;letter-spacing:.1em;text-transform:uppercase;margin-bottom:12px;font-weight:700}
.hero h1{font-size:34px;margin-bottom:10px;color:#0f2642}
.hero p{max-width:760px;line-height:1.65;color:#64748b}
.actions{display:flex;gap:10px;flex-wrap:wrap}
.btn{display:inline-flex;align-items:center;justify-content:center;text-decoration:none;border-radius:14px;padding:12px 18px;font-size:14px;font-weight:700;border:1px solid transparent;cursor:pointer}
.btn-primary{background:linear-gradient(180deg,#2563eb 0%,#1d4ed8 100%);color:#fff}
.btn-light{background:#fff;color:#334155;border-color:#d7e0ea}
.controls{margin-top:18px}
.controls-grid{display:grid;grid-template-columns:2fr 1fr auto;gap:12px}
input,select{width:100%;border:1px solid #d4dde7;border-radius:14px;padding:12px 14px;font-size:14px;outline:none;background:#fff}
input:focus,select:focus{border-color:#2c71ba;box-shadow:0 0 0 4px rgba(44,113,186,.10)}
.custom-select{position:relative;z-index:60}
.custom-select.is-open{z-index:600}
.custom-select-input{display:none}
.custom-select-toggle{width:100%;min-height:46px;border:1px solid #d4dde7;border-radius:16px;padding:0 44px 0 14px;font-size:14px;font-weight:700;text-align:left;background:linear-gradient(180deg,#fff,#f8fbff);color:#18304b;cursor:pointer;position:relative}
.custom-select-toggle::after{content:"";position:absolute;top:50%;right:16px;width:9px;height:9px;border-right:2px solid #64748b;border-bottom:2px solid #64748b;transform:translateY(-65%) rotate(45deg);transition:transform .18s ease}
.custom-select.is-open .custom-select-toggle{border-color:#38a7ff;box-shadow:0 0 0 4px rgba(56,167,255,.14)}
.custom-select.is-open .custom-select-toggle::after{transform:translateY(-30%) rotate(-135deg)}
.custom-select-menu{position:absolute;top:calc(100% + 8px);left:0;right:0;z-index:700;display:none;max-height:220px;overflow-y:auto;padding:8px;border:1px solid #d8e3ef;border-radius:20px;background:#fff;box-shadow:0 22px 40px rgba(15,23,42,.14)}
.custom-select.is-open .custom-select-menu{display:grid;gap:6px}
.custom-select-option{width:100%;display:flex;align-items:center;justify-content:space-between;border:0;border-radius:13px;padding:11px 12px;background:transparent;color:#18304b;font-size:14px;font-weight:700;text-align:left;cursor:pointer}
.custom-select-option:hover,.custom-select-option.is-selected{background:#dbeafe;color:#1d4ed8}
.custom-select-option.is-selected::after{content:"✓";font-weight:900}
.table-card{position:relative;z-index:1;overflow:hidden;margin-bottom:18px}
.table-head{padding:18px 20px;border-bottom:1px solid #e1e8ef;background:linear-gradient(180deg,#fbfdff 0%,#f7fbff 100%)}
.table-head h2{font-size:24px;color:#0f2642}
.table-head p{color:#66788b;font-size:14px;margin-top:4px}
.table-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse}
th,td{padding:16px 18px;border-bottom:1px solid #e7edf3;text-align:left;font-size:14px;vertical-align:top}
th{background:#f7fafd;color:#284764;font-weight:700;font-size:13px;position:sticky;top:0;z-index:1}
tbody tr:hover{background:#fbfdff}
.muted{color:#708194;font-size:13px;margin-top:4px;line-height:1.45}
.pill{display:inline-flex;align-items:center;padding:7px 12px;border-radius:999px;font-size:12px;font-weight:700}
.status-pending{background:#fff5dc;color:#9a6a00}
.status-returned{background:#fee2e2;color:#b91c1c}
.status-resubmitted{background:#f3e8ff;color:#7e22ce}
.status-assigned{background:#e0f2fe;color:#0369a1}
.status-progress{background:#e9f3ff;color:#1d63b8}
.status-completed{background:#eaf8ef;color:#1f7a3f}
.status-reopened{background:#f3e8ff;color:#7e22ce}
.pdf-link,.open-link{color:#1f5fbf;font-weight:700;text-decoration:none}
.open-link{display:inline-flex;align-items:center;justify-content:center;min-height:34px;padding:0 12px;border-radius:12px;border:1px solid #d7e2ee;background:#fff}
.open-link.is-primary{background:linear-gradient(180deg,#0ea5e9 0%,#0284c7 100%);border-color:transparent;color:#fff}
.empty,.error{margin:18px 20px 20px;padding:14px 16px;border-radius:16px;font-size:14px}
.empty{background:#f8fbfd;border:1px solid #dae3ec;color:#617387}
.error{background:#fff1f1;border:1px solid #ebc7c7;color:#8a1f1f}
@keyframes pageEnter{from{opacity:0}to{opacity:1}}
@media(max-width:1024px){.layout{grid-template-columns:1fr}.content{padding:18px}.controls-grid{grid-template-columns:1fr}}
body.theme-dark{background:linear-gradient(180deg,#07111f 0%,#0b1729 100%);color:#e5eef9}
body.theme-dark .hero,body.theme-dark .table-card{background:rgba(12,21,36,.9);border-color:rgba(65,85,110,.72);box-shadow:0 18px 38px rgba(0,0,0,.24)}
body.theme-dark .hero h1,body.theme-dark .table-head h2{color:#f4f8fd}
body.theme-dark .hero p,body.theme-dark .table-head p,body.theme-dark .muted{color:#9eb2c9}
body.theme-dark .table-head{background:linear-gradient(180deg,#0f1a2d 0%,#0b1525 100%);border-bottom-color:rgba(70,88,112,.6)}
body.theme-dark input,body.theme-dark select{background:#0f1a2d;border-color:rgba(78,97,121,.7);color:#eef4fb}
body.theme-dark .custom-select-toggle{background:linear-gradient(180deg,#101b2d,#0d1727);border-color:rgba(78,97,121,.7);color:#eef4fb}
body.theme-dark .custom-select-toggle::after{border-color:#9eb2c9}
body.theme-dark .custom-select-menu{background:#0d1727;border-color:#334761;box-shadow:0 18px 40px rgba(0,0,0,.36)}
body.theme-dark .custom-select-option{color:#e5eef9}
body.theme-dark .custom-select-option:hover,body.theme-dark .custom-select-option.is-selected{background:#14375c;color:#8fd4ff}
body.theme-dark .btn-light,body.theme-dark .open-link{background:rgba(255,255,255,.07);color:#eef4fb;border-color:rgba(148,163,184,.18)}
body.theme-dark th{background:rgba(20,31,49,.96);color:#cfe0f4}
body.theme-dark td{border-bottom-color:rgba(57,75,99,.6);color:#e8eff8}
body.theme-dark tbody tr:hover{background:rgba(28,44,68,.64)}
body.theme-dark .open-link.is-primary{color:#fff}
</style>
</head>
<body>
<div class="layout">
<?php $activePage = 'requests'; require __DIR__ . '/sidebar.php'; ?>
<main class="content">
<section class="hero">
<div class="hero-top">
<div>
<div class="eyebrow">Staff Queue</div>
<h1>My Work Queue</h1>
<p>Review whole requests and child task cards assigned to <?= htmlspecialchars($staffUsername, ENT_QUOTES, 'UTF-8') ?>.</p>
</div>
<div class="actions">
<a class="btn btn-light" href="dashboard.php">Back to Dashboard</a>
<a class="btn btn-primary" href="../form/request_form.php?return=staff">Customer Form</a>
</div>
</div>
<div class="controls">
<form method="get" class="controls-grid">
<input type="text" name="search" placeholder="Search requestor, company, task, location, or queue" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>">
<div class="custom-select" data-select>
<input class="custom-select-input" type="hidden" name="status" value="<?= htmlspecialchars($statusFilter, ENT_QUOTES, 'UTF-8') ?>">
<button class="custom-select-toggle" type="button" data-select-toggle><?= htmlspecialchars($statusFilter === 'all' ? 'All Status' : requestStatusLabel($statusFilter), ENT_QUOTES, 'UTF-8') ?></button>
<div class="custom-select-menu">
<button class="custom-select-option<?= $statusFilter === 'all' ? ' is-selected' : '' ?>" type="button" data-value="all">All Status</button>
<?php foreach (requestStatusOptions() as $statusValue => $statusLabel): ?>
<button class="custom-select-option<?= $statusFilter === $statusValue ? ' is-selected' : '' ?>" type="button" data-value="<?= htmlspecialchars($statusValue, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') ?></button>
<?php endforeach; ?>
</div>
</div>
<button class="btn btn-primary" type="submit">Filter</button>
</form>
</div>
</section>

<section class="table-card">
<div class="table-head"><h2>Assigned Requests</h2><p><?= count($requests) ?> result(s) assigned to you.</p></div>
<?php if ($error !== ''): ?>
<div class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
<?php elseif (!$requests): ?>
<div class="empty">No whole requests assigned to you matched the current filter.</div>
<?php else: ?>
<div class="table-wrap">
<table>
<thead><tr><th>ID</th><th>Status</th><th>Requestor</th><th>Contact</th><th>Company</th><th>SLA</th><th>Location</th><th>PDF</th><th>Action</th></tr></thead>
<tbody>
<?php foreach ($requests as $request): ?>
<?php [$statusText, $statusClass] = staffRequestStatus($request); ?>
<tr>
<td>#<?= (int) $request['id'] ?></td>
<td><span class="pill <?= htmlspecialchars($statusClass, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($statusText, ENT_QUOTES, 'UTF-8') ?></span></td>
<td><strong><?= htmlspecialchars((string) $request['requestor_name'], ENT_QUOTES, 'UTF-8') ?></strong><div class="muted"><?= htmlspecialchars((string) $request['department'], ENT_QUOTES, 'UTF-8') ?></div></td>
<td><div><?= htmlspecialchars((string) $request['requestor_email'], ENT_QUOTES, 'UTF-8') ?></div><div class="muted"><?= htmlspecialchars((string) $request['requestor_phone'], ENT_QUOTES, 'UTF-8') ?></div></td>
<td><?= htmlspecialchars((string) $request['company'], ENT_QUOTES, 'UTF-8') ?></td>
<td>
<div><strong><?= htmlspecialchars((string) ($request['task_type'] ?? 'Normal'), ENT_QUOTES, 'UTF-8') ?></strong></div>
<div class="muted"><?= trim((string) ($request['due_at'] ?? '')) !== '' ? 'Due ' . htmlspecialchars((string) $request['due_at'], ENT_QUOTES, 'UTF-8') : 'Not started' ?></div>
<div class="muted"><?= htmlspecialchars((string) ($request['sla_result'] ?? 'Pending'), ENT_QUOTES, 'UTF-8') ?><?= (int) ($request['is_overdue'] ?? 0) === 1 ? ' / Overdue' : '' ?></div>
</td>
<td><?= htmlspecialchars((string) $request['location'], ENT_QUOTES, 'UTF-8') ?></td>
<td><?= workflowGetPdfLinkHtml((string) $request['pdf_path'], 'pdf-link', 'Open PDF', '../') ?></td>
<td><a class="open-link is-primary" href="request_edit.php?id=<?= (int) $request['id'] ?>">Open</a></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php endif; ?>
</section>

<section class="table-card">
<div class="table-head"><h2>Assigned Task Cards</h2><p><?= count($tasks) ?> child task(s) assigned to you.</p></div>
<?php if ($error === '' && !$tasks): ?>
<div class="empty">No child tasks matched the current filter.</div>
<?php elseif ($tasks): ?>
<div class="table-wrap">
<table>
<thead><tr><th>Task</th><th>Parent Request</th><th>Status</th><th>SLA</th><th>Action</th></tr></thead>
<tbody>
<?php foreach ($tasks as $task): ?>
<tr>
<td><strong><?= htmlspecialchars((string) $task['task_title'], ENT_QUOTES, 'UTF-8') ?></strong><div class="muted"><?= htmlspecialchars((string) $task['task_description'], ENT_QUOTES, 'UTF-8') ?></div></td>
<td>#<?= (int) $task['parent_request_id'] ?><div class="muted"><?= htmlspecialchars((string) $task['requestor_name'], ENT_QUOTES, 'UTF-8') ?></div></td>
<td><span class="pill <?= htmlspecialchars(requestStatusClass((string) $task['status']), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(requestStatusLabel((string) $task['status']), ENT_QUOTES, 'UTF-8') ?></span></td>
<td>
<span class="pill status-assigned"><?= htmlspecialchars((string) ($task['task_type'] ?? 'Normal'), ENT_QUOTES, 'UTF-8') ?></span>
<div class="muted"><?= trim((string) ($task['due_at'] ?? '')) !== '' ? 'Due ' . htmlspecialchars((string) $task['due_at'], ENT_QUOTES, 'UTF-8') : 'Not started' ?></div>
<div class="muted"><?= htmlspecialchars((string) ($task['sla_result'] ?? 'Pending'), ENT_QUOTES, 'UTF-8') ?><?= (int) ($task['is_overdue'] ?? 0) === 1 ? ' / Overdue' : '' ?></div>
</td>
<td><a class="open-link is-primary" href="task_edit.php?id=<?= (int) $task['id'] ?>">Open Task</a></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php endif; ?>
</section>
</main>
</div>
<script>
document.querySelectorAll('[data-select]').forEach(function(select){var input=select.querySelector('.custom-select-input');var toggle=select.querySelector('[data-select-toggle]');var options=Array.from(select.querySelectorAll('.custom-select-option'));if(!input||!toggle||options.length===0){return;}toggle.addEventListener('click',function(){document.querySelectorAll('[data-select]').forEach(function(other){if(other!==select){other.classList.remove('is-open');}});select.classList.toggle('is-open');});options.forEach(function(option){option.addEventListener('click',function(){input.value=option.getAttribute('data-value')||'';toggle.textContent=option.textContent||'';options.forEach(function(item){item.classList.toggle('is-selected',item===option);});select.classList.remove('is-open');});});});
document.addEventListener('click',function(event){document.querySelectorAll('[data-select]').forEach(function(select){if(!select.contains(event.target)){select.classList.remove('is-open');}});});
</script>
</body>
</html>
