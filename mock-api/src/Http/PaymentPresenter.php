<?php

declare(strict_types=1);

namespace MockPay\Http;

use DateTimeImmutable;
use DateTimeZone;
use MockPay\Domain\CallbackAttempt;
use MockPay\Domain\OrderRow;
use MockPay\Domain\Page;
use MockPay\Domain\Payment;
use MockPay\Domain\Refund;

/**
 * Turns the domain aggregate into the snake_case response shape. The single
 * place that decides what a payment looks like on the wire.
 */
final class PaymentPresenter
{
    /**
     * @param string $publicUrl the base URL customers' browsers reach MockPay on
     */
    public function __construct(private readonly string $publicUrl = 'http://localhost:8888') {}

    /**
     * @return array<string, mixed>
     */
    public function present(Payment $payment): array
    {
        return [
            'id' => $payment->id(),
            'status' => $payment->status()->value,
            'currency' => $payment->currency(),
            'merchant_reference' => $payment->merchantReference(),
            'merchant_reference2' => $payment->merchantReference2(),
            'order_total' => $payment->orderTotal(),
            'order_vat_amount' => $payment->orderVatAmount(),
            'captured_amount' => $payment->capturedAmount(),
            'refunded_amount' => $payment->refundedAmount(),
            'remaining_refundable_amount' => $payment->remainingRefundableAmount(),
            'billing_address' => $payment->billingAddress()->toArray(),
            'shipping_address' => $payment->shippingAddress()->toArray(),
            'order_rows' => array_map(
                static fn(OrderRow $row): array => $row->toArray(),
                $payment->orderRows(),
            ),
            'merchant_urls' => $payment->merchantUrls()?->toArray(),
            'payment_page_url' => $this->paymentPageUrl($payment->id()),
            'refunds' => array_map(
                static fn(Refund $refund): array => $refund->toArray(),
                $payment->refunds(),
            ),
            'created_at' => self::timestamp($payment->createdAt()),
            'updated_at' => self::timestamp($payment->updatedAt()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function presentPage(Page $page): array
    {
        return [
            'data' => array_map(
                fn(Payment $payment): array => $this->present($payment),
                $page->items,
            ),
            'pagination' => [
                'page' => $page->page,
                'per_page' => $page->perPage,
                'total' => $page->total,
                'total_pages' => $page->totalPages(),
            ],
        ];
    }

    /**
     * @param list<CallbackAttempt> $attempts
     *
     * @return array<string, mixed>
     */
    public function presentCallbackAttempts(array $attempts): array
    {
        return [
            'data' => array_map(
                static fn(CallbackAttempt $attempt): array => $attempt->toArray(),
                $attempts,
            ),
        ];
    }

    public function paymentPageUrl(string $paymentId): string
    {
        return rtrim($this->publicUrl, '/') . '/pay/' . rawurlencode($paymentId);
    }

    private static function timestamp(DateTimeImmutable $at): string
    {
        return $at->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM);
    }
}
