<?php

declare(strict_types=1);

namespace MockPay\Domain;

enum PaymentStatus: string
{
    case Created = 'created';
    case Authorized = 'authorized';
    case Activated = 'activated';
    case PartiallyRefunded = 'partially_refunded';
    case Refunded = 'refunded';
    case Cancelled = 'cancelled';

    public function canUpdate(): bool
    {
        return $this === self::Created;
    }

    /** Only a payment the customer has not completed yet can be paid on the payment page. */
    public function canAuthorize(): bool
    {
        return $this === self::Created;
    }

    public function canCancel(): bool
    {
        return $this === self::Created || $this === self::Authorized;
    }

    public function canActivate(): bool
    {
        return $this === self::Created || $this === self::Authorized;
    }

    public function canRefund(): bool
    {
        return $this === self::Activated || $this === self::PartiallyRefunded;
    }
}
