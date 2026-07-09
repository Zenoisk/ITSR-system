<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/login/auth.php';

function pdfDownloadNotFound(): never
{
    http_response_code(404);
    echo 'PDF file not found.';
    exit;
}

function pdfDownloadAllowedByToken(string $token, string $fileName): bool
{
    if ($token === '' || !isset($_SESSION['_pdf_downloads'][$token]) || !is_array($_SESSION['_pdf_downloads'][$token])) {
        return false;
    }

    $grant = $_SESSION['_pdf_downloads'][$token];
    $expiresAt = (int) ($grant['expires_at'] ?? 0);
    $grantedFile = basename((string) ($grant['file'] ?? ''));

    if ($expiresAt < time()) {
        unset($_SESSION['_pdf_downloads'][$token]);
        return false;
    }

    return $grantedFile !== '' && hash_equals($grantedFile, $fileName);
}

$fileName = basename((string) ($_GET['file'] ?? ''));
$token = trim((string) ($_GET['token'] ?? ''));

if ($token !== '' && isset($_SESSION['_pdf_downloads'][$token]['file'])) {
    $fileName = basename((string) $_SESSION['_pdf_downloads'][$token]['file']);
}

if ($fileName === '' || !preg_match('/\A[a-zA-Z0-9._-]+\.pdf\z/i', $fileName)) {
    pdfDownloadNotFound();
}

$allowed = pdfDownloadAllowedByToken($token, $fileName);

if (!$allowed) {
    requireLogin();

    $pdfPath = 'storage/pdfs/' . $fileName;
    $role = currentUserRole();
    $pdo = db();

    if ($role === 'admin') {
        $stmt = $pdo->prepare(
            'SELECT id FROM service_requests WHERE pdf_path = :active_path
             UNION ALL
             SELECT id FROM deleted_requests WHERE pdf_path = :deleted_path
             LIMIT 1'
        );
        $stmt->execute([
            ':active_path' => $pdfPath,
            ':deleted_path' => $pdfPath,
        ]);
        $allowed = (bool) $stmt->fetchColumn();
    } elseif ($role === 'staff') {
        $stmt = $pdo->prepare('SELECT id FROM service_requests WHERE pdf_path = :pdf_path LIMIT 1');
        $stmt->execute([':pdf_path' => $pdfPath]);
        $allowed = (bool) $stmt->fetchColumn();
    } elseif ($role === 'department_user') {
        $stmt = $pdo->prepare('SELECT id FROM service_requests WHERE pdf_path = :pdf_path AND department = :department LIMIT 1');
        $stmt->execute([
            ':pdf_path' => $pdfPath,
            ':department' => currentUserDepartment(),
        ]);
        $allowed = (bool) $stmt->fetchColumn();
    }
}

if (!$allowed) {
    pdfDownloadNotFound();
}

$storageDirectory = __DIR__ . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'pdfs';
$candidate = $storageDirectory . DIRECTORY_SEPARATOR . $fileName;
$storageReal = realpath($storageDirectory);
$fileReal = is_file($candidate) ? realpath($candidate) : false;

if ($storageReal === false || $fileReal === false || !str_starts_with($fileReal, $storageReal . DIRECTORY_SEPARATOR)) {
    pdfDownloadNotFound();
}

header('Content-Type: application/pdf');
header('Content-Length: ' . (string) filesize($fileReal));
header('Content-Disposition: attachment; filename="' . str_replace('"', '', $fileName) . '"');
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
readfile($fileReal);
exit;
