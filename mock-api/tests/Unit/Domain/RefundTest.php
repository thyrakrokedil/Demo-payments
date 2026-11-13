<?php

declare(strict_types=1);

namespace MockPay\Tests\Unit\Domain;

use DateTimeImmutable;
use DateTimeZone;
use MockPay\Domain\OrderRow;
use MockPay\Domain\Refund;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RefundTest extends TestCase
{
    private const string NOW = '2026-01-02T03:04:05+00:00';

    #[Test]
    public function it_exposes_an_iso_8601_utc_created_at_and_null_order_rows(): void
    {
        $refund = new Refund('ref_1', 5000, null, null, new DateTimeImmutable(self::NOW));

        self::assertSame([
            'id' => 'ref_1',
            'amount' => 5000,
            'reference' => null,
            'order_rows' => null,
            'created_at' => self::NOW,
        ], $refund->toArray());
    }

    #[Test]
    public function it_normalises_created_at_to_utc(): void
    {
        $createdAt = new DateTimeImmutable('2026-01-02T04:04:05', new DateTimeZone('+01:00'));
        $refund = new Refund('ref_1', 1, null, null, $createdAt);

        self::assertSame(self::NOW, $refund->toArray()['created_at']);
    }

    #[Test]
    public function it_round_trips_through_arrays_with_order_rows(): void
    {
        $data = [
            'id' => 'ref_1',
            'amount' => 4900,
            'reference' => 'credit-note-1',
            'order_rows' => [
                [
                    'type' => 'shipping',
                    'sku' => 'SHIP',
                    'name' => 'Shipping',
                    'reference' => null,
                    'quantity' => 1,
                    'unit_amount' => 4900,
                    'total_amount' => 4900,
                    'vat_amount' => 980,
                    'vat_rate' => 2500,
                ],
            ],
            'created_at' => self::NOW,
        ];

        $refund = Refund::fromArray($data);

        self::assertSame('ref_1', $refund->id);
        self::assertSame(4900, $refund->amount);
        self::assertSame('credit-note-1', $refund->reference);
        self::assertNotNull($refund->orderRows);
        self::assertContainsOnlyInstancesOf(OrderRow::class, $refund->orderRows);
        self::assertSame(self::NOW, $refund->createdAt->format(DATE_ATOM));

        self::assertSame($data, $refund->toArray());
    }

    #[Test]
    public function it_rehydrates_without_the_optional_keys(): void
    {
        $refund = Refund::fromArray([
            'id' => 'ref_2',
            'amount' => 100,
            'created_at' => self::NOW,
        ]);

        self::assertNull($refund->reference);
        self::assertNull($refund->orderRows);
    }
}
