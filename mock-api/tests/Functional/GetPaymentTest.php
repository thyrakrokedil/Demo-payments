<?php

declare(strict_types=1);

namespace MockPay\Tests\Functional;

use MockPay\Tests\Support\AppTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;

#[CoversNothing]
final class GetPaymentTest extends AppTestCase
{
    public function testExistingPaymentIsReturned(): void
    {
        $created = $this->createPayment();

        $response = $this->request('GET', '/payments/' . $created['id']);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('application/json', $response->getHeaderLine('Content-Type'));

        $body = $this->decode($response);

        self::assertSame($created['id'], $body['id']);
        self::assertSame('created', $body['status']);
        self::assertSame($created, $body);
    }

    public function testUnknownIdIsA404Problem(): void
    {
        $response = $this->request('GET', '/payments/pay_doesnotexist');

        $body = $this->assertProblem($response, 404, 'not-found');

        self::assertIsString($body['detail']);
        self::assertStringContainsString('pay_doesnotexist', $body['detail']);
    }

    public function testGetRequiresAuthentication(): void
    {
        $created = $this->createPayment();

        $response = $this->request('GET', '/payments/' . $created['id'], null, false);

        $this->assertProblem($response, 401, 'unauthorized');
    }
}
