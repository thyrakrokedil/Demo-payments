<?php

declare(strict_types=1);

namespace MockPay\Domain\Exception;

use MockPay\Domain\PaymentStatus;
use RuntimeException;

final class InvalidStateTransition extends RuntimeException
{
    public function __construct(
        private readonly string $action,
        private readonly PaymentStatus $currentStatus,
    ) {
        parent::__construct(\sprintf(
            'Cannot %s a payment in status "%s".',
            $action,
            $currentStatus->value,
        ));
    }

    public function action(): string
    {
        return $this->action;
    }

    public function currentStatus(): PaymentStatus
    {
        return $this->currentStatus;
    }
}
