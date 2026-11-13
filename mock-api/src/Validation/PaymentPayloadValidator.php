<?php

declare(strict_types=1);

namespace MockPay\Validation;

/**
 * Validates and normalizes a create/update payment payload. Unknown fields are dropped.
 *
 * @phpstan-import-type NormalizedAddress from AddressValidator
 * @phpstan-import-type NormalizedOrderRow from OrderRowsValidator
 * @phpstan-import-type NormalizedMerchantUrls from MerchantUrlsValidator
 *
 * @phpstan-type NormalizedPayment array{
 *     currency: string,
 *     merchant_reference: string|null,
 *     merchant_reference2: string|null,
 *     order_total: int,
 *     order_vat_amount: int,
 *     billing_address: NormalizedAddress,
 *     shipping_address: NormalizedAddress,
 *     order_rows: list<NormalizedOrderRow>,
 *     merchant_urls: NormalizedMerchantUrls|null
 * }
 */
final class PaymentPayloadValidator
{
    public function __construct(
        private readonly AddressValidator $addressValidator = new AddressValidator(),
        private readonly OrderRowsValidator $orderRowsValidator = new OrderRowsValidator(),
        private readonly MerchantUrlsValidator $merchantUrlsValidator = new MerchantUrlsValidator(),
    ) {}

    /**
     * @return NormalizedPayment
     *
     * @throws ValidationException
     */
    public function validate(mixed $body): array
    {
        if (!\is_array($body) || (([] !== $body) && array_is_list($body))) {
            throw ValidationException::single('body', 'Must be a JSON object.');
        }

        $rules = new Rules();

        $rules->regex(
            'currency',
            $body['currency'] ?? null,
            '/^[A-Z]{3}$/',
            'Must be a valid ISO 4217 currency code (e.g. SEK).',
        );
        $rules->optionalString('merchant_reference', $body['merchant_reference'] ?? null);
        $rules->optionalString('merchant_reference2', $body['merchant_reference2'] ?? null);

        $orderTotal = $body['order_total'] ?? null;
        $orderTotalValid = $rules->intMin('order_total', $orderTotal, 0);

        $orderVatAmount = $body['order_vat_amount'] ?? null;
        $orderVatAmountValid = $rules->intMin('order_vat_amount', $orderVatAmount, 0);

        $this->addressValidator->validate('billing_address', $body['billing_address'] ?? null, $rules);
        $this->addressValidator->validate('shipping_address', $body['shipping_address'] ?? null, $rules);

        $errorsBeforeRows = $rules->count();
        $rows = $body['order_rows'] ?? null;
        $this->orderRowsValidator->validate('order_rows', $rows, $rules);
        $rowsValid = $rules->count() === $errorsBeforeRows;

        if ($rowsValid && \is_array($rows)) {
            $sums = OrderRowsValidator::sumTotals($rows);

            if ($orderTotalValid && $sums['total'] !== $orderTotal) {
                $rules->add(
                    'order_total',
                    \sprintf('Must equal the sum of order_rows.total_amount (%d).', $sums['total']),
                );
            }

            if ($orderVatAmountValid && $sums['vat'] !== $orderVatAmount) {
                $rules->add(
                    'order_vat_amount',
                    \sprintf('Must equal the sum of order_rows.vat_amount (%d).', $sums['vat']),
                );
            }
        }

        $this->merchantUrlsValidator->validate('merchant_urls', $body['merchant_urls'] ?? null, $rules);

        $rules->throwIfInvalid();

        /** @var array<array-key, mixed> $billing */
        $billing = $body['billing_address'];
        /** @var array<array-key, mixed> $shipping */
        $shipping = $body['shipping_address'];
        /** @var array<array-key, mixed> $validRows */
        $validRows = $rows;

        return [
            'currency' => Rules::asString($body['currency'] ?? null),
            'merchant_reference' => Rules::asNullableString($body['merchant_reference'] ?? null),
            'merchant_reference2' => Rules::asNullableString($body['merchant_reference2'] ?? null),
            'order_total' => Rules::asInt($orderTotal),
            'order_vat_amount' => Rules::asInt($orderVatAmount),
            'billing_address' => AddressValidator::normalize($billing),
            'shipping_address' => AddressValidator::normalize($shipping),
            'order_rows' => OrderRowsValidator::normalize($validRows),
            'merchant_urls' => MerchantUrlsValidator::normalize($body['merchant_urls'] ?? null),
        ];
    }
}
