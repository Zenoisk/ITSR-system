<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/login/auth.php';
requireAdmin();
require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/includes/workflow.php';

function reportFilterValue(string $key): string
{
    return trim((string) ($_GET[$key] ?? ''));
}

function buildMhNmhReport(PDO $pdo, int $year, array $filters): array
{
    $serviceWhere = ['sr.completed_at IS NOT NULL', 'YEAR(sr.completed_at) = :service_year'];
    $taskWhere = ['rt.completed_at IS NOT NULL', 'YEAR(rt.completed_at) = :task_year'];
    $params = [
        ':service_year' => $year,
        ':task_year' => $year,
    ];

    if ($filters['company'] !== '') {
        $serviceWhere[] = 'sr.company LIKE :service_company';
        $taskWhere[] = 'sr.company LIKE :task_company';
        $params[':service_company'] = '%' . $filters['company'] . '%';
        $params[':task_company'] = '%' . $filters['company'] . '%';
    }
    if ($filters['department'] !== '') {
        $serviceWhere[] = 'sr.department LIKE :service_department';
        $taskWhere[] = 'sr.department LIKE :task_department';
        $params[':service_department'] = '%' . $filters['department'] . '%';
        $params[':task_department'] = '%' . $filters['department'] . '%';
    }
    if ($filters['staff'] !== '') {
        $serviceWhere[] = 'sr.assign_to = :service_staff';
        $taskWhere[] = 'rt.assigned_to = :task_staff';
        $params[':service_staff'] = $filters['staff'];
        $params[':task_staff'] = $filters['staff'];
    }
    if ($filters['task_type'] !== '') {
        $serviceWhere[] = 'sr.task_type = :service_task_type';
        $taskWhere[] = 'rt.task_type = :task_task_type';
        $params[':service_task_type'] = $filters['task_type'];
        $params[':task_task_type'] = $filters['task_type'];
    }
    if ($filters['sla_result'] !== '') {
        $serviceWhere[] = 'sr.sla_result = :service_sla_result';
        $taskWhere[] = 'rt.sla_result = :task_sla_result';
        $params[':service_sla_result'] = $filters['sla_result'];
        $params[':task_sla_result'] = $filters['sla_result'];
    }
    if ($filters['status'] !== '') {
        $serviceWhere[] = 'sr.status = :service_status';
        $taskWhere[] = 'rt.status = :task_status';
        $params[':service_status'] = $filters['status'];
        $params[':task_status'] = $filters['status'];
    }

    $sql = '
        SELECT report_month, COUNT(*) AS total_request,
               SUM(CASE WHEN sla_result = "MH" THEN 1 ELSE 0 END) AS mh_count,
               SUM(CASE WHEN sla_result = "NMH" THEN 1 ELSE 0 END) AS nmh_count
        FROM (
            SELECT MONTH(sr.completed_at) AS report_month, sr.sla_result
            FROM service_requests sr
            WHERE ' . implode(' AND ', $serviceWhere) . '
            UNION ALL
            SELECT MONTH(rt.completed_at) AS report_month, rt.sla_result
            FROM request_tasks rt
            INNER JOIN service_requests sr ON sr.id = rt.parent_request_id
            WHERE ' . implode(' AND ', $taskWhere) . '
        ) report_items
        GROUP BY report_month
        ORDER BY report_month ASC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rawRows = $stmt->fetchAll();

    $months = [];
    foreach (range(1, 12) as $month) {
        $months[$month] = [
            'month' => date('M', mktime(0, 0, 0, $month, 1)),
            'total_request' => 0,
            'mh_count' => 0,
            'nmh_count' => 0,
        ];
    }

    foreach ($rawRows as $row) {
        $month = (int) ($row['report_month'] ?? 0);
        if (isset($months[$month])) {
            $months[$month]['total_request'] = (int) ($row['total_request'] ?? 0);
            $months[$month]['mh_count'] = (int) ($row['mh_count'] ?? 0);
            $months[$month]['nmh_count'] = (int) ($row['nmh_count'] ?? 0);
        }
    }

    return array_values($months);
}

$pdo = db();
ensureItsrWorkflowSchema();

$year = (int) ($_GET['year'] ?? date('Y'));
if ($year < 2000 || $year > 2100) {
    $year = (int) date('Y');
}

