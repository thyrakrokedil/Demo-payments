<?php

declare(strict_types=1);

namespace MockPay\Domain;

interface CallbackTransport
{
    /**
     * POSTs the body to the URL. Never throws: a failed delivery comes back as a
     * response without a status and with an error.
     *
     * @param array<string, string> $headers
     */
    public function post(string $url, string $body, array $headers): CallbackResponse;
}
