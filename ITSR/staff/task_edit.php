<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/login/auth.php';
requireStaff();
require_once dirname(__DIR__) . '/includes/workflow.php';
require_once dirname(__DIR__) . '/includes/notifications.php';

ensureItsrWorkflowSchema();

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$staffUsername = currentUsername();
$message = '';
$error = '';

function taskOrFail(int $id, string $staffUsername): array
{
    $stmt = db()->prepare(
        'SELECT rt.*, sr.id AS request_id, sr.requestor_name, sr.requestor_email, sr.department, sr.company, sr.location, sr.problem_description, sr.pdf_path
         FROM request_tasks rt
         INNER JOIN service_requests sr ON sr.id = rt.parent_request_id
         WHERE rt.id = :id AND rt.assigned_to = :assigned_to
         LIMIT 1'
    );
    $stmt->execute([':id' => $id, ':assigned_to' => $staffUsername]);
    $task = $stmt->fetch();

    if (!$task) {
        http_response_code(404);
        echo 'Task not found or not assigned to you.';
        exit;
    }

    return $task;
}

function postedTask(string $key): string
{
    return trim((string) ($_POST[$key] ?? ''));
}

$task = taskOrFail($id, $staffUsername);
slaRefreshOpenRecord(db(), 'request_tasks', $id);
$task = taskOrFail($id, $staffUsername);

