<?php

declare(strict_types=1);

namespace MockPay\Domain;

/**
 * Where MockPay calls the merchant back (validation, notification) and where the payment
 * page sends the customer afterwards (success, failed, cancel). Every URL may contain
 * {@see MerchantUrls::PAYMENT_ID_PLACEHOLDER}, replaced with the payment id when used.
 *
 * @phpstan-type MerchantUrlsInput array{
 *     validation?: string|null,
 *     notification?: string|null,
 *     success?: string|null,
 *     failed?: string|null,
 *     cancel?: string|null,
 * }
 * @phpstan-type MerchantUrlsArray array{
 *     validation: string|null,
 *     notification: string|null,
 *     success: string|null,
 *     failed: string|null,
 *     cancel: string|null,
 * }
 */
final readonly class MerchantUrls
{
    public const PAYMENT_ID_PLACEHOLDER = '{{PAYMENT_ID}}';

    public function __construct(
        public ?string $validation,
        public ?string $notification,
        public ?string $success,
        public ?string $failed,
        public ?string $cancel,
    ) {}

    /**
     * @param MerchantUrlsInput $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['validation'] ?? null,
            $data['notification'] ?? null,
            $data['success'] ?? null,
            $data['failed'] ?? null,
            $data['cancel'] ?? null,
        );
    }

    /**
     * Replaces the payment id placeholder in a URL template.
     */
    public static function render(string $template, string $paymentId): string
    {
        return str_replace(self::PAYMENT_ID_PLACEHOLDER, rawurlencode($paymentId), $template);
    }

    /**
     * @return MerchantUrlsArray
     */
    public function toArray(): array
    {
        return [
            'validation' => $this->validation,
            'notification' => $this->notification,
            'success' => $this->success,
            'failed' => $this->failed,
            'cancel' => $this->cancel,
        ];
    }
}
