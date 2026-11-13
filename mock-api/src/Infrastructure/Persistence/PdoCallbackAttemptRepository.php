<?php

declare(strict_types=1);

namespace MockPay\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use MockPay\Domain\CallbackAttempt;
use MockPay\Domain\CallbackAttemptRepository;
use PDO;

/**
 * SQLite backed log of callback deliveries, one row per attempt.
 */
final class PdoCallbackAttemptRepository implements CallbackAttemptRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function add(CallbackAttempt $attempt): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO callback_attempts '
            . '(id, payment_id, event, url, response_status, error, duration_ms, created_at) VALUES '
            . '(:id, :payment_id, :event, :url, :response_status, :error, :duration_ms, :created_at)',
        );

        $statement->execute([
            'id' => $attempt->id,
            'payment_id' => $attempt->paymentId,
            'event' => $attempt->event,
            'url' => $attempt->url,
            'response_status' => $attempt->responseStatus,
            'error' => $attempt->error,
            'duration_ms' => $attempt->durationMs,
            'created_at' => $attempt->createdAt->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM),
        ]);
    }

    public function forPayment(string $paymentId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, payment_id, event, url, response_status, error, duration_ms, created_at '
            . 'FROM callback_attempts WHERE payment_id = :payment_id ORDER BY created_at ASC, rowid ASC',
        );
        $statement->execute(['payment_id' => $paymentId]);

        /** @var list<array<string, mixed>> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return array_map(
            static fn(array $row): CallbackAttempt => new CallbackAttempt(
                (string) $row['id'],
                (string) $row['payment_id'],
                (string) $row['event'],
                (string) $row['url'],
                $row['response_status'] === null ? null : (int) $row['response_status'],
                $row['error'] === null ? null : (string) $row['error'],
                (int) $row['duration_ms'],
                new DateTimeImmutable((string) $row['created_at']),
            ),
            $rows,
        );
    }
}
