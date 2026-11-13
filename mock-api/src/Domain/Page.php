<?php

declare(strict_types=1);

namespace MockPay\Domain;

final readonly class Page
{
    /**
     * @param list<Payment> $items
     */
    public function __construct(
        public array $items,
        public int $page,
        public int $perPage,
        public int $total,
    ) {}

    public function totalPages(): int
    {
        return (int) ceil($this->total / $this->perPage);
    }
}
