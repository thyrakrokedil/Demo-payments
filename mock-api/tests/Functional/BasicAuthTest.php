<?php

declare(strict_types=1);

namespace MockPay\Tests\Functional;

use MockPay\Tests\Support\AppTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversNothing]
final class BasicAuthTest extends AppTestCase
{
    public function testMissingCredentialsAreRejected(): void
    {
        $response = $this->request('GET', '/payments', null, false);

        $this->assertProblem($response, 401, 'unauthorized');
        self::assertSame('Basic realm="MockPay"', $response->getHeaderLine('WWW-Authenticate'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function badHeaders(): array
    {
        return [
            'wrong password' => ['Basic ' . base64_encode('username:nope')],
            'wrong username' => ['Basic ' . base64_encode('nope:password')],
            'wrong scheme' => ['Bearer ' . base64_encode('username:password')],
            'malformed base64' => ['Basic not-base-64!!'],
            'no colon' => ['Basic ' . base64_encode('usernamepassword')],
            'empty' => ['Basic '],
        ];
    }

    #[DataProvider('badHeaders')]
    public function testInvalidCredentialsAreRejected(string $header): void
    {
        $response = $this->request('GET', '/payments', null, false, ['Authorization' => $header]);

        $this->assertProblem($response, 401, 'unauthorized');
    }

    public function testCorrectCredentialsPassTheAuthMiddleware(): void
    {
        // The payment actions may not be resolvable yet; all this asserts is that
        // authentication itself no longer rejects the request.
        $response = $this->request('GET', '/payments');

        self::assertNotSame(401, $response->getStatusCode());
    }

    public function testHealthStaysPublic(): void
    {
        self::assertSame(200, $this->request('GET', '/health', null, false)->getStatusCode());
    }
}
