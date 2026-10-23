<?php

namespace App\Services\Dashboard;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class Metrics
{
    public function __construct(private Scope $scope) {}

    public function overview(User $user, array $filters): array
    {
        $zone = $filters['timezone'];
        $date = CarbonImmutable::parse($filters['date'], $zone);
        $from = $date->startOfDay()->utc();
        $to = $date->addDay()->startOfDay()->utc();
        $query = $this->scope->attempts($user)->when($filters['campaign_id'] ?? null, fn ($q, $id) => $q->where('c.id', $id));
        $day = (clone $query)->where('a.sent_at', '>=', $from)->where('a.sent_at', '<', $to);
        $terminal = (clone $day)->whereNotNull('a.ended_at')->whereNotIn('a.outcome', ['cancelled_before_send', 'no_consent', 'suppressed', 'contact_changed', 'authorization_failed'])->count();
        $answered = (clone $day)->whereNotNull('a.ended_at')->whereNotNull('a.answered_at')->count();
        // Aggregate in the database; never stream every call into a refreshing dashboard.
        $hourQuery = clone $day;
        for ($i = 0; $i < 24; $i++) {
            $hourQuery->selectRaw('SUM(CASE WHEN a.sent_at >= ? AND a.sent_at < ? THEN 1 ELSE 0 END) as h'.$i, [$date->setTime($i, 0)->utc(), $date->setTime($i + 1, 0)->utc()]);
        }
        $buckets = $hourQuery->first();
        $hours = [];
        for ($i = 0; $i < 24; $i++) {
            $hours[] = ['hour' => sprintf('%02d:00', $i), 'count' => (int) ($buckets->{'h'.$i} ?? 0)];
        }
        $campaigns = $this->scope->campaigns($user)->when($filters['campaign_id'] ?? null, fn ($q, $id) => $q->whereKey($id))->whereIn('status', ['running', 'paused', 'scheduled'])->orderBy('scheduled_at')->limit(12)->get();
        $progress = DB::table('campaign_recipients')->whereIn('campaign_id', $campaigns->pluck('id'))->selectRaw('campaign_id, count(*) as total, SUM(CASE WHEN status IN (\'terminal\',\'excluded\',\'cancelled\') THEN 1 ELSE 0 END) as finished')->groupBy('campaign_id')->get()->keyBy('campaign_id');

        return [
            'active_campaigns' => $this->scope->campaigns($user)->when($filters['campaign_id'] ?? null, fn ($q, $id) => $q->whereKey($id))->where('status', 'running')->count(),
            'active_calls' => (clone $query)->whereNull('a.ended_at')->whereIn('a.state', ['sending', 'active'])->count(),
            'uncertain' => (clone $query)->whereNull('a.ended_at')->where('a.state', 'unknown')->count(),
            'calls_today' => (clone $day)->count(), 'terminal' => $terminal, 'answered' => $answered, 'answer_rate' => $terminal ? round(100 * $answered / $terminal, 1) : null,
            'completed' => (clone $day)->whereNotNull('a.playback_completed_at')->count(), 'hours' => $hours,
            'campaigns' => $campaigns, 'progress' => $progress,
            'scheduled' => $this->scope->campaigns($user)->where('status', 'scheduled')->orderBy('scheduled_at')->limit(6)->get(),
            'outcomes' => (clone $query)->whereNotNull('a.ended_at')->orderByDesc('a.ended_at')->limit(8)->get(['a.uuid', 'a.outcome', 'a.ended_at', 'c.name as campaign_name', 'c.id as campaign_id']),
            'pending_approvals' => $this->scope->campaigns($user)->where('status', 'pending_approval')->count(),
        ];
    }

    public function live(User $user, array $filters): array
    {
        $query = $this->scope->attempts($user)->whereNull('a.ended_at')->when($filters['campaign_id'] ?? null, fn ($q, $id) => $q->where('c.id', $id))->when($filters['state'] ?? null, fn ($q, $state) => $q->where('a.state', $state))->when($filters['q'] ?? null, fn ($q, $term) => $q->where(fn ($sub) => $sub->where('a.uuid', 'like', '%'.$term.'%')->orWhere('c.name', 'like', '%'.$term.'%')));

        $calls = $query->orderByDesc('a.created_at')->select('a.id', 'a.uuid', 'a.action_id', 'a.uniqueid', 'a.linkedid', 'a.state', 'a.sent_at', 'a.created_at', 'a.answered_at', 'a.playback_started_at', 'a.transfer_started_at', 'c.id as campaign_id', 'c.name as campaign_name', 'c.type', 'c.status as campaign_status')->paginate(25)->withQueryString();
        $bridges = DB::table('call_events')->whereIn('call_attempt_id', $calls->pluck('id'))->whereIn('event_type', ['BridgeEnter', 'BridgeLeave'])->orderByDesc('id')->get(['call_attempt_id', 'event_type'])->unique('call_attempt_id')->keyBy('call_attempt_id');
        foreach ($calls as $call) {
            $call->bridged = ($bridges[$call->id]->event_type ?? '') === 'BridgeEnter';
        }

        return ['calls' => $calls];
    }
}
