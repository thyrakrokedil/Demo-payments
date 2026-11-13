<?php

declare(strict_types=1);

namespace MockPay\Http\Action;

use MockPay\Http\JsonResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Public liveness endpoint, used by the Docker healthcheck.
 */
final class HealthAction
{
    public function __construct(private readonly JsonResponder $responder) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->responder->json(['status' => 'ok']);
    }
}
