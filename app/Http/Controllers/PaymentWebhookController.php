<?php

namespace App\Http\Controllers;

use App\Services\Payments\InvalidPaymentCallback;
use App\Services\Payments\Payments;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Unauthenticated provider callback endpoint. Every request must pass HMAC and
 * timestamp verification before any payment state is read or changed.
 */
class PaymentWebhookController extends Controller
{
    public function __invoke(Request $request, Payments $payments, string $provider)
    {
        try {
            $event = $payments->handleCallback($provider, $request);
        } catch (InvalidPaymentCallback $e) {
            Log::warning('Payment callback rejected.', [
                'provider' => $provider, 'reason' => $e->reason, 'ip' => $request->ip(),
            ]);

            return response()->json(['error' => 'invalid_callback'], 401)->header('Cache-Control', 'no-store');
        } catch (HttpExceptionInterface $e) {
            // Unknown provider names must stay 404 rather than becoming a 500.
            throw $e;
        } catch (Throwable $e) {
            Log::error('Payment callback processing failed.', [
                'provider' => $provider, 'exception' => $e::class,
            ]);

            return response()->json(['error' => 'processing_failed'], 500)->header('Cache-Control', 'no-store');
        }

        return response()->json(['received' => true, 'result' => $event->result])
            ->header('Cache-Control', 'no-store');
    }
}
