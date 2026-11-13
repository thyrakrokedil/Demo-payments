<?php

declare(strict_types=1);

namespace MockPay\Tests\Unit\Infrastructure;

use DateTimeImmutable;
use MockPay\Domain\CallbackAttempt;
use MockPay\Infrastructure\Persistence\PdoCallbackAttemptRepository;
use MockPay\Infrastructure\Persistence\PdoFactory;
use MockPay\Infrastructure\Persistence\SqliteMigrator;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PdoCallbackAttemptRepository::class)]
final class PdoCallbackAttemptRepositoryTest extends TestCase
{
    private PDO $pdo;

    private PdoCallbackAttemptRepository $repository;

    protected function setUp(): void
    {
        $this->pdo = PdoFactory::create(':memory:');
        (new SqliteMigrator($this->pdo, \dirname(__DIR__, 3) . '/database/migrations'))->migrate();
        $this->repository = new PdoCallbackAttemptRepository($this->pdo);

        foreach (['pay_1', 'pay_2'] as $id) {
            $this->pdo->exec(
                'INSERT INTO payments (id, status, currency, order_total, order_vat_amount, billing_address, '
                . "shipping_address, order_rows, created_at, updated_at) VALUES ('" . $id . "', 'created', 'SEK', "
                . "0, 0, '{}', '{}', '[]', '2026-01-01T00:00:00+00:00', '2026-01-01T00:00:00+00:00')",
            );
        }
    }

    public function testAttemptsRoundTripPerPaymentOldestFirst(): void
    {
        $first = $this->attempt('evt_b', 'pay_1', '2026-01-02T03:04:05+00:00', 200, null);
        $second = $this->attempt('evt_a', 'pay_1', '2026-01-02T03:04:05+00:00', null, 'Connection refused');
        $third = $this->attempt('evt_c', 'pay_1', '2026-01-02T03:04:06+00:00', 500, null);
        $other = $this->attempt('evt_d', 'pay_2', '2026-01-02T03:04:04+00:00', 200, null);

        foreach ([$first, $second, $third, $other] as $attempt) {
            $this->repository->add($attempt);
        }

        $loaded = $this->repository->forPayment('pay_1');

        // Same timestamp keeps insertion order rather than id order.
        self::assertSame(['evt_b', 'evt_a', 'evt_c'], array_map(static fn(CallbackAttempt $a): string => $a->id, $loaded));
        self::assertSame($first->toArray(), $loaded[0]->toArray());
        self::assertSame($second->toArray(), $loaded[1]->toArray());
        self::assertSame([], $this->repository->forPayment('pay_missing'));
    }

    public function testAnAttemptMustBelongToAnExistingPayment(): void
    {
        $this->expectException(PDOException::class);

        $this->repository->add($this->attempt('evt_x', 'pay_missing', '2026-01-02T03:04:05+00:00', 200, null));
    }

    private function attempt(string $id, string $paymentId, string $at, ?int $status, ?string $error): CallbackAttempt
    {
        return new CallbackAttempt(
            $id,
            $paymentId,
            'payment.activated',
            'https://shop.example/notify',
            $status,
            $error,
            42,
            new DateTimeImmutable($at),
        );
    }
}
