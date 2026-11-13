<?php

declare(strict_types=1);

namespace MockPay\Domain\Exception;

use RuntimeException;

final class RefundExceedsRemainingAmount extends RuntimeException
{
    public function __construct(
        private readonly int $amount,
        private readonly int $remaining,
    ) {
        parent::__construct(\sprintf(
            'Refund amount %d exceeds the remaining refundable amount %d.',
            $amount,
            $remaining,
        ));
    }

    public function amount(): int
    {
        return $this->amount;
    }

    public function remaining(): int
    {
        return $this->remaining;
    }
}
