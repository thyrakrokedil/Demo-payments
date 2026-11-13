<?php

declare(strict_types=1);

namespace MockPay\Validation;

use MockPay\Domain\OrderRowType;

/**
 * Validates an `order_rows` array. Shared by the payment and the refund payload validators.
 *
 * @phpstan-type NormalizedOrderRow array{
 *     type: string,
 *     sku: string,
 *     name: string,
 *     reference: string|null,
 *     quantity: int,
 *     unit_amount: int,
 *     total_amount: int,
 *     vat_amount: int,
 *     vat_rate: int
 * }
 */
final class OrderRowsValidator
{
    public const VAT_RATE_MAX = 10000;

    public function validate(string $path, mixed $rows, Rules $rules, bool $required = true): void
    {
        if (null === $rows && !$required) {
            return;
        }

        if (!\is_array($rows) || [] === $rows || !array_is_list($rows)) {
            $rules->add($path, 'Must be a non-empty array.');

            return;
        }

        foreach ($rows as $index => $row) {
            $this->validateRow(\sprintf('%s[%d]', $path, $index), $row, $rules);
        }
    }

    /**
     * Sums the amounts of already validated rows. Non-integer values are ignored.
     *
     * @param array<array-key, mixed> $rows
     *
     * @return array{total: int, vat: int}
     */
    public static function sumTotals(array $rows): array
    {
        $total = 0;
        $vat = 0;

        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }

            $rowTotal = $row['total_amount'] ?? null;
            if (\is_int($rowTotal)) {
                $total += $rowTotal;
            }

            $rowVat = $row['vat_amount'] ?? null;
            if (\is_int($rowVat)) {
                $vat += $rowVat;
            }
        }

        return ['total' => $total, 'vat' => $vat];
    }

    /**
     * Reduces a validated row to the known keys, with absent optionals as null.
     *
     * @param array<array-key, mixed> $row
     *
     * @return NormalizedOrderRow
     */
    public static function normalizeRow(array $row): array
    {
        return [
            'type' => Rules::asString($row['type'] ?? null),
            'sku' => Rules::asString($row['sku'] ?? null),
            'name' => Rules::asString($row['name'] ?? null),
            'reference' => Rules::asNullableString($row['reference'] ?? null),
            'quantity' => Rules::asInt($row['quantity'] ?? null),
            'unit_amount' => Rules::asInt($row['unit_amount'] ?? null),
            'total_amount' => Rules::asInt($row['total_amount'] ?? null),
            'vat_amount' => Rules::asInt($row['vat_amount'] ?? null),
            'vat_rate' => Rules::asInt($row['vat_rate'] ?? null),
        ];
    }

    /**
     * @param array<array-key, mixed> $rows
     *
     * @return list<NormalizedOrderRow>
     */
    public static function normalize(array $rows): array
    {
        $normalized = [];

        foreach ($rows as $row) {
            $normalized[] = self::normalizeRow(\is_array($row) ? $row : []);
        }

        return $normalized;
    }

    private function validateRow(string $path, mixed $row, Rules $rules): void
    {
        if (!\is_array($row) || (([] !== $row) && array_is_list($row))) {
            $rules->add($path, 'Must be an object.');

            return;
        }

        $type = $row['type'] ?? null;
        $typeValid = $rules->enum($path . '.type', $type, OrderRowType::values());

        $rules->requiredString($path . '.sku', $row['sku'] ?? null);
        $rules->requiredString($path . '.name', $row['name'] ?? null);
        $rules->optionalString($path . '.reference', $row['reference'] ?? null);

        $quantity = $row['quantity'] ?? null;
        $quantityValid = $rules->intMin($path . '.quantity', $quantity, 1);

        $unitAmount = $row['unit_amount'] ?? null;
        $unitAmountValid = $rules->strictInt($path . '.unit_amount', $unitAmount);

        if ($unitAmountValid && $typeValid) {
            /** @var int $unitAmount */
            if (OrderRowType::Discount->value === $type) {
                if ($unitAmount > 0) {
                    $rules->add($path . '.unit_amount', 'Must be zero or negative for discount rows.');
                    $unitAmountValid = false;
                }
            } elseif ($unitAmount < 0) {
                $rules->add($path . '.unit_amount', 'Must be at least 0.');
                $unitAmountValid = false;
            }
        }

        $totalAmount = $row['total_amount'] ?? null;
        $totalAmountValid = $rules->strictInt($path . '.total_amount', $totalAmount);

        $rules->strictInt($path . '.vat_amount', $row['vat_amount'] ?? null);

        $rules->intRange(
            $path . '.vat_rate',
            $row['vat_rate'] ?? null,
            0,
            self::VAT_RATE_MAX,
            \sprintf('Must be between 0 and %d (percent × 100).', self::VAT_RATE_MAX),
        );

        if ($quantityValid && $unitAmountValid && $totalAmountValid) {
            /** @var int $quantity */
            /** @var int $unitAmount */
            /** @var int $totalAmount */
            $expected = $unitAmount * $quantity;

            if ($expected !== $totalAmount) {
                $rules->add(
                    $path . '.total_amount',
                    \sprintf('Must equal unit_amount * quantity (%d).', $expected),
                );
            }
        }
    }
}
