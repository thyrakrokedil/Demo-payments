<?php

declare(strict_types=1);

namespace MockPay\Validation;

/**
 * Validates one address object (billing or shipping). Unknown keys are ignored.
 *
 * @phpstan-type NormalizedAddress array{
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
 *     phone: string
 * }
 */
final class AddressValidator
{
    public function validate(string $path, mixed $address, Rules $rules): void
    {
        if (!\is_array($address) || (([] !== $address) && array_is_list($address))) {
            $rules->add($path, 'Must be an object.');

            return;
        }

        $rules->requiredString($path . '.first_name', $address['first_name'] ?? null);
        $rules->requiredString($path . '.last_name', $address['last_name'] ?? null);
        $rules->optionalString($path . '.company', $address['company'] ?? null);
        $rules->requiredString($path . '.street_address', $address['street_address'] ?? null);
        $rules->optionalString($path . '.street_address2', $address['street_address2'] ?? null);
        $rules->requiredString($path . '.postal_code', $address['postal_code'] ?? null, 1, 32);
        $rules->requiredString($path . '.city', $address['city'] ?? null);
        $rules->optionalString($path . '.region', $address['region'] ?? null);
        $rules->regex(
            $path . '.country',
            $address['country'] ?? null,
            '/^[A-Z]{2}$/',
            'Must be a valid ISO 3166-1 alpha-2 country code (e.g. SE).',
        );
        $rules->email($path . '.email', $address['email'] ?? null);
        $rules->requiredString($path . '.phone', $address['phone'] ?? null, 1, 50);
    }

    /**
     * Reduces a validated address to the known keys, with absent optionals as null.
     *
     * @param array<array-key, mixed> $address
     *
     * @return NormalizedAddress
     */
    public static function normalize(array $address): array
    {
        return [
            'first_name' => Rules::asString($address['first_name'] ?? null),
            'last_name' => Rules::asString($address['last_name'] ?? null),
            'company' => Rules::asNullableString($address['company'] ?? null),
            'street_address' => Rules::asString($address['street_address'] ?? null),
            'street_address2' => Rules::asNullableString($address['street_address2'] ?? null),
            'postal_code' => Rules::asString($address['postal_code'] ?? null),
            'city' => Rules::asString($address['city'] ?? null),
            'region' => Rules::asNullableString($address['region'] ?? null),
            'country' => Rules::asString($address['country'] ?? null),
            'email' => Rules::asString($address['email'] ?? null),
            'phone' => Rules::asString($address['phone'] ?? null),
        ];
    }
}
