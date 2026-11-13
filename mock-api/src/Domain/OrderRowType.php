<?php

declare(strict_types=1);

namespace MockPay\Domain;

enum OrderRowType: string
{
    case Physical = 'physical';
    case Digital = 'digital';
    case Shipping = 'shipping';
    case Fee = 'fee';
    case Discount = 'discount';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn(self $type): string => $type->value, self::cases());
    }
}
