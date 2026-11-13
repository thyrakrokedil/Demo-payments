<?php

declare(strict_types=1);

namespace MockPay\Tests\Unit\Domain;

use MockPay\Domain\Address;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AddressTest extends TestCase
{
    use PaymentDetailsFixture;

    #[Test]
    public function it_round_trips_through_arrays(): void
    {
        $data = self::addressArray();
        $address = Address::fromArray($data);

        self::assertSame('Ada', $address->firstName);
        self::assertSame('Lovelace', $address->lastName);
        self::assertSame('Krokedil AB', $address->company);
        self::assertSame('Storgatan 1', $address->streetAddress);
        self::assertSame('Apt 4', $address->streetAddress2);
        self::assertSame('11122', $address->postalCode);
        self::assertSame('Stockholm', $address->city);
        self::assertSame('Stockholms lan', $address->region);
        self::assertSame('SE', $address->country);
        self::assertSame('ada@example.com', $address->email);
        self::assertSame('+46701234567', $address->phone);

        self::assertSame($data, $address->toArray());
    }

    #[Test]
    public function it_defaults_the_optional_fields_to_null_when_missing(): void
    {
        $address = Address::fromArray([
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'street_address' => 'Storgatan 1',
            'postal_code' => '11122',
            'city' => 'Stockholm',
            'country' => 'SE',
            'email' => 'ada@example.com',
            'phone' => '+46701234567',
        ]);

        self::assertNull($address->company);
        self::assertNull($address->streetAddress2);
        self::assertNull($address->region);

        $array = $address->toArray();
        self::assertArrayHasKey('company', $array);
        self::assertArrayHasKey('street_address2', $array);
        self::assertArrayHasKey('region', $array);
        self::assertNull($array['company']);
        self::assertNull($array['street_address2']);
        self::assertNull($array['region']);
    }
}
