<?php
// request_form.php

require_once dirname(__DIR__) . '/login/auth.php';
require_once dirname(__DIR__) . '/includes/workflow.php';

$dateValue = date('Y-m-d');
$serviceTypes = [
    'Hardware',
    'Software',
    'AMROS',
    'SOLO',
    'Internet',
    'Email',
    'Training',
    'GP/AIS/FIS',
    'Others'
];
$companies = ['Company A', 'Company B', 'Company C', 'Company D', 'Company E', 'Company F', 'Company G'];
$departments = [
    '120 Finance',
    '131 Quality Assurance',
    '150 (MIS) (Admin1)',
    '150 (MIS) (Admin2)',
    '210 Human Resource',
    '233 Administration (1)',
    '233 Administration (2)',
    '311 Contract & Business Dev - Gom 1 (Kontrak)',
    '311 Contract & Business Dev - Gom 2 (Bomba)',
    '330 International & Commercial Program',
    '500 Operation Division',
    '510 Resource Planning (MIS)',
    '530 Purchasing And Inventory (SCS)',
    '611 Fixed Wing Subang',
    '617 Fixed Wing Labuan',
    '632 Fixed Wing VVIP (Subang Air Base)',
    '642 Mi-17 And Mi-171',
    '654 PC 7 MK II',
    '713 CAMO',
    'ADMIN',
    'ATP',
    'COO Company A',
    'NADI',
    'Security',
];

$returnContext = strtolower(trim((string) ($_GET['return'] ?? '')));
if ($returnContext === '') {
    $referrer = str_replace('\\', '/', strtolower((string) ($_SERVER['HTTP_REFERER'] ?? '')));
    if (str_contains($referrer, '/admin/')) {
        $returnContext = 'admin';
    } elseif (str_contains($referrer, '/staff/')) {
        $returnContext = 'staff';
    } else {
        $returnContext = 'department';
    }
}
if (!in_array($returnContext, ['admin', 'staff', 'department'], true)) {
    $returnContext = 'department';
}

$returnUrl = match ($returnContext) {
    'admin' => '../admin/dashboard.php',
    'staff' => '../staff/dashboard.php',
    'department' => (currentUserRole() === 'department_user') ? '../department/dashboard.php' : '../login/login.php?portal=department',
    default => (currentUserRole() === 'department_user') ? '../department/dashboard.php' : '../login/login.php?portal=department',
};

$returnLabel = match ($returnContext) {
    'admin' => 'Back to Admin',
    'staff' => 'Back to Staff',
    'department' => (currentUserRole() === 'department_user') ? 'Back to Department' : 'Back To Log In',
    default => (currentUserRole() === 'department_user') ? 'Back to Department' : 'Back To Log In',
};

// Check if loading a returned form for editing
$requestId = (int) ($_GET['id'] ?? 0);
$requestData = null;
$selectedServiceTypes = [];
if ($requestId > 0) {
    try {
        $pdo = db();
        ensureItsrWorkflowSchema();
        
        $deptFilter = '';
        $params = [':id' => $requestId];
        if (currentUserRole() === 'department_user') {
            $deptFilter = 'AND department = :department';
            $params[':department'] = currentUserDepartment();
        }
        
        $stmt = $pdo->prepare("SELECT * FROM service_requests WHERE id = :id AND status = 'returned' $deptFilter LIMIT 1");
        $stmt->execute($params);
        $requestData = $stmt->fetch();
        
        if ($requestData) {
            $dateValue = $requestData['request_date'] ?? date('Y-m-d');
            if (trim((string) ($requestData['service_types'] ?? '')) !== '') {
                $selectedServiceTypes = json_decode($requestData['service_types'], true) ?: [];
            }
        }
    } catch (Throwable $e) {
        // fail silently
    }
}

