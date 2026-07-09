<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/config/environment.php';

function workflowColumnExists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name = :table_name
           AND column_name = :column_name'
    );
    $stmt->execute([
        ':table_name' => $table,
        ':column_name' => $column,
    ]);

    return (int) $stmt->fetchColumn() > 0;
}

function ensureItsrWorkflowSchema(): void
{
    static $initialized = false;

    if ($initialized) {
        return;
    }

    if (!appAutoMigrate()) {
        $initialized = true;
        return;
    }

    $pdo = db();

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS service_requests (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            company VARCHAR(255) NOT NULL DEFAULT '',
            department VARCHAR(255) NOT NULL DEFAULT '',
            requestor_email VARCHAR(255) NOT NULL DEFAULT '',
            status VARCHAR(30) NOT NULL DEFAULT 'pending',
            priority VARCHAR(20) NOT NULL DEFAULT 'medium',
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            read_at DATETIME NULL,
            latest_user_update_at DATETIME NULL,
            request_date DATE NULL,
            requestor_name VARCHAR(255) NOT NULL DEFAULT '',
            requestor_phone VARCHAR(100) NOT NULL DEFAULT '',
            requestor_signature_name VARCHAR(100) NOT NULL DEFAULT '',
            requestor_signed_at DATETIME NULL,
            requestor_signature_path VARCHAR(255) NULL,
            approved_by_name VARCHAR(255) NOT NULL DEFAULT '',
            approved_by_phone VARCHAR(100) NOT NULL DEFAULT '',
            approver_signature_name VARCHAR(100) NOT NULL DEFAULT '',
            approver_signed_at DATETIME NULL,
            approved_by_signature_path VARCHAR(255) NULL,
            service_types TEXT NULL,
            other_service_specify VARCHAR(255) NOT NULL DEFAULT '',
            location VARCHAR(255) NOT NULL DEFAULT '',
            ip_tag_no VARCHAR(255) NOT NULL DEFAULT '',
            problem_description TEXT NULL,
            assign_to VARCHAR(255) NOT NULL DEFAULT '',
            task_type VARCHAR(50) NOT NULL DEFAULT 'Normal',
            sla_hours DECIMAL(5,2) NULL DEFAULT NULL,
            assigned_at DATETIME NULL,
            due_at DATETIME NULL,
            completed_at DATETIME NULL,
            actual_working_minutes_taken INT NULL,
            total_hour_taken_display VARCHAR(100) NULL DEFAULT NULL,
            sla_result VARCHAR(20) NOT NULL DEFAULT 'Pending',
            is_overdue TINYINT(1) NOT NULL DEFAULT 0,
            date_receive DATE NULL,
            date_complete DATE NULL,
            total_hour_taken VARCHAR(100) NOT NULL DEFAULT '',
            staff_signature_path VARCHAR(255) NULL,
            corrective_action TEXT NULL,
            pdf_path VARCHAR(255) NOT NULL DEFAULT '',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS attachments (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            request_id INT UNSIGNED NOT NULL,
            attachment_type VARCHAR(50) NOT NULL,
            original_file_name VARCHAR(255) NOT NULL,
            stored_file_name VARCHAR(255) NOT NULL,
            file_path VARCHAR(255) NOT NULL,
            file_type VARCHAR(120) NOT NULL DEFAULT '',
            file_size INT UNSIGNED NOT NULL DEFAULT 0,
            uploaded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_request_id (request_id),
            KEY idx_attachment_type (attachment_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS request_returns (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            request_id INT UNSIGNED NOT NULL,
            returned_by INT UNSIGNED NULL DEFAULT NULL,
            return_reason VARCHAR(255) NOT NULL,
            missing_document VARCHAR(100) NOT NULL DEFAULT '',
            return_comment TEXT NULL,
            resubmitted_at DATETIME NULL,
            resubmission_note TEXT NULL,
            returned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_request_id (request_id),
            KEY idx_returned_at (returned_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS request_tasks (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            parent_request_id INT UNSIGNED NOT NULL,
            task_no INT UNSIGNED NOT NULL DEFAULT 1,
            task_title VARCHAR(255) NOT NULL,
            task_description TEXT NULL,
            assigned_to VARCHAR(255) NOT NULL DEFAULT '',
            status VARCHAR(30) NOT NULL DEFAULT 'assigned',
            priority VARCHAR(20) NOT NULL DEFAULT 'medium',
            task_type VARCHAR(50) NOT NULL DEFAULT 'Normal',
            sla_hours DECIMAL(5,2) NULL DEFAULT NULL,
            assigned_at DATETIME NULL,
            due_at DATETIME NULL,
            started_at DATETIME NULL,
            completed_at DATETIME NULL,
            actual_working_minutes_taken INT NULL,
            total_hour_taken_display VARCHAR(100) NULL DEFAULT NULL,
            sla_result VARCHAR(20) NOT NULL DEFAULT 'Pending',
            is_overdue TINYINT(1) NOT NULL DEFAULT 0,
            date_assigned DATE NULL,
            date_started DATE NULL,
            date_completed DATE NULL,
            solution TEXT NULL,
            is_read_staff TINYINT(1) NOT NULL DEFAULT 0,
            staff_read_at DATETIME NULL,
            created_by INT UNSIGNED NULL DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_parent_request_id (parent_request_id),
            KEY idx_assigned_to (assigned_to),
            KEY idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS request_logs (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            request_id INT UNSIGNED NOT NULL,
            task_id INT UNSIGNED NULL DEFAULT NULL,
            user_id INT UNSIGNED NULL DEFAULT NULL,
            action VARCHAR(80) NOT NULL,
            remarks TEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_request_id (request_id),
            KEY idx_task_id (task_id),
            KEY idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS sla_settings (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            task_type VARCHAR(50) NOT NULL,
            allowed_working_hours DECIMAL(5,2) NOT NULL,
            description VARCHAR(255) NOT NULL DEFAULT '',
            status ENUM('active','inactive') NOT NULL DEFAULT 'active',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_task_type (task_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS public_holidays (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            holiday_name VARCHAR(255) NOT NULL,
            holiday_date DATE NOT NULL,
            year INT UNSIGNED NOT NULL,
            status ENUM('active','inactive') NOT NULL DEFAULT 'active',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_holiday_date (holiday_date),
            KEY idx_year_status (year, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS email_logs (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            request_id INT UNSIGNED NULL DEFAULT NULL,
            task_id INT UNSIGNED NULL DEFAULT NULL,
            email_type VARCHAR(50) NOT NULL DEFAULT '',
            recipient_email VARCHAR(255) NOT NULL DEFAULT '',
            subject VARCHAR(255) NOT NULL DEFAULT '',
            status ENUM('sent','failed') NOT NULL DEFAULT 'failed',
            error_message TEXT NULL,
            sent_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_request_id (request_id),
            KEY idx_email_type (email_type),
            KEY idx_status (status),
            KEY idx_sent_at (sent_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $defaultSlaStmt = $pdo->prepare(
        'INSERT IGNORE INTO sla_settings (task_type, allowed_working_hours, description, status)
         VALUES (:task_type, :allowed_working_hours, :description, :status)'
    );
    foreach ([
        ['Urgent', 4, 'Half working day response target'],
        ['Priority', 9, 'One full working day plus one working hour'],
        ['Normal', 36, 'Four full working days plus four working hours'],
    ] as $defaultSla) {
        $defaultSlaStmt->execute([
            ':task_type' => $defaultSla[0],
            ':allowed_working_hours' => $defaultSla[1],
            ':description' => $defaultSla[2],
            ':status' => 'active',
        ]);
    }

    if (!workflowColumnExists($pdo, 'service_requests', 'is_read')) {
        $pdo->exec("ALTER TABLE service_requests ADD COLUMN is_read TINYINT(1) NOT NULL DEFAULT 0 AFTER status");
    }

    if (!workflowColumnExists($pdo, 'service_requests', 'read_at')) {
        $pdo->exec("ALTER TABLE service_requests ADD COLUMN read_at DATETIME NULL AFTER is_read");
    }

    if (!workflowColumnExists($pdo, 'service_requests', 'priority')) {
        $pdo->exec("ALTER TABLE service_requests ADD COLUMN priority VARCHAR(20) NOT NULL DEFAULT 'medium' AFTER status");
    }

    if (!workflowColumnExists($pdo, 'service_requests', 'updated_at')) {
        $pdo->exec("ALTER TABLE service_requests ADD COLUMN updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at");
    }

    if (!workflowColumnExists($pdo, 'service_requests', 'latest_user_update_at')) {
        $pdo->exec("ALTER TABLE service_requests ADD COLUMN latest_user_update_at DATETIME NULL AFTER read_at");
    }

    foreach ([
        'requestor_signature_name' => "ALTER TABLE service_requests ADD COLUMN requestor_signature_name VARCHAR(100) NOT NULL DEFAULT '' AFTER requestor_phone",
        'requestor_signed_at' => "ALTER TABLE service_requests ADD COLUMN requestor_signed_at DATETIME NULL AFTER requestor_signature_name",
        'approver_signature_name' => "ALTER TABLE service_requests ADD COLUMN approver_signature_name VARCHAR(100) NOT NULL DEFAULT '' AFTER approved_by_phone",
        'approver_signed_at' => "ALTER TABLE service_requests ADD COLUMN approver_signed_at DATETIME NULL AFTER approver_signature_name",
        'task_type' => "ALTER TABLE service_requests ADD COLUMN task_type VARCHAR(50) NOT NULL DEFAULT 'Normal' AFTER assign_to",
        'sla_hours' => "ALTER TABLE service_requests ADD COLUMN sla_hours DECIMAL(5,2) NULL DEFAULT NULL AFTER task_type",
        'assigned_at' => "ALTER TABLE service_requests ADD COLUMN assigned_at DATETIME NULL AFTER sla_hours",
        'due_at' => "ALTER TABLE service_requests ADD COLUMN due_at DATETIME NULL AFTER assigned_at",
        'completed_at' => "ALTER TABLE service_requests ADD COLUMN completed_at DATETIME NULL AFTER due_at",
        'actual_working_minutes_taken' => "ALTER TABLE service_requests ADD COLUMN actual_working_minutes_taken INT NULL AFTER completed_at",
        'total_hour_taken_display' => "ALTER TABLE service_requests ADD COLUMN total_hour_taken_display VARCHAR(100) NULL DEFAULT NULL AFTER actual_working_minutes_taken",
        'sla_result' => "ALTER TABLE service_requests ADD COLUMN sla_result VARCHAR(20) NOT NULL DEFAULT 'Pending' AFTER total_hour_taken_display",
        'is_overdue' => "ALTER TABLE service_requests ADD COLUMN is_overdue TINYINT(1) NOT NULL DEFAULT 0 AFTER sla_result",
        'resubmitted_at' => "ALTER TABLE service_requests ADD COLUMN resubmitted_at DATETIME NULL AFTER latest_user_update_at",
        'pdf_path' => "ALTER TABLE service_requests ADD COLUMN pdf_path VARCHAR(255) NOT NULL DEFAULT ''",
        'service_types' => "ALTER TABLE service_requests ADD COLUMN service_types TEXT NULL",
        'other_service_specify' => "ALTER TABLE service_requests ADD COLUMN other_service_specify VARCHAR(255) NOT NULL DEFAULT ''",
    ] as $column => $sql) {
        if (!workflowColumnExists($pdo, 'service_requests', $column)) {
            $pdo->exec($sql);
        }
    }

    try {
        $pdo->exec("ALTER TABLE users MODIFY COLUMN role ENUM('admin','staff','department_user') NOT NULL DEFAULT 'staff'");
    } catch (Throwable $e) {}

    foreach ([
        'task_type' => "ALTER TABLE request_tasks ADD COLUMN task_type VARCHAR(50) NOT NULL DEFAULT 'Normal' AFTER priority",
        'sla_hours' => "ALTER TABLE request_tasks ADD COLUMN sla_hours DECIMAL(5,2) NULL DEFAULT NULL AFTER task_type",
        'assigned_at' => "ALTER TABLE request_tasks ADD COLUMN assigned_at DATETIME NULL AFTER sla_hours",
        'due_at' => "ALTER TABLE request_tasks ADD COLUMN due_at DATETIME NULL AFTER assigned_at",
        'started_at' => "ALTER TABLE request_tasks ADD COLUMN started_at DATETIME NULL AFTER due_at",
        'completed_at' => "ALTER TABLE request_tasks ADD COLUMN completed_at DATETIME NULL AFTER started_at",
        'actual_working_minutes_taken' => "ALTER TABLE request_tasks ADD COLUMN actual_working_minutes_taken INT NULL AFTER completed_at",
        'total_hour_taken_display' => "ALTER TABLE request_tasks ADD COLUMN total_hour_taken_display VARCHAR(100) NULL DEFAULT NULL AFTER actual_working_minutes_taken",
        'sla_result' => "ALTER TABLE request_tasks ADD COLUMN sla_result VARCHAR(20) NOT NULL DEFAULT 'Pending' AFTER total_hour_taken_display",
        'is_overdue' => "ALTER TABLE request_tasks ADD COLUMN is_overdue TINYINT(1) NOT NULL DEFAULT 0 AFTER sla_result",
    ] as $column => $sql) {
        if (!workflowColumnExists($pdo, 'request_tasks', $column)) {
            $pdo->exec($sql);
        }
    }

    if (!workflowColumnExists($pdo, 'request_returns', 'resubmitted_at')) {
        $pdo->exec("ALTER TABLE request_returns ADD COLUMN resubmitted_at DATETIME NULL AFTER return_comment");
    }

    if (!workflowColumnExists($pdo, 'request_returns', 'resubmission_note')) {
        $pdo->exec("ALTER TABLE request_returns ADD COLUMN resubmission_note TEXT NULL AFTER resubmitted_at");
    }

    try {
        $pdo->exec("ALTER TABLE service_requests MODIFY status VARCHAR(30) NOT NULL DEFAULT 'pending'");
    } catch (Throwable $exception) {
        // Older local databases may already be compatible.
    }

    $uploadDir = workflowUploadDirectory();
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    $htaccess = $uploadDir . DIRECTORY_SEPARATOR . '.htaccess';
    if (!is_file($htaccess)) {
        file_put_contents($htaccess, "Options -Indexes\n<FilesMatch \"\\.(php|php[0-9]|phtml|phar|cgi|pl|asp|aspx|jsp|js|html|htm|exe|bat|cmd|sh)$\">\nRequire all denied\n</FilesMatch>\n");
    }

    $initialized = true;
}

function workflowUploadDirectory(): string
{
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'form' . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'attachments';
}

function slaTimezone(): DateTimeZone
{
    static $timezone = null;
    if (!$timezone instanceof DateTimeZone) {
        $timezone = new DateTimeZone('Asia/Kuala_Lumpur');
    }

    return $timezone;
}

function slaDateTime(string|DateTimeInterface|null $value = null): DateTimeImmutable
{
    if ($value instanceof DateTimeInterface) {
        return (new DateTimeImmutable($value->format('Y-m-d H:i:s'), $value->getTimezone()))->setTimezone(slaTimezone());
    }

    $raw = trim((string) ($value ?? 'now'));
    return new DateTimeImmutable($raw !== '' ? $raw : 'now', slaTimezone());
}

function slaDateTimeForDb(DateTimeInterface $dateTime): string
{
    return $dateTime->setTimezone(slaTimezone())->format('Y-m-d H:i:s');
}

function slaActiveHolidayDates(?int $year = null): array
{
    ensureItsrWorkflowSchema();

    static $cache = [];
    $cacheKey = $year === null ? 'all' : (string) $year;
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    if ($year === null) {
        $stmt = db()->query("SELECT holiday_date FROM public_holidays WHERE status = 'active'");
        return $cache[$cacheKey] = array_fill_keys(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN)), true);
    }

    $stmt = db()->prepare("SELECT holiday_date FROM public_holidays WHERE status = 'active' AND year = :year");
    $stmt->execute([':year' => $year]);

    return $cache[$cacheKey] = array_fill_keys(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN)), true);
}

function slaIsPublicHoliday(DateTimeInterface|string $date): bool
{
    $dateTime = $date instanceof DateTimeInterface ? slaDateTime($date) : slaDateTime((string) $date);
    $dateKey = $dateTime->format('Y-m-d');
    $holidays = slaActiveHolidayDates((int) $dateTime->format('Y'));

    return isset($holidays[$dateKey]);
}

function slaIsWorkingDay(DateTimeInterface|string $date): bool
{
    $dateTime = $date instanceof DateTimeInterface ? slaDateTime($date) : slaDateTime((string) $date);
    $dayNumber = (int) $dateTime->format('N');

    return $dayNumber >= 1 && $dayNumber <= 4 && !slaIsPublicHoliday($dateTime);
}

function slaNextWorkingDayStart(DateTimeImmutable $dateTime): DateTimeImmutable
{
    $candidate = $dateTime->modify('+1 day')->setTime(8, 0);
    while (!slaIsWorkingDay($candidate)) {
        $candidate = $candidate->modify('+1 day')->setTime(8, 0);
    }

    return $candidate;
}

function slaNormalizeToNextWorkingTime(DateTimeInterface|string $value): DateTimeImmutable
{
    $dateTime = $value instanceof DateTimeInterface ? slaDateTime($value) : slaDateTime((string) $value);

    while (!slaIsWorkingDay($dateTime)) {
        $dateTime = $dateTime->modify('+1 day')->setTime(8, 0);
    }

    $minutes = ((int) $dateTime->format('H')) * 60 + (int) $dateTime->format('i');
    if ($minutes < 480) {
        return $dateTime->setTime(8, 0);
    }
    if ($minutes >= 780 && $minutes < 840) {
        return $dateTime->setTime(14, 0);
    }
    if ($minutes >= 1020) {
        return slaNextWorkingDayStart($dateTime);
    }

    return $dateTime;
}

function slaCurrentBlockEnd(DateTimeImmutable $dateTime): DateTimeImmutable
{
    $minutes = ((int) $dateTime->format('H')) * 60 + (int) $dateTime->format('i');
    if ($minutes < 780) {
        return $dateTime->setTime(13, 0);
    }

    return $dateTime->setTime(17, 0);
}

function slaCalculateDueAt(DateTimeInterface|string $assignedAt, float $slaHours): DateTimeImmutable
{
    $current = slaNormalizeToNextWorkingTime($assignedAt);
    $remainingMinutes = max(0, (int) round($slaHours * 60));

    while ($remainingMinutes > 0) {
        $current = slaNormalizeToNextWorkingTime($current);
        $blockEnd = slaCurrentBlockEnd($current);
        $availableMinutes = max(0, (int) floor(($blockEnd->getTimestamp() - $current->getTimestamp()) / 60));

        if ($remainingMinutes <= $availableMinutes) {
            return $current->modify('+' . $remainingMinutes . ' minutes');
        }

        $remainingMinutes -= $availableMinutes;
        $current = slaNormalizeToNextWorkingTime($blockEnd);
        if ($current <= $blockEnd) {
            $current = slaNextWorkingDayStart($blockEnd);
        }
    }

    return $current;
}

function slaCalculateWorkingMinutes(DateTimeInterface|string|null $start, DateTimeInterface|string|null $end): int
{
    if (
        $start === null ||
        $end === null ||
        (!$start instanceof DateTimeInterface && trim((string) $start) === '') ||
        (!$end instanceof DateTimeInterface && trim((string) $end) === '')
    ) {
        return 0;
    }

    $current = slaNormalizeToNextWorkingTime($start);
    $finish = $end instanceof DateTimeInterface ? slaDateTime($end) : slaDateTime((string) $end);
    if ($finish <= $current) {
        return 0;
    }

    $minutes = 0;
    while ($current < $finish) {
        $current = slaNormalizeToNextWorkingTime($current);
        if ($current >= $finish) {
            break;
        }

        $blockEnd = slaCurrentBlockEnd($current);
        $segmentEnd = $finish < $blockEnd ? $finish : $blockEnd;
        $minutes += max(0, (int) floor(($segmentEnd->getTimestamp() - $current->getTimestamp()) / 60));
        $current = $segmentEnd;

        if ($current >= $blockEnd) {
            $current = slaNormalizeToNextWorkingTime($blockEnd);
            if ($current <= $blockEnd) {
                $current = slaNextWorkingDayStart($blockEnd);
            }
        }
    }

    return $minutes;
}

function slaFormatWorkingMinutes(?int $minutes): string
{
    if ($minutes === null || $minutes <= 0) {
        return '0h 00m';
    }

    $hours = intdiv($minutes, 60);
    $remaining = $minutes % 60;

    return $hours . 'h ' . str_pad((string) $remaining, 2, '0', STR_PAD_LEFT) . 'm';
}

function slaSettings(): array
{
    ensureItsrWorkflowSchema();

    $rows = db()->query("SELECT task_type, allowed_working_hours, description FROM sla_settings WHERE status = 'active' ORDER BY FIELD(task_type, 'Urgent', 'Priority', 'Normal'), task_type")->fetchAll();
    $settings = [];
    foreach ($rows as $row) {
        $settings[(string) $row['task_type']] = [
            'hours' => (float) $row['allowed_working_hours'],
            'description' => (string) ($row['description'] ?? ''),
        ];
    }

    return $settings ?: [
        'Urgent' => ['hours' => 4.0, 'description' => 'Half working day response target'],
        'Priority' => ['hours' => 9.0, 'description' => 'One full working day plus one working hour'],
        'Normal' => ['hours' => 36.0, 'description' => 'Four full working days plus four working hours'],
    ];
}

function slaNormalizeTaskType(string $taskType): string
{
    $taskType = trim($taskType);
    foreach (array_keys(slaSettings()) as $knownType) {
        if (strcasecmp($knownType, $taskType) === 0) {
            return $knownType;
        }
    }

    return 'Normal';
}

function slaHoursForTaskType(string $taskType): float
{
    $settings = slaSettings();
    $normalized = slaNormalizeTaskType($taskType);

    return (float) ($settings[$normalized]['hours'] ?? 36.0);
}

function slaBuildAssignmentData(string $taskType, ?string $assignedAt = null): array
{
    $normalizedType = slaNormalizeTaskType($taskType);
    $hours = slaHoursForTaskType($normalizedType);
    $start = slaNormalizeToNextWorkingTime($assignedAt !== null && trim($assignedAt) !== '' ? $assignedAt : 'now');
    $due = slaCalculateDueAt($start, $hours);

    return [
        'task_type' => $normalizedType,
        'sla_hours' => $hours,
        'assigned_at' => slaDateTimeForDb($start),
        'due_at' => slaDateTimeForDb($due),
        'sla_result' => 'Pending',
        'is_overdue' => 0,
    ];
}

function slaResultForCompletion(string|DateTimeInterface|null $completedAt, string|DateTimeInterface|null $dueAt): string
{
    if (
        $completedAt === null ||
        $dueAt === null ||
        (!$completedAt instanceof DateTimeInterface && trim((string) $completedAt) === '') ||
        (!$dueAt instanceof DateTimeInterface && trim((string) $dueAt) === '')
    ) {
        return 'Pending';
    }

    return slaDateTime($completedAt) <= slaDateTime($dueAt) ? 'MH' : 'NMH';
}

function slaCompleteData(array $record, ?string $completedAt = null): array
{
    $completed = slaDateTime($completedAt !== null && trim($completedAt) !== '' ? $completedAt : 'now');
    $assignedAt = trim((string) ($record['assigned_at'] ?? ''));
    $dueAt = trim((string) ($record['due_at'] ?? ''));
    $minutes = $assignedAt !== '' ? slaCalculateWorkingMinutes($assignedAt, $completed) : 0;
    $result = slaResultForCompletion($completed, $dueAt);
    $display = slaFormatWorkingMinutes($minutes) . ' - ' . $result;

    return [
        'completed_at' => slaDateTimeForDb($completed),
        'actual_working_minutes_taken' => $minutes,
        'total_hour_taken_display' => $display,
        'total_hour_taken' => $display,
        'sla_result' => $result,
        'is_overdue' => $result === 'NMH' ? 1 : 0,
    ];
}

function slaDisplayForRecord(array $record): string
{
    $assignedAt = trim((string) ($record['assigned_at'] ?? ''));
    if ($assignedAt === '') {
        return 'Not started - task not assigned yet';
    }

    $completedAt = trim((string) ($record['completed_at'] ?? ''));
    $storedDisplay = trim((string) ($record['total_hour_taken_display'] ?? ''));
    if ($completedAt !== '' && $storedDisplay !== '') {
        return $storedDisplay;
    }

    $minutes = slaCalculateWorkingMinutes($assignedAt, 'now');
    $dueAt = trim((string) ($record['due_at'] ?? ''));
    $status = $dueAt !== '' && slaDateTime('now') > slaDateTime($dueAt) ? 'Overdue' : 'Within Time';
    $dueText = $dueAt !== '' ? ' | Due: ' . $dueAt : '';

    return 'Running: ' . slaFormatWorkingMinutes($minutes) . $dueText . ' | Status: ' . $status;
}

function slaRefreshOpenRecord(PDO $pdo, string $table, int $id): void
{
    if (!in_array($table, ['service_requests', 'request_tasks'], true)) {
        return;
    }

    $stmt = $pdo->prepare("SELECT assigned_at, due_at, completed_at, status, is_overdue FROM `$table` WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $id]);
    $record = $stmt->fetch();
    if (!$record || trim((string) ($record['completed_at'] ?? '')) !== '' || trim((string) ($record['due_at'] ?? '')) === '') {
        return;
    }

    $isOverdue = slaDateTime('now') > slaDateTime((string) $record['due_at']) ? 1 : 0;
    if ($isOverdue !== (int) ($record['is_overdue'] ?? 0)) {
        $pdo->prepare("UPDATE `$table` SET is_overdue = :is_overdue WHERE id = :id")->execute([
            ':is_overdue' => $isOverdue,
            ':id' => $id,
        ]);
    }
}

function workflowAllowedAttachmentExtensions(): array
{
    return ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx', 'xls', 'xlsx'];
}

function workflowDangerousAttachmentExtensions(): array
{
    return [
        'php', 'php3', 'php4', 'php5', 'phtml', 'phar',
        'exe', 'js', 'html', 'htm', 'svg', 'sh', 'bat',
        'cmd', 'com', 'scr', 'msi', 'vbs', 'jar',
    ];
}

function workflowAllowedMimeTypesForExtension(string $extension): array
{
    return match ($extension) {
        'pdf' => ['application/pdf'],
        'jpg', 'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'doc' => ['application/msword', 'application/octet-stream'],
        'docx' => [
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/zip',
            'application/octet-stream',
        ],
        'xls' => ['application/vnd.ms-excel', 'application/octet-stream'],
        'xlsx' => [
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/zip',
            'application/octet-stream',
        ],
        default => [],
    };
}

function validateRequestAttachmentUpload(string $fieldName): void
{
    if (
        !isset($_FILES[$fieldName]) ||
        !is_array($_FILES[$fieldName]) ||
        (int) ($_FILES[$fieldName]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE
    ) {
        return;
    }

    if ((int) ($_FILES[$fieldName]['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Unable to upload ' . $fieldName . '. Please try again.');
    }

    $maxBytes = 8 * 1024 * 1024;
    $size = (int) ($_FILES[$fieldName]['size'] ?? 0);
    if ($size <= 0 || $size > $maxBytes) {
        throw new RuntimeException('Attachment "' . $fieldName . '" must be 8MB or smaller.');
    }

    $tmpName = (string) ($_FILES[$fieldName]['tmp_name'] ?? '');
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        throw new RuntimeException('Invalid uploaded file for ' . $fieldName . '.');
    }

    $originalName = basename((string) ($_FILES[$fieldName]['name'] ?? 'attachment'));
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if ($extension === '' || in_array($extension, workflowDangerousAttachmentExtensions(), true)) {
        throw new RuntimeException('File type .' . ($extension !== '' ? $extension : 'unknown') . ' is not allowed.');
    }

    if (!in_array($extension, workflowAllowedAttachmentExtensions(), true)) {
        throw new RuntimeException('File type .' . $extension . ' is not allowed.');
    }

    $mimeType = mime_content_type($tmpName) ?: '';
    $allowedMimeTypes = workflowAllowedMimeTypesForExtension($extension);
    if ($mimeType !== '' && !in_array($mimeType, $allowedMimeTypes, true)) {
        throw new RuntimeException('Uploaded file type is not allowed.');
    }
}

function storeRequestAttachment(string $fieldName, string $attachmentType, int $requestId): ?array
{
    ensureItsrWorkflowSchema();
    validateRequestAttachmentUpload($fieldName);

    if (
        !isset($_FILES[$fieldName]) ||
        !is_array($_FILES[$fieldName]) ||
        (int) ($_FILES[$fieldName]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE
    ) {
        return null;
    }

    if ((int) ($_FILES[$fieldName]['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Unable to upload ' . $fieldName . '. Please try again.');
    }

    $maxBytes = 8 * 1024 * 1024;
    $size = (int) ($_FILES[$fieldName]['size'] ?? 0);
    if ($size <= 0 || $size > $maxBytes) {
        throw new RuntimeException('Attachment "' . $fieldName . '" must be 8MB or smaller.');
    }

    $tmpName = (string) ($_FILES[$fieldName]['tmp_name'] ?? '');
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        throw new RuntimeException('Invalid uploaded file for ' . $fieldName . '.');
    }

    $originalName = basename((string) ($_FILES[$fieldName]['name'] ?? 'attachment'));
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if ($extension === '' || in_array($extension, workflowDangerousAttachmentExtensions(), true)) {
        throw new RuntimeException('File type .' . ($extension !== '' ? $extension : 'unknown') . ' is not allowed.');
    }

    if (!in_array($extension, workflowAllowedAttachmentExtensions(), true)) {
        throw new RuntimeException('File type .' . $extension . ' is not allowed.');
    }

    $mimeType = mime_content_type($tmpName) ?: '';
    $allowedMimeTypes = workflowAllowedMimeTypesForExtension($extension);
    if ($mimeType !== '' && !in_array($mimeType, $allowedMimeTypes, true)) {
        throw new RuntimeException('Uploaded file type is not allowed.');
    }

    $storedFileName = $attachmentType . '_' . $requestId . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $extension;
    $uploadDir = workflowUploadDirectory();
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0777, true) && !is_dir($uploadDir)) {
        throw new RuntimeException('Unable to create attachment upload folder.');
    }

    $targetPath = $uploadDir . DIRECTORY_SEPARATOR . $storedFileName;
    if (!move_uploaded_file($tmpName, $targetPath)) {
        throw new RuntimeException('Unable to store uploaded attachment.');
    }

    $relativePath = 'storage/attachments/' . $storedFileName;
    $stmt = db()->prepare(
        'INSERT INTO attachments (request_id, attachment_type, original_file_name, stored_file_name, file_path, file_type, file_size)
         VALUES (:request_id, :attachment_type, :original_file_name, :stored_file_name, :file_path, :file_type, :file_size)'
    );
    $stmt->execute([
        ':request_id' => $requestId,
        ':attachment_type' => $attachmentType,
        ':original_file_name' => $originalName,
        ':stored_file_name' => $storedFileName,
        ':file_path' => $relativePath,
        ':file_type' => $mimeType,
        ':file_size' => $size,
    ]);

    addRequestLog($requestId, null, 'attachment_uploaded', ucfirst(str_replace('_', ' ', $attachmentType)) . ' uploaded: ' . $originalName);

    return [
        'original_file_name' => $originalName,
        'stored_file_name' => $storedFileName,
        'file_path' => $relativePath,
        'file_type' => $mimeType,
        'file_size' => $size,
    ];
}

function getRequestAttachments(int $requestId): array
{
    ensureItsrWorkflowSchema();

    $stmt = db()->prepare('SELECT * FROM attachments WHERE request_id = :request_id ORDER BY uploaded_at DESC, id DESC');
    $stmt->execute([':request_id' => $requestId]);

    return $stmt->fetchAll();
}

function safeAttachmentPath(array $attachment): ?string
{
    $storedName = basename((string) ($attachment['stored_file_name'] ?? ''));
    if ($storedName === '') {
        return null;
    }

    $path = workflowUploadDirectory() . DIRECTORY_SEPARATOR . $storedName;
    $base = realpath(workflowUploadDirectory());
    $real = is_file($path) ? realpath($path) : false;

    if ($base === false || $real === false || !str_starts_with($real, $base)) {
        return null;
    }

    return $real;
}

function workflowCurrentUserId(): ?int
{
    if (function_exists('currentUserId')) {
        $id = currentUserId();
        return $id > 0 ? $id : null;
    }

    return null;
}

function addRequestLog(int $requestId, ?int $taskId, string $action, string $remarks = ''): void
{
    ensureItsrWorkflowSchema();

    try {
        $stmt = db()->prepare(
            'INSERT INTO request_logs (request_id, task_id, user_id, action, remarks)
             VALUES (:request_id, :task_id, :user_id, :action, :remarks)'
        );
        $stmt->execute([
            ':request_id' => $requestId,
            ':task_id' => $taskId,
            ':user_id' => workflowCurrentUserId(),
            ':action' => $action,
            ':remarks' => $remarks,
        ]);
    } catch (Throwable $exception) {
    }
}

function getRequestLogs(int $requestId): array
{
    ensureItsrWorkflowSchema();

    $stmt = db()->prepare(
        'SELECT rl.*, u.username
         FROM request_logs rl
         LEFT JOIN users u ON u.id = rl.user_id
         WHERE rl.request_id = :request_id
         ORDER BY rl.created_at DESC, rl.id DESC'
    );
    $stmt->execute([':request_id' => $requestId]);

    return $stmt->fetchAll();
}

function requestStatusOptions(): array
{
    return [
        'pending' => 'Pending',
        'returned' => 'Returned',
        'resubmitted' => 'Resubmitted',
        'assigned' => 'Assigned',
        'progress' => 'In Progress',
        'completed' => 'Completed',
        'closed' => 'Closed',
        'reopened' => 'Reopened',
    ];
}

function requestStatusLabel(string $status): string
{
    $options = requestStatusOptions();

    return $options[$status] ?? 'Pending';
}

function requestStatusClass(string $status): string
{
    return match ($status) {
        'returned' => 'status-returned',
        'resubmitted' => 'status-resubmitted',
        'assigned' => 'status-assigned',
        'progress' => 'status-progress',
        'completed', 'closed' => 'status-completed',
        'reopened' => 'status-reopened',
        default => 'status-pending',
    };
}

function returnMissingDocumentOptions(): array
{
    return [
        '' => 'No specific document',
        'qa_approval' => 'QA approval document',
        'supporting_document' => 'Supporting document / attachment',
        'other_document' => 'Other document',
        'both' => 'QA approval and supporting document',
    ];
}

function sendReturnedRequestNotification(array $request, string $reason, string $missingDocument, string $comment): bool
{
    $email = trim((string) ($request['requestor_email'] ?? ''));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $ticket = '#' . (int) ($request['id'] ?? 0);
    $missingLabel = returnMissingDocumentOptions()[$missingDocument] ?? $missingDocument;
    $subject = 'ITSR request returned ' . $ticket;
    $body = "Dear " . trim((string) ($request['requestor_name'] ?? 'Customer')) . ",\n\n"
        . "Your IT service request " . $ticket . " has been returned for update.\n\n"
        . "Reason: " . $reason . "\n"
        . ($missingDocument !== '' ? "Missing document: " . $missingLabel . "\n" : '')
        . ($comment !== '' ? "Comment: " . $comment . "\n" : '')
        . "\nPlease update or resubmit the request with the required information.\n\n"
        . "Regards,\nEnterprise IT Service Team";

    $headers = "From: ITSR <no-reply@localhost>\r\n";

    try {
        return @mail($email, $subject, $body, $headers);
    } catch (Throwable $exception) {
        return false;
    }
}

function workflowGetPdfLinkHtml(string $pdfPath, string $class = 'action-link', string $label = 'PDF', string $levelUp = '../'): string
{
    $pdfPath = trim($pdfPath);
    if ($pdfPath === '') {
        return '<span class="' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '" style="color: #94a3b8; cursor: not-allowed;" title="No PDF path in database">PDF not available</span>';
    }

    $fileName = basename(str_replace('\\', '/', $pdfPath));
    $pdfFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'form' . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'pdfs' . DIRECTORY_SEPARATOR . $fileName;

    if ($fileName !== '' && is_file($pdfFile)) {
        $role = function_exists('currentUserRole') ? currentUserRole() : '';
        $portal = match ($role) {
            'admin' => 'admin',
            'staff' => 'staff',
            default => 'department',
        };
        $url = $levelUp . 'form/download_pdf.php?' . http_build_query([
            'portal' => $portal,
            'file' => $fileName,
        ]);

        return '<a class="' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '" href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</a>';
    }

    return '<span class="' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '" style="color: #94a3b8; cursor: not-allowed;" title="PDF file not found on disk">PDF not available</span>';
}
