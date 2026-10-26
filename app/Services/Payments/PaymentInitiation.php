<?php

namespace App\Services\Payments;

final class PaymentInitiation
{
    public function __construct(
        public readonly string $providerReference,
        public readonly string $checkoutUrl,
        public readonly array $metadata = [],
    ) {}
}
