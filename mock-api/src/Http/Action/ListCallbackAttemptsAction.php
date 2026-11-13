<?php

declare(strict_types=1);

namespace MockPay\Http\Action;

use MockPay\Application\PaymentService;
use MockPay\Http\JsonResponder;
use MockPay\Http\PaymentPresenter;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Lists every callback sent for a payment, oldest first, with the merchant's answer.
 */
final class ListCallbackAttemptsAction
{
    public function __construct(
        private readonly PaymentService $payments,
        private readonly PaymentPresenter $presenter,
        private readonly JsonResponder $responder,
    ) {}

    /**
     * @param array<string, string> $args
     */
    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        return $this->responder->json(
            $this->presenter->presentCallbackAttempts($this->payments->callbackAttempts($args['id'])),
        );
    }
}
