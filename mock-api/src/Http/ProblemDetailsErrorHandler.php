<?php

declare(strict_types=1);

namespace MockPay\Http;

use MockPay\Domain\Exception\InvalidStateTransition;
use MockPay\Domain\Exception\PaymentNotFound;
use MockPay\Domain\Exception\RefundExceedsRemainingAmount;
use MockPay\Http\Exception\MalformedJsonException;
use MockPay\Validation\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpMethodNotAllowedException;
use Slim\Exception\HttpNotFoundException;
use Slim\Exception\HttpSpecializedException;
use Slim\Interfaces\ErrorHandlerInterface;
use Throwable;

/**
 * Maps every exception to an RFC 9457 problem response. This is the error contract.
 */
final class ProblemDetailsErrorHandler implements ErrorHandlerInterface
{
    public function __construct(
        private readonly JsonResponder $responder,
        private readonly LoggerInterface $logger,
        private readonly bool $debug = false,
    ) {}

    public function __invoke(
        ServerRequestInterface $request,
        Throwable $exception,
        bool $displayErrorDetails,
        bool $logErrors,
        bool $logErrorDetails,
    ): ResponseInterface {
        return match (true) {
            $exception instanceof MalformedJsonException => $this->responder->problem(
                400,
                'malformed-json',
                'Malformed JSON',
                $exception->getMessage(),
            ),
            $exception instanceof ValidationException => $this->responder->problem(
                422,
                'validation-error',
                'Validation failed',
                $exception->getMessage(),
                ['errors' => $exception->errorsAsArray()],
            ),
            $exception instanceof RefundExceedsRemainingAmount => $this->responder->problem(
                422,
                'validation-error',
                'Validation failed',
                'The request contains 1 invalid field.',
                ['errors' => [[
                    'field' => 'amount',
                    'message' => \sprintf(
                        'Must be between 1 and the remaining refundable amount (%d).',
                        $exception->remaining(),
                    ),
                ]]],
            ),
            $exception instanceof PaymentNotFound => $this->responder->problem(
                404,
                'not-found',
                'Not found',
                $exception->getMessage(),
            ),
            $exception instanceof InvalidStateTransition => $this->responder->problem(
                409,
                'invalid-state',
                'Invalid state',
                $exception->getMessage(),
                [
                    'action' => $exception->action(),
                    'current_status' => $exception->currentStatus()->value,
                ],
            ),
            $exception instanceof HttpNotFoundException => $this->responder->problem(
                404,
                'not-found',
                'Not found',
                \sprintf(
                    'No route matches %s %s.',
                    $request->getMethod(),
                    $request->getUri()->getPath(),
                ),
            ),
            $exception instanceof HttpMethodNotAllowedException => $this->methodNotAllowed($exception),
            $exception instanceof HttpSpecializedException => $this->responder->problem(
                $exception->getCode(),
                'http-error',
                $exception->getTitle(),
                $exception->getDescription(),
            ),
            default => $this->internalError($exception),
        };
    }

    private function methodNotAllowed(HttpMethodNotAllowedException $exception): ResponseInterface
    {
        $allowed = implode(', ', $exception->getAllowedMethods());

        return $this->responder->problem(
            405,
            'method-not-allowed',
            'Method not allowed',
            \sprintf('The allowed methods for this resource are: %s.', $allowed),
            [],
            ['Allow' => $allowed],
        );
    }

    private function internalError(Throwable $exception): ResponseInterface
    {
        $this->logger->error($exception->getMessage(), ['exception' => $exception]);

        return $this->responder->problem(
            500,
            'internal-error',
            'Internal server error',
            $this->debug ? $exception->getMessage() : null,
        );
    }
}
