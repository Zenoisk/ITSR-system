<?php

declare(strict_types=1);

require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/workflow.php';

function itsrNotificationAppUrl(): string
{
    $config = itsrMailConfig();
    return rtrim((string) ($config['app_url'] ?? 'http://localhost/dashboard/form2'), '/');
}

function itsrNotificationServiceTypes(array $request): string
{
    $raw = (string) ($request['service_types'] ?? '');
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return 'Not specified';
    }

    $items = array_values(array_filter(array_map(static fn ($item): string => trim((string) $item), $decoded)));
    return $items !== [] ? implode(', ', $items) : 'Not specified';
}

function itsrStaffByUsername(string $username): ?array
{
    $username = trim($username);
    if ($username === '') {
        return null;
    }

    $stmt = db()->prepare("SELECT id, full_name, username, email FROM users WHERE username = :username AND role = 'staff' AND status = 'active' LIMIT 1");
    $stmt->execute([':username' => $username]);
    $staff = $stmt->fetch();

    return $staff ?: null;
}

/**
 * Log email result to both request_logs (timeline) and email_logs (dedicated audit table).
 */
function itsrLogEmailResult(int $requestId, ?int $taskId, string $recipient, bool $sent, string $context, string $error = '', string $emailType = '', string $subject = ''): void
{
    $safeRecipient = $recipient !== '' ? $recipient : 'unknown recipient';
    $remarks = $sent
        ? 'Email notification sent to ' . $safeRecipient . ' for ' . $context . '.'
        : 'Email notification failed for ' . $context . ' to ' . $safeRecipient . ($error !== '' ? '. Reason: ' . $error : '.');

    addRequestLog($requestId, $taskId, $sent ? 'email_notification_sent' : 'email_notification_failed', $remarks);

    if (function_exists('logSystemActivity')) {
        logSystemActivity(
            $sent ? 'email_sent' : 'email_failed',
            $sent ? 'Email notification sent' : 'Email notification failed',
            $remarks
        );
    }

    // Write to dedicated email_logs table for proper audit trail
    try {
        ensureItsrWorkflowSchema(); // ensures email_logs table exists
        $stmt = db()->prepare(
            'INSERT INTO email_logs (request_id, task_id, email_type, recipient_email, subject, status, error_message)
             VALUES (:request_id, :task_id, :email_type, :recipient_email, :subject, :status, :error_message)'
        );
        $stmt->execute([
            ':request_id'     => $requestId > 0 ? $requestId : null,
            ':task_id'        => $taskId,
            ':email_type'     => $emailType !== '' ? $emailType : $context,
            ':recipient_email' => $safeRecipient,
            ':subject'        => $subject,
            ':status'         => $sent ? 'sent' : 'failed',
            ':error_message'  => $error,
        ]);
    } catch (Throwable $exception) {
        error_log('itsrLogEmailResult: could not write to email_logs: ' . $exception->getMessage());
    }
}

function itsrNotificationStatusMessage(array $results): string
{
    if ($results === []) {
        return '';
    }

    return in_array(false, $results, true)
        ? ' Email notification failed. Check SMTP settings and PHP error log.'
        : ' Email notification sent.';
}

/**
 * Feature 1: Send submission confirmation email to requestor after new form submission.
 */
function itsrNotifyRequestorSubmission(array $request): bool
{
    $requestId = (int) ($request['id'] ?? 0);
    $requestorEmail = trim((string) ($request['requestor_email'] ?? ''));
    $requestorName  = trim((string) ($request['requestor_name'] ?? 'Requestor'));

    if ($requestorEmail === '' || !filter_var($requestorEmail, FILTER_VALIDATE_EMAIL)) {
        return false; // No email provided — skip silently
    }

    $submittedAt = trim((string) ($request['created_at'] ?? date('Y-m-d H:i:s')));
    $subject     = 'ITSR Request Received — Ticket #' . $requestId;
    $body        = "Dear " . ($requestorName !== '' ? $requestorName : 'Requestor') . ",\n\n"
        . "Thank you. Your IT Service Request has been received and is now pending admin review.\n\n"
        . "Ticket Number : #" . $requestId . "\n"
        . "Status        : Pending Review\n"
        . "Submitted At  : " . $submittedAt . "\n"
        . "Service Type  : " . itsrNotificationServiceTypes($request) . "\n\n"
        . "Our IT team will review your request shortly. You will be notified once it is assigned or if further information is required.\n\n"
        . "You can view the status of your request on the Department portal:\n"
        . itsrNotificationAppUrl() . "/login/login.php?portal=department\n\n"
        . "Regards,\nEnterprise IT Service Team";

    $mailError = null;
    $sent = itsrSendSmtpMail($requestorEmail, $requestorName, $subject, $body, $mailError);
    itsrLogEmailResult($requestId, null, $requestorEmail, $sent, 'submission confirmation', (string) $mailError, 'submission', $subject);

    return $sent;
}

