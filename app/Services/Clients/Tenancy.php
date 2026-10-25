<?php

namespace App\Services\Clients;

use App\Models\CallAttempt;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Client;
use App\Models\ClientMembership;
use App\Models\Contact;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class Tenancy
{
    public const CLIENT_ABILITIES = ['contacts.view', 'contacts.manage', 'campaigns.view', 'campaigns.manage', 'audio.manage', 'reports.view', 'dashboard.view', 'dashboard.live'];

    private ?int $jobClient = null;

    private bool $inJob = false;

    public function membership(?User $user): ?ClientMembership
    {
        return $user ? ClientMembership::with('client')->where('user_id', $user->id)->first() : null;
    }

    public function clientId(?User $user): ?int
    {
        return $this->membership($user)?->client_id;
    }

    public function active(?int $clientId): bool
    {
        return $clientId === null || Client::whereKey($clientId)->where('status', 'active')->exists();
    }

    public function usable(?User $user): bool
    {
        $m = $this->membership($user);

        return $m ? ($m->status === 'active' && $m->client->status === 'active') : ! ($user?->hasRole('Client Member') ?? false);
    }

    public function current(): ?int
    {
        if ($this->inJob) {
            return $this->jobClient;
        }
        $user = auth()->user();
        abort_unless($this->usable($user), 403, 'Client account or membership is inactive.');

        return $this->clientId($user);
    }

    public function run(?int $id, callable $work): mixed
    {
        $old = [$this->jobClient, $this->inJob];
        $this->jobClient = $id;
        $this->inJob = true;
        try {
            return $work();
        } finally {
            [$this->jobClient, $this->inJob] = $old;
        }
    }

    public function ownerOf(Model $record): ?int
    {
        if ($record instanceof Client) {
            return $record->id;
        }
        $parent = match (class_basename($record)) {
            'ContactConsent' => Contact::withoutGlobalScopes()->find($record->contact_id),
            'CampaignVersion', 'CampaignRun', 'CampaignRecipient' => Campaign::withoutGlobalScopes()->find($record->campaign_id),
            'CallAttempt' => CampaignRecipient::withoutGlobalScopes()->find($record->campaign_recipient_id),
            'CallEvent', 'DtmfResponse' => CallAttempt::withoutGlobalScopes()->find($record->call_attempt_id),
            default => null,
        };

        return $parent ? $this->ownerOf($parent) : $record->client_id;
    }

    public function assertAccess(User $user, Model $record): void
    {
        abort_unless($this->usable($user), 403, 'Client account or membership is inactive.');
        $id = $this->clientId($user);
        abort_if($id !== null && (int) $record->client_id !== $id, 403);
    }

    public function sameAccount(User $user, Model $record): bool
    {
        $this->assertAccess($user, $record);

        return $this->clientId($user) !== null;
    }

    public function assertAssets($campaign, array $ids, string $model): void
    {
        $found = $model::withoutGlobalScopes()->whereIn('id', $ids)->get();
        abort_unless($found->count() === count(array_unique($ids)), 422, 'An account asset is unavailable.');
        foreach ($found as $asset) {
            abort_unless($asset->client_id === $campaign->client_id, 422, 'Assets must belong to the campaign account.');
        }
    }
}
