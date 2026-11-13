<?php

declare(strict_types=1);

namespace MockPay\Tests\Unit\Infrastructure;

use MockPay\Infrastructure\Persistence\PdoFactory;
use MockPay\Infrastructure\Persistence\SqliteMigrator;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(SqliteMigrator::class)]
#[CoversClass(PdoFactory::class)]
final class SqliteMigratorTest extends TestCase
{
    private const VERSIONS = ['0001_create_payments_and_refunds', '0002_add_merchant_urls_and_callbacks'];

    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = PdoFactory::create(':memory:');
    }

    public function testMigrateAppliesEveryMigrationAndCreatesTheTables(): void
    {
        $applied = $this->migrator()->migrate();

        self::assertSame(self::VERSIONS, $applied);
        self::assertTrue($this->tableExists('payments'));
        self::assertTrue($this->tableExists('refunds'));
        self::assertTrue($this->tableExists('callback_attempts'));
        self::assertTrue($this->tableExists('schema_migrations'));
    }

    public function testMigrateIsIdempotent(): void
    {
        $migrator = $this->migrator();
        $migrator->migrate();

        self::assertSame([], $migrator->migrate());
        self::assertSame(self::VERSIONS, $migrator->appliedVersions());
    }

    public function testMigration0002KeepsExistingPaymentsAndRefunds(): void
    {
        $dir = sys_get_temp_dir() . '/mockpay-migrations-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $source = \dirname(__DIR__, 3) . '/database/migrations';
        copy($source . '/0001_create_payments_and_refunds.sql', $dir . '/0001_create_payments_and_refunds.sql');

        try {
            (new SqliteMigrator($this->pdo, $dir))->migrate();

            $this->pdo->exec(
                'INSERT INTO payments (id, status, currency, order_total, order_vat_amount, billing_address, '
                . "shipping_address, order_rows, created_at, updated_at) VALUES ('pay_1', 'partially_refunded', "
                . "'SEK', 100, 20, '{}', '{}', '[]', '2026-01-01T00:00:00+00:00', '2026-01-01T00:00:00+00:00')",
            );
            $this->pdo->exec(
                'INSERT INTO refunds (id, payment_id, amount, created_at) '
                . "VALUES ('ref_1', 'pay_1', 40, '2026-01-01T00:00:00+00:00')",
            );

            copy(
                $source . '/0002_add_merchant_urls_and_callbacks.sql',
                $dir . '/0002_add_merchant_urls_and_callbacks.sql',
            );
            (new SqliteMigrator($this->pdo, $dir))->migrate();
        } finally {
            array_map('unlink', glob($dir . '/*.sql') ?: []);
            rmdir($dir);
        }

        self::assertSame('partially_refunded', $this->scalar("SELECT status FROM payments WHERE id = 'pay_1'"));
        self::assertSame(40, $this->scalar("SELECT amount FROM refunds WHERE payment_id = 'pay_1'"));

        // The rebuilt table accepts the new status and keeps the foreign key from refunds.
        $this->pdo->exec("UPDATE payments SET status = 'authorized' WHERE id = 'pay_1'");
        $this->pdo->exec("DELETE FROM payments WHERE id = 'pay_1'");
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM refunds'));
    }

    public function testAppliedVersionsIsEmptyBeforeMigrating(): void
    {
        self::assertSame([], $this->migrator()->appliedVersions());
    }

    public function testMissingMigrationsDirectoryThrows(): void
    {
        $migrator = new SqliteMigrator($this->pdo, __DIR__ . '/does-not-exist');

        $this->expectException(RuntimeException::class);

        $migrator->migrate();
    }

    private function migrator(): SqliteMigrator
    {
        return new SqliteMigrator($this->pdo, \dirname(__DIR__, 3) . '/database/migrations');
    }

    private function scalar(string $sql): mixed
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute();

        return $statement->fetchColumn();
    }

    private function tableExists(string $name): bool
    {
        $statement = $this->pdo->prepare(
            "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = :name",
        );
        $statement->execute(['name' => $name]);

        return (int) $statement->fetchColumn() === 1;
    }
}
