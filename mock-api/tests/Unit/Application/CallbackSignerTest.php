<?php

declare(strict_types=1);

namespace MockPay\Tests\Unit\Application;

use MockPay\Application\CallbackSigner;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CallbackSignerTest extends TestCase
{
    #[Test]
    public function it_signs_with_hmac_sha256_of_the_body(): void
    {
        // The well-known HMAC-SHA256 test vector, so the scheme is easy to reproduce in a shop.
        self::assertSame(
            'sha256=f7bc83f430538424b13298e6aa6fb143ef4d59a14946175997479dbc2d1a3cd8',
            (new CallbackSigner('key'))->sign('The quick brown fox jumps over the lazy dog'),
        );
    }

    #[Test]
    public function a_different_key_gives_a_different_signature(): void
    {
        self::assertNotSame((new CallbackSigner('a'))->sign('{}'), (new CallbackSigner('b'))->sign('{}'));
    }
}
