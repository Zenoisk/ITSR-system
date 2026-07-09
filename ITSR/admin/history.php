<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/login/auth.php';
requireAdmin();
require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/includes/workflow.php';

function historyStatusMeta(string $status): array
{
    return [requestStatusLabel($status), requestStatusClass($status)];
}

function archiveRedirectUrl(array $extra = []): string
{
    $query = [];
    $search = trim((string) ($_GET['search'] ?? ''));

    if ($search !== '') {
        $query['search'] = $search;
    }

    foreach ($extra as $key => $value) {
        $query[$key] = $value;
    }

    $queryString = http_build_query($query);
    return 'history.php' . ($queryString !== '' ? '?' . $queryString : '');
}

$pdo = db();
ensureItsrWorkflowSchema();
$search = trim((string) ($_GET['search'] ?? ''));
$selectedId = (int) ($_GET['id'] ?? 0);
$historyRows = [];
$selectedRow = null;
$selectedSnapshot = [];
$error = '';
$cleared = isset($_GET['cleared']) && $_GET['cleared'] === '1';
$summary = [
    'total' => 0,
    'today' => 0,
    'with_pdf' => 0,
];

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['clear_history'] ?? '') === '1') {
        csrfValidateOrDie();
        $pdo->exec('DELETE FROM deleted_requests');
        logSystemActivity('history_cleared', 'Deleted archive cleared', 'Admin cleared the deleted request archive history.');
        header('Location: history.php?cleared=1');
        exit;
    }

    $summary['total'] = (int) $pdo->query('SELECT COUNT(*) FROM deleted_requests')->fetchColumn();
    $summary['today'] = (int) $pdo->query('SELECT COUNT(*) FROM deleted_requests WHERE DATE(deleted_at) = CURDATE()')->fetchColumn();
    $summary['with_pdf'] = (int) $pdo->query("SELECT COUNT(*) FROM deleted_requests WHERE pdf_path <> ''")->fetchColumn();

    $conditions = [];
    $params = [];

    if ($search !== '') {
        $conditions[] = '(original_request_id = :search_id OR requestor_name LIKE :search_text OR requestor_email LIKE :search_text OR company LIKE :search_text OR department LIKE :search_text OR location LIKE :search_text OR assign_to LIKE :search_text OR deleted_by_username LIKE :search_text)';
        $params[':search_text'] = '%' . $search . '%';
        $params[':search_id'] = ctype_digit($search) ? (int) $search : 0;
    }

    $sql = 'SELECT * FROM deleted_requests';
    if ($conditions !== []) {
        $sql .= ' WHERE ' . implode(' AND ', $conditions);
    }
    $sql .= ' ORDER BY deleted_at DESC, id DESC LIMIT 120';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $historyRows = $stmt->fetchAll();

    if ($selectedId > 0) {
        $selectedStmt = $pdo->prepare('SELECT * FROM deleted_requests WHERE id = :id LIMIT 1');
        $selectedStmt->execute([':id' => $selectedId]);
        $selectedRow = $selectedStmt->fetch() ?: null;
    } elseif ($historyRows !== []) {
        $selectedRow = $historyRows[0];
    }

    if ($selectedRow && trim((string) ($selectedRow['snapshot_json'] ?? '')) !== '') {
        $decoded = json_decode((string) $selectedRow['snapshot_json'], true);
        if (is_array($decoded)) {
            $selectedSnapshot = $decoded;
        }
    }
} catch (Throwable $exception) {
    $error = appErrorMessage($exception, 'Deleted request history failed', 'Unable to load deleted request history.');
}

$selectedPdfPath = trim((string) ($selectedRow['pdf_path'] ?? ''));
$selectedPdfAbsolutePath = $selectedPdfPath !== ''
    ? dirname(__DIR__) . DIRECTORY_SEPARATOR . 'form' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $selectedPdfPath)
    : '';
