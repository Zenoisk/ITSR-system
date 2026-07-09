<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/login/auth.php';
requireAdmin();
require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/includes/workflow.php';

ensureItsrWorkflowSchema();

$activePage = 'email_logs';
$pdo = db();

$filterType    = trim((string) ($_GET['type'] ?? ''));
$filterStatus  = trim((string) ($_GET['status'] ?? ''));
$filterTicket  = (int) trim((string) ($_GET['ticket'] ?? 0));
$page          = max(1, (int) ($_GET['page'] ?? 1));
$perPage       = 50;
$offset        = ($page - 1) * $perPage;

$where  = [];
$params = [];

if ($filterType !== '') {
    $where[] = 'email_type = :email_type';
    $params[':email_type'] = $filterType;
}
if ($filterStatus !== '') {
    $where[] = 'status = :status';
    $params[':status'] = $filterStatus;
}
if ($filterTicket > 0) {
    $where[] = 'request_id = :request_id';
    $params[':request_id'] = $filterTicket;
}

$whereClause = $where !== [] ? 'WHERE ' . implode(' AND ', $where) : '';
$totalRows = (int) $pdo->prepare("SELECT COUNT(*) FROM email_logs $whereClause")->execute($params) ? $pdo->prepare("SELECT COUNT(*) FROM email_logs $whereClause")->execute($params) : 0;

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM email_logs $whereClause");
$countStmt->execute($params);
$totalRows = (int) $countStmt->fetchColumn();
$totalPages = $totalRows > 0 ? (int) ceil($totalRows / $perPage) : 1;

$stmt = $pdo->prepare("SELECT * FROM email_logs $whereClause ORDER BY sent_at DESC LIMIT :limit OFFSET :offset");
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$logs = $stmt->fetchAll();

// Summary counts
$summaryStmt = $pdo->query("SELECT status, COUNT(*) as cnt FROM email_logs GROUP BY status");
$summaryCounts = ['sent' => 0, 'failed' => 0];
foreach ($summaryStmt->fetchAll() as $row) {
    $summaryCounts[$row['status']] = (int) $row['cnt'];
}

$emailTypeLabels = [
    'submission'       => 'Submission',
    'return'           => 'Return',
    'staff_assignment' => 'Staff Assignment',
    'staff_task_assignment' => 'Task Assignment',
    'requestor_assigned' => 'Requestor Assigned',
    'completion'       => 'Completion',
];

