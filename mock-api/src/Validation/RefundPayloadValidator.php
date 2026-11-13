<?php

declare(strict_types=1);

namespace MockPay\Validation;

/**
 * Validates and normalizes a refund payload. An empty body means "refund the remaining amount".
 *
 * @phpstan-import-type NormalizedOrderRow from OrderRowsValidator
 *
 * @phpstan-type NormalizedRefund array{
 *     amount: int|null,
 *     reference: string|null,
 *     order_rows: list<NormalizedOrderRow>|null
 * }
 */
final class RefundPayloadValidator
{
    public function __construct(
        private readonly OrderRowsValidator $orderRowsValidator = new OrderRowsValidator(),
    ) {}

    /**
     * @return NormalizedRefund
     *
     * @throws ValidationException
     */
    public function validate(mixed $body): array
    {
        if (null === $body) {
            $body = [];
        }

        if (!\is_array($body) || (([] !== $body) && array_is_list($body))) {
            throw ValidationException::single('body', 'Must be a JSON object.');
        }

        $rules = new Rules();

        $amount = $body['amount'] ?? null;
        $amountValid = null !== $amount && $rules->intMin('amount', $amount, 1);

        $rules->optionalString('reference', $body['reference'] ?? null);

        $rows = $body['order_rows'] ?? null;
        $errorsBeforeRows = $rules->count();
        $this->orderRowsValidator->validate('order_rows', $rows, $rules, false);
        $rowsValid = null !== $rows && $rules->count() === $errorsBeforeRows;

        if ($rowsValid && $amountValid && \is_array($rows)) {
            $sums = OrderRowsValidator::sumTotals($rows);

            if ($sums['total'] !== $amount) {
                $rules->add(
                    'amount',
                    \sprintf('Must equal the sum of order_rows.total_amount (%d).', $sums['total']),
                );
            }
        }

        $rules->throwIfInvalid();

        return [
            'amount' => \is_int($amount) ? $amount : null,
            'reference' => Rules::asNullableString($body['reference'] ?? null),
            'order_rows' => \is_array($rows) ? OrderRowsValidator::normalize($rows) : null,
        ];
    }
}
