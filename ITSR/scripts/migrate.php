<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

putenv('APP_AUTO_MIGRATE=1');

require_once dirname(__DIR__) . '/login/auth.php';
require_once dirname(__DIR__) . '/includes/workflow.php';

try {
    ensureAuthSchema();
    ensureDepartmentLoginSchema();
    ensureItsrWorkflowSchema();

    $pdo = db();
    $userCount = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();

    if ($userCount === 0) {
        $username = appEnv('INITIAL_ADMIN_USERNAME', 'admin') ?? 'admin';
        $email = appEnv('INITIAL_ADMIN_EMAIL');
        $password = appEnvRaw('INITIAL_ADMIN_PASSWORD');

        if ($email === null || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Set INITIAL_ADMIN_EMAIL to a valid email address before the first migration.');
        }

        if ($password === null || ($passwordError = passwordPolicyError($password)) !== null) {
            throw new RuntimeException('INITIAL_ADMIN_PASSWORD: ' . ($passwordError ?? 'Password is required.'));
        }

        $stmt = $pdo->prepare(
            "INSERT INTO users (full_name, username, email, password_hash, role, department, status)
             VALUES ('Administrator', :username, :email, :password_hash, 'admin', '', 'active')"
        );
        $stmt->execute([
            ':username' => $username,
            ':email' => $email,
            ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
        ]);

        fwrite(STDOUT, "Created the initial administrator account.\n");
    }

    fwrite(STDOUT, "Database migration completed successfully.\n");
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Migration failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