function itsrNotifyStaffAssignedRequest(array $request, string $staffUsername): bool
{
    $staff = itsrStaffByUsername($staffUsername);
    $requestId = (int) ($request['id'] ?? 0);

    if (!$staff) {
        itsrLogEmailResult($requestId, null, $staffUsername, false, 'staff request assignment', 'Assigned staff account or email was not found.', 'staff_assignment', '');
        return false;
    }

    $staffEmail = trim((string) ($staff['email'] ?? ''));
    $staffName  = trim((string) (($staff['full_name'] ?? '') ?: ($staff['username'] ?? '')));
    $subject    = 'New ITSR request assigned #' . $requestId;
    $body       = "Hello " . ($staffName !== '' ? $staffName : $staffUsername) . ",\n\n"
        . "A new ITSR request has been assigned to you.\n\n"
        . "Ticket: #" . $requestId . "\n"
        . "Requestor: " . (string) ($request['requestor_name'] ?? '-') . "\n"
        . "Department/Company: " . (string) ($request['department'] ?? '-') . " / " . (string) ($request['company'] ?? '-') . "\n"
        . "Service Type: " . itsrNotificationServiceTypes($request) . "\n"
        . "Priority/Status: " . ucfirst((string) ($request['priority'] ?? 'medium')) . " / " . requestStatusLabel((string) ($request['status'] ?? 'assigned')) . "\n"
        . "Problem Description:\n" . trim((string) ($request['problem_description'] ?? 'No description provided.')) . "\n\n"
        . "Please log in to the staff dashboard:\n" . itsrNotificationAppUrl() . "/login/login.php?portal=staff\n";

    $mailError = null;
    $sent = itsrSendSmtpMail($staffEmail, $staffName, $subject, $body, $mailError);
    itsrLogEmailResult($requestId, null, $staffEmail, $sent, 'staff request assignment', (string) $mailError, 'staff_assignment', $subject);

    return $sent;
}

function itsrNotifyRequestorAssigned(array $request, string $staffUsername = ''): bool
{
    $requestId     = (int) ($request['id'] ?? 0);
    $requestorEmail = trim((string) ($request['requestor_email'] ?? ''));
    $requestorName  = trim((string) ($request['requestor_name'] ?? 'Requestor'));
    $subject        = 'Your ITSR request has been accepted #' . $requestId;
    $body           = "Dear " . ($requestorName !== '' ? $requestorName : 'Requestor') . ",\n\n"
        . "Your IT service request has been accepted and assigned to IT staff.\n\n"
        . "Ticket: #" . $requestId . "\n"
        . "Current Status: " . requestStatusLabel((string) ($request['status'] ?? 'assigned')) . "\n"
        . ($staffUsername !== '' ? "Assigned To: " . $staffUsername . "\n" : '')
        . "Department/Company: " . (string) ($request['department'] ?? '-') . " / " . (string) ($request['company'] ?? '-') . "\n"
        . "Service Type: " . itsrNotificationServiceTypes($request) . "\n\n"
        . "You can view the status of your request on the Department portal:\n"
        . itsrNotificationAppUrl() . "/login/login.php?portal=department\n";

    $mailError = null;
    $sent = itsrSendSmtpMail($requestorEmail, $requestorName, $subject, $body, $mailError);
    itsrLogEmailResult($requestId, null, $requestorEmail, $sent, 'requestor assignment notice', (string) $mailError, 'requestor_assigned', $subject);

    return $sent;
}

function itsrNotifyStaffAssignedTask(array $request, array $task, string $staffUsername): bool
{
    $staff     = itsrStaffByUsername($staffUsername);
    $requestId = (int) ($request['id'] ?? $task['parent_request_id'] ?? 0);
    $taskId    = (int) ($task['id'] ?? 0);

    if (!$staff) {
        itsrLogEmailResult($requestId, $taskId, $staffUsername, false, 'staff child task assignment', 'Assigned staff account or email was not found.', 'staff_task_assignment', '');
        return false;
    }

    $staffEmail = trim((string) ($staff['email'] ?? ''));
    $staffName  = trim((string) (($staff['full_name'] ?? '') ?: ($staff['username'] ?? '')));
    $subject    = 'New ITSR task assigned #' . $requestId . '-' . (int) ($task['task_no'] ?? 0);
    $body       = "Hello " . ($staffName !== '' ? $staffName : $staffUsername) . ",\n\n"
        . "A new child task has been assigned to you.\n\n"
        . "Parent Ticket: #" . $requestId . "\n"
        . "Task: " . (string) ($task['task_title'] ?? '-') . "\n"
        . "Requestor: " . (string) ($request['requestor_name'] ?? '-') . "\n"
        . "Department/Company: " . (string) ($request['department'] ?? '-') . " / " . (string) ($request['company'] ?? '-') . "\n"
        . "Priority/Status: " . ucfirst((string) ($task['priority'] ?? 'medium')) . " / " . requestStatusLabel((string) ($task['status'] ?? 'assigned')) . "\n"
        . "Task Description:\n" . trim((string) ($task['task_description'] ?? 'No task description provided.')) . "\n\n"
        . "Please log in to the staff dashboard:\n" . itsrNotificationAppUrl() . "/login/login.php?portal=staff\n";

    $mailError = null;
    $sent = itsrSendSmtpMail($staffEmail, $staffName, $subject, $body, $mailError);
    itsrLogEmailResult($requestId, $taskId, $staffEmail, $sent, 'staff child task assignment', (string) $mailError, 'staff_task_assignment', $subject);

    return $sent;
}

