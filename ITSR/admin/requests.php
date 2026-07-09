<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/login/auth.php';
requireAdmin();
require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/includes/workflow.php';

function requestsRedirectUrl(array $extra = []): string
{
    $query = [];
    $search = trim((string) ($_GET['search'] ?? ''));
    $status = trim((string) ($_GET['status'] ?? ''));
    $read = trim((string) ($_GET['read'] ?? ''));
    $assignment = trim((string) ($_GET['assignment'] ?? ''));

    if ($search !== '') {
        $query['search'] = $search;
    }
    if ($status !== '' && $status !== 'all') {
        $query['status'] = $status;
    }
    if ($read !== '' && $read !== 'all') {
        $query['read'] = $read;
    }
    if ($assignment === 'unassigned') {
        $query['assignment'] = $assignment;
    }

    foreach ($extra as $key => $value) {
        $query[$key] = $value;
    }

    $queryString = http_build_query($query);
    return 'requests.php' . ($queryString !== '' ? '?' . $queryString : '');
}

$isAdmin = currentUserRole() === 'admin';
$search = trim((string) ($_GET['search'] ?? ''));
$statusFilter = trim((string) ($_GET['status'] ?? ''));
$readFilter = trim((string) ($_GET['read'] ?? ''));
$assignmentFilter = trim((string) ($_GET['assignment'] ?? ''));
$statusOptions = requestStatusOptions();
$allowedStatuses = array_keys($statusOptions);
$allowedRead = ['all', 'unread', 'read'];

if (!in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = 'all';
}

if (!in_array($readFilter, $allowedRead, true)) {
    $readFilter = 'all';
}

if ($assignmentFilter !== 'unassigned') {
    $assignmentFilter = 'all';
}

$requests = [];
$error = '';
$deleted = isset($_GET['deleted']) && $_GET['deleted'] === '1';
$bulkDeleted = isset($_GET['bulk_deleted']) && $_GET['bulk_deleted'] === '1';
$statusUpdated = isset($_GET['status_updated']) && $_GET['status_updated'] === '1';
$summary = [
    'visible' => 0,
    'unread' => 0,
    'progress' => 0,
    'assigned' => 0,
];

