<?php

declare(strict_types=1);

namespace MockPay\Tests\Unit\Validation;

use MockPay\Tests\Support\PaymentPayloadBuilder;
use MockPay\Validation\RefundPayloadValidator;
use MockPay\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RefundPayloadValidatorTest extends TestCase
{
    private RefundPayloadValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new RefundPayloadValidator();
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function emptyBodies(): iterable
    {
        yield 'null' => [null];
        yield 'empty array' => [[]];
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function invalidAmounts(): iterable
    {
        yield 'zero' => [0, 'Must be at least 1.'];
        yield 'negative' => [-500, 'Must be at least 1.'];
        yield 'float' => [500.0, 'Must be an integer.'];
        yield 'numeric string' => ['500', 'Must be an integer.'];
        yield 'bool' => [true, 'Must be an integer.'];
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function nonObjectBodies(): iterable
    {
        yield 'string' => ['{}'];
        yield 'int' => [42];
        yield 'list' => [[['amount' => 1]]];
    }

    #[Test]
    #[DataProvider('emptyBodies')]
    public function an_empty_body_means_a_full_refund(mixed $body): void
    {
        self::assertSame(
            ['amount' => null, 'reference' => null, 'order_rows' => null],
            $this->validator->validate($body),
        );
    }

    #[Test]
    public function it_accepts_an_amount_only(): void
    {
        $normalized = $this->validator->validate(['amount' => 4900]);

        self::assertSame(4900, $normalized['amount']);
        self::assertNull($normalized['reference']);
        self::assertNull($normalized['order_rows']);
    }

    #[Test]
    public function it_treats_an_explicit_null_amount_as_absent(): void
    {
        $normalized = $this->validator->validate(['amount' => null, 'reference' => 'RMA-1']);

        self::assertNull($normalized['amount']);
        self::assertSame('RMA-1', $normalized['reference']);
    }

    #[Test]
    #[DataProvider('invalidAmounts')]
    public function it_rejects_invalid_amounts(mixed $amount, string $message): void
    {
        $errors = $this->errorsFor(['amount' => $amount]);

        self::assertSame($message, $errors['amount']);
    }

    #[Test]
    public function it_rejects_a_too_long_reference(): void
    {
        $errors = $this->errorsFor(['reference' => str_repeat('r', 256)]);

        self::assertSame('Must be at most 255 characters.', $errors['reference']);
    }

    #[Test]
    public function it_accepts_order_rows_whose_sum_matches_the_amount(): void
    {
        $rows = [
            PaymentPayloadBuilder::validRow(['quantity' => 2, 'unit_amount' => 10000, 'total_amount' => 20000, 'vat_amount' => 4000]),
        ];

        $normalized = $this->validator->validate(['amount' => 20000, 'order_rows' => $rows]);

        self::assertSame(20000, $normalized['amount']);
        self::assertNotNull($normalized['order_rows']);
        self::assertCount(1, $normalized['order_rows']);
        self::assertSame('SKU-001', $normalized['order_rows'][0]['sku']);
    }

    #[Test]
    public function it_rejects_order_rows_that_do_not_sum_to_the_amount(): void
    {
        $rows = [PaymentPayloadBuilder::validRow()];

        $errors = $this->errorsFor(['amount' => 4900, 'order_rows' => $rows]);

        self::assertSame('Must equal the sum of order_rows.total_amount (10000).', $errors['amount']);
    }

    #[Test]
    public function it_propagates_row_level_errors(): void
    {
        $rows = [PaymentPayloadBuilder::validRow(['sku' => ''])];

        $errors = $this->errorsFor(['amount' => 10000, 'order_rows' => $rows]);

        self::assertSame('Must be between 1 and 255 characters.', $errors['order_rows[0].sku']);
        self::assertArrayNotHasKey('amount', $errors);
    }

    #[Test]
    public function it_rejects_an_empty_order_rows_array(): void
    {
        $errors = $this->errorsFor(['order_rows' => []]);

        self::assertSame('Must be a non-empty array.', $errors['order_rows']);
    }

    #[Test]
    public function it_leaves_the_amount_null_when_only_order_rows_are_given(): void
    {
        $rows = [PaymentPayloadBuilder::validRow()];

        $normalized = $this->validator->validate(['order_rows' => $rows]);

        self::assertNull($normalized['amount']);
        self::assertNotNull($normalized['order_rows']);
        self::assertCount(1, $normalized['order_rows']);
    }

    #[Test]
    public function it_drops_unknown_fields(): void
    {
        $normalized = $this->validator->validate(['amount' => 100, 'hacker' => 'yes']);

        self::assertSame(['amount', 'reference', 'order_rows'], array_keys($normalized));
    }

    #[Test]
    #[DataProvider('nonObjectBodies')]
    public function it_rejects_a_non_object_body(mixed $body): void
    {
        self::assertSame(['body' => 'Must be a JSON object.'], $this->errorsFor($body));
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
