<?php

namespace App\Services\Broadcast;

use App\Models\CallAttempt;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\CampaignVersion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class CampaignPolicy
{
    public function advanced(array $config): array
    {
        Validator::make($config, ['excluded_dates_text' => 'nullable|string|max:2000', 'retry_policy_configured' => 'nullable|boolean'])->validate();
        if (array_key_exists('excluded_dates_text', $config)) {
            $config['excluded_dates'] = array_values(array_filter(preg_split('/[\s,]+/', trim((string) $config['excluded_dates_text']))));
        }
        $config['retry_outcomes'] = $config['retry_outcomes'] ?? (empty($config['retry_policy_configured']) ? ['busy', 'no_answer', 'failed'] : []);
        $data = Validator::make($config, [
            'retry_outcomes' => 'array|max:3', 'retry_outcomes.*' => 'required|distinct|in:busy,no_answer,failed',
            'excluded_dates' => 'nullable|array|max:100', 'excluded_dates.*' => 'required|distinct|date_format:Y-m-d',
            'expires_on' => 'nullable|date_format:Y-m-d',
        ])->validate();

        return ['retry_outcomes' => $data['retry_outcomes'], 'excluded_dates' => $data['excluded_dates'] ?? [], 'expires_on' => $data['expires_on'] ?? null];
    }

    public function schedule(array $config): array
    {
        Validator::make($config, [
            'caller_id' => 'nullable|regex:/\A\+?[0-9]{3,15}\z/', 'timezone' => 'nullable|timezone',
            'scheduled_at' => 'nullable|date', 'max_attempts' => 'nullable|integer|min:1|max:5',
            'retry_delay_seconds' => 'nullable|integer|min:60|max:86400', 'priority' => 'nullable|integer|min:1|max:10',
        ])->validate();
        $advanced = $this->advanced($config);
        $zone = $config['timezone'] ?? 'Africa/Accra';

        return ['scheduled_at' => empty($config['scheduled_at']) ? null : CarbonImmutable::parse($config['scheduled_at'], $zone)->utc()->toIso8601String(),
            'excluded_dates' => $advanced['excluded_dates'], 'expires_on' => $advanced['expires_on'],
            'timezone' => $zone, 'priority' => (int) ($config['priority'] ?? 1),
            'retry_policy' => ['max_attempts' => (int) ($config['max_attempts'] ?? 1), 'delay_seconds' => (int) ($config['retry_delay_seconds'] ?? 300), 'outcomes' => $advanced['retry_outcomes']]];
    }

    public function retry(CallAttempt $attempt, string $outcome): bool
    {
        if ($attempt->opted_out_at || $attempt->playback_completed_at || $attempt->ivr_completed_at || $attempt->transfer_answered_at || ! $attempt->sent_at || ! in_array($outcome, ['busy', 'no_answer', 'failed'], true)) {
            return false;
        }
        $s = CampaignVersion::findOrFail($attempt->campaign_version_id)->snapshot['broadcast'];
        $p = $s['retry_policy'];
        if (! in_array($outcome, $p['outcomes'] ?? ['busy', 'no_answer', 'failed'], true)) {
            return false;
        }
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
            Campaign::where('status', 'scheduled')->where('scheduled_at', '<=', now())->where('stop_requested', false)->update(['status' => 'running']);
            Campaign::whereIn('status', ['running', 'paused'])->whereNotNull('active_version_id')->orderBy('id')->chunkById(100, function ($campaigns) {
                foreach ($campaigns as $campaign) {
                    $snapshot = CampaignVersion::findOrFail($campaign->active_version_id)->snapshot['broadcast'];
                    if (! empty($snapshot['expires_on']) && CarbonImmutable::now($snapshot['timezone'])->format('Y-m-d') > $snapshot['expires_on']) {
                        CampaignRecipient::where('campaign_id', $campaign->id)->where('status', 'pending')->update(['status' => 'excluded', 'exclusion_reason' => 'campaign_expired']);
                        app(Engine::class)->complete($campaign->id);
                    }
                }
            });
        });
    }
}
