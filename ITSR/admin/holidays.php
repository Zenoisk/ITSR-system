<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/login/auth.php';
requireAdmin();
require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/includes/workflow.php';

$pdo = db();
ensureItsrWorkflowSchema();

$message = '';
$error = '';
$year = (int) ($_GET['year'] ?? date('Y'));
if ($year < 2000 || $year > 2100) {
    $year = (int) date('Y');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfValidateOrDie();
    try {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'save') {
            $name = trim((string) ($_POST['holiday_name'] ?? ''));
            $date = trim((string) ($_POST['holiday_date'] ?? ''));
            $status = (string) ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';
            $timestamp = strtotime($date);

            if ($name === '' || !$timestamp) {
                throw new RuntimeException('Please enter a valid holiday name and date.');
            }

            $id = (int) ($_POST['id'] ?? 0);
            if ($id > 0) {
                $stmt = $pdo->prepare(
                    'UPDATE public_holidays
                     SET holiday_name = :holiday_name, holiday_date = :holiday_date, year = :year, status = :status, updated_at = NOW()
                     WHERE id = :id'
                );
                $stmt->execute([
                    ':holiday_name' => $name,
                    ':holiday_date' => date('Y-m-d', $timestamp),
                    ':year' => (int) date('Y', $timestamp),
                    ':status' => $status,
                    ':id' => $id,
                ]);
                logSystemActivity('holiday_updated', 'Public holiday updated', $name . ' was updated for SLA calculation.');
                $message = 'Public holiday updated successfully.';
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO public_holidays (holiday_name, holiday_date, year, status)
                     VALUES (:holiday_name, :holiday_date, :year, :status)'
                );
                $stmt->execute([
                    ':holiday_name' => $name,
                    ':holiday_date' => date('Y-m-d', $timestamp),
                    ':year' => (int) date('Y', $timestamp),
                    ':status' => $status,
                ]);
                logSystemActivity('holiday_created', 'Public holiday added', $name . ' was added for SLA calculation.');
                $message = 'Public holiday added successfully.';
            }
            $year = (int) date('Y', $timestamp);
        }

        if ($action === 'toggle') {
            $id = (int) ($_POST['id'] ?? 0);
            $status = (string) ($_POST['next_status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';
            $stmt = $pdo->prepare('UPDATE public_holidays SET status = :status, updated_at = NOW() WHERE id = :id');
            $stmt->execute([':status' => $status, ':id' => $id]);
            logSystemActivity('holiday_updated', 'Public holiday status changed', 'Holiday #' . $id . ' was set to ' . $status . '.');
            $message = 'Holiday status updated.';
        }
    } catch (Throwable $exception) {
        $error = appErrorMessage($exception, 'Public holiday update failed', 'Unable to update the public holiday.');
    }
}

