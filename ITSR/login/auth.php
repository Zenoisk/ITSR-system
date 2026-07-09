<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/config/environment.php';

function itsrPortalContext(): string
{
    $requestedPortal = strtolower(trim((string) ($_POST['portal'] ?? $_GET['portal'] ?? $_GET['return'] ?? $_POST['return_context'] ?? '')));
    if (in_array($requestedPortal, ['admin', 'staff', 'department'], true)) {
        return $requestedPortal;
    }

    $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if (str_contains($scriptName, '/department/')) {
        return 'department';
    }

    if (str_contains($scriptName, '/staff/')) {
        return 'staff';
    }

    if (str_contains($scriptName, '/form/')) {
        return 'department';
    }

    if (str_contains($scriptName, '/admin/')) {
        return 'admin';
    }

    return 'admin';
}

function itsrSessionNameForPortal(string $portal): string
{
    return match ($portal) {
        'staff' => 'ITSR_STAFF_SESSION',
        'department' => 'ITSR_DEPARTMENT_SESSION',
        default => 'ITSR_ADMIN_SESSION',
    };
}

function itsrIsHttpsRequest(): bool
{
    $https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
    $serverPort = (int) ($_SERVER['SERVER_PORT'] ?? 0);

    if (appEnvBool('APP_TRUST_PROXY', false)) {
        $remoteAddress = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $trustedProxies = array_filter(array_map('trim', explode(',', appEnv('APP_TRUSTED_PROXIES', '') ?? '')));
        if (in_array($remoteAddress, $trustedProxies, true)) {
            $forwardedProto = strtolower(trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
            if ($forwardedProto === 'https') {
                return true;
            }
        }
    }

    return $https !== '' && $https !== 'off' || $serverPort === 443;
}

function itsrConfigureSessionCookie(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $params = session_get_cookie_params();
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => $params['path'] ?? '/',
        'domain' => $params['domain'] ?? '',
        'secure' => itsrIsHttpsRequest(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    itsrConfigureSessionCookie();
    session_name(itsrSessionNameForPortal(itsrPortalContext()));
    session_start();
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
}

function ensureAuthSchema(): void
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
        "CREATE TABLE IF NOT EXISTS users (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            full_name VARCHAR(150) NOT NULL DEFAULT '',
            username VARCHAR(100) NOT NULL,
            email VARCHAR(255) NULL DEFAULT NULL,
            password_hash VARCHAR(255) NOT NULL,
            role ENUM('admin','staff','department_user') NOT NULL DEFAULT 'staff',
            department VARCHAR(150) NOT NULL DEFAULT '',
            status ENUM('active','blocked') NOT NULL DEFAULT 'active',
            profile_picture_path VARCHAR(255) NULL DEFAULT NULL,
            profile_picture_position_x TINYINT UNSIGNED NOT NULL DEFAULT 50,
            profile_picture_position_y TINYINT UNSIGNED NOT NULL DEFAULT 50,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_username (username),
            UNIQUE KEY unique_email (email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $columnExistsStmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'email'");
    if (!$columnExistsStmt->fetch()) {
        $pdo->exec("ALTER TABLE users ADD COLUMN email VARCHAR(255) NULL DEFAULT NULL AFTER username");
    }

    $fullNameColumnStmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'full_name'");
    if (!$fullNameColumnStmt->fetch()) {
        $pdo->exec("ALTER TABLE users ADD COLUMN full_name VARCHAR(150) NOT NULL DEFAULT '' AFTER id");
    }

    $departmentColumnStmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'department'");
    if (!$departmentColumnStmt->fetch()) {
        $pdo->exec("ALTER TABLE users ADD COLUMN department VARCHAR(150) NOT NULL DEFAULT '' AFTER role");
    }

    try {
        $pdo->exec("ALTER TABLE users MODIFY role ENUM('admin','staff','department_user') NOT NULL DEFAULT 'staff'");
    } catch (Throwable $exception) {
        // Existing databases may already have the expanded role list.
    }

    $indexExistsStmt = $pdo->query("SHOW INDEX FROM users WHERE Key_name = 'unique_email'");
    if (!$indexExistsStmt->fetch()) {
        $pdo->exec("ALTER TABLE users ADD UNIQUE KEY unique_email (email)");
    }

    $profilePictureColumnStmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'profile_picture_path'");
    if (!$profilePictureColumnStmt->fetch()) {
        $pdo->exec("ALTER TABLE users ADD COLUMN profile_picture_path VARCHAR(255) NULL DEFAULT NULL AFTER status");
    }

    $profilePicturePositionXStmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'profile_picture_position_x'");
    if (!$profilePicturePositionXStmt->fetch()) {
        $pdo->exec("ALTER TABLE users ADD COLUMN profile_picture_position_x TINYINT UNSIGNED NOT NULL DEFAULT 50 AFTER profile_picture_path");
    }

    $profilePicturePositionYStmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'profile_picture_position_y'");
    if (!$profilePicturePositionYStmt->fetch()) {
        $pdo->exec("ALTER TABLE users ADD COLUMN profile_picture_position_y TINYINT UNSIGNED NOT NULL DEFAULT 50 AFTER profile_picture_position_x");
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS password_resets (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id INT UNSIGNED NOT NULL,
            token_hash VARCHAR(255) NOT NULL,
            expires_at DATETIME NOT NULL,
            used_at DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_user_id (user_id),
            KEY idx_token_hash (token_hash)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS activity_logs (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            actor_user_id INT UNSIGNED NULL DEFAULT NULL,
            actor_name VARCHAR(100) NOT NULL DEFAULT '',
            actor_role VARCHAR(20) NOT NULL DEFAULT '',
            action_type VARCHAR(50) NOT NULL DEFAULT '',
            title VARCHAR(255) NOT NULL,
            description TEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_created_at (created_at),
            KEY idx_action_type (action_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS deleted_requests (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            original_request_id INT UNSIGNED NOT NULL,
            company VARCHAR(255) NOT NULL DEFAULT '',
            department VARCHAR(255) NOT NULL DEFAULT '',
            requestor_name VARCHAR(255) NOT NULL DEFAULT '',
            requestor_email VARCHAR(255) NOT NULL DEFAULT '',
            requestor_phone VARCHAR(100) NOT NULL DEFAULT '',
            status VARCHAR(30) NOT NULL DEFAULT '',
            location VARCHAR(255) NOT NULL DEFAULT '',
            assign_to VARCHAR(255) NOT NULL DEFAULT '',
            request_date DATE NULL,
            deleted_by_user_id INT UNSIGNED NULL DEFAULT NULL,
            deleted_by_username VARCHAR(100) NOT NULL DEFAULT '',
            original_created_at DATETIME NULL,
            pdf_path VARCHAR(255) NOT NULL DEFAULT '',
            deleted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            snapshot_json LONGTEXT NULL,
            PRIMARY KEY (id),
            KEY idx_original_request_id (original_request_id),
            KEY idx_deleted_at (deleted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $deletedRequestDateStmt = $pdo->query("SHOW COLUMNS FROM deleted_requests LIKE 'request_date'");
    if (!$deletedRequestDateStmt->fetch()) {
        $pdo->exec("ALTER TABLE deleted_requests ADD COLUMN request_date DATE NULL AFTER assign_to");
    }

    $deletedPdfPathStmt = $pdo->query("SHOW COLUMNS FROM deleted_requests LIKE 'pdf_path'");
    if (!$deletedPdfPathStmt->fetch()) {
        $pdo->exec("ALTER TABLE deleted_requests ADD COLUMN pdf_path VARCHAR(255) NOT NULL DEFAULT '' AFTER original_created_at");
    }

    $hasUsers = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0;

    if (!$hasUsers && appAllowDevelopmentUsers()) {
        $seedUsers = [
            // ⚠️ SECURITY WARNING: These seed credentials are created ONLY on fresh installs
            // (i.e. when no users exist in the database yet).
            // Before publishing to the company server, log in as admin and change the password
            // immediately via the Account page. Do NOT leave the default password in production.
            ['full_name' => 'Administrator', 'username' => 'admin', 'email' => 'admin@example.com', 'password' => appEnvRaw('DEV_ADMIN_PASSWORD', ''), 'role' => 'admin', 'department' => ''],
            ['full_name' => 'Staff Member', 'username' => 'staff', 'email' => 'staff@example.com', 'password' => appEnvRaw('DEV_STAFF_PASSWORD', ''), 'role' => 'staff', 'department' => ''],
        ];

        $insertStmt = $pdo->prepare(
            'INSERT INTO users (full_name, username, email, password_hash, role, department, status) VALUES (:full_name, :username, :email, :password_hash, :role, :department, :status)'
        );

        foreach ($seedUsers as $user) {
            if ($user['password'] === '') {
                continue;
            }
            $insertStmt->execute([
                ':full_name' => $user['full_name'],
                ':username' => $user['username'],
                ':email' => $user['email'],
                ':password_hash' => password_hash($user['password'], PASSWORD_DEFAULT),
                ':role' => $user['role'],
                ':department' => $user['department'],
                ':status' => 'active',
            ]);
        }
    }

    $initialized = true;
}

function passwordPolicyError(string $password): ?string
{
    if (strlen($password) < 12) {
        return 'Password must be at least 12 characters.';
    }

    if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
        return 'Password must contain at least one letter and one number.';
    }

    return null;
}

function findUserByUsername(string $username): ?array
{
    ensureAuthSchema();

    $stmt = db()->prepare('SELECT * FROM users WHERE username = :username LIMIT 1');
    $stmt->execute([':username' => $username]);
    $user = $stmt->fetch();

    return $user ?: null;
}

function findUserByIdentifier(string $identifier): ?array
{
    ensureAuthSchema();

    $stmt = db()->prepare('SELECT * FROM users WHERE username = :identifier OR email = :identifier LIMIT 1');
    $stmt->execute([':identifier' => $identifier]);
    $user = $stmt->fetch();

    return $user ?: null;
}

function attemptLogin(string $username, string $password, ?string $requiredRole = null): array
{
    [$allowed, $remaining] = checkLoginRateLimit();
    if (!$allowed) {
        return [false, 'Too many login attempts. Please try again in ' . $remaining . ' seconds.'];
    }

    $user = findUserByUsername($username);

    if (!$user || !password_verify($password, (string) $user['password_hash'])) {
        registerFailedAttempt();
        return [false, 'Invalid username or password.'];
    }

    if ($requiredRole !== null && (string) ($user['role'] ?? '') !== $requiredRole) {
        registerFailedAttempt();
        return [false, 'Please use a ' . str_replace('_', ' ', $requiredRole) . ' account for this login portal.'];
    }

    if (($user['status'] ?? 'active') !== 'active') {
        registerFailedAttempt();
        return [false, 'This account is blocked.'];
    }

    resetLoginAttempts();
    session_regenerate_id(true);
    $_SESSION['last_activity_timestamp'] = time();
    $_SESSION['form_user_logged_in'] = true;
    $_SESSION['form_user_id'] = (int) $user['id'];
    $_SESSION['form_username'] = (string) $user['username'];
    $_SESSION['form_user_role'] = (string) $user['role'];
    $_SESSION['form_user_department'] = (string) ($user['department'] ?? '');
    $_SESSION['form_user_label'] = match ((string) $user['role']) {
        'admin' => 'Administrator',
        'department_user' => 'Department User',
        default => 'Staff',
    };

    return [true, null];
}

function ensureDepartmentLoginSchema(): void
{
    if (!appAutoMigrate()) {
        return;
    }

    $pdo = db();
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
}

function attemptDepartmentLogin(string $department, string $password): array
{
    ensureDepartmentLoginSchema();

    $department = trim($department);
    if ($department === '' || $password === '') {
        return [false, 'Department and password are required.'];
    }

    [$allowed, $remaining] = checkLoginRateLimit();
    if (!$allowed) {
        return [false, 'Too many login attempts. Please try again in ' . $remaining . ' seconds.'];
    }

    $stmt = db()->prepare("SELECT * FROM departments WHERE name = :name LIMIT 1");
    $stmt->execute([':name' => $department]);
    $departmentRow = $stmt->fetch();

    if (!$departmentRow || (string) ($departmentRow['password_hash'] ?? '') === '' || !password_verify($password, (string) $departmentRow['password_hash'])) {
        registerFailedAttempt();
        return [false, 'Invalid department or password.'];
    }

    if (($departmentRow['status'] ?? 'active') !== 'active') {
        registerFailedAttempt();
        return [false, 'This department dashboard is not active.'];
    }

    resetLoginAttempts();
    session_regenerate_id(true);
    $_SESSION['last_activity_timestamp'] = time();
    $_SESSION['form_user_logged_in'] = true;
    $_SESSION['form_user_id'] = (int) $departmentRow['id'];
    $_SESSION['form_username'] = (string) $departmentRow['name'];
    $_SESSION['form_user_role'] = 'department_user';
    $_SESSION['form_user_department'] = (string) $departmentRow['name'];
    $_SESSION['form_user_label'] = 'Department';

    return [true, null];
}

function isLoggedIn(): bool
{
    return !empty($_SESSION['form_user_logged_in']);
}

function currentUserId(): int
{
    return (int) ($_SESSION['form_user_id'] ?? 0);
}

function currentUsername(): string
{
    return (string) ($_SESSION['form_username'] ?? '');
}

function currentUserRole(): string
{
    return (string) ($_SESSION['form_user_role'] ?? '');
}

function currentUserLabel(): string
{
    return (string) ($_SESSION['form_user_label'] ?? '');
}

function currentUserDepartment(): string
{
    return (string) ($_SESSION['form_user_department'] ?? '');
}

function logSystemActivity(string $actionType, string $title, string $description = ''): void
{
    ensureAuthSchema();

    try {
        $stmt = db()->prepare(
            'INSERT INTO activity_logs (actor_user_id, actor_name, actor_role, action_type, title, description)
             VALUES (:actor_user_id, :actor_name, :actor_role, :action_type, :title, :description)'
        );
        $stmt->execute([
            ':actor_user_id' => currentUserId() > 0 ? currentUserId() : null,
            ':actor_name' => currentUsername(),
            ':actor_role' => currentUserRole(),
            ':action_type' => $actionType,
            ':title' => $title,
            ':description' => $description,
        ]);
    } catch (Throwable $exception) {
    }
}

function archiveDeletedRequest(PDO $pdo, int $requestId): void
{
    ensureAuthSchema();

    if ($requestId <= 0) {
        return;
    }

    $requestStmt = $pdo->prepare('SELECT * FROM service_requests WHERE id = :id LIMIT 1');
    $requestStmt->execute([':id' => $requestId]);
    $request = $requestStmt->fetch();

    if (!$request) {
        return;
    }

    $archiveStmt = $pdo->prepare(
        'INSERT INTO deleted_requests (
            original_request_id,
            company,
            department,
            requestor_name,
            requestor_email,
            requestor_phone,
            status,
            location,
            assign_to,
            request_date,
            deleted_by_user_id,
            deleted_by_username,
            original_created_at,
            pdf_path,
            snapshot_json
        ) VALUES (
            :original_request_id,
            :company,
            :department,
            :requestor_name,
            :requestor_email,
            :requestor_phone,
            :status,
            :location,
            :assign_to,
            :request_date,
            :deleted_by_user_id,
            :deleted_by_username,
            :original_created_at,
            :pdf_path,
            :snapshot_json
        )'
    );

    $archiveStmt->execute([
        ':original_request_id' => (int) $request['id'],
        ':company' => (string) ($request['company'] ?? ''),
        ':department' => (string) ($request['department'] ?? ''),
        ':requestor_name' => (string) ($request['requestor_name'] ?? ''),
        ':requestor_email' => (string) ($request['requestor_email'] ?? ''),
        ':requestor_phone' => (string) ($request['requestor_phone'] ?? ''),
        ':status' => (string) ($request['status'] ?? ''),
        ':location' => (string) ($request['location'] ?? ''),
        ':assign_to' => (string) ($request['assign_to'] ?? ''),
        ':request_date' => (string) ($request['request_date'] ?? '') !== '' ? (string) $request['request_date'] : null,
        ':deleted_by_user_id' => currentUserId() > 0 ? currentUserId() : null,
        ':deleted_by_username' => currentUsername(),
        ':original_created_at' => (string) ($request['created_at'] ?? '') !== '' ? (string) $request['created_at'] : null,
        ':pdf_path' => (string) ($request['pdf_path'] ?? ''),
        ':snapshot_json' => json_encode($request, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
}

function requireLogin(): void
{
    if (isLoggedIn()) {
        return;
    }

    header('Location: ../login/login.php?portal=' . rawurlencode(itsrPortalContext()));
    exit;
}

function requireAdmin(): void
{
    requireLogin();

    if (currentUserRole() === 'admin') {
        return;
    }

    http_response_code(403);
    echo 'Access denied.';
    exit;
}

function requireStaff(): void
{
    requireLogin();

    if (currentUserRole() === 'staff') {
        return;
    }

    http_response_code(403);
    echo 'Access denied.';
    exit;
}

function requireDepartment(): void
{
    requireLogin();

    if (currentUserRole() === 'department_user') {
        return;
    }

    http_response_code(403);
    echo 'Access denied.';
    exit;
}

ensureAuthSchema();

// ── CSRF Protection ──────────────────────────────────────────────────────────

function csrfToken(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return '';
    }

    if (empty($_SESSION['_csrf_token'])) {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
    }

    return (string) $_SESSION['_csrf_token'];
}

function csrfField(): string
{
    return '<input type="hidden" name="_csrf_token" value="' . htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') . '">';
}

function csrfValidate(): bool
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return true;
    }

    $submitted = (string) ($_POST['_csrf_token'] ?? '');
    $stored = (string) ($_SESSION['_csrf_token'] ?? '');

    if ($stored === '' || $submitted === '') {
        return false;
    }

    return hash_equals($stored, $submitted);
}

function csrfValidateOrDie(): void
{
    if (!csrfValidate()) {
        http_response_code(403);
        $portal = htmlspecialchars(itsrPortalContext(), ENT_QUOTES, 'UTF-8');
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Security Token Expired</title><style>body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#f4f7fb;font-family:Arial,Helvetica,sans-serif;color:#20324a;padding:24px}.box{max-width:560px;width:100%;background:#fff;border:1px solid #dbe4ee;border-radius:18px;box-shadow:0 20px 40px rgba(15,23,42,.08);padding:28px 30px}h1{margin:0 0 10px;font-size:24px}p{margin:0 0 18px;line-height:1.6;color:#52667d}.actions{display:flex;gap:12px;flex-wrap:wrap}.btn{display:inline-flex;align-items:center;justify-content:center;min-height:42px;padding:0 16px;border-radius:999px;text-decoration:none;font-weight:700;border:1px solid #c9d6e4}.btn.primary{background:#1d4ed8;color:#fff;border-color:#1d4ed8}.btn.secondary{background:#fff;color:#20324a}</style></head><body><div class="box"><h1>Security token expired</h1><p>Your form was submitted from a page that is no longer current. Refresh the page and try again.</p><div class="actions"><a class="btn primary" href="javascript:history.back()">Go Back</a><a class="btn secondary" href="../login/login.php?portal=' . $portal . '">Go to Login</a></div></div></body></html>';
        exit;
    }
}

function destroyCurrentSession(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'] ?? '/',
            'domain' => $params['domain'] ?? '',
            'secure' => $params['secure'] ?? itsrIsHttpsRequest(),
            'httponly' => $params['httponly'] ?? true,
            'samesite' => 'Lax',
        ]);
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}

// ── Login Rate Limiting (Brute-Force Protection) ───────────────────────────

function checkLoginRateLimit(): array
{
    if (empty($_SESSION['login_attempts'])) {
        return [true, 0];
    }

    $attempts = (int) $_SESSION['login_attempts'];
    $lastTime = (int) ($_SESSION['last_login_attempt'] ?? 0);
    $lockoutTime = 60; // 60 seconds lockout

    if ($attempts >= 5) {
        $elapsed = time() - $lastTime;
        if ($elapsed < $lockoutTime) {
            $remaining = $lockoutTime - $elapsed;
            return [false, $remaining];
        }
        // Lockout expired, reset attempts
        $_SESSION['login_attempts'] = 0;
        unset($_SESSION['last_login_attempt']);
    }

    return [true, 0];
}

function registerFailedAttempt(): void
{
    $_SESSION['login_attempts'] = ($_SESSION['login_attempts'] ?? 0) + 1;
    $_SESSION['last_login_attempt'] = time();
}

function resetLoginAttempts(): void
{
    $_SESSION['login_attempts'] = 0;
    unset($_SESSION['last_login_attempt']);
}

// ── Session Auto-Logout (Inactivity Timeout) ───────────────────────────────

function checkSessionInactivityTimeout(): void
{
    if (isLoggedIn()) {
        if (isset($_SESSION['last_activity_timestamp'])) {
            $elapsedTime = time() - (int) $_SESSION['last_activity_timestamp'];
            if ($elapsedTime > 1800) { // 30 minutes of inactivity
                $portal = itsrPortalContext();
                destroyCurrentSession();
                header('Location: ../login/login.php?portal=' . rawurlencode($portal) . '&logout_reason=inactivity');
                exit;
            }
        }
        $_SESSION['last_activity_timestamp'] = time();
    }
}

// Execute inactivity timeout check immediately on session load
checkSessionInactivityTimeout();
