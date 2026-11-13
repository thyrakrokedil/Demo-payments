<?php

declare(strict_types=1);

namespace MockPay\Tests\Support;

use MockPay\Bootstrap;
use MockPay\Domain\CallbackTransport;
use MockPay\Domain\Clock;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;

/**
 * Base class for functional tests: a fresh app on an in-memory database per test.
 */
abstract class AppTestCase extends TestCase
{
    protected const USERNAME = 'username';
    protected const PASSWORD = 'password';

    protected FrozenClock $clock;

    protected RecordingCallbackTransport $callbacks;

    protected ContainerInterface $container;

    /** @var App<ContainerInterface> */
    protected App $app;

    protected Psr17Factory $psr17;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = new FrozenClock();
        $this->callbacks = new RecordingCallbackTransport();
        $this->psr17 = new Psr17Factory();
        $this->container = Bootstrap::createContainer([
            'db.path' => ':memory:',
            Clock::class => $this->clock,
            CallbackTransport::class => $this->callbacks,
        ]);
        $this->app = Bootstrap::createApp($this->container);
    }

    /**
     * @param array<string, mixed>|null $json
     * @param array<string, string> $headers
     */
    protected function request(
        string $method,
        string $uri,
        ?array $json = null,
        bool $auth = true,
        array $headers = [],
    ): ResponseInterface {
        $body = null === $json
            ? null
            : json_encode($json, \JSON_THROW_ON_ERROR);

        return $this->rawRequest($method, $uri, $body, $auth, $headers);
    }

    /**
     * @param array<string, string> $headers
     */
    protected function rawRequest(
        string $method,
        string $uri,
        ?string $body = null,
        bool $auth = true,
        array $headers = [],
    ): ResponseInterface {
        $request = $this->psr17->createServerRequest($method, $uri);

        if ($auth) {
            $request = $request->withHeader(
                'Authorization',
                'Basic ' . base64_encode(self::USERNAME . ':' . self::PASSWORD),
            );
        }

        if (null !== $body) {
            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->psr17->createStream($body));
        }

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $this->handle($request);
    }

    protected function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->app->handle($request);
    }

    /**
     * @return array<string, mixed>
     */
    protected function decode(ResponseInterface $response): array
    {
        $body = (string) $response->getBody();

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    protected function assertProblem(ResponseInterface $response, int $status, string $slug): array
    {
        self::assertSame($status, $response->getStatusCode(), (string) $response->getBody());
        self::assertStringStartsWith(
            'application/problem+json',
            $response->getHeaderLine('Content-Type'),
        );

        $body = $this->decode($response);

        self::assertSame('https://mockpay.local/problems/' . $slug, $body['type'] ?? null);
        self::assertSame($status, $body['status'] ?? null);

        return $body;
    }

    /**
     * @param array<string, mixed>|null $payload
     *
     * @return array<string, mixed>
     */
    protected function createPayment(?array $payload = null): array
    {
        $response = $this->request('POST', '/payments', $payload ?? PaymentPayloadBuilder::valid()->build());

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        return $this->decode($response);
    }
}
