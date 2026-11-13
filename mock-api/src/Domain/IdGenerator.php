<?php

declare(strict_types=1);

namespace MockPay\Domain;

interface IdGenerator
{
    /** Returns an id of the form "pay_" + 24 base-36 characters. */
    public function paymentId(): string;

    /** Returns an id of the form "ref_" + 24 base-36 characters. */
    public function refundId(): string;

    /** Returns an id of the form "evt_" + 24 base-36 characters. */
    public function eventId(): string;
}
