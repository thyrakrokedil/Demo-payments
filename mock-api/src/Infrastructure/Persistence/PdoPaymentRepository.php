<?php

declare(strict_types=1);

namespace MockPay\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use MockPay\Domain\Exception\PaymentNotFound;
use MockPay\Domain\Page;
use MockPay\Domain\Payment;
use MockPay\Domain\PaymentDetails;
use MockPay\Domain\PaymentFilter;
use MockPay\Domain\PaymentRepository;
use MockPay\Domain\PaymentStatus;
use MockPay\Domain\Refund;
use PDO;
use RuntimeException;
use Throwable;

/**
 * SQLite backed repository: one row per payment (with JSON columns) plus one row per refund.
 *
 * @phpstan-import-type PaymentDetailsInput from PaymentDetails
 * @phpstan-import-type OrderRowInput from \MockPay\Domain\OrderRow
 * @phpstan-import-type AddressInput from \MockPay\Domain\Address
 */
final class PdoPaymentRepository implements PaymentRepository
{
    private const PAYMENT_COLUMNS = 'id, status, currency, merchant_reference, merchant_reference2, '
        . 'order_total, order_vat_amount, billing_address, shipping_address, order_rows, merchant_urls, '
        . 'created_at, updated_at';

    public function __construct(private readonly PDO $pdo) {}

