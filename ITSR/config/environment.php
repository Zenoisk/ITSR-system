<?php

declare(strict_types=1);

function appEnv(string $name, ?string $default = null): ?string
{
    $value = getenv($name);
    if ($value === false) {
        return $default;
    }

    $value = trim($value);
    return $value !== '' ? $value : $default;
}

function appEnvRaw(string $name, ?string $default = null): ?string
{
    $value = getenv($name);
    return $value === false ? $default : $value;
}

function appEnvBool(string $name, bool $default = false): bool
{
    $value = appEnv($name);
    if ($value === null) {
        return $default;
    }

    return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
}

function appIsProduction(): bool
{
    return strtolower(appEnv('APP_ENV', 'local') ?? 'local') === 'production';
}

function appAutoMigrate(): bool
{
    return appEnvBool('APP_AUTO_MIGRATE', !appIsProduction());
}

function appAllowDevelopmentUsers(): bool
{
    return !appIsProduction() && appEnvBool('APP_SEED_DEFAULT_USERS', false);
}

function appUrl(): string
{
    return rtrim(appEnv('APP_URL', 'http://localhost/dashboard/form2') ?? '', '/');
}

function appLogException(Throwable $exception, string $context): void
{
    error_log($context . ': ' . $exception->getMessage());
}

function appErrorMessage(Throwable $exception, string $context, string $fallback = 'The operation could not be completed.'): string
{
    appLogException($exception, $context);
    return appIsProduction() && !($exception instanceof InvalidArgumentException)
        ? $fallback
        : $exception->getMessage();
}
