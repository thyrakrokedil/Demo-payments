<?php

declare(strict_types=1);

namespace MockPay\Domain;

/**
 * The mutable content of a payment: everything a create or update request carries.
 *
 * @phpstan-import-type AddressInput from Address
 * @phpstan-import-type AddressArray from Address
 * @phpstan-import-type OrderRowInput from OrderRow
 * @phpstan-import-type OrderRowArray from OrderRow
 * @phpstan-import-type MerchantUrlsInput from MerchantUrls
 * @phpstan-import-type MerchantUrlsArray from MerchantUrls
 *
 * @phpstan-type PaymentDetailsInput array{
 *     currency: string,
 *     merchant_reference?: string|null,
 *     merchant_reference2?: string|null,
 *     order_total: int,
 *     order_vat_amount: int,
 *     billing_address: AddressInput,
 *     shipping_address: AddressInput,
 *     order_rows: list<OrderRowInput>,
 *     merchant_urls?: MerchantUrlsInput|null,
 * }
 * @phpstan-type PaymentDetailsArray array{
 *     currency: string,
 *     merchant_reference: string|null,
 *     merchant_reference2: string|null,
 *     order_total: int,
 *     order_vat_amount: int,
 *     billing_address: AddressArray,
 *     shipping_address: AddressArray,
 *     order_rows: list<OrderRowArray>,
 *     merchant_urls: MerchantUrlsArray|null,
 * }
 */
final readonly class PaymentDetails
{
    /**
     * @param list<OrderRow> $orderRows
     */
    public function __construct(
        public string $currency,
        public ?string $merchantReference,
        public ?string $merchantReference2,
        public int $orderTotal,
        public int $orderVatAmount,
        public Address $billingAddress,
        public Address $shippingAddress,
        public array $orderRows,
        public ?MerchantUrls $merchantUrls = null,
    ) {}

    /**
     * Builds the details from an already validated snake_case array.
     *
     * @param PaymentDetailsInput $data
     */
    public static function fromArray(array $data): self
    {
        $merchantUrls = $data['merchant_urls'] ?? null;

        return new self(
            $data['currency'],
            $data['merchant_reference'] ?? null,
            $data['merchant_reference2'] ?? null,
            $data['order_total'],
            $data['order_vat_amount'],
            Address::fromArray($data['billing_address']),
            Address::fromArray($data['shipping_address']),
            array_map(
                static fn(array $row): OrderRow => OrderRow::fromArray($row),
                $data['order_rows'],
            ),
            $merchantUrls === null ? null : MerchantUrls::fromArray($merchantUrls),
        );
    }

    /**
     * @return PaymentDetailsArray
     */
    public function toArray(): array
    {
        return [
            'currency' => $this->currency,
            'merchant_reference' => $this->merchantReference,
            'merchant_reference2' => $this->merchantReference2,
            'order_total' => $this->orderTotal,
            'order_vat_amount' => $this->orderVatAmount,
            'billing_address' => $this->billingAddress->toArray(),
            'shipping_address' => $this->shippingAddress->toArray(),
            'order_rows' => array_map(
                static fn(OrderRow $row): array => $row->toArray(),
                $this->orderRows,
            ),
            'merchant_urls' => $this->merchantUrls?->toArray(),
        ];
    }
}
