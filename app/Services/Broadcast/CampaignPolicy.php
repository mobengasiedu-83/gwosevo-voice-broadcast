<?php

namespace App\Services\Broadcast;

use App\Models\CallAttempt;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\CampaignVersion;
use App\Models\Client;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class CampaignPolicy
{
    public function schedule(array $config): array
    {
        Validator::make($config, [
            'caller_id' => 'nullable|regex:/\A\+?[0-9]{3,15}\z/', 'timezone' => 'nullable|timezone',
            'scheduled_at' => 'nullable|date', 'max_attempts' => 'nullable|integer|min:1|max:5',
            'retry_delay_seconds' => 'nullable|integer|min:60|max:86400', 'priority' => 'nullable|integer|min:1|max:10',
        ])->validate();
        $zone = $config['timezone'] ?? 'Africa/Accra';

        return ['scheduled_at' => empty($config['scheduled_at']) ? null : CarbonImmutable::parse($config['scheduled_at'], $zone)->utc()->toIso8601String(),
            'timezone' => $zone, 'priority' => (int) ($config['priority'] ?? 1),
            'retry_policy' => ['max_attempts' => (int) ($config['max_attempts'] ?? 1), 'delay_seconds' => (int) ($config['retry_delay_seconds'] ?? 300), 'outcomes' => ['busy', 'no_answer', 'failed']]];
    }

    public function retry(CallAttempt $attempt, string $outcome): bool
    {
        if ($attempt->answered_at || $attempt->opted_out_at || $attempt->playback_completed_at || $attempt->ivr_completed_at || $attempt->transfer_answered_at || ! $attempt->sent_at || ! in_array($outcome, ['busy', 'no_answer', 'failed'], true)) {
            return false;
        }
        $s = CampaignVersion::findOrFail($attempt->campaign_version_id)->snapshot['broadcast'];
        $p = $s['retry_policy'];
        $recipient = CampaignRecipient::findOrFail($attempt->campaign_recipient_id);
        $campaign = Campaign::findOrFail($recipient->campaign_id);
        if (! in_array($campaign->status, ['running', 'paused'], true) || $campaign->stop_requested || $attempt->attempt_no >= ($p['max_attempts'] ?? 1) || app(Readiness::class)->exclusion($recipient->contact_id, $recipient->phone_e164, $s['purpose'])) {
            return false;
        }
        $delay = min(86400, ($p['delay_seconds'] ?? 300) * 2 ** ($attempt->attempt_no - 1));
        $recipient->update(['status' => 'pending', 'next_attempt_at' => now()->addSeconds($delay)]);

        return true;
    }

    public function activateDue(): void
    {
        DB::transaction(function () {
            app(Engine::class)->mutex();
            Campaign::where('status', 'scheduled')->whereNull('archived_at')->where(fn ($q) => $q->whereNull('client_id')->orWhereIn('client_id', Client::where('status', 'active')->select('id')))->where('scheduled_at', '<=', now())->where('stop_requested', false)->update(['status' => 'running']);
        });
    }
}
