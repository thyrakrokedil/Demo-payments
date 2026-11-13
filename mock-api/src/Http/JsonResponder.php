<?php

declare(strict_types=1);

namespace MockPay\Http;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * The single place where JSON and RFC 9457 problem responses are built.
 */
final class JsonResponder
{
    public const PROBLEM_TYPE_PREFIX = 'https://mockpay.local/problems/';

    private const ENCODE_FLAGS = \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE;

    public function __construct(
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface $streamFactory,
    ) {}

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $headers
     */
    public function json(array $data, int $status = 200, array $headers = []): ResponseInterface
    {
        return $this->write($data, $status, 'application/json; charset=utf-8', $headers);
    }

    /**
     * @param array<string, mixed> $extra
     * @param array<string, string> $headers
     */
    public function problem(
        int $status,
        string $slug,
        string $title,
        ?string $detail = null,
        array $extra = [],
        array $headers = [],
    ): ResponseInterface {
        $body = [
            'type' => self::PROBLEM_TYPE_PREFIX . $slug,
            'title' => $title,
            'status' => $status,
        ];

        if (null !== $detail) {
            $body['detail'] = $detail;
        }

        return $this->write(
            array_merge($body, $extra),
            $status,
            'application/problem+json; charset=utf-8',
            $headers,
        );
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $headers
     */
    private function write(array $data, int $status, string $contentType, array $headers): ResponseInterface
    {
        $response = $this->responseFactory->createResponse($status)
            ->withHeader('Content-Type', $contentType)
            ->withBody($this->streamFactory->createStream(json_encode($data, self::ENCODE_FLAGS)));

        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }
}
