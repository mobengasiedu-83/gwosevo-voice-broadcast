<?php

namespace App\Services\Notifications;

use App\Models\AudioFile;
use App\Models\Campaign;
use App\Models\PaymentIntent;
use App\Models\User;
use App\Models\WalletAccount;
use App\Services\Billing\Wallet;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/** Raises the Module 16 alerts at each authoritative business transition. */
class Notifier
{
    public function __construct(private Center $center) {}

    public function audioReviewed(AudioFile $audio, string $decision, ?string $reason, ?User $actor): void
    {
        $owner = $this->center->activeMember((int) $audio->client_id, $audio->uploaded_by);
        if (! $owner || $actor?->id === $owner->id) {
            return;
        }
        $approved = $decision === 'approved';
        $reason = trim((string) $reason);
        $body = $approved
            ? 'Your recording “'.$this->name($audio->original_name ?? $audio->name ?? 'Untitled recording').'” passed review and can be used in campaigns.'
            : 'Your recording “'.$this->name($audio->original_name ?? $audio->name ?? 'Untitled recording').'” needs changes before it can be used.'.($reason !== '' ? "\nReason: ".$reason : '');
        $this->safe(fn () => $this->center->notifyUser($owner, [
            'client_id' => $audio->client_id,
            'category' => 'review',
            'event_key' => 'audio.'.$decision,
            'severity' => $approved ? 'success' : 'warning',
            'title' => $approved ? 'Recording approved' : 'Recording returned for changes',
            'body' => $body,
            'url' => route('audio.index'),
            'data' => ['audio_id' => $audio->id, 'decision' => $decision],
            // Audio decisions already appear in the audit-backed client inbox.
            'in_app' => false,
        ]), 'audio_review', $audio->client_id);
    }

    public function campaignReviewed(Campaign $campaign, string $decision, string $reason, User $actor): void
    {
        $approved = $decision === 'approve';
        $reason = trim($reason);
        $body = $approved
            ? '“'.$this->name($campaign->name).'” was approved and is ready for launch.'
            : '“'.$this->name($campaign->name).'” was returned to draft for changes.'.($reason !== '' ? "\nReason: ".$reason : '');
        $this->safe(fn () => $this->center->notifyUsers(
            $this->center->campaignAudience((int) $campaign->client_id, $campaign->owner_id),
            [
                'client_id' => $campaign->client_id,
                'category' => 'campaign',
                'event_key' => 'campaign.review.'.$decision,
                'severity' => $approved ? 'success' : 'warning',
                'title' => $approved ? 'Campaign approved' : 'Campaign needs changes',
                'body' => $body,
                'url' => route('campaigns.show', $campaign->id),
                'data' => ['campaign_id' => $campaign->id, 'decision' => $decision, 'reviewer_id' => $actor->id],
            ]
        ), 'campaign_review', $campaign->client_id);
    }

    public function campaignCompleted(int $campaignId): void
    {
        $campaign = Campaign::withoutGlobalScopes()->find($campaignId);
        if (! $campaign) {
            return;
        }
        $this->safe(fn () => $this->center->notifyUsers(
            $this->center->campaignAudience((int) $campaign->client_id, $campaign->owner_id),
            [
                'client_id' => $campaign->client_id,
                'category' => 'campaign',
                'event_key' => 'campaign.completed',
                'severity' => 'info',
                'title' => 'Campaign completed',
                'body' => '“'.$this->name($campaign->name).'” has finished all eligible recipients. Open reports to review its outcomes.',
                'url' => route('campaigns.show', $campaign->id),
                'data' => ['campaign_id' => $campaign->id],
            ]
        ), 'campaign_complete', $campaign->client_id);
    }

    /**
     * Alert active account members when available credit falls below the
     * configured threshold, or below one call's maximum hold by default.
     */
    public function lowBalance(WalletAccount $wallet, int $exposureMinor): void
    {
        $configured = config('notifications.low_balance_minor');
        $threshold = $configured === null ? max(1, $exposureMinor) : max(1, (int) $configured);
        if ($wallet->available_minor >= $threshold) {
            return;
        }
        $minutes = max(1, (int) config('notifications.low_balance_cooldown_minutes', 1440));
        $bucket = intdiv(now()->timestamp, $minutes * 60);
        $amount = Wallet::amount((int) $wallet->available_minor, $wallet->minor_unit_scale);
        $limit = Wallet::amount($threshold, $wallet->minor_unit_scale);
        $this->safe(fn () => $this->center->notifyUsers($this->center->clientMembers((int) $wallet->client_id), [
            'client_id' => $wallet->client_id,
            'category' => 'billing',
            'event_key' => 'billing.low_balance',
            'severity' => 'warning',
            'title' => 'Low calling credit',
            'body' => 'Available credit is '.$amount.' '.$wallet->currency.', below the '.$limit.' '.$wallet->currency
                .' alert threshold. Top up to keep campaigns running.',
            'url' => route('wallet.index'),
            'data' => [
                'wallet_id' => $wallet->id, 'available_minor' => $wallet->available_minor,
                'threshold_minor' => $threshold, 'currency' => $wallet->currency,
                'minor_unit_scale' => $wallet->minor_unit_scale,
            ],
            'dedupe_key' => 'low_balance:'.$wallet->id.':'.$bucket,
        ]), 'low_balance', $wallet->client_id);
    }

    public function paymentStatus(PaymentIntent $intent): void
    {
        if (! in_array($intent->status, ['succeeded', 'failed', 'cancelled'], true)) {
            return;
        }
        $owner = $this->center->activeMember((int) $intent->client_id, $intent->initiated_by);
        if (! $owner) {
            return;
        }
        $amount = Wallet::amount((int) $intent->amount_minor, $intent->minor_unit_scale).' '.$intent->currency;
        $title = match ($intent->status) {
            'succeeded' => 'Top-up confirmed',
            'failed' => 'Top-up failed',
            default => 'Top-up cancelled',
        };
        $body = match ($intent->status) {
            'succeeded' => 'Your top-up of '.$amount.' was confirmed and added to calling credit.',
            'failed' => 'Your top-up of '.$amount.' was not completed. No money was added to calling credit.'
                .($intent->failure_message ? ' '.$intent->failure_message : ''),
            default => 'Your top-up of '.$amount.' was cancelled. No money was added to calling credit.',
        };
        $this->safe(fn () => $this->center->notifyUser($owner, [
            'client_id' => $intent->client_id,
            'category' => 'payment',
            'event_key' => 'payment.'.$intent->status,
            'severity' => $intent->status === 'succeeded' ? 'success' : 'error',
            'title' => $title,
            'body' => $body,
            'url' => route('wallet.payments.show', $intent->public_id),
            'data' => [
                'payment_intent_id' => $intent->id, 'status' => $intent->status,
                'amount_minor' => $intent->amount_minor, 'currency' => $intent->currency,
            ],
            'dedupe_key' => 'payment:'.$intent->public_id.':'.$intent->status,
        ]), 'payment_status', $intent->client_id);
    }

    /** @param callable(): mixed $callback */
    private function safe(callable $callback, string $event, ?int $clientId): void
    {
        try {
            $callback();
        } catch (Throwable $e) {
            Log::warning('notification.trigger_failed', [
                'event' => $event, 'client_id' => $clientId, 'exception' => $e::class,
            ]);
        }
    }

    private function name(?string $value): string
    {
        return Str::limit(trim((string) $value) ?: 'Untitled', 120);
    }
}