$filters = [
    'company' => reportFilterValue('company'),
    'department' => reportFilterValue('department'),
    'staff' => reportFilterValue('staff'),
    'task_type' => in_array(reportFilterValue('task_type'), ['Urgent', 'Priority', 'Normal'], true) ? reportFilterValue('task_type') : '',
    'sla_result' => in_array(reportFilterValue('sla_result'), ['MH', 'NMH'], true) ? reportFilterValue('sla_result') : '',
    'status' => array_key_exists(reportFilterValue('status'), requestStatusOptions()) ? reportFilterValue('status') : '',
];

$rows = buildMhNmhReport($pdo, $year, $filters);
$totals = ['total_request' => 0, 'mh_count' => 0, 'nmh_count' => 0];
foreach ($rows as $row) {
    $totals['total_request'] += (int) $row['total_request'];
    $totals['mh_count'] += (int) $row['mh_count'];
    $totals['nmh_count'] += (int) $row['nmh_count'];
}

if (($_GET['export'] ?? '') === 'excel') {
    logSystemActivity('report_generated', 'MH/NMH report exported', 'Admin exported MH/NMH report for ' . $year . '.');
    $filename = 'ITSR_MH_NMH_Report_' . $year . '.xls';
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo "\xEF\xBB\xBF";
    ?>
    <table border="1">
        <tr><th colspan="4">SUMMARY OF SERVICE REQUEST <?= (int) $year ?></th></tr>
        <tr><th>Month</th><th>Total Request</th><th>Meet Hour (MH)</th><th>Not Meet Hour (NMH)</th></tr>
        <?php foreach ($rows as $row): ?>
            <tr>
                <td><?= htmlspecialchars((string) $row['month'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= (int) $row['total_request'] ?></td>
                <td><?= (int) $row['mh_count'] ?></td>
                <td><?= (int) $row['nmh_count'] ?></td>
            </tr>
        <?php endforeach; ?>
        <tr>
            <th>TOTAL</th>
            <th><?= (int) $totals['total_request'] ?></th>
            <th><?= (int) $totals['mh_count'] ?></th>
            <th><?= (int) $totals['nmh_count'] ?></th>
        </tr>
    </table>
    <?php
    exit;
}

$staffUsers = $pdo->query("SELECT username FROM users WHERE role = 'staff' ORDER BY username ASC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Audit Report - ITSR</title>
<link rel="stylesheet" href="../assets/enterprise-ui.css">
<style>
*{box-sizing:border-box;margin:0;padding:0;font-family:Inter,"Segoe UI",Roboto,Arial,sans-serif}
body{background:linear-gradient(180deg,var(--ui-bg-top) 0%,var(--ui-bg-bottom) 100%);color:var(--ui-text)}.layout{min-height:100vh;display:grid;grid-template-columns:280px 1fr}.content{padding:28px}.page{max-width:1320px;margin:0 auto}.hero,.card{background:rgba(255,255,255,.92);border:1px solid #d8e3ef;border-radius:26px;box-shadow:0 18px 38px rgba(15,23,42,.08)}.hero{padding:28px;margin-bottom:18px}.eyebrow{color:#2563eb;font-size:12px;font-weight:800;letter-spacing:.12em;text-transform:uppercase;margin-bottom:10px}.hero h1{font-size:34px;color:#0f2642}.hero p{color:#64748b;line-height:1.65;margin-top:8px}.card{padding:24px}.filters{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-bottom:18px}label{display:block;margin-bottom:7px;font-size:13px;font-weight:800;color:#36516c}input,select{width:100%;border:1px solid #d4dde7;border-radius:16px;padding:13px 14px;font-size:14px;background:#fff;outline:none}.btn{display:inline-flex;align-items:center;justify-content:center;border:0;border-radius:15px;min-height:44px;padding:0 16px;font-weight:800;text-decoration:none;cursor:pointer}.btn-primary{background:linear-gradient(180deg,#2563eb,#1d4ed8);color:#fff}.btn-light{background:#fff;color:#334155;border:1px solid #d7e0ea}.table-wrap{overflow-x:auto}table{width:100%;border-collapse:collapse}th,td{padding:15px;border-bottom:1px solid #e7edf3;text-align:left}th{background:#f7fafd;color:#284764;font-size:13px}.total-row th,.total-row td{background:#eef6ff;font-weight:900}.actions{display:flex;gap:10px;flex-wrap:wrap;align-items:end}
body.theme-dark{background:linear-gradient(180deg,#07111f 0%,#0b1729 100%);color:#e5eef9}body.theme-dark .hero,body.theme-dark .card{background:rgba(12,21,36,.9);border-color:rgba(65,85,110,.72);box-shadow:0 18px 38px rgba(0,0,0,.24)}body.theme-dark .hero h1{color:#f4f8fd}body.theme-dark .hero p,body.theme-dark label{color:#9eb2c9}body.theme-dark input,body.theme-dark select{background:#0f1a2d;border-color:rgba(78,97,121,.7);color:#eef4fb}body.theme-dark th{background:#101b2d;color:#cfe0f4}body.theme-dark td{border-bottom-color:rgba(57,75,99,.6)}body.theme-dark .total-row th,body.theme-dark .total-row td{background:#10223b}body.theme-dark .btn-light{background:rgba(255,255,255,.07);color:#eef4fb;border-color:rgba(148,163,184,.18)}
@media(max-width:1024px){.layout{grid-template-columns:1fr}.filters{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:640px){.content{padding:18px}.filters{grid-template-columns:1fr}}
select[data-modernized]{display:none}
.modern-select{position:relative;z-index:20;width:100%}
.modern-select.is-open{z-index:300}
.modern-select-toggle{width:100%;min-height:48px;border:1px solid #d4dde7;border-radius:18px;padding:0 46px 0 14px;background:linear-gradient(180deg,#fff,#f8fbff);color:#18304b;font-size:14px;font-weight:800;text-align:left;cursor:pointer;position:relative;box-shadow:inset 0 1px 0 rgba(255,255,255,.75);transition:border-color .18s ease,box-shadow .18s ease,background .18s ease}
.modern-select-toggle:hover{border-color:#b7c7d9;background:#fff}
.modern-select-toggle:focus{outline:none;border-color:#2563eb;box-shadow:0 0 0 4px rgba(37,99,235,.12)}
.modern-select-toggle::after{content:"";position:absolute;right:17px;top:50%;width:9px;height:9px;border-right:2px solid #64748b;border-bottom:2px solid #64748b;transform:translateY(-65%) rotate(45deg);transition:transform .18s ease}
.modern-select.is-open .modern-select-toggle::after{transform:translateY(-30%) rotate(-135deg)}
.modern-select-menu{position:absolute;left:0;right:0;top:calc(100% + 8px);display:none;padding:8px;border:1px solid #d8e3ef;border-radius:22px;background:#fff;box-shadow:0 22px 40px rgba(15,23,42,.14);max-height:240px;overflow-y:auto}
.modern-select.is-open .modern-select-menu{display:grid;gap:6px}
.modern-select-option{border:0;border-radius:15px;background:transparent;color:#18304b;padding:11px 12px;text-align:left;font-size:14px;font-weight:800;cursor:pointer}
.modern-select-option:hover,.modern-select-option.is-selected{background:#dbeafe;color:#1d4ed8}
body.theme-dark .modern-select-toggle{background:linear-gradient(180deg,#101b2d,#0d1727);border-color:rgba(78,97,121,.7);color:#eef4fb}
body.theme-dark .modern-select-toggle::after{border-color:#9eb2c9}
body.theme-dark .modern-select-menu{background:#0d1727;border-color:#334761;box-shadow:0 18px 40px rgba(0,0,0,.36)}
body.theme-dark .modern-select-option{color:#e5eef9}
body.theme-dark .modern-select-option:hover,body.theme-dark .modern-select-option.is-selected{background:#14375c;color:#8fd4ff}
</style>
</head>
<body>
<div class="layout">
<?php $activePage = 'reports'; require __DIR__ . '/sidebar.php'; ?>
<main class="content"><div class="page">
<section class="hero"><div class="eyebrow">Audit Report</div><h1>MH / NMH Year Summary</h1><p>Review completed work by month and export a yearly Excel summary for audits.</p></section>
<section class="card">
<form method="get" class="filters">
<div><label>Year</label><input type="number" name="year" value="<?= (int) $year ?>" min="2000" max="2100"></div>
<div><label>Company</label><input type="text" name="company" value="<?= htmlspecialchars($filters['company'], ENT_QUOTES, 'UTF-8') ?>"></div>
<div><label>Department</label><input type="text" name="department" value="<?= htmlspecialchars($filters['department'], ENT_QUOTES, 'UTF-8') ?>"></div>
<div><label>Staff</label><select name="staff"><option value="">All Staff</option><?php foreach ($staffUsers as $staff): ?><option value="<?= htmlspecialchars((string) $staff['username'], ENT_QUOTES, 'UTF-8') ?>" <?= $filters['staff'] === (string) $staff['username'] ? 'selected' : '' ?>><?= htmlspecialchars((string) $staff['username'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></div>
<div><label>Type of Task</label><select name="task_type"><option value="">All Types</option><?php foreach (['Urgent','Priority','Normal'] as $type): ?><option value="<?= $type ?>" <?= $filters['task_type'] === $type ? 'selected' : '' ?>><?= $type ?></option><?php endforeach; ?></select></div>
<div><label>SLA Result</label><select name="sla_result"><option value="">All Results</option><option value="MH" <?= $filters['sla_result'] === 'MH' ? 'selected' : '' ?>>MH</option><option value="NMH" <?= $filters['sla_result'] === 'NMH' ? 'selected' : '' ?>>NMH</option></select></div>
<div><label>Status</label><select name="status"><option value="">All Status</option><?php foreach (requestStatusOptions() as $statusValue => $statusLabel): ?><option value="<?= htmlspecialchars($statusValue, ENT_QUOTES, 'UTF-8') ?>" <?= $filters['status'] === $statusValue ? 'selected' : '' ?>><?= htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></div>
<div class="actions"><button class="btn btn-primary" type="submit">Filter</button><button class="btn btn-light" type="submit" name="export" value="excel">Export Excel</button></div>
</form>
<div class="table-wrap">
<table>
<thead><tr><th>Month</th><th>Total Request</th><th>Meet Hour (MH)</th><th>Not Meet Hour (NMH)</th></tr></thead>
<tbody>
<?php foreach ($rows as $row): ?>
<tr><td><?= htmlspecialchars((string) $row['month'], ENT_QUOTES, 'UTF-8') ?></td><td><?= (int) $row['total_request'] ?></td><td><?= (int) $row['mh_count'] ?></td><td><?= (int) $row['nmh_count'] ?></td></tr>
<?php endforeach; ?>
<tr class="total-row"><th>TOTAL</th><td><?= (int) $totals['total_request'] ?></td><td><?= (int) $totals['mh_count'] ?></td><td><?= (int) $totals['nmh_count'] ?></td></tr>
</tbody>
</table>
</div>
</section>
</div></main>
</div>
<script>
document.querySelectorAll('select').forEach(function(select){
    select.setAttribute('data-modernized','true');
    var wrapper=document.createElement('div');
    wrapper.className='modern-select';
    var toggle=document.createElement('button');
    toggle.type='button';
    toggle.className='modern-select-toggle';
    var menu=document.createElement('div');
    menu.className='modern-select-menu';
    function label(){return select.options[select.selectedIndex] ? select.options[select.selectedIndex].text : 'Select';}
    toggle.textContent=label();
    Array.from(select.options).forEach(function(option){
        var item=document.createElement('button');
        item.type='button';
        item.className='modern-select-option'+(option.selected?' is-selected':'');
        item.textContent=option.text;
        item.addEventListener('click',function(){
            select.value=option.value;
            toggle.textContent=option.text;
            menu.querySelectorAll('.modern-select-option').forEach(function(btn){btn.classList.remove('is-selected');});
            item.classList.add('is-selected');
            wrapper.classList.remove('is-open');
            select.dispatchEvent(new Event('change',{bubbles:true}));
        });
        menu.appendChild(item);
    });
    toggle.addEventListener('click',function(){
        document.querySelectorAll('.modern-select').forEach(function(other){if(other!==wrapper){other.classList.remove('is-open');}});
        wrapper.classList.toggle('is-open');
    });
    select.parentNode.insertBefore(wrapper,select.nextSibling);
    wrapper.appendChild(toggle);
    wrapper.appendChild(menu);
});
document.addEventListener('click',function(event){
    document.querySelectorAll('.modern-select').forEach(function(select){
        if(!select.contains(event.target)){select.classList.remove('is-open');}
    });
});
</script>
</body>
</html>
