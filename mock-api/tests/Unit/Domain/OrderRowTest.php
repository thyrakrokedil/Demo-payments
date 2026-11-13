<?php

declare(strict_types=1);

namespace MockPay\Tests\Unit\Domain;

use MockPay\Domain\OrderRow;
use MockPay\Domain\OrderRowType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class OrderRowTest extends TestCase
{
    #[Test]
    public function it_round_trips_through_arrays(): void
    {
        $data = [
            'type' => 'physical',
            'sku' => 'SKU-1',
            'name' => 'A widget',
            'reference' => 'row-1',
            'quantity' => 2,
            'unit_amount' => 10000,
            'total_amount' => 20000,
            'vat_amount' => 4000,
            'vat_rate' => 2500,
        ];

        $row = OrderRow::fromArray($data);

        self::assertSame(OrderRowType::Physical, $row->type);
        self::assertSame('SKU-1', $row->sku);
        self::assertSame('A widget', $row->name);
        self::assertSame('row-1', $row->reference);
        self::assertSame(2, $row->quantity);
        self::assertSame(10000, $row->unitAmount);
        self::assertSame(20000, $row->totalAmount);
        self::assertSame(4000, $row->vatAmount);
        self::assertSame(2500, $row->vatRate);

        self::assertSame($data, $row->toArray());
    }

    #[Test]
    public function it_defaults_the_reference_to_null_when_missing(): void
    {
        $row = OrderRow::fromArray([
            'type' => 'discount',
            'sku' => 'DISC',
            'name' => 'Discount',
            'quantity' => 1,
            'unit_amount' => -5000,
            'total_amount' => -5000,
            'vat_amount' => -1000,
            'vat_rate' => 2500,
        ]);

        self::assertSame(OrderRowType::Discount, $row->type);
        self::assertNull($row->reference);

        $array = $row->toArray();
        self::assertArrayHasKey('reference', $array);
        self::assertNull($array['reference']);
    }
}
