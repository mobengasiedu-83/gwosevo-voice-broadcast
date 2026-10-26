<?php

namespace App\Services\Payments;

use App\Models\PaymentIntent;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Configuration-driven HTTP adapter.
 *
 * The JSON contract is documented in docs/module-14-payments-and-top-ups.md.
 * It is deliberately disabled until the provider's real API, settlement and
 * signature scheme are verified in UAT. No provider-specific assumptions are
 * made beyond that documented contract.
 */
class ConfiguredPaymentProvider implements PaymentProvider
{
    public function key(): string
    {
        return (string) config('payments.provider', 'xcel');
    }

    public function available(): bool
    {
        $config = $this->config();

        return (bool) config('payments.enabled', false)
            && ($config['driver'] ?? 'disabled') === 'http'
            && filled($config['base_url'] ?? null)
            && filled($config['api_key'] ?? null)
            && filled($config['webhook_secret'] ?? null);
    }

    public function canVerifyWebhooks(): bool
    {
        $config = $this->config();

        return ($config['driver'] ?? 'disabled') === 'http'
            && filled($config['webhook_secret'] ?? null);
    }

    public function initiate(PaymentIntent $intent, string $callbackUrl, string $returnUrl): PaymentInitiation
    {
        if (! $this->available()) {
            throw new PaymentUnavailable('provider_not_configured');
        }
        $config = $this->config();
        $url = rtrim((string) $config['base_url'], '/').'/'.ltrim((string) ($config['initiate_path'] ?? '/payments'), '/');
        try {
            $response = Http::withToken((string) $config['api_key'])
                ->acceptJson()
                ->asJson()
                ->timeout(max(3, min(60, (int) ($config['http_timeout'] ?? 10))))
                ->withHeaders(['Idempotency-Key' => $intent->public_id])
                ->post($url, [
                    'reference' => $intent->public_id,
                    'amount_minor' => $intent->amount_minor,
                    'currency' => $intent->currency,
                    'minor_unit_scale' => $intent->minor_unit_scale,
                    'description' => 'Calling credit top-up',
                    'callback_url' => $callbackUrl,
                    'return_url' => $returnUrl,
                ]);
        } catch (ConnectionException $e) {
            throw new PaymentProviderException('connection_failed');
        }

        if ($response->failed()) {
            throw new PaymentProviderException('http_'.$response->status());
        }
        $body = $response->json();
        if (! is_array($body)) {
            throw new PaymentProviderException('invalid_response');
        }
        $reference = data_get($body, 'provider_reference')
            ?? data_get($body, 'reference')
            ?? data_get($body, 'data.reference')
            ?? data_get($body, 'data.id')
            ?? data_get($body, 'id');
        $checkout = data_get($body, 'checkout_url')
            ?? data_get($body, 'authorization_url')
            ?? data_get($body, 'data.checkout_url')
            ?? data_get($body, 'data.authorization_url');
        if (! is_scalar($reference) || trim((string) $reference) === '' || mb_strlen((string) $reference) > 190) {
            throw new PaymentProviderException('provider_reference_missing');
        }
        if (! is_string($checkout) || mb_strlen($checkout) > 2048 || ! $this->safeCheckoutUrl($checkout)) {
            throw new PaymentProviderException('checkout_url_missing');
        }

        return new PaymentInitiation(trim((string) $reference), $checkout, [
            'provider_version' => data_get($body, 'version'),
        ]);
    }

