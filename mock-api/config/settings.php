<?php

declare(strict_types=1);

/**
 * Application settings, resolved from the environment with sensible defaults.
 *
 * @return array<string, mixed>
 */

$env = static function (string $name): ?string {
    $value = getenv($name);

    if (false === $value) {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? null;
    }

    if (null === $value || '' === $value) {
        return null;
    }

    return is_scalar($value) ? (string) $value : null;
};

$bool = static function (?string $value, bool $default): bool {
    if (null === $value) {
        return $default;
    }

    return in_array(strtolower(trim($value)), ['true', '1', 'yes', 'on'], true);
};

$root = dirname(__DIR__);

return [
    'db.path' => $env('DB_PATH') ?? $root . '/data/mockpay.sqlite',
    'db.migrate_on_boot' => $bool($env('DB_MIGRATE_ON_BOOT'), true),
    'db.migrations_dir' => $root . '/database/migrations',
    'auth.username' => $env('AUTH_USERNAME') ?? 'username',
    'auth.password' => $env('AUTH_PASSWORD') ?? 'password',
    'app.debug' => $bool($env('APP_DEBUG'), false),
    'log.level' => $env('LOG_LEVEL') ?? 'info',
    'app.public_url' => $env('PUBLIC_URL') ?? 'http://localhost:8888',
    'callbacks.timeout' => max(1, (int) ($env('CALLBACK_TIMEOUT_SECONDS') ?? '5')),
];
