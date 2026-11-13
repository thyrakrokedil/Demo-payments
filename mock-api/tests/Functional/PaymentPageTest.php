<?php

declare(strict_types=1);

namespace MockPay\Tests\Functional;

use MockPay\Tests\Support\AppTestCase;
use MockPay\Tests\Support\PaymentPayloadBuilder;
use PHPUnit\Framework\Attributes\CoversNothing;
use Psr\Http\Message\ResponseInterface;

#[CoversNothing]
final class PaymentPageTest extends AppTestCase
{
    public function testThePageShowsTheOrderWithoutAuthentication(): void
    {
        $payment = $this->createPaymentWithUrls();

        $response = $this->request('GET', '/pay/' . $payment['id'], null, false);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('text/html', $response->getHeaderLine('Content-Type'));

        $html = (string) $response->getBody();

        self::assertStringContainsString('T-shirt', $html);
        self::assertStringContainsString('249.00 SEK', $html);
        self::assertStringContainsString('action="/pay/' . $payment['id'] . '/pay"', $html);
        self::assertStringContainsString('formaction="/pay/' . $payment['id'] . '/fail"', $html);
        self::assertStringContainsString('formaction="/pay/' . $payment['id'] . '/cancel"', $html);
    }

    public function testThePageEscapesMerchantData(): void
    {
        $payload = PaymentPayloadBuilder::valid()
            ->withRowField(0, 'name', '<script>alert(1)</script>')
            ->withMerchantReference('"><b>x</b>')
            ->build();
        $payment = $this->createPayment($payload);

        $html = (string) $this->request('GET', '/pay/' . $payment['id'], null, false)->getBody();

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('<b>x</b>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testThePageOfAnUnknownPaymentIsA404(): void
    {
        $response = $this->request('GET', '/pay/pay_nope', null, false);

        self::assertSame(404, $response->getStatusCode());
        self::assertStringStartsWith('text/html', $response->getHeaderLine('Content-Type'));
    }

    public function testAPaymentThatCanNoLongerBePaidShowsNoButtons(): void
    {
        $payment = $this->createPaymentWithUrls();
        $this->request('POST', '/payments/' . $payment['id'] . '/activate');

        $html = (string) $this->request('GET', '/pay/' . $payment['id'], null, false)->getBody();

        self::assertStringContainsString('<strong>activated</strong>', $html);
        self::assertStringNotContainsString('<form', $html);
    }

    public function testPayValidatesAuthorizesAndRedirectsToSuccess(): void
    {
        $payment = $this->createPaymentWithUrls();

        $response = $this->submit($payment['id'], 'pay');

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('https://shop.example/checkout?success=' . $payment['id'], $response->getHeaderLine('Location'));
        self::assertSame('authorized', $this->statusOf($payment['id']));

        self::assertCount(1, $this->callbacks->requests);
        $request = $this->callbacks->requests[0];

        self::assertSame('https://shop.example/validate', $request['url']);
        self::assertSame(
            'sha256=' . hash_hmac('sha256', $request['body'], self::PASSWORD),
            $request['headers']['X-MockPay-Signature'],
        );

        $body = $this->callbacks->lastBody();

        self::assertSame('payment.validation', $body['event']);
        self::assertSame($payment['id'], $body['payment_id']);
        self::assertIsArray($body['payment']);
        self::assertSame('created', $body['payment']['status']);
        self::assertSame($payment['order_rows'], $body['payment']['order_rows']);
    }

    public function testPayWithoutAValidationUrlAuthorizesDirectly(): void
    {
        $payment = $this->createPayment(PaymentPayloadBuilder::valid()->withMerchantUrls(['validation' => null])->build());

        $response = $this->submit($payment['id'], 'pay');

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('authorized', $this->statusOf($payment['id']));
        self::assertSame([], $this->callbacks->requests);
    }

    public function testARejectedValidationRedirectsToFailedAndLeavesThePaymentCreated(): void
    {
        $payment = $this->createPaymentWithUrls();
        $this->callbacks->respondWith(400);

        $response = $this->submit($payment['id'], 'pay');

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('https://shop.example/checkout?failed=' . $payment['id'], $response->getHeaderLine('Location'));
        self::assertSame('created', $this->statusOf($payment['id']));
    }

    public function testAnUnreachableValidationUrlCountsAsRejected(): void
    {
        $payment = $this->createPaymentWithUrls();
        $this->callbacks->respondWith(null, 'Could not resolve host');

        $response = $this->submit($payment['id'], 'pay');

        self::assertSame('https://shop.example/checkout?failed=' . $payment['id'], $response->getHeaderLine('Location'));
        self::assertSame('created', $this->statusOf($payment['id']));
    }

    public function testFailRedirectsToFailedWithoutChangingThePayment(): void
    {
        $payment = $this->createPaymentWithUrls();

        $response = $this->submit($payment['id'], 'fail');

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('https://shop.example/checkout?failed=' . $payment['id'], $response->getHeaderLine('Location'));
        self::assertSame('created', $this->statusOf($payment['id']));
        self::assertSame([], $this->callbacks->requests);
    }

    public function testCancelRedirectsToCancelWithoutChangingThePayment(): void
    {
        $payment = $this->createPaymentWithUrls();

        $response = $this->submit($payment['id'], 'cancel');

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('https://shop.example/checkout?aborted=' . $payment['id'], $response->getHeaderLine('Location'));
        self::assertSame('created', $this->statusOf($payment['id']));
    }

    public function testWithoutARedirectUrlAMessageIsShown(): void
    {
        $payment = $this->createPayment();

        $response = $this->submit($payment['id'], 'pay');

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Payment authorized', (string) $response->getBody());
        self::assertSame('authorized', $this->statusOf($payment['id']));
    }

    public function testAnAuthorizedPaymentCannotBePaidAgain(): void
    {
        $payment = $this->createPaymentWithUrls();
        $this->submit($payment['id'], 'pay');
        $this->callbacks->requests = [];

        $response = $this->submit($payment['id'], 'pay');

        self::assertSame(409, $response->getStatusCode());
        self::assertStringContainsString('authorized', (string) $response->getBody());
        self::assertSame([], $this->callbacks->requests);
    }

    public function testSubmittingForAnUnknownPaymentIsA404(): void
    {
        self::assertSame(404, $this->submit('pay_nope', 'pay')->getStatusCode());
    }

    public function testAnAuthorizedPaymentCanBeActivated(): void
    {
        $payment = $this->createPaymentWithUrls();
        $this->submit($payment['id'], 'pay');

        $response = $this->request('POST', '/payments/' . $payment['id'] . '/activate');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('activated', $this->decode($response)['status']);
    }

    public function testAnAuthorizedPaymentCanBeCancelled(): void
    {
        $payment = $this->createPaymentWithUrls();
        $this->submit($payment['id'], 'pay');

        $response = $this->request('POST', '/payments/' . $payment['id'] . '/cancel');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('cancelled', $this->decode($response)['status']);
    }

    public function testAnAuthorizedPaymentCannotBeUpdated(): void
    {
        $payment = $this->createPaymentWithUrls();
        $this->submit($payment['id'], 'pay');

        $response = $this->request('PATCH', '/payments/' . $payment['id'], ['merchant_reference' => '2002']);

        $body = $this->assertProblem($response, 409, 'invalid-state');
        self::assertSame('authorized', $body['current_status']);
    }

    /**
     * @return array<string, mixed>
     */
    private function createPaymentWithUrls(): array
    {
        return $this->createPayment(PaymentPayloadBuilder::valid()->withMerchantUrls()->build());
    }

    private function submit(mixed $id, string $outcome): ResponseInterface
    {
        return $this->request('POST', '/pay/' . (string) $id . '/' . $outcome, null, false);
    }

    private function statusOf(mixed $id): mixed
    {
        return $this->decode($this->request('GET', '/payments/' . (string) $id))['status'];
    }
}
