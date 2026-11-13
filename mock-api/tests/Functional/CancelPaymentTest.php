<?php

declare(strict_types=1);

namespace MockPay\Tests\Functional;

use MockPay\Tests\Support\AppTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use Psr\Http\Message\ResponseInterface;

#[CoversNothing]
final class CancelPaymentTest extends AppTestCase
{
    public function testACreatedPaymentIsCancelled(): void
    {
        $created = $this->createPayment();

        $this->clock->advance('PT1S');
        $response = $this->cancel($created['id']);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $body = $this->decode($response);

        self::assertSame('cancelled', $body['status']);
        self::assertSame(0, $body['captured_amount']);
        self::assertSame(0, $body['remaining_refundable_amount']);
        self::assertSame($created['created_at'], $body['created_at']);
        self::assertSame('2026-01-02T03:04:06+00:00', $body['updated_at']);
    }

    public function testCancellingTwiceIsA409(): void
    {
        $created = $this->createPayment();

        self::assertSame(200, $this->cancel($created['id'])->getStatusCode());

        $body = $this->assertProblem($this->cancel($created['id']), 409, 'invalid-state');

        self::assertSame('cancel', $body['action']);
        self::assertSame('cancelled', $body['current_status']);
    }

    public function testCancellingAnActivatedPaymentIsA409(): void
    {
        $created = $this->createPayment();

        self::assertSame(200, $this->request('POST', '/payments/' . $created['id'] . '/activate')->getStatusCode());

        $body = $this->assertProblem($this->cancel($created['id']), 409, 'invalid-state');

        self::assertSame('cancel', $body['action']);
        self::assertSame('activated', $body['current_status']);
    }

    public function testCancelAcceptsNoBodyAtAll(): void
    {
        $created = $this->createPayment();

        $response = $this->rawRequest('POST', '/payments/' . $created['id'] . '/cancel');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('cancelled', $this->decode($response)['status']);
    }

    public function testCancellingAnUnknownPaymentIsA404(): void
    {
        $this->assertProblem($this->cancel('pay_nope'), 404, 'not-found');
    }

    public function testCancelRequiresAuthentication(): void
    {
        $created = $this->createPayment();

        $response = $this->request('POST', '/payments/' . $created['id'] . '/cancel', null, false);

        $this->assertProblem($response, 401, 'unauthorized');
    }

    private function cancel(mixed $id): ResponseInterface
    {
        return $this->request('POST', '/payments/' . (string) $id . '/cancel');
    }
}