if ($_SERVER['REQUEST_METHOD'] === 'GET' && (int) ($task['is_read_staff'] ?? 0) === 0) {
    db()->prepare('UPDATE request_tasks SET is_read_staff = 1, staff_read_at = NOW() WHERE id = :id')->execute([':id' => $id]);
    addRequestLog((int) $task['parent_request_id'], $id, 'staff_viewed_task', 'Staff opened child task #' . (int) $task['task_no'] . '.');
    $task = taskOrFail($id, $staffUsername);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfValidateOrDie();
    try {
        $previousStatus = (string) ($task['status'] ?? 'assigned');
        $status = postedTask('status');
        if (!array_key_exists($status, requestStatusOptions())) {
            $status = 'assigned';
        }

        $stmt = db()->prepare(
            'UPDATE request_tasks SET
                status = :status,
                solution = :solution,
                is_read_staff = 1,
                staff_read_at = COALESCE(staff_read_at, NOW())
             WHERE id = :id AND assigned_to = :assigned_to'
        );
        $stmt->execute([
            ':status' => $status,
            ':solution' => postedTask('solution'),
            ':id' => $id,
            ':assigned_to' => $staffUsername,
        ]);

        addRequestLog((int) $task['parent_request_id'], $id, 'staff_updated_task', 'Staff updated task #' . (int) $task['task_no'] . ' to ' . requestStatusLabel($status) . '.');
        logSystemActivity('task_updated', 'Task updated by staff', 'Staff updated task #' . (int) $task['task_no'] . ' for request #' . (int) $task['parent_request_id'] . '.');
        $task = taskOrFail($id, $staffUsername);
        if ((string) ($task['status'] ?? '') === 'completed' && $previousStatus !== 'completed') {
            $completionData = slaCompleteData($task);
            db()->prepare(
                'UPDATE request_tasks
                 SET completed_at = :completed_at,
                     date_completed = DATE(:completed_at),
                     actual_working_minutes_taken = :actual_working_minutes_taken,
                     total_hour_taken_display = :total_hour_taken_display,
                     sla_result = :sla_result,
                     is_overdue = :is_overdue
                 WHERE id = :id AND assigned_to = :assigned_to'
            )->execute([
                ':completed_at' => $completionData['completed_at'],
                ':actual_working_minutes_taken' => $completionData['actual_working_minutes_taken'],
                ':total_hour_taken_display' => $completionData['total_hour_taken_display'],
                ':sla_result' => $completionData['sla_result'],
                ':is_overdue' => $completionData['is_overdue'],
                ':id' => $id,
                ':assigned_to' => $staffUsername,
            ]);
            addRequestLog((int) $task['parent_request_id'], $id, 'sla_result_calculated', 'Staff completed task #' . (int) $task['task_no'] . '. Time taken: ' . $completionData['total_hour_taken_display'] . '.');
            $task = taskOrFail($id, $staffUsername);
        }
        $emailResults = [];
        if ((string) ($task['status'] ?? '') === 'completed' && $previousStatus !== 'completed') {
            $completionRequest = $task;
            $completionRequest['id'] = (int) ($task['request_id'] ?? $task['parent_request_id'] ?? 0);
            $emailResults[] = itsrNotifyRequestorCompleted($completionRequest, (string) ($task['solution'] ?? ''), $id);
        }
        $message = 'Task updated successfully.' . itsrNotificationStatusMessage($emailResults);
    } catch (Throwable $exception) {
        $error = appErrorMessage($exception, 'Staff task update failed', 'Unable to update the task.');
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Task #<?= (int) $task['task_no'] ?></title>
<link rel="stylesheet" href="../assets/enterprise-ui.css">
<style>
*{box-sizing:border-box;margin:0;padding:0;font-family:Inter,"Segoe UI",Roboto,Arial,sans-serif}
body{background:linear-gradient(180deg,#eef4fb 0%,#f6f9fc 100%);color:#132538}
.layout{min-height:100vh;display:grid;grid-template-columns:280px 1fr}
.content{padding:28px}
.page{max-width:1100px;margin:0 auto}
.topbar,.card{background:rgba(255,255,255,.92);border:1px solid #d8e3ef;border-radius:24px;box-shadow:0 18px 38px rgba(15,23,42,.08)}
.topbar{padding:26px;margin-bottom:18px;display:flex;justify-content:space-between;gap:16px;flex-wrap:wrap}
.eyebrow{color:#0ea5e9;font-size:12px;font-weight:800;letter-spacing:.12em;text-transform:uppercase;margin-bottom:10px}
h1{font-size:34px;margin-bottom:8px;color:#0f2642}
p,.muted{color:#66788b;line-height:1.6}
.btn{display:inline-flex;align-items:center;justify-content:center;min-height:42px;padding:0 16px;border-radius:14px;border:1px solid #d7e0ea;text-decoration:none;font-weight:800;cursor:pointer}
.btn-primary{background:linear-gradient(180deg,#0ea5e9 0%,#0284c7 100%);color:#fff;border-color:transparent}
.btn-light{background:#fff;color:#334155}
.card{padding:24px}
.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}
.grid-wide{grid-column:span 2}
label{display:block;margin-bottom:6px;color:#36516c;font-size:13px;font-weight:800}
input,select,textarea,.readonly{width:100%;border:1px solid #d4dde7;border-radius:16px;padding:12px 14px;font-size:14px;background:#fff;color:#18304b}
textarea{min-height:150px;resize:vertical}
.readonly{background:#f6f9fc;line-height:1.6}
.custom-select{position:relative;z-index:20}
.custom-select-input{display:none}
.custom-select-toggle{width:100%;min-height:46px;border:1px solid #d4dde7;border-radius:16px;padding:0 44px 0 14px;font-size:14px;font-weight:700;text-align:left;background:linear-gradient(180deg,#fff,#f8fbff);color:#18304b;cursor:pointer;position:relative}
.custom-select-toggle::after{content:"";position:absolute;top:50%;right:16px;width:9px;height:9px;border-right:2px solid #64748b;border-bottom:2px solid #64748b;transform:translateY(-65%) rotate(45deg);transition:transform .18s ease}
.custom-select.is-open .custom-select-toggle{border-color:#38a7ff;box-shadow:0 0 0 4px rgba(56,167,255,.14)}
.custom-select.is-open .custom-select-toggle::after{transform:translateY(-30%) rotate(-135deg)}
.custom-select-menu{position:absolute;top:calc(100% + 8px);left:0;right:0;z-index:40;display:none;max-height:220px;overflow-y:auto;padding:8px;border:1px solid #d8e3ef;border-radius:20px;background:#fff;box-shadow:0 22px 40px rgba(15,23,42,.14)}
.custom-select.is-open .custom-select-menu{display:grid;gap:6px}
.custom-select-option{width:100%;display:flex;align-items:center;justify-content:space-between;border:0;border-radius:13px;padding:11px 12px;background:transparent;color:#18304b;font-size:14px;font-weight:700;text-align:left;cursor:pointer}
.custom-select-option:hover,.custom-select-option.is-selected{background:#dbeafe;color:#1d4ed8}
.custom-select-option.is-selected::after{content:"✓";font-weight:900}
.section-title{font-size:20px;font-weight:800;margin:20px 0 12px;color:#1f3652}
.notice{padding:12px 14px;border-radius:14px;margin-bottom:14px}
.success{background:#edf8f1;border:1px solid #c7e8d1;color:#1f7a3f}
.error{background:#fff1f1;border:1px solid #ebc7c7;color:#8a1f1f}
.actions{display:flex;gap:10px;flex-wrap:wrap}
.footer-actions{margin-top:18px;display:flex;justify-content:flex-end}
@media(max-width:1024px){.layout{grid-template-columns:1fr}.content{padding:18px}.grid{grid-template-columns:1fr}.grid-wide{grid-column:span 1}}
body.theme-dark{background:linear-gradient(180deg,#07111f 0%,#0b1729 100%);color:#e5eef9}
body.theme-dark .topbar,body.theme-dark .card{background:rgba(12,21,36,.9);border-color:rgba(65,85,110,.72)}
body.theme-dark h1,body.theme-dark .section-title{color:#f4f8fd}
body.theme-dark p,body.theme-dark .muted,label{color:#9eb2c9}
body.theme-dark input,body.theme-dark select,body.theme-dark textarea,body.theme-dark .readonly{background:#0f1a2d;border-color:rgba(78,97,121,.7);color:#eef4fb}
body.theme-dark .custom-select-toggle{background:linear-gradient(180deg,#101b2d,#0d1727);border-color:rgba(78,97,121,.7);color:#eef4fb}
body.theme-dark .custom-select-toggle::after{border-color:#9eb2c9}
body.theme-dark .custom-select-menu{background:#0d1727;border-color:#334761;box-shadow:0 18px 40px rgba(0,0,0,.36)}
body.theme-dark .custom-select-option{color:#e5eef9}
body.theme-dark .custom-select-option:hover,body.theme-dark .custom-select-option.is-selected{background:#14375c;color:#8fd4ff}
body.theme-dark .btn-light{background:rgba(255,255,255,.07);color:#eef4fb;border-color:rgba(148,163,184,.18)}
</style>
</head>
<body>
<div class="layout">
<?php $activePage = 'requests'; require __DIR__ . '/sidebar.php'; ?>
<main class="content">
<div class="page">
<div class="topbar">
<div>
<div class="eyebrow">Child Task</div>
<h1>Task <?= (int) $task['task_no'] ?>: <?= htmlspecialchars((string) $task['task_title'], ENT_QUOTES, 'UTF-8') ?></h1>
<p>Parent request #<?= (int) $task['parent_request_id'] ?> for <?= htmlspecialchars((string) $task['requestor_name'], ENT_QUOTES, 'UTF-8') ?>.</p>
</div>
<div class="actions">
<a class="btn btn-light" href="requests.php">Back to Queue</a>
<?= workflowGetPdfLinkHtml((string) $task['pdf_path'], 'btn btn-light', 'Open PDF', '../') ?>
</div>
</div>
<div class="card">
<?php if ($message !== ''): ?><div class="notice success"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="notice error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
<div class="section-title">Task Context</div>
<div class="grid">
<div><label>Company</label><div class="readonly"><?= htmlspecialchars((string) $task['company'], ENT_QUOTES, 'UTF-8') ?></div></div>
<div><label>Department</label><div class="readonly"><?= htmlspecialchars((string) $task['department'], ENT_QUOTES, 'UTF-8') ?></div></div>
<div><label>Location</label><div class="readonly"><?= htmlspecialchars((string) $task['location'], ENT_QUOTES, 'UTF-8') ?></div></div>
<div><label>Type of Task</label><div class="readonly"><?= htmlspecialchars((string) ($task['task_type'] ?? 'Normal'), ENT_QUOTES, 'UTF-8') ?><?= trim((string) ($task['sla_hours'] ?? '')) !== '' ? ' / ' . htmlspecialchars((string) $task['sla_hours'], ENT_QUOTES, 'UTF-8') . 'h SLA' : '' ?></div></div>
<div class="grid-wide"><label>Task Description</label><div class="readonly"><?= nl2br(htmlspecialchars((string) $task['task_description'], ENT_QUOTES, 'UTF-8')) ?></div></div>
<div class="grid-wide"><label>Parent Problem Description</label><div class="readonly"><?= nl2br(htmlspecialchars((string) $task['problem_description'], ENT_QUOTES, 'UTF-8')) ?></div></div>
</div>
<form method="post">
<?= csrfField() ?>
<input type="hidden" name="id" value="<?= (int) $task['id'] ?>">
<div class="section-title">Update Task</div>
<div class="grid">
<div><label>Status</label><div class="custom-select" data-select><input class="custom-select-input" type="hidden" name="status" value="<?= htmlspecialchars((string) $task['status'], ENT_QUOTES, 'UTF-8') ?>"><button class="custom-select-toggle" type="button" data-select-toggle><?= htmlspecialchars(requestStatusLabel((string) $task['status']), ENT_QUOTES, 'UTF-8') ?></button><div class="custom-select-menu"><?php foreach (requestStatusOptions() as $statusValue => $statusLabel): ?><button class="custom-select-option<?= (string) $task['status'] === $statusValue ? ' is-selected' : '' ?>" type="button" data-value="<?= htmlspecialchars($statusValue, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') ?></button><?php endforeach; ?></div></div></div>
<div><label>Assigned At</label><div class="readonly"><?= htmlspecialchars((string) ($task['assigned_at'] ?: 'Not started - task not assigned yet'), ENT_QUOTES, 'UTF-8') ?></div></div>
<div><label>Due At</label><div class="readonly"><?= htmlspecialchars((string) ($task['due_at'] ?: 'Not calculated yet'), ENT_QUOTES, 'UTF-8') ?></div></div>
<div><label>Completed At</label><div class="readonly"><?= htmlspecialchars((string) ($task['completed_at'] ?: 'Not completed yet'), ENT_QUOTES, 'UTF-8') ?></div></div>
<div><label>Total Hour Taken</label><div class="readonly"><?= htmlspecialchars(slaDisplayForRecord($task), ENT_QUOTES, 'UTF-8') ?></div></div>
<div><label>SLA Result</label><div class="readonly"><?= htmlspecialchars((string) ($task['sla_result'] ?? 'Pending'), ENT_QUOTES, 'UTF-8') ?><?= (int) ($task['is_overdue'] ?? 0) === 1 ? ' / Overdue' : '' ?></div></div>
<div class="grid-wide"><label>Solution</label><textarea name="solution"><?= htmlspecialchars((string) $task['solution'], ENT_QUOTES, 'UTF-8') ?></textarea></div>
</div>
<div class="footer-actions"><button class="btn btn-primary" type="submit">Save Task Update</button></div>
</form>
</div>
</div>
</main>
</div>
<script>
document.querySelectorAll('[data-select]').forEach(function(select){var input=select.querySelector('.custom-select-input');var toggle=select.querySelector('[data-select-toggle]');var options=Array.from(select.querySelectorAll('.custom-select-option'));if(!input||!toggle||options.length===0){return;}toggle.addEventListener('click',function(){document.querySelectorAll('[data-select]').forEach(function(other){if(other!==select){other.classList.remove('is-open');}});select.classList.toggle('is-open');});options.forEach(function(option){option.addEventListener('click',function(){input.value=option.getAttribute('data-value')||'';toggle.textContent=option.textContent||'';options.forEach(function(item){item.classList.toggle('is-selected',item===option);});select.classList.remove('is-open');});});});
document.addEventListener('click',function(event){document.querySelectorAll('[data-select]').forEach(function(select){if(!select.contains(event.target)){select.classList.remove('is-open');}});});
</script>
</body>
</html>
