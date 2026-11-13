<?php

declare(strict_types=1);

namespace MockPay\Tests\Functional;

use MockPay\Tests\Support\AppTestCase;
use MockPay\Tests\Support\PaymentPayloadBuilder;
use PHPUnit\Framework\Attributes\CoversNothing;
use Psr\Http\Message\ResponseInterface;

#[CoversNothing]
final class RefundPaymentTest extends AppTestCase
{
    public function testRefundingACreatedPaymentIsA409(): void
    {
        $created = $this->createPayment();

        $body = $this->assertProblem($this->refund($created['id'], []), 409, 'invalid-state');

        self::assertSame('refund', $body['action']);
        self::assertSame('created', $body['current_status']);
    }

    public function testRefundingACancelledPaymentIsA409(): void
    {
        $created = $this->createPayment();
        $this->request('POST', '/payments/' . $created['id'] . '/cancel');

        $body = $this->assertProblem($this->refund($created['id'], []), 409, 'invalid-state');

        self::assertSame('refund', $body['action']);
        self::assertSame('cancelled', $body['current_status']);
    }

    public function testAPartialRefundLeavesTheRestRefundable(): void
    {
        $payment = $this->activated();

        $this->clock->advance('PT1S');
        $response = $this->refund($payment['id'], ['amount' => 10000, 'reference' => 'R-1']);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $body = $this->decode($response);

        self::assertSame('partially_refunded', $body['status']);
        self::assertSame(10000, $body['refunded_amount']);
        self::assertSame(14900, $body['remaining_refundable_amount']);
        self::assertSame(24900, $body['captured_amount']);

        $refunds = $this->refundsOf($body);
        self::assertCount(1, $refunds);
        self::assertIsString($refunds[0]['id']);
        self::assertMatchesRegularExpression('/^ref_[0-9a-z]{24}$/', $refunds[0]['id']);
        self::assertSame(10000, $refunds[0]['amount']);
        self::assertSame('R-1', $refunds[0]['reference']);
        self::assertNull($refunds[0]['order_rows']);
        self::assertSame('2026-01-02T03:04:06+00:00', $refunds[0]['created_at']);
    }

    public function testASecondPartialRefundCanCarryOrderRows(): void
    {
        $payment = $this->activated();

        $this->refund($payment['id'], ['amount' => 10000, 'reference' => 'R-1']);
        $this->clock->advance('PT1S');

        $rows = [PaymentPayloadBuilder::validRow([
            'sku' => 'SHIP',
            'name' => 'Standard shipping',
            'quantity' => 1,
            'unit_amount' => 4900,
            'total_amount' => 4900,
            'vat_amount' => 980,
        ])];

        $body = $this->decode($this->refund($payment['id'], ['amount' => 4900, 'order_rows' => $rows]));

        self::assertSame('partially_refunded', $body['status']);
        self::assertSame(14900, $body['refunded_amount']);
        self::assertSame(10000, $body['remaining_refundable_amount']);

        $refunds = $this->refundsOf($body);
        self::assertCount(2, $refunds);
        self::assertSame(4900, $refunds[1]['amount']);
        self::assertNull($refunds[1]['reference']);
        self::assertSame($rows, $refunds[1]['order_rows']);
    }

    public function testAnEmptyBodyRefundsWhateverIsLeft(): void
    {
        $payment = $this->activated();

        $this->refund($payment['id'], ['amount' => 10000]);
        $this->clock->advance('PT1S');

        $response = $this->rawRequest('POST', '/payments/' . $payment['id'] . '/refund');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $body = $this->decode($response);

        self::assertSame('refunded', $body['status']);
        self::assertSame(0, $body['remaining_refundable_amount']);
        self::assertSame($body['captured_amount'], $body['refunded_amount']);
        self::assertCount(2, $this->refundsOf($body));
    }

    public function testRefundingAFullyRefundedPaymentIsA409(): void
    {
        $payment = $this->activated();

        $this->rawRequest('POST', '/payments/' . $payment['id'] . '/refund');

        $body = $this->assertProblem($this->refund($payment['id'], []), 409, 'invalid-state');

        self::assertSame('refund', $body['action']);
        self::assertSame('refunded', $body['current_status']);
    }

    public function testCancellingAfterARefundIsA409(): void
    {
        $payment = $this->activated();

        $this->refund($payment['id'], ['amount' => 10000]);

        $body = $this->assertProblem(
            $this->request('POST', '/payments/' . $payment['id'] . '/cancel'),
            409,
            'invalid-state',
        );

        self::assertSame('cancel', $body['action']);
        self::assertSame('partially_refunded', $body['current_status']);
    }