$selectedPdfExists = $selectedPdfPath !== '' && is_file($selectedPdfAbsolutePath);
[$selectedStatusText, $selectedStatusClass] = historyStatusMeta((string) ($selectedRow['status'] ?? 'pending'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Deleted Request History</title>
    <link rel="stylesheet" href="../assets/enterprise-ui.css">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: Inter, "Segoe UI", Roboto, Arial, sans-serif; }
        body {
            background:
                radial-gradient(circle at top left, rgba(56, 189, 248, 0.08), transparent 28%),
                linear-gradient(180deg, #eef4fb 0%, #f6f9fc 100%);
            color: #132538;
            transition: background 0.3s ease, color 0.22s ease;
        }
        .layout { min-height: 100vh; display: grid; grid-template-columns: 280px 1fr; }
        .content { padding: 28px; animation: pageEnter 0.28s ease; }
        .page { max-width: 1320px; width: 100%; margin: 0 auto; }
        .hero, .panel, .stat-card {
            background: rgba(255, 255, 255, 0.9);
            border: 1px solid rgba(209, 220, 232, 0.9);
            border-radius: 24px;
            box-shadow: 0 18px 38px rgba(15, 23, 42, 0.08);
            backdrop-filter: blur(10px);
            transition: transform 0.22s ease, box-shadow 0.24s ease, border-color 0.24s ease, background 0.24s ease;
        }
        .hero:hover, .panel:hover, .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 24px 44px rgba(15, 23, 42, 0.11);
        }
        .hero {
            padding: 26px 28px;
            margin-bottom: 18px;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 16px;
            flex-wrap: wrap;
        }
        .eyebrow {
            display: inline-block;
            color: #2563eb;
            font-size: 12px;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            margin-bottom: 12px;
            font-weight: 700;
        }
        .hero h1 { font-size: 34px; margin-bottom: 10px; color: #0f2642; }
        .hero p { max-width: 780px; line-height: 1.65; color: #64748b; }
        .hero-actions { display: flex; gap: 10px; flex-wrap: wrap; }
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 44px;
            padding: 0 18px;
            text-decoration: none;
            border-radius: 14px;
            font-size: 14px;
            font-weight: 700;
            border: 1px solid #d7e0ea;
            background: #fff;
            color: #334155;
            transition: transform 0.18s ease, background 0.18s ease, border-color 0.18s ease;
        }
        .btn:hover { transform: translateY(-1px); }
        .btn-primary {
            background: linear-gradient(180deg, #2563eb 0%, #1d4ed8 100%);
            border-color: transparent;
            color: #ffffff;
        }
        .btn-danger {
            background: linear-gradient(180deg, #ef4444 0%, #dc2626 100%);
            border-color: transparent;
            color: #ffffff;
        }
        .stats {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 14px;
            margin-bottom: 18px;
        }
        .stat-card { padding: 18px 20px; }
        .stat-card strong {
            display: block;
            font-size: 30px;
            line-height: 1;
            color: #102949;
            margin-bottom: 8px;
        }
        .stat-card span {
            color: #6f8298;
            font-size: 14px;
            line-height: 1.5;
        }
        .searchbar {
            margin-bottom: 18px;
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto auto;
            gap: 12px;
        }
        .searchbar input {
            width: 100%;
            border: 1px solid #d4dde7;
            border-radius: 16px;
            padding: 13px 16px;
            font-size: 14px;
            outline: none;
            background: #fff;
        }
        .searchbar input:focus {
            border-color: #2c71ba;
            box-shadow: 0 0 0 4px rgba(44, 113, 186, 0.10);
        }
        .workspace {
            display: grid;
            grid-template-columns: minmax(0, 0.95fr) minmax(360px, 1.15fr);
            gap: 18px;
        }
        .panel { overflow: hidden; }
        .panel-head {
            padding: 18px 20px;
            border-bottom: 1px solid #e7edf3;
            background: linear-gradient(180deg, #f9fbfd 0%, #f4f8fb 100%);
        }
        .panel-head h2 { font-size: 22px; color: #0f2642; }
        .panel-head p { color: #66788b; font-size: 14px; margin-top: 4px; }
        .history-list {
            display: grid;
            gap: 12px;
            padding: 14px;
        }
        .history-item {
            position: relative;
            display: grid;
            grid-template-columns: 46px minmax(0, 1fr) 28px;
            align-items: center;
            gap: 14px;
            padding: 16px 18px;
            border: 1px solid #dce8f6;
            border-radius: 18px;
            background: linear-gradient(135deg, rgba(239, 246, 255, 0.98) 0%, rgba(248, 251, 255, 0.96) 100%);
            text-decoration: none;
            color: inherit;
            overflow: hidden;
            box-shadow: 0 10px 24px rgba(15, 23, 42, 0.05);
            transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease, background 0.2s ease;
        }
        .history-item::before {
            content: "";
            position: absolute;
            inset: 0 auto 0 0;
            width: 5px;
            background: linear-gradient(180deg, #2563eb 0%, #38bdf8 100%);
            opacity: 0;
            transition: opacity 0.2s ease;
        }
        .history-item:hover {
            transform: translateY(-2px);
            border-color: rgba(37, 99, 235, 0.35);
            box-shadow: 0 16px 30px rgba(15, 23, 42, 0.09);
            background: #ffffff;
        }
        .history-item.is-active {
            border-color: rgba(37, 99, 235, 0.36);
            background: linear-gradient(135deg, #eaf2ff 0%, #f7fbff 100%);
        }
        .history-item.is-active::before,
        .history-item:hover::before { opacity: 1; }
        .history-icon {
            width: 46px;
            height: 46px;
            border-radius: 16px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #2563eb;
            background: linear-gradient(180deg, #e0efff 0%, #f1f7ff 100%);
            border: 1px solid rgba(37, 99, 235, 0.12);
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.9);
        }
        .history-icon svg {
            width: 21px;
            height: 21px;
            fill: none;
            stroke: currentColor;
            stroke-width: 2;
            stroke-linecap: round;
            stroke-linejoin: round;
        }
        .history-content {
            min-width: 0;
            display: grid;
            gap: 6px;
        }
        .history-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            flex-wrap: wrap;
        }
        .history-title {
            font-weight: 700;
            color: #102949;
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        .history-meta {
            color: #708194;
            font-size: 13px;
            line-height: 1.55;
            overflow-wrap: anywhere;
        }
        .history-delete {
            color: #7e90a5;
            font-size: 12px;
            line-height: 1.45;
        }
        .history-arrow {
            width: 28px;
            height: 28px;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #7b8da3;
            background: rgba(255, 255, 255, 0.82);
            border: 1px solid rgba(215, 224, 234, 0.9);
            transition: transform 0.2s ease, color 0.2s ease, background 0.2s ease;
        }
        .history-item:hover .history-arrow {
            transform: translateX(2px);
            color: #2563eb;
            background: #ffffff;
        }
        .history-arrow svg {
            width: 15px;
            height: 15px;
            fill: none;
            stroke: currentColor;
            stroke-width: 2.4;
            stroke-linecap: round;
            stroke-linejoin: round;
        }
        .detail-body { padding: 20px; display: grid; gap: 16px; }
        .detail-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; }
        .detail-item {
            border: 1px solid #e6edf4;
            border-radius: 18px;
            padding: 14px 16px;
            background: #fbfdff;
        }
        .detail-label {
            color: #708194;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            margin-bottom: 6px;
        }
        .detail-value {
            color: #102949;
            font-weight: 700;
            line-height: 1.55;
            word-break: break-word;
        }
        .detail-section {
            border: 1px solid #e6edf4;
            border-radius: 18px;
            padding: 16px;
            background: #fbfdff;
        }
        .detail-section h3 {
            font-size: 15px;
            color: #102949;
            margin-bottom: 10px;
        }
        .detail-text {
            color: #26415d;
            line-height: 1.7;
            white-space: pre-wrap;
        }
        .pill {
            display: inline-flex;
            align-items: center;
            padding: 7px 12px;
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
        .notice {
            padding: 14px 16px;
            border-radius: 16px;
            background: #fff1f1;
            border: 1px solid #ebc7c7;
            color: #8a1f1f;
            margin-bottom: 16px;
        }
        .notice-success {
            background: #edf8f1;
            border-color: #c7e8d1;
            color: #1f7a3f;
        }
        .toast {
            position: fixed;
            top: 50%;
            left: 50%;
            z-index: 1200;
            min-width: 320px;
            max-width: min(440px, calc(100vw - 32px));
            margin: 0;
            box-shadow: 0 20px 40px rgba(15, 23, 42, 0.18);
            transform: translate(-50%, -50%);
            animation: toastIn 0.28s ease;
        }
        .empty {
            padding: 22px;
            color: #66788b;
            line-height: 1.6;
        }
        .detail-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        @keyframes pageEnter {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        @keyframes toastIn {
            from { opacity: 0; transform: translate(-50%, calc(-50% - 10px)); }
            to { opacity: 1; transform: translate(-50%, -50%); }
        }
        @media (max-width: 1080px) {
            .workspace { grid-template-columns: 1fr; }
        }
        @media (max-width: 1024px) {
            .layout { grid-template-columns: 1fr; }
            .content { padding: 18px; }
            .stats, .searchbar, .detail-grid { grid-template-columns: 1fr; }
            .history-item { grid-template-columns: 42px minmax(0, 1fr); }
            .history-arrow { display: none; }
        }
        body.theme-dark {
            background: linear-gradient(180deg, #07111f 0%, #0b1729 100%);
            color: #e5eef9;
        }
        body.theme-dark .hero,
        body.theme-dark .panel,
        body.theme-dark .stat-card,
        body.theme-dark .detail-item,
        body.theme-dark .detail-section {
            background: rgba(12, 21, 36, 0.9);
            border-color: rgba(65, 85, 110, 0.72);
            box-shadow: 0 18px 38px rgba(0, 0, 0, 0.24);
        }
        body.theme-dark .panel-head {
            background: linear-gradient(180deg, #0f1a2d 0%, #0b1525 100%);
            border-bottom-color: rgba(70, 88, 112, 0.6);
        }
        body.theme-dark .hero h1,
        body.theme-dark .panel-head h2,
        body.theme-dark .history-title,
        body.theme-dark .detail-value,
        body.theme-dark .detail-section h3,
        body.theme-dark .stat-card strong { color: #f4f8fd; }
        body.theme-dark .hero p,
        body.theme-dark .panel-head p,
        body.theme-dark .history-meta,
        body.theme-dark .detail-label,
        body.theme-dark .detail-text,
        body.theme-dark .empty,
        body.theme-dark .stat-card span { color: #9eb2c9; }
        body.theme-dark .history-item {
            border-color: rgba(70, 88, 112, 0.62);
            background: linear-gradient(135deg, rgba(15, 26, 45, 0.96) 0%, rgba(11, 21, 37, 0.94) 100%);
            box-shadow: 0 14px 28px rgba(0, 0, 0, 0.22);
        }
        body.theme-dark .history-item:hover {
            background: rgba(255, 255, 255, 0.04);
            border-color: rgba(96, 165, 250, 0.5);
        }
        body.theme-dark .history-item.is-active {
            background: linear-gradient(135deg, rgba(37, 99, 235, 0.24) 0%, rgba(15, 26, 45, 0.96) 100%);
        }
        body.theme-dark .history-icon {
            color: #93c5fd;
            background: rgba(37, 99, 235, 0.18);
            border-color: rgba(147, 197, 253, 0.22);
        }
        body.theme-dark .history-delete,
        body.theme-dark .history-arrow { color: #9eb2c9; }
        body.theme-dark .history-arrow {
            background: rgba(15, 26, 45, 0.82);
            border-color: rgba(70, 88, 112, 0.68);
        }
        body.theme-dark .searchbar input,
        body.theme-dark .btn {
            background: #0f1a2d;
            border-color: rgba(78, 97, 121, 0.7);
            color: #eef4fb;
        }
        body.theme-dark .btn-primary {
            background: linear-gradient(180deg, #2563eb 0%, #1d4ed8 100%);
            border-color: transparent;
            color: #ffffff;
        }
        body.theme-dark .btn-danger {
            background: linear-gradient(180deg, #ef4444 0%, #dc2626 100%);
            border-color: transparent;
            color: #ffffff;
        }
        body.theme-dark .notice { background: rgba(12, 21, 36, 0.9); }
        body.theme-dark .notice-success { color: #7ee0a0; }
    </style>
</head>
<body>
    <div class="layout">
        <?php $activePage = 'history'; require __DIR__ . '/sidebar.php'; ?>
        <main class="content">
            <div class="page">
                <section class="hero">
                    <div>
                        <div class="eyebrow">Administrator History</div>
                        <h1>Deleted Request Archive</h1>
                        <p>Every request deleted by admin is archived here first, so the team can still review the original customer details, status, and problem notes later.</p>
                    </div>
                    <div class="hero-actions">
                        <a class="btn" href="requests.php">Back to Requests</a>
                        <a class="btn btn-primary" href="<?= htmlspecialchars(archiveRedirectUrl(), ENT_QUOTES, 'UTF-8') ?>">Refresh Archive</a>
                        <?php if ($summary['total'] > 0): ?>
                            <form method="post" onsubmit="return confirm('Clear the entire deleted request archive list?');">
                                <?= csrfField() ?>
                                <input type="hidden" name="clear_history" value="1">
                                <button class="btn btn-danger" type="submit">Clear List</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </section>

                <div class="stats">
                    <div class="stat-card">
                        <strong><?= $summary['total'] ?></strong>
                        <span>Total deleted requests preserved in archive history.</span>
                    </div>
                    <div class="stat-card">
                        <strong><?= $summary['today'] ?></strong>
                        <span>Requests deleted today that admin may want to double-check.</span>
                    </div>
                    <div class="stat-card">
                        <strong><?= $summary['with_pdf'] ?></strong>
                        <span>Archived items that still have a saved PDF record available.</span>
                    </div>
                </div>

                <?php if ($error !== ''): ?>
                    <div class="notice toast" data-toast><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>
                <?php if ($cleared): ?>
                    <div class="notice notice-success toast" data-toast>Deleted request history cleared successfully.</div>
                <?php endif; ?>

                <form class="searchbar" method="get">
                    <input type="text" name="search" placeholder="Search request ID, requestor, company, department, location, assigned staff, or deleted by" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>">
                    <button class="btn btn-primary" type="submit">Search</button>
                    <a class="btn" href="history.php">Clear</a>
                </form>

                <div class="workspace">
                    <section class="panel">
                        <div class="panel-head">
                            <h2>Deleted Forms</h2>
                            <p><?= count($historyRows) ?> archived record(s) shown in the current view.</p>
                        </div>
                        <?php if ($historyRows === []): ?>
                            <div class="empty">No deleted request history yet. Once an admin deletes a request, it will appear here for review.</div>
                        <?php else: ?>
                            <div class="history-list">
                                <?php foreach ($historyRows as $row): ?>
                                    <?php [$rowStatusText, $rowStatusClass] = historyStatusMeta((string) ($row['status'] ?? 'pending')); ?>
                                    <a class="history-item<?= $selectedRow && (int) $selectedRow['id'] === (int) $row['id'] ? ' is-active' : '' ?>" href="<?= htmlspecialchars(archiveRedirectUrl(['id' => (int) $row['id']]), ENT_QUOTES, 'UTF-8') ?>">
                                        <span class="history-icon" aria-hidden="true">
                                            <svg viewBox="0 0 24 24"><path d="M4 7h16"/><path d="M9 7V4.8A.8.8 0 0 1 9.8 4h4.4a.8.8 0 0 1 .8.8V7"/><path d="M18 7l-.7 10.2a2 2 0 0 1-2 1.8H8.7a2 2 0 0 1-2-1.8L6 7"/><path d="M10 11.2v4.6M14 11.2v4.6"/></svg>
                                        </span>
                                        <span class="history-content">
                                            <span class="history-top">
                                                <span class="history-title">Request #<?= (int) $row['original_request_id'] ?></span>
                                                <span class="pill <?= $rowStatusClass ?>"><?= htmlspecialchars($rowStatusText, ENT_QUOTES, 'UTF-8') ?></span>
                                            </span>
                                            <span class="history-meta"><?= htmlspecialchars((string) $row['requestor_name'], ENT_QUOTES, 'UTF-8') ?: 'Unknown requestor' ?> | <?= htmlspecialchars((string) $row['company'], ENT_QUOTES, 'UTF-8') ?: 'No company' ?> | <?= htmlspecialchars((string) $row['department'], ENT_QUOTES, 'UTF-8') ?: 'No department' ?></span>
                                            <span class="history-delete">Deleted by <?= htmlspecialchars((string) $row['deleted_by_username'], ENT_QUOTES, 'UTF-8') ?: 'Unknown' ?> on <?= htmlspecialchars((string) $row['deleted_at'], ENT_QUOTES, 'UTF-8') ?></span>
                                        </span>
                                        <span class="history-arrow" aria-hidden="true">
                                            <svg viewBox="0 0 24 24"><path d="M9 6l6 6-6 6"/></svg>
                                        </span>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </section>

                    <section class="panel">
                        <div class="panel-head">
                            <h2>Archived Details</h2>
                            <p><?= $selectedRow ? 'Full snapshot of the deleted request at the time it was removed.' : 'Select a deleted form to review its archived details.' ?></p>
                        </div>
                        <?php if (!$selectedRow): ?>
                            <div class="empty">Choose a deleted request from the left to see the archived record.</div>
                        <?php else: ?>
                            <div class="detail-body">
                                <div class="detail-grid">
                                    <div class="detail-item">
                                        <div class="detail-label">Original Request ID</div>
                                        <div class="detail-value">#<?= (int) $selectedRow['original_request_id'] ?></div>
                                    </div>
                                    <div class="detail-item">
                                        <div class="detail-label">Archived Status</div>
                                        <div class="detail-value"><span class="pill <?= $selectedStatusClass ?>"><?= htmlspecialchars($selectedStatusText, ENT_QUOTES, 'UTF-8') ?></span></div>
                                    </div>
                                    <div class="detail-item">
                                        <div class="detail-label">Requestor</div>
                                        <div class="detail-value"><?= htmlspecialchars((string) $selectedRow['requestor_name'], ENT_QUOTES, 'UTF-8') ?: '-' ?></div>
                                    </div>
                                    <div class="detail-item">
                                        <div class="detail-label">Email / Phone</div>
                                        <div class="detail-value"><?= htmlspecialchars((string) $selectedRow['requestor_email'], ENT_QUOTES, 'UTF-8') ?: '-' ?><br><?= htmlspecialchars((string) $selectedRow['requestor_phone'], ENT_QUOTES, 'UTF-8') ?: '-' ?></div>
                                    </div>
                                    <div class="detail-item">
                                        <div class="detail-label">Company / Department</div>
                                        <div class="detail-value"><?= htmlspecialchars((string) $selectedRow['company'], ENT_QUOTES, 'UTF-8') ?: '-' ?><br><?= htmlspecialchars((string) $selectedRow['department'], ENT_QUOTES, 'UTF-8') ?: '-' ?></div>
                                    </div>
                                    <div class="detail-item">
                                        <div class="detail-label">Request Date</div>
                                        <div class="detail-value"><?= htmlspecialchars((string) ($selectedRow['request_date'] ?? ''), ENT_QUOTES, 'UTF-8') ?: '-' ?></div>
                                    </div>
                                    <div class="detail-item">
                                        <div class="detail-label">Assigned To</div>
                                        <div class="detail-value"><?= htmlspecialchars((string) $selectedRow['assign_to'], ENT_QUOTES, 'UTF-8') ?: 'Not assigned' ?></div>
                                    </div>
                                    <div class="detail-item">
                                        <div class="detail-label">Location</div>
                                        <div class="detail-value"><?= htmlspecialchars((string) $selectedRow['location'], ENT_QUOTES, 'UTF-8') ?: '-' ?></div>
                                    </div>
                                    <div class="detail-item">
                                        <div class="detail-label">Deleted By</div>
                                        <div class="detail-value"><?= htmlspecialchars((string) $selectedRow['deleted_by_username'], ENT_QUOTES, 'UTF-8') ?: 'Unknown' ?></div>
                                    </div>
                                    <div class="detail-item">
                                        <div class="detail-label">Deleted At</div>
                                        <div class="detail-value"><?= htmlspecialchars((string) $selectedRow['deleted_at'], ENT_QUOTES, 'UTF-8') ?: '-' ?></div>
                                    </div>
                                </div>

                                <div class="detail-actions">
                                    <?php if ($selectedPdfExists): ?>
                                        <a class="btn btn-primary" href="../form/download_pdf.php?portal=admin&amp;file=<?= rawurlencode(basename($selectedPdfPath)) ?>" target="_blank" rel="noopener">Open Saved PDF</a>
                                    <?php endif; ?>
                                    <a class="btn" href="requests.php">Go to Requests</a>
                                </div>

                                <div class="detail-section">
                                    <h3>Description / Justification</h3>
                                    <div class="detail-text"><?= htmlspecialchars((string) ($selectedSnapshot['problem_description'] ?? 'No problem description archived.'), ENT_QUOTES, 'UTF-8') ?></div>
                                </div>

                                <div class="detail-section">
                                    <h3>Corrective Action / Solution</h3>
                                    <div class="detail-text"><?= htmlspecialchars((string) ($selectedSnapshot['corrective_action'] ?? 'No corrective action archived.'), ENT_QUOTES, 'UTF-8') ?></div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </section>
                </div>
            </div>
        </main>
    </div>
    <script>
        document.querySelectorAll('[data-toast]').forEach(function (toast) {
            window.setTimeout(function () {
                toast.style.opacity = '0';
                toast.style.transform = 'translate(-50%, calc(-50% - 10px))';
                window.setTimeout(function () { toast.remove(); }, 220);
            }, 2800);
        });
    </script>
</body>
</html>
