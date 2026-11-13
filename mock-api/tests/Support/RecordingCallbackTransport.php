<?php

declare(strict_types=1);

namespace MockPay\Tests\Support;

use MockPay\Domain\CallbackResponse;
use MockPay\Domain\CallbackTransport;

/**
 * Records every callback instead of sending it, and answers with a configurable response.
 */
final class RecordingCallbackTransport implements CallbackTransport
{
    /**
     * @var list<array{url: string, body: string, headers: array<string, string>}>
     */
    public array $requests = [];

    private CallbackResponse $response;

    public function __construct()
    {
        $this->response = new CallbackResponse(200, null, 12);
    }

    public function respondWith(?int $status, ?string $error = null): void
    {
        $this->response = new CallbackResponse($status, $error, 12);
    }

    public function post(string $url, string $body, array $headers): CallbackResponse
    {
        $this->requests[] = ['url' => $url, 'body' => $body, 'headers' => $headers];

        return $this->response;
    }

    /**
     * @return array<string, mixed>
     */
    public function lastBody(): array
    {
        $last = $this->requests[\count($this->requests) - 1] ?? null;

        if (null === $last) {
            throw new \LogicException('No callback was sent.');
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($last['body'], true, 512, \JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
