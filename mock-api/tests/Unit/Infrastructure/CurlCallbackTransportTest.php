<?php

declare(strict_types=1);

namespace MockPay\Tests\Unit\Infrastructure;

use MockPay\Infrastructure\CurlCallbackTransport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CurlCallbackTransport::class)]
final class CurlCallbackTransportTest extends TestCase
{
    public function testAnUnreachableUrlComesBackAsAnErrorInsteadOfThrowing(): void
    {
        // Port 1 on loopback is closed, so the connection is refused straight away.
        $response = (new CurlCallbackTransport(2))->post('http://127.0.0.1:1/notify', '{}', ['Content-Type' => 'application/json']);

        self::assertNull($response->status);
        self::assertNotNull($response->error);
        self::assertGreaterThanOrEqual(0, $response->durationMs);
    }
}
