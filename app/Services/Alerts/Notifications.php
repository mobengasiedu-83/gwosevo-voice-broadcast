<?php

namespace App\Services\Alerts;

use App\Jobs\DeliverClientAlert;
use App\Models\AudioFile;
use App\Models\Campaign;
use App\Models\ClientNotice;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Clients\Portal;
use Illuminate\Support\Facades\DB;

class Notifications
{
    public function notice(int $account, string $key, string $kind, string $title, string $message, string $severity = 'info', ?int $recipient = null, $at = null): ClientNotice
    {
        if ($recipient) {
            abort_unless(User::whereKey($recipient)->where('client_account_id', $account)->exists(), 403);
        }

        return ClientNotice::firstOrCreate(['event_key' => hash('sha256', $key)], [
            'client_account_id' => $account, 'recipient_user_id' => $recipient, 'kind' => $kind,
            'title' => $title, 'message' => $message, 'severity' => $severity, 'occurred_at' => $at ?? now(),
        ]);
    }

    public function scan(): void
    {
        $active = DB::table('client_accounts')->where('status', 'active')->select('id');
        AudioFile::whereIn('client_account_id', $active)->whereIn('status', ['approved', 'rejected'])->where('updated_at', '>=', now()->subDays(30))->chunkById(200, function ($files) {
            foreach ($files as $audio) {
                app(Portal::class)->reviewNotice($audio);
            }
        });
        Campaign::whereIn('client_account_id', $active)->whereIn('status', ['completed', 'failed', 'cancelled'])->where('updated_at', '>=', now()->subDays(30))->chunkById(200, function ($campaigns) {
            foreach ($campaigns as $c) {
                $this->notice($c->client_account_id, 'campaign:'.$c->id.':'.$c->status, 'campaign.'.$c->status, 'Campaign '.$c->status,
                    $c->name.' execution is '.$c->status.'. Review call and playback outcomes; execution status alone does not confirm delivery.', $c->status === 'failed' ? 'warning' : 'info', null, $c->updated_at);
            }
        });
        $failures = DB::table('call_attempts as a')->join('campaign_recipients as r', 'r.id', '=', 'a.campaign_recipient_id')->join('campaigns as c', 'c.id', '=', 'r.campaign_id')
            ->whereIn('c.client_account_id', $active)->where('a.created_at', '>=', now()->subDay())
            ->where(fn ($q) => $q->where('a.state', 'unknown')->orWhereIn('a.outcome', ['playback_failed', 'authorization_failed', 'originate_failed', 'failed']))
            ->select('c.id', 'c.client_account_id', 'c.name')->distinct()->orderBy('c.id')->cursor();
        foreach ($failures as $c) {
            $this->notice($c->client_account_id, 'failure:'.$c->id.':'.now()->format('Y-m-d'), 'campaign.failure', 'Call outcomes need attention',
                $c->name.' has failed or uncertain call attempts. Review the campaign report and contact support if needed.', 'warning');
        }
        User::whereIn('client_account_id', $active)->orderBy('id')->chunkById(200, function ($users) {
            foreach ($users as $user) {
                $wallet = Wallet::where('client_account_id', $user->client_account_id)->first();
                if (! $wallet) {
                    continue;
                }
                $threshold = (int) DB::table('notification_preferences')->where('user_id', $user->id)->value('low_balance_minor');
                $this->signal($user, 'low_credit:'.$wallet->id, $threshold > 0 && $wallet->balance_minor - $wallet->reserved_minor < $threshold,
                    'wallet.low_credit', 'Low available credit', 'Your available wallet credit is below your notification threshold. Review your wallet before starting more calls.');
                $review = DB::table('wallet_reservations')->where('wallet_id', $wallet->id)->where('status', 'review')->exists();
                $this->signal($user, 'billing_review:'.$wallet->id, $review, 'wallet.review', 'Billing review required',
                    'A call outcome or duration needs reconciliation. Reserved funds may remain held. Contact support and review your wallet.');
            }
        });
        $this->prepareDeliveries();
    }

    private function signal(User $user, string $key, bool $active, string $kind, string $title, string $message): void
    {
        DB::transaction(function () use ($user, $key, $active, $kind, $title, $message) {
            DB::table('notification_signals')->insertOrIgnore(['user_id' => $user->id, 'signal_key' => $key, 'active' => false, 'generation' => 0, 'created_at' => now(), 'updated_at' => now()]);
            $signal = DB::table('notification_signals')->where('user_id', $user->id)->where('signal_key', $key)->lockForUpdate()->first();
            if ($active && ! $signal->active) {
                $generation = $signal->generation + 1;
                $this->notice($user->client_account_id, $key.':'.$user->id.':'.$generation, $kind, $title, $message, 'warning', $user->id);
                DB::table('notification_signals')->where('id', $signal->id)->update(['generation' => $generation]);
            }
            DB::table('notification_signals')->where('id', $signal->id)->update(['active' => $active, 'updated_at' => now()]);
        });
    }

    public function prepareDeliveries(): void
    {
        DB::table('notification_preferences')->whereNotNull('channels_enabled_at')->orderBy('id')->chunkById(200, function ($preferences) {
            foreach ($preferences as $pref) {
                $user = User::find($pref->user_id);
                if (! $user || ! $user->isClient() || ! $user->clientAccountActive()) {
                    continue;
                }
                $notices = app(Portal::class)->notices($user)->where('occurred_at', '>=', $pref->channels_enabled_at)->where('occurred_at', '>=', now()->subDays(7))->orderByDesc('client_notices.id')->limit(200)->get();
                foreach ($notices as $notice) {
                    foreach (['email', 'sms'] as $channel) {
                        if ($pref->{$channel.'_enabled'}) {
                            DB::table('notification_deliveries')->insertOrIgnore(['client_notice_id' => $notice->id, 'user_id' => $user->id, 'channel' => $channel, 'status' => 'pending', 'attempts' => 0, 'created_at' => now(), 'updated_at' => now()]);
                        }
                    }
                }
            }
        });
        // A process may have sent before crashing. Do not blindly repeat an uncertain send.
        DB::table('notification_deliveries')->where('status', 'sending')->where('updated_at', '<', now()->subMinutes(10))->update(['status' => 'unknown', 'failure_reason' => 'Delivery outcome is uncertain; automatic retry stopped.', 'updated_at' => now()]);
        if (! config('client_alerts.delivery_enabled')) {
            return;
        }
        DB::table('notification_deliveries')->whereIn('status', ['pending', 'failed'])->where('attempts', '<', config('client_alerts.max_attempts'))
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))->orderBy('id')->limit(200)->get()->each(fn ($row) => DeliverClientAlert::dispatch($row->id));
    }
}
