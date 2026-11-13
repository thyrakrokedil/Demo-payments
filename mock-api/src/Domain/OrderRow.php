<?php

declare(strict_types=1);

namespace MockPay\Domain;

/**
 * @phpstan-type OrderRowInput array{
 *     type: string,
 *     sku: string,
 *     name: string,
 *     reference?: string|null,
 *     quantity: int,
 *     unit_amount: int,
 *     total_amount: int,
 *     vat_amount: int,
 *     vat_rate: int,
 * }
 * @phpstan-type OrderRowArray array{
 *     type: string,
 *     sku: string,
 *     name: string,
 *     reference: string|null,
 *     quantity: int,
 *     unit_amount: int,
 *     total_amount: int,
 *     vat_amount: int,
 *     vat_rate: int,
 * }
 */
final readonly class OrderRow
{
    public function __construct(
        public OrderRowType $type,
        public string $sku,
        public string $name,
        public ?string $reference,
        public int $quantity,
        public int $unitAmount,
        public int $totalAmount,
        public int $vatAmount,
        public int $vatRate,
    ) {}

    /**
     * Builds an order row from an already validated snake_case array.
     *
     * @param OrderRowInput $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            OrderRowType::from($data['type']),
            $data['sku'],
            $data['name'],
            $data['reference'] ?? null,
            $data['quantity'],
            $data['unit_amount'],
            $data['total_amount'],
            $data['vat_amount'],
            $data['vat_rate'],
        );
    }

    /**
     * @return OrderRowArray
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'sku' => $this->sku,
            'name' => $this->name,
            'reference' => $this->reference,
            'quantity' => $this->quantity,
            'unit_amount' => $this->unitAmount,
            'total_amount' => $this->totalAmount,
            'vat_amount' => $this->vatAmount,
            'vat_rate' => $this->vatRate,
        ];
    }
}
