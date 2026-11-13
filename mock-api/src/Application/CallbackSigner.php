<?php

declare(strict_types=1);

namespace MockPay\Application;

/**
 * Signs callback bodies with HMAC-SHA256 keyed by the merchant's API key, so the
 * merchant can verify that a callback came from MockPay and was not altered.
 */
final class CallbackSigner
{
    public const HEADER = 'X-MockPay-Signature';

    public function __construct(private readonly string $apiKey) {}

    /** Returns the header value: "sha256=" + the hex HMAC-SHA256 of the exact body bytes. */
    public function sign(string $body): string
    {
        return 'sha256=' . hash_hmac('sha256', $body, $this->apiKey);
    }
}
