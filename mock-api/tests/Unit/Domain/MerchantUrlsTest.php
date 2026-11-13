<?php

declare(strict_types=1);

namespace MockPay\Tests\Unit\Domain;

use MockPay\Domain\MerchantUrls;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MerchantUrlsTest extends TestCase
{
    #[Test]
    public function it_replaces_every_payment_id_placeholder(): void
    {
        self::assertSame(
            'https://shop.example/checkout?success=pay_1&again=pay_1',
            MerchantUrls::render('https://shop.example/checkout?success={{PAYMENT_ID}}&again={{PAYMENT_ID}}', 'pay_1'),
        );
    }

    #[Test]
    public function it_leaves_urls_without_a_placeholder_alone(): void
    {
        self::assertSame('https://shop.example/thanks', MerchantUrls::render('https://shop.example/thanks', 'pay_1'));
    }

    #[Test]
    public function it_fills_absent_keys_with_null(): void
    {
        self::assertSame(
            ['validation' => null, 'notification' => null, 'success' => 'https://shop.example/', 'failed' => null, 'cancel' => null],
            MerchantUrls::fromArray(['success' => 'https://shop.example/'])->toArray(),
        );
    }
}
