<?php

declare(strict_types=1);

namespace MockPay\Infrastructure;

use MockPay\Domain\IdGenerator;

final class RandomIdGenerator implements IdGenerator
{
    private const ALPHABET = '0123456789abcdefghijklmnopqrstuvwxyz';
    private const LENGTH = 24;

    public function paymentId(): string
    {
        return 'pay_' . $this->random();
    }

    public function refundId(): string
    {
        return 'ref_' . $this->random();
    }

    public function eventId(): string
    {
        return 'evt_' . $this->random();
    }

    private function random(): string
    {
        $max = \strlen(self::ALPHABET) - 1;
        $out = '';
        for ($i = 0; $i < self::LENGTH; $i++) {
            $out .= self::ALPHABET[random_int(0, $max)];
        }

        return $out;
    }
}
