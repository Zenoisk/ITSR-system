<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/login/auth.php';
requireAdmin();
require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/includes/workflow.php';

ensureItsrWorkflowSchema();

$activePage = 'backup';
$pdo = db();
$message = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfValidateOrDie();
}

// ─── DB Export (simple SQL dump) ───────────────────────────────────────────
if (($_POST['action'] ?? '') === 'export_sql') {
    try {
        $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        $sql = "-- Enterprise ITSR Database Export\n-- Generated: " . date('Y-m-d H:i:s') . "\n-- Server: MySQL\n-- Charset: utf8mb4\n\nSET FOREIGN_KEY_CHECKS=0;\n\n";

        foreach ($tables as $table) {
            // Drop + create
            $createRow = $pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_NUM);
            $sql .= "\n-- Table: `$table`\nDROP TABLE IF EXISTS `$table`;\n" . $createRow[1] . ";\n\n";

            // Data
            $rows = $pdo->query("SELECT * FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
            if ($rows !== []) {
                $cols = '`' . implode('`, `', array_keys($rows[0])) . '`';
                $sql .= "INSERT INTO `$table` ($cols) VALUES\n";
                $parts = [];
                foreach ($rows as $row) {
                    $vals = array_map(static function ($v) use ($pdo) {
                        if ($v === null) {
                            return 'NULL';
                        }
                        return $pdo->quote((string) $v);
                    }, $row);
                    $parts[] = '(' . implode(', ', $vals) . ')';
                }
                $sql .= implode(",\n", $parts) . ";\n\n";
            }
        }

        $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";

        $filename = 'itsr_backup_' . date('Ymd_His') . '.sql';
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($sql));
        header('Cache-Control: no-cache, no-store, must-revalidate');
        echo $sql;
        exit;
    } catch (Throwable $e) {
        $error = 'Export failed: ' . $e->getMessage();
    }
}

// ─── CSV Export of Requests ─────────────────────────────────────────────────
if (($_POST['action'] ?? '') === 'export_csv') {
    try {
        $rows = $pdo->query("SELECT id, company, department, requestor_name, requestor_email, requestor_phone, status, priority, task_type, sla_result, is_overdue, created_at, assigned_at, completed_at, due_at, total_hour_taken_display FROM service_requests ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
        $filename = 'itsr_requests_' . date('Ymd_His') . '.csv';
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-cache, no-store, must-revalidate');

        $out = fopen('php://output', 'w');
        // BOM for Excel UTF-8
        fwrite($out, "\xEF\xBB\xBF");
        if ($rows !== []) {
            fputcsv($out, array_keys($rows[0]));
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
        }
        fclose($out);
        exit;
    } catch (Throwable $e) {
        $error = 'CSV export failed: ' . $e->getMessage();
    }
}

// ─── Stats ──────────────────────────────────────────────────────────────────
$stats = [
    'requests' => (int) $pdo->query("SELECT COUNT(*) FROM service_requests")->fetchColumn(),
    'tasks'    => (int) $pdo->query("SELECT COUNT(*) FROM request_tasks")->fetchColumn(),
    'logs'     => (int) $pdo->query("SELECT COUNT(*) FROM request_logs")->fetchColumn(),
    'emails'   => (int) $pdo->query("SELECT COUNT(*) FROM email_logs")->fetchColumn(),
    'users'    => (int) $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn(),
];

// PDF & attachment storage size
$pdfDir  = dirname(__DIR__) . '/form/storage/pdfs';
$attDir  = dirname(__DIR__) . '/form/storage/attachments';

function dirSize(string $path): int
{
    if (!is_dir($path)) {
        return 0;
    }
    $size = 0;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->isFile()) {
            $size += $file->getSize();
        }
    }
    return $size;
}

function fmtBytes(int $bytes): string
{
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    if ($bytes < 1048576) {
        return round($bytes / 1024, 1) . ' KB';
    }
    return round($bytes / 1048576, 2) . ' MB';
}

$pdfSize = dirSize($pdfDir);
$attSize = dirSize($attDir);