    public function save(Payment $payment): void
    {
        $merchantUrls = $payment->merchantUrls();

        $this->pdo->beginTransaction();

        try {
            $statement = $this->pdo->prepare(
                'INSERT INTO payments (' . self::PAYMENT_COLUMNS . ') VALUES ('
                . ':id, :status, :currency, :merchant_reference, :merchant_reference2, '
                . ':order_total, :order_vat_amount, :billing_address, :shipping_address, :order_rows, '
                . ':merchant_urls, :created_at, :updated_at) '
                . 'ON CONFLICT(id) DO UPDATE SET '
                . 'status = excluded.status, '
                . 'currency = excluded.currency, '
                . 'merchant_reference = excluded.merchant_reference, '
                . 'merchant_reference2 = excluded.merchant_reference2, '
                . 'order_total = excluded.order_total, '
                . 'order_vat_amount = excluded.order_vat_amount, '
                . 'billing_address = excluded.billing_address, '
                . 'shipping_address = excluded.shipping_address, '
                . 'order_rows = excluded.order_rows, '
                . 'merchant_urls = excluded.merchant_urls, '
                . 'updated_at = excluded.updated_at',
            );

            $statement->execute([
                'id' => $payment->id(),
                'status' => $payment->status()->value,
                'currency' => $payment->currency(),
                'merchant_reference' => $payment->merchantReference(),
                'merchant_reference2' => $payment->merchantReference2(),
                'order_total' => $payment->orderTotal(),
                'order_vat_amount' => $payment->orderVatAmount(),
                'billing_address' => $this->encode($payment->billingAddress()->toArray()),
                'shipping_address' => $this->encode($payment->shippingAddress()->toArray()),
                'order_rows' => $this->encode(
                    array_map(static fn($row): array => $row->toArray(), $payment->orderRows()),
                ),
                'merchant_urls' => $merchantUrls === null ? null : $this->encode($merchantUrls->toArray()),
                'created_at' => $this->formatTimestamp($payment->createdAt()),
                'updated_at' => $this->formatTimestamp($payment->updatedAt()),
            ]);

            $refundStatement = $this->pdo->prepare(
                'INSERT OR IGNORE INTO refunds (id, payment_id, amount, reference, order_rows, created_at) '
                . 'VALUES (:id, :payment_id, :amount, :reference, :order_rows, :created_at)',
            );

            foreach ($payment->refunds() as $refund) {
                $rows = $refund->orderRows;

                $refundStatement->execute([
                    'id' => $refund->id,
                    'payment_id' => $payment->id(),
                    'amount' => $refund->amount,
                    'reference' => $refund->reference,
                    'order_rows' => $rows === null
                        ? null
                        : $this->encode(array_map(static fn($row): array => $row->toArray(), $rows)),
                    'created_at' => $this->formatTimestamp($refund->createdAt),
                ]);
            }

            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }

    public function get(string $id): Payment
    {
        $statement = $this->pdo->prepare('SELECT ' . self::PAYMENT_COLUMNS . ' FROM payments WHERE id = :id');
        $statement->execute(['id' => $id]);

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if (! \is_array($row)) {
            throw PaymentNotFound::withId($id);
        }

        return $this->hydrate($row, $this->refundsFor([$id])[$id] ?? []);
    }

    public function list(PaymentFilter $filter): Page
    {
        $where = '';
        $conditions = [];
        /** @var array<string, string> $parameters */
        $parameters = [];

        if ($filter->status instanceof PaymentStatus) {
            $conditions[] = 'status = :status';
            $parameters['status'] = $filter->status->value;
        }

        if ($filter->merchantReference !== null) {
            $conditions[] = 'merchant_reference = :mr';
            $parameters['mr'] = $filter->merchantReference;
        }

        if ($conditions !== []) {
            $where = ' WHERE ' . implode(' AND ', $conditions);
        }

        $countStatement = $this->pdo->prepare('SELECT COUNT(*) FROM payments' . $where);
        $countStatement->execute($parameters);
        $total = (int) $countStatement->fetchColumn();

        $statement = $this->pdo->prepare(
            'SELECT ' . self::PAYMENT_COLUMNS . ' FROM payments' . $where
            . ' ORDER BY created_at DESC, id DESC LIMIT :limit OFFSET :offset',
        );

        foreach ($parameters as $name => $value) {
            $statement->bindValue(':' . $name, $value, PDO::PARAM_STR);
        }

        $statement->bindValue(':limit', $filter->perPage, PDO::PARAM_INT);
        $statement->bindValue(':offset', $filter->offset(), PDO::PARAM_INT);
        $statement->execute();

        /** @var list<array<string, mixed>> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        $ids = array_map(static fn(array $row): string => (string) $row['id'], $rows);
        $refunds = $this->refundsFor($ids);

        $items = array_map(
            fn(array $row): Payment => $this->hydrate($row, $refunds[(string) $row['id']] ?? []),
            $rows,
        );

        return new Page($items, $filter->page, $filter->perPage, $total);
    }

    /**
     * @param array<string, mixed> $row
     * @param list<Refund>         $refunds
     */
    private function hydrate(array $row, array $refunds): Payment
    {
        /** @var PaymentDetailsInput $details */
        $details = [
            'currency' => (string) $row['currency'],
            'merchant_reference' => $row['merchant_reference'] === null ? null : (string) $row['merchant_reference'],
            'merchant_reference2' => $row['merchant_reference2'] === null ? null : (string) $row['merchant_reference2'],
            'order_total' => (int) $row['order_total'],
            'order_vat_amount' => (int) $row['order_vat_amount'],
            'billing_address' => $this->decode($row['billing_address']),
            'shipping_address' => $this->decode($row['shipping_address']),
            'order_rows' => array_values($this->decode($row['order_rows'])),
            'merchant_urls' => $row['merchant_urls'] === null ? null : $this->decode($row['merchant_urls']),
        ];

        return Payment::reconstitute(
            (string) $row['id'],
            PaymentStatus::from((string) $row['status']),
            PaymentDetails::fromArray($details),
            $refunds,
            new DateTimeImmutable((string) $row['created_at']),
            new DateTimeImmutable((string) $row['updated_at']),
        );
    }

    /**
     * @param list<string> $paymentIds
     *
     * @return array<string, list<Refund>>
     */
    private function refundsFor(array $paymentIds): array
    {
        if ($paymentIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, \count($paymentIds), '?'));

        $statement = $this->pdo->prepare(
            'SELECT id, payment_id, amount, reference, order_rows, created_at FROM refunds '
            . 'WHERE payment_id IN (' . $placeholders . ') ORDER BY created_at ASC, id ASC',
        );
        $statement->execute($paymentIds);

        /** @var list<array<string, mixed>> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        $grouped = [];

        foreach ($rows as $row) {
            $orderRows = $row['order_rows'];

            /** @var list<OrderRowInput>|null $decodedRows */
            $decodedRows = $orderRows === null ? null : array_values($this->decode($orderRows));

            $grouped[(string) $row['payment_id']][] = Refund::fromArray([
                'id' => (string) $row['id'],
                'amount' => (int) $row['amount'],
                'reference' => $row['reference'] === null ? null : (string) $row['reference'],
                'order_rows' => $decodedRows,
                'created_at' => (string) $row['created_at'],
            ]);
        }

        return $grouped;
    }

    /**
     * @param array<array-key, mixed> $value
     */
    private function encode(array $value): string
    {
        return json_encode($value, \JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<array-key, mixed>
     */
    private function decode(mixed $value): array
    {
        $decoded = json_decode((string) $value, true, 512, \JSON_THROW_ON_ERROR);

        if (! \is_array($decoded)) {
            throw new RuntimeException('Expected a JSON array in the database column.');
        }

        return $decoded;
    }

    private function formatTimestamp(DateTimeImmutable $timestamp): string
    {
        return $timestamp->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM);
    }
}
