<?php

declare(strict_types=1);

namespace MockPay\Domain;

interface CallbackAttemptRepository
{
    public function add(CallbackAttempt $attempt): void;

    /**
     * Oldest first (created_at ASC, id ASC).
     *
     * @return list<CallbackAttempt>
     */
    public function forPayment(string $paymentId): array;
}
