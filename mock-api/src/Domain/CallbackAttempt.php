<?php

declare(strict_types=1);

namespace MockPay\Domain;

use DateTimeImmutable;
use DateTimeZone;

/**
 * One delivery of a signed callback to the merchant, recorded whatever the outcome.
 * The id doubles as the event id sent in the callback body.
 *
 * @phpstan-type CallbackAttemptArray array{
 *     id: string,
 *     event: string,
 *     url: string,
 *     succeeded: bool,
 *     response_status: int|null,
 *     error: string|null,
 *     duration_ms: int,
 *     created_at: string,
 * }
 */
final readonly class CallbackAttempt
{
    public function __construct(
        public string $id,
        public string $paymentId,
        public string $event,
        public string $url,
        public ?int $responseStatus,
        public ?string $error,
        public int $durationMs,
        public DateTimeImmutable $createdAt,
    ) {}

    /** A 2xx answer counts as accepted; anything else, including no answer, does not. */
    public function succeeded(): bool
    {
        return $this->responseStatus !== null && $this->responseStatus >= 200 && $this->responseStatus < 300;
    }

    /**
     * @return CallbackAttemptArray
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'event' => $this->event,
            'url' => $this->url,
            'succeeded' => $this->succeeded(),
            'response_status' => $this->responseStatus,
            'error' => $this->error,
            'duration_ms' => $this->durationMs,
            'created_at' => $this->createdAt->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM),
        ];
    }
}