function esc(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Logs — Admin ITSR</title>
    <link rel="stylesheet" href="../assets/enterprise-ui.css?v=1.1">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: Inter, "Segoe UI", Roboto, Arial, sans-serif; }
        body {
            background:
                radial-gradient(circle at top left, rgba(56, 189, 248, 0.08), transparent 28%),
                linear-gradient(180deg, var(--ui-bg-top) 0%, var(--ui-bg-bottom) 100%);
            color: var(--ui-text);
            transition: background 0.3s ease, color 0.22s ease;
        }
        .layout { min-height: 100vh; display: grid; grid-template-columns: 280px 1fr; }
        .content { padding: 28px 32px; min-width: 0; }
        @media (max-width: 1024px) {
            .layout { grid-template-columns: 1fr; }
            .content { padding: 18px; }
        }
        h1 { font-size: 22px; font-weight: 700; color: var(--text-primary, #1a2637); margin-bottom: 6px; }
        .page-sub { color: var(--text-muted, #6e8aab); font-size: 14px; margin-bottom: 24px; }

        .summary-row { display: flex; gap: 16px; margin-bottom: 24px; flex-wrap: wrap; }
        .summary-card { flex: 1; min-width: 140px; background: #fff; border: 1px solid #dce8f5; border-radius: 14px; padding: 16px 20px; }
        .summary-card .val { font-size: 28px; font-weight: 700; color: #0f2d5e; }
        .summary-card .lbl { font-size: 12px; color: #7a96b8; text-transform: uppercase; letter-spacing: .06em; margin-top: 2px; }

        .filter-bar { background: #fff; border: 1px solid #dce8f5; border-radius: 18px; padding: 20px 24px; margin-bottom: 20px; display: flex; gap: 16px; align-items: flex-end; flex-wrap: wrap; box-shadow: 0 6px 20px rgba(15, 23, 42, 0.03); }
        .filter-bar label { font-size: 12px; font-weight: 700; color: #2c4a6e; display: block; margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.04em; }
        .filter-bar input {
            height: 38px;
            padding: 8px 14px;
            border: 1.5px solid #c5d5e8;
            border-radius: 16px;
            font-size: 13px;
            font-family: inherit;
            background: #f8fafd;
            outline: none;
            transition: border-color 0.18s ease, box-shadow 0.18s ease;
        }
        .filter-bar input:focus {
            border-color: #1565c0;
            box-shadow: 0 0 0 3px rgba(21, 101, 192, 0.12);
        }
        .btn-filter {
            height: 38px;
            padding: 0 24px;
            background: #1565c0;
            color: #fff;
            border: none;
            border-radius: 16px;
            font-size: 13px;
            font-weight: 700;
            font-family: inherit;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: background 0.18s ease, transform 0.18s ease, box-shadow 0.18s ease;
        }
        .btn-filter:hover {
            background: #0d47a1;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(21, 101, 192, 0.2);
        }
        .btn-reset {
            height: 38px;
            padding: 0 18px;
            background: #f5f7fa;
            color: #4e6e8e;
            border: 1px solid #d0dce8;
            border-radius: 16px;
            font-size: 13px;
            font-weight: 600;
            font-family: inherit;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: background 0.18s ease, border-color 0.18s ease, transform 0.18s ease;
        }
        .btn-reset:hover {
            background: #eef2f7;
            border-color: #b0c2d4;
            transform: translateY(-1px);
        }

        /* Custom Dropdown Select Component Styles */
        .custom-select {
            position: relative;
            z-index: 20;
            display: inline-block;
            min-width: 180px;
        }
        .custom-select-input {
            display: none;
        }
        .custom-select-toggle {
            width: 100%;
            height: 38px;
            padding: 0 36px 0 14px;
            border: 1.5px solid #c5d5e8;
            border-radius: 16px;
            font-size: 13px;
            font-weight: 600;
            text-align: left;
            background: #f8fafd;
            color: #2c4a6e;
            cursor: pointer;
            position: relative;
            transition: border-color 0.18s ease, box-shadow 0.18s ease;
        }
        .custom-select-toggle:hover {
            border-color: #a4bedc;
        }
        .custom-select-toggle:focus {
            outline: none;
            border-color: #1565c0;
            box-shadow: 0 0 0 3px rgba(21, 101, 192, 0.12);
        }
        .custom-select-toggle::after {
            content: '';
            position: absolute;
            top: 50%;
            right: 14px;
            width: 7px;
            height: 7px;
            border-right: 2px solid #5a7594;
            border-bottom: 2px solid #5a7594;
            transform: translateY(-65%) rotate(45deg);
            transition: transform 0.18s ease;
        }
        .custom-select.is-open .custom-select-toggle::after {
            transform: translateY(-30%) rotate(-135deg);
        }
        .custom-select-menu {
            position: absolute;
            top: calc(100% + 6px);
            left: 0;
            right: 0;
            z-index: 50;
            padding: 6px;
            border-radius: 16px;
            border: 1px solid #dce8f5;
            background: #ffffff;
            box-shadow: 0 16px 36px rgba(15, 23, 42, 0.12);
            display: none;
            max-height: 220px;
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
            border-radius: 11px;
            padding: 8px 10px;
            font-size: 13px;
            font-weight: 500;
            text-align: left;
            color: #2c4a6e;
            cursor: pointer;
            transition: background 0.15s ease, color 0.15s ease;
        }
        .custom-select-option:hover,
        .custom-select-option.is-selected {
            background: #eef4ff;
            color: #1565c0;
        }

        /* Dark theme compatibility */
        body.theme-dark .filter-bar {
            background: #0d1727;
            border-color: #334761;
            box-shadow: none;
        }
        body.theme-dark .filter-bar label {
            color: #9eb2c9;
        }
        body.theme-dark .filter-bar input {
            background: #101b2d;
            border-color: rgba(78, 97, 121, 0.7);
            color: #eef4fb;
        }
        body.theme-dark .filter-bar input:focus {
            border-color: #38a7ff;
        }
        body.theme-dark .custom-select-toggle {
            background: #101b2d;
            border-color: rgba(78, 97, 121, 0.7);
            color: #eef4fb;
        }
        body.theme-dark .custom-select-toggle::after {
            border-color: #9eb2c9;
        }
        body.theme-dark .custom-select-menu {
            background: #101b2d;
            border-color: #334761;
            box-shadow: 0 12px 28px rgba(0, 0, 0, 0.3);
        }
        body.theme-dark .custom-select-option {
            color: #e5eef9;
        }
        body.theme-dark .custom-select-option:hover,
        body.theme-dark .custom-select-option.is-selected {
            background: #14375c;
            color: #8fd4ff;
        }
        body.theme-dark .btn-reset {
            background: #101b2d;
            color: #9eb2c9;
            border-color: rgba(78, 97, 121, 0.7);
        }
        body.theme-dark .btn-reset:hover {
            background: #18283f;
            border-color: #4e6e8e;
            color: #eef4fb;
        }
        body.theme-dark .summary-card {
            background: #0d1727;
            border-color: #334761;
        }
        body.theme-dark .summary-card .val {
            color: #e5eef9;
        }
        body.theme-dark .summary-card .lbl {
            color: #72859b;
        }
        body.theme-dark table {
            background: #0d1727;
            border-color: #334761;
        }
        body.theme-dark thead {
            background: #101b2d;
        }
        body.theme-dark th {
            color: #9eb2c9;
            border-bottom-color: #334761;
        }
        body.theme-dark td {
            color: #e5eef9;
            border-bottom-color: #1a2c42;
        }
        body.theme-dark tr:hover td {
            background: #132238;
        }
        body.theme-dark .type-pill {
            background: #142844;
            color: #8fd4ff;
        }

        table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 14px; overflow: hidden; border: 1px solid #dce8f5; }
        thead { background: #f0f6ff; }
        th { font-size: 12px; font-weight: 700; color: #4e6e8e; text-transform: uppercase; letter-spacing: .05em; padding: 12px 14px; text-align: left; border-bottom: 1px solid #dce8f5; }
        td { font-size: 13px; padding: 11px 14px; border-bottom: 1px solid #f0f4f8; color: #1a2637; vertical-align: top; }
        tr:last-child td { border-bottom: none; }
        tr:hover td { background: #f5f9ff; }
        .badge { display: inline-block; padding: 3px 10px; border-radius: 99px; font-size: 11px; font-weight: 700; }
        .badge-sent { background: #e8f5e9; color: #2e7d32; }
        .badge-failed { background: #fdecea; color: #c62828; }
        .type-pill { background: #eef3fb; color: #1565c0; padding: 3px 10px; border-radius: 99px; font-size: 11px; font-weight: 600; }
        .ticket-link { color: #1565c0; text-decoration: none; font-weight: 600; }
        .ticket-link:hover { text-decoration: underline; }
        .error-msg { color: #c62828; font-size: 12px; max-width: 280px; word-break: break-word; }
        .empty-row td { text-align: center; color: #9ab; padding: 40px; font-size: 14px; }

        .pagination { display: flex; gap: 8px; margin-top: 20px; align-items: center; flex-wrap: wrap; }
        .page-btn { display: inline-block; padding: 6px 14px; border: 1px solid #d0dce8; border-radius: 8px; font-size: 13px; color: #1565c0; text-decoration: none; background: #fff; }
        .page-btn.active { background: #1565c0; color: #fff; border-color: #1565c0; }
        .page-btn:hover:not(.active) { background: #eef4ff; }
    </style>
</head>
<body>
<div class="layout">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <main class="content">
        <h1>📧 Email Logs</h1>
        <div class="page-sub">Audit trail of all email notifications sent by the ITSR system.</div>

    <div class="summary-row">
        <div class="summary-card">
            <div class="val"><?= $summaryCounts['sent'] + $summaryCounts['failed'] ?></div>
            <div class="lbl">Total Emails</div>
        </div>
        <div class="summary-card">
            <div class="val" style="color:#2e7d32"><?= $summaryCounts['sent'] ?></div>
            <div class="lbl">Sent</div>
        </div>
        <div class="summary-card">
            <div class="val" style="color:#c62828"><?= $summaryCounts['failed'] ?></div>
            <div class="lbl">Failed</div>
        </div>
    </div>

    <form method="GET" action="email_logs.php" class="filter-bar">
        <div>
            <label>Email Type</label>
            <div class="custom-select" data-select>
                <input class="custom-select-input" type="hidden" name="type" value="<?= esc($filterType) ?>">
                <button class="custom-select-toggle" type="button" data-select-toggle>
                    <?php
                    if ($filterType === '') {
                        echo 'All Types';
                    } else {
                        echo esc($emailTypeLabels[$filterType] ?? $filterType);
                    }
                    ?>
                </button>
                <div class="custom-select-menu">
                    <button class="custom-select-option<?= $filterType === '' ? ' is-selected' : '' ?>" type="button" data-value="">All Types</button>
                    <?php foreach ($emailTypeLabels as $val => $lbl): ?>
                        <button class="custom-select-option<?= $filterType === $val ? ' is-selected' : '' ?>" type="button" data-value="<?= esc($val) ?>"><?= esc($lbl) ?></button>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <div>
            <label>Status</label>
            <div class="custom-select" data-select>
                <input class="custom-select-input" type="hidden" name="status" value="<?= esc($filterStatus) ?>">
                <button class="custom-select-toggle" type="button" data-select-toggle>
                    <?php
                    echo match ($filterStatus) {
                        'sent' => 'Sent',
                        'failed' => 'Failed',
                        default => 'All',
                    };
                    ?>
                </button>
                <div class="custom-select-menu">
                    <button class="custom-select-option<?= $filterStatus === '' ? ' is-selected' : '' ?>" type="button" data-value="">All</button>
                    <button class="custom-select-option<?= $filterStatus === 'sent' ? ' is-selected' : '' ?>" type="button" data-value="sent">Sent</button>
                    <button class="custom-select-option<?= $filterStatus === 'failed' ? ' is-selected' : '' ?>" type="button" data-value="failed">Failed</button>
                </div>
            </div>
        </div>
        <div>
            <label>Ticket #</label>
            <input type="number" name="ticket" min="1" placeholder="e.g. 42" value="<?= $filterTicket > 0 ? $filterTicket : '' ?>" style="width:110px">
        </div>
        <button type="submit" class="btn-filter">Filter</button>
        <a href="email_logs.php" class="btn-reset">Reset</a>
    </form>

    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>Ticket</th>
                <th>Type</th>
                <th>Recipient</th>
                <th>Subject</th>
                <th>Status</th>
                <th>Error</th>
                <th>Sent At</th>
            </tr>
        </thead>
        <tbody>
        <?php if ($logs === []): ?>
            <tr class="empty-row"><td colspan="8">No email log entries found.</td></tr>
        <?php else: ?>
            <?php foreach ($logs as $log): ?>
            <tr>
                <td><?= (int) $log['id'] ?></td>
                <td>
                    <?php if ((int) ($log['request_id'] ?? 0) > 0): ?>
                    <a class="ticket-link" href="request_edit.php?id=<?= (int) $log['request_id'] ?>">#<?= (int) $log['request_id'] ?></a>
                    <?php else: ?>—<?php endif; ?>
                </td>
                <td><span class="type-pill"><?= esc($emailTypeLabels[(string) ($log['email_type'] ?? '')] ?? (string) ($log['email_type'] ?? '—')) ?></span></td>
                <td><?= esc((string) ($log['recipient_email'] ?? '')) ?></td>
                <td><?= esc((string) ($log['subject'] ?? '')) ?></td>
                <td>
                    <span class="badge badge-<?= esc((string) ($log['status'] ?? 'failed')) ?>">
                        <?= esc(ucfirst((string) ($log['status'] ?? ''))) ?>
                    </span>
                </td>
                <td>
                    <?php $err = trim((string) ($log['error_message'] ?? '')); ?>
                    <?php if ($err !== ''): ?>
                    <div class="error-msg" title="<?= esc($err) ?>"><?= esc(mb_substr($err, 0, 80)) ?><?= mb_strlen($err) > 80 ? '…' : '' ?></div>
                    <?php else: ?>—<?php endif; ?>
                </td>
                <td><?= esc((string) ($log['sent_at'] ?? '')) ?></td>
            </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>

    <?php if ($totalPages > 1): ?>
    <div class="pagination">
        <?php for ($p = 1; $p <= min(10, $totalPages); $p++): ?>
            <a class="page-btn <?= $p === $page ? 'active' : '' ?>" href="?<?= http_build_query(array_merge($_GET, ['page' => $p])) ?>"><?= $p ?></a>
        <?php endfor; ?>
        <?php if ($totalPages > 10): ?>
            <span style="color:#7a96b8;font-size:13px;">... <?= $totalPages ?> pages total (<?= $totalRows ?> records)</span>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    </main>
</div>
<script>
    document.querySelectorAll('[data-select]').forEach(function (select) {
        const hiddenInput = select.querySelector('.custom-select-input');
        const toggle = select.querySelector('[data-select-toggle]');
        const options = Array.from(select.querySelectorAll('.custom-select-option'));

        if (!hiddenInput || !toggle || options.length === 0) {
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
                hiddenInput.value = nextValue;
                toggle.textContent = option.textContent || '';
                options.forEach(function (item) {
                    item.classList.remove('is-selected');
                });
                option.classList.add('is-selected');
                select.classList.remove('is-open');
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
</script>
</body>
</html>
