<?php

declare(strict_types=1);

namespace MockPay\Domain;

use DateTimeImmutable;
use DateTimeZone;

/**
 * @phpstan-import-type OrderRowInput from OrderRow
 * @phpstan-import-type OrderRowArray from OrderRow
 *
 * @phpstan-type RefundArray array{
 *     id: string,
 *     amount: int,
 *     reference: string|null,
 *     order_rows: list<OrderRowArray>|null,
 *     created_at: string,
 * }
 */
final readonly class Refund
{
    /**
     * @param list<OrderRow>|null $orderRows
     */
    public function __construct(
        public string $id,
        public int $amount,
        public ?string $reference,
        public ?array $orderRows,
        public DateTimeImmutable $createdAt,
    ) {}

    /**
     * Rehydrates a refund from storage.
     *
     * @param array{
     *     id: string,
     *     amount: int,
     *     reference?: string|null,
     *     order_rows?: list<OrderRowInput>|null,
     *     created_at: string,
     * } $data
     */
    public static function fromArray(array $data): self
    {
        $rows = $data['order_rows'] ?? null;

        return new self(
            $data['id'],
            $data['amount'],
            $data['reference'] ?? null,
            $rows === null
                ? null
                : array_map(static fn(array $row): OrderRow => OrderRow::fromArray($row), $rows),
            new DateTimeImmutable($data['created_at']),
        );
    }

    /**
     * @return RefundArray
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'amount' => $this->amount,
            'reference' => $this->reference,
            'order_rows' => $this->orderRows === null
                ? null
                : array_map(static fn(OrderRow $row): array => $row->toArray(), $this->orderRows),
            'created_at' => $this->createdAt->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM),
        ];
    }
}
