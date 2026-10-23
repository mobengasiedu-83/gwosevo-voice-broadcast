<?php

namespace App\Http\Controllers;

use App\Models\AudioFile;
use App\Models\Campaign;
use App\Models\CampaignRun;
use App\Models\CampaignTemplate;
use App\Models\ContactGroup;
use App\Models\IvrFlow;
use App\Services\Broadcast\Engine;
use App\Services\Broadcast\Readiness;
use App\Services\Campaigns\Workspace;
use App\Services\Foundation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CampaignController extends Controller
{
    public function index(Request $r, Workspace $w)
    {
        Gate::authorize('campaigns.view');
        $f = $r->validate(['q' => 'nullable|string|max:100', 'status' => 'nullable|in:draft,ready,pending_approval,approved,scheduled,running,paused,completed,cancelled,failed', 'type' => 'nullable|in:one_way,interactive', 'archived' => 'nullable|in:1', 'view' => 'nullable|in:all,approvals,scheduled,active,allocation', 'month' => 'nullable|date_format:Y-m']);
        $query = $w->query($r->user())->when(empty($f['archived']), fn ($q) => $q->whereNull('archived_at'), fn ($q) => $q->whereNotNull('archived_at'));
        foreach (['status', 'type'] as $key) {
            if (! empty($f[$key])) {
                $query->where($key, $f[$key]);
            }
        }
        if (! empty($f['q'])) {
            $query->where('name', 'like', '%'.addcslashes($f['q'], '%_\\').'%');
        }
        $view = $f['view'] ?? 'all';
        if ($view === 'approvals') {
            $query->where('status', 'pending_approval');
        }
        if ($view === 'scheduled') {
            $query->where('status', 'scheduled');
        }
        if (in_array($view, ['active', 'allocation'])) {
            $query->whereIn('status', ['running', 'paused', 'scheduled']);
        }
        $calendar = [];
        if ($view === 'scheduled') {
            $month = CarbonImmutable::createFromFormat('!Y-m', $f['month'] ?? now('Africa/Accra')->format('Y-m'), 'Africa/Accra');
            $calendar = (clone $query)->where('scheduled_at', '>=', $month->utc())->where('scheduled_at', '<', $month->addMonth()->utc())->orderBy('scheduled_at')->limit(500)->get()->groupBy(fn ($c) => $c->scheduled_at->timezone('Africa/Accra')->format('Y-m-d'));
        }
        $campaigns = $query->withCount('versions')->latest()->paginate(25)->withQueryString();
        $allocated = DB::table('channel_reservations')->whereIn('campaign_id', $campaigns->pluck('id'))->whereNull('released_at')->selectRaw('campaign_id,count(*) as total')->groupBy('campaign_id')->pluck('total', 'campaign_id');

        return response()->view('campaigns.index', compact('campaigns', 'f', 'view', 'calendar', 'allocated'))->header('Cache-Control', 'private, no-store');
    }

    private function data(Request $r): array
    {
        return $r->validate(['name' => 'required|string|max:255', 'purpose' => 'required|string|max:255', 'type' => 'required|in:one_way,interactive', 'dialing_strategy' => 'required|in:direct', 'timezone' => 'required|timezone', 'audio_id' => 'nullable|integer|exists:audio_files,id', 'caller_id' => 'nullable|regex:/\A\+?[0-9]{3,15}\z/', 'ivr_flow_id' => 'nullable|integer|exists:ivr_flows,id', 'source_group_id' => 'nullable|integer|exists:contact_groups,id', 'contact_ids' => 'nullable|string|max:100000', 'scheduled_at' => 'nullable|date', 'window_start' => 'required|date_format:H:i', 'window_end' => 'required|date_format:H:i|after:window_start', 'concurrency' => 'required|integer|min:1|max:100', 'cps' => 'required|numeric|gt:0|max:100', 'max_attempts' => 'required|integer|min:1|max:5', 'retry_delay_seconds' => 'required|integer|min:60|max:86400', 'priority' => 'required|integer|min:1|max:10']);
    }

    public function edit(Request $r, Workspace $w, ?Campaign $campaign = null)
    {
        Gate::authorize('campaigns.manage');
        if ($campaign) {
            $w->own($r->user(), $campaign);
            abort_if($campaign->archived_at || ! in_array($campaign->status, ['draft', 'ready'], true), 422, 'Only draft or ready campaigns can be edited. Duplicate a completed campaign for a new run.');
        }

        return view('campaigns.edit', ['campaign' => $campaign, 'audio' => AudioFile::whereNotNull('uuid')->latest('id')->get(), 'trunk' => $w->configuredTrunk(), 'flows' => IvrFlow::where('status', 'approved')->whereNotNull('deployed_at')->get(), 'groups' => ContactGroup::where('owner_id', $r->user()->id)->where('active', true)->get()]);
    }

    public function save(Request $r, Workspace $w, ?Campaign $campaign = null)
    {
        $c = $w->save($r->user(), $campaign, $this->data($r));

        return redirect()->route('campaigns.show', $c)->with('status', $c->status === 'ready' ? 'Campaign is ready. You can start it now or wait for its scheduled time.' : 'Campaign saved as a draft. It becomes ready when the selected audio is approved.');
    }

    public function show(Request $r, Campaign $campaign, Workspace $w)
    {
        $w->access($r->user(), $campaign);
        $readiness = null;
        $snapshot = null;
        try {
            $snapshot = app(Readiness::class)->snapshot($campaign);
        } catch (ValidationException $e) {
            $readiness = collect($e->errors())->flatten()->all();
        } catch (ModelNotFoundException $e) {
            $readiness = ['A configured asset no longer exists.'];
        }
        $eligibility = $w->eligibility($campaign);

        return response()->view('campaigns.show', ['campaign' => $campaign, 'readiness' => $readiness, 'snapshot' => $snapshot, 'eligibility' => $eligibility, 'versions' => $campaign->versions()->orderByDesc('version')->get(), 'reviews' => DB::table('campaign_reviews')->where('campaign_id', $campaign->id)->orderByDesc('id')->get(), 'run' => CampaignRun::where('campaign_id', $campaign->id)->first(), 'templates' => $w->templates($r->user())->where('owner_id', $r->user()->id)->latest('id')->limit(100)->get()])->header('Cache-Control', 'private, no-store');
    }

    public function review(Request $r, Campaign $campaign, Workspace $w, Foundation $f)
    {
        $w->access($r->user(), $campaign);
        $d = $r->validate(['decision' => 'required|in:submit,approve,reject', 'reason' => 'required|string|max:2000']);
        if ($d['decision'] === 'submit') {
            app(Readiness::class)->snapshot($campaign);
        }
        $f->transition($r->user(), $campaign, $d['decision'], $d['reason']);

        return back()->with('status', 'Review recorded.');
    }

    public function duplicate(Request $r, Campaign $campaign, Workspace $w)
    {
        $d = $r->validate(['name' => 'required|string|max:255']);
        $c = $w->duplicate($r->user(), $campaign, $d['name']);

        return redirect()->route('campaigns.edit', $c)->with('status', 'New draft created. A fresh approval is required before this execution can start.');
    }

    public function archive(Request $r, Campaign $campaign, Workspace $w)
    {
        $d = $r->validate(['action' => 'required|in:archive,restore']);
        $w->archive($r->user(), $campaign, $d['action'] === 'restore');

        return back()->with('status', 'Archive status updated.');
    }

    public function control(Request $r, Campaign $campaign, Workspace $w, Engine $e)
    {
        $w->access($r->user(), $campaign);
        $d = $r->validate(['action' => 'required|in:start,pause,resume,cancel', 'reason' => 'required|string|max:1000']);
        DB::transaction(function () use ($r, $campaign, $e, $d) {
            $e->mutex();
            $locked = Campaign::whereKey($campaign->id)->lockForUpdate()->firstOrFail();
            $mayOperate = $r->user()->can('broadcast.operate') || ($locked->owner_id === $r->user()->id && $r->user()->can('campaigns.manage'));
            if ($d['action'] === 'cancel' && in_array($locked->status, ['draft', 'ready', 'approved'])) {
                Gate::authorize('broadcast.emergency');
                $locked->update(['status' => 'cancelled', 'stop_requested' => true]);
            } else {
                abort_unless($d['action'] === 'cancel' || $mayOperate, 403);
                $e->control($r->user(), $locked, $d['action'] === 'cancel' ? 'emergency' : $d['action'], $mayOperate);
            }
            app(Foundation::class)->audit($r->user(), 'campaign.control_reason', $locked, $d);
        });

        return back()->with('status', 'Control recorded. Call termination requires gateway confirmation.');
    }

    public function templates(Request $r, Workspace $w)
    {
        Gate::authorize('campaigns.view');

        return view('campaigns.templates', ['templates' => $w->templates($r->user())->latest('id')->paginate(25)]);
    }

    public function template(Request $r, Campaign $campaign, Workspace $w)
    {
        $d = $r->validate(['name' => 'required|string|max:255', 'parent_id' => 'nullable|integer|exists:campaign_templates,id']);
        $w->template($r->user(), $campaign, $d['name'], empty($d['parent_id']) ? null : CampaignTemplate::findOrFail($d['parent_id']));

        return redirect()->route('campaigns.templates')->with('status', 'Immutable template version created. Templates do not carry approval, audience or a launch schedule.');
    }

    public function instantiate(Request $r, CampaignTemplate $template, Workspace $w)
    {
        $d = $r->validate(['name' => 'required|string|max:255']);
        $c = $w->instantiate($r->user(), $template, $d['name']);

        return redirect()->route('campaigns.edit', $c);
    }

    public function runs(Request $r, Workspace $w)
    {
        Gate::authorize('campaigns.view');
        $runs = CampaignRun::join('campaigns as c', 'c.id', '=', 'campaign_runs.campaign_id')->whereIn('c.id', $w->query($r->user())->select('id'))->select('campaign_runs.*', 'c.name', 'c.status')->latest('campaign_runs.id')->paginate(25);
        $ids = $runs->pluck('campaign_id');
        $metrics = DB::table('call_attempts as a')->join('campaign_recipients as r', 'r.id', '=', 'a.campaign_recipient_id')->whereIn('r.campaign_id', $ids)->selectRaw('r.campaign_id,count(*) as attempts,SUM(CASE WHEN a.sent_at IS NOT NULL THEN 1 ELSE 0 END) as sent,SUM(CASE WHEN a.answered_at IS NOT NULL THEN 1 ELSE 0 END) as answered,SUM(CASE WHEN a.playback_completed_at IS NOT NULL THEN 1 ELSE 0 END) as played,SUM(CASE WHEN a.state = ? THEN 1 ELSE 0 END) as uncertain', ['unknown'])->groupBy('r.campaign_id')->get()->keyBy('campaign_id');

        return response()->view('campaigns.runs', compact('runs', 'metrics') + ['error' => null])->header('Cache-Control', 'private, no-store');
    }
}
