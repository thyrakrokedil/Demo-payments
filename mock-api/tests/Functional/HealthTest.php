<?php

declare(strict_types=1);

namespace MockPay\Tests\Functional;

use MockPay\Tests\Support\AppTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;

#[CoversNothing]
final class HealthTest extends AppTestCase
{
    public function testHealthIsPublicAndReturnsOk(): void
    {
        $response = $this->request('GET', '/health', null, false);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame(['status' => 'ok'], $this->decode($response));
    }

    public function testHealthAlsoWorksWithCredentials(): void
    {
        $response = $this->request('GET', '/health');

        self::assertSame(200, $response->getStatusCode());
    }
}
