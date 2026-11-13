<?php

declare(strict_types=1);

namespace MockPay\Http\Action;

use MockPay\Application\PaymentService;
use MockPay\Http\JsonResponder;
use MockPay\Http\Middleware\JsonBodyParserMiddleware;
use MockPay\Http\PaymentPresenter;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Shallow-merges the request body onto a payment that is still in `created`.
 */
final class UpdatePaymentAction
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
        $payment = $this->payments->update(
            $args['id'],
            $request->getAttribute(JsonBodyParserMiddleware::ATTRIBUTE),
        );

        return $this->responder->json($this->presenter->present($payment));
    }
}
