<?php

declare(strict_types=1);

namespace MockPay\Application;

use MockPay\Domain\CallbackAttempt;
use MockPay\Domain\CallbackAttemptRepository;
use MockPay\Domain\Clock;
use MockPay\Domain\Exception\InvalidStateTransition;
use MockPay\Domain\IdGenerator;
use MockPay\Domain\OrderRow;
use MockPay\Domain\Page;
use MockPay\Domain\Payment;
use MockPay\Domain\PaymentDetails;
use MockPay\Domain\PaymentFilter;
use MockPay\Domain\PaymentRepository;
use MockPay\Domain\PaymentStatus;
use MockPay\Validation\PaymentPayloadValidator;
use MockPay\Validation\RefundPayloadValidator;
use MockPay\Validation\Rules;
use MockPay\Validation\ValidationException;

/**
 * The application layer: one method per use case, orchestrating validation,
 * the aggregate and persistence.
 */
final class PaymentService
{
    private const PER_PAGE_DEFAULT = 20;
    private const PER_PAGE_MAX = 100;

    public function __construct(
        private readonly PaymentRepository $payments,
        private readonly Clock $clock,
        private readonly IdGenerator $ids,
        private readonly PaymentPayloadValidator $paymentValidator,
        private readonly RefundPayloadValidator $refundValidator,
        private readonly CallbackDispatcher $callbacks,
        private readonly CallbackAttemptRepository $callbackAttempts,
    ) {}

    /**
     * @throws ValidationException
     */
    public function create(mixed $body): Payment
    {
        $validated = $this->paymentValidator->validate($body);

        $payment = Payment::create(
            $this->ids->paymentId(),
            PaymentDetails::fromArray($validated),
            $this->clock->now(),
        );

        $this->payments->save($payment);

        return $payment;
    }

    public function get(string $id): Payment
    {
        return $this->payments->get($id);
    }

    /**
     * @throws ValidationException
     */
    public function list(mixed $queryParams): Page
    {
        $params = \is_array($queryParams) ? $queryParams : [];

        return $this->payments->list($this->filterFrom($params));
    }

    /**
     * @throws ValidationException
     * @throws InvalidStateTransition
     */
    public function update(string $id, mixed $body): Payment
    {
        $payment = $this->payments->get($id);

        // The state check happens before validation so that a request against a
        // non-created payment always answers 409, even with an invalid body.
        if (! $payment->status()->canUpdate()) {
            throw new InvalidStateTransition('update', $payment->status());
        }

        if (! \is_array($body) || (([] !== $body) && array_is_list($body))) {
            throw ValidationException::single('body', 'Must be a JSON object.');
        }

        /** @var array<string, mixed> $merged */
        $merged = array_merge($payment->details()->toArray(), $body);

        $validated = $this->paymentValidator->validate($merged);

        $payment->update(PaymentDetails::fromArray($validated), $this->clock->now());
        $this->payments->save($payment);

        return $payment;
    }

    /**
     * @throws InvalidStateTransition
     */
    public function cancel(string $id): Payment
    {
        $payment = $this->payments->get($id);
        $payment->cancel($this->clock->now());
        $this->payments->save($payment);

        $this->callbacks->notify($payment, CallbackDispatcher::EVENT_CANCELLED);

        return $payment;
    }

    /**
     * @throws InvalidStateTransition
     */
    public function activate(string $id): Payment
    {
        $payment = $this->payments->get($id);
        $payment->activate($this->clock->now());
        $this->payments->save($payment);

        $this->callbacks->notify($payment, CallbackDispatcher::EVENT_ACTIVATED);

        return $payment;
    }

    /**
     * The customer paid on the payment page: runs the merchant's validation callback and,
     * when it approves, authorizes the payment.
     *
     * @return bool whether the payment was authorized; false when the merchant rejected it
     *
     * @throws InvalidStateTransition
     */
    public function authorize(string $id): bool
    {
        $payment = $this->payments->get($id);

        // Checked up front so that no validation callback is sent for an unpayable payment.
        if (! $payment->status()->canAuthorize()) {
            throw new InvalidStateTransition('authorize', $payment->status());
        }

        if (! $this->callbacks->validate($payment)) {
            return false;
        }

        $payment->authorize($this->clock->now());
        $this->payments->save($payment);

        return true;
    }