$selectedCompany = (string) ($requestData['company'] ?? '');
$selectedDepartment = (string) ($requestData['department'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ITSR - IT Service Request Form</title>
    <link rel="stylesheet" href="../assets/form-ui.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Nunito+Sans:wght@400;500;600;700;800&display=swap');

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: "Nunito Sans", "Segoe UI", Arial, sans-serif;
            letter-spacing: 0;
        }

        body {
            background:
                linear-gradient(rgba(237, 243, 248, 0.82), rgba(226, 235, 243, 0.86)),
                url('assets/cloud.jpg') center/cover fixed no-repeat;
            padding: 32px 18px;
            color: #111;
            -webkit-font-smoothing: antialiased;
            text-rendering: optimizeLegibility;
        }

        .shell {
            max-width: 1080px;
            margin: 0 auto;
        }

        .intro {
            position: relative;
            margin-bottom: 18px;
            text-align: center;
        }

        .intro-actions {
            display: flex;
            justify-content: flex-start;
            margin-bottom: 14px;
        }

        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 9px;
            min-height: 42px;
            padding: 0 16px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.82);
            border: 1px solid rgba(186, 204, 222, 0.9);
            color: #17466f;
            font-size: 13px;
            font-weight: 800;
            text-decoration: none;
            box-shadow: 0 10px 24px rgba(28, 46, 67, 0.08);
            transition: transform 0.2s ease, box-shadow 0.2s ease, background 0.2s ease;
        }

        .back-link:hover {
            transform: translateY(-1px);
            background: #fff;
            box-shadow: 0 14px 28px rgba(28, 46, 67, 0.12);
        }

        .back-link svg {
            flex: 0 0 auto;
        }

        .eyebrow {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.75);
            border: 1px solid rgba(17, 17, 17, 0.08);
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            color: #33506f;
            margin-bottom: 10px;
        }

        .intro h1 {
            font-size: 34px;
            margin-bottom: 8px;
            letter-spacing: 0;
        }

        .intro p {
            max-width: 760px;
            margin: 0 auto;
            color: #4e6177;
            line-height: 1.6;
            font-size: 15px;
        }

        .page {
            max-width: 980px;
            margin: 0 auto;
            background: rgba(255, 255, 255, 0.94);
            border: 1px solid rgba(214, 221, 229, 0.92);
            border-radius: 18px;
            padding: 24px;
            box-shadow: 0 18px 48px rgba(28, 46, 67, 0.12);
            backdrop-filter: blur(8px);
        }

        .topbar {
            display: flex;
            align-items: center;
            gap: 16px;
            border-bottom: 2px solid #1f3248;
            padding-bottom: 14px;
            margin-bottom: 12px;
        }

        .logo-box {
            width: 150px;
            height: 72px;
            border: 1px solid #1f3248;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(180deg, #f9fbfd 0%, #eef4f8 100%);
            overflow: hidden;
            padding: 8px 12px;
        }

        .logo-box img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }

        .title-area {
            flex: 1;
        }

        .main-title {
            text-align: center;
            font-size: 36px;
            font-weight: 700;
            letter-spacing: 0;
            color: #14263b;
        }

        .subtitle {
            font-size: 22px;
            font-weight: 700;
            margin: 14px 0 18px;
            text-transform: uppercase;
            color: #1f3248;
        }

        .section-box {
            border: 1px solid #ccd6df;
            border-radius: 14px;
            margin-bottom: 20px;
            overflow: visible;
        }

        .section-box::after {
            content: "";
            display: block;
            clear: both;
        }

        .section-title {
            border-bottom: 1px solid #ccd6df;
            padding: 11px 14px;
            font-weight: 700;
            text-transform: uppercase;
            background: linear-gradient(180deg, #f8fafc 0%, #eef3f7 100%);
            color: #1f3248;
            letter-spacing: 0.02em;
        }

        table.form-table {
            width: 100%;
            border-collapse: collapse;
        }

        .form-table th,
        .form-table td {
            border: 1px solid #d1dae3;
            padding: 10px 12px;
            vertical-align: middle;
            font-size: 14px;
        }

        .form-table tr {
            position: relative;
        }

        .form-table tr:has(.modern-select.is-open) {
            z-index: 90;
        }

        .form-table td:has(.modern-select.is-open) {
            position: relative;
            z-index: 95;
        }

        .form-table th {
            text-align: left;
            font-weight: 700;
            background: #f8fafc;
            color: #1f3248;
        }

        .label-col {
            width: 170px;
            font-weight: 700;
            background: #fbfdff;
            color: #1f3248;
        }

        input[type="text"],
        input[type="email"],
        input[type="date"],
        textarea,
        select {
            width: 100%;
            border: none;
            outline: none;
            font-size: 14px;
            background: transparent;
            color: #18293c;
        }

        .modern-select {
            position: relative;
            z-index: 12;
        }

        .modern-select.is-open {
            z-index: 120;
        }

        .modern-select-trigger {
            width: 100%;
            min-height: 40px;
            border: 1px solid #c8d7e6;
            border-radius: 13px;
            background: linear-gradient(180deg, #ffffff 0%, #f6faff 100%);
            color: #1f3248;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 0 13px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            box-shadow: 0 8px 18px rgba(31, 64, 100, 0.06);
            transition: border-color 0.18s ease, box-shadow 0.18s ease, transform 0.18s ease;
        }

        .modern-select-trigger:hover,
        .modern-select.is-open .modern-select-trigger {
            border-color: #3b82d6;
            box-shadow: 0 0 0 4px rgba(59, 130, 214, 0.12), 0 12px 22px rgba(31, 64, 100, 0.08);
        }

        .modern-select.is-invalid .modern-select-trigger {
            border-color: #dc2626;
            box-shadow: 0 0 0 4px rgba(220, 38, 38, 0.12);
        }

        .modern-select-trigger svg {
            width: 18px;
            height: 18px;
            flex: 0 0 18px;
            color: #41627f;
            transition: transform 0.18s ease;
        }

        .modern-select.is-open .modern-select-trigger svg {
            transform: rotate(180deg);
        }

        .modern-select-menu {
            position: fixed;
            left: var(--select-left, 0);
            top: var(--select-top, 0);
            width: var(--select-width, 240px);
            z-index: 9999;
            display: none;
            max-height: 214px;
            overflow-y: auto;
            list-style: none;
            padding: 8px;
            border-radius: 16px;
            background: rgba(255, 255, 255, 0.98);
            border: 1px solid #bfd1e4;
            box-shadow: 0 22px 42px rgba(25, 48, 73, 0.18);
            backdrop-filter: blur(10px);
            scrollbar-width: thin;
            scrollbar-color: rgba(93, 118, 145, 0.7) transparent;
        }

        .modern-select-menu::-webkit-scrollbar {
            width: 9px;
        }

        .modern-select-menu::-webkit-scrollbar-track {
            background: transparent;
        }

        .modern-select-menu::-webkit-scrollbar-thumb {
            background: rgba(93, 118, 145, 0.58);
            border-radius: 999px;
            border: 2px solid rgba(255, 255, 255, 0.9);
        }

        .modern-select.is-open .modern-select-menu {
            display: grid;
            gap: 4px;
        }

        .modern-select-menu.is-floating-open {
            display: grid;
            gap: 4px;
        }

        .modern-select-option {
            width: 100%;
            border: 0;
            border-radius: 11px;
            background: transparent;
            color: #1e3348;
            padding: 10px 12px;
            text-align: left;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            transition: background 0.16s ease, color 0.16s ease, transform 0.16s ease;
        }

        .modern-select-option:hover,
        .modern-select-option.is-selected {
            background: #e8f2ff;
            color: #0f5dbd;
        }

        .modern-select-option:active {
            transform: scale(0.99);
        }

        .admin-only input,
        .admin-only textarea,
        .admin-only .signature-input {
            pointer-events: none;
        }

        .admin-only input,
        .admin-only textarea {
            background: #f4f7fa;
            color: #6c7b8a;
        }

        .admin-note {
            padding: 10px 14px 0;
            font-size: 12px;
            color: #5f7084;
        }

        input[type="file"] {
            display: none;
        }

        .signature-cell {
            padding: 4px;
        }

        .electronic-signature {
            display: grid;
            gap: 6px;
        }

        .signature-input {
            width: 100%;
            min-height: 36px;
            border: 1px solid #c8d7e6;
            border-radius: 12px;
            background: linear-gradient(180deg, #ffffff 0%, #f6faff 100%);
            padding: 8px 10px;
            font-size: 14px;
            color: #18293c;
            text-align: left;
        }

        .signature-input::placeholder {
            color: #7c8b9b;
            font-size: 13px;
        }

        .signature-preview {
            min-height: 32px;
            border-bottom: 1px solid #9fb2c7;
            color: #152b45;
            font-family: "Great Vibes", "Allura", "Dancing Script", "Segoe Script", "Brush Script MT", cursive;
            font-size: 24px;
            line-height: 1.15;
            text-align: center;
            word-break: break-word;
        }

        .signature-preview.is-empty {
            color: #8a98a8;
            font-size: 18px;
        }

        .signature-confirmation {
            color: #5f7084;
            font-size: 10.5px;
            line-height: 1.3;
        }

        .dropzone {
            min-height: 68px;
            border: 1px dashed #7f96af;
            border-radius: 12px;
            background: linear-gradient(180deg, #fbfdff 0%, #f2f7fb 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            padding: 10px;
            cursor: pointer;
            transition: background 0.2s ease, border-color 0.2s ease, transform 0.2s ease;
        }

        .dropzone:hover,
        .dropzone.is-dragover {
            background: #ebf4ff;
            border-color: #1f5fbf;
            transform: translateY(-1px);
        }

        .dropzone-text {
            display: flex;
            flex-direction: column;
            gap: 4px;
            font-size: 12px;
            color: #333;
            line-height: 1.3;
        }

        .dropzone-text strong {
            font-size: 13px;
        }

        .file-name {
            color: #1f5fbf;
            font-weight: 700;
            word-break: break-word;
        }

        .service-wrap {
            padding: 14px;
            border-top: 1px solid #d1dae3;
            border-bottom: 1px solid #d1dae3;
            background: #fbfdff;
        }

        .service-title {
            font-weight: 700;
            margin-bottom: 10px;
            color: #1f3248;
        }

        .service-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 10px 16px;
        }

        .service-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            font-size: 14px;
            padding: 8px 10px;
            border: 1px solid #d9e1e9;
            border-radius: 10px;
            background: #fff;
        }

        .other-note {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-top: 10px;
            font-size: 13px;
        }

        .other-note input {
            border-bottom: 1px solid #8ba0b4;
            padding: 6px 0;
        }

        .note-box,
        .desc-head {
            padding: 12px 14px;
            border-bottom: 1px solid #d1dae3;
        }

        .note-box p,
        .note-box li,
        .desc-head {
            font-size: 13px;
            line-height: 1.5;
        }

        .note-box strong,
        .desc-head strong {
            font-size: 14px;
        }

        .note-box ol {
            padding-left: 18px;
            margin-top: 4px;
        }

        .lined-area {
            padding: 0 14px 14px;
        }

        .lined-area textarea {
            min-height: 320px;
            line-height: 30px;
            padding: 8px 0;
            background-image: linear-gradient(to bottom, transparent 29px, #cad5e0 30px);
            background-size: 100% 30px;
            resize: vertical;
        }

        .lined-area.small textarea {
            min-height: 180px;
        }

        .attachment-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px;
            padding: 0 14px 16px;
        }

        .attachment-card {
            border: 1px solid #d9e1e9;
            border-radius: 14px;
            background: linear-gradient(180deg, #fbfdff 0%, #f5f9fd 100%);
            padding: 12px;
        }

        .attachment-label {
            display: block;
            margin-bottom: 8px;
            color: #1f3248;
            font-size: 13px;
            font-weight: 700;
        }

        .attachment-help {
            margin-top: 8px;
            color: #687b91;
            font-size: 12px;
            line-height: 1.45;
        }

        .footer-code {
            display: flex;
            justify-content: space-between;
            font-size: 12px;
            color: #5f7084;
            margin-top: 8px;
            padding: 0 4px;
        }

        .submit-bar {
            display: flex;
            justify-content: flex-end;
            margin-top: 18px;
        }

        .btn {
            background: linear-gradient(180deg, #1f5fbf 0%, #184d9c 100%);
            color: #fff;
            border: none;
            padding: 13px 24px;
            border-radius: 12px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 700;
            box-shadow: 0 10px 20px rgba(31, 95, 191, 0.2);
        }

        .btn:hover {
            background: linear-gradient(180deg, #246bd5 0%, #1c58b1 100%);
        }

        .submit-note {
            margin-top: 10px;
            text-align: right;
            font-size: 12px;
            color: #5f7084;
        }

        @media (max-width: 900px) {
            .intro h1 {
                font-size: 28px;
            }

            .main-title {
                font-size: 26px;
            }

            .subtitle {
                font-size: 18px;
            }

            .service-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .attachment-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 640px) {
            body {
                padding: 14px 10px;
            }

            .page {
                padding: 12px;
                border-radius: 14px;
            }

            .topbar {
                align-items: flex-start;
            }

            .logo-box {
                width: 120px;
                height: 58px;
                padding: 6px 10px;
            }

            .main-title {
                font-size: 20px;
            }

            .intro p {
                font-size: 14px;
            }

            .service-grid {
                grid-template-columns: 1fr;
            }

            .form-table th,
            .form-table td {
                font-size: 12px;
                padding: 6px;
            }
        }
    </style>
</head>
<body>
    <div class="shell">
        <div class="intro">
            <div class="intro-actions">
                <a class="back-link" href="<?= htmlspecialchars($returnUrl, ENT_QUOTES, 'UTF-8') ?>">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <path d="M15 18 9 12l6-6" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/>
                        <path d="M10 12h10" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/>
                    </svg>
                    <?= htmlspecialchars($returnLabel, ENT_QUOTES, 'UTF-8') ?>
                </a>
            </div>
            <div class="eyebrow">Customer Form</div>
            <h1>Submit Your IT Service Request</h1>
            <p>Fill in the details below and submit the request form. A PDF copy will be generated after submission for download and record keeping.</p>
        </div>

        <form class="page" action="submit_request.php" method="POST" enctype="multipart/form-data">
            <input type="hidden" name="return_context" value="<?= htmlspecialchars($returnContext, ENT_QUOTES, 'UTF-8') ?>">
            <?= csrfField() ?>
            <?php if ($requestData): ?>
                <input type="hidden" name="request_id" value="<?= (int) $requestData['id'] ?>">
            <?php endif; ?>
            <div class="topbar">
                <div class="logo-box">
                    <img src="assets/logo.png" alt="Logo">
                </div>
                <div class="title-area">
                    <div class="main-title">IT SERVICE REQUEST FORM</div>
                </div>
            </div>

            <div class="subtitle">Management Information System Department</div>

            <div class="section-box">
                <div class="section-title">Section 1. To Be Completed By Requestor</div>

                <table class="form-table">
                    <tr>
                        <td class="label-col">Company</td>
                        <td>
                            <div class="modern-select" data-modern-select data-required-select>
                                <input type="hidden" name="company" data-modern-select-value value="<?= htmlspecialchars($selectedCompany, ENT_QUOTES, 'UTF-8') ?>">
                                <button class="modern-select-trigger" type="button" data-modern-select-trigger aria-haspopup="listbox" aria-expanded="false">
                                    <span data-modern-select-label><?= htmlspecialchars($selectedCompany !== '' ? $selectedCompany : 'Select company', ENT_QUOTES, 'UTF-8') ?></span>
                                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                </button>
                                <ul class="modern-select-menu" role="listbox">
                                    <?php foreach ($companies as $company): ?>
                                        <li>
                                            <button class="modern-select-option" type="button" data-modern-select-option value="<?= htmlspecialchars($company, ENT_QUOTES, 'UTF-8') ?>">
                                                <?= htmlspecialchars($company, ENT_QUOTES, 'UTF-8') ?>
                                            </button>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <td class="label-col">Department</td>
                        <td>
                            <div class="modern-select" data-modern-select data-required-select>
                                <input type="hidden" name="department" data-modern-select-value value="<?= htmlspecialchars($selectedDepartment, ENT_QUOTES, 'UTF-8') ?>">
                                <button class="modern-select-trigger" type="button" data-modern-select-trigger aria-haspopup="listbox" aria-expanded="false">
                                    <span data-modern-select-label><?= htmlspecialchars($selectedDepartment !== '' ? $selectedDepartment : 'Select department', ENT_QUOTES, 'UTF-8') ?></span>
                                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                </button>
                                <ul class="modern-select-menu" role="listbox">
                                    <?php foreach ($departments as $departmentItem): ?>
                                        <li>
                                            <button class="modern-select-option" type="button" data-modern-select-option value="<?= htmlspecialchars($departmentItem, ENT_QUOTES, 'UTF-8') ?>">
                                                <?= htmlspecialchars($departmentItem, ENT_QUOTES, 'UTF-8') ?>
                                            </button>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <td class="label-col">Email</td>
                        <td><input type="email" name="requestor_email" required placeholder="name@example.com" value="<?= htmlspecialchars((string) ($requestData['requestor_email'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></td>
                    </tr>
                    <tr>
                        <td class="label-col">Date</td>
                        <td><input type="date" name="request_date" value="<?= htmlspecialchars($dateValue) ?>" readonly aria-readonly="true"></td>
                    </tr>
                </table>

                <table class="form-table">
                    <tr>
                        <th style="width:170px;"></th>
                        <th>Name</th>
                        <th style="width:180px;">Signature</th>
                        <th style="width:180px;">Phone (HP/Ext.)</th>
                    </tr>
                    <tr>
                        <td class="label-col">Requestor</td>
                        <td><input type="text" name="requestor_name" value="<?= htmlspecialchars((string) ($requestData['requestor_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></td>
                        <td class="signature-cell">
                            <div class="electronic-signature" data-signature-field>
                                <input class="signature-input" type="text" name="requestor_signature_name" placeholder="Type signature" maxlength="100" data-signature-input value="<?= htmlspecialchars((string) ($requestData['requestor_signature_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                <div class="signature-preview <?= trim((string) ($requestData['requestor_signature_name'] ?? '')) !== '' ? '' : 'is-empty' ?>" data-signature-preview><?= htmlspecialchars(($requestData['requestor_signature_name'] ?? '') !== '' ? $requestData['requestor_signature_name'] : 'Typed Signature', ENT_QUOTES, 'UTF-8') ?></div>
                                <div class="signature-confirmation">By typing my name, I confirm that the information provided is correct.</div>
                            </div>
                        </td>
                        <td><input type="text" name="requestor_phone" value="<?= htmlspecialchars((string) ($requestData['requestor_phone'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></td>
                    </tr>
                    <tr>
                        <td class="label-col">Approved by</td>
                        <td><input type="text" name="approved_by_name" value="<?= htmlspecialchars((string) ($requestData['approved_by_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></td>
                        <td class="signature-cell">
                            <div class="electronic-signature" data-signature-field>
                                <input class="signature-input" type="text" name="approver_signature_name" placeholder="Type signature" maxlength="100" data-signature-input value="<?= htmlspecialchars((string) ($requestData['approver_signature_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                <div class="signature-preview <?= trim((string) ($requestData['approver_signature_name'] ?? '')) !== '' ? '' : 'is-empty' ?>" data-signature-preview><?= htmlspecialchars(($requestData['approver_signature_name'] ?? '') !== '' ? $requestData['approver_signature_name'] : 'Typed Signature', ENT_QUOTES, 'UTF-8') ?></div>
                                <div class="signature-confirmation">By typing my name, I confirm that the information provided is correct.</div>
                            </div>
                        </td>
                        <td><input type="text" name="approved_by_phone" value="<?= htmlspecialchars((string) ($requestData['approved_by_phone'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></td>
                    </tr>
                </table>

                <div class="service-wrap">
                    <div class="service-title">Type of Service Request (tick ( / ) which applicable)</div>
                    <div class="service-grid">
                        <?php foreach ($serviceTypes as $type): ?>
                            <label class="service-item">
                                <span><?= htmlspecialchars($type) ?></span>
                                <input type="checkbox" name="service_types[]" value="<?= htmlspecialchars($type) ?>" <?= in_array($type, $selectedServiceTypes, true) ? 'checked' : '' ?>>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <div class="other-note">
                        <span>Please specify</span>
                        <input type="text" name="other_service_specify" value="<?= htmlspecialchars((string) ($requestData['other_service_specify'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                </div>

                <table class="form-table">
                    <tr>
                        <td class="label-col">Location</td>
                        <td class="label-col">IP Address/Tag No.</td>
                    </tr>
                    <tr>
                        <td><input type="text" name="location" value="<?= htmlspecialchars((string) ($requestData['location'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></td>
                        <td><input type="text" name="ip_tag_no" value="<?= htmlspecialchars((string) ($requestData['ip_tag_no'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></td>
                    </tr>
                </table>

                <div class="note-box">
                    <strong>Note:</strong>
                    <ol>
                        <li>New request of email and AMROS ID, please state the full name of the user, and approved by the HOD or related approval personnel.</li>
                        <li>Please specify the hardware location.</li>
                        <li>Any problem relates with PC, please provide the IP Address or Tag No.</li>
                    </ol>
                </div>

                <div class="desc-head">
                    <strong>Specify any Special Qualifications for this Service Required / Description of Problem &amp; Justification of Service</strong>
                    (use attachment if necessary)
                </div>

                <div class="lined-area">
                    <textarea name="problem_description"><?= htmlspecialchars((string) ($requestData['problem_description'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
                </div>

                <div class="attachment-grid">
                    <div class="attachment-card">
                        <span class="attachment-label">QA approval document</span>
                        <label class="dropzone" data-dropzone>
                            <input type="file" name="qa_approval_document" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx" data-file-input>
                            <span class="dropzone-text">
                                <strong>Drop QA approval file</strong>
                                <span>PDF, image, Word, or Excel</span>
                                <span class="file-name" data-file-name>No file selected</span>
                            </span>
                        </label>
                        <div class="attachment-help">Max 8MB. Unsafe script files are blocked.</div>
                    </div>
                    <div class="attachment-card">
                        <span class="attachment-label">Supporting document / attachment</span>
                        <label class="dropzone" data-dropzone>
                            <input type="file" name="supporting_document" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx" data-file-input>
                            <span class="dropzone-text">
                                <strong>Drop supporting file</strong>
                                <span>PDF, image, Word, or Excel</span>
                                <span class="file-name" data-file-name>No file selected</span>
                            </span>
                        </label>
                        <div class="attachment-help">Use this for screenshots, approvals, or extra details.</div>
                    </div>
                </div>
            </div>

            <div class="section-box admin-only">
                <div class="section-title">Section 2. To Be Completed By Management Information System Department</div>
                <div class="admin-note">This section is reserved for admin/staff completion only.</div>

                <table class="form-table">
                    <tr>
                        <th>Assign To</th>
                        <th>Date Receive</th>
                        <th>Date Complete</th>
                        <th>Total Hour Taken</th>
                        <th>Signature</th>
                    </tr>
                    <tr>
                        <td><input type="text" name="assign_to" readonly></td>
                        <td><input type="date" name="date_receive" readonly></td>
                        <td><input type="date" name="date_complete" readonly></td>
                        <td><input type="text" name="total_hour_taken" readonly></td>
                        <td class="signature-cell">
                            <input class="signature-input" type="text" placeholder="Admin use only" readonly>
                        </td>
                    </tr>
                </table>

                <div class="desc-head"><strong>Corrective Action / Solution</strong></div>
                <div class="lined-area small">
                    <textarea name="corrective_action" readonly></textarea>
                </div>
            </div>

            <div class="footer-code">
                <span>MIS/03-01/SRF</span>
                <span>Rev 6 25/05/2025</span>
            </div>

            <div class="submit-bar">
                <button type="submit" class="btn">Submit</button>
            </div>
            <div class="submit-note">After submitting, the customer can download the PDF on the next page.</div>
        </form>
    </div>
    <script>
        document.querySelectorAll('[data-modern-select]').forEach(function (select) {
            var trigger = select.querySelector('[data-modern-select-trigger]');
            var label = select.querySelector('[data-modern-select-label]');
            var valueInput = select.querySelector('[data-modern-select-value]');
            var menu = select.querySelector('.modern-select-menu');
            var originalMenuParent = menu ? menu.parentNode : null;
            var options = Array.prototype.slice.call(select.querySelectorAll('[data-modern-select-option]'));

            function positionMenu() {
                if (!menu || !trigger || !select.classList.contains('is-open')) {
                    return;
                }

                var rect = trigger.getBoundingClientRect();
                var viewportGap = 14;
                var menuGap = 8;
                var belowSpace = window.innerHeight - rect.bottom - viewportGap;
                var aboveSpace = rect.top - viewportGap;
                var desiredHeight = Math.min(260, Math.max(150, belowSpace));
                var openAbove = belowSpace < 170 && aboveSpace > belowSpace;
                var maxHeight = openAbove ? Math.min(260, Math.max(150, aboveSpace - menuGap)) : desiredHeight;
                var top = openAbove ? Math.max(viewportGap, rect.top - maxHeight - menuGap) : rect.bottom + menuGap;

                menu.style.setProperty('--select-left', rect.left + 'px');
                menu.style.setProperty('--select-top', top + 'px');
                menu.style.setProperty('--select-width', rect.width + 'px');
                menu.style.maxHeight = maxHeight + 'px';
            }

            function closeSelect() {
                select.classList.remove('is-open');
                trigger.setAttribute('aria-expanded', 'false');
                if (menu) {
                    menu.classList.remove('is-floating-open');
                    if (originalMenuParent && menu.parentNode !== originalMenuParent) {
                        originalMenuParent.appendChild(menu);
                    }
                    menu.style.removeProperty('--select-left');
                    menu.style.removeProperty('--select-top');
                    menu.style.removeProperty('--select-width');
                    menu.style.removeProperty('max-height');
                }
            }

            document.addEventListener('modern-select-close-all', function (event) {
                if (event.detail !== select) {
                    closeSelect();
                }
            });

            trigger.addEventListener('click', function () {
                var willOpen = !select.classList.contains('is-open');
                document.dispatchEvent(new CustomEvent('modern-select-close-all', { detail: select }));
                select.classList.toggle('is-open', willOpen);
                trigger.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
                if (willOpen) {
                    if (menu && menu.parentNode !== document.body) {
                        document.body.appendChild(menu);
                    }
                    if (menu) {
                        menu.classList.add('is-floating-open');
                    }
                    positionMenu();
                }
            });

            options.forEach(function (option) {
                option.addEventListener('click', function () {
                    options.forEach(function (item) {
                        item.classList.remove('is-selected');
                    });
                    option.classList.add('is-selected');
                    valueInput.value = option.value;
                    label.textContent = option.textContent.trim();
                    select.classList.remove('is-invalid');
                    closeSelect();
                });
            });

            document.addEventListener('click', function (event) {
                if (!select.contains(event.target) && !(menu && menu.contains(event.target))) {
                    closeSelect();
                }
            });

            window.addEventListener('resize', positionMenu);
            window.addEventListener('scroll', positionMenu, true);
        });

        document.querySelector('form').addEventListener('submit', function (event) {
            var firstInvalidSelect = null;

            document.querySelectorAll('[data-required-select]').forEach(function (select) {
                var valueInput = select.querySelector('[data-modern-select-value]');
                var isInvalid = valueInput && !valueInput.value;
                select.classList.toggle('is-invalid', !!isInvalid);

                if (isInvalid && !firstInvalidSelect) {
                    firstInvalidSelect = select;
                }
            });

            if (firstInvalidSelect) {
                event.preventDefault();
                firstInvalidSelect.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        });

        document.querySelectorAll('[data-signature-field]').forEach(function (field) {
            var input = field.querySelector('[data-signature-input]');
            var preview = field.querySelector('[data-signature-preview]');

            function updatePreview() {
                var value = input.value.trim();
                preview.textContent = value || 'Typed Signature';
                preview.classList.toggle('is-empty', value === '');
            }

            input.addEventListener('input', updatePreview);
            updatePreview();
        });

        document.querySelectorAll('[data-dropzone]').forEach(function (dropzone) {
            var input = dropzone.querySelector('[data-file-input]');
            var fileName = dropzone.querySelector('[data-file-name]');

            function updateFileName(files) {
                fileName.textContent = files && files.length ? files[0].name : 'No file selected';
            }

            input.addEventListener('change', function () {
                updateFileName(input.files);
            });

            dropzone.addEventListener('dragover', function (event) {
                event.preventDefault();
                dropzone.classList.add('is-dragover');
            });

            dropzone.addEventListener('dragleave', function () {
                dropzone.classList.remove('is-dragover');
            });

            dropzone.addEventListener('drop', function (event) {
                event.preventDefault();
                dropzone.classList.remove('is-dragover');

                if (!event.dataTransfer.files.length) {
                    return;
                }

                input.files = event.dataTransfer.files;
                updateFileName(input.files);
            });
        });
    </script>
</body>
</html>
