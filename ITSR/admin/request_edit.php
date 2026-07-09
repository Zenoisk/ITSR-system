<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/login/auth.php';
requireAdmin();
require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/includes/workflow.php';
require_once dirname(__DIR__) . '/includes/notifications.php';

function requestOrFail(PDO $pdo, int $id): array
{
    $stmt = $pdo->prepare('SELECT * FROM service_requests WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $request = $stmt->fetch();

    if (!$request) {
        http_response_code(404);
        echo 'Request not found.';
        exit;
    }

    return $request;
}

function posted(string $key): string
{
    return trim((string) ($_POST[$key] ?? ''));
}

function decodeServiceTypes(string $raw): array
{
    if ($raw === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [];
    }

    return array_values(array_filter(array_map(static fn ($item): string => trim((string) $item), $decoded)));
}

function aiContainsAny(string $haystack, array $needles): bool
{
    foreach ($needles as $needle) {
        if ($needle !== '' && str_contains($haystack, $needle)) {
            return true;
        }
    }

    return false;
}

function buildAiSuggestion(array $request, array $staffRows): array
{
    $serviceTypes = decodeServiceTypes((string) ($request['service_types'] ?? ''));
    $serviceTypeLookup = array_map(static fn ($item): string => strtolower(trim($item)), $serviceTypes);
    $text = strtolower(trim(
        implode(' ', array_filter([
            (string) ($request['problem_description'] ?? ''),
            (string) ($request['other_service_specify'] ?? ''),
            (string) ($request['location'] ?? ''),
            (string) ($request['department'] ?? ''),
            (string) ($request['company'] ?? ''),
        ]))
    ));

    $categorySignals = [
        'Internet' => ['internet', 'wifi', 'network', 'lan', 'vpn', 'connection', 'online'],
        'Email' => ['email', 'mailbox', 'outlook', 'smtp', 'receive mail', 'send mail'],
        'Hardware' => ['hardware', 'pc', 'laptop', 'printer', 'scanner', 'keyboard', 'monitor', 'mouse', 'cpu', 'device'],
        'Software' => ['software', 'system', 'application', 'app', 'install', 'update', 'license'],
        'AMROS' => ['amros'],
        'SOLO' => ['solo'],
        'Training' => ['training', 'teach', 'guide', 'tutorial'],
        'GP/AIS/FIS' => ['gp/ais/fis', 'gp ais fis', 'ais', 'fis'],
    ];

    $category = 'General Support';
    $categoryReason = 'Based on the written request details.';

    foreach ($categorySignals as $label => $signals) {
        if (in_array(strtolower($label), $serviceTypeLookup, true) || aiContainsAny($text, $signals)) {
            $category = $label;
            $categoryReason = in_array(strtolower($label), $serviceTypeLookup, true)
                ? 'Matches the customer-selected service type.'
                : 'Detected from request keywords.';
            break;
        }
    }

    $highPrioritySignals = ['urgent', 'critical', 'down', 'cannot', 'can\'t', 'unable', 'failed', 'not working', 'asap', 'immediately', 'offline'];
    $mediumPrioritySignals = ['error', 'issue', 'problem', 'slow', 'access', 'login', 'email', 'internet', 'printer', 'software'];
    $lowPrioritySignals = ['training', 'request', 'new user', 'setup', 'guidance', 'tutorial'];

    $priority = 'Medium';
    $priorityReason = 'Normal operational issue that should be reviewed soon.';

    if (aiContainsAny($text, $highPrioritySignals) || in_array('internet', $serviceTypeLookup, true)) {
        $priority = 'High';
        $priorityReason = 'Contains outage or inability-to-work signals that usually need faster attention.';
    } elseif (aiContainsAny($text, $lowPrioritySignals) || in_array('training', $serviceTypeLookup, true)) {
        $priority = 'Low';
        $priorityReason = 'Looks more like a planned or informational request than an active outage.';
    } elseif (aiContainsAny($text, $mediumPrioritySignals)) {
        $priorityReason = 'Shows a user-impacting issue, but not a clear full-stop outage.';
    }

    $requestor = trim((string) ($request['requestor_name'] ?? ''));
    $department = trim((string) ($request['department'] ?? ''));
    $location = trim((string) ($request['location'] ?? ''));
    $description = trim((string) ($request['problem_description'] ?? ''));
    $snippet = $description !== '' ? mb_substr($description, 0, 140) . (mb_strlen($description) > 140 ? '...' : '') : 'No description was provided.';

    $summaryParts = [];
    $summaryParts[] = $requestor !== '' ? $requestor : 'This request';
    if ($department !== '') {
        $summaryParts[] = 'from ' . $department;
    }
    $summary = implode(' ', $summaryParts) . ' likely needs ' . $category . ' support';
    if ($location !== '') {
        $summary .= ' at ' . $location;
    }
    $summary .= '. ' . $snippet;

    $suggestedStaff = '';
    $suggestedStaffReason = 'No active staff account is available yet.';
    $currentAssigned = trim((string) ($request['assign_to'] ?? ''));
    $staffMap = [];
    foreach ($staffRows as $staffRow) {
        $username = trim((string) ($staffRow['username'] ?? ''));
        if ($username !== '') {
            $staffMap[$username] = $staffRow;
        }
    }

    if ($currentAssigned !== '' && isset($staffMap[$currentAssigned])) {
        $suggestedStaff = $currentAssigned;
        $suggestedStaffReason = 'Keeps the existing active assignee on the request for continuity.';
    } elseif ($staffRows !== []) {
        $suggestedStaff = (string) ($staffRows[0]['username'] ?? '');
        $suggestedStaffReason = $suggestedStaff !== ''
            ? 'Suggested from the active staff list with the lightest current open workload.'
            : $suggestedStaffReason;
    }

    $actionStarter = match ($category) {
        'Internet' => "Check connection scope, confirm whether the outage is location-wide, record any affected device details, and update the customer once connectivity is restored.",
        'Email' => "Verify the affected mailbox or user account, check send/receive symptoms, confirm approval details if a new account is needed, and document the final email fix clearly.",
        'Hardware' => "Inspect the affected device, confirm the exact hardware symptom, record asset or location details, and note any replacement or repair performed.",
        'Software' => "Confirm the exact application involved, reproduce the issue if possible, apply the required fix or update, and document the software steps taken.",
        'Training' => "Clarify the requested guidance, confirm the target user, schedule or provide the training support, and record the assistance delivered.",
        default => "Confirm the user impact, verify the scope of the issue, complete the corrective action, and document the resolution in clear operational language.",
    };

    $customerUpdate = 'Dear ' . ($requestor !== '' ? $requestor : 'customer') . ",\n\n"
        . 'Your IT service request is under review. The current recommendation is ' . strtolower($category) . ' support'
        . ($suggestedStaff !== '' ? ' handled by ' . $suggestedStaff : '')
        . ". We will update you again once the work is in progress or completed.\n\nRegards,\nEnterprise IT Service Team";

    return [
        'summary' => $summary,
        'category' => $category,
        'category_reason' => $categoryReason,
        'priority' => $priority,
        'priority_reason' => $priorityReason,
        'suggested_staff' => $suggestedStaff,
        'suggested_staff_reason' => $suggestedStaffReason,
        'action_starter' => $actionStarter,
        'customer_update' => $customerUpdate,
    ];
}

$pdo = db();
ensureItsrWorkflowSchema();
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$request = requestOrFail($pdo, $id);

if ($_SERVER['REQUEST_METHOD'] === 'GET' && (int) ($request['is_read'] ?? 0) === 0) {
    $readStmt = $pdo->prepare('UPDATE service_requests SET is_read = 1, read_at = NOW() WHERE id = :id');
    $readStmt->execute([':id' => $id]);
    addRequestLog($id, null, 'admin_viewed_request', 'Admin opened and reviewed the request.');
    $request = requestOrFail($pdo, $id);
}

$staffUsers = $pdo->query(
    "SELECT
        u.username,
        SUM(CASE WHEN sr.status IN ('pending', 'progress') THEN 1 ELSE 0 END) AS open_count,
        COUNT(sr.id) AS total_count
     FROM users u
     LEFT JOIN service_requests sr ON sr.assign_to = u.username
     WHERE u.role = 'staff' AND u.status = 'active'
     GROUP BY u.id, u.username
     ORDER BY open_count ASC, total_count ASC, u.username ASC"
)->fetchAll();
$message = '';
$error = '';
$statusOptions = requestStatusOptions();
$missingDocumentOptions = returnMissingDocumentOptions();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfValidateOrDie();
    try {
        $previousAssignTo = trim((string) ($request['assign_to'] ?? ''));
        $previousStatus = (string) ($request['status'] ?? 'pending');

        if (($_POST['delete_request'] ?? '') === '1') {
            archiveDeletedRequest($pdo, $id);
            $stmt = $pdo->prepare('DELETE FROM service_requests WHERE id = :id');
            $stmt->execute([':id' => $id]);
            logSystemActivity('request_deleted', 'Request deleted', 'Admin deleted request #' . $id . '.');
            header('Location: requests.php?deleted=1');
            exit;
        }

        if (($_POST['return_request'] ?? '') === '1') {
            $returnReason = posted('return_reason');
            $missingDocument = posted('missing_document');
            $returnComment = posted('return_comment');

            if ($returnReason === '') {
                throw new RuntimeException('Please enter a return reason before returning this request.');
            }

            if (!array_key_exists($missingDocument, $missingDocumentOptions)) {
                $missingDocument = '';
            }

            $returnStmt = $pdo->prepare(
                'INSERT INTO request_returns (request_id, returned_by, return_reason, missing_document, return_comment)
                 VALUES (:request_id, :returned_by, :return_reason, :missing_document, :return_comment)'
            );
            $returnStmt->execute([
                ':request_id' => $id,
                ':returned_by' => currentUserId() > 0 ? currentUserId() : null,
                ':return_reason' => $returnReason,
                ':missing_document' => $missingDocument,
                ':return_comment' => $returnComment,
            ]);

            $pdo->prepare('UPDATE service_requests SET status = :status, is_read = 1, read_at = COALESCE(read_at, NOW()) WHERE id = :id')
                ->execute([':status' => 'returned', ':id' => $id]);

            $request = requestOrFail($pdo, $id);
            $emailSent = itsrNotifyRequestorReturned($request, $returnReason, $missingDocument, $returnComment);
            addRequestLog($id, null, 'request_returned', 'Returned by admin. Reason: ' . $returnReason . ($missingDocument !== '' ? ' Missing: ' . $missingDocumentOptions[$missingDocument] . '.' : ''));
            logSystemActivity('request_returned', 'Request returned', 'Admin returned request #' . $id . ($emailSent ? ' and email notification was attempted.' : '.'));
            $message = 'Request returned successfully' . ($emailSent ? ' and email notification was sent.' : '. Email was not sent because mail is not configured or the address is missing.');
        } elseif (($_POST['create_task'] ?? '') === '1') {
            $taskTitle = posted('task_title');
            $taskDescription = posted('task_description');
            $taskAssignee = posted('task_assigned_to');
            $taskType = slaNormalizeTaskType(posted('child_task_type'));
            $slaData = $taskAssignee !== '' ? slaBuildAssignmentData($taskType) : [
                'task_type' => $taskType,
                'sla_hours' => slaHoursForTaskType($taskType),
                'assigned_at' => null,
                'due_at' => null,
                'sla_result' => 'Pending',
                'is_overdue' => 0,
            ];

            if ($taskTitle === '') {
                throw new RuntimeException('Please enter a task title.');
            }

            $taskNoStmt = $pdo->prepare('SELECT COALESCE(MAX(task_no), 0) + 1 FROM request_tasks WHERE parent_request_id = :parent_request_id');
            $taskNoStmt->execute([':parent_request_id' => $id]);
            $taskNo = (int) $taskNoStmt->fetchColumn();

            $taskStmt = $pdo->prepare(
                'INSERT INTO request_tasks (
                    parent_request_id, task_no, task_title, task_description, assigned_to, status, priority,
                    task_type, sla_hours, assigned_at, due_at, sla_result, is_overdue, date_assigned, created_by
                 )
                 VALUES (
                    :parent_request_id, :task_no, :task_title, :task_description, :assigned_to, :status, :priority,
                    :task_type, :sla_hours, :assigned_at, :due_at, :sla_result, :is_overdue, :date_assigned, :created_by
                 )'
            );
            $taskStmt->execute([
                ':parent_request_id' => $id,
                ':task_no' => $taskNo,
                ':task_title' => $taskTitle,
                ':task_description' => $taskDescription,
                ':assigned_to' => $taskAssignee,
                ':status' => 'assigned',
                ':priority' => strtolower($taskType),
                ':task_type' => $slaData['task_type'],
                ':sla_hours' => $slaData['sla_hours'],
                ':assigned_at' => $slaData['assigned_at'],
                ':due_at' => $slaData['due_at'],
                ':sla_result' => $slaData['sla_result'],
                ':is_overdue' => $slaData['is_overdue'],
                ':date_assigned' => $taskAssignee !== '' ? date('Y-m-d') : null,
                ':created_by' => currentUserId() > 0 ? currentUserId() : null,
            ]);

            $taskId = (int) $pdo->lastInsertId();
            if ($taskAssignee !== '') {
                $parentSla = trim((string) ($request['assigned_at'] ?? '')) === '' ? slaBuildAssignmentData($taskType) : null;
                if ($parentSla) {
                    $pdo->prepare(
                        'UPDATE service_requests
                         SET status = :status, assign_to = IF(assign_to = "", :assigned_to, assign_to), task_type = :task_type,
                             sla_hours = :sla_hours, assigned_at = :assigned_at, due_at = :due_at, sla_result = :sla_result, is_overdue = :is_overdue, is_read = 1
                         WHERE id = :id'
                    )->execute([
                        ':status' => 'assigned',
                        ':assigned_to' => $taskAssignee,
                        ':task_type' => $parentSla['task_type'],
                        ':sla_hours' => $parentSla['sla_hours'],
                        ':assigned_at' => $parentSla['assigned_at'],
                        ':due_at' => $parentSla['due_at'],
                        ':sla_result' => $parentSla['sla_result'],
                        ':is_overdue' => $parentSla['is_overdue'],
                        ':id' => $id,
                    ]);
                } else {
                    $pdo->prepare('UPDATE service_requests SET status = :status, assign_to = IF(assign_to = "", :assigned_to, assign_to), is_read = 1 WHERE id = :id')
                        ->execute([':status' => 'assigned', ':assigned_to' => $taskAssignee, ':id' => $id]);
                }
            }

            addRequestLog($id, $taskId, 'child_task_created', 'Task #' . $taskNo . ' created' . ($taskAssignee !== '' ? ' and assigned to ' . $taskAssignee . ' as ' . $taskType . ' (due ' . $slaData['due_at'] . ')' : '') . '.');
            logSystemActivity('task_created', 'Child task created', 'Admin created task #' . $taskNo . ' for request #' . $id . '.');
            $request = requestOrFail($pdo, $id);
            $emailResults = [];
            if ($taskAssignee !== '') {
                $taskNotifyStmt = $pdo->prepare('SELECT * FROM request_tasks WHERE id = :id LIMIT 1');
                $taskNotifyStmt->execute([':id' => $taskId]);
                $createdTask = $taskNotifyStmt->fetch() ?: [];
                $emailResults[] = itsrNotifyStaffAssignedTask($request, $createdTask, $taskAssignee);
                if (!in_array($previousStatus, ['assigned', 'progress', 'completed', 'closed'], true)) {
                    $emailResults[] = itsrNotifyRequestorAssigned($request, $taskAssignee);
                }
            }
            $message = 'Task created successfully.' . itsrNotificationStatusMessage($emailResults);
        } else {

        $stmt = $pdo->prepare(
            'UPDATE service_requests SET
                company = :company,
                department = :department,
                requestor_email = :requestor_email,
                status = :status,
                requestor_name = :requestor_name,
                requestor_phone = :requestor_phone,
                approved_by_name = :approved_by_name,
                approved_by_phone = :approved_by_phone,
                service_types = :service_types,
                other_service_specify = :other_service_specify,
                location = :location,
                ip_tag_no = :ip_tag_no,
                problem_description = :problem_description,
                is_read = 1,
                assign_to = :assign_to,
                task_type = :task_type,
                corrective_action = :corrective_action
             WHERE id = :id'
        );

        $postedServiceTypes = $_POST['service_types'] ?? [];
        if (!is_array($postedServiceTypes)) {
            $postedServiceTypes = [];
        }

        $stmt->execute([
            ':company' => posted('company'),
            ':department' => posted('department'),
            ':requestor_email' => posted('requestor_email'),
            ':status' => array_key_exists(posted('status'), $statusOptions) ? posted('status') : 'pending',
            ':requestor_name' => posted('requestor_name'),
            ':requestor_phone' => posted('requestor_phone'),
            ':approved_by_name' => posted('approved_by_name'),
            ':approved_by_phone' => posted('approved_by_phone'),
            ':service_types' => json_encode(array_map('strval', $postedServiceTypes), JSON_UNESCAPED_UNICODE),
            ':other_service_specify' => posted('other_service_specify'),
            ':location' => posted('location'),
            ':ip_tag_no' => posted('ip_tag_no'),
            ':problem_description' => posted('problem_description'),
            ':assign_to' => posted('assign_to'),
            ':task_type' => slaNormalizeTaskType(posted('task_type')),
            ':corrective_action' => posted('corrective_action'),
            ':id' => $id,
        ]);

        $newAssignToPosted = posted('assign_to');
        $postedTaskType = slaNormalizeTaskType(posted('task_type'));
        $assignmentChanged = $newAssignToPosted !== '' && (
            $newAssignToPosted !== $previousAssignTo ||
            trim((string) ($request['assigned_at'] ?? '')) === '' ||
            strcasecmp((string) ($request['task_type'] ?? 'Normal'), $postedTaskType) !== 0
        );
        if ($assignmentChanged) {
            $slaData = slaBuildAssignmentData($postedTaskType, trim((string) ($request['assigned_at'] ?? '')) !== '' && $newAssignToPosted === $previousAssignTo ? (string) $request['assigned_at'] : null);
            $pdo->prepare(
                'UPDATE service_requests
                 SET status = :status, task_type = :task_type, sla_hours = :sla_hours, assigned_at = :assigned_at,
                     due_at = :due_at, completed_at = NULL, actual_working_minutes_taken = NULL,
                     total_hour_taken_display = NULL, total_hour_taken = "", sla_result = :sla_result, is_overdue = :is_overdue,
                     date_receive = DATE(:assigned_at)
                 WHERE id = :id'
            )->execute([
                ':status' => 'assigned',
                ':task_type' => $slaData['task_type'],
                ':sla_hours' => $slaData['sla_hours'],
                ':assigned_at' => $slaData['assigned_at'],
                ':due_at' => $slaData['due_at'],
                ':sla_result' => $slaData['sla_result'],
                ':is_overdue' => $slaData['is_overdue'],
                ':id' => $id,
            ]);
            addRequestLog($id, null, 'sla_timer_started', 'Assigned to ' . $newAssignToPosted . ' as ' . $slaData['task_type'] . '. SLA due at ' . $slaData['due_at'] . '.');
        }

        $request = requestOrFail($pdo, $id);
        if ((string) ($request['status'] ?? '') === 'completed' && $previousStatus !== 'completed') {
            $completionData = slaCompleteData($request);
            $pdo->prepare(
                'UPDATE service_requests
                 SET completed_at = :completed_at, date_complete = DATE(:completed_at),
                     actual_working_minutes_taken = :actual_working_minutes_taken,
                     total_hour_taken_display = :total_hour_taken_display, total_hour_taken = :total_hour_taken,
                     sla_result = :sla_result, is_overdue = :is_overdue
                 WHERE id = :id'
            )->execute([
                ':completed_at' => $completionData['completed_at'],
                ':actual_working_minutes_taken' => $completionData['actual_working_minutes_taken'],
                ':total_hour_taken_display' => $completionData['total_hour_taken_display'],
                ':total_hour_taken' => $completionData['total_hour_taken'],
                ':sla_result' => $completionData['sla_result'],
                ':is_overdue' => $completionData['is_overdue'],
                ':id' => $id,
            ]);
            addRequestLog($id, null, 'sla_result_calculated', 'System calculated ' . $completionData['sla_result'] . ' with total time ' . $completionData['total_hour_taken_display'] . '.');
            $request = requestOrFail($pdo, $id);
        }
        addRequestLog($id, null, 'request_updated', 'Admin updated request details. Status is ' . requestStatusLabel((string) ($request['status'] ?? 'pending')) . '.');
        $newAssignTo = trim((string) ($request['assign_to'] ?? ''));
        $emailResults = [];
        if ($newAssignTo !== '' && ($newAssignTo !== $previousAssignTo || $previousStatus !== 'assigned')) {
            $emailResults[] = itsrNotifyStaffAssignedRequest($request, $newAssignTo);
            $emailResults[] = itsrNotifyRequestorAssigned($request, $newAssignTo);
        }
        logSystemActivity(
            'request_updated',
            'Request updated by admin',
            'Request #' . $id . ' is now ' . strtolower(requestStatusLabel((string) ($request['status'] ?? 'pending'))) . '.'
        );
        $message = 'Request updated successfully.' . itsrNotificationStatusMessage($emailResults);
        }
    } catch (Throwable $exception) {
        $error = appErrorMessage($exception, 'Admin request update failed', 'Unable to update the request.');
    }
}

