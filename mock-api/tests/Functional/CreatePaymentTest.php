<?php

declare(strict_types=1);

namespace MockPay\Tests\Functional;

use MockPay\Tests\Support\AppTestCase;
use MockPay\Tests\Support\PaymentPayloadBuilder;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversNothing]
final class CreatePaymentTest extends AppTestCase
{
    public function testValidPayloadIsCreated(): void
    {
        $response = $this->request('POST', '/payments', PaymentPayloadBuilder::valid()->build());

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        self::assertStringStartsWith('application/json', $response->getHeaderLine('Content-Type'));

        $body = $this->decode($response);

        self::assertIsString($body['id']);
        self::assertMatchesRegularExpression('/^pay_[0-9a-z]{24}$/', $body['id']);
        self::assertSame('/payments/' . $body['id'], $response->getHeaderLine('Location'));
        self::assertSame('created', $body['status']);
    }

    public function testNewPaymentHasNoCapturedOrRefundedAmounts(): void
    {
        $body = $this->createPayment();

        self::assertSame(0, $body['captured_amount']);
        self::assertSame(0, $body['refunded_amount']);
        self::assertSame(0, $body['remaining_refundable_amount']);
        self::assertSame([], $body['refunds']);
    }

    public function testTimestampsComeFromTheClock(): void
    {
        $body = $this->createPayment();

        self::assertSame('2026-01-02T03:04:05+00:00', $body['created_at']);
        self::assertSame('2026-01-02T03:04:05+00:00', $body['updated_at']);
    }

    public function testEveryRequestFieldIsEchoedBack(): void
    {
        $payload = PaymentPayloadBuilder::valid()->build();

        $body = $this->createPayment($payload);

        self::assertSame('SEK', $body['currency']);
        self::assertSame('1001', $body['merchant_reference']);
        self::assertSame('WC-1001', $body['merchant_reference2']);
        self::assertSame(24900, $body['order_total']);
        self::assertSame(4980, $body['order_vat_amount']);
        self::assertSame($payload['billing_address'], $body['billing_address']);
        self::assertSame($payload['shipping_address'], $body['shipping_address']);
        self::assertSame($payload['order_rows'], $body['order_rows']);
    }

    public function testOmittedOptionalKeysComeBackAsNull(): void
    {
        $payload = PaymentPayloadBuilder::valid()
            ->without('merchant_reference2')
            ->withoutBillingField('company')
            ->withoutBillingField('region')
            ->withoutRowField(0, 'reference')
            ->build();

        $body = $this->createPayment($payload);

        self::assertNull($body['merchant_reference2']);

        /** @var array<string, mixed> $billing */
        $billing = $body['billing_address'];
        self::assertArrayHasKey('company', $billing);
        self::assertNull($billing['company']);
        self::assertArrayHasKey('region', $billing);
        self::assertNull($billing['region']);
        self::assertArrayHasKey('street_address2', $billing);
        self::assertNull($billing['street_address2']);

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $body['order_rows'];
        self::assertArrayHasKey('reference', $rows[0]);
        self::assertNull($rows[0]['reference']);
    }

    public function testUnknownTopLevelFieldsAreDropped(): void
    {
        $body = $this->createPayment(
            PaymentPayloadBuilder::valid()->with('surprise', 'yes')->build(),
        );

        self::assertArrayNotHasKey('surprise', $body);
    }

    public function testOrderTotalMustMatchTheRowSum(): void
    {
        $response = $this->request(
            'POST',
            '/payments',
            PaymentPayloadBuilder::valid()->withOrderTotal(1)->build(),
        );

        $body = $this->assertProblem($response, 422, 'validation-error');

        self::assertSame('The request contains 1 invalid field.', $body['detail']);

        /** @var array<int, array<string, string>> $errors */
        $errors = $body['errors'];
        self::assertCount(1, $errors);
        self::assertSame('order_total', $errors[0]['field']);
        self::assertSame(
            'Must equal the sum of order_rows.total_amount (24900).',
            $errors[0]['message'],
        );
    }

    public function testEveryInvalidFieldIsReported(): void
    {
        $payload = PaymentPayloadBuilder::valid()
            ->without('currency')
            ->withShippingField('email', 'not-an-email')
            ->build();

        $response = $this->request('POST', '/payments', $payload);

        $body = $this->assertProblem($response, 422, 'validation-error');

        self::assertSame('The request contains 2 invalid fields.', $body['detail']);

        /** @var array<int, array<string, string>> $errors */
        $errors = $body['errors'];
        self::assertSame(
            ['currency', 'shipping_address.email'],
            array_column($errors, 'field'),
        );
    }

    public function testFloatAmountsAreRejected(): void
    {
        $response = $this->rawRequest('POST', '/payments', $this->payloadWithFloatTotal());

        $body = $this->assertProblem($response, 422, 'validation-error');

        /** @var array<int, array<string, string>> $errors */
        $errors = $body['errors'];
        self::assertSame('order_total', $errors[0]['field']);
        self::assertSame('Must be an integer.', $errors[0]['message']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nonObjectBodies(): array
    {
        return [
            'list' => ['[{"a":1}]'],
            'number' => ['123'],
            'string' => ['"nope"'],
            'bool' => ['true'],
        ];
    }

    #[DataProvider('nonObjectBodies')]
    public function testNonObjectBodiesAreRejected(string $body): void
    {
        $response = $this->rawRequest('POST', '/payments', $body);

        $problem = $this->assertProblem($response, 422, 'validation-error');

        /** @var array<int, array<string, string>> $errors */
        $errors = $problem['errors'];
        self::assertSame('body', $errors[0]['field']);
        self::assertSame('Must be a JSON object.', $errors[0]['message']);
    }

    /**
     * `[]` and `{}` are indistinguishable once decoded, so an empty body is
     * validated as an empty object: every required field is reported.
     */
    public function testAnEmptyBodyReportsEveryRequiredField(): void
    {
        $response = $this->rawRequest('POST', '/payments', '[]');

        $problem = $this->assertProblem($response, 422, 'validation-error');

        /** @var array<int, array<string, string>> $errors */
        $errors = $problem['errors'];
        $fields = array_column($errors, 'field');

        self::assertContains('currency', $fields);
        self::assertContains('order_total', $fields);
        self::assertContains('order_rows', $fields);
    }

    public function testCreateRequiresAuthentication(): void
    {
        $response = $this->request(
            'POST',
            '/payments',
            PaymentPayloadBuilder::valid()->build(),
            false,
        );

        $this->assertProblem($response, 401, 'unauthorized');
    }

    public function testTheCreatedPaymentCanBeFetchedAgain(): void
    {
        $created = $this->createPayment();

        $response = $this->request('GET', '/payments/' . $created['id']);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame($created, $this->decode($response));
    }

    /** A float `order_total` survives only when the JSON is written by hand. */
    private function payloadWithFloatTotal(): string
    {
        $payload = PaymentPayloadBuilder::valid()->build();
        unset($payload['order_total']);

        $encoded = json_encode($payload, \JSON_THROW_ON_ERROR);

        return '{"order_total": 24900.0, ' . substr($encoded, 1);
    }
}
