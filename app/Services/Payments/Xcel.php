<?php

namespace App\Services\Payments;

use App\Models\PaymentIntent;
use App\Services\Billing\Ledger;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class Xcel implements Provider
{
    public function merchant(): string
    {
        $id = (string) config('payments.xcel.merchant_id');
        if (! preg_match('/\A[A-Za-z0-9_-]{1,100}\z/', $id) || ! config('payments.xcel.public_key')) {
            throw new RuntimeException('Payment provider is not configured.');
        }

        return $id;
    }

    private function request()
    {
        $this->merchant();
        $base = (string) config('payments.xcel.base_url');
        if (! filter_var($base, FILTER_VALIDATE_URL) || parse_url($base, PHP_URL_SCHEME) !== 'https' || parse_url($base, PHP_URL_USER) || parse_url($base, PHP_URL_QUERY) || parse_url($base, PHP_URL_FRAGMENT)) {
            throw new RuntimeException('Invalid payment API configuration.');
        }

        return Http::baseUrl(rtrim($base, '/'))->acceptJson()->asJson()->connectTimeout(5)->timeout(15)->withoutRedirecting()
            ->withHeaders(['x-merchant-id' => $this->merchant(), 'x-public-key' => config('payments.xcel.public_key')]);
    }

    public function initiate(PaymentIntent $intent, string $callbackUrl, string $returnUrl): array
    {
        $response = $this->request()->post('/transactions-service/paygate/generate-payment-link', [
            'amount' => Ledger::format($intent->amount_cents, 2), 'currency' => $intent->currency,
            'client_transaction_id' => $intent->uuid, 'description' => 'VoiceCast wallet top-up', 'channel' => 'WEB',
            'redirect_url' => $returnUrl, 'webhook_url' => $callbackUrl,
            'custom_txn' => ['edit_amt' => false, 'pay_link_can_pay_multiple_times' => false],
        ]);
        if (! $response->successful() || ! is_array($response->json('data'))) {
            throw new RuntimeException('Payment initiation could not be confirmed.');
        }

        return $response->json('data');
    }

    public function verify(string $paymentCode): array
    {
        if (! preg_match('/\A[A-Za-z0-9_-]{1,120}\z/', $paymentCode)) {
            throw new RuntimeException('Invalid payment code.');
        }
        $response = $this->request()->get('/transactions-service/paygate/get-transaction-data/'.rawurlencode($paymentCode));
        if (! $response->successful() || ! is_array($response->json('data'))) {
            throw new RuntimeException('Payment verification unavailable.');
        }

        return $response->json('data');
    }
}
