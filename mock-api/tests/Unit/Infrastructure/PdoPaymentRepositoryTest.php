<?php

declare(strict_types=1);

namespace MockPay\Tests\Unit\Infrastructure;

use DateTimeImmutable;
use MockPay\Domain\Exception\PaymentNotFound;
use MockPay\Domain\OrderRow;
use MockPay\Domain\Payment;
use MockPay\Domain\PaymentDetails;
use MockPay\Domain\PaymentFilter;
use MockPay\Domain\PaymentStatus;
use MockPay\Infrastructure\Persistence\PdoFactory;
use MockPay\Infrastructure\Persistence\PdoPaymentRepository;
use MockPay\Infrastructure\Persistence\SqliteMigrator;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PdoPaymentRepository::class)]
final class PdoPaymentRepositoryTest extends TestCase
{
    private PDO $pdo;

    private PdoPaymentRepository $repository;

    protected function setUp(): void
    {
        $this->pdo = PdoFactory::create(':memory:');
        (new SqliteMigrator($this->pdo, \dirname(__DIR__, 3) . '/database/migrations'))->migrate();
        $this->repository = new PdoPaymentRepository($this->pdo);
    }

    public function testSaveAndGetRoundTripsACreatedPayment(): void
    {
        $now = new DateTimeImmutable('2026-01-02T03:04:05+00:00');
        $payment = Payment::create('pay_1', $this->details(), $now);

        $this->repository->save($payment);

        $loaded = $this->repository->get('pay_1');

        self::assertSame('pay_1', $loaded->id());
        self::assertSame(PaymentStatus::Created, $loaded->status());
        self::assertSame($payment->details()->toArray(), $loaded->details()->toArray());
        self::assertSame('2026-01-02T03:04:05+00:00', $loaded->createdAt()->format(DATE_ATOM));
        self::assertSame('2026-01-02T03:04:05+00:00', $loaded->updatedAt()->format(DATE_ATOM));
        self::assertSame([], $loaded->refunds());
        self::assertSame(0, $loaded->capturedAmount());
    }

    public function testSaveAndGetRoundTripsMerchantUrlsAndTheAuthorizedStatus(): void
    {
        $now = new DateTimeImmutable('2026-01-02T03:04:05+00:00');
        $data = $this->details()->toArray();
        $data['merchant_urls'] = [
            'validation' => 'https://shop.example/validate',
            'notification' => null,
            'success' => 'https://shop.example/checkout?success={{PAYMENT_ID}}',
            'failed' => null,
            'cancel' => null,
        ];
        $payment = Payment::create('pay_1', PaymentDetails::fromArray($data), $now);
        $payment->authorize($now);

        $this->repository->save($payment);

        $loaded = $this->repository->get('pay_1');

        self::assertSame(PaymentStatus::Authorized, $loaded->status());
        self::assertSame($data['merchant_urls'], $loaded->merchantUrls()?->toArray());
    }

    public function testMerchantUrlsCanBeRemovedOnUpdate(): void
    {
        $now = new DateTimeImmutable('2026-01-02T03:04:05+00:00');
        $data = $this->details()->toArray();
        $data['merchant_urls'] = ['validation' => null, 'notification' => null, 'success' => 'https://shop.example/', 'failed' => null, 'cancel' => null];
        $payment = Payment::create('pay_1', PaymentDetails::fromArray($data), $now);
        $this->repository->save($payment);

        $payment->update($this->details(), $now);
        $this->repository->save($payment);

        self::assertNull($this->repository->get('pay_1')->merchantUrls());
    }

    public function testGetThrowsWhenThePaymentDoesNotExist(): void
    {
        $this->expectException(PaymentNotFound::class);

        $this->repository->get('pay_missing');
    }

    public function testRefundsArePersistedAndReloadedInOrder(): void
    {
        $now = new DateTimeImmutable('2026-01-02T03:04:05+00:00');
        $payment = Payment::create('pay_1', $this->details(), $now);
        $payment->activate($now->modify('+1 minute'));
        $payment->refund('ref_1', 10000, null, $this->details()->orderRows, $now->modify('+2 minutes'));
        $payment->refund('ref_2', 4900, 'credit note 7', null, $now->modify('+3 minutes'));

        $this->repository->save($payment);

        $loaded = $this->repository->get('pay_1');
        $refunds = $loaded->refunds();

        self::assertCount(2, $refunds);
        self::assertSame('ref_1', $refunds[0]->id);
        self::assertSame(10000, $refunds[0]->amount);
        self::assertNull($refunds[0]->reference);
        self::assertNotNull($refunds[0]->orderRows);
        self::assertSame(
            array_map(static fn(OrderRow $row): array => $row->toArray(), $this->details()->orderRows),
            array_map(static fn(OrderRow $row): array => $row->toArray(), $refunds[0]->orderRows),
        );
        self::assertSame('2026-01-02T03:06:05+00:00', $refunds[0]->createdAt->format(DATE_ATOM));

        self::assertSame('ref_2', $refunds[1]->id);
        self::assertSame(4900, $refunds[1]->amount);
        self::assertSame('credit note 7', $refunds[1]->reference);
        self::assertNull($refunds[1]->orderRows);

        self::assertSame(14900, $loaded->refundedAmount());
        self::assertSame(PaymentStatus::PartiallyRefunded, $loaded->status());
    }

