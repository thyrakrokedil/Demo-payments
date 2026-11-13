<?php

declare(strict_types=1);

namespace MockPay\Tests\Unit\Domain;

use MockPay\Domain\PaymentStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PaymentStatusTest extends TestCase
{
    /**
     * @return array<string, array{PaymentStatus, bool, bool, bool, bool, bool}>
     */
    public static function statusProvider(): array
    {
        return [
            // status, canUpdate, canAuthorize, canCancel, canActivate, canRefund
            'created' => [PaymentStatus::Created, true, true, true, true, false],
            'authorized' => [PaymentStatus::Authorized, false, false, true, true, false],
            'activated' => [PaymentStatus::Activated, false, false, false, false, true],
            'partially_refunded' => [PaymentStatus::PartiallyRefunded, false, false, false, false, true],
            'refunded' => [PaymentStatus::Refunded, false, false, false, false, false],
            'cancelled' => [PaymentStatus::Cancelled, false, false, false, false, false],
        ];
    }

    #[Test]
    #[DataProvider('statusProvider')]
    public function it_exposes_the_allowed_transitions(
        PaymentStatus $status,
        bool $canUpdate,
        bool $canAuthorize,
        bool $canCancel,
        bool $canActivate,
        bool $canRefund,
    ): void {
        self::assertSame($canUpdate, $status->canUpdate());
        self::assertSame($canAuthorize, $status->canAuthorize());
        self::assertSame($canCancel, $status->canCancel());
        self::assertSame($canActivate, $status->canActivate());
        self::assertSame($canRefund, $status->canRefund());
    }

    #[Test]
    public function it_covers_every_case(): void
    {
        self::assertCount(\count(PaymentStatus::cases()), self::statusProvider());
    }
}
