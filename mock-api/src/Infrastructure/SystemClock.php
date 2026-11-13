<?php

declare(strict_types=1);

namespace MockPay\Infrastructure;

use DateTimeImmutable;
use DateTimeZone;
use MockPay\Domain\Clock;

final class SystemClock implements Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
