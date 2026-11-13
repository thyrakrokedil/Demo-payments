<?php

declare(strict_types=1);

namespace MockPay\Validation;

use RuntimeException;

/**
 * Thrown when a request payload fails validation. Carries every collected error.
 */
final class ValidationException extends RuntimeException
{
    /**
     * @var list<FieldError>
     */
    private array $errors;

    /**
     * @param list<FieldError> $errors
     */
    public function __construct(array $errors)
    {
        $this->errors = $errors;

        $count = \count($errors);
        $noun = 1 === $count ? 'field' : 'fields';

        parent::__construct(\sprintf('The request contains %d invalid %s.', $count, $noun));
    }

    public static function single(string $field, string $message): self
    {
        return new self([new FieldError($field, $message)]);
    }

    /**
     * @return list<FieldError>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * @return list<array{field: string, message: string}>
     */
    public function errorsAsArray(): array
    {
        return array_map(static fn(FieldError $error): array => $error->toArray(), $this->errors);
    }
}
