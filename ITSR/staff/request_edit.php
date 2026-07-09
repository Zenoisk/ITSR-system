<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/login/auth.php';
requireStaff();
require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/includes/workflow.php';
require_once dirname(__DIR__) . '/includes/notifications.php';

function requestOrFail(PDO $pdo, int $id): array
{
    $stmt = $pdo->prepare('SELECT * FROM service_requests WHERE id = :id AND assign_to = :assign_to LIMIT 1');
    $stmt->execute([':id' => $id, ':assign_to' => currentUsername()]);
    $request = $stmt->fetch();

    if (!$request) {
        http_response_code(404);
        echo 'Request not found.';
        exit;
    }

    return $request;
}

function posted(string $key): string
{
    return trim((string) ($_POST[$key] ?? ''));
}

function requestStatusMeta(array $request): array
{
    $status = (string) ($request['status'] ?? 'pending');

    return [requestStatusLabel($status), requestStatusClass($status)];
}

$pdo = db();
ensureItsrWorkflowSchema();
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$request = requestOrFail($pdo, $id);
slaRefreshOpenRecord($pdo, 'service_requests', $id);
$request = requestOrFail($pdo, $id);
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfValidateOrDie();
    try {
        $previousStatus = (string) ($request['status'] ?? 'pending');
        $postedStatus = posted('status');
        $status = array_key_exists($postedStatus, requestStatusOptions()) ? $postedStatus : 'assigned';
        $stmt = $pdo->prepare(
            'UPDATE service_requests SET
                status = :status,
                corrective_action = :corrective_action
             WHERE id = :id'
        );

        $stmt->execute([
            ':status' => $status,
            ':corrective_action' => posted('corrective_action'),
            ':id' => $id,
        ]);

        $request = requestOrFail($pdo, $id);
        if ((string) ($request['status'] ?? '') === 'completed' && $previousStatus !== 'completed') {
            $completionData = slaCompleteData($request);
            $pdo->prepare(
                'UPDATE service_requests
                 SET completed_at = :completed_at,
                     date_complete = DATE(:completed_at),
                     actual_working_minutes_taken = :actual_working_minutes_taken,
                     total_hour_taken_display = :total_hour_taken_display,
                     total_hour_taken = :total_hour_taken,
                     sla_result = :sla_result,
                     is_overdue = :is_overdue
                 WHERE id = :id'
            )->execute([
                ':completed_at' => $completionData['completed_at'],
                ':actual_working_minutes_taken' => $completionData['actual_working_minutes_taken'],
                ':total_hour_taken_display' => $completionData['total_hour_taken_display'],
                ':total_hour_taken' => $completionData['total_hour_taken'],
                ':sla_result' => $completionData['sla_result'],
                ':is_overdue' => $completionData['is_overdue'],
                ':id' => $id,
            ]);
            addRequestLog($id, null, 'sla_result_calculated', 'Staff completed request. Time taken: ' . $completionData['total_hour_taken_display'] . '.');
            $request = requestOrFail($pdo, $id);
        }
        logSystemActivity(
            'request_updated',
            'Request updated by staff',
            'Staff updated request #' . $id . ' to ' . strtolower(requestStatusLabel((string) ($request['status'] ?? 'pending'))) . '.'
        );
        addRequestLog($id, null, 'staff_updated_request', 'Staff updated whole request to ' . requestStatusLabel((string) ($request['status'] ?? 'pending')) . '.');
        $emailResults = [];
        if ((string) ($request['status'] ?? '') === 'completed' && $previousStatus !== 'completed') {
            $emailResults[] = itsrNotifyRequestorCompleted($request, (string) ($request['corrective_action'] ?? ''));
        }
        $message = 'Request updated successfully.' . itsrNotificationStatusMessage($emailResults);
    } catch (Throwable $exception) {
        $error = appErrorMessage($exception, 'Staff request update failed', 'Unable to update the request.');
    }
}