$stmt = $pdo->prepare('SELECT * FROM public_holidays WHERE year = :year ORDER BY holiday_date ASC, id ASC');
$stmt->execute([':year' => $year]);
$holidays = $stmt->fetchAll();
$editHoliday = null;
$editId = (int) ($_GET['edit'] ?? 0);
if ($editId > 0) {
    $editStmt = $pdo->prepare('SELECT * FROM public_holidays WHERE id = :id LIMIT 1');
    $editStmt->execute([':id' => $editId]);
    $editHoliday = $editStmt->fetch() ?: null;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Public Holidays - ITSR</title>
<link rel="stylesheet" href="../assets/enterprise-ui.css">
<style>
*{box-sizing:border-box;margin:0;padding:0;font-family:Inter,"Segoe UI",Roboto,Arial,sans-serif}
body{background:linear-gradient(180deg,var(--ui-bg-top) 0%,var(--ui-bg-bottom) 100%);color:var(--ui-text)}
.layout{min-height:100vh;display:grid;grid-template-columns:280px 1fr}
.content{padding:28px}.page{max-width:1320px;margin:0 auto}.hero,.card{background:rgba(255,255,255,.92);border:1px solid #d8e3ef;border-radius:26px;box-shadow:0 18px 38px rgba(15,23,42,.08)}
.hero{padding:28px;margin-bottom:18px;display:flex;justify-content:space-between;gap:18px;flex-wrap:wrap}.eyebrow{color:#2563eb;font-size:12px;font-weight:800;letter-spacing:.12em;text-transform:uppercase;margin-bottom:10px}.hero h1{font-size:34px;color:#0f2642}.hero p{color:#64748b;line-height:1.65;margin-top:8px}
.grid{display:grid;grid-template-columns:420px minmax(0,1fr);gap:18px}.card{padding:24px}label{display:block;margin-bottom:7px;font-size:13px;font-weight:800;color:#36516c}input,select{width:100%;border:1px solid #d4dde7;border-radius:16px;padding:13px 14px;font-size:14px;background:#fff;outline:none}input:focus,select:focus{border-color:#2563eb;box-shadow:0 0 0 4px rgba(37,99,235,.12)}.field{margin-bottom:14px}.btn{display:inline-flex;align-items:center;justify-content:center;border:0;border-radius:15px;min-height:44px;padding:0 16px;font-weight:800;text-decoration:none;cursor:pointer}.btn-primary{background:linear-gradient(180deg,#2563eb,#1d4ed8);color:#fff}.btn-light{background:#fff;color:#334155;border:1px solid #d7e0ea}.btn-warning{background:#fff7ed;color:#c2410c;border:1px solid #fed7aa}.notice{padding:12px 14px;border-radius:15px;margin-bottom:14px}.success{background:#edf8f1;border:1px solid #c7e8d1;color:#1f7a3f}.error{background:#fff1f1;border:1px solid #ebc7c7;color:#8a1f1f}.table-wrap{overflow-x:auto}table{width:100%;border-collapse:collapse}th,td{padding:15px;border-bottom:1px solid #e7edf3;text-align:left}th{background:#f7fafd;color:#284764;font-size:13px}.badge{display:inline-flex;padding:7px 11px;border-radius:999px;font-size:12px;font-weight:800}.badge-active{background:#dcfce7;color:#15803d}.badge-inactive{background:#e5e7eb;color:#4b5563}.actions{display:flex;gap:10px;flex-wrap:wrap}
body.theme-dark{background:linear-gradient(180deg,#07111f 0%,#0b1729 100%);color:#e5eef9}body.theme-dark .hero,body.theme-dark .card{background:rgba(12,21,36,.9);border-color:rgba(65,85,110,.72);box-shadow:0 18px 38px rgba(0,0,0,.24)}body.theme-dark .hero h1{color:#f4f8fd}body.theme-dark .hero p,body.theme-dark label{color:#9eb2c9}body.theme-dark input,body.theme-dark select{background:#0f1a2d;border-color:rgba(78,97,121,.7);color:#eef4fb}body.theme-dark th{background:#101b2d;color:#cfe0f4}body.theme-dark td{border-bottom-color:rgba(57,75,99,.6)}body.theme-dark .btn-light{background:rgba(255,255,255,.07);color:#eef4fb;border-color:rgba(148,163,184,.18)}
@media(max-width:1024px){.layout{grid-template-columns:1fr}.content{padding:18px}.grid{grid-template-columns:1fr}}
.hero-filter{display:flex;align-items:center;gap:10px}.hero-filter input{width:110px;padding:11px 14px;font-size:15px;border-radius:14px;min-height:auto}.hero-filter .btn{min-height:42px;padding:0 18px;font-size:15px;border-radius:14px}
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
<?php $activePage = 'holidays'; require __DIR__ . '/sidebar.php'; ?>
<main class="content"><div class="page">
<section class="hero">
<div><div class="eyebrow">SLA Calendar</div><h1>Public Holidays</h1><p>Active holidays are skipped by the Total Hour Taken and due-time calculation.</p></div>
<form method="get" class="hero-filter"><input type="number" name="year" value="<?= (int) $year ?>" min="2000" max="2100"><button class="btn btn-light" type="submit">View Year</button></form>
</section>
<?php if ($message !== ''): ?><div class="notice success"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="notice error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
<div class="grid">
<section class="card">
<h2><?= $editHoliday ? 'Edit Public Holiday' : 'Add Public Holiday' ?></h2>
<form method="post" style="margin-top:16px">
<?= csrfField() ?>
<input type="hidden" name="action" value="save">
<input type="hidden" name="id" value="<?= (int) ($editHoliday['id'] ?? 0) ?>">
<div class="field"><label>Holiday Name</label><input type="text" name="holiday_name" required placeholder="Example: Hari Raya" value="<?= htmlspecialchars((string) ($editHoliday['holiday_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></div>
<div class="field"><label>Holiday Date</label><input type="date" name="holiday_date" required value="<?= htmlspecialchars((string) ($editHoliday['holiday_date'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></div>
<div class="field"><label>Status</label><select name="status"><option value="active" <?= (string) ($editHoliday['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>Active</option><option value="inactive" <?= (string) ($editHoliday['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option></select></div>
<div class="actions"><button class="btn btn-primary" type="submit">Save Holiday</button><?php if ($editHoliday): ?><a class="btn btn-light" href="holidays.php?year=<?= (int) $year ?>">Cancel Edit</a><?php endif; ?></div>
</form>
</section>
<section class="card">
<h2>Holiday List for <?= (int) $year ?></h2>
<div class="table-wrap" style="margin-top:16px">
<table>
<thead><tr><th>Date</th><th>Name</th><th>Status</th><th>Action</th></tr></thead>
<tbody>
<?php if (!$holidays): ?>
<tr><td colspan="4">No holidays saved for this year.</td></tr>
<?php endif; ?>
<?php foreach ($holidays as $holiday): ?>
<tr>
<td><?= htmlspecialchars((string) $holiday['holiday_date'], ENT_QUOTES, 'UTF-8') ?></td>
<td><?= htmlspecialchars((string) $holiday['holiday_name'], ENT_QUOTES, 'UTF-8') ?></td>
<td><span class="badge <?= (string) $holiday['status'] === 'active' ? 'badge-active' : 'badge-inactive' ?>"><?= htmlspecialchars((string) $holiday['status'], ENT_QUOTES, 'UTF-8') ?></span></td>
<td>
<div class="actions">
<a class="btn btn-light" href="holidays.php?year=<?= (int) $year ?>&edit=<?= (int) $holiday['id'] ?>">Edit</a>
<form method="post">
<?= csrfField() ?>
<input type="hidden" name="action" value="toggle">
<input type="hidden" name="id" value="<?= (int) $holiday['id'] ?>">
<input type="hidden" name="next_status" value="<?= (string) $holiday['status'] === 'active' ? 'inactive' : 'active' ?>">
<button class="btn btn-warning" type="submit"><?= (string) $holiday['status'] === 'active' ? 'Disable' : 'Activate' ?></button>
</form>
</div>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</section>
</div>
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
