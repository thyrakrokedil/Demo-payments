<?php

declare(strict_types=1);

namespace MockPay\Domain;

use DateTimeImmutable;

interface Clock
{
    public function now(): DateTimeImmutable;
}
