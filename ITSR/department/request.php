<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/login/auth.php';
requireDepartment();
require_once dirname(__DIR__) . '/includes/workflow.php';

$id = (int) ($_GET['id'] ?? 0);
$department = trim(currentUserDepartment());
$request = null;
$attachments = [];
$logs = [];
$error = '';

try {
    if ($id <= 0) {
        throw new RuntimeException('Invalid request.');
    }

    $pdo = db();
    ensureItsrWorkflowSchema();

    $stmt = $pdo->prepare(
        "SELECT sr.*, rr.return_reason, rr.missing_document, rr.return_comment, rr.returned_at, rr.resubmitted_at
         FROM service_requests sr
         LEFT JOIN (
            SELECT r1.*
            FROM request_returns r1
            INNER JOIN (
                SELECT request_id, MAX(id) AS latest_id
                FROM request_returns
                GROUP BY request_id
            ) latest ON latest.latest_id = r1.id
         ) rr ON rr.request_id = sr.id
         WHERE sr.id = :id
           AND (:department = '' OR sr.department = :department)
         LIMIT 1"
    );
    $stmt->execute([':id' => $id, ':department' => $department]);
    $request = $stmt->fetch();

    if (!$request) {
        throw new RuntimeException('Request not found for this department.');
    }

    $department = trim((string) ($request['department'] ?? $department));

    $attachments = getRequestAttachments($id);
    $logs = getRequestLogs($id);
} catch (Throwable $exception) {
    $error = appErrorMessage($exception, 'Department request page failed', 'Unable to load this request.');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Department Request - ITSR</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: Inter, "Segoe UI", Roboto, Arial, sans-serif; }
        body { min-height: 100vh; background: #eef6ff; color: #132538; padding: 28px; }
        .page { max-width: 980px; margin: 0 auto; }
        a { color: #2563eb; font-weight: 800; text-decoration: none; }
        .card { background: #fff; border: 1px solid #dbe7f3; border-radius: 24px; padding: 24px; box-shadow: 0 18px 40px rgba(15, 38, 66, 0.08); margin-bottom: 18px; }
        .top { display: flex; justify-content: space-between; gap: 16px; align-items: flex-start; margin-bottom: 18px; }
        h1 { font-size: 34px; margin-bottom: 8px; }
        .muted { color: #64748b; line-height: 1.6; }
        .grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; margin-top: 18px; }
        .info { padding: 14px; border-radius: 16px; background: #f8fbff; border: 1px solid #e3edf7; }
        .info span { display: block; font-size: 12px; text-transform: uppercase; letter-spacing: 0.08em; color: #64748b; font-weight: 800; margin-bottom: 5px; }
        .pill { display: inline-flex; padding: 8px 12px; border-radius: 999px; font-size: 13px; font-weight: 900; background: #e9f3ff; color: #1d63b8; }
        .return { background: #fff7ed; border-color: #fed7aa; color: #8a4a06; }
        .list { display: grid; gap: 10px; margin-top: 14px; }
        .item { padding: 13px 14px; border-radius: 15px; background: #f8fbff; border: 1px solid #e3edf7; }
        .error { background: #fff1f1; border-color: #f0b6b6; color: #9f1d1d; }
        .request-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 38px;
            padding: 0 16px;
            border-radius: 12px;
            background: #2563eb;
            color: #fff;
            font-weight: 800;
            text-decoration: none;
            text-align: center;
        }
        @media (max-width: 720px) { body { padding: 18px; } .grid { grid-template-columns: 1fr; } .top { flex-direction: column; } }
    </style>
</head>
<body>
    <div class="page">
        <div class="top">
            <a href="dashboard.php">Back to Department Dashboard</a>
            <a href="../login/logout.php?portal=department">Log out</a>
        </div>

        <?php if ($error !== ''): ?>
            <section class="card error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></section>
        <?php elseif ($request): ?>
            <?php if (($request['status'] ?? '') === 'returned'): ?>
                <section class="card" style="background: #fff5f5; border-color: #feb2b2; color: #9b2c2c;">
                    <h2 style="color: #9b2c2c; margin-bottom: 8px;">Action Required: Request Returned</h2>
                    <p style="margin-bottom: 12px; line-height: 1.6;">
                        This form has been returned for corrections or missing information.
                    </p>
                    <div style="background: #fff; padding: 14px; border-radius: 12px; border: 1px solid #fecaca; margin-bottom: 14px; color: #1f3248;">
                        <div><strong>Reason for return:</strong> <?= htmlspecialchars((string) ($request['return_reason'] ?: 'No reason specified'), ENT_QUOTES, 'UTF-8') ?></div>
                        <?php if (trim((string) ($request['missing_document'] ?? '')) !== ''): ?>
                            <div style="margin-top: 6px;"><strong>Missing document:</strong> <?= htmlspecialchars(returnMissingDocumentOptions()[$request['missing_document']] ?? $request['missing_document'], ENT_QUOTES, 'UTF-8') ?></div>
                        <?php endif; ?>
                        <?php if (trim((string) ($request['return_comment'] ?? '')) !== ''): ?>
                            <div style="margin-top: 6px;"><strong>Comment:</strong> <?= nl2br(htmlspecialchars((string) $request['return_comment'], ENT_QUOTES, 'UTF-8')) ?></div>
                        <?php endif; ?>
                    </div>
                    <a href="../form/request_form.php?id=<?= (int) $request['id'] ?>&return=department" class="request-link" style="background: #e53e3e; color: #fff; box-shadow: 0 4px 12px rgba(229, 62, 62, 0.2);">Edit &amp; Resubmit Request</a>
                </section>
            <?php endif; ?>

            <section class="card">
                <h1>Request #<?= (int) $request['id'] ?></h1>
                <p class="muted">Submitted by <?= htmlspecialchars((string) ($request['requestor_name'] ?: '-'), ENT_QUOTES, 'UTF-8') ?> for <?= htmlspecialchars((string) ($request['department'] ?: '-'), ENT_QUOTES, 'UTF-8') ?>.</p>
                <div class="grid">
                    <div class="info"><span>Status</span><strong class="pill"><?= htmlspecialchars(requestStatusLabel((string) ($request['status'] ?? 'pending')), ENT_QUOTES, 'UTF-8') ?></strong></div>
                    <div class="info"><span>Assigned To</span><strong><?= htmlspecialchars((string) ($request['assign_to'] ?: 'Not assigned yet'), ENT_QUOTES, 'UTF-8') ?></strong></div>
                    <div class="info"><span>Company</span><strong><?= htmlspecialchars((string) ($request['company'] ?: '-'), ENT_QUOTES, 'UTF-8') ?></strong></div>
                    <div class="info"><span>Request Date</span><strong><?= htmlspecialchars((string) ($request['request_date'] ?: '-'), ENT_QUOTES, 'UTF-8') ?></strong></div>
                </div>
                <?php
                $pdfPath = trim((string) ($request['pdf_path'] ?? ''));
                $pdfFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'form' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $pdfPath);
                if ($pdfPath !== '' && is_file($pdfFile)):
                ?>
                    <div style="margin-top: 18px; display: flex; gap: 12px;">
                        <a class="request-link" style="background: #0f172a; color: #fff; font-size: 13.5px; padding: 10px 18px;" href="../form/download_pdf.php?portal=department&amp;file=<?= rawurlencode(basename($pdfPath)) ?>" target="_blank" rel="noopener">Open Generated PDF</a>
                    </div>
                <?php endif; ?>
            </section>

            <section class="card">
                <h2>Problem Description</h2>
                <p class="muted"><?= nl2br(htmlspecialchars((string) ($request['problem_description'] ?: '-'), ENT_QUOTES, 'UTF-8')) ?></p>
            </section>

            <section class="card">
                <h2>Service Types</h2>
                <div style="display: flex; flex-wrap: wrap; gap: 8px; margin-top: 12px;">
                    <?php
                    $selectedServiceTypes = [];
                    if (trim((string) ($request['service_types'] ?? '')) !== '') {
                        $decoded = json_decode($request['service_types'], true);
                        if (is_array($decoded)) {
                            $selectedServiceTypes = $decoded;
                        }
                    }
                    if ($selectedServiceTypes === []) {
                        echo '<span class="muted" style="font-style: italic;">No specific service types selected.</span>';
                    } else {
                        foreach ($selectedServiceTypes as $type) {
                            echo '<span class="pill" style="background: #f1f5f9; color: #334155; border: 1px solid #e2e8f0; font-size: 13px; font-weight: 600;">' . htmlspecialchars($type, ENT_QUOTES, 'UTF-8') . '</span>';
                        }
                    }
                    ?>
                </div>
            </section>

            <section class="card">
                <h2>Attachments</h2>
                <div class="list">
                    <?php foreach ($attachments as $attachment): ?>
                        <div class="item">
                            <strong><?= htmlspecialchars((string) $attachment['original_file_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                            <div class="muted"><?= htmlspecialchars((string) $attachment['attachment_type'], ENT_QUOTES, 'UTF-8') ?> uploaded <?= htmlspecialchars((string) $attachment['uploaded_at'], ENT_QUOTES, 'UTF-8') ?></div>
                            <div style="margin-top: 8px; display: flex; gap: 12px;">
                                <a href="attachment.php?id=<?= (int) $attachment['id'] ?>&amp;action=view" target="_blank" rel="noopener">View</a>
                                <a href="attachment.php?id=<?= (int) $attachment['id'] ?>&amp;action=download">Download</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <?php if ($attachments === []): ?><div class="item">No attachments uploaded yet.</div><?php endif; ?>
                </div>
            </section>

            <section class="card">
                <h2>Timeline</h2>
                <div class="list">
                    <?php foreach ($logs as $log): ?>
                        <div class="item">
                            <strong><?= htmlspecialchars((string) $log['action'], ENT_QUOTES, 'UTF-8') ?></strong>
                            <div class="muted"><?= htmlspecialchars((string) $log['remarks'], ENT_QUOTES, 'UTF-8') ?></div>
                        </div>
                    <?php endforeach; ?>
                    <?php if ($logs === []): ?><div class="item">No timeline updates yet.</div><?php endif; ?>
                </div>
            </section>
        <?php endif; ?>
    </div>
</body>
</html>
