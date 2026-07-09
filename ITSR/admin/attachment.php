<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/login/auth.php';
requireAdmin();
require_once dirname(__DIR__) . '/includes/workflow.php';

ensureItsrWorkflowSchema();

$id = (int) ($_GET['id'] ?? 0);
$action = (string) ($_GET['action'] ?? 'view');

if ($id <= 0) {
    http_response_code(404);
    echo 'Attachment not found.';
    exit;
}

$stmt = db()->prepare('SELECT * FROM attachments WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $id]);
$attachment = $stmt->fetch();

if (!$attachment) {
    http_response_code(404);
    echo 'Attachment not found.';
    exit;
}

$path = safeAttachmentPath($attachment);
if ($path === null) {
    http_response_code(404);
    echo 'Attachment file not found.';
    exit;
}

$mimeType = (string) ($attachment['file_type'] ?? '');
if ($mimeType === '') {
    $mimeType = mime_content_type($path) ?: 'application/octet-stream';
}

$originalName = preg_replace('/[^A-Za-z0-9._ -]/', '_', basename((string) ($attachment['original_file_name'] ?? 'attachment'))) ?: 'attachment';
$disposition = $action === 'download' ? 'attachment' : 'inline';

header('Content-Type: ' . $mimeType);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: ' . $disposition . '; filename="' . str_replace('"', '', $originalName) . '"');
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
readfile($path);
exit;
