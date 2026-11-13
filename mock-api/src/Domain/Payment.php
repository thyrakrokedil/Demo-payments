<?php

declare(strict_types=1);

namespace MockPay\Domain;

use DateTimeImmutable;
use MockPay\Domain\Exception\InvalidStateTransition;
use MockPay\Domain\Exception\RefundExceedsRemainingAmount;

/**
 * The payment aggregate: owns the state machine and the refund arithmetic.
 */
final class Payment
{
    /**
     * @param list<Refund> $refunds
     */
    private function __construct(
        private readonly string $id,
        private PaymentStatus $status,
        private PaymentDetails $details,
        private array $refunds,
        private readonly DateTimeImmutable $createdAt,
        private DateTimeImmutable $updatedAt,
    ) {}

    public static function create(string $id, PaymentDetails $details, DateTimeImmutable $now): self
    {
        return new self($id, PaymentStatus::Created, $details, [], $now, $now);
    }

    /**
     * @param list<Refund> $refunds
     */
    public static function reconstitute(
        string $id,
        PaymentStatus $status,
        PaymentDetails $details,
        array $refunds,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $updatedAt,
    ): self {
        return new self($id, $status, $details, $refunds, $createdAt, $updatedAt);
    }

    /**
     * @throws InvalidStateTransition
     */
    public function update(PaymentDetails $details, DateTimeImmutable $now): void
    {
        if (! $this->status->canUpdate()) {
            throw new InvalidStateTransition('update', $this->status);
        }

        $this->details = $details;
        $this->updatedAt = $now;
    }

    /**
     * Marks the payment as completed by the customer on the payment page.
     *
     * @throws InvalidStateTransition
     */
    public function authorize(DateTimeImmutable $now): void
    {
        if (! $this->status->canAuthorize()) {
            throw new InvalidStateTransition('authorize', $this->status);
        }

        $this->status = PaymentStatus::Authorized;
        $this->updatedAt = $now;
    }

    /**
     * @throws InvalidStateTransition
     */
    public function cancel(DateTimeImmutable $now): void
    {
        if (! $this->status->canCancel()) {
            throw new InvalidStateTransition('cancel', $this->status);
        }

        $this->status = PaymentStatus::Cancelled;
        $this->updatedAt = $now;
    }

    /**
     * @throws InvalidStateTransition
     */
    public function activate(DateTimeImmutable $now): void
    {
        if (! $this->status->canActivate()) {
            throw new InvalidStateTransition('activate', $this->status);
        }

        $this->status = PaymentStatus::Activated;
        $this->updatedAt = $now;
    }

    /**
     * @param list<OrderRow>|null $orderRows
     *
     * @throws InvalidStateTransition
     * @throws RefundExceedsRemainingAmount
     */
    public function refund(
        string $refundId,
        ?int $amount,
        ?string $reference,
        ?array $orderRows,
        DateTimeImmutable $now,
    ): Refund {
        if (! $this->status->canRefund()) {
            throw new InvalidStateTransition('refund', $this->status);
        }

        $remaining = $this->remainingRefundableAmount();
        $amount ??= $remaining;

        if ($amount < 1 || $amount > $remaining) {
            throw new RefundExceedsRemainingAmount($amount, $remaining);
        }

        $refund = new Refund($refundId, $amount, $reference, $orderRows, $now);
        $this->refunds[] = $refund;

        $this->status = $this->refundedAmount() === $this->capturedAmount()
            ? PaymentStatus::Refunded
            : PaymentStatus::PartiallyRefunded;
        $this->updatedAt = $now;

        return $refund;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function status(): PaymentStatus
    {
        return $this->status;
    }

    public function details(): PaymentDetails
    {
        return $this->details;
    }

    public function currency(): string
    {
        return $this->details->currency;
    }

    public function merchantReference(): ?string
    {
        return $this->details->merchantReference;
    }

    public function merchantReference2(): ?string
    {
        return $this->details->merchantReference2;
    }

    public function orderTotal(): int
    {
        return $this->details->orderTotal;
    }

    public function orderVatAmount(): int
    {
        return $this->details->orderVatAmount;
    }

    public function billingAddress(): Address
    {
        return $this->details->billingAddress;
    }

    public function shippingAddress(): Address
    {
        return $this->details->shippingAddress;
    }

    /**
     * @return list<OrderRow>
     */
    public function orderRows(): array
    {
        return $this->details->orderRows;
    }

    public function merchantUrls(): ?MerchantUrls
    {
        return $this->details->merchantUrls;
    }

    /**
     * @return list<Refund>
     */
    public function refunds(): array
    {
        return $this->refunds;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /** The full order total once the payment has been activated, 0 otherwise. */
    public function capturedAmount(): int
    {
        return match ($this->status) {
            PaymentStatus::Activated,
            PaymentStatus::PartiallyRefunded,
            PaymentStatus::Refunded => $this->details->orderTotal,
            default => 0,
        };
    }

    public function refundedAmount(): int
    {
        return array_sum(array_map(static fn(Refund $refund): int => $refund->amount, $this->refunds));
    }

    public function remainingRefundableAmount(): int
    {
        return $this->capturedAmount() - $this->refundedAmount();
    }
}