function esc(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Backup & Export — Admin ITSR</title>
    <link rel="stylesheet" href="../assets/enterprise-ui.css">
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
        .page { max-width: 820px; }
        @media (max-width: 1024px) {
            .layout { grid-template-columns: 1fr; }
            .content { padding: 18px; }
        }
        h1 { font-size: 22px; font-weight: 700; color: var(--text-primary, #1a2637); margin-bottom: 6px; }
        .page-sub { color: var(--text-muted, #6e8aab); font-size: 14px; margin-bottom: 28px; }

        .stats-row { display: flex; flex-wrap: wrap; gap: 14px; margin-bottom: 30px; }
        .stat-card { flex: 1; min-width: 130px; background: #fff; border: 1px solid #dce8f5; border-radius: 14px; padding: 16px 18px; }
        .stat-card .val { font-size: 26px; font-weight: 700; color: #0f2d5e; }
        .stat-card .lbl { font-size: 12px; color: #7a96b8; text-transform: uppercase; letter-spacing: .06em; margin-top: 2px; }

        .export-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; margin-bottom: 30px; }
        @media (max-width: 580px) { .export-grid { grid-template-columns: 1fr; } }
        .export-card { background: #fff; border: 1px solid #dce8f5; border-radius: 16px; padding: 24px; box-shadow: 0 4px 16px rgba(15,45,94,.06); }
        .export-card h2 { font-size: 16px; font-weight: 700; color: #0f2d5e; margin-bottom: 8px; }
        .export-card p { font-size: 13px; color: #4e6e8e; line-height: 1.6; margin-bottom: 18px; }
        .btn-export { display: block; width: 100%; padding: 12px; border: none; border-radius: 10px; font-size: 14px; font-weight: 700; font-family: inherit; cursor: pointer; text-align: center; transition: opacity 0.2s, transform 0.15s; }
        .btn-export:hover { opacity: 0.9; transform: translateY(-1px); }
        .btn-sql { background: linear-gradient(135deg, #1565c0, #0d47a1); color: #fff; }
        .btn-csv { background: linear-gradient(135deg, #2e7d32, #1b5e20); color: #fff; }
        .export-note { font-size: 12px; color: #7a96b8; margin-top: 10px; text-align: center; }

        .storage-card { background: #fff; border: 1px solid #dce8f5; border-radius: 16px; padding: 22px 24px; margin-bottom: 24px; }
        .storage-card h2 { font-size: 16px; font-weight: 700; color: #0f2d5e; margin-bottom: 14px; }
        .storage-row { display: flex; align-items: center; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #f0f4f8; font-size: 14px; }
        .storage-row:last-child { border-bottom: none; }
        .storage-size { font-weight: 700; color: #1565c0; }

        .warn-card { background: #fffde7; border: 1px solid #f0d060; border-radius: 14px; padding: 18px 22px; font-size: 13px; color: #6b4400; line-height: 1.6; }
        .warn-card strong { display: block; margin-bottom: 6px; font-size: 14px; color: #5a3500; }

        .alert-box { padding: 14px 18px; border-radius: 10px; margin-bottom: 18px; font-size: 14px; font-weight: 500; }
        .alert-error { background: #fdecea; border: 1px solid #f5c6c2; color: #b71c1c; }
    </style>
</head>
<body>
<div class="layout">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <main class="content">
        <div class="page">
            <h1>💾 Backup & Export</h1>
            <div class="page-sub">Export database records and manage data backups for production archiving.</div>

    <?php if ($error !== ''): ?>
    <div class="alert-box alert-error"><?= esc($error) ?></div>
    <?php endif; ?>

    <!-- Database Stats -->
    <div class="stats-row">
        <div class="stat-card">
            <div class="val"><?= $stats['requests'] ?></div>
            <div class="lbl">Requests</div>
        </div>
        <div class="stat-card">
            <div class="val"><?= $stats['tasks'] ?></div>
            <div class="lbl">Tasks</div>
        </div>
        <div class="stat-card">
            <div class="val"><?= $stats['logs'] ?></div>
            <div class="lbl">Audit Logs</div>
        </div>
        <div class="stat-card">
            <div class="val"><?= $stats['emails'] ?></div>
            <div class="lbl">Email Logs</div>
        </div>
        <div class="stat-card">
            <div class="val"><?= $stats['users'] ?></div>
            <div class="lbl">Users</div>
        </div>
    </div>

    <!-- Export options -->
    <div class="export-grid">
        <div class="export-card">
            <h2>🗄 Full Database Export (SQL)</h2>
            <p>Download a complete SQL dump of all ITSR tables including requests, tasks, logs, users, SLA settings, and email history. Use this to restore the database on a new server.</p>
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="export_sql">
                <button type="submit" class="btn-export btn-sql">Download SQL Backup</button>
            </form>
            <div class="export-note">Generates a .sql file — import via phpMyAdmin or MySQL CLI.</div>
        </div>
        <div class="export-card">
            <h2>📊 Requests CSV Export</h2>
            <p>Download all service requests as a CSV spreadsheet. Includes ticket number, requestor, department, status, SLA result, and timestamps. Compatible with Excel and Google Sheets.</p>
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="export_csv">
                <button type="submit" class="btn-export btn-csv">Download CSV Export</button>
            </form>
            <div class="export-note">UTF-8 BOM encoded for Excel compatibility.</div>
        </div>
    </div>

    <!-- Storage Usage -->
    <div class="storage-card">
        <h2>📁 File Storage Usage</h2>
        <div class="storage-row">
            <span>PDF Copies (form/storage/pdfs/)</span>
            <span class="storage-size"><?= esc(fmtBytes($pdfSize)) ?></span>
        </div>
        <div class="storage-row">
            <span>Attachments (form/storage/attachments/)</span>
            <span class="storage-size"><?= esc(fmtBytes($attSize)) ?></span>
        </div>
        <div class="storage-row">
            <span>Total File Storage</span>
            <span class="storage-size"><?= esc(fmtBytes($pdfSize + $attSize)) ?></span>
        </div>
    </div>

    <!-- Important notes -->
    <div class="warn-card">
        <strong>⚠ Backup Best Practices</strong>
        Before publishing to the company server or after major changes:<br>
        1. Download the SQL backup and store it in a safe location.<br>
        2. Copy the <code>form/storage/</code> folder (PDFs + attachments) to an external drive or cloud storage.<br>
        3. The SQL export does NOT include uploaded files — you must back up the storage folder separately.<br>
        4. Recommended: schedule a weekly automated backup on the server using mysqldump via cron.
    </div>
        </div>
    </main>
</div>
</body>
</html>
