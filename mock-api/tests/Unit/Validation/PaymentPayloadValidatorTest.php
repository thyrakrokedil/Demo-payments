<?php

declare(strict_types=1);

namespace MockPay\Tests\Unit\Validation;

use MockPay\Tests\Support\PaymentPayloadBuilder;
use MockPay\Validation\PaymentPayloadValidator;
use MockPay\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PaymentPayloadValidatorTest extends TestCase
{
    private PaymentPayloadValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new PaymentPayloadValidator();
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function nonObjectBodies(): iterable
    {
        yield 'null' => [null];
        yield 'string' => ['{}'];
        yield 'int' => [42];
        yield 'list' => [[1, 2, 3]];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function requiredTopLevelFields(): iterable
    {
        $fields = ['currency', 'order_total', 'order_vat_amount', 'billing_address', 'shipping_address', 'order_rows'];

        foreach ($fields as $field) {
            yield $field => [$field];
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidCurrencies(): iterable
    {
        yield 'lowercase' => ['sek'];
        yield 'two letters' => ['SE'];
        yield 'four letters' => ['SEKK'];
        yield 'digits' => ['123'];
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function nonIntegerAmounts(): iterable
    {
        yield 'float' => [249.00];
        yield 'numeric string' => ['24900'];
        yield 'bool' => [true];
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function requiredAddressFields(): iterable
    {
        $fields = ['first_name', 'last_name', 'street_address', 'postal_code', 'city', 'country', 'email', 'phone'];

        foreach (['billing_address', 'shipping_address'] as $address) {
            foreach ($fields as $field) {
                yield $address . '.' . $field => [$address, $field];
            }
        }
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidOrderRowCollections(): iterable
    {
        yield 'empty' => [[]];
        yield 'not an array' => ['rows'];
        yield 'assoc array' => [['a' => ['type' => 'physical']]];
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function outOfRangeVatRates(): iterable
    {
        yield 'too high' => [10001];
        yield 'negative' => [-1];
    }

    #[Test]
    public function it_returns_the_normalized_payload_for_a_valid_body(): void
    {
        $payload = PaymentPayloadBuilder::valid()->build();

        $normalized = $this->validator->validate($payload);

        self::assertSame('SEK', $normalized['currency']);
        self::assertSame('1001', $normalized['merchant_reference']);
        self::assertSame(24900, $normalized['order_total']);
        self::assertSame(4980, $normalized['order_vat_amount']);
        self::assertSame('anna@example.com', $normalized['billing_address']['email']);
        self::assertCount(2, $normalized['order_rows']);
        self::assertSame('SHIP', $normalized['order_rows'][1]['sku']);
    }

    #[Test]
    public function it_drops_unknown_fields_and_fills_absent_optionals_with_null(): void
    {
        $payload = PaymentPayloadBuilder::valid()
            ->with('hacker', 'yes')
            ->without('merchant_reference2')
            ->withBillingField('unknown_field', 'x')
            ->withoutBillingField('company')
            ->withoutRowField(0, 'reference')
            ->build();

        $normalized = $this->validator->validate($payload);

        self::assertArrayNotHasKey('hacker', $normalized);
        self::assertArrayNotHasKey('unknown_field', $normalized['billing_address']);
        self::assertNull($normalized['merchant_reference2']);
        self::assertNull($normalized['billing_address']['company']);
        self::assertNull($normalized['order_rows'][0]['reference']);
        self::assertNull($normalized['merchant_urls']);
        self::assertSame(
            [
                'currency',
                'merchant_reference',
                'merchant_reference2',
                'order_total',
                'order_vat_amount',
                'billing_address',
                'shipping_address',
                'order_rows',
                'merchant_urls',
            ],
            array_keys($normalized),
        );
    }

    #[Test]
    #[DataProvider('nonObjectBodies')]
    public function it_rejects_a_non_object_body(mixed $body): void
    {
        self::assertSame(['body' => 'Must be a JSON object.'], $this->errorsFor($body));
    }

    #[Test]
    #[DataProvider('requiredTopLevelFields')]
    public function it_requires_every_mandatory_top_level_field(string $field): void
    {
        $errors = $this->errorsFor(PaymentPayloadBuilder::valid()->without($field)->build());

        self::assertArrayHasKey($field, $errors);
    }

    #[Test]
    #[DataProvider('invalidCurrencies')]
    public function it_rejects_malformed_currency_codes(string $currency): void
    {
        $errors = $this->errorsFor(PaymentPayloadBuilder::valid()->withCurrency($currency)->build());

        self::assertSame('Must be a valid ISO 4217 currency code (e.g. SEK).', $errors['currency']);
    }

    #[Test]
    public function it_rejects_a_too_long_merchant_reference(): void
    {
        $errors = $this->errorsFor(
            PaymentPayloadBuilder::valid()->withMerchantReference(str_repeat('a', 256))->build(),
        );

        self::assertSame('Must be at most 255 characters.', $errors['merchant_reference']);
    }

    #[Test]
    public function it_rejects_a_non_string_merchant_reference(): void
    {
        $errors = $this->errorsFor(PaymentPayloadBuilder::valid()->with('merchant_reference', 1001)->build());

        self::assertSame('Must be a string.', $errors['merchant_reference']);
    }

    #[Test]
    #[DataProvider('nonIntegerAmounts')]
    public function it_requires_strict_integers_for_order_total(mixed $value): void
    {
        $errors = $this->errorsFor(PaymentPayloadBuilder::valid()->with('order_total', $value)->build());

        self::assertSame('Must be an integer.', $errors['order_total']);
    }

    #[Test]
    public function it_rejects_a_negative_order_total(): void
    {
        $errors = $this->errorsFor(PaymentPayloadBuilder::valid()->withOrderTotal(-1)->build());

        self::assertSame('Must be at least 0.', $errors['order_total']);
    }

    #[Test]
    #[DataProvider('requiredAddressFields')]
    public function it_requires_every_mandatory_address_field(string $address, string $field): void
    {
        $builder = PaymentPayloadBuilder::valid();
        $builder = 'billing_address' === $address
            ? $builder->withoutBillingField($field)
            : $builder->withoutShippingField($field);

        $errors = $this->errorsFor($builder->build());

        self::assertSame('Is required.', $errors[$address . '.' . $field] ?? null);
    }

    #[Test]
    public function it_rejects_an_invalid_email(): void
    {
        $errors = $this->errorsFor(
            PaymentPayloadBuilder::valid()->withShippingField('email', 'not-an-email')->build(),
        );

        self::assertSame('Must be a valid email address.', $errors['shipping_address.email']);
    }

    #[Test]
    public function it_rejects_a_lowercase_country(): void
    {
        $errors = $this->errorsFor(PaymentPayloadBuilder::valid()->withBillingField('country', 'se')->build());

        self::assertSame(
            'Must be a valid ISO 3166-1 alpha-2 country code (e.g. SE).',
            $errors['billing_address.country'],
        );
    }

    #[Test]
    public function it_rejects_a_too_long_postal_code(): void
    {
        $errors = $this->errorsFor(
            PaymentPayloadBuilder::valid()->withBillingField('postal_code', str_repeat('1', 33))->build(),
        );

        self::assertSame('Must be between 1 and 32 characters.', $errors['billing_address.postal_code']);
    }

    #[Test]
    public function it_rejects_an_address_that_is_not_an_object(): void
    {
        $errors = $this->errorsFor(PaymentPayloadBuilder::valid()->with('billing_address', 'Storgatan 1')->build());

        self::assertSame('Must be an object.', $errors['billing_address']);
    }

    #[Test]
    #[DataProvider('invalidOrderRowCollections')]
    public function it_requires_a_non_empty_list_of_order_rows(mixed $rows): void
    {
        $errors = $this->errorsFor(PaymentPayloadBuilder::valid()->with('order_rows', $rows)->build());

        self::assertSame('Must be a non-empty array.', $errors['order_rows']);
    }

    #[Test]
    public function it_rejects_a_row_that_is_not_an_object(): void
    {
        $errors = $this->errorsFor(PaymentPayloadBuilder::valid()->withRows(['nope'])->build());

        self::assertSame('Must be an object.', $errors['order_rows[0]']);
    }

    #[Test]
    public function it_rejects_an_unknown_row_type(): void
    {
        $errors = $this->errorsFor(PaymentPayloadBuilder::valid()->withRowField(0, 'type', 'gift_card')->build());

        self::assertSame(
            'Must be one of: physical, digital, shipping, fee, discount.',
            $errors['order_rows[0].type'],
        );
    }

    #[Test]
    public function it_rejects_a_zero_quantity(): void
    {
        $errors = $this->errorsFor(PaymentPayloadBuilder::valid()->withRowField(0, 'quantity', 0)->build());

        self::assertSame('Must be at least 1.', $errors['order_rows[0].quantity']);
        self::assertArrayNotHasKey('order_rows[0].total_amount', $errors);
    }

    #[Test]
    public function it_rejects_a_float_quantity(): void
    {
        $errors = $this->errorsFor(PaymentPayloadBuilder::valid()->withRowField(0, 'quantity', 2.0)->build());

        self::assertSame('Must be an integer.', $errors['order_rows[0].quantity']);
    }

    #[Test]
    public function it_rejects_a_discount_row_with_a_positive_unit_amount(): void
    {
        $rows = [PaymentPayloadBuilder::discountRow([
            'unit_amount' => 1000,
            'total_amount' => 1000,
            'vat_amount' => 200,
        ])];

        $errors = $this->errorsFor(
            PaymentPayloadBuilder::valid()->withRows($rows)->withOrderTotal(1000)->withOrderVatAmount(200)->build(),
        );

        self::assertSame('Must be zero or negative for discount rows.', $errors['order_rows[0].unit_amount']);
    }

    #[Test]
    public function it_accepts_a_negative_discount_row(): void
    {
        $rows = [PaymentPayloadBuilder::validRow(), PaymentPayloadBuilder::discountRow()];

        $normalized = $this->validator->validate(
            PaymentPayloadBuilder::valid()->withRows($rows)->withOrderTotal(9000)->withOrderVatAmount(1800)->build(),
        );

        self::assertSame(-1000, $normalized['order_rows'][1]['unit_amount']);
    }

    #[Test]
    public function it_rejects_a_non_discount_row_with_a_negative_unit_amount(): void
    {
        $errors = $this->errorsFor(PaymentPayloadBuilder::valid()->withRowField(0, 'unit_amount', -10000)->build());

        self::assertSame('Must be at least 0.', $errors['order_rows[0].unit_amount']);
    }

    #[Test]
    #[DataProvider('outOfRangeVatRates')]
    public function it_rejects_out_of_range_vat_rates(int $vatRate): void
    {
        $errors = $this->errorsFor(PaymentPayloadBuilder::valid()->withRowField(1, 'vat_rate', $vatRate)->build());

        self::assertSame(
            'Must be between 0 and 10000 (percent × 100).',
            $errors['order_rows[1].vat_rate'],
        );
    }

    #[Test]
    public function it_reports_row_math_mismatches_with_the_expected_total(): void
    {
        $errors = $this->errorsFor(PaymentPayloadBuilder::valid()->withRowField(1, 'quantity', 2)->build());

        self::assertSame('Must equal unit_amount * quantity (9800).', $errors['order_rows[1].total_amount']);
    }

    #[Test]
    public function it_reports_an_order_total_that_does_not_match_the_rows(): void
    {
        $errors = $this->errorsFor(PaymentPayloadBuilder::valid()->withOrderTotal(20000)->build());

        self::assertSame('Must equal the sum of order_rows.total_amount (24900).', $errors['order_total']);
    }

    #[Test]
    public function it_reports_an_order_vat_amount_that_does_not_match_the_rows(): void
    {
        $errors = $this->errorsFor(PaymentPayloadBuilder::valid()->withOrderVatAmount(1)->build());

        self::assertSame('Must equal the sum of order_rows.vat_amount (4980).', $errors['order_vat_amount']);
    }

    #[Test]
    public function it_collects_every_error_at_once(): void
    {
        $payload = PaymentPayloadBuilder::valid()
            ->withCurrency('sek')
            ->withBillingField('email', 'nope')
            ->build();

        try {
            $this->validator->validate($payload);
        } catch (ValidationException $exception) {
            self::assertCount(2, $exception->errors());
            self::assertSame('The request contains 2 invalid fields.', $exception->getMessage());
            self::assertSame(
                [
                    ['field' => 'currency', 'message' => 'Must be a valid ISO 4217 currency code (e.g. SEK).'],
                    ['field' => 'billing_address.email', 'message' => 'Must be a valid email address.'],
                ],
                $exception->errorsAsArray(),
            );

            return;
        }

        self::fail('Expected a ValidationException.');
    }

    #[Test]
    public function it_uses_the_singular_form_for_a_single_error(): void
    {
        try {
            $this->validator->validate(PaymentPayloadBuilder::valid()->withCurrency('sek')->build());
        } catch (ValidationException $exception) {
            self::assertSame('The request contains 1 invalid field.', $exception->getMessage());

            return;
        }

        self::fail('Expected a ValidationException.');
    }

    #[Test]
    public function it_skips_the_aggregate_checks_when_a_row_is_invalid(): void
    {
        $errors = $this->errorsFor(PaymentPayloadBuilder::valid()->withRowField(0, 'type', 'gift_card')->build());

        self::assertArrayHasKey('order_rows[0].type', $errors);
        self::assertArrayNotHasKey('order_total', $errors);
        self::assertArrayNotHasKey('order_vat_amount', $errors);
    }

    /**
     * @return array<string, string>
     */
    private function errorsFor(mixed $body): array
    {
        try {
            $this->validator->validate($body);
        } catch (ValidationException $exception) {
            $flat = [];
            foreach ($exception->errors() as $error) {
                $flat[$error->field] ??= $error->message;
            }

            return $flat;
        }

        self::fail('Expected a ValidationException.');
    }
}
