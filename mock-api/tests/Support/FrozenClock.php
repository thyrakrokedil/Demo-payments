<?php

declare(strict_types=1);

namespace MockPay\Tests\Support;

use DateInterval;
use DateTimeImmutable;
use MockPay\Domain\Clock;

final class FrozenClock implements Clock
{
    private DateTimeImmutable $now;

    public function __construct(?DateTimeImmutable $now = null)
    {
        $this->now = $now ?? new DateTimeImmutable('2026-01-02T03:04:05+00:00');
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function advance(string $interval): void
    {
        $this->now = $this->now->add(new DateInterval($interval));
    }

    public function set(DateTimeImmutable $now): void
    {
        $this->now = $now;
    }
}
