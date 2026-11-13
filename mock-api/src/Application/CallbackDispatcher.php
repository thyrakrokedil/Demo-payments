<?php

declare(strict_types=1);

namespace MockPay\Application;

use DateTimeZone;
use MockPay\Domain\CallbackAttempt;
use MockPay\Domain\CallbackAttemptRepository;
use MockPay\Domain\CallbackTransport;
use MockPay\Domain\Clock;
use MockPay\Domain\IdGenerator;
use MockPay\Domain\MerchantUrls;
use MockPay\Domain\Payment;
use MockPay\Http\PaymentPresenter;
use Psr\Log\LoggerInterface;

/**
 * Sends signed callbacks to a payment's merchant URLs and records every attempt.
 *
 * Delivery is synchronous and single-shot: a failed notification is recorded and logged,
 * never retried, and never undoes the state change that triggered it.
 */
final class CallbackDispatcher
{
    public const EVENT_VALIDATION = 'payment.validation';
    public const EVENT_ACTIVATED = 'payment.activated';
    public const EVENT_CANCELLED = 'payment.cancelled';
    public const EVENT_REFUNDED = 'payment.refunded';

    private const ENCODE_FLAGS = \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE;

    public function __construct(
        private readonly CallbackTransport $transport,
        private readonly CallbackSigner $signer,
        private readonly CallbackAttemptRepository $attempts,
        private readonly IdGenerator $ids,
        private readonly Clock $clock,
        private readonly PaymentPresenter $presenter,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Asks the merchant to approve the payment before it is authorized. Approved when no
     * validation URL is set or the merchant answers 2xx.
     */
    public function validate(Payment $payment): bool
    {
        $url = $payment->merchantUrls()?->validation;

        if (null === $url) {
            return true;
        }

        $attempt = $this->send($payment, $url, self::EVENT_VALIDATION, [
            'payment' => $this->presenter->present($payment),
        ]);

        return $attempt->succeeded();
    }

    /**
     * Tells the merchant that the payment changed state. Does nothing without a notification URL.
     *
     * @param array<string, mixed> $data extra fields for the body
     */
    public function notify(Payment $payment, string $event, array $data = []): void
    {
        $url = $payment->merchantUrls()?->notification;

        if (null === $url) {
            return;
        }

        $this->send($payment, $url, $event, array_merge(['status' => $payment->status()->value], $data));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function send(Payment $payment, string $urlTemplate, string $event, array $data): CallbackAttempt
    {
        $id = $this->ids->eventId();
        $now = $this->clock->now();
        $url = MerchantUrls::render($urlTemplate, $payment->id());

        $body = json_encode(
            array_merge(
                ['id' => $id, 'event' => $event, 'payment_id' => $payment->id()],
                $data,
                ['created_at' => $now->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM)],
            ),
            self::ENCODE_FLAGS,
        );

        $response = $this->transport->post($url, $body, [
            'Content-Type' => 'application/json',
            'User-Agent' => 'MockPay',
            'X-MockPay-Event' => $event,
            CallbackSigner::HEADER => $this->signer->sign($body),
        ]);

        $attempt = new CallbackAttempt(
            $id,
            $payment->id(),
            $event,
            $url,
            $response->status,
            $response->error,
            $response->durationMs,
            $now,
        );

        $this->attempts->add($attempt);

        if (! $attempt->succeeded()) {
            $this->logger->warning('Callback was not accepted by the merchant.', [
                'event' => $event,
                'payment_id' => $payment->id(),
                'url' => $url,
                'response_status' => $response->status,
                'error' => $response->error,
            ]);
        }

        return $attempt;
    }
}
