# IIS Production Deployment

## 1. Server prerequisites

- IIS with CGI/FastCGI, Request Filtering, and URL Authorization installed.
- IIS Dynamic IP Restrictions is recommended for the public login endpoints.
- PHP 8.2 or newer with `pdo_mysql`, `mbstring`, `openssl`, `fileinfo`, `gd`, `curl`, and `zip`.
- MySQL reachable from the IIS server.
- TLS certificate and an HTTPS binding for the production hostname.

Install dependencies in the deployment build:

```powershell
composer install --no-dev --optimize-autoloader
```

## 2. Environment values

Configure these as server or FastCGI environment variables. Do not place secrets in the web directory.

```text
APP_ENV=production
APP_URL=https://itsr.example.company
APP_AUTO_MIGRATE=false
# Only when TLS terminates at a trusted reverse proxy:
# APP_TRUST_PROXY=true
# APP_TRUSTED_PROXIES=10.0.0.10,10.0.0.11

DB_HOST=database-host
DB_PORT=3306
DB_DATABASE=itsr_form
DB_USERNAME=itsr_runtime
DB_PASSWORD=use-a-secret-value

MAIL_ENABLED=true
MAIL_LOG_ONLY=false
MAIL_HOST=smtp.example.company
MAIL_PORT=587
MAIL_ENCRYPTION=tls
MAIL_USERNAME=itsr@example.company
MAIL_PASSWORD=use-a-secret-value
MAIL_FROM_ADDRESS=itsr@example.company
MAIL_FROM_NAME=Enterprise ITSR System
```

The runtime database account needs normal data permissions only after migration. Do not grant it `DROP`, `ALTER`, or `CREATE`.

## 3. Writable directories

Keep the application code read-only. Grant Modify permission only to the runtime storage directories:

```powershell
$appPool = 'IIS AppPool\Enterprise-ITSR'
icacls .\form\storage\pdfs /grant "${appPool}:(OI)(CI)M"
icacls .\form\storage\attachments /grant "${appPool}:(OI)(CI)M"
icacls .\form\storage\fonts /grant "${appPool}:(OI)(CI)M"
icacls .\admin\storage\profile_pictures /grant "${appPool}:(OI)(CI)M"
icacls .\storage /grant "${appPool}:(OI)(CI)M"
```

Replace `Enterprise-ITSR` with the actual application pool name.

## 4. Database migration

Use a temporary database account with schema-change permission. On a fresh database, also set:

```text
INITIAL_ADMIN_USERNAME=admin
INITIAL_ADMIN_EMAIL=administrator@example.company
INITIAL_ADMIN_PASSWORD=use-a-unique-password-of-at-least-12-characters-with-a-letter-and-number
```

Run:

```powershell
php .\scripts\migrate.php
```

Remove the initial administrator password from the environment, switch back to the restricted runtime database account, and keep `APP_AUTO_MIGRATE=false`.

## 5. IIS security

- Point the IIS application root at this directory so the root `web.config` is applied.
- Configure HTTP to HTTPS redirection at the IIS site level.
- Add HSTS only after HTTPS and redirection have been verified.
- Confirm that `/config`, `/vendor`, `/form/database`, `/form/storage`, `/storage`, `/scripts`, and test files return 404 or 403.
- Keep the IIS request body limit at 32 MB or higher to match `.user.ini`.

## 6. Preflight and smoke test

Run from the production application directory:

```powershell
php .\scripts\preflight.php
```

Then test admin, staff, and department login; submit and resubmit a request; download its PDF; view an attachment; assign and complete a task; send an SMTP test; and restore one database backup in a non-production database.
