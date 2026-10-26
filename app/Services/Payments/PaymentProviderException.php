<?php

namespace App\Services\Payments;

use RuntimeException;

/** Provider rejected or returned an unusable initiation response. */
class PaymentProviderException extends RuntimeException
{
    public function __construct(public readonly string $reason, ?string $message = null)
    {
        parent::__construct($message ?: 'Payment provider request failed: '.$reason);
    }
}
