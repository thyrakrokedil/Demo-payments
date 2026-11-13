<?php

declare(strict_types=1);

namespace MockPay\Domain;

/**
 * What came back from one callback delivery: the HTTP status, or an error when there was none.
 */
final readonly class CallbackResponse
{
    public function __construct(
        public ?int $status,
        public ?string $error,
        public int $durationMs,
    ) {}
}
