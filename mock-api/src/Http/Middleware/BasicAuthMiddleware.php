<?php

declare(strict_types=1);

namespace MockPay\Http\Middleware;

use MockPay\Http\JsonResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * HTTP Basic authentication for the /payments group.
 */
final class BasicAuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly string $username,
        private readonly string $password,
        private readonly JsonResponder $responder,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $credentials = $this->credentials($request);

        if (null === $credentials || ! $this->matches($credentials[0], $credentials[1])) {
            return $this->responder->problem(
                401,
                'unauthorized',
                'Unauthorized',
                'Valid Basic authentication credentials are required.',
                [],
                ['WWW-Authenticate' => 'Basic realm="MockPay"'],
            );
        }

        return $handler->handle($request);
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private function credentials(ServerRequestInterface $request): ?array
    {
        $header = $request->getHeaderLine('Authorization');

        if ('' !== $header) {
            if (1 !== preg_match('/^Basic\s+(\S+)$/i', $header, $matches)) {
                return null;
            }

            $decoded = base64_decode($matches[1], true);

            if (false === $decoded || ! str_contains($decoded, ':')) {
                return null;
            }

            [$user, $pass] = explode(':', $decoded, 2);

            return [$user, $pass];
        }

        $params = $request->getServerParams();
        $user = $params['PHP_AUTH_USER'] ?? null;
        $pass = $params['PHP_AUTH_PW'] ?? null;

        if (\is_string($user) && \is_string($pass)) {
            return [$user, $pass];
        }

        return null;
    }

    private function matches(string $user, string $pass): bool
    {
        $userOk = hash_equals($this->username, $user);
        $passOk = hash_equals($this->password, $pass);

        return $userOk && $passOk;
    }
}