    public function testAnAmountAboveTheRemainingAmountIsRejected(): void
    {
        $payment = $this->activated();

        $this->refund($payment['id'], ['amount' => 10000]);

        $problem = $this->assertProblem(
            $this->refund($payment['id'], ['amount' => 14901]),
            422,
            'validation-error',
        );

        $errors = $this->errorsOf($problem);
        self::assertSame('amount', $errors[0]['field']);
        self::assertStringContainsString('14900', $errors[0]['message']);
    }

    public function testAZeroAmountIsRejected(): void
    {
        $payment = $this->activated();

        $problem = $this->assertProblem(
            $this->refund($payment['id'], ['amount' => 0]),
            422,
            'validation-error',
        );

        self::assertSame(['amount'], array_column($this->errorsOf($problem), 'field'));
    }

    public function testAFloatAmountIsRejected(): void
    {
        $payment = $this->activated();

        $response = $this->rawRequest(
            'POST',
            '/payments/' . $payment['id'] . '/refund',
            '{"amount": 100.5}',
        );

        $problem = $this->assertProblem($response, 422, 'validation-error');

        $errors = $this->errorsOf($problem);
        self::assertSame('amount', $errors[0]['field']);
        self::assertSame('Must be an integer.', $errors[0]['message']);
    }

    public function testOrderRowsMustSumToTheAmount(): void
    {
        $payment = $this->activated();

        $problem = $this->assertProblem(
            $this->refund($payment['id'], [
                'amount' => 5000,
                'order_rows' => [PaymentPayloadBuilder::validRow()],
            ]),
            422,
            'validation-error',
        );

        $errors = $this->errorsOf($problem);
        self::assertSame('amount', $errors[0]['field']);
        self::assertSame(
            'Must equal the sum of order_rows.total_amount (10000).',
            $errors[0]['message'],
        );
    }

    public function testTheAmountIsDerivedFromTheRowsWhenOmitted(): void
    {
        $payment = $this->activated();

        $body = $this->decode($this->refund($payment['id'], [
            'order_rows' => [PaymentPayloadBuilder::validRow()],
        ]));

        self::assertSame('partially_refunded', $body['status']);
        self::assertSame(10000, $body['refunded_amount']);

        $refunds = $this->refundsOf($body);
        self::assertSame(10000, $refunds[0]['amount']);
    }

    public function testRefundsSurviveAFreshGet(): void
    {
        $payment = $this->activated();

        $this->refund($payment['id'], ['amount' => 10000, 'reference' => 'R-1']);
        $this->clock->advance('PT1S');
        $refunded = $this->decode($this->refund($payment['id'], ['amount' => 4900, 'reference' => 'R-2']));

        $fetched = $this->decode($this->request('GET', '/payments/' . $payment['id']));

        self::assertSame($refunded, $fetched);
        self::assertCount(2, $this->refundsOf($fetched));
    }

    public function testRefundingAnUnknownPaymentIsA404(): void
    {
        $this->assertProblem($this->refund('pay_nope', []), 404, 'not-found');
    }

    public function testRefundRequiresAuthentication(): void
    {
        $payment = $this->activated();

        $response = $this->request(
            'POST',
            '/payments/' . $payment['id'] . '/refund',
            ['amount' => 10000],
            false,
        );

        $this->assertProblem($response, 401, 'unauthorized');
    }

    /**
     * Creates a payment and activates it, returning the activated body.
     *
     * @return array<string, mixed>
     */
    private function activated(): array
    {
        $created = $this->createPayment();

        $response = $this->request('POST', '/payments/' . $created['id'] . '/activate');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return $this->decode($response);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function refund(mixed $id, array $body): ResponseInterface
    {
        return $this->request('POST', '/payments/' . (string) $id . '/refund', $body);
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return list<array<string, mixed>>
     */
    private function refundsOf(array $body): array
    {
        /** @var list<array<string, mixed>> $refunds */
        $refunds = $body['refunds'];

        return $refunds;
    }

    /**
     * @param array<string, mixed> $problem
     *
     * @return list<array<string, string>>
     */
    private function errorsOf(array $problem): array
    {
        /** @var list<array<string, string>> $errors */
        $errors = $problem['errors'];

        return $errors;
    }
}
