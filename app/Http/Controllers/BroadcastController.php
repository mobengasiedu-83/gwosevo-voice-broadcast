<?php

namespace App\Http\Controllers;

use App\Models\AudioFile;
use App\Models\CallAttempt;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\IvrFlow;
use App\Models\TrunkProfile;
use App\Services\Broadcast\AudioLibrary;
use App\Services\Broadcast\Engine;
use App\Services\Campaigns\Workspace;
use App\Services\Foundation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

class BroadcastController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->hasAnyPermission(['campaigns.view', 'audio.manage', 'audio.approve', 'telephony.view', 'reports.view', 'operations.view']), 403);
        $user = $request->user();
        $campaigns = Campaign::when(! $user->hasAnyPermission(['campaigns.approve', 'audit.view', 'reports.view', 'broadcast.operate']), fn ($q) => $q->where('owner_id', $user->id))->latest()->paginate(20);
        $media = $user->hasAnyPermission(['audio.manage', 'audio.approve', 'telephony.view']);

        return view('broadcast.index', ['campaigns' => $campaigns, 'audio' => $media ? AudioFile::whereNotNull('uuid')->latest()->limit(100)->get() : collect(),
            'trunks' => $media ? TrunkProfile::whereNotNull('name')->get() : collect(), 'flows' => IvrFlow::where('status', 'approved')->whereNotNull('deployed_at')->get(), 'gateway' => $user->hasAnyPermission(['telephony.view', 'operations.view']) ? DB::table('broadcast_gateway')->where('id', 1)->first() : null]);
    }

    public function audioLibrary(Request $request)
    {
        abort_unless($request->user()->hasAnyPermission(['audio.manage', 'audio.approve', 'telephony.view']), 403);

        return view('broadcast.audio-library', [
            'audio' => AudioFile::whereNotNull('uuid')->when($request->validate(['status' => 'nullable|in:pending,approved,rejected'])['status'] ?? null, fn ($q, $status) => $q->where('status', $status))->latest()->paginate(25)->withQueryString(),
        ]);
    }

    public function configure(Request $request, Campaign $campaign, Engine $engine)
    {
        $data = $request->validate(['audio_id' => 'required|integer', 'concurrency' => 'required|integer|min:1|max:100', 'cps' => 'required|numeric|gt:0|max:100', 'window_start' => 'required|date_format:H:i', 'window_end' => 'required|date_format:H:i', 'contact_ids' => 'required|string|max:100000', 'caller_id' => 'nullable|string|max:16', 'timezone' => 'nullable|timezone', 'scheduled_at' => 'nullable|date', 'max_attempts' => 'nullable|integer|min:1|max:5', 'retry_delay_seconds' => 'nullable|integer|min:60|max:86400', 'priority' => 'nullable|integer|min:1|max:10', 'ivr_flow_id' => 'nullable|integer']);
        $trunk = app(Workspace::class)->configuredTrunk();
        abort_unless($trunk, 422, 'Set the configured trunk first.');
        $data['trunk_id'] = $trunk->id;
        $data['contact_ids'] = array_map('trim', explode(',', $data['contact_ids']));
        $engine->configure($request->user(), $campaign, $data);

        return back()->with('status', 'Broadcast configuration saved. Submit the campaign from the foundation workspace for separate-user approval.');
    }

    public function control(Request $request, Campaign $campaign, Engine $engine)
    {
        $data = $request->validate(['action' => 'required|in:start,pause,resume,emergency']);
        $engine->control($request->user(), $campaign, $data['action']);

        return back()->with('status', 'Campaign control applied.');
    }

    public function emergency(Request $request, Engine $engine, Foundation $audit)
    {
        Gate::authorize('broadcast.emergency');
        $data = $request->validate(['action' => 'required|in:stop,reset']);
        DB::transaction(function () use ($request, $engine, $audit, $data) {
            $engine->mutex();
            DB::table('broadcast_gateway')->where('id', 1)->update(['emergency_stop' => $data['action'] === 'stop']);
            if ($data['action'] === 'stop') {
                $ids = Campaign::whereIn('status', ['running', 'paused', 'scheduled'])->pluck('id');
                Campaign::whereIn('id', $ids)->update(['status' => 'cancelled', 'stop_requested' => true]);
                CampaignRecipient::whereIn('campaign_id', $ids)->where('status', 'pending')->update(['status' => 'cancelled']);
            }
            $audit->audit($request->user(), 'broadcast.global_'.$data['action'], $request->user());
        });

        return back()->with('status', 'Global broadcast switch updated.');
    }

    public function upload(Request $request, AudioLibrary $library)
    {
        $data = $request->validate(['name' => 'required|string|max:255', 'audio' => 'required|file|max:9765']);
        $library->upload($request->user(), $request->file('audio'), $data['name']);

        return back()->with('status', 'Audio validated and stored for review.');
    }

    public function approveAudio(Request $request, AudioFile $audio, AudioLibrary $library)
    {
        $library->approve($request->user(), $audio);

        return back()->with('status', 'Audio approved.');
    }

    public function rejectAudio(Request $request, AudioFile $audio, AudioLibrary $library)
    {
        $data = $request->validate(['reason' => 'required|string|max:2000']);
        $library->reject($request->user(), $audio, $data['reason']);

        return back()->with('status', 'Audio rejected. Linked draft campaigns remain drafts.');
    }

    public function resubmitAudio(Request $request, AudioFile $audio, AudioLibrary $library)
    {
        $library->resubmit($request->user(), $audio);

        return back()->with('status', 'Audio submitted for review.');
    }

    public function deployAudio(Request $request, AudioFile $audio, AudioLibrary $library)
    {
        $data = $request->validate(['checksum' => 'required|regex:/\A[a-f0-9]{64}\z/', 'evidence' => 'required|string|max:2000']);
        $library->deployed($request->user(), $audio, $data['checksum'], $data['evidence']);

        return back()->with('status', 'PBX deployment evidence recorded.');
    }

    public function downloadAudio(Request $request, AudioFile $audio)
    {
        abort_unless($request->user()->hasAnyPermission(['audio.manage', 'audio.approve', 'telephony.view']), 403);
        abort_unless($audio->storage_key && Storage::disk('local')->exists($audio->storage_key), 404);

        return Storage::disk('local')->download($audio->storage_key, $audio->uuid.'.wav', ['Cache-Control' => 'private, no-store']);
    }

    public function previewAudio(Request $request, AudioFile $audio)
    {
        abort_unless($request->user()->hasAnyPermission(['audio.manage', 'audio.approve', 'telephony.view']), 403);
        abort_unless($audio->storage_key && Storage::disk('local')->exists($audio->storage_key), 404);

        return response()->file(Storage::disk('local')->path($audio->storage_key), [
            'Cache-Control' => 'private, no-store',
            'Content-Disposition' => 'inline; filename="'.$audio->uuid.'.wav"',
            'Content-Type' => 'audio/wav',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function trunk(Request $request, Foundation $audit, Engine $engine)
    {
        Gate::authorize('telephony.manage');
        $data = $request->validate(['name' => 'required|regex:/\A[A-Za-z0-9_.-]{1,80}\z/|unique:trunk_profiles,name', 'caller_id' => 'required|regex:/\A\+?[0-9]{3,15}\z/',
            'max_channels' => 'required|integer|min:1|max:10000', 'reserved_channels' => 'required|integer|min:0|lt:max_channels', 'safe_channels' => 'required|integer|min:1|max:10000', 'max_cps' => 'required|numeric|gt:0|max:1000', 'number_format' => 'required|in:national,international,e164', 'evidence' => 'required|string|max:2000']);
        DB::transaction(function () use ($request, $data, $audit, $engine) {
            $engine->mutex();
            $trunk = TrunkProfile::create($data + ['active' => true, 'verified_by' => $request->user()->id, 'verified_at' => now()]);
            $audit->audit($request->user(), 'trunk.verified', $trunk, ['evidence' => $data['evidence']]);
        });

        return back()->with('status', 'Verified trunk created.');
    }

    public function disableTrunk(TrunkProfile $trunk, Request $request, Engine $engine, Foundation $audit)
    {
        Gate::authorize('telephony.manage');
        DB::transaction(function () use ($trunk, $request, $engine, $audit) {
            $engine->mutex();
            $trunk->update(['active' => false]);
            $audit->audit($request->user(), 'trunk.disabled', $trunk);
        });

        return back()->with('status', 'Trunk disabled for new broadcasts.');
    }

    public function report(Request $request, Campaign $campaign)
    {
        $user = $request->user();
        abort_unless($user->hasAnyPermission(['reports.view', 'broadcast.operate', 'audit.view', 'campaigns.approve']) || ($user->can('campaigns.view') && $campaign->owner_id === $user->id), 403);
        $ids = CampaignRecipient::where('campaign_id', $campaign->id)->pluck('id');
        $query = CallAttempt::whereIn('campaign_recipient_id', $ids);
        $counts = (clone $query)->selectRaw('outcome, count(*) as total')->groupBy('outcome')->pluck('total', 'outcome');
        $terminal = (clone $query)->whereNotNull('ended_at')->whereNotNull('sent_at')->whereNotIn('outcome', ['cancelled_before_send', 'no_consent', 'suppressed', 'contact_changed', 'authorization_failed'])->count();
        $answered = (clone $query)->whereNotNull('ended_at')->whereNotNull('answered_at')->whereNotNull('sent_at')->count();
        $started = (clone $query)->whereNotNull('playback_started_at')->count();
        $completed = (clone $query)->whereNotNull('playback_completed_at')->count();

        return view('broadcast.report', compact('campaign', 'counts', 'terminal', 'answered', 'started', 'completed') + [
            'recipients' => CampaignRecipient::where('campaign_id', $campaign->id)->selectRaw('status,count(*) as total')->groupBy('status')->pluck('total', 'status'),
            'responses' => DB::table('dtmf_responses')->whereIn('call_attempt_id', (clone $query)->select('id'))->selectRaw('node_id,digit,count(*) as total')->groupBy('node_id', 'digit')->get(), 'transfers' => (clone $query)->whereNotNull('transfer_started_at')->count(), 'transfer_success' => (clone $query)->whereNotNull('transfer_answered_at')->count(), 'optouts' => (clone $query)->whereNotNull('opted_out_at')->count(),
            'active' => (clone $query)->whereNull('ended_at')->count(),
            'attempts' => (clone $query)->latest()->paginate(50),
        ]);
    }
}
