<?php

namespace App\Services\Payments;

use App\Models\PaymentIntent;
use Illuminate\Http\Request;

interface PaymentProvider
{
    public function key(): string;

    /** Whether new top-ups may be initiated. */
    public function available(): bool;

    /** Whether signed callbacks can still be verified while initiation is disabled. */
    public function canVerifyWebhooks(): bool;

    public function initiate(PaymentIntent $intent, string $callbackUrl, string $returnUrl): PaymentInitiation;

    public function verify(Request $request): PaymentNotification;
}
