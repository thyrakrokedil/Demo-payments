<?php

declare(strict_types=1);

namespace MockPay\Validation;

use MockPay\Domain\MerchantUrls;

/**
 * Validates the optional merchant_urls object. Every URL is optional, must be an absolute
 * http(s) URL and may contain the {{PAYMENT_ID}} placeholder. Unknown keys are ignored.
 *
 * @phpstan-type NormalizedMerchantUrls array{
 *     validation: string|null,
 *     notification: string|null,
 *     success: string|null,
 *     failed: string|null,
 *     cancel: string|null
 * }
 */
final class MerchantUrlsValidator
{
    public const KEYS = ['validation', 'notification', 'success', 'failed', 'cancel'];

    private const MAX_LENGTH = 2048;

    public function validate(string $path, mixed $urls, Rules $rules): void
    {
        if (null === $urls) {
            return;
        }

        if (!\is_array($urls) || (([] !== $urls) && array_is_list($urls))) {
            $rules->add($path, 'Must be an object.');

            return;
        }

        foreach (self::KEYS as $key) {
            $field = $path . '.' . $key;
            $value = $urls[$key] ?? null;

            if (null === $value || !$rules->optionalString($field, $value, self::MAX_LENGTH)) {
                continue;
            }

            /** @var string $value */
            if (!self::isHttpUrl(MerchantUrls::render($value, 'pay_0'))) {
                $rules->add($field, 'Must be an absolute http or https URL.');
            }
        }
    }

    /**
     * Reduces validated merchant URLs to the known keys, or null when none were sent.
     *
     * @return NormalizedMerchantUrls|null
     */
    public static function normalize(mixed $urls): ?array
    {
        if (!\is_array($urls)) {
            return null;
        }

        return [
            'validation' => Rules::asNullableString($urls['validation'] ?? null),
            'notification' => Rules::asNullableString($urls['notification'] ?? null),
            'success' => Rules::asNullableString($urls['success'] ?? null),
            'failed' => Rules::asNullableString($urls['failed'] ?? null),
            'cancel' => Rules::asNullableString($urls['cancel'] ?? null),
        ];
    }

    private static function isHttpUrl(string $url): bool
    {
        if (false === filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);

        return \is_string($scheme) && \in_array(strtolower($scheme), ['http', 'https'], true);
    }
}