    public function testFullyRefundedPaymentIsStoredAsRefunded(): void
    {
        $now = new DateTimeImmutable('2026-01-02T03:04:05+00:00');
        $payment = Payment::create('pay_1', $this->details(), $now);
        $payment->activate($now);
        $payment->refund('ref_1', 24900, null, null, $now);

        $this->repository->save($payment);

        $loaded = $this->repository->get('pay_1');

        self::assertSame(PaymentStatus::Refunded, $loaded->status());
        self::assertSame(24900, $loaded->refundedAmount());
        self::assertSame(0, $loaded->remainingRefundableAmount());
    }

    public function testResavingDoesNotDuplicateRefunds(): void
    {
        $now = new DateTimeImmutable('2026-01-02T03:04:05+00:00');
        $payment = Payment::create('pay_1', $this->details(), $now);
        $payment->activate($now);
        $payment->refund('ref_1', 10000, null, null, $now);
        $payment->refund('ref_2', 4900, null, null, $now);

        $this->repository->save($payment);
        $this->repository->save($payment);

        self::assertSame(2, $this->countRows('refunds'));
        self::assertCount(2, $this->repository->get('pay_1')->refunds());
    }

    public function testUpdatedDetailsAndTimestampAreWrittenBack(): void
    {
        $now = new DateTimeImmutable('2026-01-02T03:04:05+00:00');
        $payment = Payment::create('pay_1', $this->details(), $now);
        $this->repository->save($payment);

        $later = new DateTimeImmutable('2026-01-03T09:00:00+00:00');
        $payment->update($this->details('NOK', 'wc-99'), $later);
        $this->repository->save($payment);

        $loaded = $this->repository->get('pay_1');

        self::assertSame('NOK', $loaded->currency());
        self::assertSame('wc-99', $loaded->merchantReference());
        self::assertSame('2026-01-02T03:04:05+00:00', $loaded->createdAt()->format(DATE_ATOM));
        self::assertSame('2026-01-03T09:00:00+00:00', $loaded->updatedAt()->format(DATE_ATOM));
        self::assertSame(1, $this->countRows('payments'));
    }

    public function testListReturnsNewestFirstAndBreaksTiesOnIdDescending(): void
    {
        $this->store('pay_a', new DateTimeImmutable('2026-01-01T10:00:00+00:00'));
        $this->store('pay_b', new DateTimeImmutable('2026-01-03T10:00:00+00:00'));
        $this->store('pay_c', new DateTimeImmutable('2026-01-03T10:00:00+00:00'));

        $page = $this->repository->list(new PaymentFilter());

        self::assertSame(['pay_c', 'pay_b', 'pay_a'], $this->ids($page->items));
        self::assertSame(3, $page->total);
        self::assertSame(1, $page->totalPages());
    }

    public function testListPaginates(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->store(
                \sprintf('pay_%d', $i),
                new DateTimeImmutable(\sprintf('2026-01-0%dT10:00:00+00:00', $i)),
            );
        }

        $first = $this->repository->list(new PaymentFilter(1, 2));
        $last = $this->repository->list(new PaymentFilter(3, 2));

        self::assertSame(['pay_5', 'pay_4'], $this->ids($first->items));
        self::assertSame(5, $first->total);
        self::assertSame(3, $first->totalPages());

