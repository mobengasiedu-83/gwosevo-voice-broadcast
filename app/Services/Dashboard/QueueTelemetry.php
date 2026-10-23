<?php

namespace App\Services\Dashboard;

use Illuminate\Support\Facades\DB;

class QueueTelemetry
{
    public function snapshot(array $frames, array $deltas = []): void
    {
        DB::transaction(function () use ($frames, $deltas) {
            DB::table('dashboard_queue_members')->delete();
            DB::table('dashboard_queues')->delete();
            foreach ($frames as $f) {
                $this->event($f);
            }
            foreach ($deltas as $delta) {
                $this->event($delta);
            }
            $this->state('healthy');
        });
    }

    public function state(string $status): void
    {
        DB::table('dashboard_telemetry')->updateOrInsert(['source' => 'queues'], ['status' => $status, 'observed_at' => now(), 'payload' => null]);
    }

    public function event(array $f): void
    {
        $queue = $f['Queue'] ?? '';
        if (! preg_match('/\A[A-Za-z0-9_.-]{1,80}\z/', $queue)) {
            return;
        }
        $event = $f['Event'] ?? '';
        if ($event === 'QueueParams') {
            $level = filter_var($f['ServicelevelPerf'] ?? null, FILTER_VALIDATE_FLOAT);
            DB::table('dashboard_queues')->updateOrInsert(['queue' => $queue], ['waiting' => $this->count($f['Calls'] ?? 0), 'completed' => $this->count($f['Completed'] ?? 0), 'abandoned' => $this->count($f['Abandoned'] ?? 0), 'service_level' => $level !== false && $level >= 0 && $level <= 100 ? $level : null, 'observed_at' => now()]);
        }
        if (in_array($event, ['QueueMember', 'QueueMemberAdded', 'QueueMemberStatus', 'QueueMemberPause'], true)) {
            $interface = $f['Interface'] ?? $f['Location'] ?? '';
            if (! preg_match('/\A[A-Za-z0-9_.@+\/-]{1,120}\z/', $interface)) {
                return;
            }
            $data = ['observed_at' => now()];
            foreach (['Status' => 'device_status', 'Paused' => 'paused', 'InCall' => 'in_call', 'CallsTaken' => 'calls_taken'] as $key => $column) {
                if (isset($f[$key])) {
                    $data[$column] = $this->count($f[$key]);
                }
            }
            if (isset($f['LastCall'])) {
                $data['last_call_epoch'] = max(0, (int) $f['LastCall']);
            }
            if (isset($f['Wrapuptime'])) {
                $data['wrapup_seconds'] = $this->count($f['Wrapuptime']);
            }
            if (isset($f['MemberName'])) {
                $f['Name'] = $f['MemberName'];
            }
            if (isset($f['Name'])) {
                $data['name'] = mb_substr(preg_replace('/[\x00-\x1f]/', '', $f['Name']), 0, 120);
            }
            DB::table('dashboard_queue_members')->updateOrInsert(['queue' => $queue, 'interface' => $interface], $data);
        }
        if ($event === 'QueueMemberRemoved') {
            DB::table('dashboard_queue_members')->where('queue', $queue)->where('interface', $f['Interface'] ?? $f['Location'] ?? '')->delete();
        }
        if (in_array($event, ['QueueCallerJoin', 'QueueCallerLeave'], true) && isset($f['Count'])) {
            DB::table('dashboard_queues')->where('queue', $queue)->update(['waiting' => $this->count($f['Count']), 'observed_at' => now()]);
        }
    }

    private function count($value): int
    {
        return max(0, min(1000000, (int) $value));
    }
}