try {
    $pdo = db();
    ensureItsrWorkflowSchema();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrfValidateOrDie();
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAdmin && ($_POST['delete_request'] ?? '') !== '') {
        $deleteId = (int) ($_POST['delete_request'] ?? 0);

        if ($deleteId > 0) {
            archiveDeletedRequest($pdo, $deleteId);
            $deleteStmt = $pdo->prepare('DELETE FROM service_requests WHERE id = :id');
            $deleteStmt->execute([':id' => $deleteId]);
            logSystemActivity('request_deleted', 'Request deleted', 'Admin deleted request #' . $deleteId . ' from the requests list.');
            header('Location: ' . requestsRedirectUrl(['deleted' => '1']));
            exit;
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAdmin && ($_POST['delete_selected'] ?? '') === '1') {
        $selectedIds = array_values(array_filter(
            array_map('intval', (array) ($_POST['selected_requests'] ?? [])),
            static fn (int $id): bool => $id > 0
        ));

        if ($selectedIds !== []) {
            foreach ($selectedIds as $selectedId) {
                archiveDeletedRequest($pdo, $selectedId);
            }
            $placeholders = implode(',', array_fill(0, count($selectedIds), '?'));
            $deleteStmt = $pdo->prepare("DELETE FROM service_requests WHERE id IN ($placeholders)");
            $deleteStmt->execute($selectedIds);
            logSystemActivity('request_deleted', 'Bulk request delete', 'Admin deleted ' . count($selectedIds) . ' request(s) from the requests list.');
            header('Location: ' . requestsRedirectUrl(['bulk_deleted' => '1']));
            exit;
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAdmin && ($_POST['update_request_status'] ?? '') === '1') {
        $updateId = (int) ($_POST['update_request_id'] ?? 0);
        $nextStatus = trim((string) ($_POST['update_request_value'] ?? ''));

        if ($updateId > 0 && in_array($nextStatus, $allowedStatuses, true)) {
            $statusStmt = $pdo->prepare('UPDATE service_requests SET status = :status, is_read = 1 WHERE id = :id');
            $statusStmt->execute([
                ':status' => $nextStatus,
                ':id' => $updateId,
            ]);
            if ($nextStatus === 'completed') {
                $completionStmt = $pdo->prepare('SELECT * FROM service_requests WHERE id = :id LIMIT 1');
                $completionStmt->execute([':id' => $updateId]);
                $completionRequest = $completionStmt->fetch();
                if ($completionRequest && trim((string) ($completionRequest['completed_at'] ?? '')) === '') {
                    $completionData = slaCompleteData($completionRequest);
                    $pdo->prepare(
                        'UPDATE service_requests
                         SET completed_at = :completed_at,
                             date_complete = DATE(:completed_at),
                             actual_working_minutes_taken = :actual_working_minutes_taken,
                             total_hour_taken_display = :total_hour_taken_display,
                             total_hour_taken = :total_hour_taken,
                             sla_result = :sla_result,
                             is_overdue = :is_overdue
                         WHERE id = :id'
                    )->execute([
                        ':completed_at' => $completionData['completed_at'],
                        ':actual_working_minutes_taken' => $completionData['actual_working_minutes_taken'],
                        ':total_hour_taken_display' => $completionData['total_hour_taken_display'],
                        ':total_hour_taken' => $completionData['total_hour_taken'],
                        ':sla_result' => $completionData['sla_result'],
                        ':is_overdue' => $completionData['is_overdue'],
                        ':id' => $updateId,
                    ]);
                    addRequestLog($updateId, null, 'sla_result_calculated', 'Admin completed request from the request list. Time taken: ' . $completionData['total_hour_taken_display'] . '.');
                }
            }
            addRequestLog($updateId, null, 'request_status_changed', 'Admin changed status to ' . requestStatusLabel($nextStatus) . ' from the request list.');
            header('Location: ' . requestsRedirectUrl(['status_updated' => '1']));
            exit;
        }
    }

    $conditions = [];
    $params = [];

    if ($search !== '') {
        $conditions[] = '(requestor_name LIKE :search OR requestor_email LIKE :search OR company LIKE :search OR department LIKE :search OR location LIKE :search OR assign_to LIKE :search)';
        $params[':search'] = '%' . $search . '%';
    }

    if ($statusFilter !== 'all') {
        $conditions[] = 'status = :status';
        $params[':status'] = $statusFilter;
    }

    if ($readFilter === 'unread') {
        $conditions[] = 'is_read = 0';
    } elseif ($readFilter === 'read') {
        $conditions[] = 'is_read = 1';
    }

    if ($assignmentFilter === 'unassigned') {
        $conditions[] = "(assign_to IS NULL OR assign_to = '')";
    }

    $sql = 'SELECT
                id,
                company,
                department,
                requestor_name,
                requestor_email,
                requestor_phone,
                request_date,
                status,
                is_read,
                location,
                assign_to,
                date_receive,
                pdf_path,
                created_at
            FROM service_requests';

    if ($conditions) {
        $sql .= ' WHERE ' . implode(' AND ', $conditions);
    }

    $sql .= ' ORDER BY id DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $requests = $stmt->fetchAll();

    $summary['visible'] = count($requests);
    foreach ($requests as $request) {
        if ((int) ($request['is_read'] ?? 0) === 0) {
            $summary['unread']++;
        }
        if (($request['status'] ?? '') === 'progress') {
            $summary['progress']++;
        }
        if (trim((string) ($request['assign_to'] ?? '')) !== '') {
            $summary['assigned']++;
        }
    }
} catch (Throwable $exception) {
    $error = appErrorMessage($exception, 'Admin request list failed', 'Unable to load requests.');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>All Requests - IT Service Request</title>
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
        .content > * {
            max-width: 1320px;
            margin-left: auto;
            margin-right: auto;
        }
        .hero, .table-card {
            background: rgba(255, 255, 255, 0.9);
            border: 1px solid rgba(209, 220, 232, 0.9);
            border-radius: 24px;
            box-shadow: 0 18px 38px rgba(15, 23, 42, 0.08);
            backdrop-filter: blur(10px);
            transition: transform 0.22s ease, box-shadow 0.24s ease, border-color 0.24s ease, background 0.24s ease;
        }
        .hero:hover, .table-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 24px 44px rgba(15, 23, 42, 0.11);
        }
        .hero {
            position: relative;
            z-index: 5;
            overflow: visible;
            padding: 26px 28px;
            margin-bottom: 18px;
        }
        .hero-top {
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
            letter-spacing: 0.1em;
            text-transform: uppercase;
            margin-bottom: 12px;
            font-weight: 700;
        }
        .hero h1 { font-size: 34px; margin-bottom: 10px; color: #0f2642; }
        .hero p { max-width: 760px; line-height: 1.65; color: #64748b; }
        .actions { display: flex; gap: 10px; flex-wrap: wrap; }
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            border-radius: 14px;
            padding: 12px 18px;
            font-size: 14px;
            font-weight: 700;
            border: 1px solid transparent;
            cursor: pointer;
            transition: transform 0.18s ease, box-shadow 0.22s ease, border-color 0.22s ease, background 0.22s ease;
        }
        .btn:hover { transform: translateY(-1px); }
        .btn-primary { background: linear-gradient(180deg, #2563eb 0%, #1d4ed8 100%); color: #fff; }
        .btn-light { background: #fff; color: #334155; border-color: #d7e0ea; }
        .btn-danger { background: linear-gradient(180deg, #d84c4c 0%, #b42318 100%); color: #fff; }
        .controls { margin-top: 18px; position: relative; z-index: 12; }
        .hero-stats {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 12px;
            margin-top: 18px;
        }
        .hero-stat {
            padding: 16px 18px;
            border-radius: 18px;
            border: 1px solid rgba(209, 220, 232, 0.72);
            background: rgba(255, 255, 255, 0.86);
            transition: transform 0.2s ease, border-color 0.22s ease, background 0.22s ease;
        }
        .hero-stat:hover { transform: translateY(-1px); }
        .hero .hero-stat strong {
            display: block;
            margin-bottom: 6px;
            font-size: 28px;
            color: #0f2642;
            line-height: 1;
        }
        .hero .hero-stat span {
            color: #52677f;
            font-size: 13px;
            line-height: 1.45;
        }
        .controls-grid {
            display: grid;
            grid-template-columns: 2.2fr 1fr 1fr 1fr auto;
            gap: 12px;
        }
        input, select {
            width: 100%;
            border: 1px solid #d4dde7;
            border-radius: 14px;
            padding: 12px 14px;
            font-size: 14px;
            outline: none;
            background: #fff;
        }
        input:focus, select:focus { border-color: #2c71ba; box-shadow: 0 0 0 4px rgba(44, 113, 186, 0.10); }
        .custom-select { position: relative; z-index: 20; }
        .custom-select-input {
            display: none;
        }
        .custom-select-toggle {
            width: 100%;
            min-height: 48px;
            border: 1px solid #d4dde7;
            border-radius: 16px;
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
        .custom-select-toggle:hover {
            border-color: #b7c7d9;
        }
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
            z-index: 40;
            padding: 8px;
            border-radius: 18px;
            border: 1px solid #d8e3ef;
            background: #ffffff;
            box-shadow: 0 22px 40px rgba(15, 23, 42, 0.14);
            display: none;
            max-height: 190px;
            overflow-y: auto;
            scrollbar-width: thin;
        }
        .custom-select.is-open .custom-select-menu {
            display: block;
        }
        .custom-select-option {
            width: 100%;
            border: 0;
            background: transparent;
            border-radius: 12px;
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
        .custom-select-menu::-webkit-scrollbar {
            width: 8px;
        }
        .custom-select-menu::-webkit-scrollbar-track {
            background: transparent;
        }
        .custom-select-menu::-webkit-scrollbar-thumb {
            background: rgba(148, 163, 184, 0.6);
            border-radius: 999px;
        }
        .table-card { position: relative; z-index: 1; overflow: visible; }
        .table-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            padding: 18px 20px;
            border-bottom: 1px solid #e1e8ef;
            flex-wrap: wrap;
            background: linear-gradient(180deg, #fbfdff 0%, #f7fbff 100%);
        }
        .table-head h2 { font-size: 24px; color: #0f2642; }
        .table-head p { color: #66788b; font-size: 14px; margin-top: 4px; }
        .table-head-actions {
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
        }
        .bulk-actions { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
        .bulk-count {
            color: #64748b;
            font-size: 13px;
            font-weight: 700;
            padding: 10px 12px;
            border-radius: 999px;
            background: #f3f7fb;
            border: 1px solid #e2e8f0;
        }
        .table-wrap { overflow-x: auto; overflow-y: visible; scrollbar-width: thin; padding-bottom: 180px; }
        table { width: 100%; min-width: 1580px; border-collapse: collapse; }
        th, td {
            padding: 16px 18px;
            border-bottom: 1px solid #e7edf3;
            text-align: left;
            font-size: 14px;
            vertical-align: top;
        }
        th {
            background: #f7fafd;
            color: #284764;
            font-weight: 700;
            font-size: 13px;
            position: sticky;
            top: 0;
            z-index: 1;
        }
        tbody tr:hover { background: #fbfdff; }
        .muted { color: #708194; font-size: 13px; margin-top: 4px; }
        .pill {
            display: inline-flex;
            align-items: center;
            padding: 7px 12px;
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
        .priority-high { background: #fee2e2; color: #b91c1c; }
        .priority-medium { background: #fef3c7; color: #a16207; }
        .priority-low { background: #e0f2fe; color: #0369a1; }
        .sla-good { background: #e0f2fe; color: #0369a1; }
        .sla-warning { background: #fef3c7; color: #a16207; }
        .sla-overdue { background: #fee2e2; color: #b91c1c; }
        .sla-closed { background: #dcfce7; color: #15803d; }
        .is-unread { background: #eef4ff; color: #1d63b8; }
        .is-read { background: #eef2f7; color: #64748b; }
        .pdf-link { color: #1f5fbf; font-weight: 700; text-decoration: none; white-space: nowrap; display: inline-block; }
        .select-col { width: 64px; text-align: center; }
        .table-check {
            appearance: none;
            -webkit-appearance: none;
            position: relative;
            width: 24px;
            height: 24px;
            border-radius: 9px;
            border: 1px solid #b8c7d8;
            background:
                linear-gradient(180deg, #ffffff 0%, #f5f9fd 100%);
            cursor: pointer;
            vertical-align: middle;
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.9), 0 8px 18px rgba(15, 23, 42, 0.06);
            transition: transform 0.18s ease, border-color 0.18s ease, background 0.18s ease, box-shadow 0.18s ease;
        }
        .table-check:hover {
            transform: translateY(-1px);
            border-color: #2563eb;
            box-shadow: 0 10px 22px rgba(37, 99, 235, 0.16);
        }
        .table-check:focus-visible {
            outline: none;
            box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.14), 0 10px 22px rgba(37, 99, 235, 0.14);
        }
        .table-check:checked {
            border-color: #2563eb;
            background:
                url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='18' height='18' viewBox='0 0 24 24' fill='none'%3E%3Cpath d='M6 12.5l4 4L18.5 8' stroke='white' stroke-width='3' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E") center / 17px 17px no-repeat,
                linear-gradient(180deg, #38bdf8 0%, #2563eb 100%);
            box-shadow: 0 12px 24px rgba(37, 99, 235, 0.24);
        }
        .table-check:indeterminate {
            border-color: #2563eb;
            background:
                linear-gradient(#ffffff, #ffffff) center / 12px 3px no-repeat,
                linear-gradient(180deg, #38bdf8 0%, #2563eb 100%);
            box-shadow: 0 12px 24px rgba(37, 99, 235, 0.22);
        }
        tbody tr:has(.row-check:checked) {
            background: linear-gradient(90deg, rgba(219, 234, 254, 0.72), rgba(255, 255, 255, 0.92));
        }
        .action-stack { display: flex; gap: 10px; flex-wrap: nowrap; align-items: center; min-width: 292px; }
        .status-editor { display: grid; gap: 10px; min-width: 190px; }
        .status-editor .custom-select { z-index: 80; }
        .status-editor .custom-select.is-open { z-index: 500; }
        .status-editor .custom-select-toggle {
            min-height: 38px;
            border-radius: 12px;
            padding-right: 38px;
            font-size: 13px;
            font-weight: 700;
        }
        .status-editor .custom-select-menu {
            top: calc(100% + 8px);
            bottom: auto;
            border-radius: 14px;
            max-height: 260px;
            z-index: 520;
        }
        .status-editor .custom-select-option {
            padding: 9px 10px;
            font-size: 13px;
            font-weight: 700;
        }
        .action-chip {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 34px;
            padding: 0 12px;
            border-radius: 12px;
            border: 1px solid #d7e2ee;
            text-decoration: none;
            background: #fff;
            color: #204a74;
            font-size: 13px;
            font-weight: 700;
            white-space: nowrap;
        }
        .action-chip.is-primary {
            background: linear-gradient(180deg, #2563eb 0%, #1d4ed8 100%);
            border-color: transparent;
            color: #fff;
        }
        .action-button {
            border: 1px solid #f1c8c8;
            background: #fff5f5;
            padding: 0 12px;
            min-height: 34px;
            border-radius: 12px;
            color: #b42318;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            text-align: center;
            white-space: nowrap;
        }
        .action-button:hover { background: #fee2e2; color: #8e1c1c; }
        .empty-card {
            max-width: 460px;
            min-height: 120px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            gap: 8px;
        }
        .empty-card strong {
            color: #102949;
            font-size: 17px;
        }
        .empty-card span {
            color: #6f8298;
            line-height: 1.55;
            font-size: 14px;
        }
        .itsr-drawer[hidden] { display: none; }
        .itsr-drawer {
            position: fixed;
            inset: 0;
            z-index: 1500;
        }
        .itsr-drawer-backdrop {
            position: absolute;
            inset: 0;
            background: rgba(7, 17, 31, 0.56);
            backdrop-filter: blur(4px);
        }
        .itsr-drawer-panel {
            position: absolute;
            top: 0;
            right: 0;
            width: min(860px, 100vw);
            height: 100%;
            background: #f6f9fc;
            box-shadow: -24px 0 48px rgba(15, 23, 42, 0.28);
            display: flex;
            flex-direction: column;
        }
        .itsr-drawer-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            padding: 18px 22px;
            border-bottom: 1px solid #e2e8f0;
            background: rgba(255, 255, 255, 0.9);
        }
        .itsr-drawer-head h3 {
            font-size: 22px;
            color: #102949;
        }
        .itsr-drawer-close {
            min-height: 40px;
            min-width: 40px;
            border-radius: 12px;
            border: 1px solid #d4dde7;
            background: #fff;
            color: #204a74;
            font-size: 18px;
            cursor: pointer;
        }
        .itsr-drawer-frame {
            flex: 1;
            width: 100%;
            border: 0;
            background: #f6f9fc;
        }
        .notice, .empty, .error {
            margin: 18px 20px 20px;
            padding: 14px 16px;
            border-radius: 16px;
            font-size: 14px;
        }
        .notice { background: #edf8f1; border: 1px solid #c7e8d1; color: #1f7a3f; }
        .empty { background: #f8fbfd; border: 1px solid #dae3ec; color: #617387; }
        .error { background: #fff1f1; border: 1px solid #ebc7c7; color: #8a1f1f; }
        .toast {
            position: fixed;
            top: 50%;
            left: 50%;
            z-index: 1200;
            min-width: 320px;
            max-width: min(440px, calc(100vw - 32px));
            margin: 0;
            box-shadow: 0 20px 40px rgba(15, 23, 42, 0.16);
            transform: translate(-50%, -50%);
            animation: toastIn 0.28s ease;
        }
        .itsr-confirm-modal[hidden] { display: none; }
        .itsr-confirm-modal { position: fixed; inset: 0; z-index: 1300; display: flex; align-items: center; justify-content: center; }
        .itsr-confirm-backdrop { position: absolute; inset: 0; background: rgba(7, 17, 31, 0.56); backdrop-filter: blur(3px); }
        .itsr-confirm-dialog { position: relative; width: min(420px, calc(100vw - 32px)); padding: 22px; border-radius: 22px; background: rgba(255, 255, 255, 0.96); border: 1px solid rgba(209, 220, 232, 0.92); box-shadow: 0 26px 60px rgba(15, 23, 42, 0.18); }
        .itsr-confirm-dialog h3 { font-size: 24px; color: #0f2642; margin-bottom: 8px; }
        .itsr-confirm-dialog p { color: #64748b; line-height: 1.6; margin-bottom: 18px; }
        .itsr-confirm-actions { display: flex; gap: 10px; justify-content: flex-end; }
        @media (max-width: 1024px) {
            .layout { grid-template-columns: 1fr; }
            .content { padding: 18px; }
            .controls-grid { grid-template-columns: 1fr; }
            .hero-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }

        body.theme-dark {
            background: linear-gradient(180deg, #07111f 0%, #0b1729 100%);
            color: #e5eef9;
        }
        body.theme-dark .hero,
        body.theme-dark .table-card {
            background: rgba(12, 21, 36, 0.9);
            border-color: rgba(65, 85, 110, 0.72);
            box-shadow: 0 18px 38px rgba(0, 0, 0, 0.24);
        }
        body.theme-dark .hero h1,
        body.theme-dark .table-head h2 {
            color: #f4f8fd;
        }
        body.theme-dark .hero p,
        body.theme-dark .table-head p,
        body.theme-dark .muted,
        body.theme-dark .bulk-count {
            color: #9eb2c9;
        }
        body.theme-dark .hero-stat {
            background: rgba(15, 26, 45, 0.72);
            border-color: rgba(78, 97, 121, 0.55);
        }
        body.theme-dark .hero-stat strong,
        body.theme-dark .empty-card strong {
            color: #f4f8fd;
        }
        body.theme-dark .hero-stat span,
        body.theme-dark .empty-card span {
            color: #9eb2c9;
        }
        body.theme-dark .table-head {
            background: linear-gradient(180deg, #0f1a2d 0%, #0b1525 100%);
            border-bottom-color: rgba(70, 88, 112, 0.6);
        }
        body.theme-dark input,
        body.theme-dark select,
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
        body.theme-dark .custom-select-option {
            color: #eef4fb;
        }
        body.theme-dark .custom-select-option:hover,
        body.theme-dark .custom-select-option.is-selected {
            background: #1e3a6d;
            color: #ffffff;
        }
        body.theme-dark .custom-select-menu::-webkit-scrollbar-thumb {
            background: rgba(148, 163, 184, 0.45);
        }
        body.theme-dark .btn-light {
            background: rgba(255, 255, 255, 0.07);
            color: #eef4fb;
            border-color: rgba(148, 163, 184, 0.18);
        }
        body.theme-dark .bulk-count {
            background: #101c2f;
            border-color: rgba(78, 97, 121, 0.7);
        }
        body.theme-dark th {
            background: rgba(20, 31, 49, 0.96);
            color: #cfe0f4;
        }
        body.theme-dark td {
            border-bottom-color: rgba(57, 75, 99, 0.6);
            color: #e8eff8;
        }
        body.theme-dark tbody tr:hover {
            background: rgba(28, 44, 68, 0.64);
        }
        body.theme-dark .action-chip {
            background: rgba(255,255,255,0.04);
            border-color: rgba(148, 163, 184, 0.18);
            color: #eef4fb;
        }
        body.theme-dark .status-editor-label {
            color: #9eb2c9;
        }
        body.theme-dark .action-chip.is-primary {
            background: linear-gradient(180deg, #2563eb 0%, #1d4ed8 100%);
            color: #fff;
        }
        body.theme-dark .action-button {
            background: rgba(127, 29, 29, 0.2);
            border-color: rgba(248, 113, 113, 0.24);
            color: #fecaca;
        }
        body.theme-dark .itsr-drawer-panel {
            background: #081220;
        }
        body.theme-dark .itsr-drawer-head {
            background: rgba(12, 21, 36, 0.94);
            border-bottom-color: rgba(65, 85, 110, 0.72);
        }
        body.theme-dark .itsr-drawer-head h3 {
            color: #f4f8fd;
        }
        body.theme-dark .itsr-drawer-close {
            background: rgba(255, 255, 255, 0.04);
            border-color: rgba(148, 163, 184, 0.18);
            color: #eef4fb;
        }
        body.theme-dark .empty,
        body.theme-dark .error,
        body.theme-dark .notice {
            background: rgba(12, 21, 36, 0.9);
        }
        body.theme-dark .itsr-confirm-dialog { background: rgba(12, 21, 36, 0.96); border-color: rgba(65, 85, 110, 0.72); }
        body.theme-dark .itsr-confirm-dialog h3 { color: #f4f8fd; }
        body.theme-dark .itsr-confirm-dialog p { color: #9eb2c9; }
        @keyframes pageEnter {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        @keyframes toastIn {
            from { opacity: 0; transform: translate(-50%, calc(-50% - 10px)); }
            to { opacity: 1; transform: translate(-50%, -50%); }
        }
        @media (prefers-reduced-motion: reduce) {
            .content,
            .hero,
            .table-card,
            .btn,
            .hero-stat {
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
            <section class="hero">
                <div class="hero-top">
                    <div>
                        <div class="eyebrow"><?= htmlspecialchars(currentUserLabel(), ENT_QUOTES, 'UTF-8') ?> Requests</div>
                        <h1>All Service Requests</h1>
                        <p>Search, filter, review, assign, and clean up request records from one workspace.</p>
                    </div>
                    <div class="actions">
                        <a class="btn btn-light" href="dashboard.php">Back to Dashboard</a>
                        <a class="btn btn-primary" href="../form/request_form.php?return=admin">Open Customer Form</a>
                    </div>
                </div>

                <div class="controls">
                    <form method="get" class="controls-grid">
                        <input type="text" name="search" placeholder="Search requestor, email, company, location, or assignee" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>">
                        <div class="custom-select" data-select>
                            <input class="custom-select-input" type="hidden" name="status" value="<?= htmlspecialchars($statusFilter, ENT_QUOTES, 'UTF-8') ?>">
                            <button class="custom-select-toggle" type="button" data-select-toggle>
                                <?= htmlspecialchars($statusFilter === 'all' ? 'All Status' : requestStatusLabel($statusFilter), ENT_QUOTES, 'UTF-8') ?>
                            </button>
                            <div class="custom-select-menu" data-select-menu>
                                <button class="custom-select-option<?= $statusFilter === 'all' ? ' is-selected' : '' ?>" type="button" data-value="all">All Status</button>
                                <?php foreach ($statusOptions as $statusValue => $statusLabel): ?>
                                    <button class="custom-select-option<?= $statusFilter === $statusValue ? ' is-selected' : '' ?>" type="button" data-value="<?= htmlspecialchars($statusValue, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') ?></button>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="custom-select" data-select>
                            <input class="custom-select-input" type="hidden" name="read" value="<?= htmlspecialchars($readFilter, ENT_QUOTES, 'UTF-8') ?>">
                            <button class="custom-select-toggle" type="button" data-select-toggle>
                                <?php
                                echo match ($readFilter) {
                                    'unread' => 'Unread',
                                    'read' => 'Read',
                                    default => 'All Forms',
                                };
                                ?>
                            </button>
                            <div class="custom-select-menu" data-select-menu>
                                <button class="custom-select-option<?= $readFilter === 'all' ? ' is-selected' : '' ?>" type="button" data-value="all">All Forms</button>
                                <button class="custom-select-option<?= $readFilter === 'unread' ? ' is-selected' : '' ?>" type="button" data-value="unread">Unread</button>
                                <button class="custom-select-option<?= $readFilter === 'read' ? ' is-selected' : '' ?>" type="button" data-value="read">Read</button>
                            </div>
                        </div>
                        <div class="custom-select" data-select>
                            <input class="custom-select-input" type="hidden" name="assignment" value="<?= htmlspecialchars($assignmentFilter, ENT_QUOTES, 'UTF-8') ?>">
                            <button class="custom-select-toggle" type="button" data-select-toggle><?= $assignmentFilter === 'unassigned' ? 'Unassigned Only' : 'All Assignments' ?></button>
                            <div class="custom-select-menu" data-select-menu>
                                <button class="custom-select-option<?= $assignmentFilter === 'all' ? ' is-selected' : '' ?>" type="button" data-value="all">All Assignments</button>
                                <button class="custom-select-option<?= $assignmentFilter === 'unassigned' ? ' is-selected' : '' ?>" type="button" data-value="unassigned">Unassigned Only</button>
                            </div>
                        </div>
                        <button class="btn btn-primary" type="submit">Filter</button>
                    </form>
                </div>

                <div class="hero-stats">
                    <div class="hero-stat">
                        <strong><?= $summary['visible'] ?></strong>
                        <span>Visible requests in the current filtered view.</span>
                    </div>
                    <div class="hero-stat">
                        <strong><?= $summary['unread'] ?></strong>
                        <span>Unread requests still waiting for first review.</span>
                    </div>
                    <div class="hero-stat">
                        <strong><?= $summary['progress'] ?></strong>
                        <span>Requests currently marked in progress.</span>
                    </div>
                    <div class="hero-stat">
                        <strong><?= $summary['assigned'] ?></strong>
                        <span>Requests already assigned to a staff owner.</span>
                    </div>
                </div>
            </section>

            <section class="table-card">
                <form method="post" id="request-bulk-form" onsubmit="return confirmBulkDelete(event);">
                    <?= csrfField() ?>
                    <input type="hidden" name="delete_request" id="delete-request-input" value="">
                    <input type="hidden" name="update_request_status" id="update-request-status-flag" value="">
                    <input type="hidden" name="update_request_id" id="update-request-id" value="">
                    <input type="hidden" name="update_request_value" id="update-request-value" value="">
                    <div class="table-head">
                        <div>
                            <h2>Request Records</h2>
                            <p><?= count($requests) ?> result(s) found.</p>
                        </div>
                        <div class="table-head-actions">
                        <?php if ($isAdmin): ?>
                            <div class="bulk-actions">
                                <span class="bulk-count"><span id="selected-count">0</span> selected</span>
                                <button class="btn btn-danger" type="button" data-confirm-delete="bulk">Delete Selected</button>
                            </div>
                        <?php endif; ?>
                        </div>
                    </div>

                    <?php if ($deleted): ?>
                        <div class="notice toast" data-toast>Request deleted successfully.</div>
                    <?php endif; ?>
                    <?php if ($bulkDeleted): ?>
                        <div class="notice toast" data-toast>Selected requests deleted successfully.</div>
                    <?php endif; ?>
                    <?php if ($statusUpdated): ?>
                        <div class="notice toast" data-toast>Request status updated successfully.</div>
                    <?php endif; ?>

                    <?php if ($error !== ''): ?>
                        <div class="error toast" data-toast><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
                    <?php elseif (!$requests): ?>
                        <div class="empty empty-card">
                            <strong>No request records matched this filter</strong>
                            <span>Try adjusting the search, status, or read filter to bring matching records back into view.</span>
                        </div>
                    <?php else: ?>
                        <div class="table-wrap">
                            <table>
                                <thead>
                                    <tr>
                                        <?php if ($isAdmin): ?>
                                            <th class="select-col"><input class="table-check" type="checkbox" id="select-all"></th>
                                        <?php endif; ?>
                                        <th>ID</th>
                                        <th>Status</th>
                                        <th>Read</th>
                                        <th>Requestor</th>
                                        <th>Contact</th>
                                        <th>Company</th>
                                        <th>Request Date</th>
                                        <th>Assigned To</th>
                                        <th>Location</th>
                                        <th>Created</th>
                                        <th>PDF</th>
                                        <?php if ($isAdmin): ?>
                                            <th>Actions</th>
                                        <?php endif; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($requests as $request): ?>
                                        <?php $statusText = requestStatusLabel((string) ($request['status'] ?? 'pending')); $statusClass = requestStatusClass((string) ($request['status'] ?? 'pending')); ?>
                                        <tr>
                                            <?php if ($isAdmin): ?>
                                                <td class="select-col"><input class="table-check row-check" type="checkbox" name="selected_requests[]" value="<?= (int) $request['id'] ?>"></td>
                                            <?php endif; ?>
                                            <td>#<?= (int) $request['id'] ?></td>
                                            <td>
                                                <div class="status-editor">
                                                    <span class="pill <?= $statusClass ?>"><?= $statusText ?></span>
                                                    <div class="custom-select" data-select data-status-picker data-request-id="<?= (int) $request['id'] ?>">
                                                        <input class="custom-select-input" type="hidden" value="<?= htmlspecialchars((string) ($request['status'] ?? 'pending'), ENT_QUOTES, 'UTF-8') ?>">
                                                        <button class="custom-select-toggle" type="button" data-select-toggle><?= htmlspecialchars($statusText, ENT_QUOTES, 'UTF-8') ?></button>
                                                        <div class="custom-select-menu">
                                                            <?php foreach ($statusOptions as $statusValue => $statusLabel): ?>
                                                                <button class="custom-select-option<?= (($request['status'] ?? 'pending') === $statusValue) ? ' is-selected' : '' ?>" type="button" data-value="<?= htmlspecialchars($statusValue, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') ?></button>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td><span class="pill <?= ((int) ($request['is_read'] ?? 0) === 1) ? 'is-read' : 'is-unread' ?>"><?= ((int) ($request['is_read'] ?? 0) === 1) ? 'Read' : 'Unread' ?></span></td>
                                            <td>
                                                <strong><?= htmlspecialchars((string) $request['requestor_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                                <div class="muted"><?= htmlspecialchars((string) $request['department'], ENT_QUOTES, 'UTF-8') ?></div>
                                            </td>
                                            <td>
                                                <div><?= htmlspecialchars((string) $request['requestor_email'], ENT_QUOTES, 'UTF-8') ?></div>
                                                <div class="muted"><?= htmlspecialchars((string) $request['requestor_phone'], ENT_QUOTES, 'UTF-8') ?></div>
                                            </td>
                                            <td><?= htmlspecialchars((string) $request['company'], ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars((string) $request['request_date'], ENT_QUOTES, 'UTF-8') ?></td>
                                            <td>
                                                <div><?= htmlspecialchars((string) $request['assign_to'], ENT_QUOTES, 'UTF-8') ?: '-' ?></div>
                                                <div class="muted"><?= htmlspecialchars((string) $request['date_receive'], ENT_QUOTES, 'UTF-8') ?: 'Not received' ?></div>
                                            </td>
                                            <td><?= htmlspecialchars((string) $request['location'], ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars((string) $request['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= workflowGetPdfLinkHtml((string) $request['pdf_path'], 'pdf-link', 'Open PDF', '../') ?></td>
                                            <?php if ($isAdmin): ?>
                                                <td>
                                                    <div class="action-stack">
                                                        <a class="action-chip is-primary" href="request_edit.php?id=<?= (int) $request['id'] ?>">View / Edit</a>
                                                        <?= workflowGetPdfLinkHtml((string) $request['pdf_path'], 'action-chip', 'PDF', '../') ?>
                                                        <button class="action-button" type="button" data-confirm-delete="single" data-request-id="<?= (int) $request['id'] ?>">Delete</button>
                                                    </div>
                                                </td>
                                            <?php endif; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </form>
            </section>
        </main>
    </div>
    <div class="itsr-confirm-modal" id="confirm-modal" hidden>
        <div class="itsr-confirm-backdrop" data-confirm-close></div>
        <div class="itsr-confirm-dialog">
            <h3 id="confirm-title">Delete Request?</h3>
            <p id="confirm-copy">This will permanently remove the selected request.</p>
            <div class="itsr-confirm-actions">
                <button class="btn btn-light" type="button" data-confirm-close>Cancel</button>
                <button class="btn btn-danger" type="button" id="confirm-delete-action">Delete</button>
            </div>
        </div>
    </div>
    <script>
        document.querySelectorAll('[data-select]').forEach(function (select) {
            const hiddenInput = select.querySelector('.custom-select-input');
            const toggle = select.querySelector('[data-select-toggle]');
            const options = Array.from(select.querySelectorAll('.custom-select-option'));

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
                        var nextValue = option.getAttribute('data-value') || '';
                        hiddenInput.value = nextValue;
                        toggle.textContent = option.textContent || '';
                        options.forEach(function (item) {
                            item.classList.remove('is-selected');
                        });
                        option.classList.add('is-selected');
                        select.classList.remove('is-open');

                        if (select.hasAttribute('data-status-picker') && bulkForm && updateRequestStatusFlag && updateRequestId && updateRequestValue) {
                            deleteRequestInput.value = '';
                            updateRequestStatusFlag.value = '1';
                            updateRequestId.value = select.getAttribute('data-request-id') || '';
                            updateRequestValue.value = nextValue;
                            bulkForm.submit();
                        }
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
    </script>
    <?php if ($isAdmin): ?>
    <script>
        const selectAll = document.getElementById('select-all');
        const rowChecks = Array.from(document.querySelectorAll('.row-check'));
        const selectedCount = document.getElementById('selected-count');

        function updateSelectedCount() {
            const count = rowChecks.filter((checkbox) => checkbox.checked).length;
            if (selectedCount) {
                selectedCount.textContent = String(count);
            }

            if (selectAll) {
                selectAll.checked = rowChecks.length > 0 && count === rowChecks.length;
                selectAll.indeterminate = count > 0 && count < rowChecks.length;
            }
        }

        if (selectAll) {
            selectAll.addEventListener('change', () => {
                rowChecks.forEach((checkbox) => {
                    checkbox.checked = selectAll.checked;
                });
                updateSelectedCount();
            });
        }

        rowChecks.forEach((checkbox) => {
            checkbox.addEventListener('change', updateSelectedCount);
        });

        var modal = document.getElementById('confirm-modal');
        var confirmButton = document.getElementById('confirm-delete-action');
        var confirmTitle = document.getElementById('confirm-title');
        var confirmCopy = document.getElementById('confirm-copy');
        var deleteRequestInput = document.getElementById('delete-request-input');
        var updateRequestStatusFlag = document.getElementById('update-request-status-flag');
        var updateRequestId = document.getElementById('update-request-id');
        var updateRequestValue = document.getElementById('update-request-value');
        var bulkForm = document.getElementById('request-bulk-form');
        var pendingAction = null;

        document.querySelectorAll('[data-toast]').forEach(function (toast) {
            window.setTimeout(function () {
                toast.style.opacity = '0';
                toast.style.transform = 'translate(-50%, calc(-50% - 10px))';
                window.setTimeout(function () { toast.remove(); }, 220);
            }, 2800);
        });

        function openConfirm(action) {
            pendingAction = action;
            if (confirmTitle) {
                confirmTitle.textContent = action === 'bulk' ? 'Delete Selected Requests?' : 'Delete Request?';
            }
            if (confirmCopy) {
                confirmCopy.textContent = action === 'bulk' ? 'This will permanently remove the selected requests from the system.' : 'This will permanently remove the selected request from the system.';
            }
            if (modal) {
                modal.hidden = false;
            }
        }

        function closeConfirm() {
            pendingAction = null;
            if (modal) {
                modal.hidden = true;
            }
        }

        document.querySelectorAll('[data-confirm-close]').forEach(function (control) {
            control.addEventListener('click', closeConfirm);
        });

        document.querySelectorAll('[data-confirm-delete="single"]').forEach(function (button) {
            button.addEventListener('click', function () {
                if (deleteRequestInput) {
                    deleteRequestInput.value = button.getAttribute('data-request-id') || '';
                }
                openConfirm('single');
            });
        });

        var bulkDeleteButton = document.querySelector('[data-confirm-delete="bulk"]');
        if (bulkDeleteButton) {
            bulkDeleteButton.addEventListener('click', function () {
                const count = rowChecks.filter((checkbox) => checkbox.checked).length;
                if (count === 0) {
                    alert('Please select at least one request first.');
                    return;
                }
                if (deleteRequestInput) {
                    deleteRequestInput.value = '';
                }
                openConfirm('bulk');
            });
        }

        if (confirmButton) {
            confirmButton.addEventListener('click', function () {
                if (!bulkForm) {
                    closeConfirm();
                    return;
                }
                if (updateRequestStatusFlag) {
                    updateRequestStatusFlag.value = '';
                }
                if (updateRequestId) {
                    updateRequestId.value = '';
                }
                if (updateRequestValue) {
                    updateRequestValue.value = '';
                }
                if (pendingAction === 'bulk') {
                    var hiddenDeleteSelected = document.createElement('input');
                    hiddenDeleteSelected.type = 'hidden';
                    hiddenDeleteSelected.name = 'delete_selected';
                    hiddenDeleteSelected.value = '1';
                    bulkForm.appendChild(hiddenDeleteSelected);
                }
                bulkForm.submit();
            });
        }

        function confirmBulkDelete(event) {
            const submitter = event.submitter;

            if (!submitter || submitter.name !== 'delete_selected') {
                return true;
            }

            const count = rowChecks.filter((checkbox) => checkbox.checked).length;

            if (count === 0) {
                alert('Please select at least one request first.');
                return false;
            }

            return confirm('Delete ' + count + ' selected request(s)?');
        }

        updateSelectedCount();
    </script>
    <?php endif; ?>
</body>
</html>
