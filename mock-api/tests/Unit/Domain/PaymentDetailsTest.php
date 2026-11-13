<?php

declare(strict_types=1);

namespace MockPay\Tests\Unit\Domain;

use MockPay\Domain\OrderRowType;
use MockPay\Domain\PaymentDetails;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PaymentDetailsTest extends TestCase
{
    use PaymentDetailsFixture;

    #[Test]
    public function it_round_trips_through_arrays(): void
    {
        $data = self::detailsArray();
        $details = PaymentDetails::fromArray($data);

        self::assertSame('SEK', $details->currency);
        self::assertSame('wc-1234', $details->merchantReference);
        self::assertSame('#1234', $details->merchantReference2);
        self::assertSame(24900, $details->orderTotal);
        self::assertSame(4980, $details->orderVatAmount);
        self::assertSame('Ada', $details->billingAddress->firstName);
        self::assertSame('Grace', $details->shippingAddress->firstName);
        self::assertCount(2, $details->orderRows);
        self::assertSame(OrderRowType::Physical, $details->orderRows[0]->type);
        self::assertSame(OrderRowType::Shipping, $details->orderRows[1]->type);

        self::assertSame($data, $details->toArray());
    }

    #[Test]
    public function it_defaults_the_merchant_references_to_null_when_missing(): void
    {
        $data = self::detailsArray();
        unset($data['merchant_reference'], $data['merchant_reference2']);

        $details = PaymentDetails::fromArray($data);

        self::assertNull($details->merchantReference);
        self::assertNull($details->merchantReference2);

        $array = $details->toArray();
        self::assertNull($array['merchant_reference']);
        self::assertNull($array['merchant_reference2']);
    }
}