        self::assertSame(['pay_1'], $this->ids($last->items));
        self::assertSame(5, $last->total);
        self::assertSame(3, $last->totalPages());
    }

    public function testListFiltersByStatus(): void
    {
        $now = new DateTimeImmutable('2026-01-02T03:04:05+00:00');
        $this->store('pay_1', $now);
        $activated = Payment::create('pay_2', $this->details(), $now);
        $activated->activate($now);
        $this->repository->save($activated);

        $page = $this->repository->list(new PaymentFilter(1, 20, PaymentStatus::Activated));

        self::assertSame(['pay_2'], $this->ids($page->items));
        self::assertSame(1, $page->total);
    }

    public function testListFiltersByMerchantReference(): void
    {
        $now = new DateTimeImmutable('2026-01-02T03:04:05+00:00');
        $this->repository->save(Payment::create('pay_1', $this->details('SEK', 'wc-1'), $now));
        $this->repository->save(Payment::create('pay_2', $this->details('SEK', 'wc-2'), $now));

        $page = $this->repository->list(new PaymentFilter(1, 20, null, 'wc-2'));

        self::assertSame(['pay_2'], $this->ids($page->items));
        self::assertSame(1, $page->total);

        $none = $this->repository->list(new PaymentFilter(1, 20, null, 'wc-'));

        self::assertSame([], $none->items);
        self::assertSame(0, $none->total);
    }

    public function testListOnAnEmptyTableReturnsAnEmptyPage(): void
    {
        $page = $this->repository->list(new PaymentFilter());

        self::assertSame([], $page->items);
        self::assertSame(0, $page->total);
        self::assertSame(0, $page->totalPages());
    }

    public function testListLoadsRefundsForEveryPaymentOnThePage(): void
    {
        $now = new DateTimeImmutable('2026-01-02T03:04:05+00:00');
        $payment = Payment::create('pay_1', $this->details(), $now);
        $payment->activate($now);
        $payment->refund('ref_1', 100, null, null, $now);
        $this->repository->save($payment);
        $this->store('pay_2', $now->modify('+1 day'));

        $page = $this->repository->list(new PaymentFilter());

        self::assertSame(['pay_2', 'pay_1'], $this->ids($page->items));
        self::assertSame([], $page->items[0]->refunds());
        self::assertCount(1, $page->items[1]->refunds());
    }

    public function testForeignKeyOnRefundsIsEnforced(): void
    {
        $this->expectException(PDOException::class);

        $this->pdo->prepare(
            'INSERT INTO refunds (id, payment_id, amount, reference, order_rows, created_at) '
            . "VALUES ('ref_x', 'pay_missing', 100, NULL, NULL, '2026-01-02T03:04:05+00:00')",
        )->execute();
    }

    private function countRows(string $table): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM ' . $table);
        $statement->execute();

        return (int) $statement->fetchColumn();
    }

    private function store(string $id, DateTimeImmutable $createdAt): void
    {
        $this->repository->save(Payment::create($id, $this->details(), $createdAt));
    }

    /**
     * @param list<Payment> $payments
     *
     * @return list<string>
     */
    private function ids(array $payments): array
    {
        return array_map(static fn(Payment $payment): string => $payment->id(), $payments);
    }

    private function details(string $currency = 'SEK', ?string $merchantReference = 'wc-1001'): PaymentDetails
    {
        return PaymentDetails::fromArray([
            'currency' => $currency,
            'merchant_reference' => $merchantReference,
            'merchant_reference2' => '1001',
            'order_total' => 24900,
            'order_vat_amount' => 4980,
            'billing_address' => $this->address('billing@example.com'),
            'shipping_address' => $this->address('shipping@example.com'),
            'order_rows' => [
                [
                    'type' => 'physical',
                    'sku' => 'SKU-1',
                    'name' => 'Blue mug',
                    'reference' => 'row-1',
                    'quantity' => 2,
                    'unit_amount' => 10000,
                    'total_amount' => 20000,
                    'vat_amount' => 4000,
                    'vat_rate' => 2500,
                ],
                [
                    'type' => 'shipping',
                    'sku' => 'SHIP',
                    'name' => 'Shipping',
                    'reference' => null,
                    'quantity' => 1,
                    'unit_amount' => 4900,
                    'total_amount' => 4900,
                    'vat_amount' => 980,
                    'vat_rate' => 2500,
                ],
            ],
        ]);
    }

    /**
     * @return array{
     *     first_name: string,
     *     last_name: string,
     *     company: string,
     *     street_address: string,
     *     street_address2: string,
     *     postal_code: string,
     *     city: string,
     *     region: string,
     *     country: string,
     *     email: string,
     *     phone: string,
     * }
     */
    private function address(string $email): array
    {
        return [
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'company' => 'Krokedil AB',
            'street_address' => 'Norra Skeppsbron 6',
            'street_address2' => 'Floor 3',
            'postal_code' => '803 10',
            'city' => 'Gavle',
            'region' => 'Gavleborg',
            'country' => 'SE',
            'email' => $email,
            'phone' => '+46701234567',
        ];
    }
}
