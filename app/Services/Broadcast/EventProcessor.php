<?php

namespace App\Services\Broadcast;

use App\Models\AuditLog;
use App\Models\CallAttempt;
use App\Models\CallEvent;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\CampaignVersion;
use App\Services\Billing\Ledger;
use Illuminate\Support\Facades\DB;

class EventProcessor
{
    public function ingest(array $frame): void
    {
        $action = $frame['ActionID'] ?? '';
        $uuid = $frame['AttemptID'] ?? (str_starts_with($action, 'vb-') ? substr($action, 3) : ($frame['Uniqueid'] ?? ''));
        $attempt = CallAttempt::whereNotNull('uuid')->where(function ($q) use ($uuid, $frame) {
            $q->where('uuid', $uuid);
            if (! empty($frame['Uniqueid'])) {
                $q->orWhere('uniqueid', $frame['Uniqueid']);
            }
        })->first();
        if (! $attempt) {
            return;
        } // Do not retain ordinary contact-centre events or their PII.
        $digit = null;
        if (($frame['UserEvent'] ?? '') === 'VBDigit' && ($frame['Uniqueid'] ?? '') === $attempt->uniqueid) {
            $snapshot = CampaignVersion::findOrFail($attempt->campaign_version_id)->snapshot['broadcast'];
            foreach ($snapshot['ivr']['graph']['nodes'] ?? [] as $node) {
                if ($node['id'] === ($frame['NodeID'] ?? '') && in_array($node['type'], ['input', 'confirmation', 'condition'], true) && array_key_exists($frame['Digit'] ?? '', $node['branches'])) {
                    $digit = $frame['Digit'];
                }
            }
        }
        unset($frame['Digit']);
        if ($digit !== null) {
            $frame['Digit'] = $digit;
        }
        $allowed = ['Event', 'Response', 'ActionID', 'Uniqueid', 'Linkedid', 'Channel', 'ChannelStateDesc', 'Cause', 'Reason', 'UserEvent', 'AttemptID', 'Response', 'Timestamp', 'NodeID', 'Digit', 'ParentUniqueid'];
        $payload = array_intersect_key($frame, array_flip($allowed));
        ksort($payload);
        $key = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        $event = CallEvent::firstOrCreate(['event_key' => $key], ['call_attempt_id' => $attempt->id, 'event_type' => $frame['UserEvent'] ?? $frame['Event'] ?? 'Response', 'payload' => $payload, 'occurred_at' => now()]);
        $this->process($event);
    }

