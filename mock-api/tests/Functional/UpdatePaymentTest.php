<?php

declare(strict_types=1);

namespace MockPay\Tests\Functional;

use MockPay\Tests\Support\AppTestCase;
use MockPay\Tests\Support\PaymentPayloadBuilder;
use PHPUnit\Framework\Attributes\CoversNothing;
use Psr\Http\Message\ResponseInterface;

#[CoversNothing]
final class UpdatePaymentTest extends AppTestCase
{
    public function testASingleFieldIsMergedOntoTheStoredPayload(): void
    {
        $created = $this->createPayment();

        $this->clock->advance('PT1S');
        $response = $this->patch($created['id'], ['merchant_reference' => 'ORDER-99']);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $body = $this->decode($response);

        self::assertSame('ORDER-99', $body['merchant_reference']);
        self::assertSame($created['merchant_reference2'], $body['merchant_reference2']);
        self::assertSame($created['currency'], $body['currency']);
        self::assertSame($created['order_total'], $body['order_total']);
        self::assertSame($created['billing_address'], $body['billing_address']);
        self::assertSame($created['order_rows'], $body['order_rows']);
        self::assertSame('created', $body['status']);

        self::assertSame($created['created_at'], $body['created_at']);
        self::assertSame('2026-01-02T03:04:06+00:00', $body['updated_at']);
    }

    public function testOrderRowsAndTotalsCanBeReplacedTogether(): void
    {
        $created = $this->createPayment();

        $rows = [PaymentPayloadBuilder::validRow(['quantity' => 1, 'total_amount' => 10000])];

        $body = $this->decode($this->patch($created['id'], [
            'order_rows' => $rows,
            'order_total' => 10000,
            'order_vat_amount' => 2000,
        ]));

        self::assertSame(10000, $body['order_total']);
        self::assertSame(2000, $body['order_vat_amount']);
        self::assertSame($rows, $body['order_rows']);
    }

    public function testReplacingOnlyTheRowsBreaksTheTotalSum(): void
    {
        $created = $this->createPayment();

        $response = $this->patch($created['id'], [
            'order_rows' => [PaymentPayloadBuilder::validRow(['quantity' => 1, 'total_amount' => 10000])],
        ]);

        $problem = $this->assertProblem($response, 422, 'validation-error');

        /** @var array<int, array<string, string>> $errors */
        $errors = $problem['errors'];
        self::assertContains('order_total', array_column($errors, 'field'));
    }

    public function testNestedObjectsAreReplacedWholeRatherThanDeepMerged(): void
    {
        $created = $this->createPayment();

        $address = PaymentPayloadBuilder::validAddress('Bo', 'Bengtsson');
        unset($address['email']);

        $response = $this->patch($created['id'], ['billing_address' => $address]);

        $problem = $this->assertProblem($response, 422, 'validation-error');

        /** @var array<int, array<string, string>> $errors */
        $errors = $problem['errors'];
        self::assertSame(['billing_address.email'], array_column($errors, 'field'));
    }

    public function testTheMergedResultIsFullyRevalidated(): void
    {
        $created = $this->createPayment();

        $response = $this->patch($created['id'], ['currency' => 'sek']);

        $problem = $this->assertProblem($response, 422, 'validation-error');

        /** @var array<int, array<string, string>> $errors */
        $errors = $problem['errors'];
        self::assertSame(['currency'], array_column($errors, 'field'));
    }

    public function testANonObjectBodyIsRejected(): void
    {
        $created = $this->createPayment();

        $response = $this->rawRequest('PATCH', '/payments/' . $created['id'], '123');

        $problem = $this->assertProblem($response, 422, 'validation-error');

        /** @var array<int, array<string, string>> $errors */
        $errors = $problem['errors'];
        self::assertSame('body', $errors[0]['field']);
    }

    public function testUpdatingAnActivatedPaymentIsA409(): void
    {
        $created = $this->createPayment();
        $this->request('POST', '/payments/' . $created['id'] . '/activate');

        $body = $this->assertProblem(
            $this->patch($created['id'], ['merchant_reference' => 'ORDER-99']),
            409,
            'invalid-state',
        );

        self::assertSame('update', $body['action']);
        self::assertSame('activated', $body['current_status']);
    }

    public function testUpdatingACancelledPaymentIsA409(): void
    {
        $created = $this->createPayment();
        $this->request('POST', '/payments/' . $created['id'] . '/cancel');

        $body = $this->assertProblem(
            $this->patch($created['id'], ['merchant_reference' => 'ORDER-99']),
            409,
            'invalid-state',
        );

        self::assertSame('update', $body['action']);
        self::assertSame('cancelled', $body['current_status']);
    }

    public function testTheStateCheckWinsOverValidation(): void
    {
        $created = $this->createPayment();
        $this->request('POST', '/payments/' . $created['id'] . '/activate');

        $body = $this->assertProblem(
            $this->patch($created['id'], ['currency' => 'sek']),
            409,
            'invalid-state',
        );

        self::assertSame('update', $body['action']);
    }

    public function testUpdatingAnUnknownPaymentIsA404(): void
    {
        $this->assertProblem(
            $this->patch('pay_nope', ['merchant_reference' => 'ORDER-99']),
            404,
            'not-found',
        );
    }

    public function testUpdateRequiresAuthentication(): void
    {
        $created = $this->createPayment();

        $response = $this->request(
            'PATCH',
            '/payments/' . $created['id'],
            ['merchant_reference' => 'ORDER-99'],
            false,
        );

        $this->assertProblem($response, 401, 'unauthorized');
    }

    /**
     * @param array<string, mixed> $body
     */
    private function patch(mixed $id, array $body): ResponseInterface
    {
        return $this->request('PATCH', '/payments/' . (string) $id, $body);
    }
}
