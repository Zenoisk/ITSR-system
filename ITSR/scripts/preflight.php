<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/config/environment.php';
require_once dirname(__DIR__) . '/db.php';

$failures = [];
$warnings = [];

if (PHP_VERSION_ID < 80200) {
    $failures[] = 'PHP 8.2 or newer is required.';
}

foreach (['pdo_mysql', 'mbstring', 'openssl', 'fileinfo', 'gd', 'curl', 'zip'] as $extension) {
    if (!extension_loaded($extension)) {
        $failures[] = 'Missing PHP extension: ' . $extension;
    }
}

if (!appIsProduction()) {
    $failures[] = 'APP_ENV must be production.';
}

if (!str_starts_with(strtolower(appUrl()), 'https://')) {
    $failures[] = 'APP_URL must use HTTPS.';
}

if (appAutoMigrate()) {
    $failures[] = 'APP_AUTO_MIGRATE must be false after deployment migrations finish.';
}

if (strtolower(appEnv('DB_USERNAME', '') ?? '') === 'root') {
    $warnings[] = 'The runtime database account should not be root.';
}

$writableDirectories = [
    dirname(__DIR__) . '/form/storage/pdfs',
    dirname(__DIR__) . '/form/storage/attachments',
    dirname(__DIR__) . '/form/storage/fonts',
    dirname(__DIR__) . '/admin/storage/profile_pictures',
    dirname(__DIR__) . '/storage',
];

foreach ($writableDirectories as $directory) {
    if (!is_dir($directory) || !is_writable($directory)) {
        $failures[] = 'Directory is not writable: ' . $directory;
    }
}

try {
    $pdo = db();
    foreach (['users', 'departments', 'service_requests', 'attachments', 'request_tasks', 'request_logs', 'email_logs'] as $table) {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = :table_name'
        );
        $stmt->execute([':table_name' => $table]);
        if ((int) $stmt->fetchColumn() === 0) {
            $failures[] = 'Missing database table: ' . $table;
        }
    }
} catch (Throwable $exception) {
    $failures[] = 'Database check failed: ' . $exception->getMessage();
}

$mailEnabled = appEnvBool('MAIL_ENABLED', false);
if ($mailEnabled && (appEnv('MAIL_USERNAME') === null || appEnv('MAIL_PASSWORD') === null)) {
    $failures[] = 'MAIL_ENABLED is true but SMTP credentials are incomplete.';
}
if (!$mailEnabled) {
    $warnings[] = 'Email delivery is disabled.';
}

foreach ($warnings as $warning) {
    fwrite(STDOUT, '[WARN] ' . $warning . PHP_EOL);
}
foreach ($failures as $failure) {
    fwrite(STDERR, '[FAIL] ' . $failure . PHP_EOL);
}

if ($failures !== []) {
    fwrite(STDERR, 'Preflight failed with ' . count($failures) . ' blocking issue(s).' . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, "Preflight passed. The application is ready for IIS smoke testing.\n");
exit(0);