    public function verify(Request $request): PaymentNotification
    {
        if (! $this->canVerifyWebhooks()) {
            throw new InvalidPaymentCallback('provider_not_configured');
        }
        $config = $this->config();
        $raw = $request->getContent();
        if ($raw === '' || strlen($raw) > 32768) {
            throw new InvalidPaymentCallback('payload_size');
        }
        $timestamp = trim((string) $request->header((string) ($config['webhook_timestamp_header'] ?? 'X-Payment-Timestamp'), ''));
        $signature = trim((string) $request->header((string) ($config['webhook_signature_header'] ?? 'X-Payment-Signature'), ''));
        if (! ctype_digit($timestamp) || $signature === '') {
            throw new InvalidPaymentCallback('signature_missing');
        }
        $tolerance = max(30, min(3600, (int) ($config['webhook_tolerance'] ?? 300)));
        if (abs(now()->timestamp - (int) $timestamp) > $tolerance) {
            throw new InvalidPaymentCallback('timestamp_outside_tolerance');
        }
        if (str_starts_with($signature, 'sha256=')) {
            $signature = substr($signature, 7);
        }
        $expected = hash_hmac('sha256', $timestamp.'.'.$raw, (string) $config['webhook_secret']);
        if (! hash_equals($expected, strtolower($signature))) {
            throw new InvalidPaymentCallback('signature_mismatch');
        }
        try {
            $payload = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InvalidPaymentCallback('invalid_json');
        }
        if (! is_array($payload)) {
            throw new InvalidPaymentCallback('invalid_payload');
        }

        $eventId = $payload['event_id'] ?? $payload['id'] ?? null;
        $status = $payload['status'] ?? null;
        $reference = $payload['reference'] ?? null;
        $amount = $payload['amount_minor'] ?? null;
        $currency = $payload['currency'] ?? null;
        $scale = $payload['minor_unit_scale'] ?? null;
        $providerReference = $payload['provider_reference'] ?? $payload['transaction_id'] ?? null;
        $type = $payload['type'] ?? 'payment.status';
        $message = $payload['message'] ?? null;

        if (! is_scalar($eventId) || trim((string) $eventId) === '' || mb_strlen((string) $eventId) > 190) {
            throw new InvalidPaymentCallback('event_id');
        }
        if (! is_string($status) || trim($status) === '' || mb_strlen(trim($status)) > 24) {
            throw new InvalidPaymentCallback('status');
        }
        if (! is_string($reference) || ! Str::isUuid($reference)) {
            throw new InvalidPaymentCallback('reference');
        }
        if (! is_scalar($amount) || ! preg_match('/\A\d{1,16}\z/', (string) $amount) || (int) $amount < 1) {
            throw new InvalidPaymentCallback('amount_minor');
        }
        if (! is_string($currency) || ! preg_match('/\A[A-Z]{3}\z/', strtoupper($currency))) {
            throw new InvalidPaymentCallback('currency');
        }
        if (filter_var($scale, FILTER_VALIDATE_INT) === false || (int) $scale < 0 || (int) $scale > 6) {
            throw new InvalidPaymentCallback('minor_unit_scale');
        }
        if ($providerReference !== null && (! is_scalar($providerReference) || mb_strlen((string) $providerReference) > 190)) {
            throw new InvalidPaymentCallback('provider_reference');
        }
        if (! is_string($type) || mb_strlen($type) > 64) {
            throw new InvalidPaymentCallback('event_type');
        }
        if ($message !== null && (! is_scalar($message) || mb_strlen((string) $message) > 255)) {
            throw new InvalidPaymentCallback('message');
        }

        return new PaymentNotification(
            trim((string) $eventId),
            $type,
            strtolower(trim($status)),
            $reference,
            $providerReference === null || trim((string) $providerReference) === '' ? null : trim((string) $providerReference),
            (int) $amount,
            strtoupper($currency),
            (int) $scale,
            $message === null ? null : trim((string) $message),
            $payload,
            hash('sha256', $raw),
        );
    }

    private function config(): array
    {
        $key = $this->key();
        $config = config('payments.providers.'.$key);

        return is_array($config) ? $config : ['driver' => 'disabled'];
    }

    private function safeCheckoutUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true) || empty($parts['host'])) {
            return false;
        }

        // Provider URLs must never be protocol-relative or embed credentials.
        return ! isset($parts['user']) && ! isset($parts['pass']);
    }
}
