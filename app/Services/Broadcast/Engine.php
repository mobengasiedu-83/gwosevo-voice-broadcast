<?php

namespace App\Services\Broadcast;

use App\Models\AudioFile;
use App\Models\CallAttempt;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\CampaignRun;
use App\Models\CampaignVersion;
use App\Models\TrunkProfile;
use App\Models\User;
use App\Services\Billing\Rating;
use App\Services\Billing\Tariffs;
use App\Services\Billing\Wallet;
use App\Services\Billing\WalletDeclined;
use App\Services\Campaigns\Workspace;
use App\Services\Clients\Tenancy;
use App\Services\Foundation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class Engine
{
    /** Outcomes that can never be billable, so a held reservation is releasable. */
    private const UNANSWERED_OUTCOMES = ['busy', 'no_answer', 'failed', 'invalid_number', 'originate_failed', 'authorization_failed'];

    public function __construct(private Readiness $ready) {}

    public function mutex()
    {
        return DB::table('broadcast_gateway')->where('id', 1)->lockForUpdate()->first();
    }

    public function configure(User $actor, Campaign $campaign, array $config): void
    {
        Gate::forUser($actor)->authorize('campaigns.manage');
        DB::transaction(function () use ($actor, $campaign, $config) {
            $this->mutex();
            $campaign = Campaign::whereKey($campaign->id)->lockForUpdate()->firstOrFail();
            abort_unless(app(Tenancy::class)->sameAccount($actor, $campaign) || $campaign->owner_id === $actor->id || $actor->isSuperAdministrator(), 403);
            if ($campaign->archived_at || $campaign->status !== 'draft') {
                $this->ready->fail('Only a draft can be configured.');
            }
            $trunk = app(Workspace::class)->configuredTrunk();
            if (! $trunk) {
                $this->ready->fail('Set VITALPBX_TRUNK_NAME and import its connection profile first.');
            }
            if (app(Tenancy::class)->clientId($actor) !== null) {
                unset($config['caller_id']);
            }
            $config['trunk_id'] = $trunk->id;
            $campaign->configuration = $config;
            $this->ready->snapshot($campaign);
            $campaign->save();
            app(Foundation::class)->audit($actor, 'broadcast.configured', $campaign);
        });
    }

    public function control(User $actor, Campaign $campaign, string $action, bool $allowOwnedCampaign = false): void
    {
        app(Tenancy::class)->assertAccess($actor, $campaign);
        if ($action === 'emergency') {
            abort_unless($actor->can('broadcast.emergency') || ($actor->can('campaigns.manage') && (app(Tenancy::class)->sameAccount($actor, $campaign) || $campaign->owner_id === $actor->id)), 403);
        } else {
            abort_unless(Gate::forUser($actor)->allows('broadcast.operate') || ($actor->can('campaigns.manage') && (app(Tenancy::class)->sameAccount($actor, $campaign) || $campaign->owner_id === $actor->id)), 403);
        }
        DB::transaction(function () use ($actor, $campaign, $action) {
            $this->mutex();
            $campaign = Campaign::whereKey($campaign->id)->lockForUpdate()->firstOrFail();
            if (! in_array($action, ['start', 'pause', 'resume', 'emergency'], true)) {
                $this->ready->fail('Invalid control.');
            }
            if ($action === 'start') {
                if ($campaign->archived_at || ! in_array($campaign->status, ['ready', 'approved'], true)) {
                    $this->ready->fail('Campaign must be ready before it can start.');
                }
                $version = $campaign->versions()->latest('version')->first();
                if ($campaign->status === 'ready') {
                    $trunk = app(Workspace::class)->configuredTrunk();
                    if (! $trunk) {
                        $this->ready->fail('Set the configured trunk before starting.');
                    }
                    $config = $campaign->configuration;
                    $config['trunk_id'] = $trunk->id;
                    $campaign->configuration = $config;
                    $snapshot = $campaign->only(['uuid', 'name', 'purpose', 'type', 'timezone', 'configuration']);
                    $snapshot['broadcast'] = $this->ready->snapshot($campaign);
                    $version = $campaign->versions()->create([
                        'version' => ($campaign->versions()->max('version') ?? 0) + 1,
                        'snapshot' => $snapshot,
                        'approved_by' => $actor->id,
                        'approved_at' => now(),
                    ]);
                    app(Foundation::class)->audit($actor, 'campaign.launch_snapshot_created', $campaign, ['campaign_version_id' => $version->id, 'audio_approved_by' => AudioFile::findOrFail($snapshot['broadcast']['audio_id'])->approved_by]);
                }
                $s = $version?->snapshot['broadcast'] ?? null;
                if (! $s) {
                    $this->ready->fail('A complete campaign configuration is required before launch.');
                }
                $this->ready->liveAssets($s);
                $eligible = collect($s['targets'])->contains(fn ($target) => $this->ready->exclusion($target['id'], $target['phone_e164'], $s['purpose']) === null);
                if (! $eligible) {
                    $this->ready->fail('No eligible contacts. Review DND/suppression and the frozen audience before launch.');
                }
                $credit = app(Wallet::class)->admission($campaign->client_id, app(Tariffs::class)->snapshot($campaign->client_id));
                if ($credit['applicable'] && ! $credit['allowed']) {
                    $this->ready->fail(Wallet::message($credit['reason']));
                }
                CampaignRun::firstOrCreate(['campaign_id' => $campaign->id], ['uuid' => (string) Str::uuid(), 'campaign_version_id' => $version->id, 'started_by' => $actor->id, 'snapshot' => $version->snapshot, 'created_at' => now()]);
                foreach ($s['targets'] as $target) {
                    CampaignRecipient::firstOrCreate(['campaign_id' => $campaign->id, 'contact_id' => $target['id']], ['phone_e164' => $target['phone_e164'], 'status' => 'pending']);
                }
                $campaign->active_version_id = $version->id;
                $campaign->scheduled_at = $s['scheduled_at'] ?? null;
                $campaign->priority = $s['priority'] ?? 1;
                $campaign->status = $campaign->scheduled_at && CarbonImmutable::parse($campaign->scheduled_at)->isFuture() ? 'scheduled' : 'running';
            } elseif ($action === 'pause') {
                if (! in_array($campaign->status, ['running', 'scheduled'], true)) {
                    $this->ready->fail('Only running or scheduled campaigns can be paused.');
                }
                $campaign->status = 'paused';
            } elseif ($action === 'resume') {
                if ($campaign->status !== 'paused' || $campaign->stop_requested) {
                    $this->ready->fail('This campaign cannot resume.');
                }
                $version = CampaignVersion::where('campaign_id', $campaign->id)->whereKey($campaign->active_version_id)->first();
                $snapshot = $version?->snapshot['broadcast'] ?? null;
                if (! $snapshot) {
                    $this->ready->fail('A valid immutable launch snapshot is required before resuming.');
                }
                $this->ready->liveAssets($snapshot);
                $campaign->status = $campaign->scheduled_at && $campaign->scheduled_at->isFuture() ? 'scheduled' : 'running';
            } else {
                if (! in_array($campaign->status, ['running', 'paused', 'scheduled'], true)) {
                    $this->ready->fail('Campaign has no active broadcast run.');
                }
                $campaign->status = 'cancelled';
                $campaign->stop_requested = true;
                CampaignRecipient::where('campaign_id', $campaign->id)->where('status', 'pending')->update(['status' => 'cancelled']);
            }
            $campaign->save();
            app(Foundation::class)->audit($actor, 'broadcast.'.$action, $campaign);
        });
    }

    // Called by the sole gateway under the database admission mutex.
    public function reserve(string $owner): ?CallAttempt
    {
        $gateway = $this->mutex();
        if (! $gateway || $gateway->owner !== $owner || ! $gateway->healthy || ! $gateway->snapshot_at || strtotime($gateway->snapshot_at) < time() - 15 || ! $gateway->heartbeat_at || strtotime($gateway->heartbeat_at) < time() - 15 || $gateway->emergency_stop || ! config('voice_broadcast.enabled')) {
            return null;
        }
        $globalCap = (int) config('voice_broadcast.global_channels');
        $globalCps = (float) config('voice_broadcast.global_cps');
        if ($globalCap < 1 || $globalCps <= 0 || $gateway->next_send_at > microtime(true) || CallAttempt::where('state', 'unknown')->exists()) {
            return null;
        }
        $active = DB::table('channel_reservations')->whereNull('released_at');
        if ((clone $active)->count() >= $globalCap) {
            return null;
        }
        Campaign::where('status', 'scheduled')->where('scheduled_at', '<=', now())->where('stop_requested', false)->update(['status' => 'running']);
        $campaigns = Campaign::where('status', 'running')->where('stop_requested', false)->orderBy('dispatch_score')->orderBy('last_dispatched_at')->orderBy('id')->get();
        foreach ($campaigns as $campaign) {
            if (! app(Tenancy::class)->active($campaign->client_id)) {
                continue;
            }
            $s = CampaignVersion::findOrFail($campaign->active_version_id)->snapshot['broadcast'];
            if (! $this->ready->inWindow($s)) {
                continue;
            }
            $this->ready->liveAssets($s);
            $trunk = TrunkProfile::findOrFail($s['trunk_id']);
            $observed = json_decode($gateway->channels ?? '[]', true);
            $broadcastIds = CallAttempt::whereNotNull('uuid')->whereNull('ended_at')->pluck('uniqueid')->all();
            $ordinary = count(array_filter($observed, fn ($ch) => ! in_array($ch['uniqueid'], $broadcastIds, true)));
            $cap = min($trunk->max_channels - max($trunk->reserved_channels, $ordinary), $trunk->safe_channels);
            if ((clone $active)->where('trunk_profile_id', $trunk->id)->count() >= $cap || (clone $active)->where('campaign_id', $campaign->id)->count() >= $s['concurrency'] || $trunk->next_send_at > microtime(true)) {
                continue;
            }
            // Campaign CPS enforced with a persisted last-send time, independently of trunk/global CPS.
            if ($campaign->last_dispatched_at && (float) CarbonImmutable::parse($campaign->last_dispatched_at)->format('U.u') + 1 / $s['cps'] > microtime(true)) {
                continue;
            }
            foreach (CampaignRecipient::where('campaign_id', $campaign->id)->where('status', 'pending')->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))->orderBy('id')->limit(100)->get() as $recipient) {
                $reason = $this->ready->exclusion($recipient->contact_id, $recipient->phone_e164, $s['purpose']);
                if ($reason) {
                    $recipient->update(['status' => 'excluded', 'exclusion_reason' => $reason]);

                    continue;
                }
                $uuid = (string) Str::uuid();
                $attempt = CallAttempt::create(['tariff_snapshot' => app(Tariffs::class)->snapshot($campaign->client_id), 'uuid' => $uuid, 'action_id' => 'vb-'.$uuid, 'uniqueid' => $uuid, 'campaign_recipient_id' => $recipient->id, 'campaign_version_id' => $campaign->active_version_id, 'trunk_profile_id' => $trunk->id, 'token' => bin2hex(random_bytes(32)), 'state' => 'authorizing', 'attempt_no' => (CallAttempt::where('campaign_recipient_id', $recipient->id)->max('attempt_no') ?? 0) + 1]);
                try {
                    app(Wallet::class)->reserve($attempt);
                } catch (WalletDeclined $declined) {
                    // Fail closed: no origination without a held reservation.
                    $attempt->update(['state' => 'terminal', 'outcome' => 'wallet_declined', 'ended_at' => now(), 'token' => null]);
                    $campaign->update(['status' => 'paused']);
                    \App\Models\AuditLog::create([
                        'client_id' => $campaign->client_id, 'actor_id' => null, 'action' => 'billing.wallet_declined',
                        'entity_type' => $attempt->getMorphClass(), 'entity_id' => $attempt->id,
                        'details' => ['reason' => $declined->reason] + $declined->context, 'created_at' => now(),
                    ]);

                    return null;
                }
                DB::table('channel_reservations')->insert(['call_attempt_id' => $attempt->id, 'campaign_id' => $campaign->id, 'trunk_profile_id' => $trunk->id, 'acquired_at' => now()]);
                $recipient->update(['status' => 'reserved']);
                $campaign->update(['last_dispatched_at' => now()->format('Y-m-d H:i:s.u'), 'dispatch_score' => $campaign->dispatch_score + 1 / max(1, $campaign->priority)]);

                return $attempt;
            }
            $this->complete($campaign->id);
        }

        return null;
    }

    public function complete(int $campaignId): void
    {
        if (! CampaignRecipient::where('campaign_id', $campaignId)->whereIn('status', ['pending', 'reserved', 'active', 'unknown'])->exists()) {
            Campaign::whereKey($campaignId)->where('status', 'running')->update(['status' => 'completed']);
        }
    }

    public function terminal(CallAttempt $attempt, string $outcome): void
    {
        if ($attempt->ended_at) {
            return;
        }
        $unsent = ! $attempt->sent_at && in_array($attempt->state, ['authorizing', 'ready'], true);
        $attempt->update(['state' => 'terminal', 'outcome' => $outcome, 'ended_at' => now()]);
        $answered = $attempt->answered_at || $attempt->playback_started_at || $attempt->playback_completed_at || $attempt->ivr_completed_at || $attempt->opted_out_at || $attempt->transfer_answered_at;
        if ($attempt->sent_at) {
            app(Rating::class)->issue($attempt, 'timing_required');
            if (! $answered && in_array($outcome, self::UNANSWERED_OUTCOMES, true)) {
                // Definitive no-answer/busy/failed: release the hold, post zero usage.
                app(Wallet::class)->release($attempt, 'Call ended without an answer: '.$outcome);
            }
        } elseif ($unsent) {
            app(Rating::class)->unsent($attempt);
        }
        DB::table('channel_reservations')->where('call_attempt_id', $attempt->id)->whereNull('released_at')->update(['released_at' => now()]);
        $recipient = CampaignRecipient::findOrFail($attempt->campaign_recipient_id);
        if (! app(CampaignPolicy::class)->retry($attempt, $outcome)) {
            $recipient->update(['status' => 'terminal']);
        }
        $this->complete($recipient->campaign_id);
    }

    public function disconnected(string $owner): void
    {
        DB::transaction(function () use ($owner) {
            $g = $this->mutex();
            if ($g->owner !== $owner) {
                return;
            }
            DB::table('broadcast_gateway')->where('id', 1)->update(['healthy' => false, 'snapshot_at' => null, 'channels' => '[]']);
            DB::table('dashboard_telemetry')->where('source', 'queues')->update(['status' => 'unavailable']);
            // No Originate intent was committed for authorizing/ready attempts; these cannot be live.
            foreach (CallAttempt::whereNotNull('uuid')->whereNull('ended_at')->whereIn('state', ['authorizing', 'ready'])->get() as $unsent) {
                $this->terminal($unsent, 'cancelled_before_send');
                // Keep the recipient terminal: Phase 3 never silently retries a call request.
            }
            $ids = CallAttempt::whereNotNull('uuid')->whereNull('ended_at')->pluck('campaign_recipient_id');
            CallAttempt::whereNotNull('uuid')->whereNull('ended_at')->update(['state' => 'unknown', 'outcome' => 'reconciling']);
            CampaignRecipient::whereIn('id', $ids)->update(['status' => 'unknown']);
        });
    }
}
