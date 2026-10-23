<?php

namespace App\Services\Broadcast;

use App\Models\AudioFile;
use App\Models\IvrFlow;
use App\Models\TransferRoute;
use App\Models\User;
use App\Services\Foundation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class IvrDesigner
{
    public function validate(array $graph): array
    {
        Validator::make($graph, ['start' => 'required|string|max:32', 'nodes' => 'required|array|min:1|max:30', 'nodes.*.id' => 'required|regex:/\A[a-z][a-z0-9_]{0,31}\z/|distinct', 'nodes.*.type' => 'required|in:playback,input,condition,confirmation,transfer,opt_out,retry_prompt,timeout,end'])->validate();
        $nodes = [];
        $assets = [];
        $routes = [];
        $edges = [];
        $r = app(Readiness::class);
        foreach ($graph['nodes'] as $node) {
            $id = $node['id'];
            if (in_array($id, ['s', 'h', 'i', 't', 'e'], true)) {
                $r->fail('Node ID is reserved by Asterisk.');
            }
            if (isset($nodes[$id])) {
                $r->fail('Duplicate IVR node.');
            }$nodes[$id] = $node;
            $next = [];
            if (in_array($node['type'], ['playback', 'input', 'confirmation', 'retry_prompt'], true)) {
                $audio = AudioFile::find($node['audio_id'] ?? null);
                if (! $audio) {
                    $r->fail('Every prompt needs approved deployed audio.');
                }$r->audio($audio);
                $assets[$audio->id] = ['id' => $audio->id, 'uuid' => $audio->uuid, 'checksum' => $audio->checksum];
            }
            if (in_array($node['type'], ['input', 'confirmation', 'condition'], true)) {
                if (empty($node['branches']) || ! is_array($node['branches']) || count($node['branches']) > 12) {
                    $r->fail('Digit nodes need explicit branches.');
                }
                foreach ($node['branches'] as $digit => $target) {
                    if (! preg_match('/\A[0-9*#]\z/', (string) $digit)) {
                        $r->fail('Only single permitted keypad digits can be collected.');
                    }$next[] = $target;
                }
                $next[] = $node['invalid'] ?? null;
                $next[] = $node['timeout'] ?? null;
                if ($node['type'] !== 'condition' && (! is_int($node['timeout_seconds'] ?? 5) || ($node['timeout_seconds'] ?? 5) < 1 || ($node['timeout_seconds'] ?? 5) > 30)) {
                    $r->fail('Input timeout must be 1–30 seconds.');
                }
            } elseif ($node['type'] === 'transfer') {
                $route = TransferRoute::find($node['route_id'] ?? null);
                if (! $route || ! $route->active) {
                    $r->fail('Transfer destination must be an active allowlisted route.');
                }
                $routes[$route->id] = $route->only(['id', 'type', 'destination']);
                $next[] = $node['next'] ?? null;
                $next[] = $node['unavailable'] ?? null;
            } elseif (! in_array($node['type'], ['opt_out', 'end'], true)) {
                $next[] = $node['next'] ?? null;
            }
            if ($node['type'] === 'timeout' && (! is_int($node['seconds'] ?? 1) || ($node['seconds'] ?? 1) < 1 || ($node['seconds'] ?? 1) > 30)) {
                $r->fail('Wait must be 1–30 seconds.');
            }
            $edges[$id] = $next;
        }
        if (! isset($nodes[$graph['start']])) {
            $r->fail('Start node does not exist.');
        }
        foreach ($edges as $targets) {
            foreach ($targets as $target) {
                if (! is_string($target) || ! isset($nodes[$target])) {
                    $r->fail('Every IVR edge must reference an existing node.');
                }
            }
        }
        $visited = [];
        $stack = [];
        $walk = function ($id, $depth) use (&$walk, &$visited, &$stack, $edges, $r) {
            if ($depth > 20 || isset($stack[$id])) {
                $r->fail('IVR loops and paths deeper than 20 nodes are not allowed.');
            }
            if (isset($visited[$id]) && $visited[$id] >= $depth) {
                return;
            } $stack[$id] = true;
            $visited[$id] = $depth;
            foreach ($edges[$id] as $target) {
                $walk($target, $depth + 1);
            }unset($stack[$id]);
        };
        $walk($graph['start'], 1);
        if (count($visited) !== count($nodes)) {
            $r->fail('Remove unreachable IVR nodes.');
        }

        return ['assets' => array_values($assets), 'routes' => array_values($routes)];
    }

    public function save(User $actor, string $name, array $graph, ?IvrFlow $source = null): IvrFlow
    {
        Gate::forUser($actor)->authorize('campaigns.manage');
        $this->validate($graph);

        return DB::transaction(function () use ($actor, $name, $graph, $source) {
            app(Engine::class)->mutex();
            if ($source) {
                abort_unless($source->owner_id === $actor->id || $actor->isSuperAdministrator(), 403);
            }
            $family = $source?->family_uuid ?? (string) Str::uuid();
            $flow = IvrFlow::create(['family_uuid' => $family, 'uuid' => (string) Str::uuid(), 'name' => $name, 'version' => (IvrFlow::where('family_uuid', $family)->max('version') ?? 0) + 1, 'owner_id' => $actor->id, 'graph' => $graph, 'checksum' => hash('sha256', json_encode($graph, JSON_THROW_ON_ERROR)), 'status' => 'draft']);
            app(Foundation::class)->audit($actor, 'ivr.created', $flow);

            return $flow;
        });
    }

    public function approve(User $actor, IvrFlow $flow): void
    {
        Gate::forUser($actor)->authorize('campaigns.approve');
        DB::transaction(function () use ($actor, $flow) {
            app(Engine::class)->mutex();
            $flow = IvrFlow::whereKey($flow->id)->lockForUpdate()->firstOrFail();
            abort_if($flow->owner_id === $actor->id, 403);
            if ($flow->status !== 'draft') {
                app(Readiness::class)->fail('Only draft flows can be approved.');
            }$this->validate($flow->graph);
            $flow->update(['status' => 'approved', 'approved_by' => $actor->id, 'approved_at' => now()]);
            app(Foundation::class)->audit($actor, 'ivr.approved', $flow);
        });
    }

    public function snapshot(IvrFlow $flow): array
    {
        if ($flow->status !== 'approved' || ! $flow->deployed_at) {
            app(Readiness::class)->fail('An approved deployed IVR version is required.');
        }

        return ['id' => $flow->id, 'uuid' => $flow->uuid, 'checksum' => $flow->checksum, 'graph' => $flow->graph, 'context' => 'gwosevo-ivr-'.str_replace('-', '', $flow->uuid)] + $this->validate($flow->graph);
    }
}
