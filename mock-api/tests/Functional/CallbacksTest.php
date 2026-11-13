<?php

declare(strict_types=1);

namespace MockPay\Tests\Functional;

use MockPay\Tests\Support\AppTestCase;
use MockPay\Tests\Support\PaymentPayloadBuilder;
use PHPUnit\Framework\Attributes\CoversNothing;

#[CoversNothing]
final class CallbacksTest extends AppTestCase
{
    public function testActivateSendsASignedNotification(): void
    {
        $payment = $this->createPaymentWithUrls();

        $this->clock->advance('PT1S');
        self::assertSame(200, $this->request('POST', '/payments/' . $payment['id'] . '/activate')->getStatusCode());

        self::assertCount(1, $this->callbacks->requests);
        $request = $this->callbacks->requests[0];

        self::assertSame('https://shop.example/notify?payment=' . $payment['id'], $request['url']);
        self::assertSame('application/json', $request['headers']['Content-Type']);
        self::assertSame('payment.activated', $request['headers']['X-MockPay-Event']);
        self::assertSame(
            'sha256=' . hash_hmac('sha256', $request['body'], self::PASSWORD),
            $request['headers']['X-MockPay-Signature'],
        );

        $body = $this->callbacks->lastBody();

        self::assertStringStartsWith('evt_', (string) $body['id']);
        self::assertSame('payment.activated', $body['event']);
        self::assertSame($payment['id'], $body['payment_id']);
        self::assertSame('activated', $body['status']);
        self::assertSame('2026-01-02T03:04:06+00:00', $body['created_at']);
    }

    public function testCancelSendsANotification(): void
    {
        $payment = $this->createPaymentWithUrls();

        $this->request('POST', '/payments/' . $payment['id'] . '/cancel');

        $body = $this->callbacks->lastBody();

        self::assertSame('payment.cancelled', $body['event']);
        self::assertSame('cancelled', $body['status']);
    }

    public function testRefundSendsANotificationWithTheRefund(): void
    {
        $payment = $this->createPaymentWithUrls();
        $this->request('POST', '/payments/' . $payment['id'] . '/activate');

        $response = $this->request('POST', '/payments/' . $payment['id'] . '/refund', [
            'amount' => 4900,
            'reference' => 'RMA-1',
        ]);
        $refundId = $this->decode($response)['refunds'][0]['id'] ?? null;

        $body = $this->callbacks->lastBody();

        self::assertSame('payment.refunded', $body['event']);
        self::assertSame('partially_refunded', $body['status']);
        self::assertSame(['id' => $refundId, 'amount' => 4900, 'reference' => 'RMA-1'], $body['refund']);
    }

    public function testNothingIsSentWithoutANotificationUrl(): void
    {
        $payload = PaymentPayloadBuilder::valid()->withMerchantUrls(['notification' => null])->build();
        $payment = $this->createPayment($payload);

        $this->request('POST', '/payments/' . $payment['id'] . '/activate');
        $this->request('POST', '/payments/' . $payment['id'] . '/refund');

        self::assertSame([], $this->callbacks->requests);
    }

    public function testCreateAndUpdateSendNothing(): void
    {
        $payment = $this->createPaymentWithUrls();
        $this->request('PATCH', '/payments/' . $payment['id'], ['merchant_reference' => '2002']);

        self::assertSame([], $this->callbacks->requests);
    }

    public function testARejectedNotificationDoesNotUndoTheStateChange(): void
    {
        $payment = $this->createPaymentWithUrls();
        $this->callbacks->respondWith(null, 'Connection refused');

        $response = $this->request('POST', '/payments/' . $payment['id'] . '/activate');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('activated', $this->decode($response)['status']);
        self::assertCount(1, $this->callbacks->requests);
    }

    public function testAttemptsAreListedOldestFirst(): void
    {
        $payment = $this->createPaymentWithUrls();
        $this->request('POST', '/payments/' . $payment['id'] . '/activate');

        $this->clock->advance('PT1S');
        $this->callbacks->respondWith(503);
        $this->request('POST', '/payments/' . $payment['id'] . '/refund');

        $response = $this->request('GET', '/payments/' . $payment['id'] . '/callbacks');

        self::assertSame(200, $response->getStatusCode());

        /** @var list<array<string, mixed>> $data */
        $data = $this->decode($response)['data'];

        self::assertCount(2, $data);
        self::assertSame('payment.activated', $data[0]['event']);
        self::assertTrue($data[0]['succeeded']);
        self::assertSame(200, $data[0]['response_status']);
        self::assertSame('https://shop.example/notify?payment=' . $payment['id'], $data[0]['url']);
        self::assertSame('2026-01-02T03:04:05+00:00', $data[0]['created_at']);
        self::assertSame('payment.refunded', $data[1]['event']);
        self::assertFalse($data[1]['succeeded']);
        self::assertSame(503, $data[1]['response_status']);

        // The attempt id is the event id the merchant received.
        self::assertSame($this->callbacks->lastBody()['id'], $data[1]['id']);
    }

    public function testAFailedDeliveryIsRecordedWithItsError(): void
    {
        $payment = $this->createPaymentWithUrls();
        $this->callbacks->respondWith(null, 'Operation timed out');
        $this->request('POST', '/payments/' . $payment['id'] . '/cancel');

        $data = $this->decode($this->request('GET', '/payments/' . $payment['id'] . '/callbacks'))['data'];

        self::assertIsArray($data);
        self::assertIsArray($data[0]);
        self::assertNull($data[0]['response_status']);
        self::assertSame('Operation timed out', $data[0]['error']);
        self::assertFalse($data[0]['succeeded']);
    }

    public function testListingAttemptsOfAnUnknownPaymentIsA404(): void
    {
        $this->assertProblem($this->request('GET', '/payments/pay_nope/callbacks'), 404, 'not-found');
    }

    public function testListingAttemptsRequiresAuthentication(): void
    {
        $payment = $this->createPaymentWithUrls();

        $response = $this->request('GET', '/payments/' . $payment['id'] . '/callbacks', null, false);

        $this->assertProblem($response, 401, 'unauthorized');
    }

    /**
     * @return array<string, mixed>
     */
    private function createPaymentWithUrls(): array
    {
        return $this->createPayment(PaymentPayloadBuilder::valid()->withMerchantUrls()->build());
    }
}
