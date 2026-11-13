<?php

declare(strict_types=1);

namespace MockPay\Infrastructure;

use MockPay\Domain\CallbackResponse;
use MockPay\Domain\CallbackTransport;

/**
 * Delivers callbacks with a single cURL request: no redirects followed, no retries.
 */
final class CurlCallbackTransport implements CallbackTransport
{
    public function __construct(private readonly int $timeoutSeconds) {}

    public function post(string $url, string $body, array $headers): CallbackResponse
    {
        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        $handle = curl_init($url);

        if (false === $handle) {
            return new CallbackResponse(null, 'Unable to initialise the HTTP request.', 0);
        }

        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => $this->timeoutSeconds,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
        ]);

        $start = hrtime(true);
        $result = curl_exec($handle);
        $durationMs = (int) ((hrtime(true) - $start) / 1_000_000);

        if (false === $result) {
            $error = curl_error($handle);

            return new CallbackResponse(null, '' !== $error ? $error : 'The HTTP request failed.', $durationMs);
        }

        return new CallbackResponse(curl_getinfo($handle, CURLINFO_RESPONSE_CODE), null, $durationMs);
    }
}
