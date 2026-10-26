<?php

namespace App\Services\Payments;

use RuntimeException;

/** Provider cannot currently accept new top-ups because configuration or UAT is incomplete. */
class PaymentUnavailable extends RuntimeException
{
    public function __construct(public readonly string $reason = 'not_configured')
    {
        parent::__construct('Online top-ups are unavailable: '.$reason);
    }
}