    public function process(CallEvent $event): void
    {
        DB::transaction(function () use ($event) {
            $engine = app(Engine::class);
            $engine->mutex();
            $event = CallEvent::whereKey($event->id)->lockForUpdate()->firstOrFail();
            if ($event->processed_at) {
                return;
            }
            $a = CallAttempt::whereKey($event->call_attempt_id)->lockForUpdate()->firstOrFail();
            $f = $event->payload;
            $name = $f['Event'] ?? '';
            $user = $f['UserEvent'] ?? '';
            // ChannelId is preassigned to the attempt UUID; a different leg must never release its slot.
            $own = isset($f['Uniqueid']) && in_array($f['Uniqueid'], [$a->uuid, $a->uniqueid], true);
            if ($own) {
                if (! empty($f['Channel'])) {
                    $a->channel = $f['Channel'];
                }
                if (! empty($f['Linkedid'])) {
                    $a->linkedid = $f['Linkedid'];
                }
            }
            if (! $a->ended_at && $own && in_array($name, ['Newchannel', 'Newstate', 'UserEvent'], true)) {
                $a->state = 'active';
                CampaignRecipient::whereKey($a->campaign_recipient_id)->update(['status' => 'active']);
            }
            if ($own && (($name === 'Newstate' && ($f['ChannelStateDesc'] ?? '') === 'Up') || ($name === 'UserEvent' && $user === 'VBPlaybackStart'))) {
                $a->answered_at ??= now();
            }
            if ($own && $name === 'UserEvent' && ($f['AttemptID'] ?? '') === $a->uuid) {
                if ($user === 'VBPlaybackStart') {
                    $a->playback_started_at ??= now();
                    if ($a->ended_at && ! $a->playback_completed_at) {
                        $a->outcome = 'early_hangup';
                    }
                }
                if ($user === 'VBPlaybackComplete') {
                    $a->playback_completed_at ??= now();
                    $a->playback_started_at ??= now();
                    $a->answered_at ??= now();
                    if ($a->ended_at) {
                        $a->outcome = 'completed';
                    }
                }
                if ($user === 'VBPlaybackFailed' && ! $a->playback_completed_at) {
                    $a->outcome = 'playback_failed';
                    $a->playback_failed_at ??= now();
                }
            }
            if ($name === 'UserEvent' && $user === 'VBTransferAnswered' && ($f['AttemptID'] ?? '') === $a->uuid && ($f['ParentUniqueid'] ?? '') === $a->uniqueid && $a->transfer_started_at) {
                $a->transfer_answered_at ??= now();
            }
            if ($own && $name === 'AgentConnect' && $a->transfer_started_at) {
                $a->transfer_answered_at ??= now();
            }
            if ($own && $name === 'UserEvent' && ($f['AttemptID'] ?? '') === $a->uuid) {
                if (in_array($user, ['VBIVRComplete', 'VBTransferStart', 'VBOptOut'], true) || ($user === 'VBDigit' && isset($f['Digit']))) {
                    $a->answered_at ??= now();
                }
                if ($user === 'VBDigit' && isset($f['Digit'])) {
                    DB::table('dtmf_responses')->insertOrIgnore(['call_attempt_id' => $a->id, 'node_id' => $f['NodeID'], 'digit' => $f['Digit'], 'event_key' => $event->event_key, 'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
                }
                if ($user === 'VBIVRComplete') {
                    $a->ivr_completed_at ??= now();
                    if ($a->ended_at) {
                        $a->outcome = 'ivr_completed';
                    }
                }
                if ($user === 'VBTransferStart') {
                    $a->transfer_started_at ??= now();
                }
                if ($user === 'VBTransferAnswered' && $a->transfer_started_at) {
                    $a->transfer_answered_at ??= now();
                }
                if ($user === 'VBOptOut') {
                    $a->opted_out_at ??= now();
                    $a->outcome = 'opt_out';
                    $recipient = CampaignRecipient::findOrFail($a->campaign_recipient_id);
                    $owner = Campaign::findOrFail($recipient->campaign_id)->owner_id;
                    DB::table('suppression_entries')->insertOrIgnore(['phone_e164' => $recipient->phone_e164, 'reason' => 'IVR opt-out', 'created_by' => $owner, 'effective_at' => now()]);
                    AuditLog::firstOrCreate(['action' => 'call.opt_out', 'entity_type' => CallAttempt::class, 'entity_id' => $a->id], ['actor_id' => null, 'details' => ['source' => 'PBX UserEvent'], 'created_at' => now()]);
                }
            }
            $a->save();
            if ($a->playback_completed_at || $a->ivr_completed_at || $a->opted_out_at) {
                CampaignRecipient::whereKey($a->campaign_recipient_id)->where('status', 'pending')->update(['status' => 'terminal']);
            }
            if (! $a->ended_at && $name === 'Hangup' && $own) {
                $a->hangup_cause = (int) ($f['Cause'] ?? 0);
                $a->save();
                $outcome = $a->playback_completed_at ? 'completed' : ($a->outcome === 'playback_failed' ? 'playback_failed' : ($a->answered_at ? 'early_hangup' : match ($a->hangup_cause) {
                    17 => 'busy',18,19 => 'no_answer',1,3,28 => 'invalid_number',default => 'failed'
                }));
                $outcome = $a->opted_out_at ? 'opt_out' : ($a->transfer_answered_at ? 'transfer_success' : ($a->ivr_completed_at ? 'ivr_completed' : $outcome));
                $engine->terminal($a, $outcome);
            } elseif (! $a->ended_at && ($f['ActionID'] ?? '') === $a->action_id && (($name === '' && ($f['Response'] ?? '') === 'Error') || ($name === 'OriginateResponse' && ($f['Response'] ?? '') === 'Failure'))) {
                if (! $a->channel) {
                    $engine->terminal($a, 'originate_failed');
                }
                // If a leg existed, wait for its Hangup or reconcile rather than guessing it ended.
            }
            app(Ledger::class)->settle($a->fresh());
            // AMI Response: Success means accepted, never answered or completed.
            $event->update(['processed_at' => now()]);
        });
    }

    public function replay(): void
    {
        CallEvent::whereNull('processed_at')->whereNotNull('call_attempt_id')->orderBy('id')->chunkById(100, function ($events) {
            foreach ($events as $event) {
                $this->process($event);
            }
        });
    }
}
