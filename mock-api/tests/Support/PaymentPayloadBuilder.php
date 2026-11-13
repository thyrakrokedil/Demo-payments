<?php

declare(strict_types=1);

namespace MockPay\Tests\Support;

/**
 * Builds a valid SEK create-payment payload with fluent, non-mutating overrides.
 */
final class PaymentPayloadBuilder
{
    /**
     * @var array<string, mixed>
     */
    private array $payload;

    /**
     * @param array<string, mixed>|null $payload
     */
    private function __construct(?array $payload = null)
    {
        $this->payload = $payload ?? self::defaults();
    }

    public static function valid(): self
    {
        return new self();
    }

    /**
     * @return array<string, mixed>
     */
    public function build(): array
    {
        return $this->payload;
    }

    public function with(string $key, mixed $value): self
    {
        $payload = $this->payload;
        $payload[$key] = $value;

        return new self($payload);
    }

    public function without(string $key): self
    {
        $payload = $this->payload;
        unset($payload[$key]);

        return new self($payload);
    }

    public function withCurrency(string $currency): self
    {
        return $this->with('currency', $currency);
    }

    public function withOrderTotal(int $total): self
    {
        return $this->with('order_total', $total);
    }

    public function withOrderVatAmount(int $vat): self
    {
        return $this->with('order_vat_amount', $vat);
    }

    public function withMerchantReference(?string $reference): self
    {
        return $this->with('merchant_reference', $reference);
    }

    /**
     * Sets every merchant URL, pointing at shop.example, with overrides per key.
     *
     * @param array<string, mixed> $overrides
     */
    public function withMerchantUrls(array $overrides = []): self
    {
        return $this->with('merchant_urls', array_merge(self::merchantUrls(), $overrides));
    }

    /**
     * @return array<string, string>
     */
    public static function merchantUrls(): array
    {
        return [
            'validation' => 'https://shop.example/validate',
            'notification' => 'https://shop.example/notify?payment={{PAYMENT_ID}}',
            'success' => 'https://shop.example/checkout?success={{PAYMENT_ID}}',
            'failed' => 'https://shop.example/checkout?failed={{PAYMENT_ID}}',
            'cancel' => 'https://shop.example/checkout?aborted={{PAYMENT_ID}}',
        ];
    }

    public function withBillingField(string $field, mixed $value): self
    {
        return $this->withAddressField('billing_address', $field, $value);
    }

    public function withShippingField(string $field, mixed $value): self
    {
        return $this->withAddressField('shipping_address', $field, $value);
    }

    public function withoutBillingField(string $field): self
    {
        return $this->withoutAddressField('billing_address', $field);
    }

    public function withoutShippingField(string $field): self
    {
        return $this->withoutAddressField('shipping_address', $field);
    }

    /**
     * @param array<int, mixed> $rows
     */
    public function withRows(array $rows): self
    {
        return $this->with('order_rows', $rows);
    }

    public function withRowField(int $index, string $field, mixed $value): self
    {
        $rows = $this->rows();
        $row = $rows[$index];
        $row[$field] = $value;
        $rows[$index] = $row;

        return $this->withRows($rows);
    }

    public function withoutRowField(int $index, string $field): self
    {
        $rows = $this->rows();
        $row = $rows[$index];
        unset($row[$field]);
        $rows[$index] = $row;

        return $this->withRows($rows);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    public static function validRow(array $overrides = []): array
    {
        return array_merge([
            'type' => 'physical',
            'sku' => 'SKU-001',
            'name' => 'T-shirt',
            'reference' => null,
            'quantity' => 1,
            'unit_amount' => 10000,
            'total_amount' => 10000,
            'vat_amount' => 2000,
            'vat_rate' => 2500,
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    public static function discountRow(array $overrides = []): array
    {
        return array_merge([
            'type' => 'discount',
            'sku' => 'DISCOUNT',
            'name' => 'Campaign discount',
            'reference' => null,
            'quantity' => 1,
            'unit_amount' => -1000,
            'total_amount' => -1000,
            'vat_amount' => -200,
            'vat_rate' => 2500,
        ], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    public static function validAddress(string $firstName = 'Anna', string $lastName = 'Andersson'): array
    {
        return [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'company' => null,
            'street_address' => 'Storgatan 1',
            'street_address2' => null,
            'postal_code' => '11122',
            'city' => 'Stockholm',
            'region' => null,
            'country' => 'SE',
            'email' => 'anna@example.com',
            'phone' => '+46701234567',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function defaults(): array
    {
        return [
            'currency' => 'SEK',
            'merchant_reference' => '1001',
            'merchant_reference2' => 'WC-1001',
            'order_total' => 24900,
            'order_vat_amount' => 4980,
            'billing_address' => self::validAddress(),
            'shipping_address' => self::validAddress(),
            'order_rows' => [
                [
                    'type' => 'physical',
                    'sku' => 'SKU-001',
                    'name' => 'T-shirt',
                    'reference' => null,
                    'quantity' => 2,
                    'unit_amount' => 10000,
                    'total_amount' => 20000,
                    'vat_amount' => 4000,
                    'vat_rate' => 2500,
                ],
                [
                    'type' => 'shipping',
                    'sku' => 'SHIP',
                    'name' => 'Standard shipping',
                    'reference' => null,
                    'quantity' => 1,
                    'unit_amount' => 4900,
                    'total_amount' => 4900,
                    'vat_amount' => 980,
                    'vat_rate' => 2500,
                ],
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rows(): array
    {
        /** @var array<int, array<string, mixed>> $rows */
        $rows = $this->payload['order_rows'];

        return $rows;
    }

    private function withAddressField(string $address, string $field, mixed $value): self
    {
        /** @var array<string, mixed> $current */
        $current = $this->payload[$address];
        $current[$field] = $value;

        return $this->with($address, $current);
    }

    private function withoutAddressField(string $address, string $field): self
    {
        /** @var array<string, mixed> $current */
        $current = $this->payload[$address];
        unset($current[$field]);

        return $this->with($address, $current);
    }
}