/**
 * Feature 2: Completion email — fixed to use stored completed_at timestamp.
 */
function itsrNotifyRequestorCompleted(array $request, string $solution, ?int $taskId = null): bool
{
    $requestId     = (int) ($request['id'] ?? $request['parent_request_id'] ?? 0);
    $requestorEmail = trim((string) ($request['requestor_email'] ?? ''));
    $requestorName  = trim((string) ($request['requestor_name'] ?? 'Requestor'));

    // Use the stored completed_at timestamp; fall back to current time only if missing
    $completedAt = trim((string) ($request['completed_at'] ?? ''));
    if ($completedAt === '') {
        $completedAt = date('Y-m-d H:i:s');
    }

    $subject = 'Your ITSR request has been completed #' . $requestId;
    $body    = "Dear " . ($requestorName !== '' ? $requestorName : 'Requestor') . ",\n\n"
        . "Your IT service request has been marked as completed.\n\n"
        . "Ticket: #" . $requestId . "\n"
        . "Completion Status: Completed\n"
        . "Completed At: " . $completedAt . "\n\n"
        . "Corrective Action / Solution:\n"
        . (trim($solution) !== '' ? trim($solution) : 'The assigned staff marked the request as completed.') . "\n\n"
        . "You can view your request history on the Department portal:\n"
        . itsrNotificationAppUrl() . "/login/login.php?portal=department\n\n"
        . "Regards,\nEnterprise IT Service Team";

    $mailError = null;
    $sent = itsrSendSmtpMail($requestorEmail, $requestorName, $subject, $body, $mailError);
    itsrLogEmailResult($requestId, $taskId, $requestorEmail, $sent, 'request completion notice', (string) $mailError, 'completion', $subject);

    return $sent;
}

/**
 * Feature 3: Return email via PHPMailer (replaces the old mail() fallback in workflow.php).
 */
function itsrNotifyRequestorReturned(array $request, string $reason, string $missingDocument, string $comment): bool
{
    $requestId     = (int) ($request['id'] ?? 0);
    $requestorEmail = trim((string) ($request['requestor_email'] ?? ''));
    $requestorName  = trim((string) ($request['requestor_name'] ?? 'Customer'));

    if ($requestorEmail === '' || !filter_var($requestorEmail, FILTER_VALIDATE_EMAIL)) {
        itsrLogEmailResult($requestId, null, $requestorEmail, false, 'return notification', 'No valid email address.', 'return', '');
        return false;
    }

    $missingDocOptions = returnMissingDocumentOptions();
    $missingLabel = $missingDocOptions[$missingDocument] ?? $missingDocument;
    $ticket  = '#' . $requestId;
    $subject = 'ITSR Request Returned — Action Required ' . $ticket;
    $body    = "Dear " . ($requestorName !== '' ? $requestorName : 'Customer') . ",\n\n"
        . "Your IT service request " . $ticket . " has been returned and requires your attention.\n\n"
        . "Ticket Number  : " . $ticket . "\n"
        . "Status         : Returned\n"
        . "Return Reason  : " . $reason . "\n"
        . ($missingDocument !== '' ? "Missing Document: " . $missingLabel . "\n" : '')
        . ($comment !== '' ? "Admin Comment  : " . $comment . "\n" : '')
        . "\nPlease update or resubmit the request with the required information.\n"
        . "Resubmit here  : " . itsrNotificationAppUrl() . "/form/request_form.php?id=" . $requestId . "\n\n"
        . "Regards,\nEnterprise IT Service Team";

    $mailError = null;
    $sent = itsrSendSmtpMail($requestorEmail, $requestorName, $subject, $body, $mailError);
    itsrLogEmailResult($requestId, null, $requestorEmail, $sent, 'return notification', (string) $mailError, 'return', $subject);

    return $sent;
}

