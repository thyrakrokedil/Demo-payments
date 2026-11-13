<?php

declare(strict_types=1);

namespace MockPay\Tests\Functional;

use MockPay\Tests\Support\AppTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;

#[CoversNothing]
final class ErrorHandlingTest extends AppTestCase
{
    public function testMalformedJsonBodyBecomesA400Problem(): void
    {
        // The body parser runs before the route callable is invoked, so this holds
        // even before the payment actions exist.
        $response = $this->rawRequest('POST', '/payments', '{"a":');

        $body = $this->assertProblem($response, 400, 'malformed-json');
        self::assertSame('Request body is not valid JSON.', $body['detail'] ?? null);
    }

    public function testUnknownRouteBecomesA404Problem(): void
    {
        $response = $this->request('GET', '/nope', null, false);

        $body = $this->assertProblem($response, 404, 'not-found');
        self::assertSame('No route matches GET /nope.', $body['detail'] ?? null);
    }

    public function testDisallowedMethodBecomesA405ProblemWithAllowHeader(): void
    {
        $response = $this->request('DELETE', '/payments/abc');

        $this->assertProblem($response, 405, 'method-not-allowed');

        $allow = $response->getHeaderLine('Allow');
        self::assertNotSame('', $allow);
        self::assertStringContainsString('GET', $allow);
        self::assertStringContainsString('PATCH', $allow);
    }

    public function testProblemResponsesUseTheProblemContentType(): void
    {
        $response = $this->request('GET', '/nope', null, false);

        self::assertStringStartsWith(
            'application/problem+json',
            $response->getHeaderLine('Content-Type'),
        );
    }
}
