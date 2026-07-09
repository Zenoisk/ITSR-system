<?php

declare(strict_types=1);

function itsrRequestStatusMeta(array $request): array
{
    $status = trim((string) ($request['status'] ?? ''));

    return match ($status) {
        'completed' => ['Completed', 'status-completed'],
        'progress' => ['In Progress', 'status-progress'],
        'closed' => ['Closed', 'status-closed'],
        'assigned' => ['Assigned', 'status-assigned'],
        default => ['Pending', 'status-pending'],
    };
}

function itsrParseDate(?string $value): ?DateTimeImmutable
{
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }

    try {
        return new DateTimeImmutable($value);
    } catch (Throwable $exception) {
        return null;
    }
}

function itsrRequestAnchorDate(array $request): ?DateTimeImmutable
{
    foreach (['date_receive', 'request_date', 'created_at'] as $field) {
        $parsed = itsrParseDate((string) ($request[$field] ?? ''));
        if ($parsed !== null) {
            return $parsed;
        }
    }

    return null;
}

function itsrRequestAgeDays(array $request): int
{
    $anchor = itsrRequestAnchorDate($request);
    if ($anchor === null) {
        return 0;
    }

    $now = new DateTimeImmutable('now');
    return max(0, (int) $anchor->diff($now)->format('%a'));
}

function itsrRequestPriorityMeta(array $request): array
{
    $status = trim((string) ($request['status'] ?? 'pending'));
    $ageDays = itsrRequestAgeDays($request);
    $description = strtolower(
        trim(
            implode(' ', array_filter([
                (string) ($request['problem_description'] ?? ''),
                (string) ($request['other_service_specify'] ?? ''),
                (string) ($request['department'] ?? ''),
                (string) ($request['company'] ?? ''),
            ]))
        )
    );

    $criticalSignals = ['urgent', 'critical', 'system down', 'cannot', 'can\'t', 'unable', 'offline', 'outage', 'not working', 'asap'];
    $mediumSignals = ['error', 'issue', 'problem', 'access', 'login', 'internet', 'email', 'printer', 'software'];

    $label = 'Low';
    $class = 'priority-low';

    foreach ($criticalSignals as $signal) {
        if ($signal !== '' && str_contains($description, $signal)) {
            return ['High', 'priority-high'];
        }
    }

    if ($status === 'progress' || $ageDays >= 2) {
        $label = 'Medium';
        $class = 'priority-medium';
    }

    foreach ($mediumSignals as $signal) {
        if ($signal !== '' && str_contains($description, $signal)) {
            $label = 'Medium';
            $class = 'priority-medium';
            break;
        }
    }

    if ($status !== 'completed' && $ageDays >= 4) {
        $label = 'High';
        $class = 'priority-high';
    }

    return [$label, $class];
}

function itsrRequestSlaMeta(array $request): array
{
    $status = trim((string) ($request['status'] ?? 'pending'));
    $ageDays = itsrRequestAgeDays($request);

    if ($status === 'completed') {
        return ['Closed', 'sla-closed', false, $ageDays];
    }

    if ($ageDays >= 5) {
        return ['Overdue', 'sla-overdue', true, $ageDays];
    }

    if ($ageDays >= 3) {
        return ['Due Soon', 'sla-warning', false, $ageDays];
    }

    return ['Within SLA', 'sla-good', false, $ageDays];
}

function itsrRequestTimelineItems(array $request): array
{
    $timeline = [
        [
            'title' => 'Customer submitted form',
            'meta' => 'Request entered into the system.',
            'time' => (string) ($request['created_at'] ?? ''),
        ],
    ];

    if ((int) ($request['is_read'] ?? 0) === 1) {
        $timeline[] = [
            'title' => 'Admin reviewed request',
            'meta' => 'The request has been opened and reviewed.',
            'time' => (string) ($request['updated_at'] ?? $request['created_at'] ?? ''),
        ];
    }

    if (trim((string) ($request['assign_to'] ?? '')) !== '') {
        $timeline[] = [
            'title' => 'Assigned to staff',
            'meta' => 'Assigned to ' . (string) $request['assign_to'] . '.',
            'time' => (string) ($request['updated_at'] ?? $request['created_at'] ?? ''),
        ];
    }

    if (trim((string) ($request['date_receive'] ?? '')) !== '') {
        $timeline[] = [
            'title' => 'Work started',
            'meta' => 'Staff recorded the request as received.',
            'time' => (string) ($request['date_receive'] ?? ''),
        ];
    }

    if (trim((string) ($request['date_complete'] ?? '')) !== '' || (string) ($request['status'] ?? '') === 'completed') {
        $timeline[] = [
            'title' => 'Request completed',
            'meta' => trim((string) ($request['corrective_action'] ?? '')) !== '' ? 'Corrective action recorded.' : 'Marked as completed.',
            'time' => trim((string) ($request['date_complete'] ?? '')) !== '' ? (string) $request['date_complete'] : (string) ($request['updated_at'] ?? ''),
        ];
    }

    return $timeline;
}
