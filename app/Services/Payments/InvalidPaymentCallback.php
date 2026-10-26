<?php

namespace App\Services\Payments;

use RuntimeException;

/** A provider callback failed signature, timestamp or payload verification. */
class InvalidPaymentCallback extends RuntimeException
{
    public function __construct(public readonly string $reason = 'invalid')
    {
        parent::__construct('Payment callback rejected: '.$reason);
    }
}
