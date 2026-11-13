<?php

declare(strict_types=1);

namespace MockPay\Http\Action;

use MockPay\Application\PaymentService;
use MockPay\Domain\Exception\PaymentNotFound;
use MockPay\Domain\MerchantUrls;
use MockPay\Http\PaymentPageResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Handles a button on the payment page and sends the customer back to the merchant:
 * "pay" authorizes (after the validation callback) and redirects to the success URL, or to
 * the failed URL when the merchant rejected it; "fail" and "cancel" only redirect.
 */
final class SubmitPaymentPageAction
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
        $id = $args['id'];

        try {
            $payment = $this->payments->get($id);
        } catch (PaymentNotFound) {
            return $this->responder->message(404, 'Payment not found', 'There is no payment with this id.');
        }

        if (! $payment->status()->canAuthorize()) {
            return $this->responder->message(409, 'Payment unavailable', \sprintf(
                'This payment is %s and can no longer be paid.',
                $payment->status()->value,
            ));
        }

        $outcome = match ($args['outcome']) {
            'pay' => $this->payments->authorize($id) ? 'success' : 'failed',
            'fail' => 'failed',
            default => 'cancel',
        };

        $template = match ($outcome) {
            'success' => $payment->merchantUrls()?->success,
            'failed' => $payment->merchantUrls()?->failed,
            default => $payment->merchantUrls()?->cancel,
        };

        if (null === $template) {
            return $this->responder->message(200, match ($outcome) {
                'success' => 'Payment authorized',
                'failed' => 'Payment failed',
                default => 'Checkout cancelled',
            }, \sprintf('The merchant did not set a %s URL, so there is nowhere to send you back to.', $outcome));
        }

        return $this->responder->redirect(MerchantUrls::render($template, $id));
    }
}
