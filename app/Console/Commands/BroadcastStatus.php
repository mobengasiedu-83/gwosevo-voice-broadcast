<?php

namespace App\Console\Commands;

use App\Models\AudioFile;
use App\Models\CallAttempt;
use App\Models\TrunkProfile;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BroadcastStatus extends Command
{
    protected $signature = 'broadcast:status {--json : Emit machine-readable operational status}';

    protected $description = 'Report gateway freshness, reservations, uncertain calls and configured dispatch limits';

    public function handle(): int
    {
        $g = DB::table('broadcast_gateway')->where('id', 1)->first();
        $healthy = $g && $g->healthy && $g->heartbeat_at && strtotime($g->heartbeat_at) >= time() - 15 && $g->snapshot_at && strtotime($g->snapshot_at) >= time() - 15;
        $status = [
            'dispatch_enabled' => (bool) config('voice_broadcast.enabled'), 'gateway_healthy' => (bool) $healthy,
            'emergency_stop' => (bool) ($g->emergency_stop ?? true), 'global_channels' => (int) config('voice_broadcast.global_channels'),
            'global_cps' => (float) config('voice_broadcast.global_cps'), 'reserved_channels' => DB::table('channel_reservations')->whereNull('released_at')->count(),
            'uncertain_attempts' => CallAttempt::where('state', 'unknown')->count(),
            'active_verified_trunks' => TrunkProfile::where('active', true)->whereNotNull('verified_at')->count(),
            'approved_deployed_audio' => AudioFile::where('status', 'approved')->whereNotNull('deployed_at')->count(),
        ];
        if ($this->option('json')) {
            $this->line(json_encode($status, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        } else {
            foreach ($status as $key => $value) {
                $this->line($key.': '.(is_bool($value) ? ($value ? 'yes' : 'no') : $value));
            }
        }

        return $healthy && $status['dispatch_enabled'] && ! $status['emergency_stop'] && ! $status['uncertain_attempts'] && $status['global_channels'] > 0 && $status['global_cps'] > 0 && $status['active_verified_trunks'] > 0 && $status['approved_deployed_audio'] > 0 ? self::SUCCESS : self::FAILURE;
    }
}
