<?php

namespace App\Services\Billing;

use RuntimeException;

/**
 * Raised when admission cannot hold the required credit exposure. The caller
 * decides whether to refuse the campaign launch or stop dispatching.
 */
class WalletDeclined extends RuntimeException
{
    public function __construct(public readonly string $reason, public readonly array $context = [])
    {
        parent::__construct('Wallet reservation declined: '.$reason);
    }
}
