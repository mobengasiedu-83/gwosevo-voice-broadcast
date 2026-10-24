<?php

namespace App\Services\Campaigns;

use App\Models\AudioFile;
use App\Models\Campaign;
use App\Models\CampaignTemplate;
use App\Models\Contact;
use App\Models\ContactGroup;
use App\Models\TrunkProfile;
use App\Models\User;
use App\Services\Broadcast\CampaignPolicy;
use App\Services\Broadcast\Engine;
use App\Services\Clients\AccountContext;
use App\Services\Foundation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class Workspace
{
    public function global(User $u): bool
    {
        return $u->hasAnyPermission(['campaigns.approve', 'audit.view', 'reports.view', 'operations.view']);
    }

    public function query(User $u)
    {
        return Campaign::query()->when(! $this->global($u), fn ($q) => $q->where('owner_id', $u->id));
    }

    public function templates(User $u)
    {
        return CampaignTemplate::query()->when(! $this->global($u), fn ($q) => $q->where('owner_id', $u->id));
    }

    public function access(User $u, Campaign $c): void
    {
        Gate::forUser($u)->authorize('campaigns.view');
        abort_unless($this->query($u)->whereKey($c->id)->exists(), 403);
    }

    public function own(User $u, Campaign $c): void
    {
        Gate::forUser($u)->authorize('campaigns.manage');
        abort_unless($c->owner_id === $u->id || $u->isSuperAdministrator(), 403);
    }

    public function save(User $u, ?Campaign $c, array $data): Campaign
    {
        Gate::forUser($u)->authorize('campaigns.manage');

        return DB::transaction(function () use ($u, $c, $data) {
            app(Engine::class)->mutex();
            if ($c) {
                $c = Campaign::whereKey($c->id)->lockForUpdate()->firstOrFail();
                $this->own($u, $c);
                abort_if($c->archived_at || ! in_array($c->status, ['draft', 'ready'], true), 422, 'Only unarchived draft or ready campaigns can be edited.');
            } else {
                $c = app(Foundation::class)->createCampaign($u, $data);
            }
            $data = array_replace($data, app(CampaignPolicy::class)->advanced($data));
            $config = collect($data)->only(['retry_outcomes', 'excluded_dates', 'expires_on', 'audio_id', 'caller_id', 'timezone', 'scheduled_at', 'concurrency', 'cps', 'window_start', 'window_end', 'max_attempts', 'retry_delay_seconds', 'priority', 'ivr_flow_id', 'source_group_id', 'dialing_strategy'])->all();
            $trunk = $this->configuredTrunk();
            if ($trunk) {
                $config['trunk_id'] = $trunk->id;
            }
            if (! empty($config['caller_id']) && ! preg_match('/\A\+?[0-9]{3,15}\z/', $config['caller_id'])) {
                throw ValidationException::withMessages(['caller_id' => 'Enter 3–15 digits with an optional leading +.']);
            }
            $ids = array_values(array_unique(array_filter(array_map('trim', explode(',', $data['contact_ids'] ?? '')), fn ($v) => $v !== '')));
            $config['explicit_contact_ids'] = $ids;
            if (! empty($data['source_group_id'])) {
                $group = ContactGroup::when(! $u->isSuperAdministrator(), fn ($q) => $q->where('owner_id', $u->id))->where('active', true)->findOrFail($data['source_group_id']);
                app(AccountContext::class)->sameAccount($c, $group);
                $ids = array_values(array_unique(array_merge($ids, $group->contacts()->pluck('contacts.id')->map(fn ($id) => (string) $id)->all())));
            }
            Validator::make(['ids' => $ids], ['ids' => 'array|max:10000', 'ids.*' => 'integer|distinct|exists:contacts,id'])->validate();
            $config['contact_ids'] = $ids;
            $before = $c->only(['name', 'purpose', 'type', 'configuration']);
            $audio = empty($config['audio_id']) ? null : AudioFile::find($config['audio_id']);
            $status = $audio?->status === 'approved' ? 'ready' : 'draft';
            $c->update(['name' => $data['name'], 'purpose' => $data['purpose'], 'type' => $data['type'], 'timezone' => $data['timezone'], 'configuration' => $config, 'submitted_snapshot' => null, 'status' => $status]);
            app(Foundation::class)->audit($u, 'campaign.draft_saved', $c, ['changed_fields' => array_keys(array_diff_assoc($c->only(['name', 'purpose', 'type']), collect($before)->only(['name', 'purpose', 'type'])->all()))]);

            return $c;
        });
    }

    public function defaultCallerId(?TrunkProfile $trunk = null): ?string
    {
        return config('broadcasting_discovery.caller_id') ?: ($trunk ?? $this->configuredTrunk())?->caller_id;
    }

    public function configuredTrunk(): ?TrunkProfile
    {
        $name = config('broadcasting_discovery.trunk');

        return is_string($name) && $name !== '' ? TrunkProfile::where('name', $name)->first() : null;
    }

    public function duplicate(User $u, Campaign $c, string $name): Campaign
    {
        Gate::forUser($u)->authorize('campaigns.manage');
        $this->access($u, $c);

        return DB::transaction(function () use ($u, $c, $name) {
            app(Engine::class)->mutex();
            $c = Campaign::whereKey($c->id)->lockForUpdate()->firstOrFail();
            $config = $c->configuration ?? [];
            unset($config['scheduled_at']);
            $new = Campaign::create(['name' => $name, 'purpose' => $c->purpose, 'type' => $c->type, 'timezone' => $c->timezone, 'owner_id' => $u->id, 'status' => 'draft', 'configuration' => $config, 'source_campaign_id' => $c->id, 'template_version_id' => $c->template_version_id]);
            app(Foundation::class)->audit($u, 'campaign.duplicated', $new, ['source_campaign_id' => $c->id]);

            return $new;
        });
    }

    public function archive(User $u, Campaign $c, bool $restore): void
    {
        $this->own($u, $c);
        DB::transaction(function () use ($u, $c, $restore) {
            app(Engine::class)->mutex();
            $c = Campaign::whereKey($c->id)->lockForUpdate()->firstOrFail();
            abort_if(in_array($c->status, ['running', 'paused', 'scheduled', 'pending_approval']) || DB::table('channel_reservations')->where('campaign_id', $c->id)->whereNull('released_at')->exists(), 422, 'Finish or cancel the run and reconcile its channels before archiving.');
            $c->update(['archived_at' => $restore ? null : now()]);
            app(Foundation::class)->audit($u, $restore ? 'campaign.restored' : 'campaign.archived', $c);
        });
    }

    public function template(User $u, Campaign $c, string $name, ?CampaignTemplate $parent): CampaignTemplate
    {
        $this->own($u, $c);

        return DB::transaction(function () use ($u, $c, $name, $parent) {
            app(Engine::class)->mutex();
            $c = Campaign::whereKey($c->id)->lockForUpdate()->firstOrFail();
            if ($parent) {
                abort_unless($parent->owner_id === $u->id || $u->isSuperAdministrator(), 403);
            }
            $config = $c->configuration ?? [];
            unset($config['contact_ids'],$config['explicit_contact_ids'],$config['scheduled_at'],$config['source_group_id']);
            $family = $parent?->family_uuid ?? (string) Str::uuid();
            $t = CampaignTemplate::create(['family_uuid' => $family, 'version' => (CampaignTemplate::where('family_uuid', $family)->max('version') ?? 0) + 1, 'name' => $name, 'owner_id' => $u->id, 'blueprint' => ['purpose' => $c->purpose, 'type' => $c->type, 'timezone' => $c->timezone, 'configuration' => $config], 'created_at' => now()]);
            app(Foundation::class)->audit($u, 'campaign.template_version_created', $t, ['source_campaign_id' => $c->id]);

            return $t;
        });
    }

    public function instantiate(User $u, CampaignTemplate $t, string $name): Campaign
    {
        Gate::forUser($u)->authorize('campaigns.manage');
        abort_unless($this->templates($u)->whereKey($t->id)->exists(), 403);

        return DB::transaction(function () use ($u, $t, $name) {
            $b = $t->blueprint;
            $c = Campaign::create(['name' => $name, 'purpose' => $b['purpose'], 'type' => $b['type'], 'timezone' => $b['timezone'], 'configuration' => $b['configuration'] + ['contact_ids' => []], 'status' => 'draft', 'owner_id' => $u->id, 'template_version_id' => $t->id]);
            app(Foundation::class)->audit($u, 'campaign.created_from_template', $c, ['template_version_id' => $t->id]);

            return $c;
        });
    }

    public function eligibility(Campaign $c): array
    {
        $s = $c->status === 'draft' ? [] : ($c->submitted_snapshot ?? []);
        $ids = $c->configuration['contact_ids'] ?? [];
        $current = Contact::whereIn('id', array_column($s['targets'] ?? [], 'id') ?: $ids)->pluck('phone_e164', 'id');
        $targets = $s['targets'] ?? array_map(fn ($id) => ['id' => $id, 'phone_e164' => $current[$id] ?? null], $ids);
        $suppressed = DB::table('suppression_entries')->whereIn('phone_e164', $current->values())->where('effective_at', '<=', now())->pluck('phone_e164')->flip();
        $counts = ['eligible' => 0, 'suppressed' => 0, 'contact_changed' => 0];
        foreach ($targets as $target) {
            $id = $target['id'];
            $reason = ! isset($current[$id]) || $current[$id] !== $target['phone_e164'] ? 'contact_changed' : (isset($suppressed[$current[$id]]) ? 'suppressed' : 'eligible');
            $counts[$reason]++;
        }

        return $counts;
    }
}
