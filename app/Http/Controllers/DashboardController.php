<?php

namespace App\Http\Controllers;

use App\Models\CallAttempt;
use App\Models\CampaignRecipient;
use App\Services\Broadcast\Engine;
use App\Services\Dashboard\Health;
use App\Services\Dashboard\Metrics;
use App\Services\Dashboard\Scope;
use App\Services\Foundation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class DashboardController extends Controller
{
    public function show(Request $request, Scope $scope, Metrics $metrics, Health $health)
    {
        $tab = $request->route('tab') ?? 'overview';
        Gate::authorize('dashboard.view');
        if ($tab !== 'overview') {
            Gate::authorize('dashboard.'.$tab);
        }
        $filters = $request->validate(['date' => 'nullable|date_format:Y-m-d', 'timezone' => 'nullable|timezone', 'campaign_id' => 'nullable|integer', 'state' => 'nullable|in:authorizing,ready,sending,active,unknown', 'queue' => 'nullable|regex:/\A[A-Za-z0-9_.-]{1,80}\z/', 'q' => 'nullable|string|max:80', 'page' => 'nullable|integer|min:1|max:100000']);
        $filters['timezone'] = $filters['timezone'] ?? 'Africa/Accra';
        $filters['date'] = $filters['date'] ?? now($filters['timezone'])->toDateString();
        if (! empty($filters['campaign_id'])) {
            abort_unless($scope->campaigns($request->user())->whereKey($filters['campaign_id'])->exists(), 403);
        }
        $data = match ($tab) {
            'overview' => $metrics->overview($request->user(), $filters),'live' => $metrics->live($request->user(), $filters),'wallboard' => $health->wallboard($filters),'health' => $health->system(),default => abort(404)
        };
        $data += ['tab' => $tab, 'filters' => $filters, 'generated_at' => now()->toIso8601String(), 'gateway' => $health->gateway(), 'campaign_options' => $scope->campaigns($request->user())->orderBy('name')->limit(500)->get(['id', 'name'])];
        $data['alerts'] = $this->alerts($data, $request);
        if ($request->expectsJson()) {
            return response()->json(['html' => view('dashboard.panels.'.$tab, $data)->render(), 'generated_at' => $data['generated_at']])->header('Cache-Control', 'private, no-store');
        }

        return response()->view('dashboard.index', $data)->header('Cache-Control', 'private, no-store');
    }

    public function detail(Request $request, CallAttempt $attempt, Scope $scope, Health $health)
    {
        Gate::authorize('dashboard.view');
        Gate::authorize('dashboard.live');
        $selected = $scope->attempts($request->user())->where('a.id', $attempt->id)->first(['a.uuid', 'a.action_id', 'a.uniqueid', 'a.linkedid', 'a.state', 'a.outcome', 'a.sent_at', 'a.answered_at', 'a.ended_at', 'a.hangup_cause', 'c.name as campaign_name', 'c.id as campaign_id']);
        abort_unless($selected, 403);

        return response()->view('dashboard.detail', ['selected' => $selected, 'events' => DB::table('call_events')->where('call_attempt_id', $attempt->id)->orderBy('id')->paginate(50), 'tab' => 'live', 'filters' => ['q' => ''], 'gateway' => $health->gateway(), 'alerts' => [], 'generated_at' => now()->toIso8601String()])->header('Cache-Control', 'private, no-store');
    }

    private function alerts(array $data, Request $request): array
    {
        $alerts = [];
        if (! $data['gateway']['enabled']) {
            $alerts[] = ['level' => 'notice', 'title' => 'Live dispatch is disabled', 'detail' => 'Campaign configuration remains available. Enable dispatch only after capacity and PBX acceptance.'];
        }
        if (! $data['gateway']['healthy']) {
            $alerts[] = ['level' => 'warning', 'title' => 'PBX telemetry is offline or stale', 'detail' => 'Live call states may be incomplete. The gateway requires a fresh heartbeat and channel snapshot.'];
        }
        if ($data['gateway']['emergency_stop']) {
            $alerts[] = ['level' => 'danger', 'title' => 'Emergency stop is active', 'detail' => 'Broadcast origination is blocked.'];
        }
        if (($data['uncertain'] ?? 0) > 0) {
            $alerts[] = ['level' => 'danger', 'title' => $data['uncertain'].' calls require reconciliation', 'detail' => 'Uncertain attempts are excluded from live channel counts and cannot be retried blindly.'];
        }

        return $alerts;
    }

    public function stop(Request $request, CallAttempt $attempt, Scope $scope, Engine $engine, Foundation $audit)
    {
        Gate::authorize('dashboard.live');
        Gate::authorize('broadcast.emergency');
        $data = $request->validate(['reason' => 'required|string|max:1000']);
        DB::transaction(function () use ($request, $attempt, $scope, $engine, $audit, $data) {
            $engine->mutex();
            $attempt = CallAttempt::whereKey($attempt->id)->lockForUpdate()->firstOrFail();
            $recipient = CampaignRecipient::findOrFail($attempt->campaign_recipient_id);
            abort_unless($scope->campaigns($request->user())->whereKey($recipient->campaign_id)->exists(), 403);
            abort_if($attempt->ended_at, 409, 'Call has already ended.');
            if ($attempt->stop_requested_at) {
                return;
            }
            $attempt->update(['stop_requested_at' => now()]);
            $audit->audit($request->user(), 'call.stop_requested', $attempt, ['reason' => $data['reason']]);
        });

        return back()->with('status', 'Stop requested. The gateway will act only on the matching broadcast channel; completion requires a confirmed hangup.');
    }
}
