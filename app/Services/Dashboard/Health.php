<?php

namespace App\Services\Dashboard;

use App\Models\AudioFile;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class Health
{
    public function gateway(): array
    {
        $g = DB::table('broadcast_gateway')->where('id', 1)->first();
        $fresh = $g && $g->healthy && $this->fresh($g->heartbeat_at, 15) && $this->fresh($g->snapshot_at, 15);

        return ['healthy' => (bool) $fresh, 'heartbeat' => $g->heartbeat_at ?? null, 'snapshot' => $g->snapshot_at ?? null, 'emergency_stop' => (bool) ($g->emergency_stop ?? false), 'enabled' => (bool) config('voice_broadcast.enabled')];
    }

    public function fresh(?string $at, int $seconds): bool
    {
        return $at && CarbonImmutable::parse($at, 'UTC')->betweenIncluded(now()->subSeconds($seconds), now()->addSeconds(5));
    }

    public function wallboard(array $filters): array
    {
        $source = DB::table('dashboard_telemetry')->where('source', 'queues')->first();
        $fresh = $source && $source->status === 'healthy' && $this->fresh($source->observed_at, config('dashboard.queue_stale_seconds'));
        $members = DB::table('dashboard_queue_members')->when($filters['queue'] ?? null, fn ($q, $queue) => $q->where('queue', $queue))->orderBy('queue')->orderBy('name')->get();
        $totals = ['ready' => 0, 'busy' => 0, 'paused' => 0, 'offline' => 0, 'wrapup' => 0];
        foreach ($members as $m) {
            $m->display_state = $m->paused ? 'paused' : ($m->in_call || in_array($m->device_status, [2, 3, 6, 7, 8]) ? 'busy' : ((int) $m->device_status === 1 ? ($m->last_call_epoch && $m->wrapup_seconds && $m->last_call_epoch + $m->wrapup_seconds > now()->timestamp ? 'wrapup' : 'ready') : 'offline'));
            $totals[$m->display_state]++;
        }

        return ['source' => $source, 'fresh' => $fresh, 'members' => new LengthAwarePaginator($members->forPage((int) ($filters['page'] ?? 1), 50), $members->count(), 50, (int) ($filters['page'] ?? 1), ['path' => request()->url(), 'query' => request()->query()]), 'totals' => $totals, 'queues' => DB::table('dashboard_queues')->when($filters['queue'] ?? null, fn ($q, $queue) => $q->where('queue', $queue))->orderBy('queue')->get()];
    }

    public function system(): array
    {
        $probes = DB::table('dashboard_telemetry')->get()->keyBy('source');
        $gateway = $this->gateway();
        $trunks = DB::table('trunk_profiles')->whereNotNull('name')->get();
        foreach ($trunks as $t) {
            $t->reserved = DB::table('channel_reservations')->where('trunk_profile_id', $t->id)->whereNull('released_at')->count();
            $t->allocation = max(0, min($t->max_channels - $t->reserved_channels, $t->safe_channels));
        }

        return ['gateway' => $gateway, 'probes' => $probes, 'trunks' => $trunks,
            'uncertain' => DB::table('call_attempts')->where('state', 'unknown')->count(),
            'event_backlog' => DB::table('call_events')->whereNull('processed_at')->whereNotNull('call_attempt_id')->count(),
            'oldest_event' => DB::table('call_events')->whereNull('processed_at')->whereNotNull('call_attempt_id')->min('created_at'),
            'oldest_uncertain' => DB::table('call_attempts')->where('state', 'unknown')->min('updated_at'),
            'reserved' => DB::table('channel_reservations')->whereNull('released_at')->count(),
            'failed_jobs' => DB::table('failed_jobs')->count(),
        ];
    }

    public function probe(string $source, string $status, array $payload = []): void
    {
        DB::table('dashboard_telemetry')->updateOrInsert(['source' => $source], ['status' => $status, 'observed_at' => now(), 'payload' => json_encode($payload, JSON_THROW_ON_ERROR)]);
    }

    public function storage(): void
    {
        $missing = 0;
        foreach (AudioFile::where('status', 'approved')->get() as $audio) {
            if (! $audio->storage_key || ! Storage::disk('local')->exists($audio->storage_key)) {
                $missing++;
            }
        }
        $bytes = @disk_free_space(storage_path());
        $this->probe('storage', $bytes === false ? 'unavailable' : ($bytes < config('dashboard.low_disk_bytes') || $missing ? 'degraded' : 'healthy'), ['free_bytes' => $bytes === false ? null : $bytes, 'missing_audio' => $missing]);
    }
}