$requestStatusValue = (string) ($request['status'] ?? 'pending');
$requestStatusText = requestStatusLabel($requestStatusValue);
$requestStatusClass = requestStatusClass($requestStatusValue);
$aiSuggestion = buildAiSuggestion($request, $staffUsers);
$attachments = getRequestAttachments($id);
$requestLogs = getRequestLogs($id);
$taskStmt = $pdo->prepare('SELECT * FROM request_tasks WHERE parent_request_id = :parent_request_id ORDER BY task_no ASC, id ASC');
$taskStmt->execute([':parent_request_id' => $id]);
$childTasks = $taskStmt->fetchAll();
$returnStmt = $pdo->prepare('SELECT rr.*, u.username AS returned_by_name FROM request_returns rr LEFT JOIN users u ON u.id = rr.returned_by WHERE rr.request_id = :request_id ORDER BY rr.returned_at DESC, rr.id DESC');
$returnStmt->execute([':request_id' => $id]);
$returnRecords = $returnStmt->fetchAll();
$timelineItems = [
    ['title' => 'Customer submitted form', 'meta' => 'Request entered into the system.', 'time' => (string) ($request['created_at'] ?? '')],
];
if ((int) ($request['is_read'] ?? 0) === 1) {
    $timelineItems[] = ['title' => 'Admin reviewed request', 'meta' => 'The submission has been opened for review.', 'time' => (string) ($request['updated_at'] ?? '')];
}
if (trim((string) ($request['assign_to'] ?? '')) !== '') {
    $timelineItems[] = ['title' => 'Assigned to staff', 'meta' => 'Assigned to ' . (string) $request['assign_to'] . '.', 'time' => (string) ($request['updated_at'] ?? '')];
}
if (trim((string) ($request['date_receive'] ?? '')) !== '') {
    $timelineItems[] = ['title' => 'Work started', 'meta' => 'Staff received the request for action.', 'time' => (string) ($request['date_receive'] ?? '')];
}
if (trim((string) ($request['date_complete'] ?? '')) !== '' || (string) ($request['status'] ?? '') === 'completed') {
    $timelineItems[] = ['title' => 'Request completed', 'meta' => trim((string) ($request['corrective_action'] ?? '')) !== '' ? 'Corrective action recorded.' : 'Marked as completed.', 'time' => trim((string) ($request['date_complete'] ?? '')) !== '' ? (string) $request['date_complete'] : (string) ($request['updated_at'] ?? '')];
}
foreach ($requestLogs as $logItem) {
    $timelineItems[] = [
        'title' => ucwords(str_replace('_', ' ', (string) ($logItem['action'] ?? 'Activity'))),
        'meta' => (string) ($logItem['remarks'] ?? ''),
        'time' => (string) ($logItem['created_at'] ?? ''),
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Request #<?= $id ?></title>
    <link rel="stylesheet" href="../assets/enterprise-ui.css?v=1.1">
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
        .content { padding: 28px; animation: pageEnter 0.28s ease; }
        .page { max-width: 1320px; width: 100%; margin: 0 auto; }
        .topbar, .card {
            background: rgba(255, 255, 255, 0.9);
            border: 1px solid rgba(209, 220, 232, 0.9);
            border-radius: 26px;
            box-shadow: 0 18px 38px rgba(15, 23, 42, 0.08);
            backdrop-filter: blur(10px);
            transition: transform 0.22s ease, box-shadow 0.24s ease, border-color 0.24s ease, background 0.24s ease;
        }
        .topbar:hover, .card:hover {
            transform: translateY(-2px);
            box-shadow: 0 24px 44px rgba(15, 23, 42, 0.11);
        }
        .topbar {
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
            font-weight: 700;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            margin-bottom: 12px;
        }
        .topbar h1 { font-size: 34px; margin-bottom: 8px; color: #0f2642; letter-spacing: -0.03em; }
        .topbar p { color: #65778b; max-width: 760px; line-height: 1.65; }
        .actions { display: flex; gap: 10px; flex-wrap: wrap; }
        .btn, .danger {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 42px;
            border: none;
            border-radius: 14px;
            padding: 0 16px;
            font-weight: 700;
            cursor: pointer;
            text-decoration: none;
            transition: transform 0.18s ease, box-shadow 0.22s ease, border-color 0.22s ease, background 0.22s ease;
        }
        .btn:hover, .danger:hover { transform: translateY(-1px); }
        .btn { background: linear-gradient(180deg, #1f5fbf 0%, #184d9c 100%); color: #fff; }
        .btn-light { background: #fff; color: #20486f; border: 1px solid #cfd9e3; }
        .danger { background: linear-gradient(180deg, #dc2626 0%, #b91c1c 100%); color: #fff; }
        .card { padding: 24px; }
        .notice { padding: 12px 14px; border-radius: 14px; margin-bottom: 16px; font-size: 14px; }
        .success { background: #edf8f1; border: 1px solid #c7e8d1; color: #1f7a3f; }
        .error { background: #fff1f1; border: 1px solid #ebc7c7; color: #8a1f1f; }
        .toast { position: fixed; top: 24px; right: 24px; z-index: 1200; min-width: 280px; max-width: 380px; box-shadow: 0 20px 40px rgba(15, 23, 42, 0.16); animation: toastIn 0.28s ease; }
        .grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; }
        .grid-wide { grid-column: span 2; }
        label { display: block; margin-bottom: 6px; font-size: 13px; font-weight: 700; color: #36516c; }
        input, textarea, select { width: 100%; border: 1px solid #d4dde7; border-radius: 18px; padding: 12px 14px; font-size: 14px; outline: none; background: #fff; }
        textarea { min-height: 120px; resize: vertical; }
        input:focus, textarea:focus, select:focus { border-color: #2c71ba; box-shadow: 0 0 0 4px rgba(44, 113, 186, 0.10); }
        .custom-select { position: relative; z-index: 20; }
        .custom-select.is-open { z-index: 200; }
        .custom-select-input { display: none; }
        .custom-select-toggle {
            width: 100%;
            min-height: 48px;
            border: 1px solid #d4dde7;
            border-radius: 18px;
            padding: 0 44px 0 14px;
            font-size: 14px;
            font-weight: 600;
            text-align: left;
            background: #fff;
            color: #1f3652;
            cursor: pointer;
            position: relative;
            transition: border-color 0.18s ease, box-shadow 0.18s ease, transform 0.18s ease;
        }
        .custom-select-toggle:hover { border-color: #b7c7d9; }
        .custom-select-toggle:focus {
            outline: none;
            border-color: #2c71ba;
            box-shadow: 0 0 0 4px rgba(44, 113, 186, 0.10);
        }
        .custom-select-toggle::after {
            content: '';
            position: absolute;
            top: 50%;
            right: 16px;
            width: 9px;
            height: 9px;
            border-right: 2px solid #6a7c91;
            border-bottom: 2px solid #6a7c91;
            transform: translateY(-65%) rotate(45deg);
            transition: transform 0.18s ease;
        }
        .custom-select.is-open .custom-select-toggle::after {
            transform: translateY(-30%) rotate(-135deg);
        }
        .custom-select-menu {
            position: absolute;
            top: calc(100% + 8px);
            left: 0;
            right: 0;
            z-index: 220;
            padding: 8px;
            border-radius: 22px;
            border: 1px solid #d8e3ef;
            background: #ffffff;
            box-shadow: 0 22px 40px rgba(15, 23, 42, 0.14);
            display: none;
            max-height: 230px;
            overflow-y: auto;
            scrollbar-width: thin;
        }
        .custom-select.is-open .custom-select-menu { display: block; }
        .custom-select-option {
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            border: 0;
            background: transparent;
            border-radius: 14px;
            padding: 11px 12px;
            font-size: 14px;
            text-align: left;
            color: #1f3652;
            cursor: pointer;
            transition: background 0.18s ease, color 0.18s ease;
        }
        .custom-select-option:hover,
        .custom-select-option.is-selected {
            background: #dbeafe;
            color: #1d4ed8;
        }
        .custom-select-option.is-selected::after {
            content: "✓";
            font-weight: 900;
        }
        .custom-select-menu::-webkit-scrollbar { width: 8px; }
        .custom-select-menu::-webkit-scrollbar-track { background: transparent; }
        .custom-select-menu::-webkit-scrollbar-thumb {
            background: rgba(148, 163, 184, 0.6);
            border-radius: 999px;
        }
        .workspace { display: grid; grid-template-columns: minmax(0, 1.45fr) 340px; gap: 18px; }
        .section-title { font-size: 19px; font-weight: 700; margin: 18px 0 12px; color: #1f3652; }
        .aside {
            padding: 22px;
            border-radius: 26px;
            border: 1px solid rgba(209, 220, 232, 0.9);
            background: rgba(255, 255, 255, 0.88);
            box-shadow: 0 18px 38px rgba(15, 23, 42, 0.08);
            backdrop-filter: blur(10px);
            align-self: start;
            position: sticky;
            top: 28px;
            transition: transform 0.22s ease, box-shadow 0.24s ease, border-color 0.24s ease, background 0.24s ease;
        }
        .aside:hover {
            transform: translateY(-2px);
            box-shadow: 0 24px 44px rgba(15, 23, 42, 0.11);
        }
        .status-pill {
            display: inline-flex;
            align-items: center;
            padding: 8px 12px;
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
        .attachment-list, .task-list, .return-list { display: grid; gap: 12px; }
        .attachment-item, .task-item, .return-item {
            border: 1px solid #e2eaf3;
            border-radius: 18px;
            padding: 14px;
            background: linear-gradient(180deg, #fbfdff 0%, #f7fbff 100%);
        }
        .attachment-top, .task-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 12px;
            flex-wrap: wrap;
        }
        .attachment-title, .task-title { color: #102949; font-weight: 800; line-height: 1.35; }
        .attachment-actions, .task-actions { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 10px; }
        .mini-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 34px;
            padding: 0 12px;
            border-radius: 12px;
            border: 1px solid #d7e2ee;
            text-decoration: none;
            color: #204a74;
            background: #fff;
            font-size: 13px;
            font-weight: 700;
        }
        .mini-link.is-primary { background: linear-gradient(180deg, #2563eb 0%, #1d4ed8 100%); color: #fff; border-color: transparent; }
        .task-create-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; margin-top: 12px; }
        .task-create-grid .grid-wide { grid-column: span 2; }
        .aside h2 { font-size: 22px; color: #102949; margin-bottom: 10px; }
        .aside p { color: #6c7b8a; font-size: 14px; line-height: 1.6; }
        .ai-assist { margin-bottom: 18px; padding-bottom: 18px; border-bottom: 1px solid #e6edf4; }
        .ai-head { display: flex; align-items: center; gap: 10px; margin-bottom: 10px; }
        .ai-badge {
            width: 36px;
            height: 36px;
            border-radius: 14px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(180deg, #7c3aed 0%, #4f46e5 100%);
            color: #fff;
            box-shadow: 0 14px 28px rgba(79, 70, 229, 0.22);
        }
        .ai-badge svg {
            width: 18px;
            height: 18px;
            stroke: currentColor;
            stroke-width: 1.9;
            fill: none;
            stroke-linecap: round;
            stroke-linejoin: round;
        }
        .ai-title { font-size: 20px; font-weight: 800; color: #102949; }
        .ai-copy { color: #6c7b8a; font-size: 13px; line-height: 1.65; margin-bottom: 14px; }
        .ai-list { display: grid; gap: 12px; }
        .ai-item {
            padding: 14px 16px;
            border-radius: 18px;
            border: 1px solid #e6edf4;
            background: linear-gradient(180deg, #fbfdff 0%, #f7fbff 100%);
        }
        .ai-item-label {
            display: block;
            font-size: 12px;
            font-weight: 800;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: #5e7390;
            margin-bottom: 8px;
        }
        .ai-item-value { color: #112b4b; font-size: 14px; line-height: 1.7; }
        .ai-chip-row { display: flex; gap: 8px; flex-wrap: wrap; }
        .ai-chip {
            display: inline-flex;
            align-items: center;
            padding: 7px 12px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 800;
        }
        .ai-chip.is-high { background: #fee2e2; color: #b91c1c; }
        .ai-chip.is-medium { background: #fef3c7; color: #a16207; }
        .ai-chip.is-low { background: #dcfce7; color: #15803d; }
        .ai-actions { display: grid; gap: 10px; margin-top: 14px; }
        .ai-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 40px;
            padding: 0 14px;
            border-radius: 13px;
            border: 1px solid #d6e2ef;
            background: #ffffff;
            color: #20486f;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            transition: transform 0.18s ease, background 0.18s ease, border-color 0.18s ease;
        }
        .ai-button:hover { transform: translateY(-1px); background: #f8fbff; border-color: #c6d8ea; }
        .aside-list { display: grid; gap: 12px; margin-top: 18px; }
        .aside-item {
            padding: 14px 16px;
            border-radius: 18px;
            border: 1px solid #e6edf4;
            background: #fbfdff;
        }
        .aside-label {
            display: block;
            color: #708194;
            font-size: 12px;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            margin-bottom: 6px;
        }
        .aside-value {
            color: #102949;
            font-weight: 700;
            line-height: 1.45;
            word-break: break-word;
        }
        .readonly-box {
            min-height: 48px;
            padding: 12px 14px;
            border: 1px solid #d4dde7;
            border-radius: 18px;
            background: #f6f9fc;
            color: #1f3652;
            font-size: 14px;
            line-height: 1.55;
        }
        .sla-box {
            border-color: #bfdbfe;
            background: linear-gradient(180deg, #f8fbff, #edf6ff);
            color: #12314f;
            font-weight: 700;
        }
        .aside-actions { display: grid; gap: 10px; margin-top: 18px; }
        .timeline { margin-top: 18px; padding-top: 18px; border-top: 1px solid #e6edf4; }
        .timeline h3 { font-size: 16px; color: #102949; margin-bottom: 12px; }
        .timeline-list { display: grid; gap: 14px; }
        .timeline-item { display: grid; grid-template-columns: 18px 1fr; gap: 12px; align-items: start; }
        .timeline-dot { width: 10px; height: 10px; margin-top: 6px; border-radius: 999px; background: linear-gradient(180deg, #38bdf8 0%, #2563eb 100%); box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.12); }
        .timeline-title { display: block; color: #102949; font-weight: 700; margin-bottom: 4px; }
        .timeline-meta, .timeline-time { color: #6c7b8a; font-size: 13px; line-height: 1.5; }
        .itsr-confirm-modal[hidden] { display: none; }
        .itsr-confirm-modal { position: fixed; inset: 0; z-index: 1300; display: flex; align-items: center; justify-content: center; }
        .itsr-confirm-backdrop { position: absolute; inset: 0; background: rgba(7, 17, 31, 0.56); backdrop-filter: blur(3px); }
        .itsr-confirm-dialog { position: relative; width: min(420px, calc(100vw - 32px)); padding: 22px; border-radius: 22px; background: rgba(255, 255, 255, 0.96); border: 1px solid rgba(209, 220, 232, 0.92); box-shadow: 0 26px 60px rgba(15, 23, 42, 0.18); }
        .itsr-confirm-dialog h3 { font-size: 24px; color: #0f2642; margin-bottom: 8px; }
        .itsr-confirm-dialog p { color: #64748b; line-height: 1.6; margin-bottom: 18px; }
        .footer-actions { margin-top: 20px; display: flex; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
        .meta { color: #6c7b8a; font-size: 13px; margin-top: 6px; line-height: 1.55; }
        @media (max-width: 1100px) { .workspace { grid-template-columns: 1fr; } .aside { position: static; } }
        @media (max-width: 1024px) { .layout { grid-template-columns: 1fr; } .content { padding: 18px; } .grid { grid-template-columns: 1fr; } .grid-wide { grid-column: span 1; } }
        @media (max-width: 1024px) { .task-create-grid { grid-template-columns: 1fr; } .task-create-grid .grid-wide { grid-column: span 1; } }
        body.theme-dark { background: linear-gradient(180deg, #07111f 0%, #0b1729 100%); color: #e5eef9; }
        body.theme-dark .topbar,
        body.theme-dark .card,
        body.theme-dark .aside { background: rgba(12, 21, 36, 0.9); border-color: rgba(65, 85, 110, 0.72); box-shadow: 0 18px 38px rgba(0, 0, 0, 0.24); }
        body.theme-dark .topbar h1,
        body.theme-dark .section-title,
        body.theme-dark .aside h2,
        body.theme-dark .aside-value { color: #f4f8fd; }
        body.theme-dark .readonly-box {
            background: #0f1a2d;
            border-color: rgba(78, 97, 121, 0.7);
            color: #eef4fb;
        }
        body.theme-dark .sla-box {
            background: linear-gradient(180deg, #10223b, #0f1a2d);
            border-color: rgba(56, 189, 248, 0.34);
            color: #f4f8fd;
        }
        body.theme-dark .topbar p,
        body.theme-dark .meta,
        body.theme-dark .aside p,
        body.theme-dark .aside-label { color: #9eb2c9; }
        body.theme-dark .ai-assist { border-bottom-color: rgba(65, 85, 110, 0.72); }
        body.theme-dark .ai-title,
        body.theme-dark .ai-item-value { color: #f4f8fd; }
        body.theme-dark .ai-copy,
        body.theme-dark .ai-item-label { color: #9eb2c9; }
        body.theme-dark label { color: #bfd2e7; }
        body.theme-dark input,
        body.theme-dark textarea,
        body.theme-dark select { background: #0f1a2d; border-color: rgba(78, 97, 121, 0.7); color: #eef4fb; }
        body.theme-dark .custom-select-toggle {
            background: #0f1a2d;
            border-color: rgba(78, 97, 121, 0.7);
            color: #eef4fb;
        }
        body.theme-dark .custom-select-toggle::after {
            border-color: #9eb2c9;
        }
        body.theme-dark .custom-select-menu {
            background: #0f1a2d;
            border-color: rgba(78, 97, 121, 0.7);
            box-shadow: 0 24px 44px rgba(0, 0, 0, 0.32);
        }
        body.theme-dark .custom-select-option { color: #eef4fb; }
        body.theme-dark .custom-select-option:hover,
        body.theme-dark .custom-select-option.is-selected {
            background: #1e3a6d;
            color: #ffffff;
        }
        body.theme-dark .ai-item { background: rgba(15, 26, 45, 0.7); border-color: rgba(78, 97, 121, 0.55); }
        body.theme-dark .aside-item { background: rgba(15, 26, 45, 0.7); border-color: rgba(78, 97, 121, 0.55); }
        body.theme-dark .ai-button { background: rgba(255,255,255,0.04); border-color: rgba(148, 163, 184, 0.18); color: #eef4fb; }
        body.theme-dark .ai-button:hover { background: rgba(255,255,255,0.08); }
        body.theme-dark .btn-light { background: rgba(255,255,255,0.07); color: #eef4fb; border-color: rgba(148, 163, 184, 0.18); }
        body.theme-dark .success,
        body.theme-dark .error { background: rgba(12, 21, 36, 0.9); }
        body.theme-dark .attachment-item,
        body.theme-dark .task-item,
        body.theme-dark .return-item {
            background: #0f1a2d;
            border-color: rgba(78, 97, 121, 0.7);
        }
        body.theme-dark .attachment-title,
        body.theme-dark .task-title {
            color: #f4f8fd;
        }
        body.theme-dark .mini-link {
            background: rgba(255, 255, 255, 0.04);
            border-color: rgba(148, 163, 184, 0.18);
            color: #eef4fb;
        }
        body.theme-dark .mini-link.is-primary {
            background: linear-gradient(180deg, #2563eb 0%, #1d4ed8 100%);
            color: #fff;
        }
        body.theme-dark .timeline { border-top-color: rgba(65, 85, 110, 0.72); }
        body.theme-dark .timeline h3, body.theme-dark .timeline-title { color: #f4f8fd; }
        body.theme-dark .timeline-meta, body.theme-dark .timeline-time { color: #9eb2c9; }
        body.theme-dark .itsr-confirm-dialog { background: rgba(12, 21, 36, 0.96); border-color: rgba(65, 85, 110, 0.72); }
        body.theme-dark .itsr-confirm-dialog h3 { color: #f4f8fd; }
        body.theme-dark .itsr-confirm-dialog p { color: #9eb2c9; }
        @keyframes pageEnter {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        @keyframes toastIn { from { opacity: 0; transform: translateY(-8px); } to { opacity: 1; transform: translateY(0); } }
        @media (prefers-reduced-motion: reduce) {
            .content,
            .topbar,
            .card,
            .aside,
            .btn,
            .danger {
                animation: none !important;
                transition: none !important;
            }
        }
    </style>
</head>
<body>
    <div class="layout">
        <?php $activePage = 'requests'; require __DIR__ . '/sidebar.php'; ?>
        <main class="content">
            <div class="page">
                <div class="topbar">
                    <div>
                        <div class="eyebrow">Request Editor</div>
                        <h1>Edit Request #<?= $id ?></h1>
                        <p>Review the customer details, assign the request to staff, and keep the operational record clean without leaving this page.</p>
                        <div class="meta">Created at <?= htmlspecialchars((string) $request['created_at'], ENT_QUOTES, 'UTF-8') ?></div>
                    </div>
                    <div class="actions">
                        <a class="btn btn-light" href="requests.php">Back to Requests</a>
                        <?= workflowGetPdfLinkHtml((string) $request['pdf_path'], 'btn btn-light', 'Open PDF', '../') ?>
                    </div>
                </div>

                <?php if ($message !== ''): ?><div class="notice success toast" data-toast><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
                <?php if ($error !== ''): ?><div class="notice error toast" data-toast><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

                <div class="workspace">
                    <div class="card">
                        <form method="post">
                            <?= csrfField() ?>
                            <input type="hidden" name="id" value="<?= $id ?>">
                            <input type="hidden" name="delete_request" id="delete-request-flag" value="">

                            <div class="section-title">Customer Details</div>
                            <div class="grid">
                                <div><label>Company</label><input type="text" name="company" value="<?= htmlspecialchars((string) $request['company'], ENT_QUOTES, 'UTF-8') ?>"></div>
                                <div><label>Department</label><input type="text" name="department" value="<?= htmlspecialchars((string) $request['department'], ENT_QUOTES, 'UTF-8') ?>"></div>
                                <div><label>Email</label><input type="email" name="requestor_email" value="<?= htmlspecialchars((string) $request['requestor_email'], ENT_QUOTES, 'UTF-8') ?>"></div>
                                <div><label>Request Date</label><div class="readonly-box"><?= htmlspecialchars((string) ($request['request_date'] ?: $request['created_at']), ENT_QUOTES, 'UTF-8') ?></div></div>
                                <div><label>Requestor Name</label><input type="text" name="requestor_name" value="<?= htmlspecialchars((string) $request['requestor_name'], ENT_QUOTES, 'UTF-8') ?>"></div>
                                <div><label>Requestor Phone</label><input type="text" name="requestor_phone" value="<?= htmlspecialchars((string) $request['requestor_phone'], ENT_QUOTES, 'UTF-8') ?>"></div>
                                <div><label>Approved By</label><input type="text" name="approved_by_name" value="<?= htmlspecialchars((string) $request['approved_by_name'], ENT_QUOTES, 'UTF-8') ?>"></div>
                                <div><label>Approved By Phone</label><input type="text" name="approved_by_phone" value="<?= htmlspecialchars((string) $request['approved_by_phone'], ENT_QUOTES, 'UTF-8') ?>"></div>
                                <div><label>Location</label><input type="text" name="location" value="<?= htmlspecialchars((string) $request['location'], ENT_QUOTES, 'UTF-8') ?>"></div>
                                <div><label>IP Address / Tag No.</label><input type="text" name="ip_tag_no" value="<?= htmlspecialchars((string) $request['ip_tag_no'], ENT_QUOTES, 'UTF-8') ?>"></div>
                                <div class="grid-wide">
                                    <label>Type of Service Request</label>
                                    <div class="service-types-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 12px; padding: 14px; border: 1px solid #d3e0ec; border-radius: 14px; background: #fbfdff; margin-top: 6px;">
                                        <?php
                                        $allServiceTypes = ['Hardware', 'Software', 'AMROS', 'SOLO', 'Internet', 'Email', 'Training', 'GP/AIS/FIS', 'Others'];
                                        $selectedServiceTypes = decodeServiceTypes((string) ($request['service_types'] ?? ''));
                                        foreach ($allServiceTypes as $type):
                                        ?>
                                            <label style="display: flex; align-items: center; gap: 8px; font-weight: 600; font-size: 13.5px; cursor: pointer; color: #1e293b;">
                                                <input type="checkbox" name="service_types[]" value="<?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?>" <?= in_array($type, $selectedServiceTypes, true) ? 'checked' : '' ?>>
                                                <?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <div class="grid-wide"><label>Other Service Specify</label><input type="text" name="other_service_specify" value="<?= htmlspecialchars((string) $request['other_service_specify'], ENT_QUOTES, 'UTF-8') ?>"></div>
                                <div class="grid-wide"><label>Problem Description</label><textarea name="problem_description"><?= htmlspecialchars((string) $request['problem_description'], ENT_QUOTES, 'UTF-8') ?></textarea></div>
                            </div>

                            <div class="section-title">Uploaded Attachments</div>
                            <?php if (!$attachments): ?>
                                <div class="attachment-item">
                                    <div class="attachment-title">No attachments uploaded yet</div>
                                    <div class="meta">If QA approval or supporting documents are required, return this request with the missing document reason below.</div>
                                </div>
                            <?php else: ?>
                                <div class="attachment-list">
                                    <?php foreach ($attachments as $attachment): ?>
                                        <div class="attachment-item">
                                            <div class="attachment-top">
                                                <div>
                                                    <div class="attachment-title"><?= htmlspecialchars((string) $attachment['original_file_name'], ENT_QUOTES, 'UTF-8') ?></div>
                                                    <div class="meta">
                                                        <?= htmlspecialchars(ucwords(str_replace('_', ' ', (string) $attachment['attachment_type'])), ENT_QUOTES, 'UTF-8') ?>
                                                        &middot; <?= htmlspecialchars((string) $attachment['file_type'], ENT_QUOTES, 'UTF-8') ?: 'Unknown type' ?>
                                                        &middot; <?= number_format(((int) $attachment['file_size']) / 1024, 1) ?> KB
                                                        &middot; <?= htmlspecialchars((string) $attachment['uploaded_at'], ENT_QUOTES, 'UTF-8') ?>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="attachment-actions">
                                                <a class="mini-link is-primary" href="attachment.php?id=<?= (int) $attachment['id'] ?>&action=view" target="_blank">View</a>
                                                <a class="mini-link" href="attachment.php?id=<?= (int) $attachment['id'] ?>&action=download">Download</a>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <div class="section-title">Assignment & Completion</div>
                            <div class="grid">
                                <div>
                                    <label>Status</label>
                                    <div class="custom-select" data-select>
                                        <input class="custom-select-input" type="hidden" name="status" value="<?= htmlspecialchars((string) ($request['status'] ?? 'pending'), ENT_QUOTES, 'UTF-8') ?>">
                                        <button class="custom-select-toggle" type="button" data-select-toggle><?= htmlspecialchars(requestStatusLabel((string) ($request['status'] ?? 'pending')), ENT_QUOTES, 'UTF-8') ?></button>
                                        <div class="custom-select-menu">
                                            <?php foreach ($statusOptions as $statusValue => $statusLabel): ?>
                                                <button class="custom-select-option<?= (($request['status'] ?? 'pending') === $statusValue) ? ' is-selected' : '' ?>" type="button" data-value="<?= htmlspecialchars($statusValue, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') ?></button>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                                <div>
                                    <label>Assign To</label>
                                    <div class="custom-select" data-select>
                                        <input class="custom-select-input" type="hidden" name="assign_to" value="<?= htmlspecialchars((string) ($request['assign_to'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                        <button class="custom-select-toggle" type="button" data-select-toggle><?= htmlspecialchars(trim((string) ($request['assign_to'] ?? '')) !== '' ? (string) $request['assign_to'] : 'Select staff', ENT_QUOTES, 'UTF-8') ?></button>
                                        <div class="custom-select-menu">
                                            <button class="custom-select-option<?= trim((string) ($request['assign_to'] ?? '')) === '' ? ' is-selected' : '' ?>" type="button" data-value="">Select staff</button>
                                            <?php foreach ($staffUsers as $staffUser): ?>
                                                <?php $staffUsername = (string) ($staffUser['username'] ?? ''); ?>
                                                <button class="custom-select-option<?= $staffUsername === (string) ($request['assign_to'] ?? '') ? ' is-selected' : '' ?>" type="button" data-value="<?= htmlspecialchars($staffUsername, ENT_QUOTES, 'UTF-8') ?>">
                                                    <?= htmlspecialchars($staffUsername, ENT_QUOTES, 'UTF-8') ?>
                                                </button>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                                <div>
                                    <label>Type of Task</label>
                                    <div class="custom-select" data-select>
                                        <?php $currentTaskType = slaNormalizeTaskType((string) ($request['task_type'] ?? 'Normal')); ?>
                                        <input class="custom-select-input" type="hidden" name="task_type" value="<?= htmlspecialchars($currentTaskType, ENT_QUOTES, 'UTF-8') ?>">
                                        <button class="custom-select-toggle" type="button" data-select-toggle><?= htmlspecialchars($currentTaskType, ENT_QUOTES, 'UTF-8') ?></button>
                                        <div class="custom-select-menu">
                                            <?php foreach (slaSettings() as $slaType => $slaSetting): ?>
                                                <button class="custom-select-option<?= $currentTaskType === $slaType ? ' is-selected' : '' ?>" type="button" data-value="<?= htmlspecialchars($slaType, ENT_QUOTES, 'UTF-8') ?>">
                                                    <?= htmlspecialchars($slaType . ' (' . (float) $slaSetting['hours'] . 'h)', ENT_QUOTES, 'UTF-8') ?>
                                                </button>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                                <div><label>Assigned At</label><div class="readonly-box"><?= htmlspecialchars((string) ($request['assigned_at'] ?: 'Not started - task not assigned yet'), ENT_QUOTES, 'UTF-8') ?></div></div>
                                <div><label>Due At</label><div class="readonly-box"><?= htmlspecialchars((string) ($request['due_at'] ?: 'Not calculated yet'), ENT_QUOTES, 'UTF-8') ?></div></div>
                                <div><label>Completed At</label><div class="readonly-box"><?= htmlspecialchars((string) ($request['completed_at'] ?: 'Not completed yet'), ENT_QUOTES, 'UTF-8') ?></div></div>
                                <div><label>Total Hour Taken</label><div class="readonly-box sla-box"><?= htmlspecialchars(slaDisplayForRecord($request), ENT_QUOTES, 'UTF-8') ?></div></div>
                                <div><label>SLA Result</label><div class="readonly-box"><?= htmlspecialchars((string) ($request['sla_result'] ?? 'Pending'), ENT_QUOTES, 'UTF-8') ?><?= (int) ($request['is_overdue'] ?? 0) === 1 ? ' / Overdue' : '' ?></div></div>
                                <div class="grid-wide"><label>Corrective Action / Solution</label><textarea name="corrective_action"><?= htmlspecialchars((string) $request['corrective_action'], ENT_QUOTES, 'UTF-8') ?></textarea></div>
                            </div>

                            <div class="section-title">Return Request</div>
                            <div class="grid">
                                <div>
                                    <label>Missing Document</label>
                                    <div class="custom-select" data-select>
                                        <input class="custom-select-input" type="hidden" name="missing_document" value="">
                                        <button class="custom-select-toggle" type="button" data-select-toggle><?= htmlspecialchars((string) ($missingDocumentOptions[''] ?? 'No specific document'), ENT_QUOTES, 'UTF-8') ?></button>
                                        <div class="custom-select-menu">
                                        <?php foreach ($missingDocumentOptions as $missingValue => $missingLabel): ?>
                                            <button class="custom-select-option<?= $missingValue === '' ? ' is-selected' : '' ?>" type="button" data-value="<?= htmlspecialchars($missingValue, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($missingLabel, ENT_QUOTES, 'UTF-8') ?></button>
                                        <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                                <div><label>Return Reason</label><input type="text" name="return_reason" placeholder="Example: QA approval document is required"></div>
                                <div class="grid-wide"><label>Optional Comment</label><textarea name="return_comment" placeholder="Add clear instructions for the department or requestor."></textarea></div>
                            </div>

                            <?php if ($returnRecords): ?>
                                <div class="return-list" style="margin-top: 14px;">
                                    <?php foreach ($returnRecords as $returnRecord): ?>
                                        <div class="return-item">
                                            <div class="attachment-title"><?= htmlspecialchars((string) $returnRecord['return_reason'], ENT_QUOTES, 'UTF-8') ?></div>
                                            <div class="meta">
                                                Returned by <?= htmlspecialchars((string) ($returnRecord['returned_by_name'] ?? 'Admin'), ENT_QUOTES, 'UTF-8') ?>
                                                &middot; <?= htmlspecialchars((string) $returnRecord['returned_at'], ENT_QUOTES, 'UTF-8') ?>
                                                <?php if ((string) ($returnRecord['missing_document'] ?? '') !== ''): ?>
                                                    &middot; Missing: <?= htmlspecialchars($missingDocumentOptions[(string) $returnRecord['missing_document']] ?? (string) $returnRecord['missing_document'], ENT_QUOTES, 'UTF-8') ?>
                                                <?php endif; ?>
                                            </div>
                                            <?php if (trim((string) ($returnRecord['return_comment'] ?? '')) !== ''): ?>
                                                <div class="meta"><?= htmlspecialchars((string) $returnRecord['return_comment'], ENT_QUOTES, 'UTF-8') ?></div>
                                            <?php endif; ?>
                                            <?php if (trim((string) ($returnRecord['resubmitted_at'] ?? '')) !== ''): ?>
                                                <div class="meta"><strong>User resubmitted:</strong> <?= htmlspecialchars((string) $returnRecord['resubmitted_at'], ENT_QUOTES, 'UTF-8') ?></div>
                                                <?php if (trim((string) ($returnRecord['resubmission_note'] ?? '')) !== ''): ?>
                                                    <div class="meta"><?= htmlspecialchars((string) $returnRecord['resubmission_note'], ENT_QUOTES, 'UTF-8') ?></div>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <div class="section-title">Child Task Cards</div>
                            <?php if ($childTasks): ?>
                                <div class="task-list">
                                    <?php foreach ($childTasks as $task): ?>
                                        <div class="task-item">
                                            <div class="task-top">
                                                <div>
                                                    <div class="task-title">Task <?= (int) $task['task_no'] ?>: <?= htmlspecialchars((string) $task['task_title'], ENT_QUOTES, 'UTF-8') ?></div>
                                                    <div class="meta">
                                                        Assigned to <?= htmlspecialchars((string) $task['assigned_to'], ENT_QUOTES, 'UTF-8') ?: 'Unassigned' ?>
                                                        &middot; <?= htmlspecialchars(requestStatusLabel((string) $task['status']), ENT_QUOTES, 'UTF-8') ?>
                                                        &middot; <?= htmlspecialchars((string) ($task['task_type'] ?? 'Normal'), ENT_QUOTES, 'UTF-8') ?> task
                                                        <?php if (trim((string) ($task['due_at'] ?? '')) !== ''): ?>
                                                            &middot; Due <?= htmlspecialchars((string) $task['due_at'], ENT_QUOTES, 'UTF-8') ?>
                                                        <?php endif; ?>
                                                        &middot; <?= htmlspecialchars((string) ($task['sla_result'] ?? 'Pending'), ENT_QUOTES, 'UTF-8') ?>
                                                    </div>
                                                </div>
                                            </div>
                                            <?php if (trim((string) ($task['task_description'] ?? '')) !== ''): ?>
                                                <div class="meta"><?= nl2br(htmlspecialchars((string) $task['task_description'], ENT_QUOTES, 'UTF-8')) ?></div>
                                            <?php endif; ?>
                                            <?php if (trim((string) ($task['solution'] ?? '')) !== ''): ?>
                                                <div class="meta"><strong>Solution:</strong> <?= nl2br(htmlspecialchars((string) $task['solution'], ENT_QUOTES, 'UTF-8')) ?></div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <div class="task-create-grid">
                                <div><label>Task Title</label><input type="text" name="task_title" placeholder="Example: Network troubleshooting"></div>
                                <div>
                                    <label>Assign Task To</label>
                                    <div class="custom-select" data-select>
                                        <input class="custom-select-input" type="hidden" name="task_assigned_to" value="">
                                        <button class="custom-select-toggle" type="button" data-select-toggle>Select staff</button>
                                        <div class="custom-select-menu">
                                        <button class="custom-select-option is-selected" type="button" data-value="">Select staff</button>
                                        <?php foreach ($staffUsers as $staffUser): ?>
                                            <button class="custom-select-option" type="button" data-value="<?= htmlspecialchars((string) $staffUser['username'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string) $staffUser['username'], ENT_QUOTES, 'UTF-8') ?></button>
                                        <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                                <div>
                                    <label>Type of Task</label>
                                    <div class="custom-select" data-select>
                                        <input class="custom-select-input" type="hidden" name="child_task_type" value="Normal">
                                        <button class="custom-select-toggle" type="button" data-select-toggle>Normal</button>
                                        <div class="custom-select-menu">
                                            <?php foreach (slaSettings() as $slaType => $slaSetting): ?>
                                                <button class="custom-select-option<?= $slaType === 'Normal' ? ' is-selected' : '' ?>" type="button" data-value="<?= htmlspecialchars($slaType, ENT_QUOTES, 'UTF-8') ?>">
                                                    <?= htmlspecialchars($slaType . ' (' . (float) $slaSetting['hours'] . 'h)', ENT_QUOTES, 'UTF-8') ?>
                                                </button>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="grid-wide"><label>Task Description</label><textarea name="task_description" placeholder="Describe this specific part of the request."></textarea></div>
                            </div>

                            <div class="footer-actions">
                                <button class="btn" type="submit" name="save_request" value="1">Save Changes</button>
                                <button class="btn btn-light" type="submit" name="create_task" value="1">Create Task Card</button>
                                <button class="danger" type="submit" name="return_request" value="1">Return Request</button>
                                <button class="danger" type="button" data-confirm-delete="request">Delete Request</button>
                            </div>
                        </form>
                    </div>

                    <aside class="aside">
                        <?php if (false): ?>
                        <div class="ai-assist">
                            <div class="ai-head">
                                <span class="ai-badge" aria-hidden="true">
                                    <svg viewBox="0 0 24 24"><path d="M9.5 3.5h5"/><path d="M12 3.5v3"/><path d="M8 20.5h8"/><path d="M9.5 16.5h5"/><path d="M12 16.5v4"/><rect x="5" y="6.5" width="14" height="10" rx="4"/><path d="M9.5 11.5h.01M14.5 11.5h.01"/></svg>
                                </span>
                                <div class="ai-title">AI Assist</div>
                            </div>
                            <div class="ai-copy">Local smart guidance based on the customer form, selected service types, and current staff workload.</div>
                            <div class="ai-list">
                                <div class="ai-item">
                                    <span class="ai-item-label">Request Summary</span>
                                    <div class="ai-item-value"><?= htmlspecialchars((string) $aiSuggestion['summary'], ENT_QUOTES, 'UTF-8') ?></div>
                                </div>
                                <div class="ai-item">
                                    <span class="ai-item-label">Suggested Category</span>
                                    <div class="ai-item-value">
                                        <strong><?= htmlspecialchars((string) $aiSuggestion['category'], ENT_QUOTES, 'UTF-8') ?></strong><br>
                                        <?= htmlspecialchars((string) $aiSuggestion['category_reason'], ENT_QUOTES, 'UTF-8') ?>
                                    </div>
                                </div>
                                <div class="ai-item">
                                    <span class="ai-item-label">Suggested Priority</span>
                                    <div class="ai-chip-row">
                                        <span class="ai-chip is-<?= htmlspecialchars(strtolower((string) $aiSuggestion['priority']) === 'high' ? 'high' : (strtolower((string) $aiSuggestion['priority']) === 'low' ? 'low' : 'medium'), ENT_QUOTES, 'UTF-8') ?>">
                                            <?= htmlspecialchars((string) $aiSuggestion['priority'], ENT_QUOTES, 'UTF-8') ?> Priority
                                        </span>
                                    </div>
                                    <div class="ai-item-value" style="margin-top: 8px;"><?= htmlspecialchars((string) $aiSuggestion['priority_reason'], ENT_QUOTES, 'UTF-8') ?></div>
                                </div>
                                <div class="ai-item">
                                    <span class="ai-item-label">Suggested Staff</span>
                                    <div class="ai-item-value">
                                        <strong><?= htmlspecialchars((string) ($aiSuggestion['suggested_staff'] !== '' ? $aiSuggestion['suggested_staff'] : 'No suggestion'), ENT_QUOTES, 'UTF-8') ?></strong><br>
                                        <?= htmlspecialchars((string) $aiSuggestion['suggested_staff_reason'], ENT_QUOTES, 'UTF-8') ?>
                                    </div>
                                </div>
                                <div class="ai-item">
                                    <span class="ai-item-label">Action Starter</span>
                                    <div class="ai-item-value"><?= htmlspecialchars((string) $aiSuggestion['action_starter'], ENT_QUOTES, 'UTF-8') ?></div>
                                </div>
                            </div>
                            <div class="ai-actions">
                                <?php if ((string) $aiSuggestion['suggested_staff'] !== ''): ?>
                                    <button class="ai-button" type="button" data-apply-select="assign_to" data-apply-value="<?= htmlspecialchars((string) $aiSuggestion['suggested_staff'], ENT_QUOTES, 'UTF-8') ?>">Apply Staff Suggestion</button>
                                <?php endif; ?>
                                <button class="ai-button" type="button" data-apply-text="corrective_action" data-apply-value="<?= htmlspecialchars((string) $aiSuggestion['action_starter'], ENT_QUOTES, 'UTF-8') ?>">Use Action Starter</button>
                                <button class="ai-button" type="button" data-copy-text="<?= htmlspecialchars((string) $aiSuggestion['customer_update'], ENT_QUOTES, 'UTF-8') ?>">Copy Customer Update Draft</button>
                            </div>
                        </div>
                        <?php endif; ?>

                        <h2>Request Snapshot</h2>
                        <p>Keep the important context visible while you edit so the request stays consistent from intake to completion.</p>
                        <div class="aside-list">
                            <div class="aside-item">
                                <span class="aside-label">Current Status</span>
                                <span class="status-pill <?= htmlspecialchars($requestStatusClass, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($requestStatusText, ENT_QUOTES, 'UTF-8') ?></span>
                            </div>
                            <div class="aside-item">
                                <span class="aside-label">Requestor</span>
                                <div class="aside-value"><?= htmlspecialchars((string) $request['requestor_name'], ENT_QUOTES, 'UTF-8') ?: '-' ?></div>
                            </div>
                            <div class="aside-item">
                                <span class="aside-label">Customer Contact</span>
                                <div class="aside-value"><?= htmlspecialchars((string) $request['requestor_email'], ENT_QUOTES, 'UTF-8') ?: '-' ?></div>
                                <div class="meta"><?= htmlspecialchars((string) $request['requestor_phone'], ENT_QUOTES, 'UTF-8') ?: '-' ?></div>
                            </div>
                            <div class="aside-item">
                                <span class="aside-label">Assigned Staff</span>
                                <div class="aside-value"><?= htmlspecialchars((string) $request['assign_to'], ENT_QUOTES, 'UTF-8') ?: 'Not assigned yet' ?></div>
                            </div>
                            <div class="aside-item">
                                <span class="aside-label">Location</span>
                                <div class="aside-value"><?= htmlspecialchars((string) $request['location'], ENT_QUOTES, 'UTF-8') ?: '-' ?></div>
                            </div>
                        </div>
                        <div class="aside-actions">
                            <?= workflowGetPdfLinkHtml((string) $request['pdf_path'], 'btn btn-light', 'Open Saved PDF', '../') ?>
                            <a class="btn btn-light" href="requests.php">Return to Request List</a>
                        </div>
                        <div class="timeline">
                            <h3>Request Timeline</h3>
                            <div class="timeline-list">
                                <?php foreach ($timelineItems as $timelineItem): ?>
                                    <div class="timeline-item">
                                        <span class="timeline-dot" aria-hidden="true"></span>
                                        <div>
                                            <span class="timeline-title"><?= htmlspecialchars((string) $timelineItem['title'], ENT_QUOTES, 'UTF-8') ?></span>
                                            <div class="timeline-meta"><?= htmlspecialchars((string) $timelineItem['meta'], ENT_QUOTES, 'UTF-8') ?></div>
                                            <div class="timeline-time"><?= htmlspecialchars((string) $timelineItem['time'], ENT_QUOTES, 'UTF-8') ?></div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </aside>
                </div>
            </div>
        </main>
    </div>
    <div class="itsr-confirm-modal" id="confirm-modal" hidden>
        <div class="itsr-confirm-backdrop" data-confirm-close></div>
        <div class="itsr-confirm-dialog">
            <h3>Delete Request?</h3>
            <p>This will permanently remove the request from the system.</p>
            <div class="footer-actions">
                <button class="btn btn-light" type="button" data-confirm-close>Cancel</button>
                <button class="danger" type="button" id="confirm-delete-action">Delete</button>
            </div>
        </div>
    </div>
    <script>
        (function () {
            document.querySelectorAll('[data-select]').forEach(function (select) {
                var hiddenInput = select.querySelector('.custom-select-input');
                var toggle = select.querySelector('[data-select-toggle]');
                var options = Array.from(select.querySelectorAll('.custom-select-option'));

                if (!hiddenInput || !toggle || options.length === 0) {
                    return;
                }

                toggle.addEventListener('click', function () {
                    document.querySelectorAll('[data-select]').forEach(function (otherSelect) {
                        if (otherSelect !== select) {
                            otherSelect.classList.remove('is-open');
                        }
                    });
                    select.classList.toggle('is-open');
                });

                options.forEach(function (option) {
                    option.addEventListener('click', function () {
                        hiddenInput.value = option.getAttribute('data-value') || '';
                        toggle.textContent = option.textContent || '';
                        options.forEach(function (item) {
                            item.classList.remove('is-selected');
                        });
                        option.classList.add('is-selected');
                        select.classList.remove('is-open');
                    });
                });
            });

            document.addEventListener('click', function (event) {
                document.querySelectorAll('[data-select]').forEach(function (select) {
                    if (!select.contains(event.target)) {
                        select.classList.remove('is-open');
                    }
                });
            });

            document.querySelectorAll('[data-apply-select]').forEach(function (button) {
                button.addEventListener('click', function () {
                    var fieldName = button.getAttribute('data-apply-select') || '';
                    var nextValue = button.getAttribute('data-apply-value') || '';
                    var hiddenInput = document.querySelector('.custom-select-input[name="' + fieldName + '"]');

                    if (!hiddenInput) {
                        return;
                    }

                    hiddenInput.value = nextValue;

                    var select = hiddenInput.closest('[data-select]');
                    if (select) {
                        var toggle = select.querySelector('[data-select-toggle]');
                        var options = Array.from(select.querySelectorAll('.custom-select-option'));
                        if (toggle) {
                            toggle.textContent = nextValue !== '' ? nextValue : 'Select staff';
                        }
                        options.forEach(function (option) {
                            option.classList.toggle('is-selected', (option.getAttribute('data-value') || '') === nextValue);
                        });
                    }
                });
            });

            document.querySelectorAll('[data-apply-text]').forEach(function (button) {
                button.addEventListener('click', function () {
                    var fieldName = button.getAttribute('data-apply-text') || '';
                    var nextValue = button.getAttribute('data-apply-value') || '';
                    var target = document.querySelector('[name="' + fieldName + '"]');

                    if (!target) {
                        return;
                    }

                    target.value = nextValue;
                    target.focus();
                });
            });

            document.querySelectorAll('[data-copy-text]').forEach(function (button) {
                button.addEventListener('click', function () {
                    var text = button.getAttribute('data-copy-text') || '';
                    if (text === '') {
                        return;
                    }

                    if (navigator.clipboard && navigator.clipboard.writeText) {
                        navigator.clipboard.writeText(text).catch(function () {});
                    } else {
                        var helper = document.createElement('textarea');
                        helper.value = text;
                        document.body.appendChild(helper);
                        helper.select();
                        try { document.execCommand('copy'); } catch (error) {}
                        helper.remove();
                    }
                });
            });

            document.querySelectorAll('[data-toast]').forEach(function (toast) {
                window.setTimeout(function () {
                    toast.style.opacity = '0';
                    toast.style.transform = 'translateY(-8px)';
                    window.setTimeout(function () { toast.remove(); }, 220);
                }, 2800);
            });
            var modal = document.getElementById('confirm-modal');
            var confirmButton = document.getElementById('confirm-delete-action');
            var deleteFlag = document.getElementById('delete-request-flag');
            var deleteTrigger = document.querySelector('[data-confirm-delete="request"]');
            var form = deleteFlag ? deleteFlag.closest('form') : null;
            if (!modal || !confirmButton || !deleteFlag || !deleteTrigger || !form) { return; }
            function closeModal() { modal.hidden = true; }
            deleteTrigger.addEventListener('click', function () { modal.hidden = false; });
            modal.querySelectorAll('[data-confirm-close]').forEach(function (control) { control.addEventListener('click', closeModal); });
            confirmButton.addEventListener('click', function () { deleteFlag.value = '1'; form.submit(); });
        })();
    </script>
</body>
</html>
