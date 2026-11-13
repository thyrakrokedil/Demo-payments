<?php

declare(strict_types=1);

namespace MockPay\Tests\Functional;

use MockPay\Tests\Support\AppTestCase;
use MockPay\Tests\Support\PaymentPayloadBuilder;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversNothing]
final class MerchantUrlsTest extends AppTestCase
{
    public function testMerchantUrlsAreStoredAsTemplatesAndReturned(): void
    {
        $created = $this->createPayment(PaymentPayloadBuilder::valid()->withMerchantUrls()->build());

        self::assertSame(PaymentPayloadBuilder::merchantUrls(), $created['merchant_urls']);

        $fetched = $this->decode($this->request('GET', '/payments/' . $created['id']));

        self::assertSame(PaymentPayloadBuilder::merchantUrls(), $fetched['merchant_urls']);
    }

    public function testMerchantUrlsAreNullWhenNotSent(): void
    {
        $created = $this->createPayment();

        self::assertNull($created['merchant_urls']);
    }

    public function testAbsentKeysAreNull(): void
    {
        $payload = PaymentPayloadBuilder::valid()
            ->with('merchant_urls', ['success' => 'https://shop.example/thanks'])
            ->build();

        $created = $this->createPayment($payload);

        self::assertSame([
            'validation' => null,
            'notification' => null,
            'success' => 'https://shop.example/thanks',
            'failed' => null,
            'cancel' => null,
        ], $created['merchant_urls']);
    }

    public function testThePaymentPageUrlIsReturned(): void
    {
        $created = $this->createPayment();

        self::assertSame('http://localhost:8888/pay/' . $created['id'], $created['payment_page_url']);
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function invalidMerchantUrls(): iterable
    {
        yield 'not an object' => ['https://shop.example', 'merchant_urls'];
        yield 'a list' => [['https://shop.example'], 'merchant_urls'];
        yield 'relative url' => [['success' => '/checkout?success={{PAYMENT_ID}}'], 'merchant_urls.success'];
        yield 'ftp scheme' => [['notification' => 'ftp://shop.example/notify'], 'merchant_urls.notification'];
        yield 'javascript scheme' => [['cancel' => 'javascript:alert(1)'], 'merchant_urls.cancel'];
        yield 'empty string' => [['failed' => ''], 'merchant_urls.failed'];
        yield 'not a string' => [['validation' => 123], 'merchant_urls.validation'];
        yield 'too long' => [['success' => 'https://shop.example/' . str_repeat('a', 2048)], 'merchant_urls.success'];
    }

    #[DataProvider('invalidMerchantUrls')]
    public function testInvalidMerchantUrlsAreA422(mixed $urls, string $field): void
    {
        $payload = PaymentPayloadBuilder::valid()->with('merchant_urls', $urls)->build();

        $body = $this->assertProblem($this->request('POST', '/payments', $payload), 422, 'validation-error');

        self::assertIsArray($body['errors']);
        self::assertSame([$field], array_column($body['errors'], 'field'));
    }

    public function testPatchReplacesTheMerchantUrlsWhole(): void
    {
        $created = $this->createPayment(PaymentPayloadBuilder::valid()->withMerchantUrls()->build());

        $response = $this->request('PATCH', '/payments/' . $created['id'], [
            'merchant_urls' => ['success' => 'https://shop.example/new-thanks'],
        ]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame([
            'validation' => null,
            'notification' => null,
            'success' => 'https://shop.example/new-thanks',
            'failed' => null,
            'cancel' => null,
        ], $this->decode($response)['merchant_urls']);
    }
}
