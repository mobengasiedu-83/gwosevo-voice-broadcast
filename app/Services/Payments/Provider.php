<?php

namespace App\Services\Payments;

use App\Models\PaymentIntent;

interface Provider
{
    public function merchant(): string;
    public function initiate(PaymentIntent $intent, string $callbackUrl, string $returnUrl): array;
    public function verify(string $paymentCode): array;
}
