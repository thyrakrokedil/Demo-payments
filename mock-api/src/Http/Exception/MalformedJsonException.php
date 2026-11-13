<?php

declare(strict_types=1);

namespace MockPay\Http\Exception;

use RuntimeException;

final class MalformedJsonException extends RuntimeException
{
    public static function create(): self
    {
        return new self('Request body is not valid JSON.');
    }
}