    /**
     * @return list<CallbackAttempt>
     */
    public function callbackAttempts(string $id): array
    {
        // Loaded for the 404 on an unknown payment.
        $this->payments->get($id);

        return $this->callbackAttempts->forPayment($id);
    }

    /**
     * @throws ValidationException
     * @throws InvalidStateTransition
     */
    public function refund(string $id, mixed $body): Payment
    {
        $payment = $this->payments->get($id);

        // 409 before 422: an unrefundable payment never reaches validation.
        if (! $payment->status()->canRefund()) {
            throw new InvalidStateTransition('refund', $payment->status());
        }

        $validated = $this->refundValidator->validate($body);

        $rows = $validated['order_rows'];
        $amount = $validated['amount'];

        if (null !== $rows && null === $amount) {
            $amount = array_sum(array_map(static fn(array $row): int => $row['total_amount'], $rows));
        }

        $orderRows = null === $rows
            ? null
            : array_map(static fn(array $row): OrderRow => OrderRow::fromArray($row), $rows);

        $refund = $payment->refund(
            $this->ids->refundId(),
            $amount,
            $validated['reference'],
            $orderRows,
            $this->clock->now(),
        );

        $this->payments->save($payment);

        $this->callbacks->notify($payment, CallbackDispatcher::EVENT_REFUNDED, [
            'refund' => [
                'id' => $refund->id,
                'amount' => $refund->amount,
                'reference' => $refund->reference,
            ],
        ]);

        return $payment;
    }

    /**
     * @param array<array-key, mixed> $params
     *
     * @throws ValidationException
     */
    private function filterFrom(array $params): PaymentFilter
    {
        $rules = new Rules();

        $page = 1;
        $pageRaw = $params['page'] ?? null;
        if (null !== $pageRaw && '' !== $pageRaw) {
            $parsed = $this->positiveInt($pageRaw);

            if (null === $parsed) {
                $rules->add('page', 'Must be an integer greater than or equal to 1.');
            } else {
                $page = $parsed;
            }
        }

        $perPage = self::PER_PAGE_DEFAULT;
        $perPageRaw = $params['per_page'] ?? null;
        if (null !== $perPageRaw && '' !== $perPageRaw) {
            $parsed = $this->positiveInt($perPageRaw);

            if (null === $parsed || $parsed > self::PER_PAGE_MAX) {
                $rules->add('per_page', \sprintf('Must be between 1 and %d.', self::PER_PAGE_MAX));
            } else {
                $perPage = $parsed;
            }
        }

        $status = null;
        $statusRaw = $params['status'] ?? null;
        if (null !== $statusRaw && '' !== $statusRaw) {
            $status = \is_string($statusRaw) ? PaymentStatus::tryFrom($statusRaw) : null;

            if (null === $status) {
                $rules->add('status', \sprintf(
                    'Must be one of: %s.',
                    implode(', ', array_map(
                        static fn(PaymentStatus $case): string => $case->value,
                        PaymentStatus::cases(),
                    )),
                ));
            }
        }

        $merchantReference = null;
        $merchantReferenceRaw = $params['merchant_reference'] ?? null;
        if (null !== $merchantReferenceRaw && '' !== $merchantReferenceRaw) {
            if (! \is_string($merchantReferenceRaw) || \strlen($merchantReferenceRaw) > 255) {
                $rules->add('merchant_reference', 'Must be a string of at most 255 characters.');
            } else {
                $merchantReference = $merchantReferenceRaw;
            }
        }

        $rules->throwIfInvalid();

        return new PaymentFilter($page, $perPage, $status, $merchantReference);
    }

    /** Accepts ints and digit-only strings (query values always arrive as strings). */
    private function positiveInt(mixed $value): ?int
    {
        if (\is_int($value)) {
            return $value >= 1 ? $value : null;
        }

        if (\is_string($value) && 1 === preg_match('/^\d+$/', $value)) {
            $int = (int) $value;

            return $int >= 1 ? $int : null;
        }

        return null;
    }
}
