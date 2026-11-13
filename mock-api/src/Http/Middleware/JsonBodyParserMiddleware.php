<?php

declare(strict_types=1);

namespace MockPay\Http\Middleware;

use JsonException;
use MockPay\Http\Exception\MalformedJsonException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Decodes JSON request bodies. Unlike Slim's own parser, malformed JSON raises
 * an exception (mapped to 400) instead of silently yielding null.
 *
 * The decoded value is always exposed on the request attribute
 * {@see JsonBodyParserMiddleware::ATTRIBUTE} because PSR-7's withParsedBody()
 * only accepts array|object|null, while a body such as `123` or `"x"` is valid
 * JSON that the validators must be able to reject.
 */
final class JsonBodyParserMiddleware implements MiddlewareInterface
{
    public const ATTRIBUTE = 'json_body';

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $body = (string) $request->getBody();

        if ('' === trim($body)) {
            return $handler->handle($request->withAttribute(self::ATTRIBUTE, null));
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw MalformedJsonException::create();
        }

        $request = $request->withAttribute(self::ATTRIBUTE, $decoded);

        if (\is_array($decoded)) {
            $request = $request->withParsedBody($decoded);
        }

        return $handler->handle($request);
    }
}
