<?php

namespace App\Http\Controllers;

use App\Models\AudioFile;
use App\Models\IvrFlow;
use App\Models\TransferRoute;
use App\Models\TrunkProfile;
use App\Services\Broadcast\Engine;
use App\Services\Broadcast\IvrCompiler;
use App\Services\Broadcast\IvrDesigner;
use App\Services\Broadcast\Readiness;
use App\Services\Foundation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class InteractiveController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->hasAnyPermission(['campaigns.manage', 'campaigns.approve', 'telephony.manage']), 403);

        return view('broadcast.interactive', ['telephonyPage' => false, 'flows' => IvrFlow::whereNotNull('uuid')->latest()->paginate(20), 'audio' => AudioFile::where('status', 'approved')->whereNotNull('deployed_at')->get(), 'routes' => TransferRoute::all(), 'trunks' => $request->user()->clientMembership()->exists() ? collect() : TrunkProfile::whereNotNull('name')->get()]);
    }

    public function telephony()
    {
        Gate::authorize('telephony.manage');

        return view('broadcast.interactive', [
            'telephonyPage' => true,
            'trunks' => TrunkProfile::whereNotNull('name')->get(),
            'routes' => TransferRoute::all(),
        ]);
    }

    public function callerIds(Request $request, TrunkProfile $trunk, Engine $engine, Foundation $audit)
    {
        Gate::authorize('telephony.manage');
        $data = $request->validate(['caller_id' => 'required|regex:/\A\+?[0-9]{3,15}\z/', 'allowed_caller_ids' => 'nullable|string|max:2000', 'evidence' => 'required|string|max:2000']);
        $ids = array_values(array_filter(array_map('trim', explode(',', $data['allowed_caller_ids'] ?? ''))));
        foreach ($ids as $id) {
            if (! preg_match('/\A\+?[0-9]{3,15}\z/', $id)) {
                app(Readiness::class)->fail('Caller IDs must contain digits with an optional leading +.');
            }
        }
        DB::transaction(function () use ($trunk, $data, $ids, $request, $engine, $audit) {
            $engine->mutex();
            $trunk->refresh();
            $before = ['caller_id' => $trunk->caller_id, 'allowed_caller_ids' => $trunk->allowed_caller_ids];
            $trunk->update(['caller_id' => $data['caller_id'], 'allowed_caller_ids' => array_values(array_unique($ids))]);
            $audit->audit($request->user(), 'trunk.caller_ids_updated', $trunk, ['before' => $before, 'evidence' => $data['evidence']]);
        });

        return back()->with('status', 'Caller IDs updated. Campaigns can select these numbers before approval.');
    }

    public function capacity(Request $request, TrunkProfile $trunk, Engine $engine, Foundation $audit)
    {
        Gate::authorize('telephony.manage');
        $data = $request->validate(['max_channels' => 'required|integer|min:1|max:10000', 'reserved_channels' => 'required|integer|min:0|lt:max_channels', 'safe_channels' => 'required|integer|min:1|max:10000', 'max_cps' => 'required|numeric|gt:0|max:1000', 'number_format' => 'required|in:national,international,e164', 'evidence' => 'required|string|max:2000']);
        DB::transaction(function () use ($request, $trunk, $data, $engine, $audit) {
            $engine->mutex();
            $trunk->update($data + ['active' => true, 'verified_by' => $request->user()->id, 'verified_at' => now()]);
            $audit->audit($request->user(), 'trunk.capacity_verified', $trunk, ['evidence' => $data['evidence']]);
        });

        return back()->with('status', 'Verified trunk capacity saved. Global dispatch settings remain separate.');
    }

    public function save(Request $request, IvrDesigner $designer)
    {
        $data = $request->validate(['name' => 'required|string|max:255', 'graph' => 'required|json|max:100000', 'source_id' => 'nullable|integer|exists:ivr_flows,id']);
        $graph = json_decode($data['graph'], true, 64, JSON_THROW_ON_ERROR);
        if (! is_array($graph)) {
            app(Readiness::class)->fail('Flow must be a JSON object.');
        }
        $designer->save($request->user(), $data['name'], $graph, isset($data['source_id']) ? IvrFlow::findOrFail($data['source_id']) : null);

        return back()->with('status', 'New IVR version saved for separate-user approval.');
    }

    public function approve(Request $request, IvrFlow $flow, IvrDesigner $designer)
    {
        $designer->approve($request->user(), $flow);

        return back()->with('status', 'IVR approved.');
    }

    public function download(Request $request, IvrFlow $flow, IvrCompiler $compiler)
    {
        Gate::authorize('telephony.manage');
        abort_unless($flow->status === 'approved', 422);
        $content = $compiler->compile($flow);

        return response($content, 200, ['Content-Type' => 'text/plain; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="gwosevo-ivr-'.$flow->uuid.'.conf"', 'Cache-Control' => 'private, no-store']);
    }

    public function deployed(Request $request, IvrFlow $flow, IvrCompiler $compiler, IvrDesigner $designer, Engine $engine, Foundation $audit)
    {
        Gate::authorize('telephony.manage');
        $data = $request->validate(['checksum' => 'required|regex:/\A[a-f0-9]{64}\z/', 'evidence' => 'required|string|max:2000']);
        DB::transaction(function () use ($request, $flow, $compiler, $engine, $audit, $data) {
            $engine->mutex();
            $flow = IvrFlow::whereKey($flow->id)->lockForUpdate()->firstOrFail();
            if ($flow->status !== 'approved' || ! hash_equals(hash('sha256', $compiler->compile($flow)), $data['checksum'])) {
                app(Readiness::class)->fail('Record the checksum of the actual generated dialplan file installed on the PBX.');
            }
            $flow->update(['deployed_at' => now(), 'deployment_evidence' => $data['evidence']]);
            $audit->audit($request->user(), 'ivr.deployed', $flow, ['evidence' => $data['evidence']]);
        });

        return back()->with('status', 'IVR deployment recorded.');
    }

    public function route(Request $request, Engine $engine, Foundation $audit)
    {
        Gate::authorize('telephony.manage');
        $data = $request->validate(['name' => 'required|string|max:255', 'type' => 'required|in:queue,extension', 'destination' => 'required|regex:/\A[0-9]{1,12}\z/', 'evidence' => 'required|string|max:2000']);
        DB::transaction(function () use ($request, $data, $engine, $audit) {
            $engine->mutex();
            $route = TransferRoute::create($data + ['verified_by' => $request->user()->id, 'active' => true]);
            $audit->audit($request->user(), 'transfer_route.created', $route);
        });

        return back()->with('status', 'Internal transfer destination allowlisted.');
    }

    public function disableRoute(Request $request, TransferRoute $route, Engine $engine, Foundation $audit)
    {
        Gate::authorize('telephony.manage');
        DB::transaction(function () use ($request, $route, $engine, $audit) {
            $engine->mutex();
            $route->update(['active' => false]);
            $audit->audit($request->user(), 'transfer_route.disabled', $route);
        });

        return back()->with('status', 'Transfer route disabled; affected campaigns require reconfiguration.');
    }
}