[$requestStatusText, $requestStatusClass] = requestStatusMeta($request);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Staff Request #<?= $id ?></title>
<link rel="stylesheet" href="../assets/enterprise-ui.css">
<style>
*{box-sizing:border-box;margin:0;padding:0;font-family:Inter,"Segoe UI",Roboto,Arial,sans-serif}
body{background:radial-gradient(circle at top left,rgba(56,189,248,.08),transparent 28%),linear-gradient(180deg,#eef4fb 0%,#f6f9fc 100%);color:#132538}
.layout{min-height:100vh;display:grid;grid-template-columns:280px 1fr}
.content{padding:28px}
.page{max-width:1320px}
.topbar,.card,.aside{background:rgba(255,255,255,.9);border:1px solid rgba(209,220,232,.9);border-radius:26px;box-shadow:0 18px 38px rgba(15,23,42,.08);backdrop-filter:blur(10px)}
.topbar{padding:26px 28px;margin-bottom:18px;display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap}
.eyebrow{display:inline-block;color:#0ea5e9;font-size:12px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;margin-bottom:12px}
.topbar h1{font-size:34px;margin-bottom:8px;color:#0f2642;letter-spacing:-.03em}
.topbar p,.meta{color:#66788b;line-height:1.65}
.actions{display:flex;gap:10px;flex-wrap:wrap}
.btn{display:inline-flex;align-items:center;justify-content:center;text-decoration:none;border-radius:14px;min-height:42px;padding:0 16px;font-size:14px;font-weight:700;border:1px solid transparent;cursor:pointer;transition:transform .18s ease}
.btn:hover{transform:translateY(-1px)}
.btn-primary{background:linear-gradient(180deg,#0ea5e9 0%,#0284c7 100%);color:#fff}
.btn-light{background:#fff;color:#334155;border-color:#d7e0ea}
.workspace{display:grid;grid-template-columns:minmax(0,1.45fr) 340px;gap:18px}
.card{padding:24px}
.aside{padding:22px;align-self:start;position:sticky;top:28px}
.notice{padding:12px 14px;border-radius:16px;margin-bottom:16px;font-size:14px}
.success{background:#edf8f1;border:1px solid #c7e8d1;color:#1f7a3f}
.error{background:#fff1f1;border:1px solid #ebc7c7;color:#8a1f1f}
.toast{position:fixed;top:24px;right:24px;z-index:1200;min-width:280px;max-width:380px;box-shadow:0 20px 40px rgba(15,23,42,.16);animation:toastIn .28s ease}
.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}
.grid-wide{grid-column:span 2}
.readonly{background:#f5f8fb;border:1px solid #d9e4ef;border-radius:18px;padding:12px 14px;color:#36516c;font-size:14px;line-height:1.6}
.section-title{font-size:19px;font-weight:700;margin:18px 0 12px;color:#1f3652}
label{display:block;margin-bottom:6px;font-size:13px;font-weight:700;color:#36516c}
input,textarea,select{width:100%;border:1px solid #d4dde7;border-radius:18px;padding:12px 14px;font-size:14px;outline:none;background:#fff}
textarea{min-height:140px;resize:vertical}
input:focus,textarea:focus,select:focus{border-color:#2c71ba;box-shadow:0 0 0 4px rgba(44,113,186,.10)}
.custom-select{position:relative;z-index:20}
.custom-select-input{display:none}
.custom-select-toggle{width:100%;min-height:48px;border:1px solid #d4dde7;border-radius:18px;padding:0 44px 0 14px;font-size:14px;font-weight:600;text-align:left;background:#fff;color:#1f3652;cursor:pointer;position:relative;transition:border-color .18s ease,box-shadow .18s ease}
.custom-select-toggle:hover{border-color:#b7c7d9}
.custom-select-toggle:focus{outline:none;border-color:#2c71ba;box-shadow:0 0 0 4px rgba(44,113,186,.10)}
.custom-select-toggle::after{content:'';position:absolute;top:50%;right:16px;width:9px;height:9px;border-right:2px solid #6a7c91;border-bottom:2px solid #6a7c91;transform:translateY(-65%) rotate(45deg);transition:transform .18s ease}
.custom-select.is-open .custom-select-toggle::after{transform:translateY(-30%) rotate(-135deg)}
.custom-select-menu{position:absolute;top:calc(100% + 8px);left:0;right:0;z-index:40;padding:8px;border-radius:22px;border:1px solid #d8e3ef;background:#ffffff;box-shadow:0 22px 40px rgba(15,23,42,.14);display:none;max-height:190px;overflow-y:auto;scrollbar-width:thin}
.custom-select.is-open .custom-select-menu{display:block}
.custom-select-option{width:100%;border:0;background:transparent;border-radius:14px;padding:11px 12px;font-size:14px;text-align:left;color:#1f3652;cursor:pointer;transition:background .18s ease,color .18s ease}
.custom-select-option:hover,.custom-select-option.is-selected{background:#dbeafe;color:#1d4ed8}
.custom-select-menu::-webkit-scrollbar{width:8px}
.custom-select-menu::-webkit-scrollbar-track{background:transparent}
.custom-select-menu::-webkit-scrollbar-thumb{background:rgba(148,163,184,.6);border-radius:999px}
.footer-actions{margin-top:20px;display:flex;justify-content:flex-end;gap:12px;flex-wrap:wrap}
.status-pill{display:inline-flex;align-items:center;padding:8px 12px;border-radius:999px;font-size:12px;font-weight:700}
.status-pending{background:#fff5dc;color:#9a6a00}
.status-returned{background:#fee2e2;color:#b91c1c}
.status-resubmitted{background:#f3e8ff;color:#7e22ce}
.status-assigned{background:#e0f2fe;color:#0369a1}
.status-progress{background:#e9f3ff;color:#1d63b8}
.status-completed{background:#eaf8ef;color:#1f7a3f}
.status-reopened{background:#f3e8ff;color:#7e22ce}
.aside h2{font-size:22px;color:#102949;margin-bottom:10px}
.aside p{color:#6c7b8a;font-size:14px;line-height:1.6}
.aside-list{display:grid;gap:12px;margin-top:18px}
.aside-item{padding:14px 16px;border-radius:18px;border:1px solid #e6edf4;background:#fbfdff}
.aside-label{display:block;color:#708194;font-size:12px;letter-spacing:.08em;text-transform:uppercase;margin-bottom:6px}
.aside-value{color:#102949;font-weight:700;line-height:1.45;word-break:break-word}
.aside-actions{display:grid;gap:10px;margin-top:18px}
@media (max-width:1100px){.workspace{grid-template-columns:1fr}.aside{position:static}}
@media (max-width:1024px){.layout{grid-template-columns:1fr}.content{padding:18px}.grid{grid-template-columns:1fr}.grid-wide{grid-column:span 1}}
body.theme-dark{background:linear-gradient(180deg,#07111f 0%,#0b1729 100%);color:#e5eef9}
body.theme-dark .topbar,body.theme-dark .card,body.theme-dark .aside{background:rgba(12,21,36,.9);border-color:rgba(65,85,110,.72);box-shadow:0 18px 38px rgba(0,0,0,.24)}
body.theme-dark .topbar h1,body.theme-dark .section-title,body.theme-dark .aside h2,body.theme-dark .aside-value{color:#f4f8fd}
body.theme-dark .topbar p,body.theme-dark .meta,body.theme-dark label,body.theme-dark .aside p,body.theme-dark .aside-label{color:#bfd2e7}
body.theme-dark input,body.theme-dark textarea,body.theme-dark select,body.theme-dark .readonly,body.theme-dark .aside-item{background:#0f1a2d;border-color:rgba(78,97,121,.7);color:#eef4fb}
body.theme-dark .custom-select-toggle{background:#0f1a2d;border-color:rgba(78,97,121,.7);color:#eef4fb}
body.theme-dark .custom-select-toggle::after{border-color:#9eb2c9}
body.theme-dark .custom-select-menu{background:#0f1a2d;border-color:rgba(78,97,121,.7);box-shadow:0 24px 44px rgba(0,0,0,.32)}
body.theme-dark .custom-select-option{color:#eef4fb}
body.theme-dark .custom-select-option:hover,body.theme-dark .custom-select-option.is-selected{background:#1e3a6d;color:#ffffff}
body.theme-dark .btn-light{background:rgba(255,255,255,.07);color:#eef4fb;border-color:rgba(148,163,184,.18)}
body.theme-dark .success,body.theme-dark .error{background:rgba(12,21,36,.9)}
@keyframes toastIn{from{opacity:0;transform:translateY(-8px)}to{opacity:1;transform:translateY(0)}}
</style>
</head>
<body>
<div class="layout">
<?php $activePage='requests'; require __DIR__ . '/sidebar.php'; ?>
<main class="content">
<div class="page">
<div class="topbar">
<div>
<div class="eyebrow">Work Update</div>
<h1>Update Request #<?= $id ?></h1>
<p>Keep customer context visible while you update progress, completion details, and the final corrective action for the assigned request.</p>
<div class="meta">Created at <?= htmlspecialchars((string) $request['created_at'], ENT_QUOTES, 'UTF-8') ?></div>
</div>
<div class="actions">
<a class="btn btn-light" href="requests.php">Back to Queue</a>
<?= workflowGetPdfLinkHtml((string) $request['pdf_path'], 'btn btn-light', 'Open PDF', '../') ?>
</div>
</div>
<div class="workspace">
<div class="card">
<?php if ($message !== ''): ?><div class="notice success toast" data-toast><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="notice error toast" data-toast><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
<form method="post">
<?= csrfField() ?>
<input type="hidden" name="id" value="<?= $id ?>">
<div class="section-title">Customer Context</div>
<div class="grid">
<div><label>Company</label><div class="readonly"><?= htmlspecialchars((string) $request['company'], ENT_QUOTES, 'UTF-8') ?></div></div>
<div><label>Department</label><div class="readonly"><?= htmlspecialchars((string) $request['department'], ENT_QUOTES, 'UTF-8') ?></div></div>
<div><label>Requestor Name</label><div class="readonly"><?= htmlspecialchars((string) $request['requestor_name'], ENT_QUOTES, 'UTF-8') ?></div></div>
<div><label>Requestor Phone</label><div class="readonly"><?= htmlspecialchars((string) $request['requestor_phone'], ENT_QUOTES, 'UTF-8') ?></div></div>
<div class="grid-wide">
    <label>Type of Service Request</label>
    <div class="readonly" style="display: flex; flex-wrap: wrap; gap: 8px; min-height: auto; padding: 12px; background: #f8fbff; border: 1px solid #e3edf7; border-radius: 12px;">
        <?php
        $selectedServiceTypes = [];
        if (trim((string) ($request['service_types'] ?? '')) !== '') {
            $decoded = json_decode($request['service_types'], true);
            if (is_array($decoded)) {
                $selectedServiceTypes = $decoded;
            }
        }
        if ($selectedServiceTypes === []) {
            echo '<span style="color: #64748b; font-style: italic;">None selected</span>';
        } else {
            foreach ($selectedServiceTypes as $type) {
                echo '<span style="background: #e2e8f0; color: #1e293b; padding: 4px 10px; border-radius: 999px; font-size: 12.5px; font-weight: 700; border: 1px solid #cbd5e1;">' . htmlspecialchars($type, ENT_QUOTES, 'UTF-8') . '</span>';
            }
        }
        ?>
    </div>
</div>
<div class="grid-wide"><label>Problem Description</label><div class="readonly"><?= nl2br(htmlspecialchars((string) $request['problem_description'], ENT_QUOTES, 'UTF-8')) ?></div></div>
</div>
<div class="section-title">Work Completion</div>
<div class="grid">
<div><label>Status</label><div class="custom-select" data-select><input class="custom-select-input" type="hidden" name="status" value="<?= htmlspecialchars((string) ($request['status'] ?? 'pending'), ENT_QUOTES, 'UTF-8') ?>"><button class="custom-select-toggle" type="button" data-select-toggle><?= htmlspecialchars(requestStatusLabel((string) ($request['status'] ?? 'pending')), ENT_QUOTES, 'UTF-8') ?></button><div class="custom-select-menu"><?php foreach (requestStatusOptions() as $statusValue => $statusLabel): ?><button class="custom-select-option<?= (($request['status'] ?? 'pending') === $statusValue) ? ' is-selected' : '' ?>" type="button" data-value="<?= htmlspecialchars($statusValue, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') ?></button><?php endforeach; ?></div></div></div>
<div><label>Assigned To</label><div class="readonly"><?= htmlspecialchars((string) $request['assign_to'], ENT_QUOTES, 'UTF-8') ?: 'Not assigned yet' ?></div></div>
<div><label>Type of Task</label><div class="readonly"><?= htmlspecialchars((string) ($request['task_type'] ?? 'Normal'), ENT_QUOTES, 'UTF-8') ?><?= trim((string) ($request['sla_hours'] ?? '')) !== '' ? ' / ' . htmlspecialchars((string) $request['sla_hours'], ENT_QUOTES, 'UTF-8') . 'h SLA' : '' ?></div></div>
<div><label>Assigned At</label><div class="readonly"><?= htmlspecialchars((string) ($request['assigned_at'] ?: 'Not started - task not assigned yet'), ENT_QUOTES, 'UTF-8') ?></div></div>
<div><label>Due At</label><div class="readonly"><?= htmlspecialchars((string) ($request['due_at'] ?: 'Not calculated yet'), ENT_QUOTES, 'UTF-8') ?></div></div>
<div><label>Completed At</label><div class="readonly"><?= htmlspecialchars((string) ($request['completed_at'] ?: 'Not completed yet'), ENT_QUOTES, 'UTF-8') ?></div></div>
<div><label>Total Hour Taken</label><div class="readonly"><?= htmlspecialchars(slaDisplayForRecord($request), ENT_QUOTES, 'UTF-8') ?></div></div>
<div><label>SLA Result</label><div class="readonly"><?= htmlspecialchars((string) ($request['sla_result'] ?? 'Pending'), ENT_QUOTES, 'UTF-8') ?><?= (int) ($request['is_overdue'] ?? 0) === 1 ? ' / Overdue' : '' ?></div></div>
<div class="grid-wide"><label>Corrective Action / Solution</label><textarea name="corrective_action"><?= htmlspecialchars((string) $request['corrective_action'], ENT_QUOTES, 'UTF-8') ?></textarea></div>
</div>
<div class="footer-actions"><button class="btn btn-primary" type="submit">Save Changes</button></div>
</form>
</div>
<aside class="aside">
<h2>Request Snapshot</h2>
<p>Use this side panel as a quick reference while you update the working details.</p>
<div class="aside-list">
<div class="aside-item"><span class="aside-label">Current Status</span><span class="status-pill <?= htmlspecialchars($requestStatusClass, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($requestStatusText, ENT_QUOTES, 'UTF-8') ?></span></div>
<div class="aside-item"><span class="aside-label">Assigned Staff</span><div class="aside-value"><?= htmlspecialchars((string) $request['assign_to'], ENT_QUOTES, 'UTF-8') ?: 'Not assigned yet' ?></div></div>
<div class="aside-item"><span class="aside-label">Customer</span><div class="aside-value"><?= htmlspecialchars((string) $request['requestor_name'], ENT_QUOTES, 'UTF-8') ?: '-' ?></div><div class="meta"><?= htmlspecialchars((string) $request['requestor_phone'], ENT_QUOTES, 'UTF-8') ?: '-' ?></div></div>
<div class="aside-item"><span class="aside-label">Location</span><div class="aside-value"><?= htmlspecialchars((string) $request['location'], ENT_QUOTES, 'UTF-8') ?: '-' ?></div></div>
</div>
<div class="aside-actions">
<?= workflowGetPdfLinkHtml((string) $request['pdf_path'], 'btn btn-light', 'Open Saved PDF', '../') ?>
<a class="btn btn-light" href="requests.php">Return to Queue</a>
</div>
</aside>
</div>
</div>
</main>
</div>
<script>
document.querySelectorAll('[data-select]').forEach(function(select){
var hiddenInput=select.querySelector('.custom-select-input');
var toggle=select.querySelector('[data-select-toggle]');
var options=Array.from(select.querySelectorAll('.custom-select-option'));
if(!hiddenInput||!toggle||options.length===0){return;}
toggle.addEventListener('click',function(){
document.querySelectorAll('[data-select]').forEach(function(otherSelect){
if(otherSelect!==select){otherSelect.classList.remove('is-open');}
});
select.classList.toggle('is-open');
});
options.forEach(function(option){
option.addEventListener('click',function(){
hiddenInput.value=option.getAttribute('data-value')||'';
toggle.textContent=option.textContent||'';
options.forEach(function(item){item.classList.remove('is-selected');});
option.classList.add('is-selected');
select.classList.remove('is-open');
});
});
});
document.addEventListener('click',function(event){
document.querySelectorAll('[data-select]').forEach(function(select){
if(!select.contains(event.target)){select.classList.remove('is-open');}
});
});
document.querySelectorAll('[data-toast]').forEach(function(toast){window.setTimeout(function(){toast.style.opacity='0';toast.style.transform='translateY(-8px)';window.setTimeout(function(){toast.remove();},220);},2800);});
</script>
</body>
</html>
