<?php

declare(strict_types=1);

namespace MockPay\Tests\Functional;

use MockPay\Tests\Support\AppTestCase;
use MockPay\Tests\Support\PaymentPayloadBuilder;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversNothing]
final class ListPaymentsTest extends AppTestCase
{
    public function testEmptyListHasTheFullEnvelope(): void
    {
        $response = $this->request('GET', '/payments');

        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('application/json', $response->getHeaderLine('Content-Type'));

        self::assertSame(
            [
                'data' => [],
                'pagination' => ['page' => 1, 'per_page' => 20, 'total' => 0, 'total_pages' => 0],
            ],
            $this->decode($response),
        );
    }

    public function testNewestPaymentsComeFirst(): void
    {
        $ids = $this->createMany(5);

        $body = $this->list('/payments');

        self::assertSame(array_reverse($ids), $this->idsOf($body));
        self::assertSame(5, $this->pagination($body)['total']);
    }

    public function testPagesAreSliced(): void
    {
        $ids = array_reverse($this->createMany(5));

        $first = $this->list('/payments?per_page=2');

        self::assertSame(\array_slice($ids, 0, 2), $this->idsOf($first));
        self::assertSame(
            ['page' => 1, 'per_page' => 2, 'total' => 5, 'total_pages' => 3],
            $this->pagination($first),
        );

        $last = $this->list('/payments?per_page=2&page=3');

        self::assertSame(\array_slice($ids, 4, 1), $this->idsOf($last));
        self::assertSame(3, $this->pagination($last)['page']);
    }

    public function testAPageBeyondTheLastOneIsEmpty(): void
    {
        $this->createMany(5);

        $body = $this->list('/payments?per_page=2&page=9');

        self::assertSame([], $this->idsOf($body));
        self::assertSame(5, $this->pagination($body)['total']);
        self::assertSame(3, $this->pagination($body)['total_pages']);
    }

    public function testStatusFilter(): void
    {
        $ids = $this->createMany(3);
        $activated = $ids[1];

        self::assertSame(200, $this->request('POST', '/payments/' . $activated . '/activate')->getStatusCode());

        self::assertSame([$activated], $this->idsOf($this->list('/payments?status=activated')));

        $created = $this->idsOf($this->list('/payments?status=created'));
        self::assertCount(2, $created);
        self::assertNotContains($activated, $created);
    }

    public function testMerchantReferenceFilterMatchesExactly(): void
    {
        $wanted = $this->createPayment(
            PaymentPayloadBuilder::valid()->withMerchantReference('ORDER-42')->build(),
        );
        $this->createPayment(PaymentPayloadBuilder::valid()->withMerchantReference('ORDER-4')->build());

        self::assertSame(
            [$wanted['id']],
            $this->idsOf($this->list('/payments?merchant_reference=ORDER-42')),
        );
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function invalidQueries(): array
    {
        return [
            'per_page zero' => ['per_page=0', 'per_page'],
            'per_page too large' => ['per_page=101', 'per_page'],
            'page zero' => ['page=0', 'page'],
            'page not numeric' => ['page=abc', 'page'],
            'unknown status' => ['status=bogus', 'status'],
        ];
    }

    #[DataProvider('invalidQueries')]
    public function testInvalidQueryParametersAreRejected(string $query, string $field): void
    {
        $response = $this->request('GET', '/payments?' . $query);

        $body = $this->assertProblem($response, 422, 'validation-error');

        /** @var array<int, array<string, string>> $errors */
        $errors = $body['errors'];
        self::assertSame([$field], array_column($errors, 'field'));
    }

    public function testListRequiresAuthentication(): void
    {
        $this->assertProblem($this->request('GET', '/payments', null, false), 401, 'unauthorized');
    }

    /**
     * Creates `$count` payments one second apart so the ordering is unambiguous.
     *
     * @return list<string>
     */
    private function createMany(int $count): array
    {
        $ids = [];

        for ($i = 0; $i < $count; $i++) {
            $payment = $this->createPayment(
                PaymentPayloadBuilder::valid()->withMerchantReference('REF-' . $i)->build(),
            );

            /** @var string $id */
            $id = $payment['id'];
            $ids[] = $id;

            $this->clock->advance('PT1S');
        }

        return $ids;
    }

    /**
     * @return array<string, mixed>
     */
    private function list(string $uri): array
    {
        $response = $this->request('GET', $uri);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return $this->decode($response);
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return list<string>
     */
    private function idsOf(array $body): array
    {
        /** @var list<array<string, mixed>> $data */
        $data = $body['data'];

        /** @var list<string> $ids */
        $ids = array_column($data, 'id');

        return $ids;
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, int>
     */
    private function pagination(array $body): array
    {
        /** @var array<string, int> $pagination */
        $pagination = $body['pagination'];

        return $pagination;
    }
}
