<?php

declare(strict_types=1);

namespace MockPay\Domain\Exception;

use RuntimeException;

final class PaymentNotFound extends RuntimeException
{
    public static function withId(string $id): self
    {
        return new self(\sprintf('Payment "%s" was not found.', $id));
    }
}
