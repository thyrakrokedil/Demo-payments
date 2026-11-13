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
 * Creates a payment and answers 201 with a Location header.
 */
final class CreatePaymentAction
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
        $payment = $this->payments->create($request->getAttribute(JsonBodyParserMiddleware::ATTRIBUTE));

        // Built from the matched path so it keeps working when the app is
        // mounted under a prefix. Kept relative on purpose.
        $location = rtrim($request->getUri()->getPath(), '/') . '/' . $payment->id();

        return $this->responder->json(
            $this->presenter->present($payment),
            201,
            ['Location' => $location],
        );
    }
}
