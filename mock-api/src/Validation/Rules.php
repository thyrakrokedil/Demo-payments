<?php

declare(strict_types=1);

namespace MockPay\Validation;

/**
 * Reusable field checks that collect errors instead of throwing.
 *
 * Every check takes the dotted/indexed field path (e.g. `billing_address.email`,
 * `order_rows[1].vat_rate`), the raw value and returns whether the value was valid.
 */
final class Rules
{
    /**
     * @var list<FieldError>
     */
    private array $errors = [];

    public function add(string $field, string $message): void
    {
        $this->errors[] = new FieldError($field, $message);
    }

    /**
     * @return list<FieldError>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    public function count(): int
    {
        return \count($this->errors);
    }

    public function hasErrors(): bool
    {
        return [] !== $this->errors;
    }

    /**
     * Throws with every collected error, or does nothing when all checks passed.
     */
    public function throwIfInvalid(): void
    {
        if ([] !== $this->errors) {
            throw new ValidationException($this->errors);
        }
    }

    public function requiredString(string $path, mixed $value, int $min = 1, int $max = 255): bool
    {
        if (null === $value) {
            $this->add($path, 'Is required.');

            return false;
        }

        if (!\is_string($value)) {
            $this->add($path, 'Must be a string.');

            return false;
        }

        return $this->length($path, $value, $min, $max);
    }

    public function optionalString(string $path, mixed $value, int $max = 255): bool
    {
        if (null === $value) {
            return true;
        }

        if (!\is_string($value)) {
            $this->add($path, 'Must be a string.');

            return false;
        }

        if (mb_strlen($value) > $max) {
            $this->add($path, \sprintf('Must be at most %d characters.', $max));

            return false;
        }

        return true;
    }

    public function strictInt(string $path, mixed $value): bool
    {
        if (null === $value) {
            $this->add($path, 'Is required.');

            return false;
        }

        if (!\is_int($value)) {
            $this->add($path, 'Must be an integer.');

            return false;
        }

        return true;
    }

    public function intMin(string $path, mixed $value, int $min): bool
    {
        if (!$this->strictInt($path, $value)) {
            return false;
        }

        /** @var int $value */
        if ($value < $min) {
            $this->add($path, \sprintf('Must be at least %d.', $min));

            return false;
        }

        return true;
    }

    public function intMax(string $path, mixed $value, int $max, ?string $message = null): bool
    {
        if (!$this->strictInt($path, $value)) {
            return false;
        }

        /** @var int $value */
        if ($value > $max) {
            $this->add($path, $message ?? \sprintf('Must be at most %d.', $max));

            return false;
        }

        return true;
    }

    public function intRange(string $path, mixed $value, int $min, int $max, ?string $message = null): bool
    {
        if (!$this->strictInt($path, $value)) {
            return false;
        }

        /** @var int $value */
        if ($value < $min || $value > $max) {
            $this->add($path, $message ?? \sprintf('Must be between %d and %d.', $min, $max));

            return false;
        }

        return true;
    }

    /**
     * Required string that must also match a pattern; `$message` describes the expected format.
     */
    public function regex(string $path, mixed $value, string $pattern, string $message, int $max = 255): bool
    {
        if (!$this->requiredString($path, $value, 1, $max)) {
            return false;
        }

        /** @var string $value */
        if (1 !== preg_match($pattern, $value)) {
            $this->add($path, $message);

            return false;
        }

        return true;
    }

    public function email(string $path, mixed $value, int $max = 255): bool
    {
        if (!$this->requiredString($path, $value, 1, $max)) {
            return false;
        }

        /** @var string $value */
        if (false === filter_var($value, FILTER_VALIDATE_EMAIL)) {
            $this->add($path, 'Must be a valid email address.');

            return false;
        }

        return true;
    }

    /**
     * @param list<string> $allowed
     */
    public function enum(string $path, mixed $value, array $allowed): bool
    {
        if (null === $value) {
            $this->add($path, 'Is required.');

            return false;
        }

        if (!\is_string($value) || !\in_array($value, $allowed, true)) {
            $this->add($path, \sprintf('Must be one of: %s.', implode(', ', $allowed)));

            return false;
        }

        return true;
    }

    public static function asString(mixed $value): string
    {
        return \is_string($value) ? $value : '';
    }

    public static function asNullableString(mixed $value): ?string
    {
        return \is_string($value) ? $value : null;
    }

    public static function asInt(mixed $value): int
    {
        return \is_int($value) ? $value : 0;
    }

    private function length(string $path, string $value, int $min, int $max): bool
    {
        $length = mb_strlen($value);

        if ($length < $min || $length > $max) {
            $this->add($path, \sprintf('Must be between %d and %d characters.', $min, $max));

            return false;
        }

        return true;
    }
}
