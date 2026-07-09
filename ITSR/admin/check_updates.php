<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/login/auth.php';
requireAdmin();
require_once dirname(__DIR__) . '/db.php';

session_write_close();

try {
    $pdo = db();

    $latestRequestId = (int) $pdo->query('SELECT MAX(id) FROM service_requests')->fetchColumn();
    $latestActivityId = (int) $pdo->query('SELECT MAX(id) FROM activity_logs')->fetchColumn();
    
    // Check if request_tasks table exists and has columns
    $latestTaskId = 0;
    try {
        $latestTaskId = (int) $pdo->query('SELECT MAX(id) FROM request_tasks')->fetchColumn();
    } catch (Throwable $e) {
        // ignore if table doesn't exist
    }

    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'success',
        'latest_request_id' => $latestRequestId,
        'latest_activity_id' => $latestActivityId,
        'latest_task_id' => $latestTaskId,
    ]);
} catch (Throwable $exception) {
    appLogException($exception, 'Admin update check failed');
    header('HTTP/1.1 500 Internal Server Error');
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'error',
        'message' => 'Unable to check for updates right now.',
    ]);
}
