<?php

namespace App\Models\Concerns;

use App\Models\CallAttempt;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Contact;
use App\Services\Clients\Tenancy;
use Illuminate\Database\Eloquent\Builder;

trait ScopedThroughClient
{
    public static function bootScopedThroughClient(): void
    {
        static::addGlobalScope('client', function (Builder $query) {
            if (app(Tenancy::class)->current() === null) {
                return;
            }
            [$model, $foreign] = match (class_basename($query->getModel())) {
                'ContactConsent' => [Contact::class, 'contact_id'],
                'CampaignVersion', 'CampaignRun', 'CampaignRecipient' => [Campaign::class, 'campaign_id'],
                'CallAttempt' => [CampaignRecipient::class, 'campaign_recipient_id'],
                'CallEvent', 'DtmfResponse', 'CallTelecomUsage', 'CallTimingEvidence', 'CallRating', 'BillingException' => [CallAttempt::class, 'call_attempt_id'],
            };
            $query->whereIn($query->getModel()->qualifyColumn($foreign), $model::select('id'));
        });
    }
}
