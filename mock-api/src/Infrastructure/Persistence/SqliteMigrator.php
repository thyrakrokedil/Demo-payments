<?php

declare(strict_types=1);

namespace MockPay\Infrastructure\Persistence;

use PDO;
use RuntimeException;
use Throwable;

/**
 * Applies the plain SQL files in database/migrations, tracking them in schema_migrations.
 */
final class SqliteMigrator
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $migrationsDir,
    ) {}

    /**
     * Applies every migration not yet recorded.
     *
     * @return list<string> the versions applied by this run
     */
    public function migrate(): array
    {
        $this->createMigrationsTable();

        $applied = $this->appliedVersions();
        $newlyApplied = [];

        foreach ($this->availableMigrations() as $version => $file) {
            if (\in_array($version, $applied, true)) {
                continue;
            }

            $sql = file_get_contents($file);

            if ($sql === false) {
                throw new RuntimeException(\sprintf('Unable to read migration file "%s".', $file));
            }

            $this->pdo->beginTransaction();

            try {
                $this->pdo->exec($sql);

                $statement = $this->pdo->prepare(
                    'INSERT INTO schema_migrations (version, applied_at) VALUES (:version, :applied_at)',
                );
                $statement->execute([
                    'version' => $version,
                    'applied_at' => gmdate(DATE_ATOM),
                ]);

                $this->pdo->commit();
            } catch (Throwable $exception) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }

                throw $exception;
            }

            $newlyApplied[] = $version;
        }

        return $newlyApplied;
    }

    /**
     * @return list<string>
     */
    public function appliedVersions(): array
    {
        $this->createMigrationsTable();

        $statement = $this->pdo->query('SELECT version FROM schema_migrations ORDER BY version ASC');

        if ($statement === false) {
            return [];
        }

        /** @var list<string> $versions */
        $versions = $statement->fetchAll(PDO::FETCH_COLUMN);

        return $versions;
    }

    private function createMigrationsTable(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations ('
            . 'version TEXT PRIMARY KEY, '
            . 'applied_at TEXT NOT NULL)',
        );
    }

    /**
     * @return array<string, string> version => absolute file path, sorted by version
     */
    private function availableMigrations(): array
    {
        if (! is_dir($this->migrationsDir)) {
            throw new RuntimeException(
                \sprintf('Migrations directory "%s" does not exist.', $this->migrationsDir),
            );
        }

        $files = glob(rtrim($this->migrationsDir, '/\\') . \DIRECTORY_SEPARATOR . '*.sql');

        if ($files === false) {
            return [];
        }

        sort($files, \SORT_STRING);

        $migrations = [];

        foreach ($files as $file) {
            $migrations[pathinfo($file, \PATHINFO_FILENAME)] = $file;
        }

        return $migrations;
    }
}
