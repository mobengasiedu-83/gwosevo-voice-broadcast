<?php

namespace App\Services\Payments;

use Illuminate\Http\Request;

/**
 * Resolves the single configured payment provider and enforces fail-closed
 * availability checks before money can move.
 */
class PaymentGateway
{
    public function __construct(private PaymentProvider $provider) {}

    public function provider(): PaymentProvider
    {
        return $this->provider;
    }

    public function key(): string
    {
        return $this->provider->key();
    }

    public function enabled(): bool
    {
        return $this->provider->available();
    }

    /** Callbacks may continue after new top-ups are disabled, so pending payments can settle. */
    public function providerForCallback(string $key): PaymentProvider
    {
        abort_unless(hash_equals($this->provider->key(), $key), 404);

        return $this->provider;
    }

    public function verify(Request $request, string $key): PaymentNotification
    {
        return $this->providerForCallback($key)->verify($request);
    }

    public function requireEnabled(): PaymentProvider
    {
        if (! $this->provider->available()) {
            throw new PaymentUnavailable('provider_not_configured');
        }

        return $this->provider;
    }
}
