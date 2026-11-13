<?php

declare(strict_types=1);

namespace MockPay\Infrastructure\Persistence;

use PDO;
use RuntimeException;

/**
 * Builds the single PDO connection used by the migrator and the repository.
 */
final class PdoFactory
{
    public static function create(string $path): PDO
    {
        if ($path !== ':memory:') {
            self::ensureDirectory($path);
        }

        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_STRINGIFY_FETCHES => false,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        $pdo->exec('PRAGMA foreign_keys = ON');

        if ($path !== ':memory:') {
            $pdo->exec('PRAGMA journal_mode = WAL');
            $pdo->exec('PRAGMA busy_timeout = 5000');
        }

        return $pdo;
    }

    private static function ensureDirectory(string $path): void
    {
        $directory = \dirname($path);

        if (is_dir($directory)) {
            return;
        }

        if (! mkdir($directory, 0o777, true) && ! is_dir($directory)) {
            throw new RuntimeException(\sprintf('Unable to create database directory "%s".', $directory));
        }
    }
}
