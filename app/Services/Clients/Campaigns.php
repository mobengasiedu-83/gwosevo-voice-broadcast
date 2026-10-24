<?php

namespace App\Services\Clients;

use App\Models\AudioFile;
use App\Models\Campaign;
use App\Models\Contact;
use App\Models\ContactGroup;
use App\Models\User;
use App\Services\Broadcast\Engine;
use App\Services\Broadcast\Readiness;
use App\Services\Campaigns\Workspace;
use App\Services\Foundation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class Campaigns
{
    public function access(User $actor, Campaign $campaign, bool $manage = false): void
    {
        $id = app(Portal::class)->authorize($actor);
        abort_unless((int) $campaign->client_account_id === $id, 404);
        if ($manage) {
            abort_unless((int) $campaign->owner_id === (int) $actor->id, 403);
        }
    }

    public function save(User $actor, ?Campaign $campaign, array $data): Campaign
    {
        $id = app(Portal::class)->authorize($actor);

        return DB::transaction(function () use ($actor, $campaign, $data, $id) {
            app(Engine::class)->mutex();
            if ($campaign) {
                $campaign = Campaign::whereKey($campaign->id)->lockForUpdate()->firstOrFail();
                $this->access($actor, $campaign, true);
                abort_unless(! $campaign->archived_at && in_array($campaign->status, ['draft', 'ready'], true), 422, 'Only unlaunched campaigns can be edited.');
            }
            $group = ContactGroup::where('client_account_id', $id)->where('active', true)->findOrFail($data['source_group_id']);
            $audio = AudioFile::where('client_account_id', $id)->where('status', 'approved')->findOrFail($data['audio_id']);
            $ids = $group->contacts()->where('contacts.client_account_id', $id)->orderBy('contacts.id')->pluck('contacts.id')->all();
            if (! count($ids) || count($ids) > 10000) {
                throw ValidationException::withMessages(['source_group_id' => 'Select a group with 1–10,000 contacts.']);
            }
            $config = collect($data)->only(['audio_id', 'source_group_id', 'timezone', 'scheduled_at', 'window_start', 'window_end', 'max_attempts', 'retry_delay_seconds'])->all();
            $config += ['contact_ids' => $ids, 'dialing_strategy' => 'direct', 'concurrency' => 1, 'cps' => 1, 'priority' => 1];
            $trunk = app(Workspace::class)->configuredTrunk();
            if ($trunk) {
                $config['trunk_id'] = $trunk->id;
            }
            $campaign ??= new Campaign(['owner_id' => $actor->id, 'client_account_id' => $id]);
            $campaign->fill(['name' => $data['name'], 'purpose' => $data['purpose'], 'type' => 'one_way', 'timezone' => $data['timezone'], 'configuration' => $config, 'submitted_snapshot' => null, 'status' => 'ready'])->save();
            app(Foundation::class)->audit($actor, 'client.campaign_saved', $campaign);

            return $campaign;
        });
    }

    public function control(User $actor, Campaign $campaign, string $action, string $reason): void
    {
        DB::transaction(function () use ($actor, $campaign, $action, $reason) {
            app(Engine::class)->mutex();
            $campaign = Campaign::whereKey($campaign->id)->lockForUpdate()->firstOrFail();
            $this->access($actor, $campaign, true);
            if ($action === 'start') {
                $eligible = app(Workspace::class)->eligibility($campaign);
                if ($eligible['eligible'] < 1) {
                    app(Readiness::class)->fail('No eligible contacts. Review the audience and DND exclusions.');
                }
            }
            if ($action === 'cancel' && in_array($campaign->status, ['draft', 'ready'], true)) {
                $campaign->update(['status' => 'cancelled', 'stop_requested' => true]);
            } else {
                app(Engine::class)->control($actor, $campaign, $action === 'cancel' ? 'emergency' : $action);
            }
            app(Foundation::class)->audit($actor, 'client.campaign_control', $campaign, compact('action', 'reason'));
        });
    }

    public function test(User $actor, Campaign $campaign, int $contactId): Campaign
    {
        return DB::transaction(function () use ($actor, $campaign, $contactId) {
            app(Engine::class)->mutex();
            $campaign = Campaign::whereKey($campaign->id)->lockForUpdate()->firstOrFail();
            $this->access($actor, $campaign, true);
            abort_unless(config('voice_broadcast.client_execution_enabled'), 403, 'Client execution is disabled pending controlled acceptance.');
            abort_unless(in_array($campaign->status, ['draft', 'ready'], true) && empty($campaign->configuration['client_test']), 422);
            if (Campaign::where('source_campaign_id', $campaign->id)->where('configuration->client_test', true)->whereIn('status', ['running', 'paused', 'scheduled'])->exists()) {
                app(Readiness::class)->fail('An earlier test run is still active. Review or cancel it before requesting another.');
            }
            $contact = Contact::where('client_account_id', $actor->client_account_id)->findOrFail($contactId);
            abort_unless(in_array($contact->phone_e164, config('voice_broadcast.client_test_numbers', []), true), 422, 'Choose an administrator-authorized test recipient.');
            if (app(Readiness::class)->exclusion($contact->id, $contact->phone_e164, $campaign->purpose)) {
                app(Readiness::class)->fail('Test recipient is excluded by DND or contact changes.');
            }
            $config = $campaign->configuration;
            unset($config['scheduled_at'], $config['source_group_id']);
            $config['contact_ids'] = [$contact->id];
            $config['max_attempts'] = 1;
            $config['client_test'] = true;
            $test = Campaign::create(['name' => 'Test: '.$campaign->name, 'purpose' => $campaign->purpose, 'type' => 'one_way', 'timezone' => $campaign->timezone, 'owner_id' => $actor->id, 'status' => 'ready', 'configuration' => $config, 'source_campaign_id' => $campaign->id]);
            app(Engine::class)->control($actor, $test, 'start');
            app(Foundation::class)->audit($actor, 'client.campaign_test_requested', $test, ['source_campaign_id' => $campaign->id]);

            return $test;
        });
    }
}
