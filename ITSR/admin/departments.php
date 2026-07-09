<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/login/auth.php';
requireAdmin();

$message = '';
$error = '';
$pdo = db();

function ensureDepartmentDashboardSchema(PDO $pdo): void
{
    if (!appAutoMigrate()) {
        return;
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS departments (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(150) NOT NULL,
            password_hash VARCHAR(255) NOT NULL DEFAULT '',
            status ENUM('active','hidden') NOT NULL DEFAULT 'active',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_department_name (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $passwordColumnStmt = $pdo->query("SHOW COLUMNS FROM departments LIKE 'password_hash'");
    if (!$passwordColumnStmt->fetch()) {
        $pdo->exec("ALTER TABLE departments ADD COLUMN password_hash VARCHAR(255) NOT NULL DEFAULT '' AFTER name");
    }

    try {
        $existingDepartmentsStmt = $pdo->query('SELECT COUNT(*) FROM departments');
        $existingDepartments = (int) $existingDepartmentsStmt->fetchColumn();

        // Only seed once on a fresh install. Re-seeding on every load would
        // resurrect departments that an admin intentionally deleted or renamed.
        if ($existingDepartments === 0) {
            $seedStmt = $pdo->query("SELECT DISTINCT department FROM service_requests WHERE department IS NOT NULL AND TRIM(department) <> ''");
            $insertStmt = $pdo->prepare("INSERT IGNORE INTO departments (name, status) VALUES (:name, 'active')");

            foreach ($seedStmt->fetchAll(PDO::FETCH_COLUMN) as $departmentName) {
                $departmentName = trim((string) $departmentName);
                if ($departmentName !== '') {
                    $insertStmt->execute([':name' => $departmentName]);
                }
            }
        }
    } catch (Throwable $exception) {
        // If service_requests is not ready yet, the page can still manage department names.
    }
}

ensureDepartmentDashboardSchema($pdo);

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrfValidateOrDie();
        if (($_POST['add_department'] ?? '') === '1') {
            $department = trim((string) ($_POST['new_department'] ?? ''));
            $password = (string) ($_POST['new_password'] ?? '');

            if ($department === '' || $password === '') {
                throw new InvalidArgumentException('Department name and password are required.');
            }
            if (($passwordError = passwordPolicyError($password)) !== null) {
                throw new InvalidArgumentException($passwordError);
            }

            $stmt = $pdo->prepare("INSERT INTO departments (name, password_hash, status) VALUES (:name, :password_hash, 'active')");
            $stmt->execute([
                ':name' => $department,
                ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
            ]);

            logSystemActivity('department_dashboard_created', 'Department dashboard added', 'Admin added the "' . $department . '" department dashboard.');
            $message = 'Department dashboard added successfully.';
        }

        if (isset($_POST['department_id'])) {
            $departmentId = (int) $_POST['department_id'];
            if (($_POST['delete_department'] ?? '') === '1') {
                if ($departmentId <= 0) {
                    throw new RuntimeException('Invalid department delete request.');
                }

                $lookupStmt = $pdo->prepare('SELECT name FROM departments WHERE id = :id LIMIT 1');
                $lookupStmt->execute([':id' => $departmentId]);
                $departmentName = (string) ($lookupStmt->fetchColumn() ?: '');

                $stmt = $pdo->prepare('DELETE FROM departments WHERE id = :id');
                $stmt->execute([':id' => $departmentId]);

                if ($departmentName !== '') {
                    logSystemActivity('department_dashboard_deleted', 'Department dashboard removed', 'Admin removed the "' . $departmentName . '" department dashboard.');
                }

                $message = 'Department dashboard removed successfully.';
            } else {
                $department = trim((string) ($_POST['department'] ?? ''));
                $status = (string) ($_POST['status'] ?? 'active');
                $password = (string) ($_POST['password'] ?? '');

                if ($departmentId <= 0 || $department === '') {
                    throw new RuntimeException('Invalid department update request.');
                }

                $normalizedStatus = $status === 'hidden' ? 'hidden' : 'active';
                $stmt = $pdo->prepare('UPDATE departments SET name = :name, status = :status WHERE id = :id');
                $stmt->execute([
                    ':name' => $department,
                    ':status' => $normalizedStatus,
                    ':id' => $departmentId,
                ]);

                if ($password !== '') {
                    if (($passwordError = passwordPolicyError($password)) !== null) {
                        throw new InvalidArgumentException($passwordError);
                    }
                    $passwordStmt = $pdo->prepare('UPDATE departments SET password_hash = :password_hash WHERE id = :id');
                    $passwordStmt->execute([
                        ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
                        ':id' => $departmentId,
                    ]);
                }

                logSystemActivity('department_dashboard_updated', 'Department dashboard updated', 'Admin updated the "' . $department . '" department dashboard.');
                $message = 'Department dashboard updated successfully.';
            }
        }
    }
} catch (Throwable $exception) {
    $error = str_contains($exception->getMessage(), 'Duplicate')
        ? 'That department dashboard already exists.'
        : appErrorMessage($exception, 'Department management update failed', 'Unable to update the department dashboard.');
}

$departments = [];
try {
    $departments = $pdo->query(
        "SELECT
            d.id,
            d.name,
            d.status,
            CASE WHEN d.password_hash <> '' THEN 1 ELSE 0 END AS has_password,
            d.created_at,
            d.updated_at,
            COUNT(sr.id) AS total_requests,
            SUM(CASE WHEN sr.status IN ('completed', 'closed') THEN 1 ELSE 0 END) AS completed_requests,
            SUM(CASE WHEN sr.status IN ('pending', 'assigned', 'progress', 'returned', 'resubmitted', 'reopened') THEN 1 ELSE 0 END) AS active_requests
         FROM departments d
         LEFT JOIN service_requests sr ON sr.department = d.name
         GROUP BY d.id, d.name, d.password_hash, d.status, d.created_at, d.updated_at
         ORDER BY d.name ASC"
    )->fetchAll();
} catch (Throwable $exception) {
    $error = $error !== ''
        ? $error
        : appErrorMessage($exception, 'Department management list failed', 'Unable to load department dashboards.');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Department Dashboards</title>
    <link rel="stylesheet" href="../assets/enterprise-ui.css?v=1.1">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: Inter, "Segoe UI", Roboto, Arial, sans-serif; }
        body { background: linear-gradient(180deg, #eef4fb 0%, #f6f9fc 100%); color: #132538; }
        .layout { min-height: 100vh; display: grid; grid-template-columns: 280px 1fr; }
        .content { padding: 28px; }
        .page { max-width: 1320px; margin: 0 auto; }
        .hero, .panel {
            background: rgba(255, 255, 255, 0.9);
            border: 1px solid rgba(209, 220, 232, 0.92);
            border-radius: 26px;
            box-shadow: 0 18px 38px rgba(15, 23, 42, 0.08);
            padding: 24px;
            margin-bottom: 20px;
        }
        .hero {
            background:
                radial-gradient(circle at 92% 18%, rgba(125, 211, 252, 0.18), transparent 26%),
                linear-gradient(135deg, #0f172a 0%, #163b68 52%, #1d4ed8 100%);
            color: #f8fbff;
            overflow: hidden;
        }
        .eyebrow { color: #bfdbfe; font-size: 12px; letter-spacing: 0.16em; text-transform: uppercase; font-weight: 900; }
        h1 { font-size: 36px; margin: 8px 0; }
        .hero p { color: #dbeafe; line-height: 1.65; max-width: 820px; }
        h2 { font-size: 28px; line-height: 1.1; margin-bottom: 8px; color: #10243c; }
        .panel-intro { color: #637893; line-height: 1.55; margin-bottom: 18px; }
        .workspace { display: grid; grid-template-columns: minmax(320px, 0.58fr) minmax(0, 1.42fr); gap: 22px; align-items: start; }
        .form-grid, .department-list { display: grid; gap: 14px; }
        label { display: block; font-weight: 800; font-size: 13px; color: #29415d; margin-bottom: 7px; }
        input {
            width: 100%;
            min-height: 52px;
            border: 1px solid #cfdeee;
            border-radius: 16px;
            padding: 0 16px;
            font-size: 15px;
            background: #fff;
            color: #10243c;
            outline: none;
            transition: border-color 0.18s ease, box-shadow 0.18s ease, background 0.18s ease;
        }
        input:focus {
            border-color: #38a7ff;
            box-shadow: 0 0 0 4px rgba(56, 167, 255, 0.16);
            background: #fbfdff;
        }
        input::placeholder { color: #7a8ca3; }
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 46px;
            padding: 0 18px;
            border: 0;
            border-radius: 14px;
            font-weight: 900;
            cursor: pointer;
            text-decoration: none;
            transition: transform 0.18s ease, box-shadow 0.18s ease, filter 0.18s ease;
        }
        .btn:hover { transform: translateY(-1px); }
        .btn-primary { background: linear-gradient(135deg, #2563eb, #1d9bf0); color: #fff; box-shadow: 0 14px 26px rgba(37, 99, 235, 0.2); }
        .btn-danger { background: linear-gradient(135deg, #dc2626, #b91c1c); color: #fff; box-shadow: 0 14px 26px rgba(220, 38, 38, 0.16); }
        .btn-light { background: #edf4fb; color: #17385b; }
        .notice, .error { padding: 14px 16px; border-radius: 14px; margin-bottom: 16px; }
        .notice { background: #eaf8ef; color: #1f7a3f; border: 1px solid #b7e1c4; }
        .error { background: #fff1f1; color: #9f1d1d; border: 1px solid #f0b6b6; }
        .department-card {
            border: 1px solid #e2eaf3;
            border-radius: 22px;
            background: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%);
            padding: 18px;
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 16px;
            align-items: start;
            box-shadow: 0 12px 28px rgba(15, 23, 42, 0.05);
        }
        .department-name { font-size: 22px; font-weight: 900; color: #10243c; margin-bottom: 8px; }
        .department-meta { color: #6b7f95; font-size: 13px; line-height: 1.55; }
        .metric-row { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 14px; }
        .metric-pill {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            min-height: 34px;
            padding: 0 12px;
            border-radius: 999px;
            background: #edf4fb;
            color: #17385b;
            font-size: 12px;
            font-weight: 900;
        }
        .metric-pill.is-complete { background: #eaf8ef; color: #1f7a3f; }
        .metric-pill.is-active { background: #fff5dc; color: #9a6a00; }
        .pill { display: inline-flex; padding: 7px 11px; border-radius: 999px; font-size: 12px; font-weight: 900; text-transform: capitalize; }
        .pill.active { background: #eaf8ef; color: #1f7a3f; }
        .pill.hidden { background: #e5e7eb; color: #475569; }
        .card-actions { display: flex; gap: 10px; flex-wrap: wrap; justify-content: flex-end; }
        .edit-form {
            grid-column: 1 / -1;
            display: grid;
            grid-template-columns: minmax(200px, 1fr) minmax(190px, 0.9fr) 170px auto auto;
            gap: 12px;
            align-items: end;
            padding-top: 16px;
            border-top: 1px solid #edf2f7;
        }
        .modern-select { position: relative; }
        .status-trigger {
            width: 100%;
            min-height: 52px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            border: 1px solid #cfdeee;
            border-radius: 16px;
            padding: 0 14px 0 16px;
            background: linear-gradient(180deg, #fff, #f8fbff);
            color: #10243c;
            font-size: 15px;
            font-weight: 800;
            cursor: pointer;
        }
        .status-trigger::after {
            content: "";
            width: 10px;
            height: 10px;
            border-right: 2px solid #55708d;
            border-bottom: 2px solid #55708d;
            transform: rotate(45deg) translateY(-2px);
            transition: transform 0.18s ease;
        }
        .modern-select.is-open .status-trigger {
            border-color: #38a7ff;
            box-shadow: 0 0 0 4px rgba(56, 167, 255, 0.16);
        }
        .modern-select.is-open .status-trigger::after { transform: rotate(225deg) translateY(-2px); }
        .status-options {
            position: absolute;
            z-index: 20;
            top: calc(100% + 8px);
            left: 0;
            right: 0;
            display: none;
            padding: 8px;
            border: 1px solid #d8e5f2;
            border-radius: 18px;
            background: rgba(255, 255, 255, 0.98);
            box-shadow: 0 18px 40px rgba(15, 23, 42, 0.16);
        }
        .modern-select.is-open .status-options { display: grid; gap: 6px; }
        .status-option {
            width: 100%;
            min-height: 42px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border: 0;
            border-radius: 12px;
            padding: 0 12px;
            background: transparent;
            color: #10243c;
            font-size: 14px;
            font-weight: 800;
            cursor: pointer;
            text-align: left;
        }
        .status-option:hover,
        .status-option.is-selected { background: #e8f3ff; color: #0b66c3; }
        .status-option.is-selected::after { content: "✓"; color: #0b66c3; font-weight: 900; }
        .empty-state {
            min-height: 220px;
            display: grid;
            place-items: center;
            text-align: center;
            padding: 32px;
            border: 1px dashed #cbdbea;
            border-radius: 22px;
            background: linear-gradient(180deg, #fbfdff, #f3f8fd);
            color: #637893;
        }
        .empty-icon {
            width: 54px;
            height: 54px;
            display: inline-grid;
            place-items: center;
            margin-bottom: 14px;
            border-radius: 18px;
            background: #e8f3ff;
            color: #0b78be;
            font-weight: 900;
            box-shadow: inset 0 0 0 1px #cce5ff;
        }
        .empty-state strong { display: block; color: #10243c; font-size: 18px; margin-bottom: 6px; }
        body.theme-dark { background: #07111f; color: #e5eef9; }
        body.theme-dark .panel, body.theme-dark .department-card { background: rgba(12, 21, 36, 0.9); border-color: rgba(65, 85, 110, 0.72); }
        body.theme-dark label { color: #9eb2c9; }
        body.theme-dark h2, body.theme-dark .empty-state strong, body.theme-dark .department-name { color: #f8fbff; }
        body.theme-dark .panel-intro, body.theme-dark .department-meta { color: #9eb2c9; }
        body.theme-dark input { background: #101b2d; border-color: #334761; color: #f8fbff; }
        body.theme-dark input:focus { border-color: #38bdf8; box-shadow: 0 0 0 4px rgba(56, 189, 248, 0.18); }
        body.theme-dark .status-trigger { background: linear-gradient(180deg, #101b2d, #0d1727); border-color: #334761; color: #f8fbff; }
        body.theme-dark .status-trigger::after { border-color: #9eb2c9; }
        body.theme-dark .status-options { background: #0d1727; border-color: #334761; box-shadow: 0 18px 40px rgba(0, 0, 0, 0.36); }
        body.theme-dark .status-option { color: #e5eef9; }
        body.theme-dark .status-option:hover,
        body.theme-dark .status-option.is-selected { background: #14375c; color: #8fd4ff; }
        body.theme-dark .status-option.is-selected::after { color: #8fd4ff; }
        body.theme-dark .empty-state { background: rgba(16, 27, 45, 0.76); border-color: #334761; color: #9eb2c9; }
        body.theme-dark .empty-icon { background: #15314d; color: #7dd3fc; box-shadow: inset 0 0 0 1px #255474; }
        @media (max-width: 1120px) { .workspace, .edit-form { grid-template-columns: 1fr; } .card-actions { justify-content: flex-start; } }
        @media (max-width: 1024px) { .layout { grid-template-columns: 1fr; } .content { padding: 18px; } }
        .password-wrapper { position: relative; display: flex; align-items: center; width: 100%; }
        .password-wrapper input { width: 100%; padding-right: 46px !important; }
        .password-toggle-btn { position: absolute; right: 12px; background: transparent; border: none; color: #64748b; cursor: pointer; display: flex; align-items: center; justify-content: center; padding: 6px; border-radius: 8px; transition: color 0.18s ease, background 0.18s ease; }
        .password-toggle-btn:hover { color: #1e293b; background: rgba(100, 116, 139, 0.08); }
        .password-toggle-btn svg { width: 18px; height: 18px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
        body.theme-dark .password-toggle-btn:hover { color: #f8fbff; background: rgba(148, 163, 184, 0.08); }
    </style>
</head>
<body>
    <div class="layout">
        <?php $activePage = 'departments'; require __DIR__ . '/sidebar.php'; ?>

        <main class="content">
            <div class="page">
                <section class="hero">
                    <div class="eyebrow">Administrator Departments</div>
                    <h1>Manage Department Dashboards</h1>
                    <p>Departments are not login accounts here. Admin only creates the department dashboard name, and completed ITSR forms for that department will appear in its dashboard.</p>
                </section>

                <?php if ($message !== ''): ?><div class="notice"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
                <?php if ($error !== ''): ?><div class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

                <div class="workspace">
                    <section class="panel">
                        <h2>Add Department</h2>
                        <p class="panel-intro">Create department access with only a department name and password.</p>
                        <form method="post" class="form-grid">
                            <?= csrfField() ?>
                            <input type="hidden" name="add_department" value="1">
                            <div><label>Department Name</label><input name="new_department" placeholder="Example: QA, HR, MIS, Finance" required></div>
                            <div>
                                <label>Password</label>
                                <div class="password-wrapper">
                                    <input type="password" name="new_password" placeholder="Create department password" autocomplete="new-password" required>
                                    <button class="password-toggle-btn" type="button" aria-label="Toggle password visibility">
                                        <svg class="eye-open" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                        <svg class="eye-closed" viewBox="0 0 24 24" style="display:none;"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                                    </button>
                                </div>
                            </div>
                            <button class="btn btn-primary" type="submit">Add Department Dashboard</button>
                        </form>
                    </section>

                    <section class="panel">
                        <h2>Department Dashboards</h2>
                        <p class="panel-intro">Open each dashboard to review completed forms, or reset the department password.</p>
                        <div class="department-list">
                            <?php foreach ($departments as $departmentRow): ?>
                                <?php $selectedStatus = (string) $departmentRow['status'] === 'hidden' ? 'hidden' : 'active'; ?>
                                <article class="department-card">
                                    <div>
                                        <div class="department-name"><?= htmlspecialchars((string) $departmentRow['name'], ENT_QUOTES, 'UTF-8') ?></div>
                                        <div class="department-meta">Created <?= htmlspecialchars((string) $departmentRow['created_at'], ENT_QUOTES, 'UTF-8') ?> · Updated <?= htmlspecialchars((string) $departmentRow['updated_at'], ENT_QUOTES, 'UTF-8') ?></div>
                                        <div class="metric-row">
                                            <span class="metric-pill"><?= (int) $departmentRow['total_requests'] ?> total</span>
                                            <span class="metric-pill is-complete"><?= (int) $departmentRow['completed_requests'] ?> completed</span>
                                            <span class="metric-pill is-active"><?= (int) $departmentRow['active_requests'] ?> active</span>
                                            <span class="metric-pill"><?= (int) $departmentRow['has_password'] === 1 ? 'Password set' : 'No password' ?></span>
                                            <span class="pill <?= $selectedStatus ?>"><?= htmlspecialchars($selectedStatus, ENT_QUOTES, 'UTF-8') ?></span>
                                        </div>
                                    </div>
                                    <div class="card-actions">
                                        <a class="btn btn-light" href="../login/login.php?portal=department" target="_blank">Open Login</a>
                                    </div>
                                    <form class="edit-form" method="post">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="department_id" value="<?= (int) $departmentRow['id'] ?>">
                                        <div><label>Department Name</label><input name="department" value="<?= htmlspecialchars((string) $departmentRow['name'], ENT_QUOTES, 'UTF-8') ?>" required></div>
                                        <div>
                                            <label>New Password</label>
                                            <div class="password-wrapper">
                                                <input type="password" name="password" placeholder="Leave blank to keep" autocomplete="new-password">
                                                <button class="password-toggle-btn" type="button" aria-label="Toggle password visibility">
                                                    <svg class="eye-open" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                                    <svg class="eye-closed" viewBox="0 0 24 24" style="display:none;"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                                                </button>
                                            </div>
                                        </div>
                                        <div>
                                            <label>Status</label>
                                            <div class="modern-select" data-modern-select>
                                                <input type="hidden" name="status" value="<?= htmlspecialchars($selectedStatus, ENT_QUOTES, 'UTF-8') ?>" data-select-value>
                                                <button class="status-trigger" type="button" data-select-trigger><?= $selectedStatus === 'hidden' ? 'Hidden' : 'Active' ?></button>
                                                <div class="status-options" role="listbox">
                                                    <button class="status-option<?= $selectedStatus === 'active' ? ' is-selected' : '' ?>" type="button" data-value="active">Active</button>
                                                    <button class="status-option<?= $selectedStatus === 'hidden' ? ' is-selected' : '' ?>" type="button" data-value="hidden">Hidden</button>
                                                </div>
                                            </div>
                                        </div>
                                        <button class="btn btn-primary" name="save_department" value="1" type="submit">Save</button>
                                        <button class="btn btn-danger" name="delete_department" value="1" type="submit" onclick="return confirm('Remove this department dashboard? Existing requests will not be deleted.')">Delete</button>
                                    </form>
                                </article>
                            <?php endforeach; ?>
                            <?php if ($departments === []): ?>
                                <div class="empty-state">
                                    <div>
                                        <span class="empty-icon">DP</span>
                                        <strong>No department dashboards yet</strong>
                                        <p>Add the first department name on the left. Completed ITSR forms will show under that department dashboard.</p>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </section>
                </div>
            </div>
        </main>
    </div>
    <script>
        document.querySelectorAll('[data-modern-select]').forEach(function (select) {
            var trigger = select.querySelector('[data-select-trigger]');
            var valueInput = select.querySelector('[data-select-value]');
            var options = select.querySelectorAll('[data-value]');

            trigger.addEventListener('click', function () {
                document.querySelectorAll('[data-modern-select].is-open').forEach(function (openSelect) {
                    if (openSelect !== select) {
                        openSelect.classList.remove('is-open');
                    }
                });
                select.classList.toggle('is-open');
            });

            options.forEach(function (option) {
                option.addEventListener('click', function () {
                    valueInput.value = option.dataset.value;
                    trigger.textContent = option.textContent;
                    options.forEach(function (item) {
                        item.classList.toggle('is-selected', item === option);
                    });
                    select.classList.remove('is-open');
                });
            });
        });

        document.addEventListener('click', function (event) {
            if (!event.target.closest('[data-modern-select]')) {
                document.querySelectorAll('[data-modern-select].is-open').forEach(function (select) {
                    select.classList.remove('is-open');
                });
            }
        });

        document.querySelectorAll('.password-toggle-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var wrapper = btn.closest('.password-wrapper');
                var input = wrapper.querySelector('input');
                var eyeOpen = btn.querySelector('.eye-open');
                var eyeClosed = btn.querySelector('.eye-closed');

                if (input.type === 'password') {
                    input.type = 'text';
                    eyeOpen.style.display = 'none';
                    eyeClosed.style.display = 'block';
                } else {
                    input.type = 'password';
                    eyeOpen.style.display = 'block';
                    eyeClosed.style.display = 'none';
                }
            });
        });
    </script>
</body>
</html>
