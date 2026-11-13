<?php

declare(strict_types=1);

namespace MockPay\Validation;

/**
 * A single validation failure: the dotted/indexed path of the offending field and a human message.
 */
final readonly class FieldError
{
    public function __construct(
        public string $field,
        public string $message,
    ) {}

    /**
     * @return array{field: string, message: string}
     */
    public function toArray(): array
    {
        return [
            'field' => $this->field,
            'message' => $this->message,
        ];
    }
}
