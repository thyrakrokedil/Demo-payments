<?php

declare(strict_types=1);

namespace MockPay\Domain;

/**
 * @phpstan-type AddressInput array{
 *     first_name: string,
 *     last_name: string,
 *     company?: string|null,
 *     street_address: string,
 *     street_address2?: string|null,
 *     postal_code: string,
 *     city: string,
 *     region?: string|null,
 *     country: string,
 *     email: string,
 *     phone: string,
 * }
 * @phpstan-type AddressArray array{
 *     first_name: string,
 *     last_name: string,
 *     company: string|null,
 *     street_address: string,
 *     street_address2: string|null,
 *     postal_code: string,
 *     city: string,
 *     region: string|null,
 *     country: string,
 *     email: string,
 *     phone: string,
 * }
 */
final readonly class Address
{
    public function __construct(
        public string $firstName,
        public string $lastName,
        public ?string $company,
        public string $streetAddress,
        public ?string $streetAddress2,
        public string $postalCode,
        public string $city,
        public ?string $region,
        public string $country,
        public string $email,
        public string $phone,
    ) {}

    /**
     * Builds an address from an already validated snake_case array.
     *
     * @param AddressInput $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['first_name'],
            $data['last_name'],
            $data['company'] ?? null,
            $data['street_address'],
            $data['street_address2'] ?? null,
            $data['postal_code'],
            $data['city'],
            $data['region'] ?? null,
            $data['country'],
            $data['email'],
            $data['phone'],
        );
    }

    /**
     * @return AddressArray
     */
    public function toArray(): array
    {
        return [
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
            'company' => $this->company,
            'street_address' => $this->streetAddress,
            'street_address2' => $this->streetAddress2,
            'postal_code' => $this->postalCode,
            'city' => $this->city,
            'region' => $this->region,
            'country' => $this->country,
            'email' => $this->email,
            'phone' => $this->phone,
        ];
    }
}
