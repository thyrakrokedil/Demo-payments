<?php

declare(strict_types=1);

namespace MockPay\Tests\Functional;

use MockPay\Tests\Support\AppTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use Psr\Http\Message\ResponseInterface;

#[CoversNothing]
final class ActivatePaymentTest extends AppTestCase
{
    public function testActivationCapturesTheFullOrderTotal(): void
    {
        $created = $this->createPayment();

        $this->clock->advance('PT1S');
        $response = $this->activate($created['id']);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $body = $this->decode($response);

        self::assertSame('activated', $body['status']);
        self::assertSame($created['order_total'], $body['captured_amount']);
        self::assertSame($created['order_total'], $body['remaining_refundable_amount']);
        self::assertSame(0, $body['refunded_amount']);
        self::assertSame([], $body['refunds']);
        self::assertSame($created['created_at'], $body['created_at']);
        self::assertSame('2026-01-02T03:04:06+00:00', $body['updated_at']);
    }

    public function testActivationIsPersisted(): void
    {
        $created = $this->createPayment();

        $this->activate($created['id']);

        $body = $this->decode($this->request('GET', '/payments/' . $created['id']));

        self::assertSame('activated', $body['status']);
        self::assertSame($created['order_total'], $body['captured_amount']);
    }

    public function testActivatingTwiceIsA409(): void
    {
        $created = $this->createPayment();

        self::assertSame(200, $this->activate($created['id'])->getStatusCode());

        $body = $this->assertProblem($this->activate($created['id']), 409, 'invalid-state');

        self::assertSame('activate', $body['action']);
        self::assertSame('activated', $body['current_status']);
    }

    public function testActivatingACancelledPaymentIsA409(): void
    {
        $created = $this->createPayment();

        self::assertSame(200, $this->request('POST', '/payments/' . $created['id'] . '/cancel')->getStatusCode());

        $body = $this->assertProblem($this->activate($created['id']), 409, 'invalid-state');

        self::assertSame('activate', $body['action']);
        self::assertSame('cancelled', $body['current_status']);
    }

    public function testActivatingAnUnknownPaymentIsA404(): void
    {
        $this->assertProblem($this->activate('pay_nope'), 404, 'not-found');
    }

    public function testActivateRequiresAuthentication(): void
    {
        $created = $this->createPayment();

        $response = $this->request('POST', '/payments/' . $created['id'] . '/activate', null, false);

        $this->assertProblem($response, 401, 'unauthorized');
    }

    private function activate(mixed $id): ResponseInterface
    {
        return $this->request('POST', '/payments/' . (string) $id . '/activate');
    }
}
