<?php

declare(strict_types=1);

namespace MockPay\Tests\Unit\Domain;

use MockPay\Domain\PaymentDetails;

/**
 * @phpstan-import-type AddressInput from \MockPay\Domain\Address
 * @phpstan-import-type PaymentDetailsInput from PaymentDetails
 *
 * A valid SEK payment fixture: 2 x 10000 physical (vat 4000) + 4900 shipping (vat 980).
 */
trait PaymentDetailsFixture
{
    /**
     * @return AddressInput
     */
    private static function addressArray(string $firstName = 'Ada'): array
    {
        return [
            'first_name' => $firstName,
            'last_name' => 'Lovelace',
            'company' => 'Krokedil AB',
            'street_address' => 'Storgatan 1',
            'street_address2' => 'Apt 4',
            'postal_code' => '11122',
            'city' => 'Stockholm',
            'region' => 'Stockholms lan',
            'country' => 'SE',
            'email' => 'ada@example.com',
            'phone' => '+46701234567',
        ];
    }

    /**
     * @return PaymentDetailsInput
     */
    private static function detailsArray(): array
    {
        return [
            'currency' => 'SEK',
            'merchant_reference' => 'wc-1234',
            'merchant_reference2' => '#1234',
            'order_total' => 24900,
            'order_vat_amount' => 4980,
            'billing_address' => self::addressArray(),
            'shipping_address' => self::addressArray('Grace'),
            'order_rows' => [
                [
                    'type' => 'physical',
                    'sku' => 'SKU-1',
                    'name' => 'A widget',
                    'reference' => 'row-1',
                    'quantity' => 2,
                    'unit_amount' => 10000,
                    'total_amount' => 20000,
                    'vat_amount' => 4000,
                    'vat_rate' => 2500,
                ],
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
            'merchant_urls' => [
                'validation' => 'https://shop.example/validate',
                'notification' => 'https://shop.example/notify?payment={{PAYMENT_ID}}',
                'success' => 'https://shop.example/checkout?success={{PAYMENT_ID}}',
                'failed' => 'https://shop.example/checkout?failed={{PAYMENT_ID}}',
                'cancel' => 'https://shop.example/checkout?aborted={{PAYMENT_ID}}',
            ],
        ];
    }

    private static function details(): PaymentDetails
    {
        return PaymentDetails::fromArray(self::detailsArray());
    }
}
