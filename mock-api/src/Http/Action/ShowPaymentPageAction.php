<?php

declare(strict_types=1);

namespace MockPay\Http\Action;

use MockPay\Application\PaymentService;
use MockPay\Domain\Exception\PaymentNotFound;
use MockPay\Http\PaymentPageResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The customer-facing test payment page. Public: the payment id is the only key.
 */
final class ShowPaymentPageAction
{
    public function __construct(
        private readonly PaymentService $payments,
        private readonly PaymentPageResponder $responder,
    ) {}

    /**
     * @param array<string, string> $args
     */
    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        try {
            $payment = $this->payments->get($args['id']);
        } catch (PaymentNotFound) {
            return $this->responder->message(404, 'Payment not found', 'There is no payment with this id.');
        }

        return $this->responder->page($payment);
    }
}
