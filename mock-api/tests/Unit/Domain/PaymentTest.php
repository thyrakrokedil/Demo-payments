<?php

declare(strict_types=1);

namespace MockPay\Tests\Unit\Domain;

use DateTimeImmutable;
use MockPay\Domain\Exception\InvalidStateTransition;
use MockPay\Domain\Exception\RefundExceedsRemainingAmount;
use MockPay\Domain\Payment;
use MockPay\Domain\PaymentDetails;
use MockPay\Domain\PaymentStatus;
use MockPay\Domain\Refund;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PaymentTest extends TestCase
{
    use PaymentDetailsFixture;

    private const string NOW = '2026-01-02T03:04:05+00:00';
    private const string LATER = '2026-01-03T03:04:05+00:00';
    private const int ORDER_TOTAL = 24900;

    private static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable(self::NOW);
    }

    private static function later(): DateTimeImmutable
    {
        return new DateTimeImmutable(self::LATER);
    }

    private static function payment(): Payment
    {
        return Payment::create('pay_1', self::details(), self::now());
    }

    /**
     * @param list<Refund> $refunds
     */
    private static function paymentIn(PaymentStatus $status, array $refunds = []): Payment
    {
        return Payment::reconstitute('pay_1', $status, self::details(), $refunds, self::now(), self::now());
    }

    #[Test]
    public function it_starts_in_created_with_no_refunds(): void
    {
        $payment = self::payment();

        self::assertSame('pay_1', $payment->id());
        self::assertSame(PaymentStatus::Created, $payment->status());
        self::assertSame('SEK', $payment->currency());
        self::assertSame('wc-1234', $payment->merchantReference());
        self::assertSame('#1234', $payment->merchantReference2());
        self::assertSame(self::ORDER_TOTAL, $payment->orderTotal());
        self::assertSame(4980, $payment->orderVatAmount());
        self::assertSame('Ada', $payment->billingAddress()->firstName);
        self::assertSame('Grace', $payment->shippingAddress()->firstName);
        self::assertCount(2, $payment->orderRows());
        self::assertSame([], $payment->refunds());
        self::assertEquals(self::now(), $payment->createdAt());
        self::assertEquals(self::now(), $payment->updatedAt());

        self::assertSame(0, $payment->capturedAmount());
        self::assertSame(0, $payment->refundedAmount());
        self::assertSame(0, $payment->remainingRefundableAmount());
    }

    /**
     * @return array<string, array{PaymentStatus, string, bool}>
     */
    public static function transitionProvider(): array
    {
        $cases = [];
        $allowed = [
            'created' => ['update' => true, 'authorize' => true, 'cancel' => true, 'activate' => true, 'refund' => false],
            'authorized' => ['update' => false, 'authorize' => false, 'cancel' => true, 'activate' => true, 'refund' => false],
            'activated' => ['update' => false, 'authorize' => false, 'cancel' => false, 'activate' => false, 'refund' => true],
            'partially_refunded' => ['update' => false, 'authorize' => false, 'cancel' => false, 'activate' => false, 'refund' => true],
            'refunded' => ['update' => false, 'authorize' => false, 'cancel' => false, 'activate' => false, 'refund' => false],
            'cancelled' => ['update' => false, 'authorize' => false, 'cancel' => false, 'activate' => false, 'refund' => false],
        ];

        foreach ($allowed as $status => $actions) {
            foreach ($actions as $action => $isAllowed) {
                $cases[$status . ' / ' . $action] = [PaymentStatus::from($status), $action, $isAllowed];
            }
        }

        return $cases;
    }

    #[Test]
    #[DataProvider('transitionProvider')]
    public function it_enforces_the_state_machine(PaymentStatus $status, string $action, bool $isAllowed): void
    {
        // A partially refunded payment needs an existing refund so that something remains refundable.
        $refunds = $status === PaymentStatus::PartiallyRefunded
            ? [new Refund('ref_0', 400, null, null, self::now())]
            : [];
        if ($status === PaymentStatus::Refunded) {
            $refunds = [new Refund('ref_0', self::ORDER_TOTAL, null, null, self::now())];
        }

        $payment = self::paymentIn($status, $refunds);

        if (! $isAllowed) {
            try {
                self::applyAction($payment, $action);
                self::fail(\sprintf('Expected %s to be rejected in status %s.', $action, $status->value));
            } catch (InvalidStateTransition $e) {
                self::assertSame($action, $e->action());
                self::assertSame($status, $e->currentStatus());
                self::assertSame($status, $payment->status());

                return;
            }
        }

        self::applyAction($payment, $action);

        $expected = match ($action) {
            'authorize' => PaymentStatus::Authorized,
            'cancel' => PaymentStatus::Cancelled,
            'activate' => PaymentStatus::Activated,
            'refund' => PaymentStatus::Refunded,
            default => $status,
        };

        self::assertSame($expected, $payment->status());
    }

    private static function applyAction(Payment $payment, string $action): void
    {
        match ($action) {
            'update' => $payment->update(self::details(), self::later()),
            'cancel' => $payment->cancel(self::later()),
            'authorize' => $payment->authorize(self::later()),
            'activate' => $payment->activate(self::later()),
            'refund' => $payment->refund('ref_1', null, null, null, self::later()),
            default => self::fail(\sprintf('Unknown action "%s".', $action)),
        };
    }

    #[Test]
    public function update_replaces_the_details_and_touches_updated_at(): void
    {
        $payment = self::payment();
        $data = self::detailsArray();
        $data['merchant_reference'] = 'wc-9999';
        $details = PaymentDetails::fromArray($data);

        $payment->update($details, self::later());

        self::assertSame($details, $payment->details());
        self::assertSame('wc-9999', $payment->merchantReference());
        self::assertEquals(self::later(), $payment->updatedAt());
        self::assertEquals(self::now(), $payment->createdAt());
    }

    #[Test]
    public function activate_captures_the_full_order_total(): void
    {
        $payment = self::payment();
        $payment->activate(self::later());

        self::assertSame(PaymentStatus::Activated, $payment->status());
        self::assertSame(self::ORDER_TOTAL, $payment->capturedAmount());
        self::assertSame(0, $payment->refundedAmount());
        self::assertSame(self::ORDER_TOTAL, $payment->remainingRefundableAmount());
        self::assertEquals(self::later(), $payment->updatedAt());
    }

    #[Test]
    public function cancel_touches_updated_at(): void
    {
        $payment = self::payment();
        $payment->cancel(self::later());

        self::assertSame(PaymentStatus::Cancelled, $payment->status());
        self::assertSame(0, $payment->capturedAmount());
        self::assertEquals(self::later(), $payment->updatedAt());
    }

    #[Test]
    public function a_partial_refund_moves_to_partially_refunded_and_then_to_refunded(): void
    {
        $payment = self::payment();
        $payment->activate(self::now());

        $first = $payment->refund('ref_1', 4900, 'shipping back', null, self::later());

        self::assertSame('ref_1', $first->id);
        self::assertSame(4900, $first->amount);
        self::assertSame('shipping back', $first->reference);
        self::assertNull($first->orderRows);
        self::assertEquals(self::later(), $first->createdAt);

        self::assertSame(PaymentStatus::PartiallyRefunded, $payment->status());
        self::assertSame(self::ORDER_TOTAL, $payment->capturedAmount());
        self::assertSame(4900, $payment->refundedAmount());
        self::assertSame(20000, $payment->remainingRefundableAmount());
        self::assertSame([$first], $payment->refunds());

        $second = $payment->refund('ref_2', 20000, null, null, self::later());

        self::assertSame(PaymentStatus::Refunded, $payment->status());
        self::assertSame(self::ORDER_TOTAL, $payment->refundedAmount());
        self::assertSame(0, $payment->remainingRefundableAmount());
        self::assertSame([$first, $second], $payment->refunds());
    }

    #[Test]
    public function a_refund_without_an_amount_refunds_the_remaining_amount(): void
    {
        $payment = self::payment();
        $payment->activate(self::now());
        $payment->refund('ref_1', 900, null, null, self::now());

        $refund = $payment->refund('ref_2', null, null, null, self::later());

        self::assertSame(24000, $refund->amount);
        self::assertSame(PaymentStatus::Refunded, $payment->status());
        self::assertSame(0, $payment->remainingRefundableAmount());
    }

    #[Test]
    public function a_refund_can_carry_order_rows(): void
    {
        $payment = self::payment();
        $payment->activate(self::now());

        $rows = [$payment->orderRows()[1]];
        $refund = $payment->refund('ref_1', 4900, null, $rows, self::later());

        self::assertSame($rows, $refund->orderRows);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function invalidAmountProvider(): array
    {
        return [
            'zero' => [0],
            'negative' => [-1],
            'above remaining' => [self::ORDER_TOTAL + 1],
        ];
    }

    #[Test]
    #[DataProvider('invalidAmountProvider')]
    public function it_rejects_refund_amounts_outside_the_remaining_amount(int $amount): void
    {
        $payment = self::payment();
        $payment->activate(self::now());

        try {
            $payment->refund('ref_1', $amount, null, null, self::later());
            self::fail('Expected the refund to be rejected.');
        } catch (RefundExceedsRemainingAmount $e) {
            self::assertSame($amount, $e->amount());
            self::assertSame(self::ORDER_TOTAL, $e->remaining());
        }

        self::assertSame(PaymentStatus::Activated, $payment->status());
        self::assertSame([], $payment->refunds());
    }

    #[Test]
    public function it_rejects_a_partial_refund_larger_than_what_remains(): void
    {
        $payment = self::payment();
        $payment->activate(self::now());
        $payment->refund('ref_1', 20000, null, null, self::now());

        $this->expectException(RefundExceedsRemainingAmount::class);

        $payment->refund('ref_2', 4901, null, null, self::later());
    }

    #[Test]
    public function it_rejects_a_refund_on_a_fully_refunded_payment(): void
    {
        $payment = self::payment();
        $payment->activate(self::now());
        $payment->refund('ref_1', self::ORDER_TOTAL, null, null, self::now());

        self::assertSame(PaymentStatus::Refunded, $payment->status());

        $this->expectException(InvalidStateTransition::class);

        $payment->refund('ref_2', 1, null, null, self::later());
    }

    #[Test]
    public function reconstitute_preserves_the_refunds_and_the_computed_amounts(): void
    {
        $refunds = [
            new Refund('ref_1', 4900, null, null, self::now()),
            new Refund('ref_2', 100, null, null, self::now()),
        ];

        $payment = Payment::reconstitute(
            'pay_9',
            PaymentStatus::PartiallyRefunded,
            self::details(),
            $refunds,
            self::now(),
            self::later(),
        );

        self::assertSame('pay_9', $payment->id());
        self::assertSame($refunds, $payment->refunds());
        self::assertSame(self::ORDER_TOTAL, $payment->capturedAmount());
        self::assertSame(5000, $payment->refundedAmount());
        self::assertSame(19900, $payment->remainingRefundableAmount());
        self::assertEquals(self::now(), $payment->createdAt());
        self::assertEquals(self::later(), $payment->updatedAt());
    }
}
