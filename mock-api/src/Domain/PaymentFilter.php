<?php

declare(strict_types=1);

namespace MockPay\Domain;

final readonly class PaymentFilter
{
    public function __construct(
        public int $page = 1,
        public int $perPage = 20,
        public ?PaymentStatus $status = null,
        public ?string $merchantReference = null,
    ) {}

    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }
}
