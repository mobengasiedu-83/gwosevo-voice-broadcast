<?php

namespace App\Services\Broadcast;

use App\Models\CallAttempt;
use App\Models\User;
use App\Services\Foundation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

class Reconciliation
{
    public function resolve(User $actor, array $record, string $evidence): void
    {
        Gate::forUser($actor)->authorize('telephony.manage');
        Validator::make($record, ['attempt_uuid' => 'required|uuid', 'uniqueid' => 'required|string|max:255', 'ended_at' => 'required|date|before_or_equal:now', 'answered_at' => 'nullable|date|before_or_equal:ended_at', 'disposition' => 'required|in:ANSWERED,BUSY,NO ANSWER,FAILED', 'billable_seconds' => 'required|integer|min:0|max:86400'])->validate();
        Validator::make(compact('evidence'), ['evidence' => 'required|string|max:1000'])->validate();
        DB::transaction(function () use ($actor, $record, $evidence) {
            $engine = app(Engine::class);
            $g = $engine->mutex();
            $ready = app(Readiness::class);
            if (! $g->healthy || ! $g->snapshot_at || strtotime($g->snapshot_at) < now()->getTimestamp() - 10 || CarbonImmutable::parse($g->snapshot_at)->gt(now())) {
                $ready->fail('A fresh, complete gateway channel snapshot is required.');
            }
            $a = CallAttempt::where('uuid', $record['attempt_uuid'])->lockForUpdate()->firstOrFail();
            if ($a->ended_at) {
                return;
            }
            if ($a->state !== 'unknown' || $record['uniqueid'] !== $a->uniqueid) {
                $ready->fail('Only uncertain attempts with matching CDR Uniqueid can be reconciled.');
            }
            $ended = CarbonImmutable::parse($record['ended_at']);
            if ($ended->gt(CarbonImmutable::parse($g->snapshot_at)) || $ended->lt($a->sent_at ?? $a->created_at)) {
                $ready->fail('CDR termination must fall after attempt creation and before the channel snapshot.');
            }
            foreach (json_decode($g->channels ?? '[]', true, 512, JSON_THROW_ON_ERROR) as $channel) {
                if (in_array($channel['uniqueid'], [$a->uuid, $a->uniqueid], true) || in_array($channel['linkedid'], array_filter([$a->uuid, $a->uniqueid, $a->linkedid]), true)) {
                    $ready->fail('The attempt or a linked leg is still active.');
                }
            }
            $outcome = $a->playback_completed_at ? 'completed' : match ($record['disposition']) {
                'ANSWERED' => 'answered_unknown','BUSY' => 'busy','NO ANSWER' => 'no_answer',default => 'failed'
            };
            $outcome = $a->opted_out_at ? 'opt_out' : ($a->transfer_answered_at ? 'transfer_success' : ($a->ivr_completed_at ? 'ivr_completed' : $outcome));
            $a->billable_seconds = $record['billable_seconds'];
            if ($record['disposition'] === 'ANSWERED' && ! empty($record['answered_at'])) {
                $answer = CarbonImmutable::parse($record['answered_at']);
                if ($answer->lt($a->sent_at ?? $a->created_at)) {
                    $ready->fail('CDR answer cannot precede dispatch.');
                }
                $a->answered_at = $answer;
            }
            $a->save();
            $engine->terminal($a, $outcome, $ended);
            app(Foundation::class)->audit($actor, 'call.reconciled', $a, ['evidence' => $evidence, 'disposition' => $record['disposition']]);
        });
    }
}
