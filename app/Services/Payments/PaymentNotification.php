<?php

namespace App\Services\Payments;

final class PaymentNotification
{
    public function __construct(
        public readonly string $eventId,
        public readonly string $eventType,
        public readonly string $status,
        public readonly string $reference,
        public readonly ?string $providerReference,
        public readonly int $amountMinor,
        public readonly string $currency,
        public readonly int $minorUnitScale,
        public readonly ?string $message,
        public readonly array $payload,
        public readonly string $payloadHash,
    ) {}
}
