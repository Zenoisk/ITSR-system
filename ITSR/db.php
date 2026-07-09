<?php

declare(strict_types=1);

require_once __DIR__ . '/config/environment.php';

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $host = appEnv('DB_HOST', '127.0.0.1') ?? '127.0.0.1';
    $port = (int) (appEnv('DB_PORT', '3306') ?? '3306');
    $dbname = appEnv('DB_DATABASE', 'itsr_form') ?? 'itsr_form';
    $username = appEnv('DB_USERNAME', appIsProduction() ? null : 'root');
    $password = appEnvRaw('DB_PASSWORD', appIsProduction() ? null : '');

    if ($username === null || $password === null) {
        throw new RuntimeException('Production database credentials are not configured.');
    }

    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $dbname);

    $pdo = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    return $pdo;
}
