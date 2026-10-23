<?php

namespace App\Services\Broadcast;

use App\Models\CallAttempt;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\CampaignVersion;
use App\Models\TrunkProfile;
use App\Services\Dashboard\QueueTelemetry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class Gateway
{
    public string $owner;

    private array $snapshot = [];

    private array $queueFrames = [];

    private array $queueDeltas = [];

    private string $queueAction = '';

    private float $queueRequested = 0;

    private bool $queuesAllowed = true;

    private string $snapshotId = '';

    private string $snapshotAction = 'CoreShowChannels';

    private float $snapshotRequested = 0;

    private float $lastPong = 0;

    private float $pingAt = 0;

    private array $hangupSent = [];

    public bool $pongVerified = false;

    public bool $snapshotVerified = false;

    public function __construct(private Engine $engine, private Readiness $ready, private EventProcessor $events)
    {
        $this->owner = (string) Str::uuid();
    }

    public function claim(): void
    {
        DB::transaction(function () {
            $g = $this->engine->mutex();
            if ($g->owner && $g->owner !== $this->owner && $g->heartbeat_at && strtotime($g->heartbeat_at) > time() - 60) {
                throw new RuntimeException('Another gateway owns the lease.');
            }
            DB::table('broadcast_gateway')->where('id', 1)->update(['owner' => $this->owner, 'heartbeat_at' => now(), 'healthy' => false]);
        });
        $this->engine->disconnected($this->owner);
        $this->events->replay();
    }

    private function heartbeat(): void
    {
        $count = DB::table('broadcast_gateway')->where('id', 1)->where('owner', $this->owner)->update(['heartbeat_at' => now()]);
        if (! $count && DB::table('broadcast_gateway')->where('id', 1)->value('owner') !== $this->owner) {
            throw new RuntimeException('Gateway lease lost.');
        }
    }

    public function session(AmiClient $ami, callable $stop): void
    {
        $this->events->replay();
        $ami->connect();
        $this->queueAction = '';
        $this->queueRequested = 0;
        $this->queuesAllowed = true;
        $this->lastPong = microtime(true);
        $this->pongVerified = false;
        $this->snapshotVerified = false;
        $this->pingAt = 0;
        $this->snapshotId = '';
        $this->snapshotRequested = 0;
        while (! $stop()) {
            $this->heartbeat();
            foreach ($ami->poll() as $f) {
                if (($f['ActionID'] ?? '') === 'vb-ping' && ($f['Ping'] ?? '') === 'Pong') {
                    $this->lastPong = microtime(true);
                    $this->pongVerified = true;
                }
                if (($f['ActionID'] ?? '') === $this->snapshotId) {
                    if (($f['Response'] ?? '') === 'Error') {
                        if ($this->snapshotAction === 'CoreShowChannels') {
                            $this->snapshotAction = 'Status';
                            $this->snapshot = [];
                            $this->snapshotId = 'snapshot-'.Str::uuid();
                            $this->snapshotRequested = microtime(true);
                            $ami->send(['Action' => 'Status', 'ActionID' => $this->snapshotId]);

                            continue;
                        }
                        throw new RuntimeException('AMI channel inventory rejected. Verify AMI read permissions.');
                    }
                    if (in_array($f['Event'] ?? '', ['CoreShowChannel', 'Status'], true)) {
                        if (empty($f['Uniqueid']) || empty($f['Channel'])) {
                            throw new RuntimeException('AMI channel inventory lacks correlation identifiers.');
                        }
                        $this->snapshot[] = ['uniqueid' => $f['Uniqueid'] ?? '', 'channel' => $f['Channel'] ?? '', 'linkedid' => $f['Linkedid'] ?? ''];
                    }
                    if (count($this->snapshot) > 10000) {
                        throw new RuntimeException('Channel inventory exceeds safety bound.');
                    }
                    if (in_array($f['Event'] ?? '', ['CoreShowChannelsComplete', 'StatusComplete'], true)) {
                        $expected = $f['ListItems'] ?? $f['Items'] ?? null;
                        if ($expected !== null && (! ctype_digit((string) $expected) || (int) $expected !== count($this->snapshot))) {
                            throw new RuntimeException('AMI channel inventory count mismatch.');
                        }
                        DB::table('broadcast_gateway')->where('id', 1)->where('owner', $this->owner)->update(['channels' => json_encode($this->snapshot, JSON_THROW_ON_ERROR), 'snapshot_at' => now(), 'healthy' => true]);
                        $this->snapshotId = '';
                        $this->snapshotVerified = true;
                    }
                }
                if (str_starts_with($f['ActionID'] ?? '', 'auth-') && isset($f['Response'])) {
                    $this->authorized($ami, substr($f['ActionID'], 5), $f['Response'] === 'Success');
                }
                $telemetry = app(QueueTelemetry::class);
                if ($this->queueAction !== '' && ($f['ActionID'] ?? '') === $this->queueAction) {
                    if (($f['Response'] ?? '') === 'Error') {
                        $telemetry->state('unavailable');
                        $this->queuesAllowed = false;
                        $this->queueAction = '';
                    } elseif (($f['Event'] ?? '') === 'QueueStatusComplete') {
                        $telemetry->snapshot($this->queueFrames, $this->queueDeltas);
                        $this->queueAction = '';
                    } elseif (in_array($f['Event'] ?? '', ['QueueParams', 'QueueMember'], true)) {
                        $this->queueFrames[] = $f;
                        if (count($this->queueFrames) > 10000) {
                            throw new RuntimeException('Queue inventory exceeds safety bound.');
                        }
                    }
                } elseif (in_array($f['Event'] ?? '', ['QueueMemberAdded', 'QueueMemberRemoved', 'QueueMemberStatus', 'QueueMemberPause', 'QueueCallerJoin', 'QueueCallerLeave'], true)) {
                    if ($this->queueAction !== '') {
                        $this->queueDeltas[] = $f;
                    } else {
                        $telemetry->event($f);
                    }
                    if (count($this->queueDeltas) > 10000) {
                        throw new RuntimeException('Queue delta buffer exceeds safety bound.');
                    }
                }
                $this->events->ingest($f);
            }
            if (microtime(true) - $this->lastPong > 15) {
                throw new RuntimeException('AMI heartbeat expired.');
            }
            if (microtime(true) - $this->pingAt > 5) {
                $ami->send(['Action' => 'Ping', 'ActionID' => 'vb-ping']);
                $this->pingAt = microtime(true);
            }
            if ($this->snapshotId !== '' && microtime(true) - $this->snapshotRequested > 10) {
                throw new RuntimeException('Active-channel snapshot timed out.');
            }
            if ($this->snapshotId === '' && microtime(true) - $this->snapshotRequested > 5) {
                $this->snapshot = [];
                $this->snapshotId = 'snapshot-'.Str::uuid();
                $this->snapshotRequested = microtime(true);
                $ami->send(['Action' => $this->snapshotAction, 'ActionID' => $this->snapshotId]);
            }
            if ($this->queuesAllowed && $this->queueAction === '' && microtime(true) - $this->queueRequested > 30) {
                $this->queueFrames = [];
                $this->queueDeltas = [];
                $this->queueAction = 'queues-'.Str::uuid();
                $this->queueRequested = microtime(true);
                $ami->send(['Action' => 'QueueStatus', 'ActionID' => $this->queueAction]);
            }
            if ($this->queueAction !== '' && microtime(true) - $this->queueRequested > 10) {
                app(QueueTelemetry::class)->state('unavailable');
                $this->queuesAllowed = false;
                $this->queueAction = '';
            }
            if (! config('voice_broadcast.observe_only', false)) {
                $this->hangups($ami);
            }
            $cleanup = CallAttempt::whereNotNull('ended_at')->whereNotNull('token')->orderBy('id')->first();
            if ($cleanup && ! config('voice_broadcast.observe_only', false)) {
                $ami->send(['Action' => 'DBDel', 'ActionID' => 'cleanup-'.$cleanup->uuid, 'Family' => 'vb-auth', 'Key' => $cleanup->uuid]);
                $cleanup->update(['token' => null]);
            }
            foreach (CallAttempt::where('state', 'ready')->whereNull('ended_at')->orderBy('id')->limit(100)->get() as $ready) {
                $this->authorized($ami, $ready->uuid, true);
            }
            $attempt = DB::transaction(fn () => $this->engine->reserve($this->owner));
            if ($attempt) {
                $ami->send(['Action' => 'DBPut', 'ActionID' => 'auth-'.$attempt->uuid, 'Family' => 'vb-auth', 'Key' => $attempt->uuid, 'Val' => $attempt->token]);
            }
            // Unacknowledged originates/auth writes are never retried.
            if (CallAttempt::whereNull('ended_at')->whereIn('state', ['authorizing', 'sending'])->where('updated_at', '<', now()->subSeconds(45))->exists()) {
                throw new RuntimeException('Uncertain originate acknowledgement.');
            }
        }
    }

    public function authorized(AmiClient $ami, string $uuid, bool $success): void
    {
        $intent = DB::transaction(function () use ($uuid, $success) {
            $g = $this->engine->mutex();
            if ($g->owner !== $this->owner) {
                throw new RuntimeException('Gateway lease lost.');
            }
            $a = CallAttempt::where('uuid', $uuid)->lockForUpdate()->first();
            if (! $a || ! in_array($a->state, ['authorizing', 'ready'], true) || $a->ended_at) {
                return null;
            }
            if (! $success) {
                $this->engine->terminal($a, 'authorization_failed');

                return null;
            }
            $a->update(['state' => 'ready']);
            $recipient = CampaignRecipient::findOrFail($a->campaign_recipient_id);
            $campaign = Campaign::findOrFail($recipient->campaign_id);
            $s = CampaignVersion::findOrFail($a->campaign_version_id)->snapshot['broadcast'];
            if (! $this->canSend($g, $a, $campaign, $recipient, $s)) {
                return null;
            }
            $trunk = TrunkProfile::findOrFail($s['trunk_id']);
            $cps = (float) config('voice_broadcast.global_cps');
            if ($cps <= 0 || $g->next_send_at > microtime(true) || $trunk->next_send_at > microtime(true)) {
                return null;
            }
            if ($campaign->last_dispatched_at && (float) CarbonImmutable::parse($campaign->last_dispatched_at)->format('U.u') + 1 / $s['cps'] > microtime(true)) {
                return null;
            }
            DB::table('broadcast_gateway')->where('id', 1)->update(['next_send_at' => microtime(true) + 1 / $cps]);
            $trunk->update(['next_send_at' => microtime(true) + 1 / $trunk->max_cps]);
            $campaign->update(['last_dispatched_at' => now()->format('Y-m-d H:i:s.u')]);
            // Commit intent BEFORE socket I/O. A crash or commit failure after sending can never make this retryable.
            $a->update(['state' => 'sending', 'sent_at' => now()]);

            return $a->id;
        });
        if (! $intent) {
            return;
        }
        DB::transaction(function () use ($intent, $ami) {
            $g = $this->engine->mutex();
            if ($g->owner !== $this->owner) {
                throw new RuntimeException('Gateway lease lost.');
            }
            $a = CallAttempt::whereKey($intent)->lockForUpdate()->firstOrFail();
            if ($a->state !== 'sending' || $a->ended_at) {
                return;
            }
            $recipient = CampaignRecipient::findOrFail($a->campaign_recipient_id);
            $campaign = Campaign::findOrFail($recipient->campaign_id);
            $s = CampaignVersion::findOrFail($a->campaign_version_id)->snapshot['broadcast'];
            if (! $this->canSend($g, $a, $campaign, $recipient, $s)) {
                // No network write has occurred in this stage; preserve paused/window-blocked work.
                if (! $a->ended_at) {
                    $a->update(['state' => 'ready', 'sent_at' => null]);
                }

                return;
            }
            $phone = DialNumber::format($recipient->phone_e164, $s['number_format']);
            // Consent withdrawal, suppression, pause and stop share this mutex through the final write.
            $ami->send(['Action' => 'Originate', 'ActionID' => $a->action_id, 'Channel' => 'PJSIP/'.$phone.'@'.$s['trunk_name'], 'ChannelId' => $a->uuid, 'Context' => $s['ivr']['context'] ?? config('voice_broadcast.context'), 'Exten' => 's', 'Priority' => 1, 'Async' => 'true', 'CallerID' => $s['caller_id'], 'Timeout' => 30000, 'Variable' => ['__VB_ATTEMPT_ID='.$a->uuid, '__VB_TOKEN='.$a->token, '__VB_AUDIO_ID='.$s['audio_uuid'], '__VB_CHECKSUM='.$s['checksum']]]);
            // Charge actual socket writes as well as intents so delayed writes cannot create a CPS burst.
            DB::table('broadcast_gateway')->where('id', 1)->update(['next_send_at' => microtime(true) + 1 / (float) config('voice_broadcast.global_cps')]);
            $trunk = TrunkProfile::findOrFail($s['trunk_id']);
            $trunk->update(['next_send_at' => microtime(true) + 1 / $trunk->max_cps]);
            $campaign->update(['last_dispatched_at' => now()->format('Y-m-d H:i:s.u')]);

        });
    }

    private function canSend($g, CallAttempt $a, Campaign $campaign, CampaignRecipient $recipient, array $s): bool
    {
        $reason = $this->ready->exclusion($recipient->contact_id, $recipient->phone_e164, $s['purpose']);
        if (! config('voice_broadcast.enabled') || $g->emergency_stop || ! in_array($campaign->status, ['running', 'paused'], true) || $campaign->stop_requested || $a->stop_requested_at || $reason) {
            $this->engine->terminal($a, $reason ?? 'cancelled_before_send');

            return false;
        }
        if ($campaign->status === 'paused' || ! $this->ready->inWindow($s)) {
            return false;
        }
        if (! $g->healthy || ! $g->snapshot_at || strtotime($g->snapshot_at) < time() - 15 || CallAttempt::where('state', 'unknown')->exists()) {
            return false;
        }
        $this->ready->liveAssets($s);
        $trunk = TrunkProfile::findOrFail($s['trunk_id']);
        $slots = DB::table('channel_reservations')->whereNull('released_at');
        $known = CallAttempt::whereNull('ended_at')->pluck('uniqueid')->all();
        $ordinary = count(array_filter(json_decode($g->channels ?? '[]', true), fn ($ch) => ! in_array($ch['uniqueid'], $known, true)));
        $cap = min($trunk->max_channels - max($trunk->reserved_channels, $ordinary), $trunk->safe_channels);

        return (clone $slots)->count() <= (int) config('voice_broadcast.global_channels') &&
            (clone $slots)->where('trunk_profile_id', $trunk->id)->count() <= $cap &&
            (clone $slots)->where('campaign_id', $campaign->id)->count() <= $s['concurrency'];
    }

    private function hangups(AmiClient $ami): void
    {
        $g = DB::table('broadcast_gateway')->where('id', 1)->first();
        $ids = Campaign::where('stop_requested', true)->pluck('id');
        $recipientIds = CampaignRecipient::whereIn('campaign_id', $ids)->pluck('id');
        $query = CallAttempt::whereNull('ended_at')->whereNotNull('channel');
        if (! $g->emergency_stop) {
            $query->where(fn ($q) => $q->whereIn('campaign_recipient_id', $recipientIds)->orWhereNotNull('stop_requested_at'));
        }
        foreach ($query->get() as $a) {
            if (! preg_match('/\APJSIP\/[A-Za-z0-9_.@+\/-]+\z/', $a->channel)) {
                continue;
            }
            $matches = array_filter(json_decode($g->channels ?? '[]', true), fn ($ch) => $ch['uniqueid'] === $a->uniqueid && $ch['channel'] === $a->channel);
            if (! $g->snapshot_at || strtotime($g->snapshot_at) < time() - 10 || ! $matches) {
                continue;
            }
            if (($this->hangupSent[$a->id] ?? 0) > microtime(true) - 5) {
                continue;
            }
            $this->hangupSent[$a->id] = microtime(true);
            if (count($this->hangupSent) > 1000) {
                $this->hangupSent = array_filter($this->hangupSent, fn ($sent) => $sent > microtime(true) - 10);
            }
            // Exact observed broadcast channel only; never use a channel regex or trunk-wide hangup.
            $ami->send(['Action' => 'Hangup', 'ActionID' => 'stop-'.$a->uuid, 'Channel' => $a->channel]);
        }
    }

    public function release(): void
    {
        $this->engine->disconnected($this->owner);
        DB::table('broadcast_gateway')->where('id', 1)->where('owner', $this->owner)->update(['owner' => null, 'healthy' => false]);
    }
}
