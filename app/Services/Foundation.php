<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Campaign;
use App\Models\Contact;
use App\Models\User;
use App\Services\Broadcast\Engine;
use App\Services\Broadcast\Readiness;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class Foundation
{
    public function audit(User $actor, string $action, $entity, array $details = []): void
    {
        AuditLog::create(['actor_id' => $actor->id, 'action' => $action, 'entity_type' => $entity->getMorphClass(), 'entity_id' => $entity->id, 'details' => $details, 'created_at' => now()]);
    }

    public function createContact(User $actor, array $data): Contact
    {
        Gate::forUser($actor)->authorize('contacts.manage');

        return DB::transaction(function () use ($actor, $data) {
            $contact = Contact::create(['name' => $data['name'], 'phone_e164' => GhanaPhone::normalize($data['phone']), 'source_phone' => $data['phone'], 'source' => $data['source'], 'created_by' => $actor->id]);
            $this->audit($actor, 'contact.created', $contact);

            return $contact;
        });
    }

    public function createCampaign(User $actor, array $data): Campaign
    {
        Gate::forUser($actor)->authorize('campaigns.manage');

        return DB::transaction(function () use ($actor, $data) {
            $campaign = Campaign::create(['name' => $data['name'], 'purpose' => $data['purpose'], 'type' => $data['type'], 'timezone' => 'Africa/Accra', 'owner_id' => $actor->id, 'status' => 'draft', 'configuration' => []]);
            $this->audit($actor, 'campaign.created', $campaign);

            return $campaign;
        });
    }

    public function updateCampaign(User $actor, Campaign $campaign, array $data): void
    {
        Gate::forUser($actor)->authorize('campaigns.manage');
        DB::transaction(function () use ($actor, $campaign, $data) {
            app(Engine::class)->mutex();
            $campaign = Campaign::whereKey($campaign->id)->lockForUpdate()->firstOrFail();
            abort_unless($campaign->owner_id === $actor->id || $actor->isSuperAdministrator(), 403);
            if ($campaign->archived_at || $campaign->status !== 'draft') {
                throw ValidationException::withMessages(['campaign' => 'Only drafts can be edited.']);
            }
            $before = $campaign->only(['name', 'purpose', 'type']);
            $campaign->update(collect($data)->only(['name', 'purpose', 'type'])->all());
            $this->audit($actor, 'campaign.updated', $campaign, ['before' => $before, 'after' => $campaign->only(['name', 'purpose', 'type'])]);
        });
    }

    public function transition(User $actor, Campaign $campaign, string $decision, string $reason): void
    {
        Gate::forUser($actor)->authorize($decision === 'submit' ? 'campaigns.manage' : 'campaigns.approve');
        DB::transaction(function () use ($actor, $campaign, $decision, $reason) {
            app(Engine::class)->mutex();
            $campaign = Campaign::whereKey($campaign->id)->lockForUpdate()->firstOrFail();
            if ($decision === 'submit') {
                abort_unless($campaign->owner_id === $actor->id || $actor->isSuperAdministrator(), 403);
                if ($campaign->archived_at || $campaign->status !== 'draft') {
                    throw ValidationException::withMessages(['campaign' => 'Only drafts can be submitted.']);
                }
                if ($campaign->configuration) {
                    $campaign->submitted_snapshot = app(Readiness::class)->snapshot($campaign);
                }
                $status = 'pending_approval';
            } else {
                abort_if($campaign->owner_id === $actor->id && ! $actor->isSuperAdministrator(), 403, 'A different user must review the campaign.');
                if ($campaign->status !== 'pending_approval') {
                    throw ValidationException::withMessages(['campaign' => 'Only pending campaigns can be reviewed.']);
                }
                if (! in_array($decision, ['approve', 'reject'], true)) {
                    throw ValidationException::withMessages(['decision' => 'Invalid review decision.']);
                }
                $status = $decision === 'approve' ? 'approved' : 'draft';
                if ($decision === 'approve') {
                    $snapshot = $campaign->only(['uuid', 'name', 'purpose', 'type', 'timezone', 'configuration']);
                    if ($campaign->configuration) {
                        $snapshot['broadcast'] = app(Readiness::class)->snapshot($campaign);
                        if ($campaign->submitted_snapshot && json_encode($snapshot['broadcast']) !== json_encode($campaign->submitted_snapshot)) {
                            throw ValidationException::withMessages(['campaign' => 'Audience or assets changed since submission. Reject and resubmit for a fresh review.']);
                        }
                    }
                    $campaign->versions()->create(['version' => ($campaign->versions()->max('version') ?? 0) + 1, 'snapshot' => $snapshot, 'approved_by' => $actor->id, 'approved_at' => now()]);
                }
            }
            DB::table('campaign_reviews')->insert(['campaign_id' => $campaign->id, 'reviewer_id' => $actor->id, 'decision' => $decision, 'reason' => $reason, 'created_at' => now()]);
            $before = $campaign->status;
            $campaign->update(['status' => $status]);
            $this->audit($actor, 'campaign.'.$decision, $campaign, ['before' => $before, 'after' => $status]);
        });
    }
}
