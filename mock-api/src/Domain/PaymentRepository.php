<?php

declare(strict_types=1);

namespace MockPay\Domain;

use MockPay\Domain\Exception\PaymentNotFound;

interface PaymentRepository
{
    /** Inserts or updates the payment and persists any refunds not yet stored. */
    public function save(Payment $payment): void;

    /**
     * @throws PaymentNotFound
     */
    public function get(string $id): Payment;

    /** Newest first (created_at DESC, id DESC). */
    public function list(PaymentFilter $filter): Page;
}
